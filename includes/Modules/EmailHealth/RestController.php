<?php
/**
 * Email Health REST controller.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\EmailHealth;

use UxStudio\Rest\Controller;
use WP_REST_Request;

defined( 'ABSPATH' ) || exit;

/**
 * POST uxstudio/v1/email-health/test   - send a test email, return the result
 * GET  uxstudio/v1/email-health/status - return the last stored result
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
		$this->route( '/email-health/test', 'POST', array( $this, 'send_test' ) );
		$this->route( '/email-health/status', 'GET', array( $this, 'status' ) );
	}

	/**
	 * Send a test email and return the outcome.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function send_test( WP_REST_Request $request ) {
		return $this->ok( $this->module->run_test() );
	}

	/**
	 * Return the last stored result.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function status( WP_REST_Request $request ) {
		return $this->ok( $this->module->get_last_result() );
	}
}
