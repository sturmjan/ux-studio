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
 * text (renamed "Claude Panel" -> "AI Panel" so it isn't tied to one vendor)
 * and small, marked "UX Studio:" hardening (grant-bound session, shared
 * client IP resolver, self-expiring rescue endpoint).
 *
 * Kill switch (production): define('UXSTUDIO_DISABLE_AI_PANEL', true) in
 * wp-config.php disables the /ai-panel-<hex>/ route, the admin-ajax handlers
 * and the admin page entirely, and deletes the rescue endpoint.
 * DISALLOW_FILE_EDIT / DISALLOW_FILE_MODS switch off granting, the route and
 * the rescue endpoint (the admin page stays to explain why and to revoke).
 */
final class Module extends BaseModule {

	/**
	 * Register hooks. Runs synchronously during plugins_loaded (this method
	 * IS the earliest hook point in the new plugin), which is early enough
	 * to register the init@0 listener the frontend rescue-route needs.
	 */
	public function boot(): void {
		if ( defined( 'UXSTUDIO_DISABLE_AI_PANEL' ) && UXSTUDIO_DISABLE_AI_PANEL ) {
			// Kill switch: nothing may stay reachable, not even the standalone
			// rescue endpoint in uploads/ (it would outlive the route).
			self::remove_rescue_if_installed();
			return;
		}

		require_once __DIR__ . '/legacy/Panel.php';

		// Auto-removes the rescue endpoint once its allowed window expires.
		// WP-Cron runs on frontend requests too, not just wp-admin, so this
		// must be registered unconditionally.
		add_action( 'cp_rescue_expire', array( '\\Claude_Panel_Bootstrap', 'rescue_remove' ) );

		if ( '' !== self::file_mods_block_reason() ) {
			// File editing is disallowed site-wide and this tool edits files:
			// no access route and no rescue endpoint either.
			self::remove_rescue_if_installed();
		} else {
			// The frontend route at /ai-panel-<hex>/ must short-circuit before
			// any other plugin renders - same timing requirement as the legacy
			// module, preserved unchanged.
			add_action( 'init', array( '\\Claude_Panel_Bootstrap', 'maybe_route_panel' ), 0 );
		}

		if ( is_admin() ) {
			add_action( 'wp_dashboard_setup', array( $this, 'register_dashboard_widget' ) );
			add_action( 'wp_ajax_cp_grant_ajax', array( $this, 'ajax_grant' ) );
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
	 * Module switched off / plugin deactivated: revoke an active grant and
	 * delete the rescue endpoint, which would otherwise live on in uploads/
	 * as a standalone PHP file. The instance may not have been booted.
	 */
	public function on_disable(): void {
		require_once __DIR__ . '/legacy/Panel.php';

		$opts = \Claude_Panel_Bootstrap::get_settings();
		if ( ! empty( $opts['access_active'] ) ) {
			$opts['access_active']        = false;
			$opts['access_slug']          = '';
			$opts['access_password_hash'] = '';
			$opts['access_expires_at']    = 0;
			$opts['last_grant_password']  = '';
			\Claude_Panel_Bootstrap::update_settings( $opts );
			\Claude_Panel_Bootstrap::audit( 'access_revoked_module_disabled' );
		}
		\Claude_Panel_Bootstrap::rescue_remove();
		wp_clear_scheduled_hook( 'cp_rescue_expire' );
	}

	/**
	 * Remove the rescue endpoint when one is recorded (the settings option is
	 * autoloaded, so the filesystem is only touched when needed).
	 */
	private static function remove_rescue_if_installed(): void {
		$opts = get_option( 'claude_panel_settings', array() );
		if ( is_array( $opts ) && ! empty( $opts['rescue_installed'] ) ) {
			require_once __DIR__ . '/legacy/Panel.php';
			\Claude_Panel_Bootstrap::rescue_remove();
			wp_clear_scheduled_hook( 'cp_rescue_expire' );
		}
	}

	/**
	 * Non-empty (a human-readable reason) when wp-config.php disallows file
	 * edits/mods - this module grants file, DB and shell access, so it must
	 * not become a way around those constants.
	 */
	public static function file_mods_block_reason(): string {
		if ( defined( 'DISALLOW_FILE_MODS' ) && DISALLOW_FILE_MODS ) {
			return __( 'DISALLOW_FILE_MODS is set in wp-config.php, so the AI Panel cannot grant access to files, database or shell.', 'ux-studio' );
		}
		if ( defined( 'DISALLOW_FILE_EDIT' ) && DISALLOW_FILE_EDIT ) {
			return __( 'DISALLOW_FILE_EDIT is set in wp-config.php, so the AI Panel cannot grant access to files, database or shell.', 'ux-studio' );
		}
		return '';
	}

	/**
	 * Whether the current user may grant access: an administrator
	 * (manage_options) who may also edit plugin code (edit_plugins - core maps
	 * it to "no" under DISALLOW_FILE_EDIT/MODS and to super admins only on
	 * multisite).
	 */
	public static function user_can_grant(): bool {
		return '' === self::file_mods_block_reason()
			&& current_user_can( 'manage_options' )
			&& current_user_can( 'edit_plugins' );
	}

	/**
	 * Why the current user can't grant access (empty when they can).
	 */
	private static function grant_block_reason(): string {
		if ( self::user_can_grant() ) {
			return '';
		}
		$reason = self::file_mods_block_reason();
		return '' !== $reason ? $reason : __( 'Only administrators allowed to edit plugin files (super admins on multisite) can grant AI Panel access. You can still review the log and revoke access.', 'ux-studio' );
	}

	/**
	 * AJAX grant: stricter than the legacy manage_options check.
	 */
	public function ajax_grant(): void {
		$reason = self::grant_block_reason();
		if ( '' !== $reason ) {
			wp_send_json_error( array( 'message' => $reason ), 403 );
		}
		\Claude_Panel_Bootstrap::ajax_grant();
	}

	/**
	 * Dashboard widget only for users who can actually grant access.
	 */
	public function register_dashboard_widget(): void {
		if ( self::user_can_grant() ) {
			\Claude_Panel_Bootstrap::register_dashboard_widget();
		}
	}

	/**
	 * Own dedicated wp-admin page (not part of the React SPA - the panel's
	 * UI is a self-contained HTML/JS admin screen, same as before the port).
	 * Registered for manage_options so an admin can always see the state and
	 * revoke; granting itself is gated by user_can_grant().
	 */
	public function register_admin_page(): void {
		add_submenu_page(
			'ux-studio',
			__( 'AI Panel', 'ux-studio' ),
			__( 'AI Panel', 'ux-studio' ),
			'manage_options',
			'ux-studio-ai-panel',
			array( $this, 'render_admin_page' )
		);
	}

	/**
	 * Admin page, prefixed with the reason when granting is unavailable.
	 */
	public function render_admin_page(): void {
		$reason = self::grant_block_reason();
		if ( '' !== $reason ) {
			printf(
				'<div class="notice notice-warning" style="margin:20px 20px 0 0"><p>%s</p></div>',
				esc_html( $reason )
			);
		}
		\Claude_Panel_Bootstrap::render_admin_page();
	}

	/**
	 * Managing this module requires an admin who may edit plugin code:
	 * edit_plugins is denied by core under DISALLOW_FILE_EDIT/MODS and is
	 * limited to super admins on multisite.
	 */
	public function capability(): string {
		return 'edit_plugins';
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
