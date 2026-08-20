<?php
/**
 * Activity Log module - browse/filter/prune the shared audit log.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\ActivityLog;

use UxStudio\Core\ActivityLog;
use UxStudio\Modules\BaseModule;

defined( 'ABSPATH' ) || exit;

/**
 * The uxstudio_activity_log table itself is created once, up front, in
 * DB::migrate_1() (shared by every module via Core\ActivityLog::log()) - this
 * module does NOT create its own table, it only reads/prunes the shared one
 * and optionally alerts on logins from a new IP address.
 */
final class Module extends BaseModule {

	/**
	 * Register hooks.
	 */
	public function boot(): void {
		add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );
		add_action( 'wp_login', array( $this, 'on_login' ), 10, 2 );
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
				'help'    => __( 'Entries older than this are removed by the "Purge old entries" action.', 'ux-studio' ),
				'default' => 30,
			),
			array(
				'key'     => 'alert_new_ip',
				'type'    => 'toggle',
				'label'   => __( 'Alert on new IP login', 'ux-studio' ),
				'help'    => __( 'Send an email when a user logs in from an IP address not seen for them before.', 'ux-studio' ),
				'default' => false,
			),
			array(
				'key'     => 'alert_email',
				'type'    => 'text',
				'label'   => __( 'Alert email', 'ux-studio' ),
				'help'    => __( 'Recipient for new-IP alerts. Defaults to the site admin email.', 'ux-studio' ),
				'default' => '',
			),
		);
	}

	/**
	 * Record every login and, if enabled, alert on a previously unseen IP.
	 *
	 * @param string   $user_login Username.
	 * @param \WP_User $user       User object.
	 */
	public function on_login( string $user_login, \WP_User $user ): void {
		$ip = $this->client_ip();

		if ( $this->settings->get( 'alert_new_ip', false ) && ! $this->ip_seen_before( $user->ID, $ip ) ) {
			$this->send_new_ip_alert( $user, $ip );
		}

		ActivityLog::log( 'activity-log', 'login', 'user', $user->ID, array( 'ip' => $ip ) );
	}

	/**
	 * Whether this user has a prior recorded login from this IP.
	 *
	 * @param int    $user_id User ID.
	 * @param string $ip      IP address.
	 */
	private function ip_seen_before( int $user_id, string $ip ): bool {
		global $wpdb;

		if ( '' === $ip ) {
			return true; // Unknown IP - do not alert on it.
		}

		$found = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$wpdb->prefix}uxstudio_activity_log
				WHERE module = 'activity-log' AND action = 'login' AND user_id = %d AND meta LIKE %s
				LIMIT 1",
				$user_id,
				'%' . $wpdb->esc_like( '"ip":"' . $ip . '"' ) . '%'
			)
		);

		return null !== $found;
	}

	/**
	 * Email the configured (or admin) address about a login from a new IP.
	 *
	 * @param \WP_User $user User who logged in.
	 * @param string   $ip   IP address.
	 */
	private function send_new_ip_alert( \WP_User $user, string $ip ): void {
		$to = (string) $this->settings->get( 'alert_email', '' );
		if ( '' === $to || ! is_email( $to ) ) {
			$to = (string) get_option( 'admin_email' );
		}

		$subject = sprintf(
			/* translators: 1: site name, 2: user login */
			__( '[%1$s] New IP login for %2$s', 'ux-studio' ),
			get_bloginfo( 'name' ),
			$user->user_login
		);
		$body = sprintf(
			/* translators: 1: user login, 2: IP address, 3: date/time */
			__( "User %1\$s just logged in from a new IP address: %2\$s\nTime: %3\$s", 'ux-studio' ),
			$user->user_login,
			'' === $ip ? __( 'unknown', 'ux-studio' ) : $ip,
			current_time( 'mysql' )
		);

		wp_mail( $to, $subject, $body );
	}

	/**
	 * Best-effort client IP (proxies are not trusted beyond REMOTE_ADDR).
	 */
	private function client_ip(): string {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		return is_string( $ip ) ? $ip : '';
	}

	/**
	 * Filtered/paginated log entries for the SPA table.
	 *
	 * @param array $args {
	 *     @type string $module Optional module filter.
	 *     @type string $action Optional action filter.
	 *     @type int    $limit  Max rows (default 50, capped at 200).
	 *     @type int    $offset Offset.
	 * }
	 * @return array{items:array<int,array<string,mixed>>,total:int}
	 */
	public function get_entries( array $args ): array {
		global $wpdb;

		$where  = array( '1=1' );
		$params = array();

		if ( '' !== ( $args['module'] ?? '' ) ) {
			$where[]  = 'module = %s';
			$params[] = $args['module'];
		}
		if ( '' !== ( $args['action'] ?? '' ) ) {
			$where[]  = 'action = %s';
			$params[] = $args['action'];
		}

		$limit  = max( 1, min( 200, (int) ( $args['limit'] ?? 50 ) ) );
		$offset = max( 0, (int) ( $args['offset'] ?? 0 ) );
		$where_sql = implode( ' AND ', $where );

		$total_sql = "SELECT COUNT(*) FROM {$wpdb->prefix}uxstudio_activity_log WHERE {$where_sql}";
		$total     = (int) ( empty( $params ) ? $wpdb->get_var( $total_sql ) : $wpdb->get_var( $wpdb->prepare( $total_sql, $params ) ) );

		$items_sql = "SELECT id, created_at, user_id, module, action, object_type, object_id, meta
			FROM {$wpdb->prefix}uxstudio_activity_log WHERE {$where_sql} ORDER BY id DESC LIMIT %d OFFSET %d";
		$items = $wpdb->get_results( $wpdb->prepare( $items_sql, array_merge( $params, array( $limit, $offset ) ) ), ARRAY_A );

		return array(
			'items' => is_array( $items ) ? $items : array(),
			'total' => $total,
		);
	}

	/**
	 * Delete entries older than the configured (or given) retention window.
	 *
	 * @param int $days Override for the configured retention_days; 0 = use settings.
	 * @return int Number of deleted rows.
	 */
	public function purge_old_entries( int $days = 0 ): int {
		global $wpdb;

		$days = $days > 0 ? $days : max( 1, (int) $this->settings->get( 'retention_days', 30 ) );
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) );

		return (int) $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->prefix}uxstudio_activity_log WHERE created_at < %s",
				$cutoff
			)
		);
	}
}
