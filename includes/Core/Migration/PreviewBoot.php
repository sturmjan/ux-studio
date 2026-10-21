<?php
/**
 * Early part of the safe preview: runs from the mu-plugin, before any normal plugin loads.
 *
 * @package UxStudio
 */

namespace UxStudio\Core\Migration;

defined( 'ABSPATH' ) || exit;

/**
 * PLAN.md 26.4 / 26.10. This file is `require`d by `wp-content/mu-plugins/uxstudio-preview.php`
 * at the very start of a request, so it may only use what WordPress has loaded at that point
 * (options, hooks, formatting) and nothing of UX Studio except itself. In particular there is
 * no `wp_salt()` (pluggable.php comes later) and no current user: the token is therefore bound
 * to the SESSION of the logged-in cookie instead.
 *
 * A request is a preview request only when it carries a valid, unexpired, signed token:
 *
 *   cookie `uxstudio_preview`  (browser; scope `c`, bound to the session token of the
 *                               `wordpress_logged_in_*` cookie, so it works for that one
 *                               logged-in session only)
 *   header `X-UXS-Preview`     (loopback tests; scope `h`, created and used by the server itself)
 *
 * token = `<run>.<user id>.<expiry>.<scope>.<hmac-sha256>`, key = AUTH_KEY + AUTH_SALT (the value
 * `wp_salt('auth')` yields without the `salt` filter; computed here by hand because both sides,
 * the mu-plugin and the signing code, must get exactly the same key).
 *
 * For such a request, and only for it:
 *  - the run's source plugins disappear from `active_plugins` (and the network list),
 *  - the run's target modules are added to `uxstudio_active_modules`,
 *  - the values staged for the run overlay the module options (secrets and post meta too),
 *  - nothing is persisted: WP-Cron is off, scheduling is refused, and every database write that
 *    touches an option, meta or table of UX Studio (or `active_plugins`) is dropped.
 */
final class PreviewBoot {

	public const COOKIE        = 'uxstudio_preview';
	public const SERVER_HEADER = 'HTTP_X_UXS_PREVIEW';
	public const STATE_OPTION  = 'uxstudio_migration_preview';
	public const STAGED_OPTION = 'uxstudio_migration_staged';
	public const ACTIVE_HEADER = 'X-UXS-Preview-Active';
	public const ACTION_ENTER  = 'uxstudio_preview_enter';
	public const ACTION_EXIT   = 'uxstudio_preview_exit';

	/** Token / preview lifetime in seconds (30 minutes). */
	public const TTL = 1800;

	private static bool $resolved = false;

	/** @var array<string, mixed>|null */
	private static ?array $ctx = null;

	/** @var string[] */
	private static array $blocked = array();

	/** @var array<string, array<string, mixed>> Post meta overlay: object id => key => value. */
	private static array $meta = array();

	/**
	 * Forget the resolved request (tests).
	 */
	public static function reset(): void {
		self::$resolved = false;
		self::$ctx      = null;
		self::$blocked  = array();
		self::$meta     = array();
	}

	/* ── Token ── */

	/**
	 * Signing key: AUTH_KEY . AUTH_SALT, or the stored fallback WordPress itself would use.
	 */
	public static function salt(): string {
		$bad  = array( '', 'put your unique phrase here' );
		$key  = defined( 'AUTH_KEY' ) ? (string) AUTH_KEY : '';
		$salt = defined( 'AUTH_SALT' ) ? (string) AUTH_SALT : '';
		if ( in_array( $key, $bad, true ) || in_array( $salt, $bad, true ) ) {
			$key  = function_exists( 'get_site_option' ) ? (string) get_site_option( 'auth_key', '' ) : '';
			$salt = function_exists( 'get_site_option' ) ? (string) get_site_option( 'auth_salt', '' ) : '';
		}
		return $key . $salt;
	}

	/**
	 * HMAC over the token fields.
	 *
	 * @param string $run   Run id.
	 * @param int    $uid   User id.
	 * @param int    $exp   Expiry (unix time).
	 * @param string $scope `c` (cookie) or `h` (header).
	 * @param string $bind  Session binding (cookie tokens), empty for header tokens.
	 */
	private static function sign( string $run, int $uid, int $exp, string $scope, string $bind ): string {
		$salt = self::salt();
		if ( '' === $salt ) {
			return '';
		}
		return hash_hmac( 'sha256', implode( '|', array( 'uxs-preview', $run, $uid, $exp, $scope, $bind ) ), $salt );
	}

	/**
	 * Build a token. Empty string when the site has no usable salt (then no preview is possible).
	 *
	 * @param string $run   Run id.
	 * @param int    $uid   User id.
	 * @param int    $exp   Expiry (unix time).
	 * @param string $scope `c` or `h`.
	 * @param string $bind  Session binding, required for `c`.
	 */
	public static function make_token( string $run, int $uid, int $exp, string $scope = 'h', string $bind = '' ): string {
		if ( ! preg_match( '/^[a-z0-9]+$/', $run ) || ! in_array( $scope, array( 'c', 'h' ), true ) || ( 'c' === $scope && '' === $bind ) ) {
			return '';
		}
		$sig = self::sign( $run, $uid, $exp, $scope, 'c' === $scope ? $bind : '' );
		return '' === $sig ? '' : implode( '.', array( $run, $uid, $exp, $scope, $sig ) );
	}

	/**
	 * Validate a token.
	 *
	 * @param string $token Token.
	 * @param int    $now   Current time.
	 * @param string $bind  Session binding of THIS request (for `c` tokens).
	 * @return array{run:string,uid:int,exp:int,scope:string}|null Null when invalid, expired or tampered with.
	 */
	public static function parse_token( string $token, int $now, string $bind = '' ): ?array {
		$parts = explode( '.', $token );
		if ( 5 !== count( $parts ) ) {
			return null;
		}
		[ $run, $uid, $exp, $scope, $sig ] = $parts;
		if ( ! preg_match( '/^[a-z0-9]+$/', $run ) || ! ctype_digit( $uid ) || ! ctype_digit( $exp ) || ! in_array( $scope, array( 'c', 'h' ), true ) ) {
			return null;
		}
		if ( (int) $exp <= $now ) {
			return null;
		}
		if ( 'c' === $scope && '' === $bind ) {
			return null;
		}
		$want = self::sign( $run, (int) $uid, (int) $exp, $scope, 'c' === $scope ? $bind : '' );
		if ( '' === $want || ! hash_equals( $want, $sig ) ) {
			return null;
		}
		return array(
			'run'   => $run,
			'uid'   => (int) $uid,
			'exp'   => (int) $exp,
			'scope' => $scope,
		);
	}

	/**
	 * Binding of a cookie token to the logged-in session: hash of the user name and the session
	 * token inside the `wordpress_logged_in_*` cookie (`user|expiry|token|hmac`).
	 *
	 * @param array<string, mixed> $cookies Cookies of the request.
	 */
	public static function session_bind( array $cookies ): string {
		foreach ( $cookies as $name => $value ) {
			if ( 0 === strpos( (string) $name, 'wordpress_logged_in_' ) && is_string( $value ) ) {
				$p = explode( '|', $value );
				if ( count( $p ) >= 4 && '' !== $p[2] ) {
					return hash( 'sha256', $p[0] . '|' . $p[2] );
				}
			}
		}
		return '';
	}

	/**
	 * Token and the channel it arrived on (`h` header wins over `c` cookie).
	 *
	 * @param array<string, mixed> $server  $_SERVER.
	 * @param array<string, mixed> $cookies $_COOKIE.
	 * @return array{0:string,1:string}
	 */
	public static function request_token( array $server, array $cookies ): array {
		$header = isset( $server[ self::SERVER_HEADER ] ) && is_string( $server[ self::SERVER_HEADER ] ) ? trim( $server[ self::SERVER_HEADER ] ) : '';
		if ( '' !== $header ) {
			return array( $header, 'h' );
		}
		$cookie = isset( $cookies[ self::COOKIE ] ) && is_string( $cookies[ self::COOKIE ] ) ? trim( $cookies[ self::COOKIE ] ) : '';
		return '' !== $cookie ? array( $cookie, 'c' ) : array( '', '' );
	}

	/**
	 * Requests a browser cookie must NOT turn into preview requests: the wizard's own REST routes
	 * (they end the preview, switch, undo, and need to write) and the enter / exit actions.
	 *
	 * @param array<string, mixed> $server $_SERVER.
	 * @param array<string, mixed> $get    $_GET.
	 */
	public static function exempt_request( array $server, array $get ): bool {
		$path = self::path_of( (string) ( $server['REQUEST_URI'] ?? '' ) );
		if ( preg_match( '#/wp-json/uxstudio/v1/migration(?:/|$)#', $path ) ) {
			return true;
		}
		$route = isset( $get['rest_route'] ) && is_string( $get['rest_route'] ) ? $get['rest_route'] : '';
		if ( 0 === strpos( '/' . ltrim( $route, '/' ), '/uxstudio/v1/migration' ) && '' !== $route ) {
			return true;
		}
		$action = isset( $get['action'] ) && is_string( $get['action'] ) ? $get['action'] : '';
		return 'admin-post.php' === basename( $path ) && in_array( $action, array( self::ACTION_ENTER, self::ACTION_EXIT ), true );
	}

	/* ── Request context ── */

	/**
	 * Decide whether this request is a preview request and, if so, switch the world around.
	 * Cheap for everybody else: one array lookup. Called once from the mu-plugin.
	 *
	 * @param array<string, mixed>|null $server  Test seam ($_SERVER).
	 * @param array<string, mixed>|null $cookies Test seam ($_COOKIE).
	 * @param array<string, mixed>|null $get     Test seam ($_GET).
	 * @param int|null                  $now     Test seam (time).
	 */
	public static function init( ?array $server = null, ?array $cookies = null, ?array $get = null, ?int $now = null ): bool {
		if ( self::$resolved ) {
			return null !== self::$ctx;
		}
		self::$resolved = true;
		$server         = $server ?? $_SERVER; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$cookies        = $cookies ?? $_COOKIE; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$get            = $get ?? $_GET; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$now            = $now ?? time();

		list( $token, $channel ) = self::request_token( $server, $cookies );
		if ( '' === $token ) {
			return false;
		}
		$parsed = self::parse_token( $token, $now, 'c' === $channel ? self::session_bind( $cookies ) : '' );
		if ( ! $parsed || $parsed['scope'] !== $channel ) {
			return false;
		}
		if ( 'c' === $channel && self::exempt_request( $server, $get ) ) {
			return false;
		}

		$state = get_option( self::STATE_OPTION, array() );
		$entry = is_array( $state ) ? ( $state['runs'][ $parsed['run'] ] ?? null ) : null;
		if ( ! is_array( $entry ) || (int) ( $entry['user_id'] ?? 0 ) !== $parsed['uid'] || (int) ( $entry['expires'] ?? 0 ) <= $now ) {
			return false;
		}
		$staged_all = get_option( self::STAGED_OPTION, array() );
		$staged     = is_array( $staged_all ) && isset( $staged_all[ $parsed['run'] ] ) && is_array( $staged_all[ $parsed['run'] ] ) ? $staged_all[ $parsed['run'] ] : array();

		self::$ctx = array(
			'run'     => $parsed['run'],
			'user_id' => $parsed['uid'],
			'expires' => min( $parsed['exp'], (int) $entry['expires'] ),
			'scope'   => $channel,
			'plugins' => array_values( array_map( 'strval', (array) ( $entry['plugins'] ?? array() ) ) ),
			'modules' => array_values( array_map( 'strval', (array) ( $entry['modules'] ?? array() ) ) ),
			'staged'  => $staged,
		);
		self::hook();
		return true;
	}

	/**
	 * Whether this request is a preview request.
	 */
	public static function is_active(): bool {
		return null !== self::$ctx;
	}

	/**
	 * Context of the preview request (run, user, expiry ...), null outside a preview.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function context(): ?array {
		return self::$ctx;
	}

	/**
	 * Write statements the preview refused in this request (for diagnostics and tests).
	 *
	 * @return string[]
	 */
	public static function blocked(): array {
		return self::$blocked;
	}

	/**
	 * Ids of data staged for the run, by kind (`redirect`, `snippet` ...).
	 *
	 * @param string $kind Kind a source registered.
	 * @return string[]
	 */
	public static function ids( string $kind ): array {
		$out = array();
		foreach ( (array) ( self::$ctx['staged']['activations'] ?? array() ) as $row ) {
			if ( is_array( $row ) && ( $row['kind'] ?? '' ) === $kind ) {
				foreach ( (array) ( $row['ids'] ?? array() ) as $id ) {
					$out[] = (string) $id;
				}
			}
		}
		return array_values( array_unique( $out ) );
	}

	/* ── Filters of a preview request ── */

	/**
	 * Register everything a preview request changes.
	 */
	private static function hook(): void {
		if ( ! defined( 'UXSTUDIO_PREVIEW' ) ) {
			define( 'UXSTUDIO_PREVIEW', true );
		}
		// No page cache, no WP-Cron spawn: a preview request must neither read nor feed either.
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
		}
		if ( ! defined( 'DISABLE_WP_CRON' ) ) {
			define( 'DISABLE_WP_CRON', true );
		}
		if ( function_exists( 'headers_sent' ) && ! headers_sent() ) {
			header( self::ACTIVE_HEADER . ': ' . self::$ctx['run'] );
			header( 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0' );
			header( 'X-Robots-Tag: noindex, nofollow' );
		}

		add_filter( 'option_active_plugins', array( self::class, 'filter_active_plugins' ) );
		add_filter( 'site_option_active_sitewide_plugins', array( self::class, 'filter_network_plugins' ) );
		add_filter( 'option_uxstudio_active_modules', array( self::class, 'filter_modules' ) );
		add_filter( 'default_option_uxstudio_active_modules', array( self::class, 'filter_modules' ) );

		foreach ( (array) ( self::$ctx['staged']['settings'] ?? array() ) as $module => $values ) {
			$option = 'uxstudio_' . str_replace( '-', '_', (string) $module );
			$values = (array) $values;
			$merge  = static function ( $value ) use ( $values ) {
				return array_merge( is_array( $value ) ? $value : array(), $values );
			};
			add_filter( 'option_' . $option, $merge );
			add_filter( 'default_option_' . $option, $merge );
		}
		foreach ( (array) ( self::$ctx['staged']['secrets'] ?? array() ) as $option => $secret ) {
			$sealed = (string) ( $secret['sealed'] ?? '' );
			if ( '' !== $sealed ) {
				add_filter(
					'pre_option_' . $option,
					static function () use ( $sealed ) {
						return $sealed;
					}
				);
			}
		}
		self::load_meta_overlay();

		// Persistence guards. Scheduling is refused outright, DB writes of ours are dropped.
		foreach ( array( 'pre_schedule_event', 'pre_reschedule_event', 'pre_unschedule_event', 'pre_clear_scheduled_hook', 'pre_unschedule_hook' ) as $hook ) {
			add_filter( $hook, array( self::class, 'refuse' ) );
		}
		add_filter( 'query', array( self::class, 'guard_query' ), 1 );
	}

	/**
	 * `pre_*` filter value that makes WordPress skip the action.
	 */
	public static function refuse() {
		return false;
	}

	/**
	 * @param mixed $plugins Active plugin basenames.
	 * @return mixed
	 */
	public static function filter_active_plugins( $plugins ) {
		if ( ! is_array( $plugins ) || ! self::$ctx ) {
			return $plugins;
		}
		return array_values( array_diff( $plugins, self::$ctx['plugins'] ) );
	}

	/**
	 * @param mixed $plugins Network-active plugins (basename => time).
	 * @return mixed
	 */
	public static function filter_network_plugins( $plugins ) {
		if ( ! is_array( $plugins ) || ! self::$ctx ) {
			return $plugins;
		}
		return array_diff_key( $plugins, array_flip( self::$ctx['plugins'] ) );
	}

	/**
	 * @param mixed $modules Enabled module ids.
	 * @return mixed
	 */
	public static function filter_modules( $modules ) {
		if ( ! self::$ctx ) {
			return $modules;
		}
		return array_values( array_unique( array_merge( array_map( 'strval', is_array( $modules ) ? $modules : array() ), self::$ctx['modules'] ) ) );
	}

	/**
	 * Post meta that is staged (menu item icons, visibility ...) answers `get_post_meta()` as if
	 * the switch had already written it.
	 */
	private static function load_meta_overlay(): void {
		foreach ( (array) ( self::$ctx['staged']['activations'] ?? array() ) as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			foreach ( (array) ( $row['data'] ?? array() ) as $id => $payload ) {
				if ( is_array( $payload ) && isset( $payload['meta'] ) && is_array( $payload['meta'] ) && 'user' !== ( $payload['type'] ?? 'post' ) ) {
					foreach ( $payload['meta'] as $key => $value ) {
						self::$meta[ (int) $id ][ (string) $key ] = $value;
					}
				}
			}
		}
		if ( self::$meta ) {
			add_filter( 'get_post_metadata', array( self::class, 'filter_post_meta' ), 10, 4 );
		}
	}

	/**
	 * @param mixed  $value     Short-circuit value.
	 * @param int    $object_id Post id.
	 * @param string $meta_key  Meta key.
	 * @param bool   $single    Single value requested.
	 * @return mixed
	 */
	public static function filter_post_meta( $value, $object_id, $meta_key, $single ) {
		if ( null !== $value || '' === $meta_key || ! isset( self::$meta[ (int) $object_id ][ $meta_key ] ) ) {
			return $value;
		}
		$staged = self::$meta[ (int) $object_id ][ $meta_key ];
		return $single ? $staged : array( $staged );
	}

	/**
	 * Drop write statements that would persist UX Studio state in a preview request.
	 *
	 * @param mixed $sql Query.
	 * @return mixed
	 */
	public static function guard_query( $sql ) {
		if ( ! is_string( $sql ) || ! self::$ctx || ! preg_match( '/^\s*(?:\/\*.*?\*\/\s*)?(?:INSERT|UPDATE|REPLACE|DELETE|TRUNCATE|ALTER|DROP|CREATE|RENAME)\b/is', $sql ) ) {
			return $sql;
		}
		if ( self::is_ours( $sql ) ) {
			if ( count( self::$blocked ) < 50 ) {
				self::$blocked[] = substr( preg_replace( '/\s+/', ' ', $sql ), 0, 160 );
			}
			return '';
		}
		return $sql;
	}

	/**
	 * Does a write statement touch something of UX Studio, or the plugin lists a preview rewrote?
	 *
	 * @param string $sql Write statement.
	 */
	public static function is_ours( string $sql ): bool {
		$prefix = isset( $GLOBALS['wpdb']->prefix ) ? (string) $GLOBALS['wpdb']->prefix : 'wp_';
		// Our tables (and the migration backup tables): the name sits at the head of the statement.
		if ( preg_match( '/`?' . preg_quote( $prefix, '/' ) . '(?:uxstudio_|uxsmb_)/i', substr( $sql, 0, 300 ) ) ) {
			return true;
		}
		// Options, transients and meta keys of ours, and the active plugin lists.
		return (bool) preg_match( "/'(?:_site_transient_|_transient_)?(?:timeout_)?(?:uxstudio_[a-z0-9_\\-]*|active_plugins|active_sitewide_plugins)'/i", $sql );
	}

	/**
	 * Path part of a request URI (no WordPress formatting API needed this early).
	 *
	 * @param string $uri Request URI.
	 */
	private static function path_of( string $uri ): string {
		$path = parse_url( $uri, PHP_URL_PATH ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url
		return is_string( $path ) ? $path : '';
	}
}
