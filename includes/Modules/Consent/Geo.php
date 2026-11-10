<?php
/**
 * Region check for the "show the banner only in the EU" setting.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\Consent;

use UxStudio\Rest\Controller;
use WP_REST_Request;
use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

/**
 * The page itself must stay cacheable, so it cannot depend on the visitor's country. The country comes from a tiny
 * REST endpoint that the banner script asks only when the setting is "EU only". It reads the `CF-IPCountry` header
 * that Cloudflare adds; there is no GeoIP database in the plugin. No header, an unknown country (`XX`, `T1` for Tor)
 * or an error all mean "show the banner" - the safer direction.
 */
final class Geo {

	/** EU, EEA, and the UK and Switzerland, whose cookie rules are alike. */
	private const COUNTRIES = array(
		'AT', 'BE', 'BG', 'HR', 'CY', 'CZ', 'DK', 'EE', 'FI', 'FR', 'DE', 'GR', 'HU', 'IE', 'IT', 'LV', 'LT', 'LU', 'MT', 'NL', 'PL', 'PT', 'RO', 'SK', 'SI', 'ES', 'SE',
		'IS', 'LI', 'NO',
		'GB', 'CH',
	);

	/**
	 * Country codes where the banner is shown in "EU only" mode.
	 *
	 * @return list<string>
	 */
	public static function regions(): array {
		$list = (array) apply_filters( 'uxstudio_consent_regions', self::COUNTRIES );
		return array_values( array_filter( array_map( 'strtoupper', array_map( 'strval', $list ) ), static fn( string $c ): bool => 1 === preg_match( '/^[A-Z]{2}$/', $c ) ) );
	}

	/**
	 * Should the banner be shown for this country header? True when in doubt.
	 *
	 * @param string $header Raw CF-IPCountry value (may be empty).
	 */
	public static function needs_banner( string $header ): bool {
		$code = strtoupper( trim( $header ) );
		if ( 1 !== preg_match( '/^[A-Z]{2}$/', $code ) || in_array( $code, array( 'XX', 'T1' ), true ) ) {
			return true;
		}
		return in_array( $code, self::regions(), true );
	}

	/** Register the public endpoint. */
	public static function register_routes(): void {
		register_rest_route(
			Controller::NS,
			'/consent/region',
			array(
				'methods'             => 'GET',
				'callback'            => array( self::class, 'handle' ),
				'permission_callback' => '__return_true', // Read-only, returns one boolean, no personal data.
			)
		);
	}

	/**
	 * @param WP_REST_Request $request Request (unused).
	 */
	public static function handle( WP_REST_Request $request ): WP_REST_Response { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		$header   = isset( $_SERVER['HTTP_CF_IPCOUNTRY'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['HTTP_CF_IPCOUNTRY'] ) ) : '';
		$response = new WP_REST_Response( array( 'eu' => self::needs_banner( $header ) ) );
		$response->header( 'Cache-Control', 'no-store, max-age=0' );
		$response->header( 'Vary', 'CF-IPCountry' );
		return $response;
	}
}
