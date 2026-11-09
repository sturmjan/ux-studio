<?php
/**
 * REST routes of the dynamic scan: two signed routes for the Central App and two admin routes for the module page.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\Consent;

use UxStudio\Modules\ContentSync\NodeController;
use UxStudio\Rest\Controller;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

/**
 * Signed (HMAC with the shared node key, the same check as every other Central App call, see NodeController::verify):
 *   GET  uxstudio/v1/content-sync/node/consent/scan-plan    - pages the scan should open
 *   POST uxstudio/v1/content-sync/node/consent/scan-result  - {scan_id, result}; answers with the summary of the evaluation
 *
 * Admin (manage_options):
 *   GET  uxstudio/v1/consent/scan                           - last scan, its evaluation and the state of the link
 *   POST uxstudio/v1/consent/scan/request                   - ask the Central App for a scan
 */
final class ScanRest extends Controller {

	private const NODE = '/content-sync/node/consent';

	/** Largest body the signed push may have, in bytes. The Central App stays far below (about 150 kB at most). */
	private const MAX_BODY = 600000;

	/** Registered on `rest_api_init`. */
	public static function register(): void {
		( new self() )->register_routes();
	}

	/**
	 * Register routes.
	 */
	public function register_routes(): void {
		$node   = new NodeController();
		$verify = array( $node, 'verify' );

		register_rest_route(
			self::NS,
			self::NODE . '/scan-plan',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'plan' ),
				'permission_callback' => $verify,
			)
		);
		register_rest_route(
			self::NS,
			self::NODE . '/scan-result',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'result' ),
				'permission_callback' => $verify,
			)
		);

		$this->route( '/consent/scan', 'GET', array( $this, 'get_scan' ) );
		$this->route( '/consent/scan/request', 'POST', array( $this, 'request' ) );
	}

	/**
	 * @param WP_REST_Request $request Request (unused).
	 */
	public function plan( WP_REST_Request $request ): WP_REST_Response { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		return new WP_REST_Response( Scan::plan(), 200 );
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function result( WP_REST_Request $request ) {
		if ( strlen( (string) $request->get_body() ) > self::MAX_BODY ) {
			return new WP_Error( 'uxstudio_consent_scan_big', __( 'The scan result is too large.', 'ux-studio' ), array( 'status' => 413 ) );
		}
		$summary = Scan::receive( $request->get_json_params() );
		return $summary instanceof WP_Error ? $summary : new WP_REST_Response( $summary, 200 );
	}

	/**
	 * @param WP_REST_Request $request Request (unused).
	 */
	public function get_scan( WP_REST_Request $request ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		return $this->ok( Scan::payload() );
	}

	/**
	 * @param WP_REST_Request $request Request (unused).
	 */
	public function request( WP_REST_Request $request ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		$result = Scan::request_scan();
		return $result instanceof WP_Error ? $result : $this->ok( $result + array( 'state' => Scan::payload() ) );
	}
}
