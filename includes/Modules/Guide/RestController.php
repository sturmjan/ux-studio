<?php
/**
 * Guide REST controller.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\Guide;

use UxStudio\Rest\Controller;
use WP_REST_Request;

defined( 'ABSPATH' ) || exit;

/**
 * GET  uxstudio/v1/guide/steps    - steps + completion state
 * POST uxstudio/v1/guide/progress - store completed step ids
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
		$this->route( '/guide/steps', 'GET', array( $this, 'get_steps' ) );
		$this->route(
			'/guide/progress',
			'POST',
			array( $this, 'save_progress' ),
			array(
				'completed' => array(
					'required' => true,
					'type'     => 'array',
				),
			)
		);
	}

	/**
	 * Return the guide steps with completion flags.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function get_steps( WP_REST_Request $request ) {
		return $this->ok( array( 'steps' => $this->module->steps_with_progress() ) );
	}

	/**
	 * Store the completed step ids.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function save_progress( WP_REST_Request $request ) {
		$completed = (array) $request->get_param( 'completed' );
		return $this->ok( array( 'completed' => $this->module->save_progress( $completed ) ) );
	}
}
