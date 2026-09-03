<?php
/**
 * Push Notifications module - Web Push (VAPID) subscriptions, notifications
 * and delivery/click event tracking.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\PushNotifications;

use UxStudio\Core\ActivityLog;
use UxStudio\Core\DB;
use UxStudio\Modules\BaseModule;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Ported/redesigned from the legacy push-notifications module. Delivers real
 * Web Push messages: the payload is encrypted per subscriber (RFC 8291,
 * aes128gcm) and POSTed to each endpoint with a VAPID-signed request
 * (RFC 8292). Supports immediate or scheduled sending, a basic audience
 * segment and delivery/click analytics.
 */
final class Module extends BaseModule {

	/** Cron hook for scheduled sends. */
	public const CRON_SEND = 'uxstudio_push_send';

	private Vapid $vapid;

	/**
	 * @param string $id   Module id.
	 * @param array  $meta meta.json contents.
	 */
	public function __construct( string $id, array $meta ) {
		parent::__construct( $id, $meta );
		$this->vapid = new Vapid();
	}

	/**
	 * Register hooks.
	 */
	public function boot(): void {
		add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );
		add_action( self::CRON_SEND, array( $this, 'cron_send' ) );

		DB::ensure_module_tables(
			'push-notifications',
			2,
			function ( int $from ): void {
				global $wpdb;
				$charset = $wpdb->get_charset_collate();
				dbDelta(
					"CREATE TABLE {$wpdb->prefix}uxstudio_push_subscribers (
						id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
						created_at DATETIME NOT NULL,
						endpoint TEXT NOT NULL,
						p256dh_key VARCHAR(255) NOT NULL DEFAULT '',
						auth_key VARCHAR(255) NOT NULL DEFAULT '',
						user_agent VARCHAR(255) NOT NULL DEFAULT '',
						endpoint_hash CHAR(64) NOT NULL DEFAULT '',
						PRIMARY KEY  (id),
						UNIQUE KEY endpoint_hash (endpoint_hash)
					) {$charset};"
				);
				dbDelta(
					"CREATE TABLE {$wpdb->prefix}uxstudio_push_notifications (
						id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
						created_at DATETIME NOT NULL,
						title VARCHAR(255) NOT NULL DEFAULT '',
						body TEXT NULL,
						url VARCHAR(500) NOT NULL DEFAULT '',
						icon VARCHAR(500) NOT NULL DEFAULT '',
						segment VARCHAR(40) NOT NULL DEFAULT 'all',
						scheduled_at DATETIME NULL,
						sent_count BIGINT NOT NULL DEFAULT 0,
						delivered_count BIGINT NOT NULL DEFAULT 0,
						PRIMARY KEY  (id),
						KEY created_at (created_at)
					) {$charset};"
				);
				dbDelta(
					"CREATE TABLE {$wpdb->prefix}uxstudio_push_events (
						id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
						created_at DATETIME NOT NULL,
						subscriber_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
						notification_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
						event VARCHAR(20) NOT NULL DEFAULT '',
						PRIMARY KEY  (id),
						KEY subscriber_id (subscriber_id),
						KEY notification_id (notification_id)
					) {$charset};"
				);
			}
		);
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
				'key'     => 'vapid_subject',
				'type'    => 'text',
				'label'   => __( 'VAPID contact (mailto: or URL)', 'ux-studio' ),
				'help'    => __( 'Used as the "sub" claim of the VAPID JWT sent with each push. Defaults to the site admin email.', 'ux-studio' ),
				'default' => '',
			),
		);
	}

	/**
	 * Adds the read-only public VAPID key / key presence flags.
	 */
	public function settings_values(): array {
		$values                 = parent::settings_values();
		$values['public_key']   = $this->vapid->public_key();
		$values['has_vapid_keys'] = $this->vapid->has_keys();
		if ( '' === (string) $values['vapid_subject'] ) {
			$values['vapid_subject'] = 'mailto:' . get_option( 'admin_email' );
		}
		return $values;
	}

	// =====================================================================
	// VAPID
	// =====================================================================

	/** Public VAPID key (generates a keypair on first access). */
	public function vapid_public_key(): string {
		return $this->vapid->public_key();
	}

	/** Force-generate a new VAPID keypair. */
	public function generate_vapid_keys(): bool {
		$ok = $this->vapid->generate();
		if ( $ok ) {
			ActivityLog::log( 'push-notifications', 'vapid_generate' );
		}
		return $ok;
	}

	// =====================================================================
	// Subscribers
	// =====================================================================

	/**
	 * All subscribers, newest first.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function list_subscribers(): array {
		global $wpdb;
		$rows = $wpdb->get_results(
			"SELECT id, created_at, endpoint, user_agent FROM {$wpdb->prefix}uxstudio_push_subscribers ORDER BY id DESC",
			ARRAY_A
		);
		$rows = is_array( $rows ) ? $rows : array();
		return array_map(
			static fn( array $row ): array => array(
				'id'         => (int) $row['id'],
				'created_at' => $row['created_at'],
				'endpoint'   => $row['endpoint'],
				'user_agent' => $row['user_agent'],
			),
			$rows
		);
	}

	/**
	 * Subscribe (or re-subscribe) a browser endpoint. Rate limiting is
	 * handled by the REST controller before this is called.
	 *
	 * @param array $data { endpoint, p256dh, auth, user_agent? }.
	 */
	public function subscribe( array $data ): bool {
		global $wpdb;

		$endpoint = esc_url_raw( (string) ( $data['endpoint'] ?? '' ) );
		$p256dh   = sanitize_text_field( (string) ( $data['p256dh'] ?? '' ) );
		$auth     = sanitize_text_field( (string) ( $data['auth'] ?? '' ) );

		if ( '' === $endpoint || '' === $p256dh || '' === $auth ) {
			return false;
		}

		$hash = hash( 'sha256', $endpoint );

		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$wpdb->prefix}uxstudio_push_subscribers (created_at, endpoint, p256dh_key, auth_key, user_agent, endpoint_hash)
				VALUES (%s, %s, %s, %s, %s, %s)
				ON DUPLICATE KEY UPDATE p256dh_key = VALUES(p256dh_key), auth_key = VALUES(auth_key), user_agent = VALUES(user_agent)",
				current_time( 'mysql' ),
				$endpoint,
				$p256dh,
				$auth,
				mb_substr( sanitize_text_field( (string) ( $data['user_agent'] ?? '' ) ), 0, 255 ),
				$hash
			)
		);

		return true;
	}

	/**
	 * Record a delivery/click event. subscriber is resolved from the
	 * endpoint (never a client-supplied numeric id, to avoid enumeration).
	 *
	 * @param string $endpoint        Subscriber's push endpoint.
	 * @param int    $notification_id Notification id (0 if unknown).
	 * @param string $event           delivered|clicked.
	 */
	public function record_event( string $endpoint, int $notification_id, string $event ): bool {
		if ( ! in_array( $event, array( 'delivered', 'clicked' ), true ) ) {
			return false;
		}

		global $wpdb;
		$hash          = hash( 'sha256', esc_url_raw( $endpoint ) );
		$subscriber_id = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT id FROM {$wpdb->prefix}uxstudio_push_subscribers WHERE endpoint_hash = %s", $hash )
		);
		if ( ! $subscriber_id ) {
			return false;
		}

		$wpdb->insert(
			"{$wpdb->prefix}uxstudio_push_events",
			array(
				'created_at'      => current_time( 'mysql' ),
				'subscriber_id'   => $subscriber_id,
				'notification_id' => $notification_id,
				'event'           => $event,
			),
			array( '%s', '%d', '%d', '%s' )
		);

		return true;
	}

	// =====================================================================
	// Notifications
	// =====================================================================

	/** Column list shared by list/get. */
	private const NOTIFICATION_COLS = 'id, created_at, title, body, url, icon, segment, scheduled_at, sent_count, delivered_count';

	/**
	 * All notifications, newest first.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function list_notifications(): array {
		global $wpdb;
		$rows = $wpdb->get_results(
			'SELECT ' . self::NOTIFICATION_COLS . " FROM {$wpdb->prefix}uxstudio_push_notifications ORDER BY id DESC",
			ARRAY_A
		);
		$rows = is_array( $rows ) ? $rows : array();
		return array_map( array( $this, 'format_notification' ), $rows );
	}

	/**
	 * @param int $id Notification id.
	 */
	public function get_notification( int $id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT ' . self::NOTIFICATION_COLS . " FROM {$wpdb->prefix}uxstudio_push_notifications WHERE id = %d",
				$id
			),
			ARRAY_A
		);
		return is_array( $row ) ? $this->format_notification( $row ) : null;
	}

	/**
	 * Create a draft notification (not sent).
	 *
	 * @param array $data { title, body, url?, icon?, segment? }.
	 */
	public function create_notification( array $data ): array {
		global $wpdb;

		$wpdb->insert(
			"{$wpdb->prefix}uxstudio_push_notifications",
			array(
				'created_at' => current_time( 'mysql' ),
				'title'      => mb_substr( sanitize_text_field( (string) ( $data['title'] ?? '' ) ), 0, 255 ),
				'body'       => sanitize_textarea_field( (string) ( $data['body'] ?? '' ) ),
				'url'        => esc_url_raw( (string) ( $data['url'] ?? '' ) ),
				'icon'       => esc_url_raw( (string) ( $data['icon'] ?? '' ) ),
				'segment'    => $this->sanitize_segment( (string) ( $data['segment'] ?? 'all' ) ),
				'sent_count' => 0,
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%d' )
		);

		$id = (int) $wpdb->insert_id;

		ActivityLog::log( 'push-notifications', 'create', 'notification', $id );

		return (array) $this->get_notification( $id );
	}

	/**
	 * Send a notification now, or schedule it for a future time. When
	 * $scheduled_at is a future timestamp the delivery is deferred to WP-Cron
	 * (self::CRON_SEND); otherwise it is delivered immediately.
	 *
	 * @param int    $id           Notification id.
	 * @param string $scheduled_at Optional 'Y-m-d H:i:s' (site time) in the future.
	 */
	public function send_notification( int $id, string $scheduled_at = '' ) {
		$existing = $this->get_notification( $id );
		if ( null === $existing ) {
			return new WP_Error( 'uxstudio_not_found', __( 'Notification not found.', 'ux-studio' ), array( 'status' => 404 ) );
		}
		if ( ! $this->vapid->has_keys() ) {
			return new WP_Error( 'uxstudio_no_vapid', __( 'Generate VAPID keys first.', 'ux-studio' ), array( 'status' => 409 ) );
		}

		$when = '' !== $scheduled_at ? strtotime( get_gmt_from_date( $scheduled_at ) . ' UTC' ) : 0;
		if ( $when && $when > time() ) {
			global $wpdb;
			$wpdb->update(
				"{$wpdb->prefix}uxstudio_push_notifications",
				array( 'scheduled_at' => gmdate( 'Y-m-d H:i:s', $when + ( (int) ( get_option( 'gmt_offset' ) * HOUR_IN_SECONDS ) ) ) ),
				array( 'id' => $id ),
				array( '%s' ),
				array( '%d' )
			);
			wp_schedule_single_event( $when, self::CRON_SEND, array( $id ) );
			ActivityLog::log( 'push-notifications', 'schedule', 'notification', $id, array( 'at' => $scheduled_at ) );
			return (array) $this->get_notification( $id );
		}

		return $this->deliver( $id );
	}

	/**
	 * WP-Cron callback for scheduled sends.
	 *
	 * @param int $id Notification id.
	 */
	public function cron_send( int $id ): void {
		$this->deliver( $id );
	}

	/**
	 * Encrypt + POST the notification to every targeted subscriber, recording
	 * per-subscriber delivery events and pruning expired subscriptions.
	 *
	 * @param int $id Notification id.
	 * @return array Updated notification row.
	 */
	private function deliver( int $id ): array {
		global $wpdb;

		$notification = $this->get_notification( $id );
		if ( null === $notification ) {
			return array();
		}

		$subject = (string) $this->settings->get( 'vapid_subject', '' );
		if ( '' === $subject ) {
			$subject = 'mailto:' . get_option( 'admin_email' );
		}

		$sender = new Sender( $this->vapid );
		$result = $sender->send(
			$notification,
			$subject,
			function ( int $subscriber_id, string $event ) use ( $id ): void {
				$this->insert_event( $subscriber_id, $id, $event );
			},
			function ( int $subscriber_id ): void {
				$this->delete_subscriber( $subscriber_id );
			}
		);

		$wpdb->update(
			"{$wpdb->prefix}uxstudio_push_notifications",
			array(
				'sent_count'      => (int) $result['targeted'],
				'delivered_count' => (int) $result['delivered'],
				'scheduled_at'    => null,
			),
			array( 'id' => $id ),
			array( '%d', '%d', '%s' ),
			array( '%d' )
		);

		ActivityLog::log( 'push-notifications', 'send', 'notification', $id, $result );

		return (array) $this->get_notification( $id );
	}

	/**
	 * Analytics summary: subscribers, notifications and per-event counts.
	 *
	 * @return array<string, mixed>
	 */
	public function analytics(): array {
		global $wpdb;
		$subscribers   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}uxstudio_push_subscribers" );
		$notifications = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}uxstudio_push_notifications" );
		$events        = $wpdb->get_results(
			"SELECT event, COUNT(*) AS c FROM {$wpdb->prefix}uxstudio_push_events GROUP BY event",
			ARRAY_A
		);
		$by_event = array();
		foreach ( is_array( $events ) ? $events : array() as $row ) {
			$by_event[ (string) $row['event'] ] = (int) $row['c'];
		}
		return array(
			'subscribers'   => $subscribers,
			'notifications' => $notifications,
			'delivered'     => $by_event['delivered'] ?? 0,
			'failed'        => $by_event['failed'] ?? 0,
			'clicked'       => $by_event['clicked'] ?? 0,
		);
	}

	/**
	 * Insert a raw analytics event (subscriber_id already resolved).
	 *
	 * @param int    $subscriber_id   Subscriber id.
	 * @param int    $notification_id Notification id.
	 * @param string $event           delivered|failed|clicked.
	 */
	private function insert_event( int $subscriber_id, int $notification_id, string $event ): void {
		global $wpdb;
		$wpdb->insert(
			"{$wpdb->prefix}uxstudio_push_events",
			array(
				'created_at'      => current_time( 'mysql' ),
				'subscriber_id'   => $subscriber_id,
				'notification_id' => $notification_id,
				'event'           => substr( $event, 0, 20 ),
			),
			array( '%s', '%d', '%d', '%s' )
		);
	}

	/**
	 * Remove a subscriber whose subscription the push service reported expired.
	 *
	 * @param int $subscriber_id Subscriber id.
	 */
	private function delete_subscriber( int $subscriber_id ): void {
		global $wpdb;
		$wpdb->delete( "{$wpdb->prefix}uxstudio_push_subscribers", array( 'id' => $subscriber_id ), array( '%d' ) );
	}

	/**
	 * Whitelist the segment value.
	 *
	 * @param string $segment Raw segment.
	 */
	private function sanitize_segment( string $segment ): string {
		return in_array( $segment, array( 'all', 'recent_30d' ), true ) ? $segment : 'all';
	}

	/**
	 * Normalize a notification row for REST output.
	 *
	 * @param array $row Raw row.
	 */
	private function format_notification( array $row ): array {
		$sent_count   = (int) $row['sent_count'];
		$scheduled_at = $row['scheduled_at'] ?? null;
		$scheduled    = $scheduled_at && strtotime( (string) $scheduled_at ) > time();

		if ( $scheduled && 0 === $sent_count ) {
			$status = 'scheduled';
		} elseif ( $sent_count > 0 ) {
			$status = 'sent';
		} else {
			$status = 'draft';
		}

		return array(
			'id'              => (int) $row['id'],
			'created_at'      => $row['created_at'],
			'title'           => $row['title'],
			'body'            => $row['body'],
			'url'             => $row['url'] ?? '',
			'icon'            => $row['icon'] ?? '',
			'segment'         => $row['segment'] ?? 'all',
			'scheduled_at'    => $scheduled_at,
			'sent_count'      => $sent_count,
			'delivered_count' => (int) ( $row['delivered_count'] ?? 0 ),
			'status'          => $status,
		);
	}
}
