<?php
/**
 * Analytics REST controller.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\Analytics;

use UxStudio\Rest\Controller;
use WP_Error;
use WP_REST_Request;

defined( 'ABSPATH' ) || exit;

/**
 * GET  uxstudio/v1/analytics/summary?period=... - visitor stats (cards/chart/rankings)
 * GET  uxstudio/v1/analytics/geoip               - GeoIP database status
 * POST uxstudio/v1/analytics/geoip               - download/update or disable GeoIP
 * POST uxstudio/v1/analytics/hit                 - public, cookieless beacon
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
	 * Register routes. The /hit collector must be public (no consent needed),
	 * so it's registered directly instead of through Controller::route(),
	 * which always requires a capability.
	 */
	public function register_routes(): void {
		register_rest_route(
			self::NS,
			'/analytics/hit',
			array(
				'methods'             => 'POST',
				'permission_callback' => '__return_true',
				'callback'            => array( $this->module, 'hit' ),
			)
		);

		$this->route(
			'/analytics/summary',
			'GET',
			array( $this, 'summary' ),
			array(
				'period' => array(
					'required'          => false,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
				),
			),
			$this->module->capability()
		);

		$this->route( '/analytics/geoip', 'GET', array( $this, 'geoip_get' ), array(), $this->module->capability() );
		$this->route(
			'/analytics/geoip',
			'POST',
			array( $this, 'geoip_post' ),
			array(
				'action' => array(
					'required'          => false,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
				),
			),
			$this->module->capability()
		);
	}

	/**
	 * Visitor summary.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function summary( WP_REST_Request $request ) {
		return $this->ok( $this->module->summary( $request ) );
	}

	/**
	 * GeoIP database status.
	 */
	public function geoip_get() {
		return $this->ok( $this->module->geoip_get() );
	}

	/**
	 * Download/update or disable the GeoIP database.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function geoip_post( WP_REST_Request $request ) {
		$action = (string) $request->get_param( 'action' );
		$result = $this->module->geoip_set( $action );
		if ( ! $result['ok'] ) {
			return new WP_Error( 'uxstudio_geoip_failed', $result['message'], array( 'status' => 500 ) );
		}
		return $this->ok( array_merge( $this->module->geoip_get(), array( 'message' => $result['message'] ) ) );
	}
}
