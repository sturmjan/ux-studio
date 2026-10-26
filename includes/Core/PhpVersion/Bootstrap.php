<?php
/**
 * PHP version check wiring in the plugin core.
 *
 * @package UxStudio
 */

namespace UxStudio\Core\PhpVersion;

use UxStudio\Modules\SecurityOptimization\PhpVersionMaintenance;
use UxStudio\Modules\SecurityOptimization\PhpVersionNotice;

defined( 'ABSPATH' ) || exit;

/**
 * The classes live in includes/Modules/SecurityOptimization/ (they are also the data source of the Security "Site
 * hardening" row), but they run from here, so the warning works right after activation with no module enabled.
 */
final class Bootstrap {

	/**
	 * Registered once from Plugin::boot(): daily refresh + e-mail, dashboard widget, admin notice, admin bar node.
	 */
	public static function register(): void {
		PhpVersionMaintenance::register();
		PhpVersionNotice::register();
	}

	/**
	 * Plugin activation: schedule the first refresh (and so the e-mail) within a minute instead of five.
	 */
	public static function activate(): void {
		PhpVersionMaintenance::schedule( 30 );
	}

	/**
	 * Plugin deactivation: stop the cron. The snooze and the stored data stay.
	 */
	public static function deactivate(): void {
		PhpVersionMaintenance::unschedule();
	}
}
