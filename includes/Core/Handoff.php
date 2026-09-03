<?php
/**
 * Automated handoff from the legacy ux1 plugin to UX Studio.
 *
 * On activation: install/upgrade DB + migrate data (while ux1 is still active,
 * so its data is readable), then deactivate ux1 and offer to delete its files.
 * Deactivating/deleting ux1 never drops its ux1_* tables (it has no uninstall
 * hook), so per-module data still migrates lazily on each module's first boot
 * even after the old files are gone.
 *
 * @package UxStudio
 */

namespace UxStudio\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Orchestrates the one-time takeover from ux1-wordpress-customizer.
 */
final class Handoff {

	private const LEGACY_PLUGIN = 'ux1-wordpress-customizer/ux1-wordpress-customizer.php';
	private const OFFER_OPTION  = 'uxstudio_handoff_offer_delete';
	private const DELETE_ACTION = 'uxstudio_delete_legacy';

	/**
	 * Activation hook. Always runs (registered before the conflict guard), even
	 * while ux1 is still active, so the takeover can happen.
	 */
	public static function on_activation(): void {
		// 1) Install/upgrade core tables + migrate options and core-table data.
		//    ux1 is still active here, so all legacy data is readable.
		DB::activate();

		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		// 2) If the legacy plugin is present and active, deactivate it so UX Studio
		//    can boot. We migrate BEFORE deactivating (not after, as a literal
		//    reading of the spec would suggest) so a legacy deactivation hook can
		//    never drop data before it is copied. Module-specific data copies
		//    lazily on each module's first boot; ux1_* tables survive deactivation.
		if ( is_plugin_active( self::LEGACY_PLUGIN ) ) {
			deactivate_plugins( self::LEGACY_PLUGIN );
			// Offer to delete the now-orphaned ux1 files (only shown if present).
			update_option( self::OFFER_OPTION, '1', false );
		}
	}

	/**
	 * Register runtime hooks (admin notice + delete handler). Called from boot.
	 */
	public static function register(): void {
		add_action( 'admin_notices', array( self::class, 'maybe_offer_delete' ) );
		add_action( 'admin_post_' . self::DELETE_ACTION, array( self::class, 'handle_delete' ) );
	}

	/**
	 * Admin notice offering to delete the legacy plugin files after a handoff.
	 * Only shown when the offer flag is set, the user may delete plugins, and the
	 * ux1 files still exist (otherwise it is a no-op and the flag is cleared).
	 */
	public static function maybe_offer_delete(): void {
		if ( '1' !== get_option( self::OFFER_OPTION ) ) {
			return;
		}
		if ( ! current_user_can( 'delete_plugins' ) ) {
			return;
		}
		if ( ! file_exists( WP_PLUGIN_DIR . '/' . self::LEGACY_PLUGIN ) ) {
			delete_option( self::OFFER_OPTION );
			return;
		}

		$url = wp_nonce_url(
			admin_url( 'admin-post.php?action=' . self::DELETE_ACTION ),
			self::DELETE_ACTION
		);
		printf(
			'<div class="notice notice-success"><p>%s</p><p><a href="%s" class="button button-primary">%s</a></p></div>',
			esc_html__( 'UX Studio has taken over from the legacy UX1 plugin and migrated its data. You can now delete the old plugin files - your data stays and keeps migrating as you enable modules.', 'ux-studio' ),
			esc_url( $url ),
			esc_html__( 'Delete old UX1 plugin', 'ux-studio' )
		);
	}

	/**
	 * admin-post handler: delete the legacy plugin files (data tables are kept).
	 */
	public static function handle_delete(): void {
		if ( ! current_user_can( 'delete_plugins' ) ) {
			wp_die( esc_html__( 'You are not allowed to delete plugins.', 'ux-studio' ) );
		}
		check_admin_referer( self::DELETE_ACTION );

		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		if ( file_exists( WP_PLUGIN_DIR . '/' . self::LEGACY_PLUGIN ) ) {
			delete_plugins( array( self::LEGACY_PLUGIN ) );
		}
		delete_option( self::OFFER_OPTION );

		wp_safe_redirect( admin_url( 'plugins.php?uxstudio_legacy_deleted=1' ) );
		exit;
	}
}
