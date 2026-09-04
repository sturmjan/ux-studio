<?php
/**
 * Cron Control REST controller.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\CronControl;

use UxStudio\Rest\Controller;
use WP_REST_Request;

defined( 'ABSPATH' ) || exit;

/**
 * GET    uxstudio/v1/cron-control/status    - WP-Cron status + mode snapshot
 * GET    uxstudio/v1/cron-control/events    - flat list of scheduled events
 * GET    uxstudio/v1/cron-control/schedules - registered cron schedules
 * POST   uxstudio/v1/cron-control/events/run - run one scheduled event now
 * DELETE uxstudio/v1/cron-control/events    - unschedule one event
 * GET    uxstudio/v1/cron-control/watch     - last watcher result
 * POST   uxstudio/v1/cron-control/watch     - run the watcher now
 *
 * The run/delete endpoints take the event identity (hook, timestamp, args) as
 * body params rather than a {hook} URL path segment: hook names can contain
 * characters that would need URL-encoding, and timestamp+args are required
 * anyway to disambiguate a hook scheduled multiple times with different args.
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
		$this->route( '/cron-control/status', 'GET', array( $this, 'status' ) );
		$this->route( '/cron-control/events', 'GET', array( $this, 'events' ) );
		$this->route( '/cron-control/schedules', 'GET', array( $this, 'schedules' ) );

		$this->route(
			'/cron-control/events/run',
			'POST',
			array( $this, 'run' ),
			$this->event_args()
		);

		$this->route(
			'/cron-control/events',
			'DELETE',
			array( $this, 'delete' ),
			$this->event_args()
		);

		$this->route( '/cron-control/watch', 'GET', array( $this, 'watch_result' ) );
		$this->route( '/cron-control/watch', 'POST', array( $this, 'run_watch' ) );
	}

	/**
	 * Shared arg declarations for the run/delete endpoints.
	 *
	 * @return array
	 */
	private function event_args(): array {
		return array(
			'hook'      => array(
				'required'          => true,
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			),
			'timestamp' => array(
				'required'          => true,
				'type'              => 'integer',
				'sanitize_callback' => 'absint',
			),
			'args'      => array(
				'required' => false,
				'type'     => 'array',
				'default'  => array(),
			),
		);
	}

	/**
	 * WP-Cron status snapshot.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function status( WP_REST_Request $request ) {
		return $this->ok( $this->module->get_status() );
	}

	/**
	 * Flat list of scheduled events.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function events( WP_REST_Request $request ) {
		return $this->ok( $this->module->get_events() );
	}

	/**
	 * Registered cron schedules.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function schedules( WP_REST_Request $request ) {
		return $this->ok( $this->module->get_schedules() );
	}

	/**
	 * Run one scheduled event now.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function run( WP_REST_Request $request ) {
		$hook      = (string) $request->get_param( 'hook' );
		$timestamp = (int) $request->get_param( 'timestamp' );
		$args      = (array) $request->get_param( 'args' );

		return $this->ok( $this->module->run_event( $hook, $timestamp, $args ) );
	}

	/**
	 * Delete (unschedule) one event.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function delete( WP_REST_Request $request ) {
		$hook      = (string) $request->get_param( 'hook' );
		$timestamp = (int) $request->get_param( 'timestamp' );
		$args      = (array) $request->get_param( 'args' );

		return $this->ok( $this->module->delete_event( $hook, $timestamp, $args ) );
	}

	/**
	 * Last cached watcher result.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function watch_result( WP_REST_Request $request ) {
		return $this->ok( $this->module->watch_result() );
	}

	/**
	 * Run the watcher now and return the fresh result.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function run_watch( WP_REST_Request $request ) {
		return $this->ok( $this->module->run_watch_now() );
	}
}
