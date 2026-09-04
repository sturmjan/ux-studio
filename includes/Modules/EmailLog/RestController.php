<?php
/**
 * Email Log REST controller.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\EmailLog;

use UxStudio\Rest\Controller;
use WP_Error;
use WP_REST_Request;

defined( 'ABSPATH' ) || exit;

/**
 * GET    uxstudio/v1/email-log/entries             - paginated log rows
 * GET    uxstudio/v1/email-log/entries/{id}        - full detail (body/headers/attachments)
 * DELETE uxstudio/v1/email-log/entries/{id}        - delete one entry
 * POST   uxstudio/v1/email-log/entries/{id}/resend - re-send a stored message
 * POST   uxstudio/v1/email-log/clear               - delete every entry
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
			'/email-log/entries',
			'GET',
			array( $this, 'list_entries' ),
			array(
				'limit'  => array(
					'required'          => false,
					'type'              => 'integer',
					'sanitize_callback' => 'absint',
				),
				'offset' => array(
					'required'          => false,
					'type'              => 'integer',
					'sanitize_callback' => 'absint',
				),
			)
		);
		$this->route(
			'/email-log/entries/(?P<id>\d+)',
			'GET',
			array( $this, 'get_entry' ),
			array(
				'id' => array(
					'required'          => true,
					'type'              => 'integer',
					'sanitize_callback' => 'absint',
				),
			)
		);
		$this->route(
			'/email-log/entries/(?P<id>\d+)',
			'DELETE',
			array( $this, 'delete_entry' ),
			array(
				'id' => array(
					'required'          => true,
					'type'              => 'integer',
					'sanitize_callback' => 'absint',
				),
			)
		);
		$this->route(
			'/email-log/entries/(?P<id>\d+)/resend',
			'POST',
			array( $this, 'resend_entry' ),
			array(
				'id' => array(
					'required'          => true,
					'type'              => 'integer',
					'sanitize_callback' => 'absint',
				),
			)
		);
		$this->route( '/email-log/clear', 'POST', array( $this, 'clear' ) );
	}

	/**
	 * Full detail of a single entry.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function get_entry( WP_REST_Request $request ) {
		$id    = (int) $request['id'];
		$entry = $this->module->get_entry( $id );
		if ( null === $entry ) {
			return new WP_Error( 'uxstudio_not_found', __( 'Entry not found.', 'ux-studio' ), array( 'status' => 404 ) );
		}
		return $this->ok( $entry );
	}

	/**
	 * Re-send a stored message.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function resend_entry( WP_REST_Request $request ) {
		$id     = (int) $request['id'];
		$result = $this->module->resend_entry( $id );
		if ( ! $result['success'] ) {
			return new WP_Error( 'uxstudio_resend_failed', $result['message'], array( 'status' => 400 ) );
		}
		return $this->ok( array( 'id' => $id, 'message' => $result['message'] ) );
	}

	/**
	 * Paginated log entries.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function list_entries( WP_REST_Request $request ) {
		$result = $this->module->get_entries(
			(int) ( $request->get_param( 'limit' ) ?: 50 ),
			(int) ( $request->get_param( 'offset' ) ?: 0 )
		);
		return $this->ok( $result['items'], array( 'total' => $result['total'] ) );
	}

	/**
	 * Delete a single entry.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function delete_entry( WP_REST_Request $request ) {
		$id = (int) $request['id'];
		if ( ! $this->module->delete_entry( $id ) ) {
			return new WP_Error( 'uxstudio_delete_failed', __( 'Could not delete this entry.', 'ux-studio' ), array( 'status' => 400 ) );
		}
		return $this->ok( array( 'id' => $id ) );
	}

	/**
	 * Delete every entry.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function clear( WP_REST_Request $request ) {
		$this->module->clear_all();
		return $this->ok( array( 'cleared' => true ) );
	}
}
