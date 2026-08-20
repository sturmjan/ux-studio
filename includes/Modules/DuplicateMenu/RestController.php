<?php
/**
 * Duplicate Menu REST controller.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\DuplicateMenu;

use UxStudio\Rest\Controller;
use WP_Error;
use WP_REST_Request;

defined( 'ABSPATH' ) || exit;

/**
 * GET  uxstudio/v1/duplicate-menu/menus - list available menus
 * POST uxstudio/v1/duplicate-menu       - duplicate a menu
 */
final class RestController extends Controller {

	private Module $module;

	/**
	 * @param Module $module Owning module instance.
	 */
	public function __construct( Module $module ) {
		$this->module = $module;
	}

	/**
	 * Register routes.
	 */
	public function register_routes(): void {
		$this->route( '/duplicate-menu/menus', 'GET', array( $this, 'list_menus' ), array(), 'edit_theme_options' );
		$this->route(
			'/duplicate-menu',
			'POST',
			array( $this, 'duplicate' ),
			array(
				'menu_id'   => array(
					'required'          => true,
					'type'              => 'integer',
					'sanitize_callback' => 'absint',
				),
				'menu_name' => array(
					'required'          => true,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
				),
			),
			'edit_theme_options'
		);
	}

	/**
	 * List existing navigation menus for the SPA picker.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function list_menus( WP_REST_Request $request ) {
		$menus = array();

		foreach ( wp_get_nav_menus() as $menu ) {
			$menus[] = array(
				'id'   => (int) $menu->term_id,
				'name' => $menu->name,
			);
		}

		return $this->ok( $menus );
	}

	/**
	 * Duplicate a menu.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function duplicate( WP_REST_Request $request ) {
		$menu_id   = (int) $request->get_param( 'menu_id' );
		$menu_name = (string) $request->get_param( 'menu_name' );

		$new_menu_id = $this->module->duplicate_menu( $menu_id, $menu_name );

		if ( ! $new_menu_id ) {
			return new WP_Error(
				'uxstudio_duplication_failed',
				__( 'Failed to duplicate menu. The menu name may already exist.', 'ux-studio' ),
				array( 'status' => 400 )
			);
		}

		return $this->ok(
			array(
				'menu_id'   => $new_menu_id,
				'menu_name' => $menu_name,
				'edit_url'  => admin_url( 'nav-menus.php?action=edit&menu=' . $new_menu_id ),
			)
		);
	}
}
