<?php
/**
 * Auto Image Upload REST controller.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\AutoImageUpload;

use UxStudio\Rest\Controller;
use WP_Error;
use WP_REST_Request;

defined( 'ABSPATH' ) || exit;

/**
 * POST uxstudio/v1/auto-image-upload/sideload - sideload one external image.
 * POST uxstudio/v1/auto-image-upload/bulk     - process one batch of posts.
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
			'/auto-image-upload/sideload',
			'POST',
			array( $this, 'sideload' ),
			array(
				'url'     => array(
					'required' => true,
					'type'     => 'string',
				),
				'post_id' => array(
					'type'              => 'integer',
					'default'           => 0,
					'sanitize_callback' => 'absint',
				),
			),
			'upload_files'
		);

		$this->route(
			'/auto-image-upload/bulk',
			'POST',
			array( $this, 'bulk' ),
			array(
				'offset' => array(
					'type'              => 'integer',
					'default'           => 0,
					'sanitize_callback' => 'absint',
				),
			),
			'manage_options'
		);
	}

	/**
	 * Sideload a single external image (instant paste rewrite from the editor).
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function sideload( WP_REST_Request $request ) {
		$url     = (string) $request->get_param( 'url' );
		$post_id = (int) $request->get_param( 'post_id' );

		$result = $this->module->processor()->sideload( $url, $post_id );
		if ( is_wp_error( $result ) ) {
			return new WP_Error( 'uxstudio_sideload_failed', $result->get_error_message(), array( 'status' => 400 ) );
		}

		return $this->ok(
			array(
				'id'         => (int) $result['id'],
				'url'        => (string) $result['url'],
				'source_url' => (string) $result['source_url'],
			)
		);
	}

	/**
	 * Process one batch of existing posts, rewriting external images (no ajax).
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function bulk( WP_REST_Request $request ) {
		$offset = max( 0, (int) $request->get_param( 'offset' ) );

		return $this->ok( $this->module->process_bulk_batch( $offset ) );
	}
}
