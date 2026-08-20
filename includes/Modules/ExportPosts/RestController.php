<?php
/**
 * Export Posts REST controller.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\ExportPosts;

use UxStudio\Rest\Controller;
use WP_Error;
use WP_REST_Request;

defined( 'ABSPATH' ) || exit;

/**
 * POST uxstudio/v1/export-posts/export - return a signed download URL for the
 * given posts and format (the file itself streams through admin-post.php).
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
			'/export-posts/export',
			'POST',
			array( $this, 'export' ),
			array(
				'export_type' => array(
					'required'          => true,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_key',
				),
				'post_ids'    => array(
					'required' => true,
					'type'     => 'array',
					'items'    => array( 'type' => 'integer' ),
				),
			),
			'edit_posts'
		);
	}

	/**
	 * Return a signed download URL for the requested export.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function export( WP_REST_Request $request ) {
		$type = (string) $request->get_param( 'export_type' );
		if ( ! $this->module->is_valid_type( $type ) ) {
			return new WP_Error( 'uxstudio_invalid_type', __( 'Invalid export type.', 'ux-studio' ), array( 'status' => 400 ) );
		}

		$ids = array_values( array_filter( array_map( 'absint', (array) $request->get_param( 'post_ids' ) ) ) );
		if ( array() === $ids ) {
			return new WP_Error( 'uxstudio_no_posts', __( 'No posts selected for export.', 'ux-studio' ), array( 'status' => 400 ) );
		}

		return $this->ok( array( 'url' => $this->module->build_download_url( $type, $ids ) ) );
	}
}
