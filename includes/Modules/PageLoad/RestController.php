<?php
/**
 * Page Load REST controller.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\PageLoad;

use UxStudio\Rest\Controller;
use WP_REST_Request;

defined( 'ABSPATH' ) || exit;

/**
 * GET uxstudio/v1/page-load/overview - last 24h summary + hourly breakdown
 * GET uxstudio/v1/page-load/log      - recent raw log rows
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
		$this->route( '/page-load/overview', 'GET', array( $this, 'overview' ) );
		$this->route(
			'/page-load/log',
			'GET',
			array( $this, 'log' ),
			array(
				'limit' => array(
					'required'          => false,
					'type'              => 'integer',
					'sanitize_callback' => 'absint',
				),
			)
		);
	}

	/**
	 * Return the last 24h summary + hourly breakdown.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function overview( WP_REST_Request $request ) {
		return $this->ok( $this->module->get_overview() );
	}

	/**
	 * Return recent raw log rows.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function log( WP_REST_Request $request ) {
		$limit = (int) $request->get_param( 'limit' );
		if ( $limit <= 0 ) {
			$limit = 50;
		}
		$limit = max( 1, min( 200, $limit ) );
		return $this->ok( $this->module->get_recent_log( $limit ) );
	}
}
