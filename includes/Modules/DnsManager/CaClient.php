<?php
/**
 * Signed HTTP client for the central app's DNS broker (`?page=dns_manage`).
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\DnsManager;

use UxStudio\Core\Security;
use UxStudio\Core\Settings;
use UxStudio\Modules\ContentSync\HmacAuth;
use UxStudio\Modules\ContentSync\Module as ContentSyncModule;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Same hub<->node credentials and signature as TurnstileCaProvisioner /
 * ServiceRequests\CentralClient (METHOD \n URL \n TS \n NONCE \n sha256(body)).
 *
 * The Cloudflare token lives ONLY in the central app. This site never sees it,
 * and never tells the central app which zone to touch: the zone is derived
 * there from the signed `site_url`.
 */
final class CaClient {

	private const TIMEOUT = 25;

	/**
	 * Signed call to the broker.
	 *
	 * @param string $action CA action (status, list, create, update, delete, apply, log, revert, ack).
	 * @param string $method HTTP method.
	 * @param array  $body   JSON body (ignored for GET).
	 * @param array  $query  Extra query parameters.
	 * @return array<string, mixed>|WP_Error Decoded response, or an error carrying the CA code and results.
	 */
	public static function call( string $action, string $method, array $body = array(), array $query = array() ) {
		$content_sync = new Settings( 'uxstudio_content_sync' );
		$central_url  = (string) $content_sync->get( 'central_app_url', '' );
		$secret       = Security::get_secret( ContentSyncModule::SECRET_NODE_KEY );

		if ( '' === $central_url || '' === $secret ) {
			return new WP_Error(
				'not_configured',
				__( 'The central app URL or the node API key is missing in Content Sync settings.', 'ux-studio' ),
				array( 'status' => 424 )
			);
		}

		$method = strtoupper( $method );
		$query  = array_merge(
			array(
				'page'     => 'dns_manage',
				'action'   => $action,
				'site_url' => home_url( '/' ),
			),
			$query
		);
		$url = untrailingslashit( $central_url ) . '/?' . http_build_query( $query );
		$raw = 'GET' === $method ? '' : (string) wp_json_encode( $body );

		$timestamp = time();
		$nonce     = bin2hex( random_bytes( 8 ) );
		$signature = HmacAuth::sign( $method, $url, $raw, $timestamp, $nonce, $secret );

		$args = array(
			'method'  => $method,
			'timeout' => self::TIMEOUT,
			'headers' => array(
				'Content-Type'             => 'application/json',
				HmacAuth::HEADER_SIGNATURE => $signature,
				HmacAuth::HEADER_TIMESTAMP => (string) $timestamp,
				HmacAuth::HEADER_NONCE     => $nonce,
			),
		);
		if ( 'GET' !== $method ) {
			$args['body'] = $raw;
		}

		$response = wp_remote_request( $url, $args );
		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'ca_unreachable', __( 'The central app is not reachable.', 'ux-studio' ), array( 'status' => 502 ) );
		}

		$code    = (int) wp_remote_retrieve_response_code( $response );
		$decoded = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		$decoded = is_array( $decoded ) ? $decoded : array();

		if ( $code < 200 || $code >= 300 || empty( $decoded['success'] ) ) {
			$message = (string) ( $decoded['error'] ?? '' );
			if ( '' === $message ) {
				/* translators: %d: HTTP status code */
				$message = sprintf( __( 'Central app returned HTTP %d.', 'ux-studio' ), $code );
			}
			$ca_code = (string) ( $decoded['code'] ?? '' );
			return new WP_Error(
				'' !== $ca_code ? $ca_code : 'ca_http',
				$message,
				array(
					'status'  => $code >= 400 ? $code : 502,
					'results' => $decoded['results'] ?? array(),
				)
			);
		}

		return $decoded;
	}
}
