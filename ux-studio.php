<?php
/**
 * Plugin Name:       UX Studio
 * Plugin URI:        https://github.com/TODO-org/ux-studio
 * Description:       Modular WordPress admin platform: one consistent SPA for all site tools.
 * Version:           0.1.0
 * Requires at least: 6.5
 * Requires PHP:      8.1
 * Author:            UX One
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       ux-studio
 * Domain Path:       /languages
 * Update URI:        https://github.com/TODO-org/ux-studio
 *
 * @package UxStudio
 */

defined( 'ABSPATH' ) || exit;

define( 'UXSTUDIO_VERSION', '0.1.0' );
// Public Extension API version (contract for add-on plugins: `ux_studio/modules`
// filter, BaseModule, REST namespace, window.uxStudio.registerPage). An add-on
// declares a minimum version; on mismatch it is skipped safely.
define( 'UXSTUDIO_API_VERSION', 1 );
define( 'UXSTUDIO_DB_VERSION', 1 );
define( 'UXSTUDIO_FILE', __FILE__ );
define( 'UXSTUDIO_PATH', plugin_dir_path( __FILE__ ) );
define( 'UXSTUDIO_URL', plugin_dir_url( __FILE__ ) );

require_once UXSTUDIO_PATH . 'includes/Autoloader.php';
\UxStudio\Autoloader::register();

// Conflict guard: never run alongside the legacy ux1 plugin.
if ( ! \UxStudio\Core\ConflictGuard::can_boot() ) {
	return;
}

// Install / upgrade DB tables on activation.
register_activation_hook( __FILE__, array( '\UxStudio\Core\DB', 'activate' ) );

add_action(
	'plugins_loaded',
	static function () {
		\UxStudio\Plugin::instance()->boot();
	}
);
