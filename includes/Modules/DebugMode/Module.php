<?php
/**
 * Debug Mode module - read-only view of the WordPress debug configuration.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\DebugMode;

use UxStudio\Modules\BaseModule;

defined( 'ABSPATH' ) || exit;

/**
 * Exposes the current state of the debug constants and the debug log through
 * a REST endpoint consumed by the SPA.
 *
 * The legacy module toggled WP_DEBUG by rewriting wp-config.php on module
 * activation/deactivation. Writing to wp-config.php is intentionally NOT
 * ported - this module is read-only.
 *
 * TODO: If enabling/disabling WP_DEBUG from the UI is ever needed, implement
 * it without editing wp-config.php directly (e.g. instruct the user, or use a
 * drop-in), never by rewriting configuration files from the plugin.
 */
final class Module extends BaseModule {

	/**
	 * Register hooks.
	 */
	public function boot(): void {
		add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );
	}

	/**
	 * Register the module REST controller.
	 */
	public function register_rest_routes(): void {
		( new RestController() )->register_routes();
	}

	/**
	 * REST controller class.
	 */
	public function rest_controller(): ?string {
		return RestController::class;
	}
}
