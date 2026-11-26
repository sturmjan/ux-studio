<?php
/**
 * HTTP port. The core never calls WordPress or the CA app for HTTP; the host passes an implementation
 * (CurlHttpClient works everywhere; a host may wrap its own stack, e.g. wp_remote_* for proxy settings).
 *
 * @package Ux1\AiCore
 */

declare(strict_types=1);

namespace Ux1\AiCore\Http;

interface HttpClient {

	/**
	 * Sends a request and returns the whole response.
	 *
	 * @param array<string, string> $headers Header name => value.
	 * @throws HttpException When the request could not be made at all (DNS, TLS, timeout...). HTTP error statuses are
	 *                       NOT exceptions; read HttpResponse::$status.
	 */
	public function request( string $method, string $url, array $headers = array(), ?string $body = null, int $timeout = 120 ): HttpResponse;

	/**
	 * POSTs and hands every received chunk to $on_data as it arrives (chunks are NOT aligned to lines or UTF-8
	 * characters; see SseLineBuffer). The returned response carries the status and the first bytes of the body
	 * (for error messages), not the whole stream.
	 *
	 * @param array<string, string>  $headers Header name => value.
	 * @param callable(string): void $on_data Receives raw chunks.
	 * @throws HttpException When the request could not be made at all.
	 */
	public function stream( string $url, array $headers, string $body, int $timeout, callable $on_data ): HttpResponse;
}
