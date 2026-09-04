<?php
/**
 * Email Log module - record every outgoing wp_mail() send.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\EmailLog;

use UxStudio\Core\ActivityLog;
use UxStudio\Modules\BaseModule;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Independent from the SMTP Email module's delivery log (uxstudio_smtp_logs):
 * this module keeps its own uxstudio_email_log table so it can be enabled on
 * its own, purely as an outgoing-mail audit trail with its own retention.
 *
 * Capture strategy: a high-priority 'wp_mail' filter records the full message
 * (body, headers, attachment filenames, best-effort source) into a pending row
 * before delivery; wp_mail_succeeded / wp_mail_failed then flip that row's
 * status. This is the only place the complete wp_mail() args are available.
 */
final class Module extends BaseModule {

	private const RETENTION_LOCK = 'uxstudio_email_log_retention_lock';

	/** Schema version for this module's own table (bumped to add columns). */
	private const DB_VERSION = 2;

	/**
	 * Row id of the message captured by the current wp_mail() call, so the
	 * matching success/failure hook can update it. wp_mail() is synchronous
	 * (filter -> send -> succeeded|failed) so a single slot is safe.
	 */
	private ?int $last_log_id = null;

	/**
	 * Register hooks.
	 */
	public function boot(): void {
		add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );

		\UxStudio\Core\DB::ensure_module_tables(
			'email-log',
			self::DB_VERSION,
			function ( int $from ): void {
				global $wpdb;
				$charset = $wpdb->get_charset_collate();
				// dbDelta() diffs this definition against the live table and
				// ADDs any missing columns on a version bump (v1 -> v2 gains
				// message/headers/attachments/source). Large fields = LONGTEXT.
				dbDelta(
					"CREATE TABLE {$wpdb->prefix}uxstudio_email_log (
						id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
						created_at DATETIME NOT NULL,
						to_email VARCHAR(255) NOT NULL DEFAULT '',
						subject VARCHAR(255) NOT NULL DEFAULT '',
						status VARCHAR(20) NOT NULL DEFAULT '',
						error_message TEXT NULL,
						source VARCHAR(191) NOT NULL DEFAULT '',
						message LONGTEXT NULL,
						headers LONGTEXT NULL,
						attachments LONGTEXT NULL,
						PRIMARY KEY  (id),
						KEY created_at (created_at)
					) {$charset};"
				);
			}
		);

		add_filter( 'wp_mail', array( $this, 'capture' ), 999 );
		add_action( 'wp_mail_succeeded', array( $this, 'log_success' ) );
		add_action( 'wp_mail_failed', array( $this, 'log_failure' ) );
	}

	/**
	 * Register the module REST controller.
	 */
	public function register_rest_routes(): void {
		( new RestController( $this ) )->register_routes();
	}

	/**
	 * REST controller class.
	 */
	public function rest_controller(): ?string {
		return RestController::class;
	}

	/**
	 * Settings schema for the generic renderer / embedded Settings tab.
	 */
	public function settings_schema(): array {
		return array(
			array(
				'key'     => 'retention_days',
				'type'    => 'number',
				'label'   => __( 'Retention (days)', 'ux-studio' ),
				'help'    => __( 'Log entries older than this are pruned automatically.', 'ux-studio' ),
				'default' => 30,
			),
			array(
				'key'     => 'detect_source',
				'type'    => 'toggle',
				'label'   => __( 'Detect source', 'ux-studio' ),
				'help'    => __( 'Record which plugin, theme or mu-plugin triggered each mail. Adds a small overhead per send; disable on high-volume sites.', 'ux-studio' ),
				'default' => true,
			),
		);
	}

	/**
	 * Capture the full message before delivery (hooked on the wp_mail filter).
	 * Inserts a pending row and remembers its id for the success/failure hook.
	 * Returns the args unchanged - this filter never mutates the mail.
	 *
	 * @param mixed $args wp_mail() args array: to, subject, message, headers, attachments.
	 * @return mixed The args, untouched.
	 */
	public function capture( $args ) {
		if ( ! is_array( $args ) ) {
			return $args;
		}

		global $wpdb;

		$to = $args['to'] ?? '';
		if ( is_array( $to ) ) {
			$to = implode( ', ', $to );
		}

		$headers = $args['headers'] ?? '';
		if ( is_array( $headers ) ) {
			$headers = implode( "\n", $headers );
		}

		$attachments = $args['attachments'] ?? array();
		if ( is_string( $attachments ) ) {
			$attachments = '' === $attachments ? array() : array( $attachments );
		}
		if ( ! is_array( $attachments ) ) {
			$attachments = array();
		}
		$attachment_names = array_values( array_map( 'basename', $attachments ) );

		$source = $this->detect_source_enabled() ? $this->detect_source() : '';

		$inserted = $wpdb->insert(
			"{$wpdb->prefix}uxstudio_email_log",
			array(
				'created_at'    => current_time( 'mysql' ),
				'to_email'      => mb_substr( (string) $to, 0, 255 ),
				'subject'       => mb_substr( (string) ( $args['subject'] ?? '' ), 0, 255 ),
				'status'        => 'pending',
				'error_message' => null,
				'source'        => mb_substr( $source, 0, 191 ),
				'message'       => (string) ( $args['message'] ?? '' ),
				'headers'       => (string) $headers,
				'attachments'   => wp_json_encode( $attachment_names ),
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);

		$this->last_log_id = $inserted && $wpdb->insert_id ? (int) $wpdb->insert_id : null;

		return $args;
	}

	/**
	 * Flip the captured row to 'success' (hooked on wp_mail_succeeded, WP 5.9+).
	 *
	 * @param array $mail_data Same shape as the wp_mail() arguments (unused).
	 */
	public function log_success( array $mail_data ): void {
		if ( null !== $this->last_log_id ) {
			global $wpdb;
			$wpdb->update(
				"{$wpdb->prefix}uxstudio_email_log",
				array( 'status' => 'success' ),
				array( 'id' => $this->last_log_id ),
				array( '%s' ),
				array( '%d' )
			);
			$this->last_log_id = null;
		}
		$this->maybe_prune();
	}

	/**
	 * Flip the captured row to 'error' (hooked on wp_mail_failed).
	 *
	 * @param WP_Error $error Error carrying the original mail_data.
	 */
	public function log_failure( WP_Error $error ): void {
		if ( null !== $this->last_log_id ) {
			global $wpdb;
			$wpdb->update(
				"{$wpdb->prefix}uxstudio_email_log",
				array(
					'status'        => 'error',
					'error_message' => $error->get_error_message(),
				),
				array( 'id' => $this->last_log_id ),
				array( '%s', '%s' ),
				array( '%d' )
			);
			$this->last_log_id = null;
		}
		$this->maybe_prune();
	}

	/** Whether source detection is enabled in settings. */
	private function detect_source_enabled(): bool {
		return (bool) $this->settings->get( 'detect_source', true );
	}

	/**
	 * Best-effort attribution of the wp_mail() caller by scanning the backtrace
	 * for the first frame outside WP core and this module. Mirrors legacy ux1.
	 */
	private function detect_source(): string {
		$trace = debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS, 12 );

		foreach ( $trace as $frame ) {
			$file = $frame['file'] ?? '';
			if ( '' === $file ) {
				continue;
			}

			$file = str_replace( '\\', '/', $file );

			// Skip WordPress core and this module's own frames.
			if (
				false !== strpos( $file, '/wp-includes/' ) ||
				false !== strpos( $file, '/wp-admin/includes/' ) ||
				false !== strpos( $file, '/EmailLog/' ) ||
				false !== strpos( $file, '/email-log/' )
			) {
				continue;
			}

			if ( preg_match( '#/plugins/([^/]+)/#', $file, $m ) ) {
				return 'plugin: ' . $m[1];
			}
			if ( preg_match( '#/themes/([^/]+)/#', $file, $m ) ) {
				return 'theme: ' . $m[1];
			}
			if ( false !== strpos( $file, '/mu-plugins/' ) ) {
				return 'mu-plugin: ' . basename( $file );
			}

			return basename( $file );
		}

		return 'WordPress';
	}

	/**
	 * Opportunistically prune entries past retention, at most once/hour
	 * (transient-gated compare-and-swap, same pattern as EmailHealth).
	 */
	private function maybe_prune(): void {
		if ( false !== get_transient( self::RETENTION_LOCK ) ) {
			return;
		}
		set_transient( self::RETENTION_LOCK, 1, HOUR_IN_SECONDS );
		$this->purge_old_entries();
	}

	/**
	 * Paginated log entries for the SPA table (list columns only - the large
	 * body/headers/attachments fields are fetched on demand via get_entry()).
	 *
	 * @param int $limit  Max rows (default 50, capped at 200).
	 * @param int $offset Offset.
	 * @return array{items:array<int,array<string,mixed>>,total:int}
	 */
	public function get_entries( int $limit = 50, int $offset = 0 ): array {
		global $wpdb;

		$limit  = max( 1, min( 200, $limit ) );
		$offset = max( 0, $offset );

		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}uxstudio_email_log" );
		$items = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, created_at, to_email, subject, status, error_message, source
				FROM {$wpdb->prefix}uxstudio_email_log ORDER BY id DESC LIMIT %d OFFSET %d",
				$limit,
				$offset
			),
			ARRAY_A
		);

		return array(
			'items' => is_array( $items ) ? $items : array(),
			'total' => $total,
		);
	}

	/**
	 * Full detail of a single entry, including body/headers/attachments.
	 * Attachments are decoded to an array of filenames.
	 *
	 * @param int $id Row id.
	 * @return array<string,mixed>|null
	 */
	public function get_entry( int $id ): ?array {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, created_at, to_email, subject, status, error_message, source, message, headers, attachments
				FROM {$wpdb->prefix}uxstudio_email_log WHERE id = %d",
				$id
			),
			ARRAY_A
		);

		if ( ! $row ) {
			return null;
		}

		$attachments = json_decode( (string) ( $row['attachments'] ?? '[]' ), true );
		$row['attachments'] = is_array( $attachments ) ? array_values( array_map( 'strval', $attachments ) ) : array();

		return $row;
	}

	/**
	 * Re-send a stored message via wp_mail(). The logger filter is detached for
	 * the duration so the resend is not itself captured as a new pending row;
	 * its outcome updates the ORIGINAL entry instead. Attachments were stored as
	 * filenames only, so they cannot be re-attached.
	 *
	 * @param int $id Row id.
	 * @return array{success:bool,message:string}
	 */
	public function resend_entry( int $id ): array {
		$entry = $this->get_entry( $id );
		if ( null === $entry ) {
			return array(
				'success' => false,
				'message' => __( 'Entry not found.', 'ux-studio' ),
			);
		}

		remove_filter( 'wp_mail', array( $this, 'capture' ), 999 );

		$sent = wp_mail(
			(string) $entry['to_email'],
			(string) $entry['subject'],
			(string) ( $entry['message'] ?? '' ),
			(string) ( $entry['headers'] ?? '' )
		);

		add_filter( 'wp_mail', array( $this, 'capture' ), 999 );

		ActivityLog::log(
			'email-log',
			'resend',
			'email',
			$id,
			array(
				'to'      => (string) $entry['to_email'],
				'subject' => (string) $entry['subject'],
				'sent'    => (bool) $sent,
			)
		);

		if ( $sent ) {
			return array(
				'success' => true,
				'message' => __( 'Email was re-sent.', 'ux-studio' ),
			);
		}

		return array(
			'success' => false,
			'message' => __( 'Re-sending failed.', 'ux-studio' ),
		);
	}

	/**
	 * Delete a single entry.
	 *
	 * @param int $id Row id.
	 */
	public function delete_entry( int $id ): bool {
		global $wpdb;
		return false !== $wpdb->delete( "{$wpdb->prefix}uxstudio_email_log", array( 'id' => $id ), array( '%d' ) );
	}

	/**
	 * Delete every entry.
	 */
	public function clear_all(): int {
		global $wpdb;
		return (int) $wpdb->query( "TRUNCATE TABLE {$wpdb->prefix}uxstudio_email_log" );
	}

	/**
	 * Delete entries older than the configured retention window.
	 */
	private function purge_old_entries(): int {
		global $wpdb;
		$days   = max( 1, (int) $this->settings->get( 'retention_days', 30 ) );
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) );

		return (int) $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->prefix}uxstudio_email_log WHERE created_at < %s",
				$cutoff
			)
		);
	}
}
