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
 * Ported/redesigned from the legacy push-notifications module.
 *
 * DEVIATION (documented, see project report): actually delivering a Web Push
 * message (encrypting the payload per-subscriber and POSTing to each
 * endpoint with a signed VAPID JWT) is a substantial standalone feature. This
 * pass implements the full subscribe/track/manage pipeline and VAPID keypair
 * generation, but Module::send_notification() only records the notification
 * as "queued" (sent_count = -1) and logs the action - actual delivery is a
 * TODO for a follow-up change.
 */
final class Module extends BaseModule {

	private const SENT_QUEUED = -1;

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

		DB::ensure_module_tables(
			'push-notifications',
			1,
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
						sent_count BIGINT NOT NULL DEFAULT 0,
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
				'help'    => __( 'Used as the "sub" claim of the VAPID JWT once real delivery is implemented.', 'ux-studio' ),
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

	/**
	 * All notifications, newest first.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function list_notifications(): array {
		global $wpdb;
		$rows = $wpdb->get_results(
			"SELECT id, created_at, title, body, sent_count FROM {$wpdb->prefix}uxstudio_push_notifications ORDER BY id DESC",
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
				"SELECT id, created_at, title, body, sent_count FROM {$wpdb->prefix}uxstudio_push_notifications WHERE id = %d",
				$id
			),
			ARRAY_A
		);
		return is_array( $row ) ? $this->format_notification( $row ) : null;
	}

	/**
	 * Create a draft notification (not sent).
	 *
	 * @param array $data { title, body }.
	 */
	public function create_notification( array $data ): array {
		global $wpdb;

		$wpdb->insert(
			"{$wpdb->prefix}uxstudio_push_notifications",
			array(
				'created_at' => current_time( 'mysql' ),
				'title'      => mb_substr( sanitize_text_field( (string) ( $data['title'] ?? '' ) ), 0, 255 ),
				'body'       => sanitize_textarea_field( (string) ( $data['body'] ?? '' ) ),
				'sent_count' => 0,
			),
			array( '%s', '%s', '%s', '%d' )
		);

		$id = (int) $wpdb->insert_id;

		ActivityLog::log( 'push-notifications', 'create', 'notification', $id );

		return (array) $this->get_notification( $id );
	}

	/**
	 * Mark a notification as queued for sending. Actual Web Push delivery
	 * (payload encryption + VAPID-signed POST to each subscriber endpoint)
	 * is NOT implemented in this pass - see the class docblock. This only
	 * flips sent_count to the "queued" sentinel (-1) and logs the intent.
	 *
	 * @param int $id Notification id.
	 */
	public function queue_send( int $id ) {
		global $wpdb;

		$existing = $this->get_notification( $id );
		if ( null === $existing ) {
			return new WP_Error( 'uxstudio_not_found', __( 'Notification not found.', 'ux-studio' ), array( 'status' => 404 ) );
		}

		$wpdb->update(
			"{$wpdb->prefix}uxstudio_push_notifications",
			array( 'sent_count' => self::SENT_QUEUED ),
			array( 'id' => $id ),
			array( '%d' ),
			array( '%d' )
		);

		// TODO: real delivery. For each row in uxstudio_push_subscribers, build
		// an encrypted Web Push payload (RFC 8291) and POST it to `endpoint`
		// with a VAPID JWT (RFC 8292) signed with Vapid::private_key_pem(),
		// then update sent_count to the number of successful deliveries.
		ActivityLog::log( 'push-notifications', 'queue_send', 'notification', $id, array( 'note' => 'delivery not implemented, TODO' ) );

		return (array) $this->get_notification( $id );
	}

	/**
	 * Normalize a notification row for REST output.
	 *
	 * @param array $row Raw row.
	 */
	private function format_notification( array $row ): array {
		$sent_count = (int) $row['sent_count'];
		return array(
			'id'         => (int) $row['id'],
			'created_at' => $row['created_at'],
			'title'      => $row['title'],
			'body'       => $row['body'],
			'sent_count' => $sent_count,
			'status'     => self::SENT_QUEUED === $sent_count ? 'queued' : ( $sent_count > 0 ? 'sent' : 'draft' ),
		);
	}
}
