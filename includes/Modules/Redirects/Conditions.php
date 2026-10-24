<?php
/**
 * Conditions of a redirect rule (PLAN.md 27, A2): the rule applies only to visitors that meet ALL of them.
 *
 * Pure PHP on purpose (no WordPress calls in the matching and the validation), so the whole matrix is covered by a
 * standalone test. The request data come in as a plain "context" array; `request_context()` builds it from the real
 * request, `blank_context()` is an anonymous visitor with no headers (what the URL tester and the 404 list use).
 *
 * Semantics follow the Redirection plugin's match types (login, role, referrer, user agent, cookie, IP, server, HTTP
 * header), with two additions: `contains` next to `equals` / `regex` for text, and a per-condition `negate`. The
 * plugin's "target when not matched" is a second rule with the same source and the negated condition.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\Redirects;

use UxStudio\Core\ClientIp;

defined( 'ABSPATH' ) || exit;

final class Conditions {

	public const TYPES = array( 'login', 'role', 'referrer', 'agent', 'ip', 'cookie', 'host', 'header' );

	public const OPS = array( 'equals', 'contains', 'regex' );

	/** Max conditions per rule. */
	public const MAX = 5;

	/** Max length of one text value. */
	public const MAX_VALUE = 500;

	/** Max entries of a role / IP list. */
	public const MAX_LIST = 50;

	/** Longest request value that is compared (a huge header must not feed a regex). */
	private const MAX_SUBJECT = 2048;

	/** @var array<string, mixed>|null */
	private static ?array $request_context = null;

	/*
	 * ------------------------------------------------------------------
	 * Validation / storage
	 * ------------------------------------------------------------------
	 */

	/**
	 * Validate and canonicalise the conditions of one rule.
	 *
	 * @param mixed $raw List of conditions (or one condition as an object); null / '' / [] = none.
	 * @return array{conditions:array<int, array<string, mixed>>, errors:array<int, array{code:string, message:string}>}
	 */
	public static function normalize( $raw ): array {
		$errors = array();
		if ( is_string( $raw ) ) {
			$decoded = '' === trim( $raw ) ? array() : json_decode( $raw, true );
			$raw     = is_array( $decoded ) ? $decoded : array();
		}
		if ( ! is_array( $raw ) || ! $raw ) {
			return array( 'conditions' => array(), 'errors' => array() );
		}
		if ( isset( $raw['type'] ) ) {
			$raw = array( $raw ); // A single condition given as an object.
		}
		if ( count( $raw ) > self::MAX ) {
			$errors[] = self::issue( 'too_many_conditions', sprintf( 'A rule can have at most %d conditions.', self::MAX ) );
			$raw      = array_slice( $raw, 0, self::MAX );
		}

		$out = array();
		foreach ( array_values( $raw ) as $index => $item ) {
			if ( ! is_array( $item ) ) {
				$errors[] = self::issue( 'invalid_condition', sprintf( 'Condition %d is not an object.', $index + 1 ) );
				continue;
			}
			$result = self::normalize_one( $item, $index + 1 );
			if ( is_string( $result ) ) {
				$errors[] = self::issue( 'invalid_condition', $result );
				continue;
			}
			$out[] = $result;
		}

		// The order of conditions does not matter (all must hold), so the stored form is sorted: the same
		// set always yields the same key.
		usort(
			$out,
			static fn ( array $a, array $b ): int => strcmp( (string) json_encode( $a ), (string) json_encode( $b ) )
		);
		return array( 'conditions' => $out, 'errors' => $errors );
	}

	/**
	 * One condition in its canonical form, or the error message.
	 *
	 * @param array<string, mixed> $item Raw condition.
	 * @param int                  $n    Position, for messages.
	 * @return array<string, mixed>|string
	 */
	private static function normalize_one( array $item, int $n ) {
		$type = strtolower( trim( (string) ( $item['type'] ?? '' ) ) );
		if ( ! in_array( $type, self::TYPES, true ) ) {
			return sprintf( 'Condition %1$d: type must be one of %2$s.', $n, implode( ', ', self::TYPES ) );
		}
		$negate = self::truthy( $item['negate'] ?? false );
		$op     = strtolower( trim( (string) ( $item['op'] ?? '' ) ) );
		$value  = $item['value'] ?? '';

		switch ( $type ) {
			case 'login':
				$flag = strtolower( trim( is_array( $value ) ? '' : (string) $value ) );
				if ( in_array( $flag, array( 'in', 'logged_in', 'logged-in', 'login', 'true', '1', 'yes' ), true ) ) {
					$in = true;
				} elseif ( in_array( $flag, array( 'out', 'logged_out', 'logged-out', 'logout', 'false', '0', 'no' ), true ) ) {
					$in = false;
				} else {
					return sprintf( 'Condition %d: login value must be "in" or "out".', $n );
				}
				// "not logged in" is "logged out": one canonical form.
				return array( 'type' => 'login', 'value' => ( $in xor $negate ) ? 'in' : 'out', 'negate' => false );

			case 'role':
				$roles = self::list_of( $value, true );
				if ( ! $roles ) {
					return sprintf( 'Condition %d: give at least one role or capability.', $n );
				}
				if ( count( $roles ) > self::MAX_LIST ) {
					return sprintf( 'Condition %1$d: at most %2$d roles.', $n, self::MAX_LIST );
				}
				return array( 'type' => 'role', 'value' => $roles, 'negate' => $negate );

			case 'ip':
				$entries = self::list_of( $value, false );
				if ( ! $entries ) {
					return sprintf( 'Condition %d: give at least one IP address or CIDR range.', $n );
				}
				if ( count( $entries ) > self::MAX_LIST ) {
					return sprintf( 'Condition %1$d: at most %2$d IP entries.', $n, self::MAX_LIST );
				}
				$clean = array();
				foreach ( $entries as $entry ) {
					$norm = self::normalize_ip( $entry );
					if ( null === $norm ) {
						return sprintf( 'Condition %1$d: "%2$s" is not an IP address or CIDR range.', $n, substr( $entry, 0, 60 ) );
					}
					$clean[ $norm ] = $norm;
				}
				return array( 'type' => 'ip', 'value' => array_values( $clean ), 'negate' => $negate );

			case 'cookie':
			case 'header':
				$name = trim( (string) ( $item['name'] ?? '' ) );
				$ok   = 'cookie' === $type ? '/^[A-Za-z0-9_.\-]{1,100}$/' : '/^[A-Za-z0-9\-]{1,100}$/';
				if ( 1 !== preg_match( $ok, $name ) ) {
					return sprintf( 'Condition %1$d: invalid %2$s name.', $n, $type );
				}
				$text = self::text_value( $value );
				if ( null === $text ) {
					return sprintf( 'Condition %1$d: the value is longer than %2$d characters.', $n, self::MAX_VALUE );
				}
				$cond = array( 'type' => $type, 'name' => 'header' === $type ? strtolower( $name ) : $name );
				if ( '' === $text ) {
					// No value = the cookie / header only has to exist.
					return $cond + array( 'op' => 'equals', 'value' => '', 'negate' => $negate );
				}
				$op = self::text_op( $op, 'equals' );
				if ( null === $op ) {
					return sprintf( 'Condition %d: op must be equals, contains or regex.', $n );
				}
				if ( 'regex' === $op && ! self::regex_ok( $text ) ) {
					return sprintf( 'Condition %d: the value is not a valid regular expression, or it is too slow (avoid nested repeats such as (a+)+).', $n );
				}
				return $cond + array( 'op' => $op, 'value' => $text, 'negate' => $negate );

			default: // referrer, agent, host.
				$text = self::text_value( $value );
				if ( null === $text || '' === $text ) {
					return null === $text
						? sprintf( 'Condition %1$d: the value is longer than %2$d characters.', $n, self::MAX_VALUE )
						: sprintf( 'Condition %d: the value is required.', $n );
				}
				$default = 'host' === $type ? 'equals' : 'contains';
				$op      = self::text_op( $op, $default );
				if ( null === $op ) {
					return sprintf( 'Condition %d: op must be equals, contains or regex.', $n );
				}
				if ( 'host' === $type && 'regex' !== $op ) {
					$text = self::host_of( $text );
					if ( '' === $text ) {
						return sprintf( 'Condition %d: the host is not valid.', $n );
					}
				}
				if ( 'regex' === $op && ! self::regex_ok( $text ) ) {
					return sprintf( 'Condition %d: the value is not a valid regular expression, or it is too slow (avoid nested repeats such as (a+)+).', $n );
				}
				return array( 'type' => $type, 'op' => $op, 'value' => $text, 'negate' => $negate );
		}
	}

	/**
	 * JSON to store ('' = no conditions).
	 *
	 * @param array<int, array<string, mixed>> $conditions Canonical conditions.
	 */
	public static function encode( array $conditions ): string {
		return $conditions ? (string) json_encode( array_values( $conditions ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) : '';
	}

	/**
	 * Stored JSON back to conditions (a corrupt value is "no conditions", never an error on the front end).
	 *
	 * @param mixed $stored JSON string from the database.
	 * @return array<int, array<string, mixed>>
	 */
	public static function decode( $stored ): array {
		if ( ! is_string( $stored ) || '' === $stored ) {
			return array();
		}
		$data = json_decode( $stored, true );
		return is_array( $data ) ? array_values( array_filter( $data, 'is_array' ) ) : array();
	}

	/**
	 * Identity of a condition set (part of a rule's unique key): '' when there are none.
	 *
	 * @param array<int, array<string, mixed>> $conditions Canonical conditions.
	 */
	public static function key( array $conditions ): string {
		return $conditions ? sha1( self::encode( $conditions ) ) : '';
	}

	/**
	 * Short English description, for logs and the URL tester.
	 *
	 * @param array<int, array<string, mixed>> $conditions Canonical conditions.
	 */
	public static function summary( array $conditions ): string {
		$parts = array();
		foreach ( $conditions as $c ) {
			$neg   = ! empty( $c['negate'] ) ? 'not ' : '';
			$value = is_array( $c['value'] ?? '' ) ? implode( ',', $c['value'] ) : (string) ( $c['value'] ?? '' );
			switch ( $c['type'] ?? '' ) {
				case 'login':
					$parts[] = 'in' === $value ? 'logged in' : 'logged out';
					break;
				case 'cookie':
				case 'header':
					$parts[] = $neg . $c['type'] . ' ' . ( $c['name'] ?? '' ) . ( '' === $value ? ' present' : ' ' . ( $c['op'] ?? 'equals' ) . ' ' . $value );
					break;
				case 'role':
				case 'ip':
					$parts[] = $neg . $c['type'] . ' in ' . $value;
					break;
				default:
					$parts[] = $neg . ( $c['type'] ?? '' ) . ' ' . ( $c['op'] ?? 'equals' ) . ' ' . $value;
			}
		}
		return implode( ' and ', $parts );
	}

	/*
	 * ------------------------------------------------------------------
	 * Matching
	 * ------------------------------------------------------------------
	 */

	/**
	 * Whether a visitor meets every condition.
	 *
	 * @param array<int, array<string, mixed>> $conditions Canonical conditions (empty = always).
	 * @param array<string, mixed>             $context    See blank_context().
	 */
	public static function matches( array $conditions, array $context ): bool {
		foreach ( $conditions as $condition ) {
			$ok = self::holds( $condition, $context );
			if ( ! empty( $condition['negate'] ) ) {
				$ok = ! $ok;
			}
			if ( ! $ok ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * One condition before negation.
	 *
	 * @param array<string, mixed> $c   Condition.
	 * @param array<string, mixed> $ctx Context.
	 */
	private static function holds( array $c, array $ctx ): bool {
		$value = $c['value'] ?? '';
		switch ( $c['type'] ?? '' ) {
			case 'login':
				return 'in' === $value ? ! empty( $ctx['logged_in'] ) : empty( $ctx['logged_in'] );

			case 'role':
				if ( empty( $ctx['logged_in'] ) ) {
					return false;
				}
				$roles = array_map( 'strval', (array) ( $ctx['roles'] ?? array() ) );
				foreach ( (array) $value as $wanted ) {
					// A role slug, or a capability (the Redirection plugin asks current_user_can()).
					if ( in_array( (string) $wanted, $roles, true ) || ( isset( $ctx['can'] ) && is_callable( $ctx['can'] ) && true === (bool) call_user_func( $ctx['can'], (string) $wanted ) ) ) {
						return true;
					}
				}
				return false;

			case 'ip':
				$ip = (string) ( $ctx['ip'] ?? '' );
				return '' !== $ip && ClientIp::in_ranges( $ip, (array) $value );

			case 'cookie':
				$name    = (string) ( $c['name'] ?? '' );
				$cookies = (array) ( $ctx['cookies'] ?? array() );
				// PHP rewrites "." and " " in cookie names to "_".
				$subject = $cookies[ $name ] ?? ( $cookies[ str_replace( array( '.', ' ' ), '_', $name ) ] ?? null );
				return self::text_holds( $c, null === $subject ? null : (string) $subject, false );

			case 'header':
				$headers = array_change_key_case( (array) ( $ctx['headers'] ?? array() ), CASE_LOWER );
				$subject = $headers[ strtolower( (string) ( $c['name'] ?? '' ) ) ] ?? null;
				return self::text_holds( $c, null === $subject ? null : (string) $subject, true );

			case 'referrer':
				return self::text_holds( $c, (string) ( $ctx['referrer'] ?? '' ), true );

			case 'agent':
				return self::text_holds( $c, (string) ( $ctx['agent'] ?? '' ), true );

			case 'host':
				return self::text_holds( $c, strtolower( (string) ( $ctx['host'] ?? '' ) ), true );
		}
		return false;
	}

	/**
	 * Text comparison. `null` subject = the cookie / header is not there.
	 *
	 * @param array<string, mixed> $c          Condition.
	 * @param string|null          $subject    Request value.
	 * @param bool                 $ignorecase Case-insensitive equals / contains.
	 */
	private static function text_holds( array $c, ?string $subject, bool $ignorecase ): bool {
		if ( null === $subject ) {
			return false;
		}
		$wanted = (string) ( $c['value'] ?? '' );
		if ( '' === $wanted && in_array( $c['type'] ?? '', array( 'cookie', 'header' ), true ) ) {
			return true; // Existence only.
		}
		$subject = substr( $subject, 0, self::MAX_SUBJECT );
		switch ( $c['op'] ?? 'equals' ) {
			case 'regex':
				// Validated on save; run with a short subject and a low backtrack limit (ReDoS): a pattern that
				// fails at run time simply does not match.
				return \UxStudio\Core\SafeRegex::match( self::delimit( $wanted ), $subject );
			case 'contains':
				return $ignorecase ? false !== stripos( $subject, $wanted ) : false !== strpos( $subject, $wanted );
			default:
				return $ignorecase ? 0 === strcasecmp( $subject, $wanted ) : $subject === $wanted;
		}
	}

	/*
	 * ------------------------------------------------------------------
	 * Context
	 * ------------------------------------------------------------------
	 */

	/**
	 * An anonymous visitor with no headers, no cookies and no IP.
	 *
	 * @return array<string, mixed>
	 */
	public static function blank_context(): array {
		return array(
			'logged_in' => false,
			'roles'     => array(),
			'can'       => null,
			'referrer'  => '',
			'agent'     => '',
			'ip'        => '',
			'cookies'   => array(),
			'host'      => '',
			'headers'   => array(),
		);
	}

	/**
	 * The current request (memoised: built at most once, and only when a conditional rule is looked at).
	 *
	 * @return array<string, mixed>
	 */
	public static function request_context(): array {
		if ( null !== self::$request_context ) {
			return self::$request_context;
		}
		$server = static function ( string $key ): string {
			$v = $_SERVER[ $key ] ?? ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			return is_string( $v ) ? substr( function_exists( 'wp_unslash' ) ? (string) wp_unslash( $v ) : $v, 0, self::MAX_SUBJECT ) : '';
		};

		$headers = array();
		foreach ( array_keys( $_SERVER ) as $key ) {
			if ( is_string( $key ) && 0 === strpos( $key, 'HTTP_' ) ) {
				$headers[ strtolower( str_replace( '_', '-', substr( $key, 5 ) ) ) ] = $server( $key );
			}
		}
		$cookies = array();
		foreach ( (array) $_COOKIE as $name => $val ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			if ( is_string( $val ) ) {
				$cookies[ (string) $name ] = substr( function_exists( 'wp_unslash' ) ? (string) wp_unslash( $val ) : $val, 0, self::MAX_SUBJECT );
			}
		}

		$logged_in = function_exists( 'is_user_logged_in' ) && is_user_logged_in();
		$roles     = array();
		if ( $logged_in && function_exists( 'wp_get_current_user' ) ) {
			$roles = (array) wp_get_current_user()->roles;
		}
		$host = $server( 'HTTP_HOST' );
		if ( '' === $host ) {
			$host = $server( 'SERVER_NAME' );
		}

		self::$request_context = array(
			'logged_in' => $logged_in,
			'roles'     => $roles,
			'can'       => $logged_in && function_exists( 'current_user_can' ) ? 'current_user_can' : null,
			'referrer'  => $server( 'HTTP_REFERER' ),
			'agent'     => $server( 'HTTP_USER_AGENT' ),
			'ip'        => ClientIp::get( 'auto' ),
			'cookies'   => $cookies,
			'host'      => self::host_of( $host ),
			'headers'   => $headers,
		);
		return self::$request_context;
	}

	/**
	 * Forget the memoised request (tests, long-running processes).
	 */
	public static function reset_request_context(): void {
		self::$request_context = null;
	}

	/**
	 * A simulated visitor for the URL tester: only known keys, bounded values.
	 *
	 * @param mixed $raw logged_in, roles, referrer, agent, ip, host, cookies {name: value}, headers {name: value}.
	 * @return array<string, mixed>
	 */
	public static function sanitize_context( $raw ): array {
		$ctx = self::blank_context();
		if ( ! is_array( $raw ) ) {
			return $ctx;
		}
		$cut = static fn ( $v ): string => substr( is_scalar( $v ) ? (string) $v : '', 0, self::MAX_SUBJECT );

		$roles = self::list_of( $raw['roles'] ?? array(), true );
		if ( ! empty( $raw['logged_in'] ) && self::truthy( $raw['logged_in'] ) || $roles ) {
			$ctx['logged_in'] = true;
			$ctx['roles']     = $roles;
		}
		foreach ( array( 'referrer', 'agent' ) as $key ) {
			$ctx[ $key ] = $cut( $raw[ $key ] ?? '' );
		}
		$ip = trim( $cut( $raw['ip'] ?? '' ) );
		if ( '' !== $ip && false !== @inet_pton( $ip ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			$ctx['ip'] = $ip;
		}
		$ctx['host'] = self::host_of( $cut( $raw['host'] ?? '' ) );
		foreach ( array( 'cookies', 'headers' ) as $key ) {
			foreach ( is_array( $raw[ $key ] ?? null ) ? array_slice( $raw[ $key ], 0, 30, true ) : array() as $name => $val ) {
				$ctx[ $key ][ substr( (string) $name, 0, 100 ) ] = $cut( $val );
			}
		}
		return $ctx;
	}

	/*
	 * ------------------------------------------------------------------
	 * Helpers
	 * ------------------------------------------------------------------
	 */

	/**
	 * Wrap a user pattern in delimiters, case-insensitive UTF-8.
	 *
	 * @param string $pattern Pattern without delimiters.
	 */
	public static function delimit( string $pattern ): string {
		return '@' . str_replace( '@', '\@', $pattern ) . '@iu';
	}

	/**
	 * Whether a pattern compiles.
	 *
	 * @param string $pattern Pattern without delimiters.
	 */
	public static function regex_ok( string $pattern ): bool {
		return \UxStudio\Core\SafeRegex::probe( self::delimit( $pattern ) );
	}

	/**
	 * Host part of a value typed as "example.com", "https://Example.com:8080/x".
	 *
	 * @param string $value Raw value.
	 */
	public static function host_of( string $value ): string {
		$value = strtolower( trim( $value ) );
		if ( '' === $value ) {
			return '';
		}
		if ( false !== strpos( $value, '://' ) ) {
			$host = (string) parse_url( $value, PHP_URL_HOST );
		} else {
			$host = (string) preg_replace( '~[/?#].*$~', '', $value );
			if ( 1 === preg_match( '/^(\[[0-9a-f:.]+\]|[^:\[\]]+):\d+$/', $host, $m ) ) {
				$host = $m[1];
			}
		}
		$host = trim( $host, '[]' );
		return 1 === preg_match( '/^[a-z0-9._\-]+$/', $host ) || false !== @inet_pton( $host ) ? $host : ''; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
	}

	/**
	 * IP or CIDR in its canonical text form, or null.
	 *
	 * @param string $entry "203.0.113.5", "10.0.0.0/8", "2001:db8::/32".
	 */
	public static function normalize_ip( string $entry ): ?string {
		$entry = trim( $entry );
		$bits  = null;
		if ( false !== strpos( $entry, '/' ) ) {
			list( $entry, $bits ) = explode( '/', $entry, 2 );
			if ( 1 !== preg_match( '/^\d{1,3}$/', $bits ) ) {
				return null;
			}
			$bits = (int) $bits;
		}
		$bin = @inet_pton( $entry ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( false === $bin ) {
			return null;
		}
		$text = (string) inet_ntop( $bin );
		if ( null === $bits ) {
			return $text;
		}
		return $bits <= strlen( $bin ) * 8 ? $text . '/' . $bits : null;
	}

	/**
	 * Values given as a list or as one comma / newline separated string.
	 *
	 * @param mixed $value        List or string.
	 * @param bool  $slugs_only   Keep only [a-z0-9_-] (roles and capabilities).
	 * @return array<int, string>
	 */
	private static function list_of( $value, bool $slugs_only ): array {
		$items = is_array( $value ) ? $value : preg_split( '/[\s,;]+/', (string) $value );
		$out   = array();
		foreach ( (array) $items as $item ) {
			$item = trim( is_scalar( $item ) ? (string) $item : '' );
			if ( $slugs_only ) {
				$item = strtolower( (string) preg_replace( '/[^a-zA-Z0-9_\-]/', '', $item ) );
			}
			if ( '' !== $item ) {
				$out[ $item ] = $item;
			}
		}
		return array_values( $out );
	}

	/**
	 * A bounded text value, or null when it is too long.
	 *
	 * @param mixed $value Raw value.
	 */
	private static function text_value( $value ): ?string {
		$text = is_scalar( $value ) ? trim( (string) $value ) : '';
		$text = (string) preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $text );
		return strlen( $text ) > self::MAX_VALUE ? null : $text;
	}

	/**
	 * Text operator or the default; null for an unknown one.
	 *
	 * @param string $op      Given operator.
	 * @param string $default Default.
	 */
	private static function text_op( string $op, string $default ): ?string {
		if ( '' === $op ) {
			return $default;
		}
		return in_array( $op, self::OPS, true ) ? $op : null;
	}

	/**
	 * Boolean from a REST-ish value.
	 *
	 * @param mixed $value Raw value.
	 */
	private static function truthy( $value ): bool {
		if ( is_string( $value ) ) {
			return in_array( strtolower( trim( $value ) ), array( '1', 'true', 'yes', 'on' ), true );
		}
		return (bool) $value;
	}

	/**
	 * Issue entry (same shape as Repository's).
	 *
	 * @param string $code    Machine code.
	 * @param string $message Message.
	 * @return array{code:string, message:string}
	 */
	private static function issue( string $code, string $message ): array {
		return array( 'code' => $code, 'message' => $message );
	}
}
