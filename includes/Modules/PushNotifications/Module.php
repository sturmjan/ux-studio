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
use UxStudio\Core\Retention;
use UxStudio\Modules\BaseModule;
use WP_Error;
use WP_Post;

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

	/** Cron hook building + sending the notification for a freshly published post. */
	public const CRON_AUTO = 'uxstudio_push_auto';

	/** Post meta marking a post as already announced. The legacy module wrote _ux1_push_sent; both are honoured. */
	private const META_SENT = '_uxstudio_push_sent';

	/** Daily cron hook purging old push_events rows. */
	public const CRON_RETENTION = 'uxstudio_push_retention';

	/**
	 * Push service hosts a subscription endpoint may point at (exact host or,
	 * with a leading dot, any subdomain). Filterable via
	 * `uxstudio_push_allowed_hosts`. Anything else is refused at subscribe and
	 * send time - the endpoint comes from an anonymous request, so without
	 * this the server would POST to any URL an attacker stores (SSRF).
	 */
	private const ALLOWED_PUSH_HOSTS = array(
		'fcm.googleapis.com',
		'.push.services.mozilla.com',
		'.notify.windows.com',
		'web.push.apple.com',
		'.push.apple.com',
	);

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
		add_action( self::CRON_RETENTION, array( $this, 'purge_old_events' ) );
		add_action( self::CRON_AUTO, array( $this, 'cron_auto_send' ) );
		Retention::ensure_scheduled( self::CRON_RETENTION );

		// Service worker is served from the site root at the legacy URL
		// (ux1-push-sw.js); init so rest_url()/home_url() are fully available.
		add_action( 'init', array( $this, 'maybe_serve_service_worker' ), 1 );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_frontend' ) );
		if ( $this->settings->get( 'auto_send_enabled', false ) ) {
			add_action( 'transition_post_status', array( $this, 'on_post_status' ), 10, 3 );
		}

		DB::ensure_module_tables(
			'push-notifications',
			4,
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
						user_id BIGINT UNSIGNED NULL DEFAULT NULL,
						segments TEXT NULL,
						failed_count INT NOT NULL DEFAULT 0,
						PRIMARY KEY  (id),
						UNIQUE KEY endpoint_hash (endpoint_hash),
						KEY user_id (user_id)
					) {$charset};"
				);
				// Same column set as the legacy ux1_push_segments table: the
				// Migrator blind-copies that table over this one, so the schemas
				// must stay identical.
				dbDelta(
					"CREATE TABLE {$wpdb->prefix}uxstudio_push_segments (
						id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
						name VARCHAR(255) NOT NULL,
						slug VARCHAR(255) NOT NULL,
						type VARCHAR(50) NOT NULL DEFAULT 'custom',
						reference_id BIGINT UNSIGNED NULL DEFAULT NULL,
						created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
						PRIMARY KEY  (id),
						UNIQUE KEY slug (slug),
						KEY type (type)
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
						segments TEXT NULL,
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
						KEY notification_id (notification_id),
						KEY created_at (created_at)
					) {$charset};"
				);
				// v3: endpoints are now allowlisted; drop anything stored before
				// that does not point at a known push service.
				if ( $from > 0 && $from < 3 ) {
					$this->prune_disallowed_subscribers();
				}
			}
		);
	}

	/**
	 * Remove the retention cron when the module is switched off.
	 */
	public function on_disable(): void {
		Retention::unschedule( self::CRON_RETENTION );
	}

	/**
	 * Daily retention purge of the delivery/click event log.
	 */
	public function purge_old_events(): void {
		Retention::purge( 'uxstudio_push_events', (int) $this->settings->get( 'retention_days', Retention::DEFAULT_DAYS ) );
	}

	/**
	 * Whether a subscription endpoint is an https URL on an allowlisted push
	 * service host (see ALLOWED_PUSH_HOSTS).
	 *
	 * @param string $endpoint Endpoint URL.
	 */
	public static function is_allowed_endpoint( string $endpoint ): bool {
		$parts = wp_parse_url( $endpoint );
		if ( ! is_array( $parts ) || 'https' !== strtolower( (string) ( $parts['scheme'] ?? '' ) ) ) {
			return false;
		}
		if ( isset( $parts['port'] ) && 443 !== (int) $parts['port'] ) {
			return false;
		}
		if ( isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
			return false;
		}
		$host = strtolower( rtrim( (string) ( $parts['host'] ?? '' ), '.' ) );
		if ( '' === $host ) {
			return false;
		}

		/**
		 * Push service hosts a Web Push endpoint may use. Exact host, or a
		 * leading dot for "any subdomain of".
		 *
		 * @param string[] $hosts Allowed hosts.
		 */
		$allowed = (array) apply_filters( 'uxstudio_push_allowed_hosts', self::ALLOWED_PUSH_HOSTS );
		foreach ( $allowed as $pattern ) {
			$pattern = strtolower( (string) $pattern );
			if ( '' === $pattern ) {
				continue;
			}
			if ( '.' === $pattern[0] ) {
				if ( str_ends_with( $host, $pattern ) && strlen( $host ) > strlen( $pattern ) ) {
					return true;
				}
			} elseif ( $host === $pattern ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Delete stored subscriptions whose endpoint fails is_allowed_endpoint().
	 *
	 * @return int Number of removed subscriptions.
	 */
	public function prune_disallowed_subscribers(): int {
		global $wpdb;
		$rows    = $wpdb->get_results( "SELECT id, endpoint FROM {$wpdb->prefix}uxstudio_push_subscribers", ARRAY_A );
		$removed = 0;
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			if ( ! self::is_allowed_endpoint( (string) $row['endpoint'] ) ) {
				$this->delete_subscriber( (int) $row['id'] );
				++$removed;
			}
		}
		if ( $removed > 0 ) {
			ActivityLog::log( 'push-notifications', 'prune_disallowed', '', 0, array( 'removed' => $removed ) );
		}
		return $removed;
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
			array(
				'key'     => 'retention_days',
				'type'    => 'number',
				'label'   => __( 'Event log retention (days)', 'ux-studio' ),
				'help'    => __( 'Delivery/click events older than this are deleted daily. 0 = keep forever.', 'ux-studio' ),
				'default' => Retention::DEFAULT_DAYS,
			),
			array(
				'key'     => 'default_icon',
				'type'    => 'text',
				'label'   => __( 'Default icon URL', 'ux-studio' ),
				'help'    => __( 'Used for notifications that do not set their own icon (192x192 px recommended).', 'ux-studio' ),
				'default' => '',
			),
			array(
				'key'     => 'max_failed',
				'type'    => 'number',
				'label'   => __( 'Max. failed sends', 'ux-studio' ),
				'help'    => __( 'A subscriber is removed after this many failed sends in a row (1-10). A successful send resets the counter.', 'ux-studio' ),
				'default' => 3,
			),
			array(
				'key'     => 'auto_send_enabled',
				'type'    => 'toggle',
				'label'   => __( 'Notify subscribers when a post is published', 'ux-studio' ),
				'help'    => __( 'Automatically sends a push notification (title, excerpt, featured image) when a post of a selected type is first published.', 'ux-studio' ),
				'default' => false,
			),
			array(
				'key'     => 'auto_send_post_types',
				'type'    => 'multiselect',
				'label'   => __( 'Post types announced automatically', 'ux-studio' ),
				'options' => self::post_type_options(),
				'default' => array( 'post' ),
			),
			array(
				'key'     => 'prompt_delay',
				'type'    => 'number',
				'label'   => __( 'Opt-in prompt delay (seconds)', 'ux-studio' ),
				'help'    => __( 'How long after page load the opt-in prompt appears (0-120).', 'ux-studio' ),
				'default' => 5,
			),
			array(
				'key'     => 'prompt_scroll',
				'type'    => 'number',
				'label'   => __( 'Opt-in prompt scroll trigger (%)', 'ux-studio' ),
				'help'    => __( '0 = ignore scrolling, otherwise also show the prompt once this share of the page has been scrolled.', 'ux-studio' ),
				'default' => 0,
			),
			array(
				'key'     => 'prompt_position',
				'type'    => 'select',
				'label'   => __( 'Opt-in prompt position', 'ux-studio' ),
				'options' => array(
					'top-left'     => __( 'Top left', 'ux-studio' ),
					'top-right'    => __( 'Top right', 'ux-studio' ),
					'bottom-right' => __( 'Bottom right', 'ux-studio' ),
					'bottom-left'  => __( 'Bottom left', 'ux-studio' ),
					'center'       => __( 'Center', 'ux-studio' ),
				),
				'default' => 'top-left',
			),
			array(
				'key'     => 'prompt_title',
				'type'    => 'text',
				'label'   => __( 'Opt-in prompt title', 'ux-studio' ),
				'default' => self::default_prompt_texts()['title'],
			),
			array(
				'key'     => 'prompt_text',
				'type'    => 'textarea',
				'label'   => __( 'Opt-in prompt text', 'ux-studio' ),
				'default' => self::default_prompt_texts()['text'],
			),
			array(
				'key'     => 'prompt_btn_allow',
				'type'    => 'text',
				'label'   => __( 'Opt-in prompt "allow" button', 'ux-studio' ),
				'default' => self::default_prompt_texts()['allow'],
			),
			array(
				'key'     => 'prompt_btn_later',
				'type'    => 'text',
				'label'   => __( 'Opt-in prompt "later" button', 'ux-studio' ),
				'default' => self::default_prompt_texts()['later'],
			),
			array(
				'key'     => 'prompt_bg_color',
				'type'    => 'color',
				'label'   => __( 'Opt-in prompt background colour', 'ux-studio' ),
				'default' => '#ffffff',
			),
			array(
				'key'     => 'prompt_btn_color',
				'type'    => 'color',
				'label'   => __( 'Opt-in prompt accent / button colour', 'ux-studio' ),
				'default' => '#2271b1',
			),
		);
	}

	/**
	 * Default opt-in prompt copy (English source strings, translated per locale).
	 *
	 * @return array{title:string,text:string,allow:string,later:string}
	 */
	private static function default_prompt_texts(): array {
		return array(
			'title' => __( 'Do you want to receive notifications?', 'ux-studio' ),
			'text'  => __( 'Be the first to know about new posts.', 'ux-studio' ),
			'allow' => __( 'Allow', 'ux-studio' ),
			'later' => __( 'Later', 'ux-studio' ),
		);
	}

	/**
	 * Public post types as value => label options.
	 *
	 * @return array<string,string>
	 */
	private static function post_type_options(): array {
		$options = array();
		foreach ( get_post_types( array( 'public' => true ), 'objects' ) as $post_type ) {
			$options[ $post_type->name ] = $post_type->labels->singular_name ?: $post_type->label;
		}
		return $options;
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
			"SELECT id, created_at, endpoint, user_agent, user_id, segments, failed_count FROM {$wpdb->prefix}uxstudio_push_subscribers ORDER BY id DESC",
			ARRAY_A
		);
		$rows = is_array( $rows ) ? $rows : array();
		return array_map(
			fn( array $row ): array => array(
				'id'         => (int) $row['id'],
				'created_at' => $row['created_at'],
				'endpoint'   => $row['endpoint'],
				'user_agent' => $row['user_agent'],
				'user_id'    => (int) $row['user_id'],
				'segments'   => $this->decode_slugs( $row['segments'] ?? '' ),
				'failed'     => (int) $row['failed_count'],
			),
			$rows
		);
	}

	/**
	 * Subscribe (or re-subscribe) a browser endpoint. Rate limiting is
	 * handled by the REST controller before this is called.
	 *
	 * @param array $data { endpoint, p256dh, auth, user_agent?, user_id? }.
	 */
	public function subscribe( array $data ): bool {
		global $wpdb;

		$endpoint = esc_url_raw( (string) ( $data['endpoint'] ?? '' ) );
		$p256dh   = sanitize_text_field( (string) ( $data['p256dh'] ?? '' ) );
		$auth     = sanitize_text_field( (string) ( $data['auth'] ?? '' ) );
		$user_id  = absint( $data['user_id'] ?? 0 );

		if ( '' === $endpoint || '' === $p256dh || '' === $auth ) {
			return false;
		}
		if ( ! self::is_allowed_endpoint( $endpoint ) ) {
			return false;
		}

		$hash = hash( 'sha256', $endpoint );

		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$wpdb->prefix}uxstudio_push_subscribers (created_at, endpoint, p256dh_key, auth_key, user_agent, endpoint_hash, user_id)
				VALUES (%s, %s, %s, %s, %s, %s, NULLIF(%d, 0))
				ON DUPLICATE KEY UPDATE p256dh_key = VALUES(p256dh_key), auth_key = VALUES(auth_key), user_agent = VALUES(user_agent), failed_count = 0, user_id = COALESCE(VALUES(user_id), user_id)",
				current_time( 'mysql' ),
				$endpoint,
				$p256dh,
				$auth,
				mb_substr( sanitize_text_field( (string) ( $data['user_agent'] ?? '' ) ), 0, 255 ),
				$hash,
				$user_id
			)
		);

		return true;
	}

	/**
	 * Remove a subscription by its endpoint (visitor opted out / browser rotated it).
	 *
	 * @param string $endpoint Push endpoint.
	 */
	public function unsubscribe( string $endpoint ): bool {
		global $wpdb;
		$hash = hash( 'sha256', esc_url_raw( $endpoint ) );
		return (bool) $wpdb->delete( "{$wpdb->prefix}uxstudio_push_subscribers", array( 'endpoint_hash' => $hash ), array( '%s' ) );
	}

	/**
	 * Admin: delete one subscriber.
	 *
	 * @param int $id Subscriber id.
	 */
	public function remove_subscriber( int $id ): bool {
		$ok = $this->delete_subscriber( $id );
		if ( $ok ) {
			ActivityLog::log( 'push-notifications', 'delete_subscriber', 'subscriber', $id );
		}
		return $ok;
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
	private const NOTIFICATION_COLS = 'id, created_at, title, body, url, icon, segment, segments, scheduled_at, sent_count, delivered_count';

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

		$icon = esc_url_raw( (string) ( $data['icon'] ?? '' ) );
		if ( '' === $icon ) {
			$icon = esc_url_raw( (string) $this->settings->get( 'default_icon', '' ) );
		}

		$wpdb->insert(
			"{$wpdb->prefix}uxstudio_push_notifications",
			array(
				'created_at' => current_time( 'mysql' ),
				'title'      => mb_substr( sanitize_text_field( (string) ( $data['title'] ?? '' ) ), 0, 255 ),
				'body'       => sanitize_textarea_field( (string) ( $data['body'] ?? '' ) ),
				'url'        => esc_url_raw( (string) ( $data['url'] ?? '' ) ),
				'icon'       => mb_substr( $icon, 0, 500 ),
				'segment'    => $this->sanitize_segment( (string) ( $data['segment'] ?? 'all' ) ),
				'segments'   => (string) wp_json_encode( $this->valid_segment_slugs( (array) ( $data['segments'] ?? array() ) ) ),
				'sent_count' => 0,
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d' )
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
				$this->track_delivery_result( $subscriber_id, $event );
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
	private function delete_subscriber( int $subscriber_id ): bool {
		global $wpdb;
		return (bool) $wpdb->delete( "{$wpdb->prefix}uxstudio_push_subscribers", array( 'id' => $subscriber_id ), array( '%d' ) );
	}

	/**
	 * Maintain the consecutive-failure counter: a delivered push resets it, a
	 * failed one increments it and the subscriber is removed once it reaches
	 * the max_failed setting (1-10, default 3).
	 *
	 * @param int    $subscriber_id Subscriber id.
	 * @param string $event         delivered|failed.
	 */
	private function track_delivery_result( int $subscriber_id, string $event ): void {
		global $wpdb;
		$table = "{$wpdb->prefix}uxstudio_push_subscribers";
		if ( 'delivered' === $event ) {
			$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET failed_count = 0 WHERE id = %d AND failed_count > 0", $subscriber_id ) );
			return;
		}
		if ( 'failed' !== $event ) {
			return;
		}
		$max = max( 1, min( 10, (int) $this->settings->get( 'max_failed', 3 ) ) );
		$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET failed_count = failed_count + 1 WHERE id = %d", $subscriber_id ) );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE id = %d AND failed_count >= %d", $subscriber_id, $max ) );
	}

	// =====================================================================
	// Frontend: service worker + opt-in script
	// =====================================================================

	/**
	 * Serve the push service worker when its (legacy-compatible) URL is requested.
	 */
	public function maybe_serve_service_worker(): void {
		if ( ! ServiceWorker::is_request() ) {
			return;
		}
		$key = $this->vapid->public_key();
		if ( '' === $key ) {
			status_header( 404 );
			exit;
		}
		ServiceWorker::serve( $key );
		exit;
	}

	/**
	 * Enqueue the visitor opt-in script on the public site.
	 */
	public function enqueue_frontend(): void {
		if ( is_admin() || is_customize_preview() ) {
			return;
		}
		$key = $this->vapid->public_key();
		if ( '' === $key ) {
			return;
		}

		$rel     = 'includes/Modules/PushNotifications/assets/push-subscribe.js';
		$version = file_exists( UXSTUDIO_PATH . $rel ) ? (string) filemtime( UXSTUDIO_PATH . $rel ) : UXSTUDIO_VERSION;
		wp_enqueue_script( 'uxstudio-push-subscribe', UXSTUDIO_URL . $rel, array(), $version, true );

		$defaults = self::default_prompt_texts();
		$config   = array(
			'vapidPublicKey' => $key,
			'swUrl'          => ServiceWorker::url(),
			'swScope'        => ServiceWorker::scope(),
			'subscribeUrl'   => rest_url( 'uxstudio/v1/push-notifications/subscribe' ),
			'nonce'          => wp_create_nonce( 'wp_rest' ),
			'userId'         => get_current_user_id(),
			'prompt'         => array(
				'delay'    => max( 0, min( 120, (int) $this->settings->get( 'prompt_delay', 5 ) ) ),
				'scroll'   => max( 0, min( 100, (int) $this->settings->get( 'prompt_scroll', 0 ) ) ),
				'position' => (string) $this->settings->get( 'prompt_position', 'top-left' ),
				'title'    => (string) $this->settings->get( 'prompt_title', $defaults['title'] ),
				'text'     => (string) $this->settings->get( 'prompt_text', $defaults['text'] ),
				'btnAllow' => (string) $this->settings->get( 'prompt_btn_allow', $defaults['allow'] ),
				'btnLater' => (string) $this->settings->get( 'prompt_btn_later', $defaults['later'] ),
				'bgColor'  => (string) $this->settings->get( 'prompt_bg_color', '#ffffff' ),
				'btnColor' => (string) $this->settings->get( 'prompt_btn_color', '#2271b1' ),
			),
		);
		wp_add_inline_script( 'uxstudio-push-subscribe', 'window.uxsPush = ' . wp_json_encode( $config ) . ';', 'before' );
	}

	// =====================================================================
	// Auto-send on publish
	// =====================================================================

	/**
	 * transition_post_status: announce a post the first time it is published.
	 * Delivery is deferred to WP-Cron so the publish request stays fast and the
	 * featured image (saved after the status transition in the block editor) is
	 * available.
	 *
	 * @param string  $new_status New status.
	 * @param string  $old_status Old status.
	 * @param WP_Post $post       Post.
	 */
	public function on_post_status( string $new_status, string $old_status, WP_Post $post ): void {
		if ( 'publish' !== $new_status || 'publish' === $old_status ) {
			return;
		}
		if ( wp_is_post_revision( $post->ID ) || wp_is_post_autosave( $post->ID ) ) {
			return;
		}
		$allowed = (array) $this->settings->get( 'auto_send_post_types', array( 'post' ) );
		if ( ! in_array( $post->post_type, $allowed, true ) ) {
			return;
		}
		// Already announced (this module or the legacy one) - never twice.
		if ( get_post_meta( $post->ID, self::META_SENT, true ) || get_post_meta( $post->ID, '_ux1_push_sent', true ) ) {
			return;
		}
		update_post_meta( $post->ID, self::META_SENT, current_time( 'mysql' ) );
		wp_schedule_single_event( time() + 5, self::CRON_AUTO, array( (int) $post->ID ) );
	}

	/**
	 * WP-Cron: build the notification for a published post and send it.
	 *
	 * @param int $post_id Post id.
	 */
	public function cron_auto_send( int $post_id ): void {
		$post = get_post( $post_id );
		if ( ! $post instanceof WP_Post || 'publish' !== $post->post_status || ! $this->vapid->has_keys() ) {
			return;
		}

		$icon  = '';
		$thumb = get_post_thumbnail_id( $post->ID );
		if ( $thumb ) {
			$icon = (string) wp_get_attachment_image_url( (int) $thumb, 'thumbnail' );
		}

		$notification = $this->create_notification(
			array(
				'title'   => html_entity_decode( get_the_title( $post ), ENT_QUOTES, 'UTF-8' ),
				'body'    => wp_trim_words( wp_strip_all_tags( strip_shortcodes( (string) $post->post_content ) ), 20, '...' ),
				'url'     => (string) get_permalink( $post ),
				'icon'    => $icon,
				'segment' => 'all',
			)
		);
		if ( ! empty( $notification['id'] ) ) {
			$this->deliver( (int) $notification['id'] );
		}
	}

	// =====================================================================
	// Test send
	// =====================================================================

	/**
	 * Send a notification to the current user's own subscription(s) only. Unlike
	 * the legacy module there is deliberately NO fallback to some other
	 * visitor's device when the admin has no subscription.
	 *
	 * @param int $id Notification id.
	 * @return array|WP_Error Delivery counts.
	 */
	public function send_test( int $id ) {
		global $wpdb;
		$notification = $this->get_notification( $id );
		if ( null === $notification ) {
			return new WP_Error( 'uxstudio_not_found', __( 'Notification not found.', 'ux-studio' ), array( 'status' => 404 ) );
		}
		if ( ! $this->vapid->has_keys() ) {
			return new WP_Error( 'uxstudio_no_vapid', __( 'Generate VAPID keys first.', 'ux-studio' ), array( 'status' => 409 ) );
		}
		$user_id = get_current_user_id();
		$rows    = array();
		if ( $user_id ) {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT id, endpoint, p256dh_key, auth_key, segments FROM {$wpdb->prefix}uxstudio_push_subscribers WHERE user_id = %d",
					$user_id
				),
				ARRAY_A
			);
		}
		if ( ! is_array( $rows ) || ! $rows ) {
			return new WP_Error(
				'uxstudio_no_test_subscription',
				__( 'Your account has no push subscription. Open the public site while logged in and allow notifications, then try again.', 'ux-studio' ),
				array( 'status' => 409 )
			);
		}

		$subject = (string) $this->settings->get( 'vapid_subject', '' );
		if ( '' === $subject ) {
			$subject = 'mailto:' . get_option( 'admin_email' );
		}
		$sender = new Sender( $this->vapid );
		$result = $sender->send_test(
			$rows,
			$notification,
			$subject,
			function ( int $subscriber_id, string $event ): void {
				$this->track_delivery_result( $subscriber_id, $event );
			},
			function ( int $subscriber_id ): void {
				$this->delete_subscriber( $subscriber_id );
			}
		);
		ActivityLog::log( 'push-notifications', 'send_test', 'notification', $id, $result );
		return $result;
	}

	// =====================================================================
	// Segments
	// =====================================================================

	/**
	 * All segments with their subscriber counts.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function list_segments(): array {
		global $wpdb;
		$rows = $wpdb->get_results( "SELECT id, name, slug, type, created_at FROM {$wpdb->prefix}uxstudio_push_segments ORDER BY type ASC, name ASC", ARRAY_A );
		$rows = is_array( $rows ) ? $rows : array();

		$counts = array();
		$subs   = $wpdb->get_col( "SELECT segments FROM {$wpdb->prefix}uxstudio_push_subscribers WHERE segments IS NOT NULL AND segments <> ''" );
		foreach ( is_array( $subs ) ? $subs : array() as $json ) {
			foreach ( $this->decode_slugs( $json ) as $slug ) {
				$counts[ $slug ] = ( $counts[ $slug ] ?? 0 ) + 1;
			}
		}

		return array_map(
			static fn( array $row ): array => array(
				'id'          => (int) $row['id'],
				'name'        => $row['name'],
				'slug'        => $row['slug'],
				'type'        => $row['type'],
				'created_at'  => $row['created_at'],
				'subscribers' => $counts[ $row['slug'] ] ?? 0,
			),
			$rows
		);
	}

	/**
	 * Create a custom segment (slug made unique like the legacy module did).
	 *
	 * @param string $name Segment name.
	 * @return array|WP_Error Created segment.
	 */
	public function create_segment( string $name ) {
		global $wpdb;
		$table = "{$wpdb->prefix}uxstudio_push_segments";
		$name  = mb_substr( sanitize_text_field( $name ), 0, 255 );
		if ( '' === $name ) {
			return new WP_Error( 'uxstudio_invalid', __( 'Name is required.', 'ux-studio' ), array( 'status' => 400 ) );
		}
		$slug = sanitize_title( $name );
		if ( '' === $slug ) {
			$slug = 'segment-' . wp_rand( 1000, 9999 );
		}
		if ( $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE slug = %s", $slug ) ) ) {
			$slug .= '-' . wp_rand( 100, 999 );
		}
		$ok = $wpdb->insert(
			$table,
			array(
				'name'       => $name,
				'slug'       => mb_substr( $slug, 0, 255 ),
				'type'       => 'custom',
				'created_at' => current_time( 'mysql' ),
			),
			array( '%s', '%s', '%s', '%s' )
		);
		if ( ! $ok ) {
			return new WP_Error( 'uxstudio_segment_failed', __( 'Could not create the segment.', 'ux-studio' ), array( 'status' => 500 ) );
		}
		$id = (int) $wpdb->insert_id;
		ActivityLog::log( 'push-notifications', 'create_segment', 'segment', $id );
		foreach ( $this->list_segments() as $segment ) {
			if ( $segment['id'] === $id ) {
				return $segment;
			}
		}
		return array();
	}

	/**
	 * Delete a custom segment (system segments are protected, as in legacy).
	 *
	 * @param int $id Segment id.
	 */
	public function delete_segment( int $id ): bool {
		global $wpdb;
		$deleted = (bool) $wpdb->delete( "{$wpdb->prefix}uxstudio_push_segments", array( 'id' => $id, 'type' => 'custom' ), array( '%d', '%s' ) );
		if ( $deleted ) {
			ActivityLog::log( 'push-notifications', 'delete_segment', 'segment', $id );
		}
		return $deleted;
	}

	/**
	 * Replace the segment tags of one subscriber.
	 *
	 * @param int      $subscriber_id Subscriber id.
	 * @param string[] $slugs         Segment slugs (unknown ones are dropped).
	 */
	public function set_subscriber_segments( int $subscriber_id, array $slugs ): bool {
		global $wpdb;
		$exists = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}uxstudio_push_subscribers WHERE id = %d", $subscriber_id ) );
		if ( ! $exists ) {
			return false;
		}
		$wpdb->update(
			"{$wpdb->prefix}uxstudio_push_subscribers",
			array( 'segments' => (string) wp_json_encode( $this->valid_segment_slugs( $slugs ) ) ),
			array( 'id' => $subscriber_id ),
			array( '%s' ),
			array( '%d' )
		);
		return true;
	}

	/**
	 * Keep only slugs that exist in the segments table.
	 *
	 * @param array $slugs Raw slugs.
	 * @return string[]
	 */
	private function valid_segment_slugs( array $slugs ): array {
		global $wpdb;
		$slugs = array_values( array_unique( array_filter( array_map( 'sanitize_text_field', array_map( 'strval', $slugs ) ) ) ) );
		if ( ! $slugs ) {
			return array();
		}
		$known = $wpdb->get_col( "SELECT slug FROM {$wpdb->prefix}uxstudio_push_segments" );
		return array_values( array_intersect( $slugs, is_array( $known ) ? $known : array() ) );
	}

	/**
	 * Decode a stored JSON slug list.
	 *
	 * @param mixed $json Stored value.
	 * @return string[]
	 */
	private function decode_slugs( $json ): array {
		$data = json_decode( (string) $json, true );
		return is_array( $data ) ? array_values( array_map( 'strval', $data ) ) : array();
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
			'segments'        => $this->decode_slugs( $row['segments'] ?? '' ),
			'scheduled_at'    => $scheduled_at,
			'sent_count'      => $sent_count,
			'delivered_count' => (int) ( $row['delivered_count'] ?? 0 ),
			'status'          => $status,
		);
	}
}
