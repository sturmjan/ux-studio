<?php
/**
 * Elementor Import REST controller.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\ElementorImport;

use UxStudio\Rest\Controller;
use WP_REST_Request;

defined( 'ABSPATH' ) || exit;

/**
 * POST uxstudio/v1/elementor-import - import a template from a JSON/ZIP upload.
 *
 * Requires manage_options. When Elementor is not active the endpoint returns
 * 424 Failed Dependency with an explanation instead of importing.
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
		$this->route( '/elementor-import', 'POST', array( $this, 'import' ) );
	}

	/**
	 * Handle the upload + import.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function import( WP_REST_Request $request ) {
		if ( ! $this->module->is_elementor_active() ) {
			return new \WP_Error(
				'uxstudio_elementor_inactive',
				__( 'Elementor must be installed and active to import templates.', 'ux-studio' ),
				array( 'status' => 424 )
			);
		}

		$files = $request->get_file_params();
		if ( empty( $files['file'] ) ) {
			return new \WP_Error(
				'uxstudio_no_file',
				__( 'Upload a .json or .zip template file in the "file" field.', 'ux-studio' ),
				array( 'status' => 400 )
			);
		}

		$template = $this->module->read_upload( (array) $files['file'] );
		if ( is_wp_error( $template ) ) {
			return $template;
		}

		$result = $this->module->import_template( $template );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return $this->ok( $result );
	}
}
