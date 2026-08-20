<?php
/**
 * Rollback Manager module - roll an installed plugin/theme back to an older
 * wordpress.org version.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\RollbackManager;

use UxStudio\Modules\BaseModule;

defined( 'ABSPATH' ) || exit;

/**
 * No settings screen and no own DB table: this module only exposes REST
 * endpoints consumed by its custom SPA page (src/modules/rollback-manager).
 */
final class Module extends BaseModule {

	/**
	 * Register hooks.
	 */
	public function boot(): void {
		add_action(
			'rest_api_init',
			function (): void {
				( new RestController( $this ) )->register_routes();
			}
		);
	}

	/**
	 * REST controller class.
	 */
	public function rest_controller(): ?string {
		return RestController::class;
	}

	/**
	 * No settings for this module.
	 */
	public function settings_schema(): array {
		return array();
	}

	/**
	 * Rolling back core assets is high-impact; keep it restricted to admins.
	 */
	public function capability(): string {
		return 'manage_options';
	}
}
