<?php
/**
 * Signed HTTP client for the central app's ticket API.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\ServiceRequests;

use UxStudio\Core\Security;
use UxStudio\Core\Settings;
use UxStudio\Modules\ContentSync\HmacAuth;
use UxStudio\Modules\ContentSync\Module as ContentSyncModule;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Talks to `?page=ticket_api` on the central app.
 *
 * Deliberately reuses the EXISTING hub<->node credentials and signature
 * scheme rather than introducing a third channel:
 *   - secret: the node_api_key (ContentSyncModule::SECRET_NODE_KEY), the same
 *     shared key the central app already uses to call this site,
 *   - signature: HmacAuth::sign(), i.e.
 *     METHOD \n URL \n TIMESTAMP \n NONCE \n sha256(body).
 *
 * The central app verifies with HmacAuth::signWithNonce() and burns the
 * signature in its `hmac_nonces` table, so a captured request cannot be
 * replayed. Nothing here needs new pairing or a new key in settings.
 *
 * The URL that gets signed must be byte-identical to what the central app
 * reconstructs from its own $_SERVER['REQUEST_URI'] - that is why the query
 * string is built once, into a string, and both signed and sent as-is.
 */
final class CentralClient {

	private const TIMEOUT = 20;

	/**
	 * Is the channel usable at all? (central URL + node key both present)
	 */
	public static function is_configured(): bool {
		list( $url, $secret ) = self::credentials();
		return '' !== $url && '' !== $secret;
	}

	/**
	 * Base URL of the central app, or '' when unset.
	 */
	public static function central_url(): string {
		return self::credentials()[0];
	}

	/**
	 * Signed call to the ticket API.
	 *
	 * @param string $action HTTP-level action (`create`, `message`, `list`, `detail`, `ping`).
	 * @param string $method HTTP method.
	 * @param array  $body   JSON body for writes (ignored for GET).
	 * @param array  $query  Extra query parameters.
	 * @return array<string, mixed>|WP_Error Decoded response, or an error.
	 */
	public static function call( string $action, string $method, array $body = array(), array $query = array() ) {
		list( $central_url, $secret ) = self::credentials();

		if ( '' === $central_url || '' === $secret ) {
			return new WP_Error(
				'uxstudio_tickets_not_configured',
				__( 'The central app URL or the node API key is missing in Content Sync settings.', 'ux-studio' ),
				array( 'status' => 424 )
			);
		}

		$method = strtoupper( $method );
		$query  = array_merge(
			array(
				'page'     => 'ticket_api',
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
				'Content-Type'                    => 'application/json',
				HmacAuth::HEADER_SIGNATURE        => $signature,
				HmacAuth::HEADER_TIMESTAMP        => (string) $timestamp,
				HmacAuth::HEADER_NONCE            => $nonce,
			),
		);
		if ( 'GET' !== $method ) {
			$args['body'] = $raw;
		}

		$response = wp_remote_request( $url, $args );
		if ( is_wp_error( $response ) ) {
			return $response;
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
			// 4xx other than 429 is a permanent problem with THIS request
			// (bad payload, unknown site, wrong key). Retrying it on a timer
			// would just burn the queue forever, so the caller is told to stop.
			$permanent = $code >= 400 && $code < 500 && 429 !== $code;

			return new WP_Error(
				'uxstudio_tickets_http',
				$message,
				array(
					'status'    => $code,
					'permanent' => $permanent,
				)
			);
		}

		return $decoded;
	}

	/**
	 * Upload an attachment. The signature covers the RAW FILE BYTES, not the
	 * multipart body - PHP consumes the body for multipart requests, so
	 * php://input is empty on the receiving end. Same convention as the
	 * opposite direction (SyncClient::upload_file()).
	 *
	 * @param string $external_id Local request id as known to the central app.
	 * @param string $path        Absolute path of the file to send.
	 * @param string $filename    Name to show in the ticket.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function upload( string $external_id, string $path, string $filename ) {
		list( $central_url, $secret ) = self::credentials();
		if ( '' === $central_url || '' === $secret ) {
			return new WP_Error( 'uxstudio_tickets_not_configured', __( 'Ticket sync is not configured.', 'ux-studio' ), array( 'status' => 424 ) );
		}
		if ( ! is_readable( $path ) ) {
			return new WP_Error( 'uxstudio_tickets_file', __( 'Attachment is not readable.', 'ux-studio' ), array( 'status' => 400 ) );
		}

		$query = array(
			'page'     => 'ticket_api',
			'action'   => 'attachment',
			'site_url' => home_url( '/' ),
		);
		$url = untrailingslashit( $central_url ) . '/?' . http_build_query( $query );

		$bytes     = (string) file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$timestamp = time();
		$nonce     = bin2hex( random_bytes( 8 ) );
		$signature = HmacAuth::sign( 'POST', $url, $bytes, $timestamp, $nonce, $secret );

		$boundary = wp_generate_password( 24, false );
		$payload  = '';
		$payload .= '--' . $boundary . "\r\n";
		$payload .= 'Content-Disposition: form-data; name="external_id"' . "\r\n\r\n" . $external_id . "\r\n";
		$payload .= '--' . $boundary . "\r\n";
		$payload .= 'Content-Disposition: form-data; name="file"; filename="' . str_replace( '"', '', $filename ) . '"' . "\r\n";
		$payload .= 'Content-Type: application/octet-stream' . "\r\n\r\n";
		$payload .= $bytes . "\r\n";
		$payload .= '--' . $boundary . "--\r\n";

		$response = wp_remote_post(
			$url,
			array(
				'timeout' => 45,
				'headers' => array(
					'Content-Type'             => 'multipart/form-data; boundary=' . $boundary,
					HmacAuth::HEADER_SIGNATURE => $signature,
					HmacAuth::HEADER_TIMESTAMP => (string) $timestamp,
					HmacAuth::HEADER_NONCE     => $nonce,
				),
				'body'    => $payload,
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code    = (int) wp_remote_retrieve_response_code( $response );
		$decoded = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		$decoded = is_array( $decoded ) ? $decoded : array();

		if ( $code < 200 || $code >= 300 || empty( $decoded['success'] ) ) {
			return new WP_Error(
				'uxstudio_tickets_upload',
				(string) ( $decoded['error'] ?? __( 'Attachment upload failed.', 'ux-studio' ) ),
				array( 'status' => $code )
			);
		}

		return $decoded;
	}

	/**
	 * Central app URL + shared node key.
	 *
	 * Both live in the content-sync module's settings; this module never
	 * stores its own copy, so there is exactly one place to repoint when a
	 * site moves to another central app.
	 *
	 * @return array{0:string,1:string}
	 */
	private static function credentials(): array {
		$content_sync = new Settings( 'uxstudio_content_sync' );

		return array(
			(string) $content_sync->get( 'central_app_url', '' ),
			Security::get_secret( ContentSyncModule::SECRET_NODE_KEY ),
		);
	}
}
