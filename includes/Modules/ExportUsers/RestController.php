<?php
/**
 * Export Users REST controller.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\ExportUsers;

use UxStudio\Rest\Controller;
use WP_Error;
use WP_REST_Request;

defined( 'ABSPATH' ) || exit;

/**
 * POST uxstudio/v1/export-users/export - return a signed download URL for the
 * given users and format (the file itself streams through admin-post.php).
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
			'/export-users/export',
			'POST',
			array( $this, 'export' ),
			array(
				'export_type' => array(
					'required'          => true,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_key',
				),
				'user_ids'    => array(
					'required' => true,
					'type'     => 'array',
					'items'    => array( 'type' => 'integer' ),
				),
			),
			'list_users'
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

		$ids = array_values( array_filter( array_map( 'absint', (array) $request->get_param( 'user_ids' ) ) ) );
		if ( array() === $ids ) {
			return new WP_Error( 'uxstudio_no_users', __( 'No users selected for export.', 'ux-studio' ), array( 'status' => 400 ) );
		}

		$ids = array_values( array_filter( $ids, fn ( int $id ): bool => $this->module->can_export_user( $id ) ) );
		if ( array() === $ids ) {
			return new WP_Error( 'uxstudio_forbidden', __( 'You are not allowed to export these users.', 'ux-studio' ), array( 'status' => 403 ) );
		}

		return $this->ok( array( 'url' => $this->module->build_download_url( $type, $ids ) ) );
	}
}
