<?php
/**
 * Which REST route (and method) a request really runs.
 *
 * @package UxStudio
 */

namespace UxStudio\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Security decisions about a REST route must use the route WordPress dispatches, not a guess
 * from `$_GET['rest_route']` or the URL path: WP::parse_request() prefers `$_POST['rest_route']`
 * over the query string and the permalink, and the REST server honours `?_method=` and
 * `X-HTTP-Method-Override`. A guess that differs from what is dispatched is a bypass
 * (POST /wp-json/<allowed route> with body `rest_route=/wp/v2/users`).
 *
 * Sources, from the most to the least authoritative:
 *  1. the served WP_REST_Request (known from `rest_pre_dispatch` on), see served();
 *  2. inside a REST request: the `rest_route` query var rest_api_loaded() hands to the server;
 *  3. before that: requested(), the same precedence as WP::parse_request() applied by hand.
 */
final class RestRoute {

	/** The top-level request is about to be dispatched (rest_authentication_errors has run). */
	private static bool $armed = false;

	/** @var \WP_REST_Request|null The top-level request the REST server dispatches. */
	private static $served = null;

	private static bool $tracking = false;

	/** The served request has been dispatched (its rest_post_dispatch ran): embeds come next. */
	private static bool $dispatched = false;

	/** @var string|null Route an authentication method vouched for while authenticating, see vouch(). */
	private static $vouched_for = null;

	/** @var string|null The vouched route, carried from arm() to capture(). */
	private static $armed_vouch = null;

	/** @var string|null The vouched route of the served request ('' = none). */
	private static $served_vouch = null;

	/**
	 * Start remembering the served request (idempotent).
	 */
	public static function track(): void {
		if ( self::$tracking ) {
			return;
		}
		self::$tracking = true;
		// serve_request() runs check_authentication() right before dispatch() of the request it
		// serves, so the first rest_pre_dispatch after the authentication filters is that request.
		// Internal sub-requests (rest_do_request from an ability) come later and are not it.
		add_filter( 'rest_authentication_errors', array( self::class, 'arm' ), PHP_INT_MAX );
		add_filter( 'rest_pre_dispatch', array( self::class, 'capture' ), PHP_INT_MIN, 3 );
		// serve_request() runs rest_post_dispatch for the served request right before
		// response_to_data(), which dispatches the `_embed` links as new requests.
		add_filter( 'rest_post_dispatch', array( self::class, 'mark_dispatched' ), PHP_INT_MAX, 3 );
	}

	/**
	 * Called from a rest_authentication_errors filter: the caller of the request about to be
	 * served is trusted (a logged-in user, or another authentication method answered `true`).
	 * The trust is bound to the route current() names now, i.e. the route serve_request() hands
	 * to dispatch(), and to the WP_REST_Request captured next, see served_vouched().
	 *
	 * @param bool $trusted Trusted or not (false clears an earlier vouch).
	 */
	public static function vouch( bool $trusted ): void {
		self::$vouched_for = $trusted ? strtolower( self::current() ) : null;
	}

	/**
	 * Did an authentication method vouch for the served request? Only for the very
	 * WP_REST_Request serve_request() dispatches, and only while it still runs the route the
	 * trust was given for.
	 */
	public static function served_vouched(): bool {
		return null !== self::$served && null !== self::$served_vouch && '' !== self::$served_vouch
			&& self::$served_vouch === strtolower( self::normalize( (string) self::$served->get_route() ) );
	}

	/**
	 * Has the served request been dispatched? Requests dispatched after that are its `_embed`
	 * links (WP_REST_Server::embed_links()) or come from late output filters.
	 */
	public static function served_dispatched(): bool {
		return self::$dispatched;
	}

	/**
	 * `rest_post_dispatch`, last: the served request has its response.
	 *
	 * @param mixed $response Response.
	 * @param mixed $server   REST server.
	 * @param mixed $request  Request.
	 * @return mixed Unchanged.
	 */
	public static function mark_dispatched( $response, $server = null, $request = null ) {
		if ( null !== self::$served && self::$served === $request ) {
			self::$dispatched = true;
		}
		return $response;
	}

	/**
	 * `rest_authentication_errors`, last: the next dispatch is the served request.
	 *
	 * @param mixed $result Authentication result so far.
	 * @return mixed Unchanged.
	 */
	public static function arm( $result ) {
		self::$armed       = ! ( function_exists( 'is_wp_error' ) && is_wp_error( $result ) );
		self::$armed_vouch = self::$armed ? self::$vouched_for : null;
		self::$vouched_for = null;
		return $result;
	}

	/**
	 * `rest_pre_dispatch`, first: remember the served request.
	 *
	 * @param mixed $result  Short-circuit value.
	 * @param mixed $server  REST server.
	 * @param mixed $request Request.
	 * @return mixed Unchanged.
	 */
	public static function capture( $result, $server = null, $request = null ) {
		if ( self::$armed && $request instanceof \WP_REST_Request ) {
			self::$armed        = false;
			self::$served       = $request;
			self::$served_vouch = self::$armed_vouch;
			self::$armed_vouch  = null;
			self::$dispatched   = false;
		}
		return $result;
	}

	/**
	 * Is this the top-level request the REST server serves (not an internal sub-request)?
	 *
	 * @param mixed $request Request passed to a REST filter.
	 */
	public static function is_served( $request ): bool {
		return null !== self::$served && self::$served === $request;
	}

	/**
	 * The served request, once dispatch has started.
	 *
	 * @return \WP_REST_Request|null
	 */
	public static function served() {
		return self::$served;
	}

	/**
	 * Is this a REST API request (constant defined by rest_api_loaded())?
	 */
	public static function is_rest(): bool {
		return defined( 'REST_REQUEST' ) && REST_REQUEST;
	}

	/**
	 * The route this request runs, leading slash, no trailing slash ('' = none / ambiguous).
	 */
	public static function current(): string {
		if ( null !== self::$served ) {
			return self::normalize( (string) self::$served->get_route() );
		}
		if ( self::is_rest() && isset( $GLOBALS['wp'] ) && is_object( $GLOBALS['wp'] ) && isset( $GLOBALS['wp']->query_vars['rest_route'] ) ) {
			$route = $GLOBALS['wp']->query_vars['rest_route'];
			return is_string( $route ) ? self::normalize( $route ) : '';
		}
		return self::requested();
	}

	/**
	 * The route of the raw request, parsed the way WP::parse_request() resolves `rest_route`:
	 * POST body, then query string, then the permalink. Both GET and POST with different values
	 * is refused by WordPress (400) and returns '' here, as does a non-string value.
	 *
	 * @param array<string, mixed>|null $get    $_GET (test seam).
	 * @param array<string, mixed>|null $post   $_POST (test seam).
	 * @param array<string, mixed>|null $server $_SERVER (test seam).
	 */
	public static function requested( ?array $get = null, ?array $post = null, ?array $server = null ): string {
		// phpcs:disable WordPress.Security.NonceVerification, WordPress.Security.ValidatedSanitizedInput -- routing lookup, compared only.
		$get    = $get ?? $_GET;
		$post   = $post ?? $_POST;
		$server = $server ?? $_SERVER;
		// phpcs:enable

		if ( isset( $post['rest_route'] ) ) {
			if ( isset( $get['rest_route'] ) && $get['rest_route'] !== $post['rest_route'] ) {
				return '';
			}
			return is_string( $post['rest_route'] ) ? self::normalize( $post['rest_route'] ) : '';
		}
		if ( isset( $get['rest_route'] ) ) {
			return is_string( $get['rest_route'] ) ? self::normalize( $get['rest_route'] ) : '';
		}

		$path   = (string) wp_parse_url( (string) ( $server['REQUEST_URI'] ?? '' ), PHP_URL_PATH );
		$prefix = '/' . trim( rest_get_url_prefix(), '/' );
		$pos    = strpos( $path . '/', $prefix . '/' );
		if ( false === $pos ) {
			return '';
		}
		return self::normalize( (string) substr( $path, $pos + strlen( $prefix ) ) );
	}

	/**
	 * HTTP method the REST server will dispatch with (`?_method=`, then X-HTTP-Method-Override).
	 *
	 * @param array<string, mixed>|null $get    $_GET (test seam).
	 * @param array<string, mixed>|null $server $_SERVER (test seam).
	 */
	public static function method( ?array $get = null, ?array $server = null ): string {
		if ( null !== self::$served ) {
			return strtoupper( (string) self::$served->get_method() );
		}
		// phpcs:disable WordPress.Security.NonceVerification, WordPress.Security.ValidatedSanitizedInput -- compared only.
		$get    = $get ?? $_GET;
		$server = $server ?? $_SERVER;
		// phpcs:enable
		$method = is_scalar( $server['REQUEST_METHOD'] ?? null ) ? (string) $server['REQUEST_METHOD'] : 'GET';
		if ( isset( $get['_method'] ) ) {
			$method = is_scalar( $get['_method'] ) ? (string) $get['_method'] : '';
		} elseif ( isset( $server['HTTP_X_HTTP_METHOD_OVERRIDE'] ) ) {
			$method = is_scalar( $server['HTTP_X_HTTP_METHOD_OVERRIDE'] ) ? (string) $server['HTTP_X_HTTP_METHOD_OVERRIDE'] : '';
		}
		return strtoupper( $method );
	}

	/**
	 * Forget the served request (tests).
	 */
	public static function reset(): void {
		self::$armed        = false;
		self::$served       = null;
		self::$dispatched   = false;
		self::$vouched_for  = null;
		self::$armed_vouch  = null;
		self::$served_vouch = null;
	}

	/**
	 * Leading slash, no trailing slash, '/' for the index (as rest_api_loaded() does).
	 *
	 * @param string $route Raw route.
	 */
	private static function normalize( string $route ): string {
		return '/' . trim( $route, '/' );
	}
}
