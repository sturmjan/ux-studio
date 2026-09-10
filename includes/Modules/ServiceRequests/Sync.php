<?php
/**
 * Outbound queue and inbound mirror for the central ticket system.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\ServiceRequests;

use UxStudio\Core\ActivityLog;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * The site is NOT the owner of a service request any more - the central app is.
 * Locally we keep a row so the client can see their own requests in wp-admin
 * even when the central app is unreachable, but the number, the status and the
 * conversation all come from the central app.
 *
 * WHY A QUEUE AND NOT A PLAIN HTTP CALL: the central app can be down, slow, or
 * behind an expired certificate exactly at the moment a client hits "send".
 * Losing the request then would be the worst possible failure - the client
 * believes it was reported and nobody ever sees it. So every write is recorded
 * locally first, pushed immediately as a best effort, and retried on a timer
 * with backoff if that push failed.
 *
 * Permanent failures (4xx that is not 429: unknown site, bad key, invalid
 * payload) stop retrying - hammering them on a timer would never succeed and
 * would only fill the log. They stay visible as `sync_state = 'error'` with
 * the reason, so it is fixable rather than silently stuck.
 */
final class Sync {

	public const CRON_HOOK     = 'uxstudio_service_requests_sync';
	public const CRON_SCHEDULE = 'uxstudio_five_minutes';

	/**
	 * Central status -> local status column.
	 *
	 * The local column stays only so that v1 rows and the status filter keep
	 * working. What the client is actually shown is `central_status`, because
	 * the central app owns it - eight states collapse into three here, and
	 * collapsing is lossy, so it must never be the value anyone reads back.
	 */
	private const LOCAL_STATUS = array(
		'new'            => 'open',
		'triaged'        => 'open',
		'in_progress'    => 'in_progress',
		'waiting_client' => 'in_progress',
		'waiting_vendor' => 'in_progress',
		'resolved'       => 'done',
		'closed'         => 'done',
		'rejected'       => 'done',
	);

	/** After this many failed attempts we stop retrying automatically. */
	private const MAX_ATTEMPTS = 8;

	/** Backoff in seconds, indexed by attempt count. */
	private const BACKOFF = array( 0, 60, 300, 900, 3600, 10800, 21600, 43200 );

	// ══════════════════════════════════════════════════════════════════
	//  Cron
	// ══════════════════════════════════════════════════════════════════

	/**
	 * Register the recurring sync. Idempotent - safe to call on every boot.
	 */
	public static function schedule(): void {
		add_filter( 'cron_schedules', array( __CLASS__, 'add_schedule' ) ); // phpcs:ignore WordPress.WP.CronInterval.ChangeDetected
		add_action( self::CRON_HOOK, array( __CLASS__, 'run' ) );

		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + 60, self::CRON_SCHEDULE, self::CRON_HOOK );
		}
	}

	/**
	 * Add the 5-minute interval WordPress does not ship with.
	 *
	 * @param array $schedules Existing schedules.
	 * @return array
	 */
	public static function add_schedule( array $schedules ): array {
		if ( ! isset( $schedules[ self::CRON_SCHEDULE ] ) ) {
			$schedules[ self::CRON_SCHEDULE ] = array(
				'interval' => 300,
				'display'  => __( 'Every 5 minutes (UX Studio ticket sync)', 'ux-studio' ),
			);
		}
		return $schedules;
	}

	/**
	 * One sync pass: push what is waiting, then refresh what is already known.
	 *
	 * @return array{pushed:int,failed:int,replies:int,pulled:int}
	 */
	public static function run(): array {
		$out = array(
			'pushed'  => 0,
			'failed'  => 0,
			'replies' => 0,
			'pulled'  => 0,
		);

		if ( ! CentralClient::is_configured() ) {
			return $out;
		}

		$out['pushed']  = self::push_pending( 20 );
		$out['replies'] = self::flush_outbox( 40 );
		$out['pulled']  = self::pull_updates( 30 );

		return $out;
	}

	// ══════════════════════════════════════════════════════════════════
	//  Push
	// ══════════════════════════════════════════════════════════════════

	/**
	 * Push requests that have not reached the central app yet.
	 *
	 * @param int $limit Max rows per pass.
	 * @return int Successfully pushed.
	 */
	public static function push_pending( int $limit = 20 ): int {
		global $wpdb;
		$table = self::table();

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT * FROM {$table}
				  WHERE central_ticket_id = 0
				    AND sync_state <> 'error'
				    AND sync_attempts < %d
				  ORDER BY id ASC
				  LIMIT %d",
				self::MAX_ATTEMPTS,
				max( 1, min( 50, $limit ) )
			),
			ARRAY_A
		);

		$done = 0;
		foreach ( (array) $rows as $row ) {
			if ( ! self::is_due( $row ) ) {
				continue;
			}
			if ( self::push_one( $row ) ) {
				++$done;
			}
		}
		return $done;
	}

	/**
	 * Push a single request. Returns true when the central app accepted it.
	 *
	 * @param array $row Local request row.
	 */
	public static function push_one( array $row ): bool {
		$result = CentralClient::call(
			'create',
			'POST',
			array(
				'external_id'          => self::external_id( (int) $row['id'] ),
				'subject'              => (string) $row['title'],
				'description'          => (string) $row['description'],
				'url'                  => (string) ( $row['page_url'] ?? '' ),
				'type'                 => (string) ( $row['type'] ?? 'bug' ),
				'priority'             => (string) ( $row['priority'] ?? 'normal' ),
				'requester_name'       => (string) ( $row['requester_name'] ?? '' ),
				'requester_email'      => (string) $row['requester_email'],
				'requester_wp_user_id' => (int) ( $row['requester_user_id'] ?? 0 ),
				'environment'          => self::decode_json( (string) ( $row['environment'] ?? '' ) ),
			)
		);

		if ( $result instanceof WP_Error ) {
			self::record_failure( (int) $row['id'], $result );
			return false;
		}

		$ticket = is_array( $result['ticket'] ?? null ) ? $result['ticket'] : array();
		self::store_central_state( (int) $row['id'], $ticket );

		// The attachment goes as a separate signed upload - putting file bytes
		// in the JSON body would base64-inflate them by a third and blow past
		// the central app's body limits on anything bigger than a screenshot.
		$attachment_id = (int) ( $row['attachment_id'] ?? 0 );
		if ( $attachment_id > 0 ) {
			$path = get_attached_file( $attachment_id );
			if ( is_string( $path ) && '' !== $path ) {
				$upload = CentralClient::upload(
					self::external_id( (int) $row['id'] ),
					$path,
					(string) basename( $path )
				);
				if ( $upload instanceof WP_Error ) {
					// The ticket itself is through; a failed attachment must
					// not push the whole row back into the retry queue, or the
					// central app would keep receiving duplicate creates.
					ActivityLog::log( 'service-requests', 'attachment_failed', 'service_request', (int) $row['id'], array( 'error' => $upload->get_error_message() ) );
				}
			}
		}

		ActivityLog::log( 'service-requests', 'pushed', 'service_request', (int) $row['id'], array( 'number' => (string) ( $ticket['number'] ?? '' ) ) );
		return true;
	}

	// ══════════════════════════════════════════════════════════════════
	//  Replies (outbox)
	// ══════════════════════════════════════════════════════════════════

	/**
	 * Queue a client reply and try to deliver it right away.
	 *
	 * @param int    $request_id Local request id.
	 * @param string $body       Message text.
	 * @param string $author     Display name.
	 * @param string $email      Author e-mail.
	 * @return true|WP_Error True when queued (delivery may still be pending).
	 */
	public static function queue_reply( int $request_id, string $body, string $author, string $email ) {
		global $wpdb;

		$body = trim( $body );
		if ( '' === $body ) {
			return new WP_Error( 'uxstudio_empty_reply', __( 'The message is empty.', 'ux-studio' ), array( 'status' => 400 ) );
		}

		$outbox = self::outbox_table();
		$wpdb->insert(
			$outbox,
			array(
				'request_id'  => $request_id,
				'external_id' => 'msg-' . $request_id . '-' . wp_generate_password( 8, false ),
				'body'        => $body,
				'author_name' => $author,
				'author_email' => $email,
				'attempts'    => 0,
				'created_at'  => current_time( 'mysql' ),
			),
			array( '%d', '%s', '%s', '%s', '%s', '%d', '%s' )
		);

		// Best effort now so the usual case feels instant; the cron picks up
		// whatever this leaves behind.
		self::flush_outbox( 5 );
		return true;
	}

	/**
	 * Deliver queued replies.
	 *
	 * @param int $limit Max messages per pass.
	 * @return int Delivered.
	 */
	public static function flush_outbox( int $limit = 40 ): int {
		global $wpdb;
		$outbox = self::outbox_table();
		$table  = self::table();

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT o.*, r.central_ticket_id
				   FROM {$outbox} o
				   JOIN {$table} r ON r.id = o.request_id
				  WHERE o.attempts < %d
				  ORDER BY o.id ASC
				  LIMIT %d",
				self::MAX_ATTEMPTS,
				max( 1, min( 100, $limit ) )
			),
			ARRAY_A
		);

		$sent = 0;
		foreach ( (array) $rows as $row ) {
			// A reply cannot arrive before the ticket it belongs to. Leave it
			// queued rather than failing it - the create is retried separately.
			if ( (int) $row['central_ticket_id'] <= 0 ) {
				continue;
			}
			if ( ! self::is_due( $row ) ) {
				continue;
			}

			$result = CentralClient::call(
				'message',
				'POST',
				array(
					'external_id'         => self::external_id( (int) $row['request_id'] ),
					'external_message_id' => (string) $row['external_id'],
					'body'                => (string) $row['body'],
					'author_name'         => (string) $row['author_name'],
					'author_email'        => (string) $row['author_email'],
				)
			);

			if ( $result instanceof WP_Error ) {
				$permanent = (bool) ( $result->get_error_data()['permanent'] ?? false );
				$wpdb->update(
					$outbox,
					array(
						'attempts'     => $permanent ? self::MAX_ATTEMPTS : ( (int) $row['attempts'] + 1 ),
						'last_error'   => mb_substr( $result->get_error_message(), 0, 500 ),
						'last_try_at'  => current_time( 'mysql' ),
					),
					array( 'id' => (int) $row['id'] ),
					array( '%d', '%s', '%s' ),
					array( '%d' )
				);
				continue;
			}

			// Delivered - the message now lives in the central thread, which we
			// mirror on the next pull. Keeping a local copy too would mean two
			// sources for the same text and inevitable drift.
			$wpdb->delete( $outbox, array( 'id' => (int) $row['id'] ), array( '%d' ) );
			++$sent;
		}

		return $sent;
	}

	// ══════════════════════════════════════════════════════════════════
	//  Pull
	// ══════════════════════════════════════════════════════════════════

	/**
	 * Refresh status and conversation of requests already in the central app.
	 *
	 * Only requests that are not finished are refreshed - a closed ticket does
	 * not change any more and re-fetching all of them forever would turn a
	 * 5-minute cron into a slow leak of HTTP calls.
	 *
	 * @param int $limit Max rows per pass.
	 * @return int Refreshed.
	 */
	public static function pull_updates( int $limit = 30 ): int {
		global $wpdb;
		$table = self::table();

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT id FROM {$table}
				  WHERE central_ticket_id > 0
				    AND ( central_status IS NULL OR central_status NOT IN ('closed','rejected') )
				  ORDER BY COALESCE(synced_at, created_at) ASC
				  LIMIT %d",
				max( 1, min( 50, $limit ) )
			),
			ARRAY_A
		);

		$done = 0;
		foreach ( (array) $rows as $row ) {
			if ( self::pull_one( (int) $row['id'] ) ) {
				++$done;
			}
		}
		return $done;
	}

	/**
	 * Refresh one request from the central app.
	 *
	 * @param int $request_id Local request id.
	 */
	public static function pull_one( int $request_id ): bool {
		$result = CentralClient::call(
			'detail',
			'GET',
			array(),
			array( 'external_id' => self::external_id( $request_id ) )
		);

		if ( $result instanceof WP_Error ) {
			return false;
		}

		global $wpdb;
		$ticket = is_array( $result['ticket'] ?? null ) ? $result['ticket'] : array();

		// The thread is stored as a cached blob, not as rows: it is a read-only
		// mirror of someone else's data and nothing here ever queries into it.
		$central_status = (string) ( $ticket['status'] ?? '' );

		$wpdb->update(
			self::table(),
			array(
				'central_status' => $central_status,
				'central_number' => (string) ( $ticket['number'] ?? '' ),
				'central_thread' => (string) wp_json_encode( $result['messages'] ?? array() ),
				'status'         => self::LOCAL_STATUS[ $central_status ] ?? 'open',
				'sync_state'     => 'synced',
				'sync_error'     => '',
				'synced_at'      => current_time( 'mysql' ),
			),
			array( 'id' => $request_id ),
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%s' ),
			array( '%d' )
		);

		return true;
	}

	// ══════════════════════════════════════════════════════════════════
	//  Environment snapshot
	// ══════════════════════════════════════════════════════════════════

	/**
	 * What was true on this site when the request was filed.
	 *
	 * This is the single biggest reason the plugin side exists at all: without
	 * it every ticket starts with a round of "which page, which browser, which
	 * plugin version". Collected once, at submit time - reading it later would
	 * describe the site as it is now, not as it was when it broke.
	 *
	 * @param string $page_url Page the client was on.
	 * @return array<string, string>
	 */
	public static function collect_environment( string $page_url = '' ): array {
		$theme = wp_get_theme();
		$user  = wp_get_current_user();

		$plugins = array();
		if ( function_exists( 'get_plugins' ) || file_exists( ABSPATH . 'wp-admin/includes/plugin.php' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
			foreach ( (array) get_option( 'active_plugins', array() ) as $file ) {
				$data = get_plugin_data( WP_PLUGIN_DIR . '/' . $file, false, false );
				if ( ! empty( $data['Name'] ) ) {
					$plugins[] = $data['Name'] . ' ' . (string) ( $data['Version'] ?? '' );
				}
				if ( count( $plugins ) >= 30 ) {
					break;
				}
			}
		}

		$env = array(
			__( 'Page', 'ux-studio' )       => $page_url,
			'WordPress'                     => get_bloginfo( 'version' ),
			'PHP'                           => PHP_VERSION,
			__( 'Theme', 'ux-studio' )      => $theme->get( 'Name' ) . ' ' . (string) $theme->get( 'Version' ),
			__( 'Language', 'ux-studio' )   => (string) get_locale(),
			__( 'User role', 'ux-studio' )  => implode( ', ', (array) $user->roles ),
			__( 'Browser', 'ux-studio' )    => isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '',
			__( 'Plugins', 'ux-studio' )    => implode( ', ', $plugins ),
		);

		return array_filter( $env, static fn ( $v ): bool => '' !== trim( (string) $v ) );
	}

	// ══════════════════════════════════════════════════════════════════
	//  Internals
	// ══════════════════════════════════════════════════════════════════

	/**
	 * Key that identifies this request in the central app. Stable and derived
	 * from the local row id, which is what makes every retry idempotent -
	 * the central app upserts on (site, external_id).
	 *
	 * @param int $request_id Local row id.
	 */
	public static function external_id( int $request_id ): string {
		return 'uxs-' . $request_id;
	}

	/**
	 * Has enough time passed since the last failed attempt?
	 *
	 * @param array $row Row with `attempts`/`sync_attempts` and a last-try timestamp.
	 */
	private static function is_due( array $row ): bool {
		$attempts = (int) ( $row['sync_attempts'] ?? $row['attempts'] ?? 0 );
		if ( 0 === $attempts ) {
			return true;
		}
		$last = (string) ( $row['last_try_at'] ?? $row['synced_at'] ?? '' );
		if ( '' === $last ) {
			return true;
		}
		$wait = self::BACKOFF[ min( $attempts, count( self::BACKOFF ) - 1 ) ];
		return ( time() - (int) strtotime( $last ) ) >= $wait;
	}

	/**
	 * Note a failed push on the request row.
	 *
	 * @param int      $request_id Local row id.
	 * @param WP_Error $error      What went wrong.
	 */
	private static function record_failure( int $request_id, WP_Error $error ): void {
		global $wpdb;

		$permanent = (bool) ( $error->get_error_data()['permanent'] ?? false );

		$wpdb->query(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				'UPDATE ' . self::table() . '
				    SET sync_attempts = sync_attempts + 1,
				        sync_state    = %s,
				        sync_error    = %s,
				        last_try_at   = %s
				  WHERE id = %d',
				$permanent ? 'error' : 'pending',
				mb_substr( $error->get_error_message(), 0, 500 ),
				current_time( 'mysql' ),
				$request_id
			)
		);
	}

	/**
	 * Store what the central app said about a freshly created ticket.
	 *
	 * @param int   $request_id Local row id.
	 * @param array $ticket     Ticket payload from the central app.
	 */
	private static function store_central_state( int $request_id, array $ticket ): void {
		global $wpdb;

		$wpdb->update(
			self::table(),
			array(
				'central_ticket_id' => (int) ( $ticket['id'] ?? 0 ),
				'central_number'    => (string) ( $ticket['number'] ?? '' ),
				'central_status'    => (string) ( $ticket['status'] ?? '' ),
				'sync_state'        => 'synced',
				'sync_error'        => '',
				'synced_at'         => current_time( 'mysql' ),
			),
			array( 'id' => $request_id ),
			array( '%d', '%s', '%s', '%s', '%s', '%s' ),
			array( '%d' )
		);
	}

	/**
	 * @param string $json Raw JSON.
	 * @return array<string, mixed>
	 */
	private static function decode_json( string $json ): array {
		if ( '' === $json ) {
			return array();
		}
		$decoded = json_decode( $json, true );
		return is_array( $decoded ) ? $decoded : array();
	}

	private static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'uxstudio_service_requests';
	}

	private static function outbox_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'uxstudio_service_request_outbox';
	}
}
