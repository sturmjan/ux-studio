<?php
/**
 * Main plugin singleton: boots core services, module registry and the admin SPA.
 *
 * @package UxStudio
 */

namespace UxStudio;

use UxStudio\Core\Admin;
use UxStudio\Core\DB;
use UxStudio\Core\GithubUpdater;
use UxStudio\Core\Megamenu;
use UxStudio\Core\Modules;
use UxStudio\Core\Rest;

defined( 'ABSPATH' ) || exit;

/**
 * Plugin bootstrap.
 */
final class Plugin {

	private static ?Plugin $instance = null;

	/** Module registry. */
	public Modules $modules;

	/**
	 * Singleton accessor.
	 */
	public static function instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Boot the plugin (on plugins_loaded).
	 */
	public function boot(): void {
		load_plugin_textdomain( 'ux-studio', false, dirname( plugin_basename( UXSTUDIO_FILE ) ) . '/languages' );

		DB::maybe_upgrade();

		$this->modules = new Modules();
		$this->modules->boot();

		( new Rest( $this->modules ) )->register();

		if ( is_admin() ) {
			( new Admin( $this->modules ) )->register();
			( new Megamenu( $this->modules ) )->register();
		}

		GithubUpdater::register();

		/**
		 * Fires after UX Studio has booted. Add-on plugins hook here.
		 *
		 * @param Plugin $plugin Plugin instance.
		 */
		do_action( 'ux_studio/booted', $this );
	}
}
