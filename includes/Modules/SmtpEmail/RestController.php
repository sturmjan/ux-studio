<?php
/**
 * SMTP Email REST controller.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\SmtpEmail;

use UxStudio\Rest\Controller;
use WP_REST_Request;

defined( 'ABSPATH' ) || exit;

/**
 * GET  uxstudio/v1/smtp-email/logs - last 50 delivery log rows
 * POST uxstudio/v1/smtp-email/test - send a test email through the configured transport
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
		$this->route( '/smtp-email/logs', 'GET', array( $this, 'logs' ) );
		$this->route(
			'/smtp-email/test',
			'POST',
			array( $this, 'test' ),
			array(
				'to' => array(
					'required'          => false,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_email',
				),
			)
		);
	}

	/**
	 * Return the last 50 delivery log rows.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function logs( WP_REST_Request $request ) {
		return $this->ok( $this->module->get_logs() );
	}

	/**
	 * Send a test email.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function test( WP_REST_Request $request ) {
		$to = (string) $request->get_param( 'to' );
		return $this->ok( $this->module->send_test( $to ) );
	}
}
