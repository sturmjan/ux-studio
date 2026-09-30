<?php
/**
 * Plugin Name:       UX Studio
 * Plugin URI:        https://github.com/sturmjan/ux-studio
 * Description:       Modular WordPress admin platform: one consistent SPA for all site tools.
 * Version:           0.2.0
 * Requires at least: 6.5
 * Requires PHP:      8.1
 * Author:            UX One
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       ux-studio
 * Domain Path:       /languages
 * Update URI:        https://github.com/sturmjan/ux-studio
 *
 * @package UxStudio
 */

defined( 'ABSPATH' ) || exit;

define( 'UXSTUDIO_VERSION', '0.2.0' );
// Public Extension API version (contract for add-on plugins: `ux_studio/modules`
// filter, BaseModule, REST namespace, window.uxStudio.registerPage). An add-on
// declares a minimum version; on mismatch it is skipped safely.
define( 'UXSTUDIO_API_VERSION', 1 );
define( 'UXSTUDIO_DB_VERSION', 2 );
define( 'UXSTUDIO_FILE', __FILE__ );
define( 'UXSTUDIO_PATH', plugin_dir_path( __FILE__ ) );
define( 'UXSTUDIO_URL', plugin_dir_url( __FILE__ ) );

require_once UXSTUDIO_PATH . 'includes/Autoloader.php';
\UxStudio\Autoloader::register();

// Activation: hand over from the legacy ux1 plugin (install DB + migrate data +
// deactivate ux1 + offer to delete it). Registered BEFORE the conflict guard so
// it always runs on activation, even while ux1 is still active.
register_activation_hook( __FILE__, array( '\UxStudio\Core\Handoff', 'on_activation' ) );

// Deactivation: undo side effects that would otherwise outlive the plugin
// (Cron Control mu-plugin + wp-cron deny, .htaccess blocks, cron events).
// Settings and data stay, so reactivation restores the same setup.
register_deactivation_hook(
	__FILE__,
	static function () {
		// The plugin was active for this request, so the registry is already
		// booted (meta discovered); nothing to do if it stayed dormant.
		$plugin = \UxStudio\Plugin::instance();
		if ( isset( $plugin->modules ) ) {
			$plugin->modules->cleanup_for_plugin_deactivation();
		}
	}
);

// Keep the legacy ux1 plugin deactivated and un-re-activatable now that UX Studio
// owns this job. Registered unconditionally (even if UX Studio is dormant for one
// request below) so the lock always applies.
\UxStudio\Core\Ux1Lock::register();

// Conflict guard: never run BOTH at once. If ux1 is still active this single
// request, UX Studio stays dormant; Ux1Lock deactivates ux1 on admin_init, so
// UX Studio takes over from the next request.
if ( ! \UxStudio\Core\ConflictGuard::can_boot() ) {
	return;
}

add_action(
	'plugins_loaded',
	static function () {
		\UxStudio\Plugin::instance()->boot();
		\UxStudio\Core\Handoff::register();
	}
);
