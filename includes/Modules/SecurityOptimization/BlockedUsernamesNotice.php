<?php
/**
 * Admin notice: existing accounts whose usernames are on the blocked list.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\SecurityOptimization;

defined( 'ABSPATH' ) || exit;

/**
 * Ported from legacy checkExistingBlockedUsernames(). Blocking reserved
 * usernames only stops NEW accounts, so an existing "admin" stays the obvious
 * brute-force target - the notice tells the administrators about it.
 *
 * Differences from legacy: the user scan is cached (legacy read every user on
 * every admin page), and a dismissal is remembered per user until the set of
 * matching accounts changes (legacy notice came back on the next page load).
 */
final class BlockedUsernamesNotice {

	private const ACTION         = 'uxstudio_blocked_usernames_dismiss';
	private const NONCE          = 'uxstudio_blocked_usernames_notice';
	private const META_DISMISSED = 'uxstudio_blocked_usernames_dismissed';
	private const CACHE          = 'uxstudio_security_blocked_user_accounts';

	/** Accounts named in the notice; the rest is a count. */
	private const SHOW_MAX = 5;

	private Module $module;

	/**
	 * @param Module $module Owning module.
	 */
	public function __construct( Module $module ) {
		$this->module = $module;
	}

	/**
	 * Hook the notice, its dismissal and cache invalidation (admin only).
	 */
	public function boot(): void {
		if ( ! is_admin() ) {
			return;
		}
		add_action( 'admin_notices', array( $this, 'render' ) );
		add_action( 'wp_ajax_' . self::ACTION, array( $this, 'ajax_dismiss' ) );
		add_action( 'user_register', array( self::class, 'flush' ) );
		add_action( 'profile_update', array( self::class, 'flush' ) );
		add_action( 'deleted_user', array( self::class, 'flush' ) );
	}

	/**
	 * Drop the cached scan (a user was added, renamed or deleted).
	 */
	public static function flush(): void {
		delete_transient( self::CACHE );
	}

	/**
	 * Logins of existing accounts matching the blocked list, cached for 12 h
	 * and keyed by the list itself so an edited list is re-checked at once.
	 *
	 * @return string[]
	 */
	private function matching_logins(): array {
		$signature = md5( implode( "\n", $this->module->blocked_usernames() ) );
		$cached    = get_transient( self::CACHE );
		if ( is_array( $cached ) && ( $cached['sig'] ?? '' ) === $signature && is_array( $cached['logins'] ?? null ) ) {
			return $cached['logins'];
		}

		global $wpdb;
		$logins = array();
		foreach ( (array) $wpdb->get_col( "SELECT user_login FROM {$wpdb->users}" ) as $login ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			if ( $this->module->is_blocked_username( (string) $login ) ) {
				$logins[] = (string) $login;
			}
		}
		sort( $logins );

		set_transient(
			self::CACHE,
			array(
				'sig'    => $signature,
				'logins' => $logins,
			),
			12 * HOUR_IN_SECONDS
		);
		return $logins;
	}

	/**
	 * Print the warning for administrators.
	 */
	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$logins = $this->matching_logins();
		if ( empty( $logins ) ) {
			return;
		}

		$key = md5( implode( "\n", $logins ) );
		if ( get_user_meta( get_current_user_id(), self::META_DISMISSED, true ) === $key ) {
			return;
		}

		$count = count( $logins );
		?>
		<div class="notice notice-warning is-dismissible" id="uxstudio-blocked-usernames-notice" data-nonce="<?php echo esc_attr( wp_create_nonce( self::NONCE ) ); ?>" data-key="<?php echo esc_attr( $key ); ?>">
			<p>
				<strong><?php esc_html_e( 'UX Studio security warning:', 'ux-studio' ); ?></strong>
				<?php
				echo esc_html(
					sprintf(
						/* translators: %d: number of user accounts */
						_n(
							'%d existing user account uses a blocked username. Blocking only stops new accounts - rename or replace this one:',
							'%d existing user accounts use a blocked username. Blocking only stops new accounts - rename or replace these:',
							$count,
							'ux-studio'
						),
						$count
					)
				);
				?>
			</p>
			<ul style="list-style:disc;margin-left:2em">
				<?php foreach ( array_slice( $logins, 0, self::SHOW_MAX ) as $login ) : ?>
					<li><strong><?php echo esc_html( $login ); ?></strong></li>
				<?php endforeach; ?>
				<?php if ( $count > self::SHOW_MAX ) : ?>
					<li>
						<?php
						/* translators: %d: number of further accounts */
						echo esc_html( sprintf( __( 'and %d more…', 'ux-studio' ), $count - self::SHOW_MAX ) );
						?>
					</li>
				<?php endif; ?>
			</ul>
		</div>
		<script>
		( function () {
			var box = document.getElementById( 'uxstudio-blocked-usernames-notice' );
			if ( ! box ) { return; }
			box.addEventListener( 'click', function ( e ) {
				if ( ! e.target.classList.contains( 'notice-dismiss' ) ) { return; }
				var data = new URLSearchParams( {
					action: <?php echo wp_json_encode( self::ACTION ); ?>,
					_wpnonce: box.getAttribute( 'data-nonce' ),
					key: box.getAttribute( 'data-key' )
				} );
				fetch( <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>, { method: 'POST', credentials: 'same-origin', body: data } );
			} );
		} )();
		</script>
		<?php
	}

	/**
	 * AJAX: remember the dismissal for this user and this set of accounts.
	 */
	public function ajax_dismiss(): void {
		check_ajax_referer( self::NONCE );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( null, 403 );
		}

		$key = isset( $_POST['key'] ) ? sanitize_key( wp_unslash( $_POST['key'] ) ) : '';
		if ( 1 !== preg_match( '/^[a-f0-9]{32}$/', $key ) ) {
			wp_send_json_error( null, 400 );
		}
		update_user_meta( get_current_user_id(), self::META_DISMISSED, $key );
		wp_send_json_success();
	}
}
