<?php
/**
 * Bearer token authentication of the MCP routes.
 *
 * @package UxStudio
 */

namespace UxStudio\Core\Mcp;

use UxStudio\Core\ClientIp;
use UxStudio\Core\RestRoute;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * A bearer token is accepted ONLY on MCP routes, so a leaked token is not a key to the site:
 *  - `/uxstudio/v1/mcp`  (our endpoint): every token, the ability scopes decide what it may do;
 *  - `/mcp/`, `/wp-abilities/` (the WordPress MCP Adapter plugin, Abilities REST): tokens with
 *    the `admin` scope only, because those routes know nothing about scopes.
 * Everywhere else (wp/v2, wc/v3, uxstudio/v1/*, wp-admin) the Authorization header is ignored.
 *
 * The route is the one the REST server dispatches (RestRoute), and only inside a REST request:
 * like Application Passwords, the token is not looked at before rest_api_loaded() (the REST
 * server clears the cached anonymous user, so the token is evaluated then). rest_pre_dispatch
 * binds the result to the served request once more, so a token can never run another route.
 */
final class Auth {

	public const KIND_ENDPOINT = 'endpoint';
	public const KIND_LEGACY   = 'legacy';

	/** Route prefixes of third party MCP transports. */
	private const LEGACY_PREFIXES = array( '/mcp/', '/wp-abilities/' );

	/** @var array<string, mixed>|null Result for this request. */
	private static ?array $context = null;

	/** Evaluation in progress: writing options/transients fires hooks that ask for the current user again. */
	private static bool $busy = false;

	/**
	 * Hook `determine_current_user` (after cookies and application passwords).
	 */
	public static function register(): void {
		RestRoute::track();
		add_filter( 'determine_current_user', array( self::class, 'authenticate' ), 20 );
		add_filter( 'rest_pre_dispatch', array( self::class, 'bind' ), -1000, 3 );
	}

	/**
	 * `determine_current_user` filter.
	 *
	 * @param int|false $user_id Result so far.
	 * @return int|false
	 */
	public static function authenticate( $user_id ) {
		if ( $user_id ) {
			return $user_id;
		}
		if ( null !== self::$context ) {
			return 'ok' === self::$context['state'] ? (int) self::$context['user_id'] : $user_id;
		}
		if ( self::$busy || ! RestRoute::is_rest() || ! Config::enabled() ) {
			return $user_id;
		}
		$kind = self::route_kind();
		if ( '' === $kind ) {
			return $user_id;
		}
		$raw = self::bearer();
		if ( '' === $raw ) {
			return $user_id;
		}

		self::$busy = true;
		try {
			self::$context = self::evaluate( $raw, $kind, ClientIp::get() );
		} finally {
			self::$busy = false;
		}
		self::$context['route_kind'] = $kind;
		if ( 'ok' === self::$context['state'] ) {
			return (int) self::$context['user_id'];
		}
		return $user_id;
	}

	/**
	 * `rest_pre_dispatch`: a token accepted for an MCP route must not run any other served route.
	 * Internal sub-requests of an ability (rest_do_request) are not the served request and pass.
	 *
	 * @param mixed $result  Short-circuit value.
	 * @param mixed $server  REST server.
	 * @param mixed $request Request.
	 * @return mixed
	 */
	public static function bind( $result, $server = null, $request = null ) {
		$ctx = self::$context;
		if ( null !== $result || ! $ctx || 'ok' !== $ctx['state'] || ! RestRoute::is_served( $request ) ) {
			return $result;
		}
		if ( '' !== (string) ( $ctx['route_kind'] ?? '' ) && self::route_kind( (string) $request->get_route() ) === $ctx['route_kind'] ) {
			return $result;
		}
		self::$context = array(
			'state'  => 'denied',
			'code'   => 'scope_route',
			'id'     => (string) ( $ctx['id'] ?? '' ),
			'label'  => (string) ( $ctx['label'] ?? '' ),
			'scopes' => array(),
			'ip'     => (string) ( $ctx['ip'] ?? '' ),
		);
		wp_set_current_user( 0 );
		return new WP_Error( 'uxstudio_mcp_route', 'An MCP token is valid on the MCP endpoint only.', array( 'status' => 401 ) );
	}

	/**
	 * What the request authenticated as (null: no bearer token on an MCP route).
	 *
	 * @return array<string, mixed>|null
	 */
	public static function context(): ?array {
		return self::$context;
	}

	/**
	 * Forget the result, or set it (tests).
	 *
	 * @param array<string, mixed>|null $context Context to use.
	 */
	public static function reset( ?array $context = null ): void {
		self::$context = $context;
	}

	/**
	 * Decide about a bearer value.
	 *
	 * @param string   $raw  Bearer value.
	 * @param string   $kind KIND_ENDPOINT | KIND_LEGACY.
	 * @param string   $ip   Client IP.
	 * @param int|null $now  Test seam.
	 * @return array<string, mixed> state ok|denied, code, id, label, scopes, user_id, retry_after.
	 */
	public static function evaluate( string $raw, string $kind, string $ip, ?int $now = null ): array {
		$denied = static fn( string $code, array $extra = array() ): array => array_merge(
			array(
				'state'  => 'denied',
				'code'   => $code,
				'id'     => '',
				'label'  => '',
				'scopes' => array(),
				'ip'     => $ip,
			),
			$extra
		);

		if ( 0 === strpos( $raw, Tokens::PREFIX ) ) {
			$check = Tokens::verify( $raw, $ip, $now );
			if ( ! $check['ok'] ) {
				return $denied( $check['code'] );
			}
			$token = $check['token'];
			$ctx   = array(
				'state'   => 'ok',
				'code'    => 'ok',
				'kind'    => 'token',
				'id'      => (string) $token['id'],
				'label'   => (string) $token['label'],
				'scopes'  => Scopes::normalize( $token['scopes'] ),
				'user_id' => (int) $token['user_id'],
				'ip'      => $ip,
			);
		} elseif ( 2 === substr_count( $raw, '.' ) ) {
			$payload = LegacyJwt::validate( $raw );
			if ( null === $payload ) {
				return $denied( 'unknown' );
			}
			$ctx = array(
				'state'   => 'ok',
				'code'    => 'ok',
				'kind'    => 'jwt',
				'id'      => 'jwt:' . substr( (string) $payload['jti'], 0, 8 ),
				'label'   => 'AI Assistant (old)',
				'scopes'  => array( Scopes::ADMIN ),
				'user_id' => (int) $payload['user_id'],
				'ip'      => $ip,
			);
		} else {
			return $denied( 'malformed' );
		}

		if ( self::KIND_LEGACY === $kind && ! in_array( Scopes::ADMIN, $ctx['scopes'], true ) ) {
			return $denied( 'scope_route', array( 'id' => $ctx['id'], 'label' => $ctx['label'] ) );
		}

		$hit = Tokens::hit( $ctx['id'], $now );
		if ( ! $hit['ok'] ) {
			return $denied(
				'rate_limited',
				array(
					'id'          => $ctx['id'],
					'label'       => $ctx['label'],
					'retry_after' => $hit['retry_after'],
				)
			);
		}
		if ( 'token' === $ctx['kind'] ) {
			Tokens::touch( $ctx['id'], $ip, $now );
		}
		return $ctx;
	}

	/**
	 * Which MCP route does this request target ('' = none)?
	 *
	 * @param string|null $route Route override (tests); null reads the request.
	 */
	public static function route_kind( ?string $route = null ): string {
		$route = $route ?? self::request_route();
		if ( '' === $route || str_contains( $route, '..' ) ) {
			return '';
		}
		$route = '/' . ltrim( rawurldecode( $route ), '/' );
		if ( str_contains( $route, '..' ) ) {
			return '';
		}
		if ( 'uxstudio/v1' . Config::ROUTE === trim( $route, '/' ) ) {
			return self::KIND_ENDPOINT;
		}
		/**
		 * Filters the REST route prefixes of third party MCP transports on which an `admin` token is accepted.
		 *
		 * @param array<int, string> $prefixes Route prefixes (leading slash).
		 */
		foreach ( (array) apply_filters( 'uxstudio_ai_assistant_mcp_route_prefixes', self::LEGACY_PREFIXES ) as $prefix ) {
			if ( str_starts_with( $route . '/', (string) $prefix ) ) {
				return self::KIND_LEGACY;
			}
		}
		return '';
	}

	/**
	 * REST route this request runs: the one the REST server dispatches (POST `rest_route` wins
	 * over GET and the permalink, a GET/POST mismatch is '' = no token), see RestRoute.
	 */
	private static function request_route(): string {
		return RestRoute::current();
	}

	/**
	 * The raw value of an `Authorization: Bearer` header ('' if absent).
	 */
	private static function bearer(): string {
		$auth = '';
		if ( ! empty( $_SERVER['HTTP_AUTHORIZATION'] ) ) {
			$auth = (string) $_SERVER['HTTP_AUTHORIZATION']; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		} elseif ( ! empty( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ) ) {
			$auth = (string) $_SERVER['REDIRECT_HTTP_AUTHORIZATION']; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		} elseif ( function_exists( 'getallheaders' ) ) {
			foreach ( (array) getallheaders() as $name => $value ) {
				if ( 0 === strcasecmp( (string) $name, 'Authorization' ) ) {
					$auth = (string) $value;
					break;
				}
			}
		}
		if ( '' === $auth || 0 !== stripos( $auth, 'Bearer ' ) ) {
			return '';
		}
		return trim( substr( $auth, 7 ) );
	}
}
