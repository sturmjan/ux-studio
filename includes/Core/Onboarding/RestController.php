<?php
/**
 * REST routes of the onboarding starter pack (uxstudio/v1/onboarding/*).
 *
 * @package UxStudio
 */

namespace UxStudio\Core\Onboarding;

use UxStudio\Plugin;
use UxStudio\Rest\Controller;
use WP_REST_Request;

defined( 'ABSPATH' ) || exit;

/**
 * All routes need `manage_options` (base controller); writes spend the shared per-user write budget.
 *
 *   GET  onboarding/starter           items, presets in words, detections, overlap status, state
 *   POST onboarding/starter/apply     items[], overrides{}, confirmed[]  (snapshot first, login address last and pending)
 *   POST onboarding/starter/revert    put the snapshot back
 *   POST onboarding/login-url/confirm force?  step B: close wp-login.php, send the e-mail
 *   POST onboarding/ca/prepare        url?    node mode + key, returns the POST form for the Central App (CentralApp)
 *   GET  onboarding/ca/status                 connection state, last call of the Central App, CAPTCHA state
 *   POST onboarding/ca/captcha        enabled CAPTCHA keys from the Central App on / off
 *
 * Item ids are checked against a fixed whitelist (StarterPack::ids()); values are cleaned by the modules' own schemas.
 */
final class RestController extends Controller {

	public function register_routes(): void {
		$this->route( '/onboarding/starter', 'GET', array( $this, 'starter' ) );
		$this->route(
			'/onboarding/starter/apply',
			'POST',
			array( $this, 'apply' ),
			array(
				'items'     => array(
					'required'          => true,
					'type'              => 'array',
					'items'             => array( 'type' => 'string' ),
					'sanitize_callback' => static fn( $v ): array => StarterPack::sanitize_ids( array_map( 'sanitize_key', (array) $v ) ),
				),
				'confirmed' => array(
					'required'          => false,
					'type'              => 'array',
					'items'             => array( 'type' => 'string' ),
					'default'           => array(),
					'sanitize_callback' => static fn( $v ): array => StarterPack::sanitize_ids( array_map( 'sanitize_key', (array) $v ) ),
				),
				'overrides' => array(
					'required' => false,
					'type'     => 'object',
					'default'  => array(),
				),
			)
		);
		$this->route( '/onboarding/starter/revert', 'POST', array( $this, 'revert' ) );
		$this->route(
			'/onboarding/login-url/confirm',
			'POST',
			array( $this, 'confirm_login_url' ),
			array(
				'force' => array(
					'required'          => false,
					'type'              => 'boolean',
					'default'           => false,
					'sanitize_callback' => 'rest_sanitize_boolean',
				),
			)
		);
		$this->route(
			'/onboarding/ca/prepare',
			'POST',
			array( $this, 'ca_prepare' ),
			array(
				'url' => array(
					'required' => false,
					'type'     => 'string',
					'default'  => '',
				),
			)
		);
		$this->route( '/onboarding/ca/status', 'GET', array( $this, 'ca_status' ) );
		$this->route(
			'/onboarding/ca/captcha',
			'POST',
			array( $this, 'ca_captcha' ),
			array(
				'enabled' => array(
					'required'          => true,
					'type'              => 'boolean',
					'sanitize_callback' => 'rest_sanitize_boolean',
				),
			)
		);
	}

	/**
	 * The Central App connection on the live module registry.
	 */
	private function central_app(): CentralApp {
		return new CentralApp( Plugin::instance()->modules );
	}

	/**
	 * POST onboarding/ca/prepare. The response carries the shared key once, for the form the browser posts.
	 */
	public function ca_prepare( WP_REST_Request $request ) {
		$result = $this->central_app()->prepare( (string) $request['url'] );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$response = $this->ok( $result );
		// The key is in the body: it must never be cached by a proxy or the browser.
		$response->header( 'Cache-Control', 'no-store' );
		return $response;
	}

	/**
	 * GET onboarding/ca/status.
	 */
	public function ca_status( WP_REST_Request $request ) {
		return $this->ok( $this->central_app()->status() );
	}

	/**
	 * POST onboarding/ca/captcha.
	 */
	public function ca_captcha( WP_REST_Request $request ) {
		$result = $this->central_app()->set_captcha( (bool) $request['enabled'] );
		return is_wp_error( $result ) ? $result : $this->ok( $result );
	}

	/**
	 * The applier on the live module registry.
	 */
	private function applier(): Applier {
		return new Applier( Plugin::instance()->modules );
	}

	/**
	 * GET onboarding/starter.
	 */
	public function starter( WP_REST_Request $request ) {
		return $this->ok( $this->applier()->describe() );
	}

	/**
	 * POST onboarding/starter/apply.
	 */
	public function apply( WP_REST_Request $request ) {
		$overrides = $request['overrides'];
		$result    = $this->applier()->apply(
			(array) $request['items'],
			is_array( $overrides ) ? $overrides : array(),
			(array) $request['confirmed']
		);
		return is_wp_error( $result ) ? $result : $this->ok( $result );
	}

	/**
	 * POST onboarding/starter/revert.
	 */
	public function revert( WP_REST_Request $request ) {
		$result = $this->applier()->revert();
		return is_wp_error( $result ) ? $result : $this->ok( $result );
	}

	/**
	 * POST onboarding/login-url/confirm.
	 */
	public function confirm_login_url( WP_REST_Request $request ) {
		$result = $this->applier()->confirm_login_url( (bool) $request['force'] );
		return is_wp_error( $result ) ? $result : $this->ok( $result );
	}
}
