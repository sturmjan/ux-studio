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
 * GET uxstudio/v1/bot-throttle/log - last 100 blocked-hit log rows
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
	}

	/**
	 * Return the last 100 log rows.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function log( WP_REST_Request $request ) {
		return $this->ok( $this->module->get_log() );
	}
}
