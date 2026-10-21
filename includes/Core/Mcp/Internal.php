<?php
/**
 * Calls the plugin's own REST routes from inside an ability.
 *
 * @package UxStudio
 */

namespace UxStudio\Core\Mcp;

defined( 'ABSPATH' ) || exit;

/**
 * Abilities do not re-implement what the admin UI already does: module switching (with the
 * overlap guard), settings saves (with the module's own side effects), the whole migration
 * wizard. They dispatch the same `uxstudio/v1` route, as the current user, so every
 * permission check, validation and safety rule of that route applies unchanged.
 */
final class Internal {

	/** @var callable|null Test seam: fn( string $method, string $route, array $params ): array. */
	private static $transport = null;

	/**
	 * Replace the dispatcher (tests).
	 *
	 * @param callable|null $transport Transport.
	 */
	public static function set_transport( ?callable $transport ): void {
		self::$transport = $transport;
	}

	/**
	 * Call a route of the `uxstudio/v1` namespace.
	 *
	 * @param string               $method GET or POST.
	 * @param string               $route  Route with a leading slash, e.g. `/migration/scan`.
	 * @param array<string, mixed> $params Query (GET) or JSON body (POST).
	 * @return array{data:mixed,meta:array<string, mixed>}
	 * @throws McpError When the route answers with an error.
	 */
	public static function rest( string $method, string $route, array $params = array() ): array {
		if ( self::$transport ) {
			return ( self::$transport )( $method, $route, $params );
		}
		$request = new \WP_REST_Request( $method, '/uxstudio/v1' . $route );
		if ( 'GET' === $method ) {
			$request->set_query_params( $params );
		} else {
			$request->set_header( 'content-type', 'application/json' );
			$request->set_body( (string) wp_json_encode( (object) $params ) );
			$request->set_body_params( $params );
		}
		$response = rest_do_request( $request );
		if ( $response->is_error() ) {
			$error = $response->as_error();
			$data  = (array) $error->get_error_data();
			unset( $data['status'] );
			$code = (string) $error->get_error_code();
			if ( 'rest_no_route' === $code ) {
				$code = 'unavailable';
			}
			throw new McpError( $code, $error->get_error_message(), $data );
		}
		$body = $response->get_data();
		if ( is_array( $body ) && array_key_exists( 'data', $body ) ) {
			return array(
				'data' => $body['data'],
				'meta' => (array) ( $body['meta'] ?? array() ),
			);
		}
		return array(
			'data' => $body,
			'meta' => array(),
		);
	}

	/**
	 * Same as rest(), only the payload.
	 *
	 * @param string               $method Method.
	 * @param string               $route  Route.
	 * @param array<string, mixed> $params Params.
	 * @return mixed
	 */
	public static function data( string $method, string $route, array $params = array() ) {
		return self::rest( $method, $route, $params )['data'];
	}
}
