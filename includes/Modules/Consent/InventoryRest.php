<?php
/**
 * REST routes of the consent inventory (admin) and of the cookie list of the banner (public).
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\Consent;

use UxStudio\Rest\Controller;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

/**
 * GET  uxstudio/v1/consent/inventory         - found services, queue "to be classified", decisions (manage_options)
 * POST uxstudio/v1/consent/inventory/scan    - read the sample pages and CSP reports now (manage_options)
 * POST uxstudio/v1/consent/inventory/assign  - {host, category} decide a host; category `remove` forgets it (manage_options)
 * GET  uxstudio/v1/consent/cookies?s=A|B     - PUBLIC, cookies of the named catalogue services, for the banner detail
 *
 * The public route returns only catalogue data (no site data, no visitor data), so the banner can load it when the visitor
 * opens the detail instead of carrying it in every page. It answers for labels that exist in the catalogue and ignores
 * the rest, so the answer is the same for everybody and can be cached by a CDN.
 */
final class InventoryRest extends Controller {

	/** Most labels a public call may ask for. */
	private const MAX_LABELS = 40;

	/** Registered on `rest_api_init`. */
	public static function register(): void {
		( new self() )->register_routes();
	}

	/**
	 * Register routes.
	 */
	public function register_routes(): void {
		$this->route( '/consent/inventory', 'GET', array( $this, 'get_inventory' ) );
		$this->route( '/consent/inventory/scan', 'POST', array( $this, 'scan' ) );
		$this->route(
			'/consent/inventory/assign',
			'POST',
			array( $this, 'assign' ),
			array(
				'host'     => array(
					'type'              => 'string',
					'required'          => true,
					'sanitize_callback' => static fn( $v ): string => strtolower( sanitize_text_field( (string) $v ) ),
				),
				'category' => array(
					'type'     => 'string',
					'required' => true,
					'enum'     => array( 'preferences', 'statistics', 'marketing', 'necessary', 'remove' ),
				),
			)
		);

		register_rest_route(
			self::NS,
			'/consent/cookies',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'public_cookies' ),
				'permission_callback' => '__return_true', // Catalogue data only, identical for every visitor.
				'args'                => array(
					's' => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => static fn( $v ): string => substr( sanitize_text_field( (string) $v ), 0, 2000 ),
					),
				),
			)
		);
	}

	/**
	 * What the admin page shows: found services with their cookies, the queue, decisions, source of the cookie texts.
	 *
	 * @return array<string, mixed>
	 */
	public static function payload(): array {
		$state = Inventory::state();
		$lang  = Cookies::lang( determine_locale() );
		$services = array();
		foreach ( Cookies::services_by_category( Inventory::banner_services(), $lang ) as $list ) {
			foreach ( $list as $service ) {
				$service['hosts'] = $state['services'][ $service['label'] ]['hosts'] ?? array();
				$service['found'] = isset( $state['services'][ $service['label'] ] );
				$services[]       = $service;
			}
		}
		$next = wp_next_scheduled( Inventory::CRON_HOOK );
		return array(
			'scanned_at' => $state['scanned_at'],
			'next_scan'  => $next ? (int) $next : 0,
			'pages'      => $state['pages'],
			'services'   => $services,
			'queue'      => $state['queue'],
			'decided'    => $state['decided'],
			'known'      => $state['known'],
			'ocd'        => OpenCookieDb::meta(),
		);
	}

	/**
	 * @param WP_REST_Request $request Request (unused).
	 */
	public function get_inventory( WP_REST_Request $request ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		return $this->ok( self::payload() );
	}

	/**
	 * @param WP_REST_Request $request Request (unused).
	 */
	public function scan( WP_REST_Request $request ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		Inventory::run();
		return $this->ok( self::payload() );
	}

	/**
	 * @param WP_REST_Request $request Request.
	 */
	public function assign( WP_REST_Request $request ) {
		$result = Inventory::assign( (string) $request->get_param( 'host' ), (string) $request->get_param( 'category' ) );
		if ( $result instanceof WP_Error ) {
			return $result;
		}
		return $this->ok( self::payload() );
	}

	/**
	 * Cookies of the named services for the banner detail.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function public_cookies( WP_REST_Request $request ): WP_REST_Response {
		$lang   = Cookies::lang( determine_locale() );
		$labels = array_slice( array_unique( array_filter( array_map( 'trim', explode( '|', (string) $request->get_param( 's' ) ) ) ) ), 0, self::MAX_LABELS );
		$known  = array_flip( Cookies::known_labels() );
		$out    = array();
		foreach ( $labels as $label ) {
			if ( ! isset( $known[ $label ] ) ) {
				continue;
			}
			$key  = 'uxc_ck_' . md5( $label . '|' . $lang . '|' . UXSTUDIO_VERSION );
			$item = get_transient( $key );
			if ( ! is_array( $item ) ) {
				$service = Cookies::service( $label, $lang );
				if ( null === $service ) {
					continue;
				}
				$item = array(
					'p' => $service['processor'],
					'c' => $service['country_name'],
					't' => $service['transfer_text'],
					'u' => esc_url_raw( $service['policy'] ),
					'n' => $service['note'],
					'k' => array_map(
						static fn( array $c ): array => array( $c['name'], $c['purpose'], $c['duration'] ),
						$service['cookies']
					),
				);
				set_transient( $key, $item, 12 * HOUR_IN_SECONDS );
			}
			$out[ $label ] = $item;
		}
		$response = new WP_REST_Response( $out );
		$response->header( 'Cache-Control', 'public, max-age=3600' );
		return $response;
	}
}
