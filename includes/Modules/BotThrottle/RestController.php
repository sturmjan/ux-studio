<?php
/**
 * Bot Throttle REST controller.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\BotThrottle;

use UxStudio\Rest\Controller;
use WP_REST_Request;

defined( 'ABSPATH' ) || exit;

/**
 * Routes:
 *   GET  uxstudio/v1/bot-throttle/log         - last 100 log rows
 *   GET  uxstudio/v1/bot-throttle/dashboard   - tier + stats + categories + cache
 *   POST uxstudio/v1/bot-throttle/test        - simulate a UA/IP against the plan
 *   POST uxstudio/v1/bot-throttle/clear-log   - truncate the log
 *   POST uxstudio/v1/bot-throttle/clear-cache - empty the microcache
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
		$this->route( '/bot-throttle/log', 'GET', array( $this, 'log' ) );
		$this->route( '/bot-throttle/dashboard', 'GET', array( $this, 'dashboard' ) );
		$this->route(
			'/bot-throttle/test',
			'POST',
			array( $this, 'test' ),
			array(
				'user_agent' => array(
					'type'              => 'string',
					'required'          => true,
					'sanitize_callback' => 'sanitize_text_field',
				),
				'ip'         => array(
					'type'              => 'string',
					'default'           => '127.0.0.1',
					'sanitize_callback' => 'sanitize_text_field',
				),
			)
		);
		$this->route( '/bot-throttle/clear-log', 'POST', array( $this, 'clear_log' ) );
		$this->route( '/bot-throttle/clear-cache', 'POST', array( $this, 'clear_cache' ) );
	}

	/**
	 * Last 100 log rows.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function log( WP_REST_Request $request ) {
		return $this->ok( $this->module->get_log() );
	}

	/**
	 * Dashboard payload.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function dashboard( WP_REST_Request $request ) {
		return $this->ok( $this->module->dashboard() );
	}

	/**
	 * Simulate a UA/IP.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function test( WP_REST_Request $request ) {
		return $this->ok(
			$this->module->test_ua(
				(string) $request->get_param( 'user_agent' ),
				(string) $request->get_param( 'ip' )
			)
		);
	}

	/**
	 * Truncate the log.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function clear_log( WP_REST_Request $request ) {
		return $this->ok( array( 'cleared' => $this->module->clear_log() ) );
	}

	/**
	 * Empty the microcache.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function clear_cache( WP_REST_Request $request ) {
		return $this->ok( array( 'cleared' => $this->module->clear_cache() ) );
	}
}
