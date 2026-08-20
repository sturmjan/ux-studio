<?php
/**
 * Dashboard Widgets REST controller.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\DashboardWidgets;

use UxStudio\Rest\Controller;
use WP_REST_Request;

defined( 'ABSPATH' ) || exit;

/**
 * GET  uxstudio/v1/dashboard-widgets/tasks     - current tasks + notes
 * POST uxstudio/v1/dashboard-widgets/tasks     - persist full tasks list + notes
 * GET  uxstudio/v1/dashboard-widgets/pagespeed - PageSpeed Insights performance score
 * GET  uxstudio/v1/dashboard-widgets/activity  - recent rows from the shared activity log
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
		$this->route( '/dashboard-widgets/tasks', 'GET', array( $this, 'get_tasks' ) );
		$this->route(
			'/dashboard-widgets/tasks',
			'POST',
			array( $this, 'save_tasks' ),
			array(
				'tasks' => array(
					'required' => false,
					'type'     => 'array',
				),
				'notes' => array(
					'required'          => false,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_textarea_field',
				),
			)
		);
		$this->route(
			'/dashboard-widgets/pagespeed',
			'GET',
			array( $this, 'pagespeed' ),
			array(
				'url' => array(
					'required'          => false,
					'type'              => 'string',
					'sanitize_callback' => 'esc_url_raw',
				),
			)
		);
		$this->route(
			'/dashboard-widgets/activity',
			'GET',
			array( $this, 'activity' ),
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
	 * Return the current tasks list and notes text.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function get_tasks( WP_REST_Request $request ) {
		return $this->ok(
			array(
				'tasks' => $this->module->get_tasks(),
				'notes' => $this->module->get_notes(),
			)
		);
	}

	/**
	 * Persist the full desired tasks list and notes text.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function save_tasks( WP_REST_Request $request ) {
		$tasks = $request->get_param( 'tasks' );
		$notes = (string) $request->get_param( 'notes' );

		$saved_tasks = $this->module->save_tasks( is_array( $tasks ) ? $tasks : array() );
		$saved_notes = $this->module->save_notes( $notes );

		return $this->ok(
			array(
				'tasks' => $saved_tasks,
				'notes' => $saved_notes,
			)
		);
	}

	/**
	 * Return the PageSpeed Insights performance score for a URL.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function pagespeed( WP_REST_Request $request ) {
		$url = (string) $request->get_param( 'url' );
		return $this->ok( $this->module->get_pagespeed( $url ) );
	}

	/**
	 * Return recent rows from the shared activity log.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function activity( WP_REST_Request $request ) {
		$limit = (int) $request->get_param( 'limit' );
		if ( $limit <= 0 ) {
			$limit = 10;
		}
		return $this->ok( $this->module->get_recent_activity( $limit ) );
	}
}
