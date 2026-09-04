<?php
/**
 * Keeps the legacy ux1 plugin deactivated once UX Studio has taken over, and
 * blocks any attempt to re-activate it (with an explanatory admin notice).
 *
 * @package UxStudio
 */

namespace UxStudio\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Two plugins doing the same job must never run at once. After the handoff
 * (see Handoff), UX Studio is the owner: this guard undoes any re-activation of
 * ux1, disables its "Activate" link, and explains why. ux1's data is left
 * untouched (it has no uninstall hook), so nothing is lost.
 *
 * Registered unconditionally from the bootstrap - even when UX Studio itself is
 * dormant for one request (see ConflictGuard) - so the lock always applies.
 */
final class Ux1Lock {

	private const LEGACY_PLUGIN    = 'ux1-wordpress-customizer/ux1-wordpress-customizer.php';
	private const NOTICE_TRANSIENT = 'uxstudio_ux1_blocked';

	/**
	 * Hook the guard.
	 */
	public static function register(): void {
		add_action( 'activated_plugin', array( self::class, 'on_activated' ), 10, 1 );
		add_action( 'admin_init', array( self::class, 'self_heal' ) );
		add_action( 'admin_notices', array( self::class, 'render_notice' ) );
		add_filter( 'plugin_action_links_' . self::LEGACY_PLUGIN, array( self::class, 'disable_activate_link' ) );
	}

	/**
	 * Fires right after any plugin activates: if it was ux1, undo it.
	 *
	 * @param string $plugin Plugin file that was just activated.
	 */
	public static function on_activated( $plugin ): void {
		if ( self::LEGACY_PLUGIN === $plugin ) {
			self::block();
		}
	}

	/**
	 * Belt-and-braces: on every admin load, if ux1 is somehow active again
	 * (e.g. enabled via WP-CLI or a must-use loader), deactivate it.
	 */
	public static function self_heal(): void {
		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		if ( is_plugin_active( self::LEGACY_PLUGIN ) ) {
			self::block();
		}
	}

	/**
	 * Deactivate ux1 and flag the explanatory notice.
	 */
	private static function block(): void {
		if ( ! function_exists( 'deactivate_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		deactivate_plugins( self::LEGACY_PLUGIN );
		set_transient( self::NOTICE_TRANSIENT, 1, MINUTE_IN_SECONDS );
	}

	/**
	 * Replace ux1's "Activate" action link with a disabled explanation so it
	 * can't be turned on from the Plugins screen.
	 *
	 * @param array<string,string> $actions Row action links.
	 * @return array<string,string>
	 */
	public static function disable_activate_link( $actions ): array {
		unset( $actions['activate'] );
		$actions['uxstudio_locked'] = '<span style="color:#b32d2e;">' . esc_html__( 'Replaced by UX Studio', 'ux-studio' ) . '</span>';
		return $actions;
	}

	/**
	 * Show the "we kept ux1 off" notice once after a blocked (re)activation.
	 */
	public static function render_notice(): void {
		if ( ! get_transient( self::NOTICE_TRANSIENT ) ) {
			return;
		}
		delete_transient( self::NOTICE_TRANSIENT );
		printf(
			'<div class="notice notice-warning is-dismissible"><p>%s</p></div>',
			esc_html__( 'The legacy plugin "UX One - WordPress customizer" was kept deactivated: UX Studio has replaced it, and running both at once would conflict. Its data is preserved - nothing was deleted.', 'ux-studio' )
		);
	}
}
