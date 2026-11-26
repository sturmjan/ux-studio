<?php
/**
 * HttpClient on plain cURL. Works in WordPress and in CA.
 *
 * @package Ux1\AiCore
 */

declare(strict_types=1);

namespace Ux1\AiCore\Http;

final class CurlHttpClient implements HttpClient {

	/** How much of a streamed body is kept for error messages. */
	private const STREAM_EXCERPT_BYTES = 2000;

	/**
	 * @param string|null $ca_bundle  Path to a CA bundle (e.g. WordPress' wp-includes/certificates/ca-bundle.crt);
	 *                                null = the PHP/cURL default.
	 * @param bool        $force_ipv4 Resolve IPv4 only (CA: allowlists on the remote side would never match a
	 *                                rotating IPv6 source address).
	 * @param bool        $verify_tls Verify TLS certificates (turn off only for a local self-signed bridge).
	 */
	public function __construct(
		private readonly ?string $ca_bundle = null,
		private readonly bool $force_ipv4 = false,
		private readonly bool $verify_tls = true,
		private readonly int $connect_timeout = 10
	) {}

	public function request( string $method, string $url, array $headers = array(), ?string $body = null, int $timeout = 120 ): HttpResponse {
		$ch = curl_init( $url );
		$options = $this->base_options( $headers, $timeout ) + array(
			CURLOPT_CUSTOMREQUEST  => strtoupper( $method ),
			CURLOPT_RETURNTRANSFER => true,
		);
		if ( null !== $body ) {
			$options[ CURLOPT_POSTFIELDS ] = $body;
		}
		curl_setopt_array( $ch, $options );

		$raw = curl_exec( $ch );
		if ( false === $raw || curl_errno( $ch ) ) {
			$error = curl_error( $ch );
			curl_close( $ch );
			throw new HttpException( '' !== $error ? $error : 'HTTP request failed' );
		}
		$status = (int) curl_getinfo( $ch, CURLINFO_HTTP_CODE );
		curl_close( $ch );

		return new HttpResponse( $status, (string) $raw );
	}

	public function stream( string $url, array $headers, string $body, int $timeout, callable $on_data ): HttpResponse {
		$excerpt = '';
		$ch      = curl_init( $url );
		$options = $this->base_options( $headers, $timeout ) + array(
			CURLOPT_POST           => true,
			CURLOPT_POSTFIELDS     => $body,
			CURLOPT_RETURNTRANSFER => false,
			CURLOPT_WRITEFUNCTION  => static function ( $handle, string $data ) use ( $on_data, &$excerpt ): int {
				if ( strlen( $excerpt ) < self::STREAM_EXCERPT_BYTES ) {
					$excerpt .= substr( $data, 0, self::STREAM_EXCERPT_BYTES - strlen( $excerpt ) );
				}
				$on_data( $data );
				return strlen( $data );
			},
		);
		curl_setopt_array( $ch, $options );
		curl_exec( $ch );

		if ( curl_errno( $ch ) ) {
			$error = curl_error( $ch );
			curl_close( $ch );
			throw new HttpException( $error );
		}
		$status = (int) curl_getinfo( $ch, CURLINFO_HTTP_CODE );
		curl_close( $ch );

		return new HttpResponse( $status, $excerpt );
	}

	/**
	 * @param array<string, string> $headers Header name => value.
	 * @return array<int, mixed>
	 */
	private function base_options( array $headers, int $timeout ): array {
		$lines = array();
		foreach ( $headers as $name => $value ) {
			$lines[] = $name . ': ' . $value;
		}
		$options = array(
			CURLOPT_HTTPHEADER     => $lines,
			CURLOPT_TIMEOUT        => $timeout,
			CURLOPT_CONNECTTIMEOUT => $this->connect_timeout,
			CURLOPT_FOLLOWLOCATION => false,
			CURLOPT_SSL_VERIFYPEER => $this->verify_tls,
			CURLOPT_SSL_VERIFYHOST => $this->verify_tls ? 2 : 0,
		);
		if ( null !== $this->ca_bundle && is_file( $this->ca_bundle ) ) {
			$options[ CURLOPT_CAINFO ] = $this->ca_bundle;
		}
		if ( $this->force_ipv4 ) {
			$options[ CURLOPT_IPRESOLVE ] = CURL_IPRESOLVE_V4;
		}
		return $options;
	}
}
