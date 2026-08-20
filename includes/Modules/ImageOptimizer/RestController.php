<?php
/**
 * Image Optimizer REST controller.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\ImageOptimizer;

use UxStudio\Rest\Controller;
use WP_Error;
use WP_REST_Request;

defined( 'ABSPATH' ) || exit;

/**
 * POST uxstudio/v1/image-optimizer/optimize/{attachment_id} - optimize one image
 * POST uxstudio/v1/image-optimizer/bulk-start                - queue + schedule all unoptimized images
 * GET  uxstudio/v1/image-optimizer/bulk-status                - queue progress
 * POST uxstudio/v1/image-optimizer/restore/{attachment_id}   - restore the backed-up original
 *
 * All routes require manage_options (the default capability gate applied by
 * Controller::route()).
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
		$this->route(
			'/image-optimizer/optimize/(?P<id>\d+)',
			'POST',
			array( $this, 'optimize' )
		);
		$this->route( '/image-optimizer/bulk-start', 'POST', array( $this, 'bulk_start' ) );
		$this->route( '/image-optimizer/bulk-status', 'GET', array( $this, 'bulk_status' ) );
		$this->route(
			'/image-optimizer/restore/(?P<id>\d+)',
			'POST',
			array( $this, 'restore' )
		);
	}

	/**
	 * @param WP_REST_Request $request Request.
	 */
	public function optimize( WP_REST_Request $request ) {
		$id     = absint( $request->get_param( 'id' ) );
		$result = $this->module->optimize( $id );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return $this->ok( $result );
	}

	/**
	 * @param WP_REST_Request $request Request.
	 */
	public function bulk_start( WP_REST_Request $request ) {
		return $this->ok( $this->module->bulk_start() );
	}

	/**
	 * @param WP_REST_Request $request Request.
	 */
	public function bulk_status( WP_REST_Request $request ) {
		return $this->ok( $this->module->bulk_status() );
	}

	/**
	 * @param WP_REST_Request $request Request.
	 */
	public function restore( WP_REST_Request $request ) {
		$id     = absint( $request->get_param( 'id' ) );
		$result = $this->module->restore( $id );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return $this->ok( $result );
	}
}
