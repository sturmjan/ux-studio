<?php
/**
 * Wires the Menu Organizer engine into WordPress hooks + REST routes.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\AdminCustomiser;

defined( 'ABSPATH' ) || exit;

/**
 * Hooks are registered unconditionally (not just when the module is
 * enabled/REST-active) because MenuOrganizer itself gates every callback on
 * `is_enabled()` - matching the legacy module's always-hooked design so the
 * `admin_menu`/`parent_file`/`submenu_file` priority races with other
 * menu-editor plugins are consistent whether or not the feature is on.
 */
final class MenuOrganizerBootstrap {

	private static ?MenuOrganizer $organizer = null;

	/**
	 * Register hooks + REST routes. Called once from Module::boot().
	 */
	public static function register(): void {
		$organizer = self::organizer();

		add_action( 'admin_menu', array( $organizer, 'reorganize_admin_menu' ), 99999 );
		add_action( 'admin_init', array( $organizer, 'disarm_conflicts' ), 999 );
		add_filter( 'parent_file', array( $organizer, 'reorganize_at_render' ), 99999 );
		add_filter( 'submenu_file', array( $organizer, 'reorganize_at_render' ), 99999 );
		add_action( 'admin_head', array( $organizer, 'output_menu_styles' ), 999 );
		add_filter( 'admin_body_class', array( $organizer, 'add_body_class' ) );
		add_action( 'admin_footer', array( $organizer, 'output_custom_link_script' ), 99 );
		add_action( 'admin_footer', array( $organizer, 'output_sidebar_expand_script' ), 100 );

		add_action( 'rest_api_init', array( self::class, 'register_rest_routes' ) );
	}

	public static function register_rest_routes(): void {
		( new MenuOrganizerRestController( self::organizer() ) )->register_routes();
	}

	/**
	 * Shared MenuOrganizer instance so the pre-reorganization $menu/$submenu
	 * snapshot captured on `admin_menu` is available to the REST controller
	 * later in the same request (e.g. the "current-menu" endpoint reads it
	 * after WP has populated $menu).
	 */
	public static function organizer(): MenuOrganizer {
		if ( null === self::$organizer ) {
			self::$organizer = new MenuOrganizer();
		}
		return self::$organizer;
	}
}
