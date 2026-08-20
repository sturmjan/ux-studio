<?php
/**
 * Menu Organizer REST controller: config CRUD, current-menu source list,
 * default-config export and conflict detection.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\AdminCustomiser;

use UxStudio\Rest\Controller;
use WP_REST_Request;

defined( 'ABSPATH' ) || exit;

/**
 * GET  uxstudio/v1/admin-customiser/menu-organizer/config            - current config
 * POST uxstudio/v1/admin-customiser/menu-organizer/config            - save config
 * GET  uxstudio/v1/admin-customiser/menu-organizer/current-menu      - native admin menu tree (editor source list)
 * POST uxstudio/v1/admin-customiser/menu-organizer/export-default    - export current config as JSON
 * POST uxstudio/v1/admin-customiser/menu-organizer/detect-conflicts  - detected conflicting menu-editor plugins
 */
final class MenuOrganizerRestController extends Controller {

	private MenuOrganizer $organizer;

	public function __construct( MenuOrganizer $organizer ) {
		$this->organizer = $organizer;
	}

	public function register_routes(): void {
		$this->route(
			'/admin-customiser/menu-organizer/config',
			'GET',
			array( $this, 'get_config' ),
			array(),
			'manage_options'
		);
		$this->route(
			'/admin-customiser/menu-organizer/config',
			'POST',
			array( $this, 'save_config' ),
			array(),
			'manage_options'
		);
		$this->route(
			'/admin-customiser/menu-organizer/current-menu',
			'GET',
			array( $this, 'current_menu' ),
			array(),
			'manage_options'
		);
		$this->route(
			'/admin-customiser/menu-organizer/export-default',
			'POST',
			array( $this, 'export_default' ),
			array(),
			'manage_options'
		);
		$this->route(
			'/admin-customiser/menu-organizer/detect-conflicts',
			'POST',
			array( $this, 'detect_conflicts' ),
			array(),
			'manage_options'
		);
	}

	public function get_config( WP_REST_Request $request ) {
		return $this->ok( $this->organizer->get_config() );
	}

	public function save_config( WP_REST_Request $request ) {
		$data = $request->get_json_params();
		if ( ! is_array( $data ) ) {
			$data = array();
		}
		$config = $this->organizer->save_config( $data );
		return $this->ok( $config );
	}

	public function current_menu( WP_REST_Request $request ) {
		return $this->ok( $this->organizer->get_current_menu_items() );
	}

	public function export_default( WP_REST_Request $request ) {
		return $this->ok( $this->organizer->export_default_config() );
	}

	public function detect_conflicts( WP_REST_Request $request ) {
		return $this->ok( $this->organizer->detect_conflicts() );
	}
}
