<?php
/**
 * Service Requests REST controller.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\ServiceRequests;

use UxStudio\Rest\Controller;
use WP_Error;
use WP_REST_Request;

defined( 'ABSPATH' ) || exit;

/**
 * GET    uxstudio/v1/service-requests/items                 - list requests (optionally ?status=)
 * POST   uxstudio/v1/service-requests/items                 - create a request
 * DELETE uxstudio/v1/service-requests/items/{id}             - delete a request
 * POST   uxstudio/v1/service-requests/items/{id}/status      - change status
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
			'/service-requests/items',
			'GET',
			array( $this, 'list_items' ),
			array(
				'status' => array(
					'required'          => false,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
				),
			)
		);

		$this->route(
			'/service-requests/items',
			'POST',
			array( $this, 'create_item' ),
			array(
				'title'           => array(
					'required'          => true,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
				),
				'description'     => array(
					'required'          => false,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_textarea_field',
				),
				'requester_email' => array(
					'required'          => false,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_email',
				),
				'attachment_id'   => array(
					'required'          => false,
					'type'              => 'integer',
					'sanitize_callback' => 'absint',
				),
			)
		);

		$this->route(
			'/service-requests/items/(?P<id>\d+)',
			'DELETE',
			array( $this, 'delete_item' )
		);

		$this->route(
			'/service-requests/items/(?P<id>\d+)/status',
			'POST',
			array( $this, 'update_status' ),
			array(
				'status' => array(
					'required'          => true,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
				),
			)
		);
	}

	/**
	 * List requests, optionally filtered by status.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function list_items( WP_REST_Request $request ) {
		$status = (string) $request->get_param( 'status' );
		return $this->ok( $this->module->list_items( $status ) );
	}

	/**
	 * Create a new request.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function create_item( WP_REST_Request $request ) {
		$data = array(
			'title'           => (string) $request->get_param( 'title' ),
			'description'     => (string) $request->get_param( 'description' ),
			'requester_email' => (string) $request->get_param( 'requester_email' ),
			'attachment_id'   => absint( $request->get_param( 'attachment_id' ) ),
		);

		return $this->ok( $this->module->create_item( $data ) );
	}

	/**
	 * Delete a request.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function delete_item( WP_REST_Request $request ) {
		$id = absint( $request->get_param( 'id' ) );

		if ( ! $this->module->delete_item( $id ) ) {
			return new WP_Error( 'uxstudio_not_found', __( 'Service request not found.', 'ux-studio' ), array( 'status' => 404 ) );
		}

		return $this->ok( array( 'deleted' => true ) );
	}

	/**
	 * Change a request's status.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function update_status( WP_REST_Request $request ) {
		$id     = absint( $request->get_param( 'id' ) );
		$status = (string) $request->get_param( 'status' );

		$result = $this->module->update_status( $id, $status );
		return $result instanceof WP_Error ? $result : $this->ok( $result );
	}
}
