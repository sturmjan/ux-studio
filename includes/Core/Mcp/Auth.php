<?php
/**
 * Bearer token authentication of the MCP routes.
 *
 * @package UxStudio
 */

namespace UxStudio\Core\Mcp;

use UxStudio\Core\ClientIp;

defined( 'ABSPATH' ) || exit;

/**
 * A bearer token is accepted ONLY on MCP routes, so a leaked token is not a key to the site:
 *  - `/uxstudio/v1/mcp`  (our endpoint): every token, the ability scopes decide what it may do;
 *  - `/mcp/`, `/wp-abilities/` (the WordPress MCP Adapter plugin, Abilities REST): tokens with
 *    the `admin` scope only, because those routes know nothing about scopes.
 * Everywhere else (wp/v2, wc/v3, uxstudio/v1/*, wp-admin) the Authorization header is ignored.
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
		add_filter( 'determine_current_user', array( self::class, 'authenticate' ), 20 );
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
		if ( self::$busy || ! Config::enabled() ) {
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
		if ( 'ok' === self::$context['state'] ) {
			return (int) self::$context['user_id'];
		}
		return $user_id;
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
	 * REST route of the raw request (both pretty permalinks and ?rest_route=). Parsed by hand
	 * because determine_current_user may run before WordPress has resolved the route.
	 */
	private static function request_route(): string {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput
		if ( isset( $_GET['rest_route'] ) && is_string( $_GET['rest_route'] ) ) {
			return (string) wp_unslash( $_GET['rest_route'] );
		}
		$path   = (string) wp_parse_url( (string) ( $_SERVER['REQUEST_URI'] ?? '' ), PHP_URL_PATH );
		$prefix = '/' . trim( rest_get_url_prefix(), '/' ) . '/';
		$pos    = strpos( $path, $prefix );
		if ( false === $pos ) {
			return '';
		}
		// phpcs:enable
		return substr( $path, $pos + strlen( $prefix ) - 1 );
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
