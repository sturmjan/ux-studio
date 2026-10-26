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
use UxStudio\Core\Mcp\Bootstrap as McpBootstrap;
use UxStudio\Core\Megamenu;
use UxStudio\Core\Migration\ActivationGuard;
use UxStudio\Core\Migration\Bootstrap as MigrationBootstrap;
use UxStudio\Core\Modules;
use UxStudio\Core\PhpVersion\Bootstrap as PhpVersionBootstrap;
use UxStudio\Core\Rest;
use UxStudio\Core\Upgrade;

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
		// Records how the site looked under the previous release, before any module acts on it.
		Upgrade::run();

		$this->modules = new Modules();
		$this->modules->boot();

		( new Rest( $this->modules ) )->register();
		ActivationGuard::register();
		MigrationBootstrap::register();
		McpBootstrap::register();
		PhpVersionBootstrap::register();

		if ( is_admin() ) {
			( new Admin( $this->modules ) )->register();
			( new Megamenu( $this->modules ) )->register();
		}

		// Plugin Update Checker loads ~20 files - only where updates are
		// checked/installed (admin, WP-Cron auto-updates, WP-CLI, plugins REST).
		if ( GithubUpdater::is_update_context() ) {
			GithubUpdater::register();
		}

		/**
		 * Fires after UX Studio has booted. Add-on plugins hook here.
		 *
		 * @param Plugin $plugin Plugin instance.
		 */
		do_action( 'ux_studio/booted', $this );
	}
}
