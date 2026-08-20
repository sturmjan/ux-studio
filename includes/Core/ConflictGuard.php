<?php
/**
 * Prevents UX Studio from running alongside the legacy ux1 plugin.
 *
 * @package UxStudio
 */

namespace UxStudio\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Detects the legacy ux1-wordpress-customizer plugin and blocks boot when active.
 */
final class ConflictGuard {

	private const LEGACY_PLUGIN = 'ux1-wordpress-customizer/ux1-wordpress-customizer.php';

	/**
	 * Whether UX Studio may boot (legacy plugin not active).
	 */
	public static function can_boot(): bool {
		if ( ! function_exists( 'is_plugin_active' ) ) {
			include_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		if ( ! is_plugin_active( self::LEGACY_PLUGIN ) ) {
			return true;
		}
		add_action( 'admin_notices', array( self::class, 'notice' ) );
		return false;
	}

	/**
	 * Admin notice explaining why UX Studio is dormant.
	 */
	public static function notice(): void {
		printf(
			'<div class="notice notice-error"><p>%s</p></div>',
			esc_html__( 'UX Studio is inactive: the legacy plugin "UX One - Wordpress customizer" is still active. Deactivate it to let UX Studio take over.', 'ux-studio' )
		);
	}
}
