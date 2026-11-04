<?php
/**
 * Central App link wiring in the plugin core (PLAN.md 40).
 *
 * @package UxStudio
 */

namespace UxStudio\Core\CentralApp;

defined( 'ABSPATH' ) || exit;

/**
 * Runs from Plugin::boot(), independent of the modules: the domain watch and the notice of a paused link have to
 * work whichever module is on.
 */
final class Bootstrap {

	/**
	 * Registered once from Plugin::boot().
	 */
	public static function register(): void {
		DomainWatch::register();
		if ( is_admin() ) {
			PausedNotice::register();
		}
	}

	/**
	 * Plugin deactivation: stop the daily check.
	 */
	public static function deactivate(): void {
		DomainWatch::unschedule();
	}
}
