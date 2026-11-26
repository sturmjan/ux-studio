<?php
/**
 * HTTP port of the shared AI core (ux1-ai-core) for WordPress: plain requests go through the WP HTTP API (proxy
 * settings, http_request_args filters and pre_http_request mocks keep working, as before the extraction); streams
 * use the core's cURL client with the WordPress CA bundle, which is what the providers did before.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\AiAssistant;

use Ux1\AiCore\Http\CurlHttpClient;
use Ux1\AiCore\Http\HttpClient;
use Ux1\AiCore\Http\HttpException;
use Ux1\AiCore\Http\HttpResponse;

defined( 'ABSPATH' ) || exit;

final class WpHttpClient implements HttpClient {

	private ?CurlHttpClient $curl = null;

	public function request( string $method, string $url, array $headers = array(), ?string $body = null, int $timeout = 120 ): HttpResponse {
		$args = array(
			'method'  => strtoupper( $method ),
			'timeout' => $timeout,
			'headers' => $headers,
		);
		if ( null !== $body ) {
			$args['body'] = $body;
		}

		$response = wp_remote_request( $url, $args );
		if ( is_wp_error( $response ) ) {
			throw new HttpException( $response->get_error_message() );
		}

		return new HttpResponse( (int) wp_remote_retrieve_response_code( $response ), (string) wp_remote_retrieve_body( $response ) );
	}

	public function stream( string $url, array $headers, string $body, int $timeout, callable $on_data ): HttpResponse {
		if ( null === $this->curl ) {
			$this->curl = new CurlHttpClient( ABSPATH . WPINC . '/certificates/ca-bundle.crt' );
		}
		return $this->curl->stream( $url, $headers, $body, $timeout, $on_data );
	}
}
