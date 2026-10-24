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
 * POST   uxstudio/v1/cron-control/central/resync - re-send cron config to the central app
 * GET    uxstudio/v1/cron-control/url-events        - events that call a URL (with the last result)
 * POST   uxstudio/v1/cron-control/url-events        - create or change one (body `id` to change)
 * DELETE uxstudio/v1/cron-control/url-events        - delete one (`id`)
 * POST   uxstudio/v1/cron-control/url-events/run    - call the address now (`id`)
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

		$this->route( '/cron-control/central/resync', 'POST', array( $this, 'resync_central' ) );

		$id_arg = array(
			'id' => array(
				'required'          => true,
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_key',
			),
		);
		$this->route( '/cron-control/url-events', 'GET', array( $this, 'url_events' ) );
		$this->route(
			'/cron-control/url-events',
			'POST',
			array( $this, 'save_url_event' ),
			array(
				'id'        => array(
					'required'          => false,
					'type'              => 'string',
					'default'           => '',
					'sanitize_callback' => 'sanitize_key',
				),
				'name'      => array(
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
				),
				'url'       => array(
					'required' => true,
					'type'     => 'string',
				),
				'method'    => array(
					'type'    => 'string',
					'enum'    => array( 'GET', 'POST' ),
					'default' => 'GET',
				),
				'body'      => array(
					'type'    => 'string',
					'default' => '',
				),
				'schedule'  => array(
					'type'              => 'string',
					'default'           => '',
					'sanitize_callback' => 'sanitize_key',
				),
				'timeout'   => array(
					'type'    => 'integer',
					'default' => 15,
				),
				'enabled'   => array(
					'type'    => 'boolean',
					'default' => true,
				),
				'first_run' => array(
					'type'        => 'string',
					'default'     => '',
					'description' => 'Site time as YYYY-MM-DDTHH:MM, empty = in a minute.',
				),
			)
		);
		$this->route( '/cron-control/url-events', 'DELETE', array( $this, 'delete_url_event' ), $id_arg );
		$this->route( '/cron-control/url-events/run', 'POST', array( $this, 'run_url_event' ), $id_arg );
	}

	/**
	 * Events that call a URL.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function url_events( WP_REST_Request $request ) {
		return $this->ok( $this->module->get_url_events() );
	}

	/**
	 * Create or change a URL event.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function save_url_event( WP_REST_Request $request ) {
		$input = array(
			'name'      => (string) $request->get_param( 'name' ),
			'url'       => (string) $request->get_param( 'url' ),
			'method'    => (string) $request->get_param( 'method' ),
			'body'      => (string) $request->get_param( 'body' ),
			'schedule'  => (string) $request->get_param( 'schedule' ),
			'timeout'   => (int) $request->get_param( 'timeout' ),
			'enabled'   => (bool) $request->get_param( 'enabled' ),
			'first_run' => self::site_time_to_timestamp( (string) $request->get_param( 'first_run' ) ),
		);
		$event = $this->module->save_url_event( $input, (string) $request->get_param( 'id' ) );
		return is_wp_error( $event ) ? $event : $this->ok( $event );
	}

	/**
	 * Delete a URL event.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function delete_url_event( WP_REST_Request $request ) {
		return $this->ok( array( 'deleted' => $this->module->delete_url_event( (string) $request->get_param( 'id' ) ) ) );
	}

	/**
	 * Call the address of a URL event now.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function run_url_event( WP_REST_Request $request ) {
		$result = $this->module->run_url_event( (string) $request->get_param( 'id' ) );
		if ( null === $result ) {
			return new \WP_Error( 'uxstudio_not_found', __( 'That event does not exist.', 'ux-studio' ), array( 'status' => 404 ) );
		}
		return $this->ok( $result );
	}

	/**
	 * `YYYY-MM-DDTHH:MM` of the site's time zone to a Unix timestamp (0 for an empty or malformed value).
	 *
	 * @param string $value Value from the datetime field.
	 */
	private static function site_time_to_timestamp( string $value ): int {
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}/', $value ) ) {
			return 0;
		}
		try {
			$date = new \DateTimeImmutable( substr( str_replace( 'T', ' ', $value ), 0, 16 ), wp_timezone() );
		} catch ( \Exception $e ) {
			return 0;
		}
		return $date->getTimestamp();
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

	/**
	 * Re-send the cron config to the central app (central mode only).
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function resync_central( WP_REST_Request $request ) {
		return $this->ok( $this->module->resync_central() );
	}
}
