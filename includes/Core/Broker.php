<?php
/**
 * Shared HMAC-signed HTTP client for talking to the central app.
 *
 * @package UxStudio
 */

namespace UxStudio\Core;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Every module that talks to the central app (content-sync's own config
 * consumers: instagram-feed, review-aggregator) routes the actual signed
 * HTTP call through here. The HMAC secret and URL always come from the
 * content-sync module's settings - this class never reads or stores them
 * itself, it only signs/verifies with whatever secret it is given.
 *
 * Same scheme as Modules\ThirdPartyLogin\RestController::callback():
 * hash_hmac('sha256', ...) + hash_equals() verification, a 300s timestamp
 * window and a transient-based anti-replay guard - applied here to both the
 * outbound request (signed with our secret) and the inbound response
 * (verified against the central app's signature).
 */
final class Broker {

	private const MAX_AGE = 300; // seconds.

	/**
	 * POST a signed JSON payload to the central app and verify the signed
	 * JSON response.
	 *
	 * @param string $central_app_url Base URL of the central app (content-sync's central_app_url setting).
	 * @param string $hmac_secret     Shared secret (content-sync's decrypted hmac_secret).
	 * @param string $endpoint        Path appended to the base URL, e.g. '/api/instagram/sync'.
	 * @param array  $payload         Request payload (module-specific).
	 * @return array<string, mixed>|WP_Error Decoded response body, or an error.
	 */
	public static function call( string $central_app_url, string $hmac_secret, string $endpoint, array $payload ) {
		if ( '' === $central_app_url || '' === $hmac_secret ) {
			return new WP_Error(
				'uxstudio_content_sync_not_configured',
				__( 'Content Sync is not configured.', 'ux-studio' ),
				array( 'status' => 424 )
			);
		}

		$timestamp       = time();
		$payload['timestamp'] = $timestamp;
		ksort( $payload );
		$body      = (string) wp_json_encode( $payload );
		$signature = hash_hmac( 'sha256', $body, $hmac_secret );

		$response = wp_remote_post(
			untrailingslashit( esc_url_raw( $central_app_url ) ) . $endpoint,
			array(
				'timeout' => 15,
				'headers' => array(
					'Content-Type'          => 'application/json',
					'X-UxStudio-Signature'  => $signature,
					'X-UxStudio-Timestamp'  => (string) $timestamp,
					'X-UxStudio-Site'       => home_url( '/' ),
				),
				'body'    => $body,
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 300 ) {
			return new WP_Error(
				'uxstudio_broker_error',
				sprintf(
					/* translators: %d: HTTP status code */
					__( 'Central app returned HTTP %d.', 'ux-studio' ),
					$code
				),
				array( 'status' => 502 )
			);
		}

		$raw_body        = (string) wp_remote_retrieve_body( $response );
		$resp_signature  = (string) wp_remote_retrieve_header( $response, 'x-uxstudio-signature' );
		$resp_timestamp  = absint( wp_remote_retrieve_header( $response, 'x-uxstudio-timestamp' ) );
		$generic_error   = new WP_Error(
			'uxstudio_broker_invalid_response',
			__( 'Central app response failed signature verification.', 'ux-studio' ),
			array( 'status' => 502 )
		);

		if ( '' === $resp_signature || ! ctype_xdigit( $resp_signature ) || 0 === $resp_timestamp ) {
			return $generic_error;
		}

		if ( abs( time() - $resp_timestamp ) > self::MAX_AGE ) {
			return $generic_error;
		}

		$expected = hash_hmac( 'sha256', $raw_body . '|' . $resp_timestamp, $hmac_secret );
		if ( ! hash_equals( $expected, $resp_signature ) ) {
			return $generic_error;
		}

		// Anti-replay: this exact signature must not have been used before.
		$replay_key = 'uxstudio_broker_used_' . md5( $resp_signature );
		if ( false !== get_transient( $replay_key ) ) {
			return $generic_error;
		}
		set_transient( $replay_key, 1, self::MAX_AGE + 10 );

		$decoded = json_decode( $raw_body, true );
		return is_array( $decoded ) ? $decoded : array();
	}
}
