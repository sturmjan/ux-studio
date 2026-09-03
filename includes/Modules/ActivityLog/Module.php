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
		$this->register_event_tracking();
	}

	/**
	 * Register the site-wide audit events this module records (posts, users,
	 * plugins, themes, terms, media, comments, WooCommerce orders, auth). These
	 * are the events legacy ux1 tracked; per-module actions are logged by the
	 * modules themselves via ActivityLog::log().
	 */
	private function register_event_tracking(): void {
		// Auth.
		add_action( 'wp_login', array( $this, 'on_login' ), 10, 2 );
		add_action( 'wp_logout', array( $this, 'on_logout' ) );
		// Posts (meaningful status transitions + deletions; skips revisions/autosaves).
		add_action( 'transition_post_status', array( $this, 'on_post_transition' ), 10, 3 );
		add_action( 'before_delete_post', array( $this, 'on_post_delete' ) );
		// Users.
		add_action( 'user_register', array( $this, 'on_user_register' ) );
		add_action( 'profile_update', array( $this, 'on_profile_update' ) );
		add_action( 'set_user_role', array( $this, 'on_set_user_role' ), 10, 3 );
		add_action( 'delete_user', array( $this, 'on_user_delete' ) );
		// Plugins & themes.
		add_action( 'activated_plugin', array( $this, 'on_plugin_activated' ) );
		add_action( 'deactivated_plugin', array( $this, 'on_plugin_deactivated' ) );
		add_action( 'switch_theme', array( $this, 'on_switch_theme' ), 10, 2 );
		// Terms.
		add_action( 'created_term', array( $this, 'on_term_created' ), 10, 3 );
		add_action( 'delete_term', array( $this, 'on_term_deleted' ), 10, 4 );
		// Media.
		add_action( 'add_attachment', array( $this, 'on_attachment_added' ) );
		add_action( 'delete_attachment', array( $this, 'on_attachment_deleted' ) );
		// Comments.
		add_action( 'wp_insert_comment', array( $this, 'on_comment_inserted' ), 10, 2 );
		add_action( 'delete_comment', array( $this, 'on_comment_deleted' ) );
		// WooCommerce (fire only when WC emits them).
		add_action( 'woocommerce_new_order', array( $this, 'on_wc_new_order' ) );
		add_action( 'woocommerce_order_status_changed', array( $this, 'on_wc_order_status' ), 10, 3 );
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
				'key'     => 'alert_role_escalation',
				'type'    => 'toggle',
				'label'   => __( 'Alert on admin promotion', 'ux-studio' ),
				'help'    => __( 'Send an email when any user is promoted to the administrator role.', 'ux-studio' ),
				'default' => true,
			),
			array(
				'key'     => 'alert_email',
				'type'    => 'text',
				'label'   => __( 'Alert email', 'ux-studio' ),
				'help'    => __( 'Recipient for alerts. Defaults to the site admin email.', 'ux-studio' ),
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
	 * Log a logout.
	 *
	 * @param int $user_id User ID (WP passes it since 5.5).
	 */
	public function on_logout( int $user_id = 0 ): void {
		ActivityLog::log( 'activity-log', 'logout', 'user', (int) $user_id );
	}

	/**
	 * Record meaningful post status transitions; skip revisions/autosaves/noise.
	 *
	 * @param string    $new  New status.
	 * @param string    $old  Old status.
	 * @param \WP_Post  $post Post object.
	 */
	public function on_post_transition( string $new, string $old, \WP_Post $post ): void {
		if ( $new === $old ) {
			return;
		}
		$skip_types = array( 'revision', 'nav_menu_item', 'customize_changeset', 'oembed_cache' );
		if ( in_array( $post->post_type, $skip_types, true ) || in_array( $new, array( 'auto-draft', 'inherit' ), true ) ) {
			return;
		}
		ActivityLog::log( 'activity-log', 'post_' . $new, $post->post_type, (int) $post->ID, array( 'title' => $post->post_title ) );
	}

	/**
	 * Record a post deletion (non-revision, non-autosave).
	 *
	 * @param int $post_id Post ID.
	 */
	public function on_post_delete( int $post_id ): void {
		$post = get_post( $post_id );
		if ( ! $post || in_array( $post->post_type, array( 'revision', 'nav_menu_item' ), true ) || 'auto-draft' === $post->post_status ) {
			return;
		}
		ActivityLog::log( 'activity-log', 'delete', $post->post_type, $post_id, array( 'title' => $post->post_title ) );
	}

	/** @param int $user_id New user ID. */
	public function on_user_register( int $user_id ): void {
		ActivityLog::log( 'activity-log', 'user_register', 'user', $user_id, array( 'login' => $this->user_login_name( $user_id ) ) );
	}

	/** @param int $user_id Updated user ID. */
	public function on_profile_update( int $user_id ): void {
		ActivityLog::log( 'activity-log', 'profile_update', 'user', $user_id, array( 'login' => $this->user_login_name( $user_id ) ) );
	}

	/**
	 * Record a role change and alert on privilege escalation to administrator.
	 *
	 * @param int      $user_id   User ID.
	 * @param string   $role      New (primary) role.
	 * @param string[] $old_roles Previous roles.
	 */
	public function on_set_user_role( int $user_id, string $role, array $old_roles ): void {
		if ( empty( $old_roles ) ) {
			return; // Initial assignment on registration - already covered by user_register.
		}
		ActivityLog::log( 'activity-log', 'role_change', 'user', $user_id, array( 'role' => $role, 'from' => implode( ',', $old_roles ) ) );

		if ( 'administrator' === $role && ! in_array( 'administrator', $old_roles, true ) && $this->settings->get( 'alert_role_escalation', true ) ) {
			$this->send_role_escalation_alert( $user_id, $old_roles );
		}
	}

	/** @param int $user_id Deleted user ID. */
	public function on_user_delete( int $user_id ): void {
		ActivityLog::log( 'activity-log', 'delete', 'user', $user_id, array( 'login' => $this->user_login_name( $user_id ) ) );
	}

	/** @param string $plugin Plugin file. */
	public function on_plugin_activated( string $plugin ): void {
		ActivityLog::log( 'activity-log', 'activated', 'plugin', 0, array( 'plugin' => $plugin ) );
	}

	/** @param string $plugin Plugin file. */
	public function on_plugin_deactivated( string $plugin ): void {
		ActivityLog::log( 'activity-log', 'deactivated', 'plugin', 0, array( 'plugin' => $plugin ) );
	}

	/** @param string $name New theme name. */
	public function on_switch_theme( string $name ): void {
		ActivityLog::log( 'activity-log', 'switch_theme', 'theme', 0, array( 'name' => $name ) );
	}

	/**
	 * @param int    $term_id  Term ID.
	 * @param int    $tt_id    Term taxonomy ID.
	 * @param string $taxonomy Taxonomy.
	 */
	public function on_term_created( int $term_id, int $tt_id, string $taxonomy ): void {
		ActivityLog::log( 'activity-log', 'create', 'term', $term_id, array( 'taxonomy' => $taxonomy ) );
	}

	/**
	 * @param int          $term         Term ID.
	 * @param int          $tt_id        Term taxonomy ID.
	 * @param string       $taxonomy     Taxonomy.
	 * @param \WP_Term|mixed $deleted_term Deleted term object.
	 */
	public function on_term_deleted( int $term, int $tt_id, string $taxonomy, $deleted_term ): void {
		$name = ( $deleted_term instanceof \WP_Term ) ? $deleted_term->name : '';
		ActivityLog::log( 'activity-log', 'delete', 'term', (int) $term, array( 'taxonomy' => $taxonomy, 'name' => $name ) );
	}

	/** @param int $post_id Attachment ID. */
	public function on_attachment_added( int $post_id ): void {
		ActivityLog::log( 'activity-log', 'upload', 'attachment', $post_id, array( 'title' => get_the_title( $post_id ) ) );
	}

	/** @param int $post_id Attachment ID. */
	public function on_attachment_deleted( int $post_id ): void {
		ActivityLog::log( 'activity-log', 'delete', 'attachment', $post_id, array( 'title' => get_the_title( $post_id ) ) );
	}

	/**
	 * @param int         $comment_id Comment ID.
	 * @param \WP_Comment $comment    Comment object.
	 */
	public function on_comment_inserted( int $comment_id, $comment ): void {
		$post_id = is_object( $comment ) ? (int) $comment->comment_post_ID : 0;
		ActivityLog::log( 'activity-log', 'comment', 'comment', $comment_id, array( 'post_id' => $post_id ) );
	}

	/** @param int $comment_id Comment ID. */
	public function on_comment_deleted( int $comment_id ): void {
		ActivityLog::log( 'activity-log', 'delete', 'comment', (int) $comment_id );
	}

	/** @param int $order_id Order ID. */
	public function on_wc_new_order( int $order_id ): void {
		ActivityLog::log( 'activity-log', 'order_new', 'order', $order_id );
	}

	/**
	 * @param int    $order_id Order ID.
	 * @param string $from     Old status.
	 * @param string $to       New status.
	 */
	public function on_wc_order_status( int $order_id, string $from, string $to ): void {
		ActivityLog::log( 'activity-log', 'order_status', 'order', $order_id, array( 'from' => $from, 'to' => $to ) );
	}

	/**
	 * Resolve a user's login name for the log meta.
	 *
	 * @param int $user_id User ID.
	 */
	private function user_login_name( int $user_id ): string {
		$user = get_userdata( $user_id );
		return $user ? $user->user_login : '';
	}

	/**
	 * Email an alert when a user is promoted to administrator.
	 *
	 * @param int      $user_id   Promoted user ID.
	 * @param string[] $old_roles Their previous roles.
	 */
	private function send_role_escalation_alert( int $user_id, array $old_roles ): void {
		$to = (string) $this->settings->get( 'alert_email', '' );
		if ( '' === $to || ! is_email( $to ) ) {
			$to = (string) get_option( 'admin_email' );
		}
		$user     = get_userdata( $user_id );
		$previous = empty( $old_roles ) ? __( '(none)', 'ux-studio' ) : implode( ', ', $old_roles );
		$subject  = sprintf(
			/* translators: %s: site name */
			__( '[%s] User promoted to administrator', 'ux-studio' ),
			get_bloginfo( 'name' )
		);
		$body = sprintf(
			/* translators: 1: user login, 2: user id, 3: previous roles, 4: date/time */
			__( "User %1\$s (ID %2\$d) was just promoted to administrator.\nPrevious roles: %3\$s\nTime: %4\$s", 'ux-studio' ),
			$user ? $user->user_login : ( '#' . $user_id ),
			$user_id,
			$previous,
			current_time( 'mysql' )
		);
		wp_mail( $to, $subject, $body );
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
