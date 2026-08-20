<?php
/**
 * AI Panel module - thin wrapper around the unmodified legacy runtime.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\AiPanel;

use UxStudio\Modules\BaseModule;

defined( 'ABSPATH' ) || exit;

/**
 * Deliberately does NOT reimplement the access/security model. The module
 * grants a WordPress admin a temporary URL + one-time password that lets a
 * remote AI coding agent (Claude Code, Codex, or similar) read/write files,
 * query the database and run shell commands for a limited time window - this
 * is an intentionally powerful support tool, already hardened and in active
 * use, so the port is a lift-and-shift: legacy/Panel.php, Runtime.php and
 * rescue.php are carried over byte-for-byte except for user-facing branding
 * text (renamed "Claude Panel" -> "AI Panel" so it isn't tied to one vendor).
 *
 * Kill switch (production): define('UXSTUDIO_DISABLE_AI_PANEL', true) in
 * wp-config.php disables the /ai-panel-<hex>/ route, the admin-ajax handlers
 * and the admin page entirely.
 */
final class Module extends BaseModule {

	/**
	 * Register hooks. Runs synchronously during plugins_loaded (this method
	 * IS the earliest hook point in the new plugin), which is early enough
	 * to register the init@0 listener the frontend rescue-route needs.
	 */
	public function boot(): void {
		if ( defined( 'UXSTUDIO_DISABLE_AI_PANEL' ) && UXSTUDIO_DISABLE_AI_PANEL ) {
			return;
		}

		require_once __DIR__ . '/legacy/Panel.php';

		// The frontend route at /ai-panel-<hex>/ must short-circuit before
		// any other plugin renders - same timing requirement as the legacy
		// module, preserved unchanged.
		add_action( 'init', array( '\\Claude_Panel_Bootstrap', 'maybe_route_panel' ), 0 );

		// Auto-removes the rescue endpoint once its allowed window expires.
		// WP-Cron runs on frontend requests too, not just wp-admin, so this
		// must be registered unconditionally.
		add_action( 'cp_rescue_expire', array( '\\Claude_Panel_Bootstrap', 'rescue_remove' ) );

		if ( is_admin() ) {
			add_action( 'wp_dashboard_setup', array( '\\Claude_Panel_Bootstrap', 'register_dashboard_widget' ) );
			add_action( 'wp_ajax_cp_grant_ajax', array( '\\Claude_Panel_Bootstrap', 'ajax_grant' ) );
			add_action( 'wp_ajax_cp_revoke_ajax', array( '\\Claude_Panel_Bootstrap', 'ajax_revoke' ) );
			add_action( 'wp_ajax_cp_clear_log_ajax', array( '\\Claude_Panel_Bootstrap', 'ajax_clear_log' ) );
			add_action( 'admin_init', array( '\\Claude_Panel_Bootstrap', 'maybe_export_csv' ) );
			// Fallback cleanup for hosts with WP-Cron disabled: if an admin
			// opens wp-admin and the grant has already expired, remove it.
			add_action( 'admin_init', array( '\\Claude_Panel_Bootstrap', 'maybe_cleanup_expired_rescue' ) );
			add_action( 'admin_menu', array( $this, 'register_admin_page' ) );
		}
	}

	/**
	 * Own dedicated wp-admin page (not part of the React SPA - the panel's
	 * UI is a self-contained HTML/JS admin screen, same as before the port).
	 */
	public function register_admin_page(): void {
		add_submenu_page(
			'ux-studio',
			__( 'AI Panel', 'ux-studio' ),
			__( 'AI Panel', 'ux-studio' ),
			'manage_options',
			'ux-studio-ai-panel',
			array( '\\Claude_Panel_Bootstrap', 'render_admin_page' )
		);
	}

	/**
	 * No settings schema - the panel manages its own state (access grants,
	 * audit log) through its unmodified legacy admin page, not the generic
	 * schema-driven settings renderer.
	 */
	public function settings_schema(): array {
		return array();
	}
}
