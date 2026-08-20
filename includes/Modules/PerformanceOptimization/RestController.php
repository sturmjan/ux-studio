<?php
/**
 * Performance Optimization REST controller.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\PerformanceOptimization;

use UxStudio\Rest\Controller;
use WP_REST_Request;

defined( 'ABSPATH' ) || exit;

/**
 * GET  uxstudio/v1/performance-optimization/analyze          - run read-only checks
 * POST uxstudio/v1/performance-optimization/fix/{fix_id}     - run one whitelisted fix
 * GET  uxstudio/v1/performance-optimization/history           - last 50 score history rows
 *
 * All routes require manage_options (the default capability gate applied by
 * Controller::route()). No DB export, no phpinfo, no query log - see the
 * Module class docblock for the reasoning.
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
		$this->route( '/performance-optimization/analyze', 'GET', array( $this, 'analyze' ) );
		$this->route( '/performance-optimization/fix/(?P<fix_id>[a-z_]+)', 'POST', array( $this, 'fix' ) );
		$this->route( '/performance-optimization/history', 'GET', array( $this, 'history' ) );
	}

	/**
	 * @param WP_REST_Request $request Request.
	 */
	public function analyze( WP_REST_Request $request ) {
		return $this->ok( $this->module->analyze() );
	}

	/**
	 * @param WP_REST_Request $request Request.
	 */
	public function fix( WP_REST_Request $request ) {
		$fix_id = sanitize_key( (string) $request->get_param( 'fix_id' ) );
		$result = $this->module->fix( $fix_id );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return $this->ok( $result );
	}

	/**
	 * @param WP_REST_Request $request Request.
	 */
	public function history( WP_REST_Request $request ) {
		return $this->ok( $this->module->get_history() );
	}
}
