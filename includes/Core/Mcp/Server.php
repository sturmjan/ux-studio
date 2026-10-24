<?php
/**
 * Streamable HTTP transport of the MCP server (`POST uxstudio/v1/mcp`).
 *
 * @package UxStudio
 */

namespace UxStudio\Core\Mcp;

use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * JSON response mode of the Streamable HTTP transport: every POST carries one JSON-RPC
 * message (or a batch) and is answered with `application/json`; notifications get 202.
 * There is no server-initiated stream, so GET answers 405 as the specification allows.
 * No sessions, no CORS headers (MCP clients are not browsers); a request with a foreign
 * `Origin` is refused against DNS rebinding.
 *
 * Authentication happens earlier, in Auth (determine_current_user): this class only reads
 * its verdict, because the route itself is `__return_true` - that keeps anonymous requests
 * reachable for a proper 401 even when the Security module restricts the REST API.
 */
final class Server {

	/** Seconds a batch may keep working before the remaining messages are answered with an error. */
	private const BATCH_SECONDS = 20.0;

	/**
	 * Hook the route (only while the server is on).
	 */
	public static function register(): void {
		if ( ! Config::enabled() ) {
			return;
		}
		add_action( 'rest_api_init', array( self::class, 'register_routes' ) );
		add_filter( 'rest_pre_serve_request', array( self::class, 'serve_raw' ), 10, 3 );
	}

	/**
	 * Register the endpoint.
	 */
	public static function register_routes(): void {
		register_rest_route(
			'uxstudio/v1',
			Config::ROUTE,
			array(
				'methods'             => array( 'POST', 'GET', 'DELETE' ),
				'callback'            => array( self::class, 'handle' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * Request handler.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function handle( WP_REST_Request $request ): WP_REST_Response {
		$origin = self::origin_problem( $request );
		if ( null !== $origin ) {
			return self::reply( 403, Protocol::error( null, Protocol::ERR_REQUEST, $origin ) );
		}

		$denied = self::auth_problem( Auth::context() );
		if ( null !== $denied ) {
			return $denied;
		}
		$ctx = (array) Auth::context();

		if ( 'POST' !== $request->get_method() ) {
			return self::reply( 405, Protocol::error( null, Protocol::ERR_REQUEST, 'This server answers POST requests only (no server-initiated stream).' ), array( 'Allow' => 'POST' ) );
		}

		$body = json_decode( (string) $request->get_body(), true );
		if ( null === $body || ! is_array( $body ) ) {
			return self::reply( 400, Protocol::error( null, Protocol::ERR_PARSE, 'The body is not valid JSON.' ) );
		}

		$protocol = new Protocol( $ctx );
		$is_batch = array() !== $body && array_keys( $body ) === range( 0, count( $body ) - 1 );
		if ( ! $is_batch ) {
			$result = $protocol->handle( $body );
			return self::reply( $result['status'], $result['response'], self::scope_headers( $result ) );
		}

		if ( count( $body ) > 20 ) {
			return self::reply( 400, Protocol::error( null, Protocol::ERR_REQUEST, 'A batch may hold at most 20 messages.' ) );
		}
		// The request itself was counted once by Auth; every further message of the batch costs one
		// call of the per-minute budget too, and the whole batch is refused when the budget is short.
		if ( count( $body ) > 1 && ! empty( $ctx['id'] ) ) {
			$hit = Tokens::hit( (string) $ctx['id'], null, count( $body ) - 1 );
			if ( ! $hit['ok'] ) {
				return self::reply(
					429,
					Protocol::error( null, -32029, 'This batch is larger than the remaining call budget of the token. Retry in ' . $hit['retry_after'] . ' seconds or send fewer messages.', array( 'retry_after' => $hit['retry_after'] ) ),
					array( 'Retry-After' => (string) $hit['retry_after'] )
				);
			}
		}
		$out      = array();
		$deadline = microtime( true ) + self::BATCH_SECONDS;
		foreach ( $body as $message ) {
			if ( microtime( true ) > $deadline ) {
				// Time cap: the rest is answered with an error instead of holding the PHP worker.
				$id = is_array( $message ) && array_key_exists( 'id', $message ) ? $message['id'] : null;
				if ( is_array( $message ) && array_key_exists( 'id', $message ) ) {
					$out[] = Protocol::error( $id, Protocol::ERR_INTERNAL, 'The batch ran out of time; send this message again.' );
				}
				continue;
			}
			$result = $protocol->handle( $message );
			if ( null !== $result['response'] ) {
				$out[] = $result['response'];
			}
		}
		return $out ? self::reply( 200, $out ) : self::reply( 202, null );
	}

	/**
	 * Send null-bodied (202) answers without the `null` JSON literal WordPress would print.
	 *
	 * @param bool             $served  Whether already served.
	 * @param WP_REST_Response $result  Response.
	 * @param WP_REST_Request  $request Request.
	 * @return bool
	 */
	public static function serve_raw( $served, $result, $request ): bool {
		if ( $served || ! $request instanceof WP_REST_Request || '/uxstudio/v1' . Config::ROUTE !== $request->get_route() ) {
			return (bool) $served;
		}
		return $result instanceof WP_REST_Response && null === $result->get_data();
	}

	/**
	 * Headers to add to a scope refusal.
	 *
	 * @param array{response:?array<string, mixed>,status:int} $result Protocol result.
	 * @return array<string, string>
	 */
	private static function scope_headers( array $result ): array {
		if ( 403 === $result['status'] ) {
			return array( 'WWW-Authenticate' => 'Bearer realm="UX Studio MCP", error="insufficient_scope"' );
		}
		return array();
	}

	/**
	 * Why the Origin header is unacceptable (null = fine or absent).
	 *
	 * @param WP_REST_Request $request Request.
	 */
	private static function origin_problem( WP_REST_Request $request ): ?string {
		$origin = (string) $request->get_header( 'origin' );
		if ( '' === $origin ) {
			return null;
		}
		$host    = strtolower( (string) wp_parse_url( $origin, PHP_URL_HOST ) );
		$allowed = array( strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) ) );
		/**
		 * Extra hosts allowed in the Origin header of MCP requests (browser based clients).
		 *
		 * @param string[] $hosts Host names.
		 */
		$allowed = array_map( 'strtolower', (array) apply_filters( 'uxstudio_mcp_allowed_origins', $allowed ) );
		return in_array( $host, $allowed, true ) ? null : 'Requests from this Origin are not accepted.';
	}

	/**
	 * Error response for an unauthenticated request (null = authenticated).
	 *
	 * @param array<string, mixed>|null $ctx Result of Auth.
	 */
	private static function auth_problem( ?array $ctx ): ?WP_REST_Response {
		if ( $ctx && 'ok' === $ctx['state'] ) {
			return null;
		}
		$challenge = array( 'WWW-Authenticate' => 'Bearer realm="UX Studio MCP"' );
		$code      = $ctx['code'] ?? 'missing';
		switch ( $code ) {
			case 'rate_limited':
				$retry = (int) ( $ctx['retry_after'] ?? 60 );
				return self::reply(
					429,
					Protocol::error( null, -32029, 'Too many requests for this token. Retry in ' . $retry . ' seconds.', array( 'retry_after' => $retry ) ),
					array( 'Retry-After' => (string) $retry )
				);
			case 'ip_denied':
				return self::reply( 403, Protocol::error( null, Protocol::ERR_SCOPE, 'This token may not be used from this IP address.' ) );
			case 'expired':
				return self::reply( 401, Protocol::error( null, -32001, 'This token has expired.' ), $challenge + array() );
			case 'missing':
				return self::reply( 401, Protocol::error( null, -32001, 'Send the token as "Authorization: Bearer <token>".' ), $challenge );
		}
		return self::reply( 401, Protocol::error( null, -32001, 'The token is invalid or was revoked.' ), $challenge );
	}

	/**
	 * Response with the given status and extra headers.
	 *
	 * @param int                  $status  HTTP status.
	 * @param mixed                $body    JSON body (null = empty).
	 * @param array<string, string> $headers Extra headers.
	 */
	private static function reply( int $status, $body, array $headers = array() ): WP_REST_Response {
		$response = new WP_REST_Response( $body, $status );
		$response->header( 'Cache-Control', 'no-store' );
		foreach ( $headers as $name => $value ) {
			$response->header( $name, $value );
		}
		return $response;
	}
}
