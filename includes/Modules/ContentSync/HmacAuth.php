<?php
/**
 * HMAC request signing/verification for the hub <-> node channel.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\ContentSync;

use WP_Error;
use WP_REST_Request;

defined( 'ABSPATH' ) || exit;

/**
 * Shared, symmetric HMAC-SHA256 auth used by BOTH ends of the content-sync
 * channel: the hub signs outbound requests (SyncClient) and the node verifies
 * them (NodeController / SsoController). The shared secret is the node's
 * node_api_key: the node keeps it in Security::store_secret(); the hub keeps a
 * copy per-site (also as an encrypted secret).
 *
 * Signature payload (newline-joined, order significant):
 *   METHOD \n URL \n TIMESTAMP \n NONCE \n sha256(body)
 *
 * Defence in depth:
 *   - constant-time comparison (hash_equals)
 *   - +/- 300s timestamp window (rejects stale / clock-skewed replays)
 *   - per-nonce single-use guard (rejects in-window replays)
 */
final class HmacAuth {

	/** Timestamp tolerance in seconds. */
	public const TOLERANCE = 300;

	public const HEADER_SIGNATURE = 'X-Content-Sync-Signature';
	public const HEADER_TIMESTAMP = 'X-Content-Sync-Timestamp';
	public const HEADER_NONCE     = 'X-Content-Sync-Nonce';
	public const HEADER_VERSION   = 'X-Content-Sync-Version';

	/**
	 * Generate a random shared key (64 hex chars = 32 bytes).
	 */
	public static function generate_key(): string {
		return bin2hex( random_bytes( 32 ) );
	}

	/**
	 * Compute the signature for a request.
	 *
	 * @param string $method    HTTP method.
	 * @param string $url       Full request URL (scheme://host/path[?query]).
	 * @param string $body      Raw request body (empty string for GET).
	 * @param int    $timestamp Unix timestamp.
	 * @param string $nonce     Unique per-request nonce.
	 * @param string $secret    Shared secret.
	 */
	public static function sign( string $method, string $url, string $body, int $timestamp, string $nonce, string $secret ): string {
		$payload = strtoupper( $method ) . "\n"
			. $url . "\n"
			. $timestamp . "\n"
			. $nonce . "\n"
			. hash( 'sha256', $body );

		return hash_hmac( 'sha256', $payload, $secret );
	}

	/**
	 * Verify an incoming REST request against a shared secret.
	 *
	 * Reconstructs the URL from the actual HTTP request (not rest_url(), which
	 * can resolve to a different hostname behind proxies) so it matches what the
	 * hub signed. For multipart uploads the body is the raw uploaded file bytes,
	 * matching SyncClient::upload_file().
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @param string          $secret  Shared secret (node_api_key).
	 * @return true|WP_Error True when authentic, WP_Error otherwise.
	 */
	public static function verify_request( WP_REST_Request $request, string $secret ) {
		$signature = (string) $request->get_header( self::HEADER_SIGNATURE );
		$timestamp = (string) $request->get_header( self::HEADER_TIMESTAMP );
		$nonce     = (string) $request->get_header( self::HEADER_NONCE );

		if ( '' === $signature || '' === $timestamp || '' === $nonce ) {
			return new WP_Error( 'uxstudio_cs_missing_auth', __( 'Missing authentication headers.', 'ux-studio' ), array( 'status' => 401 ) );
		}
		if ( '' === $secret ) {
			return new WP_Error( 'uxstudio_cs_no_key', __( 'This node has no API key configured.', 'ux-studio' ), array( 'status' => 403 ) );
		}
		if ( ! ctype_xdigit( $signature ) || ! ctype_alnum( $nonce ) ) {
			return new WP_Error( 'uxstudio_cs_bad_auth', __( 'Malformed authentication headers.', 'ux-studio' ), array( 'status' => 401 ) );
		}

		// 1. Timestamp window.
		$ts = (int) $timestamp;
		if ( abs( time() - $ts ) > self::TOLERANCE ) {
			return new WP_Error( 'uxstudio_cs_timestamp', __( 'Request timestamp outside the allowed window.', 'ux-studio' ), array( 'status' => 401 ) );
		}

		// 2. Reconstruct the exact URL the hub signed.
		$scheme = is_ssl() ? 'https' : 'http';
		$host   = isset( $_SERVER['HTTP_HOST'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) ) : ( isset( $_SERVER['SERVER_NAME'] ) ? sanitize_text_field( wp_unslash( $_SERVER['SERVER_NAME'] ) ) : 'localhost' );
		$uri    = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
		$path   = (string) strtok( $uri, '?' );
		$url    = $scheme . '://' . $host . $path;

		$query = $request->get_query_params();
		if ( ! empty( $query ) ) {
			$url .= '?' . http_build_query( $query );
		}

		// 3. Body: raw uploaded file for multipart, JSON body otherwise.
		$files = $request->get_file_params();
		if ( ! empty( $files['file']['tmp_name'] ) && @is_readable( $files['file']['tmp_name'] ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			$body = (string) file_get_contents( $files['file']['tmp_name'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		} else {
			$body = (string) $request->get_body();
		}

		// 4. Constant-time signature comparison.
		$expected = self::sign( $request->get_method(), $url, $body, $ts, $nonce, $secret );
		if ( ! hash_equals( $expected, $signature ) ) {
			return new WP_Error( 'uxstudio_cs_signature', __( 'Invalid request signature.', 'ux-studio' ), array( 'status' => 401 ) );
		}

		// 5. Anti-replay: this nonce must not have been seen inside the window.
		$replay_key = 'uxstudio_cs_nonce_' . md5( $nonce );
		if ( false !== get_transient( $replay_key ) ) {
			return new WP_Error( 'uxstudio_cs_replay', __( 'Request nonce already used.', 'ux-studio' ), array( 'status' => 401 ) );
		}
		set_transient( $replay_key, 1, self::TOLERANCE * 2 );

		return true;
	}
}
