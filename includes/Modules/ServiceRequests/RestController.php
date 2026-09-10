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
 * POST   uxstudio/v1/service-requests/items/{id}/status      - change status (legacy, local only)
 * POST   uxstudio/v1/service-requests/items/{id}/reply       - client reply into the central thread
 * POST   uxstudio/v1/service-requests/items/{id}/resync      - force a push/pull for this request
 *
 * Since F2 the central app owns a request: `status` here is a local mirror and
 * the value the UI shows is `central_status`. The `/status` route is kept so
 * pre-F2 rows that never reached the central app can still be closed locally.
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
				'page_url'        => array(
					'required'          => false,
					'type'              => 'string',
					'sanitize_callback' => 'esc_url_raw',
				),
				'type'            => array(
					'required'          => false,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_key',
				),
				'priority'        => array(
					'required'          => false,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_key',
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

		$this->route(
			'/service-requests/items/(?P<id>\d+)/reply',
			'POST',
			array( $this, 'reply' ),
			array(
				'body' => array(
					'required'          => true,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_textarea_field',
				),
			)
		);

		$this->route(
			'/service-requests/items/(?P<id>\d+)/resync',
			'POST',
			array( $this, 'resync' )
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
			'page_url'        => (string) $request->get_param( 'page_url' ),
			'type'            => (string) $request->get_param( 'type' ),
			'priority'        => (string) $request->get_param( 'priority' ),
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

	/**
	 * Client reply, delivered into the central conversation.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function reply( WP_REST_Request $request ) {
		$result = $this->module->reply(
			absint( $request->get_param( 'id' ) ),
			(string) $request->get_param( 'body' )
		);

		return $result instanceof WP_Error ? $result : $this->ok( $result );
	}

	/**
	 * Force a sync pass for this request.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function resync( WP_REST_Request $request ) {
		$result = $this->module->resync( absint( $request->get_param( 'id' ) ) );

		return $result instanceof WP_Error ? $result : $this->ok( $result );
	}
}
