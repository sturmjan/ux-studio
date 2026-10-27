<?php
/**
 * Hooks of the migration feature: REST routes, mapping table, daily snapshot purge.
 *
 * @package UxStudio
 */

namespace UxStudio\Core\Migration;

defined( 'ABSPATH' ) || exit;

/**
 * Registered once from Plugin::boot().
 */
final class Bootstrap {

	public const PURGE_HOOK = 'uxstudio_migration_purge';

	/**
	 * Wire everything up.
	 */
	public static function register(): void {
		add_action(
			'rest_api_init',
			static function (): void {
				( new MigrationRestController() )->register_routes();
				( new \UxStudio\Core\Onboarding\RestController() )->register_routes();
				( new \UxStudio\Core\Transfer\RestController() )->register_routes();
				( new \UxStudio\Modules\SecurityOptimization\PhpVersionRestController() )->register_routes();
			}
		);
		add_action( self::PURGE_HOOK, array( Snapshot::class, 'purge_expired' ) );
		add_action( self::PURGE_HOOK, array( Switcher::class, 'purge_expired' ) );
		add_action( self::PURGE_HOOK, array( Preview::class, 'purge_expired' ) );
		add_action( self::PURGE_HOOK, array( \UxStudio\Core\Transfer\ImportSnapshot::class, 'purge_expired' ) );
		\UxStudio\Core\Transfer\RestController::register_hooks();
		Preview::register();
		AutoStart::register();
		add_action( 'admin_init', array( self::class, 'admin_init' ) );
	}

	/**
	 * Admin requests: make sure the table exists and the purge is scheduled.
	 */
	public static function admin_init(): void {
		MigrationMap::ensure_table();
		Preview::purge_expired();
		if ( ! wp_next_scheduled( self::PURGE_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::PURGE_HOOK );
		}
	}

	/**
	 * Plugin deactivation.
	 */
	public static function deactivate(): void {
		wp_clear_scheduled_hook( self::PURGE_HOOK );
		Preview::stop_all();
	}
}
