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
 * GET  uxstudio/v1/smtp-email/logs            - paginated / searchable delivery log
 * POST uxstudio/v1/smtp-email/logs/clear      - delete the whole delivery log
 * GET  uxstudio/v1/smtp-email/logs/export-url - nonce-signed CSV export URL
 * POST uxstudio/v1/smtp-email/brevo/verify    - check the stored Brevo API key
 * POST uxstudio/v1/smtp-email/test            - send a test email (body `mailer`: through exactly that transport, else primary + fallback)
 * POST uxstudio/v1/smtp-email/resend          - resend the last message that went through wp_mail()
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
			'/smtp-email/logs',
			'GET',
			array( $this, 'logs' ),
			array(
				'page'     => array(
					'type'              => 'integer',
					'default'           => 1,
					'sanitize_callback' => 'absint',
				),
				'per_page' => array(
					'type'              => 'integer',
					'default'           => 20,
					'sanitize_callback' => 'absint',
				),
				'search'   => array(
					'type'              => 'string',
					'default'           => '',
					'sanitize_callback' => 'sanitize_text_field',
				),
			)
		);
		$this->route( '/smtp-email/logs/clear', 'POST', array( $this, 'clear_logs' ) );
		$this->route( '/smtp-email/logs/export-url', 'GET', array( $this, 'export_url' ) );
		$this->route( '/smtp-email/brevo/verify', 'POST', array( $this, 'verify_brevo' ) );
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
				'mailer' => array(
					'required'          => false,
					'type'              => 'string',
					'default'           => '',
					'sanitize_callback' => 'sanitize_key',
				),
			)
		);
		$this->route( '/smtp-email/resend', 'POST', array( $this, 'resend' ) );
	}

	/**
	 * Paginated / searchable delivery log.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function logs( WP_REST_Request $request ) {
		return $this->ok(
			$this->module->get_logs(
				(int) $request->get_param( 'page' ),
				(int) $request->get_param( 'per_page' ),
				(string) $request->get_param( 'search' )
			)
		);
	}

	/**
	 * Delete the whole delivery log.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function clear_logs( WP_REST_Request $request ) {
		return $this->ok( array( 'cleared' => $this->module->clear_logs() ) );
	}

	/**
	 * Nonce-signed CSV export URL.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function export_url( WP_REST_Request $request ) {
		return $this->ok( array( 'url' => $this->module->export_logs_url() ) );
	}

	/**
	 * Verify the stored Brevo API key.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function verify_brevo( WP_REST_Request $request ) {
		return $this->ok( $this->module->verify_brevo_key() );
	}

	/**
	 * Send a test email.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function test( WP_REST_Request $request ) {
		$to     = (string) $request->get_param( 'to' );
		$mailer = (string) $request->get_param( 'mailer' );
		return $this->ok( $this->module->send_test( $to, $mailer ) );
	}

	/**
	 * Resend the last captured outgoing message.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function resend( WP_REST_Request $request ) {
		return $this->ok( $this->module->resend_last() );
	}
}
