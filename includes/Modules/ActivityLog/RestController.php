<?php
/**
 * Activity Log REST controller.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\ActivityLog;

use UxStudio\Rest\Controller;
use WP_REST_Request;

defined( 'ABSPATH' ) || exit;

/**
 * GET    uxstudio/v1/activity-log/entries - filtered/paginated log rows
 * DELETE uxstudio/v1/activity-log/entries - purge entries older than retention
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
			'/activity-log/entries',
			'GET',
			array( $this, 'list_entries' ),
			array(
				'module' => array(
					'required'          => false,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
				),
				'action' => array(
					'required'          => false,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
				),
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
		$this->route( '/activity-log/entries', 'DELETE', array( $this, 'purge' ) );
	}

	/**
	 * Filtered/paginated log entries.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function list_entries( WP_REST_Request $request ) {
		$result = $this->module->get_entries(
			array(
				'module' => (string) $request->get_param( 'module' ),
				'action' => (string) $request->get_param( 'action' ),
				'limit'  => (int) ( $request->get_param( 'limit' ) ?: 50 ),
				'offset' => (int) ( $request->get_param( 'offset' ) ?: 0 ),
			)
		);
		return $this->ok( $result['items'], array( 'total' => $result['total'] ) );
	}

	/**
	 * Purge entries older than the configured retention.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function purge( WP_REST_Request $request ) {
		$deleted = $this->module->purge_old_entries();
		return $this->ok( array( 'deleted' => $deleted ) );
	}
}
