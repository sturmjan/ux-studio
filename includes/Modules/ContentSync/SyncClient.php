<?php
/**
 * HMAC-signed HTTP client the hub uses to talk to a node.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\ContentSync;

defined( 'ABSPATH' ) || exit;

/**
 * Wraps wp_remote_request() and signs every call with HmacAuth using the
 * node's shared key. Mirrors the legacy SyncClient but targets the studio
 * node namespace (uxstudio/v1/content-sync/node).
 */
final class SyncClient {

	private string $base_url;
	private string $secret;
	private int $timeout;

	/**
	 * @param string $site_url Node base URL (e.g. https://node.example.com).
	 * @param string $secret   Shared HMAC key (the node's node_api_key).
	 * @param int    $timeout  Request timeout in seconds.
	 */
	public function __construct( string $site_url, string $secret, int $timeout = 30 ) {
		$this->base_url = untrailingslashit( $site_url ) . '/wp-json/uxstudio/v1/content-sync/node';
		$this->secret   = $secret;
		$this->timeout  = $timeout;
	}

	/**
	 * Signed GET.
	 *
	 * @param string $endpoint Path under the node base.
	 * @param array  $params   Query parameters.
	 * @return array<string, mixed>
	 */
	public function get( string $endpoint, array $params = array() ): array {
		$url = $this->base_url . $endpoint;
		if ( ! empty( $params ) ) {
			$url .= '?' . http_build_query( $params );
		}
		return $this->request( 'GET', $url, '' );
	}

	/**
	 * Signed POST.
	 *
	 * @param string $endpoint Path under the node base.
	 * @param array  $data     JSON body.
	 * @return array<string, mixed>
	 */
	public function post( string $endpoint, array $data = array() ): array {
		return $this->request( 'POST', $this->base_url . $endpoint, (string) wp_json_encode( $data ) );
	}

	/**
	 * Signed PUT.
	 *
	 * @param string $endpoint Path under the node base.
	 * @param array  $data     JSON body.
	 * @return array<string, mixed>
	 */
	public function put( string $endpoint, array $data = array() ): array {
		return $this->request( 'PUT', $this->base_url . $endpoint, (string) wp_json_encode( $data ) );
	}

	/**
	 * Signed DELETE.
	 *
	 * @param string $endpoint Path under the node base.
	 * @return array<string, mixed>
	 */
	public function delete( string $endpoint ): array {
		return $this->request( 'DELETE', $this->base_url . $endpoint, '' );
	}

	/**
	 * Test the connection to the node.
	 *
	 * @return array<string, mixed>
	 */
	public function ping(): array {
		return $this->get( '/ping' );
	}

	/**
	 * Upload a local file to the node via multipart POST. The signature is
	 * computed over the raw file bytes (matching HmacAuth::verify_request()).
	 *
	 * @param string $endpoint  Path under the node base.
	 * @param string $file_path Absolute local path.
	 * @param string $field     Multipart field name.
	 * @return array<string, mixed>
	 */
	public function upload_file( string $endpoint, string $file_path, string $field = 'file' ): array {
		$url = $this->base_url . $endpoint;

		if ( ! is_readable( $file_path ) ) {
			return array(
				'success' => false,
				'code'    => 0,
				'data'    => null,
				'error'   => __( 'Local file is not readable.', 'ux-studio' ),
			);
		}

		$file_body = (string) file_get_contents( $file_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$timestamp = time();
		$nonce     = wp_generate_password( 24, false );
		$signature = HmacAuth::sign( 'POST', $url, $file_body, $timestamp, $nonce, $this->secret );

		$boundary = wp_generate_password( 24, false );
		$filename = basename( $file_path );
		$mime     = wp_check_filetype( $filename );
		$type     = $mime['type'] ? $mime['type'] : 'application/octet-stream';

		$body  = '--' . $boundary . "\r\n";
		$body .= 'Content-Disposition: form-data; name="' . $field . '"; filename="' . $filename . '"' . "\r\n";
		$body .= 'Content-Type: ' . $type . "\r\n\r\n";
		$body .= $file_body . "\r\n";
		$body .= '--' . $boundary . "--\r\n";

		$response = wp_remote_post(
			$url,
			array(
				'timeout' => max( $this->timeout, 60 ),
				'headers' => array(
					'Content-Type'                => 'multipart/form-data; boundary=' . $boundary,
					HmacAuth::HEADER_SIGNATURE    => $signature,
					HmacAuth::HEADER_TIMESTAMP    => (string) $timestamp,
					HmacAuth::HEADER_NONCE        => $nonce,
					HmacAuth::HEADER_VERSION      => '1',
				),
				'body'    => $body,
			)
		);

		return $this->parse( $response );
	}

	/**
	 * Send a signed request.
	 *
	 * @param string $method HTTP method.
	 * @param string $url    Full URL.
	 * @param string $body   Raw body.
	 * @return array<string, mixed>
	 */
	private function request( string $method, string $url, string $body ): array {
		$timestamp = time();
		$nonce     = wp_generate_password( 24, false );
		$signature = HmacAuth::sign( $method, $url, $body, $timestamp, $nonce, $this->secret );

		$args = array(
			'method'  => $method,
			'timeout' => $this->timeout,
			'headers' => array(
				'Content-Type'             => 'application/json',
				HmacAuth::HEADER_SIGNATURE => $signature,
				HmacAuth::HEADER_TIMESTAMP => (string) $timestamp,
				HmacAuth::HEADER_NONCE     => $nonce,
				HmacAuth::HEADER_VERSION   => '1',
			),
		);

		if ( 'GET' !== $method && '' !== $body ) {
			$args['body'] = $body;
		}

		return $this->parse( wp_remote_request( $url, $args ) );
	}

	/**
	 * Normalise a WP HTTP response into a uniform array.
	 *
	 * @param array|\WP_Error $response Response.
	 * @return array<string, mixed>
	 */
	private function parse( $response ): array {
		if ( is_wp_error( $response ) ) {
			return array(
				'success' => false,
				'code'    => 0,
				'data'    => null,
				'error'   => $response->get_error_message(),
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		return array(
			'success' => $code >= 200 && $code < 300,
			'code'    => $code,
			'data'    => $data,
			'error'   => ( $code >= 400 )
				? ( is_array( $data ) && isset( $data['message'] ) ? (string) $data['message'] : 'HTTP ' . $code )
				: null,
		);
	}
}
