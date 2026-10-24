<?php
/**
 * Cron events that call a URL (GET or POST) on a schedule.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\CronControl;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * The definitions live in the option `uxstudio_cron_url_events` (id => event); each enabled one has a WP-Cron event on the hook
 * `uxstudio_cron_url_event` with the id as its only argument. There are NO events that run PHP code (PLAN.md 27.3 C): the only
 * thing an event can do is request one address.
 *
 * SSRF: the address must be http(s) and every IP its host resolves to must be public (not private, loopback, link-local, CGNAT,
 * multicast or reserved, IPv4-mapped IPv6 included). The request goes through `wp_safe_remote_request()`, which validates the
 * address again and every redirect, and only talks to ports 80, 443 and 8080. A developer can open ONE address to private
 * targets with the filter `uxstudio_cron_url_allow_private` (url, host); such a request does not follow redirects.
 *
 * Each run stores its outcome in the definition: time, HTTP status, status (`ok` = 2xx, `error`, `blocked`), the error text and
 * the duration. The response body is never stored.
 */
final class UrlEvents {

	public const OPTION = 'uxstudio_cron_url_events';
	public const HOOK   = 'uxstudio_cron_url_event';

	/** Most events the module keeps. */
	public const MAX = 50;

	/** Longest request body (bytes). */
	public const MAX_BODY = 4000;

	/**
	 * Every event, normalized.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function all(): array {
		$out = array();
		foreach ( (array) get_option( self::OPTION, array() ) as $id => $event ) {
			if ( is_array( $event ) ) {
				$norm = self::normalize( (string) $id, $event );
				if ( null !== $norm ) {
					$out[ $norm['id'] ] = $norm;
				}
			}
		}
		return $out;
	}

	/**
	 * One event.
	 *
	 * @param string $id Event id.
	 * @return array<string, mixed>|null
	 */
	public static function get( string $id ): ?array {
		$all = self::all();
		return $all[ $id ] ?? null;
	}

	/**
	 * Well-formed event with every key present; null when it cannot be an event.
	 *
	 * @param string               $id    Event id.
	 * @param array<string, mixed> $event Stored data.
	 * @return array<string, mixed>|null
	 */
	public static function normalize( string $id, array $event ): ?array {
		$id = preg_replace( '/[^a-f0-9]/', '', strtolower( $id ) );
		if ( ! is_string( $id ) || '' === $id || '' === (string) ( $event['url'] ?? '' ) ) {
			return null;
		}
		return array(
			'id'          => $id,
			'name'        => mb_substr( sanitize_text_field( (string) ( $event['name'] ?? '' ) ), 0, 100 ),
			'url'         => (string) $event['url'],
			'method'      => 'POST' === strtoupper( (string) ( $event['method'] ?? 'GET' ) ) ? 'POST' : 'GET',
			'body'        => mb_substr( (string) ( $event['body'] ?? '' ), 0, self::MAX_BODY ),
			'schedule'    => sanitize_key( (string) ( $event['schedule'] ?? '' ) ),
			'timeout'     => max( 1, min( 60, (int) ( $event['timeout'] ?? 15 ) ) ),
			'enabled'     => ! empty( $event['enabled'] ),
			'first_run'   => max( 0, (int) ( $event['first_run'] ?? 0 ) ),
			'last_run'    => max( 0, (int) ( $event['last_run'] ?? 0 ) ),
			'last_code'   => max( 0, (int) ( $event['last_code'] ?? 0 ) ),
			'last_status' => in_array( (string) ( $event['last_status'] ?? '' ), array( 'ok', 'error', 'blocked' ), true ) ? (string) $event['last_status'] : '',
			'last_error'  => mb_substr( (string) ( $event['last_error'] ?? '' ), 0, 300 ),
			'last_ms'     => max( 0, (int) ( $event['last_ms'] ?? 0 ) ),
		);
	}

	/**
	 * Create or change an event (validated; nothing is written when it is not valid).
	 *
	 * @param array<string, mixed> $input Name, url, method, body, schedule ('' = once), timeout, enabled, first_run (timestamp).
	 * @param string               $id    Id of the event to change, '' for a new one.
	 * @return array<string, mixed>|WP_Error The stored event.
	 */
	public static function save( array $input, string $id = '' ) {
		$all = self::all();
		if ( '' !== $id && ! isset( $all[ $id ] ) ) {
			return new WP_Error( 'uxstudio_not_found', __( 'That event does not exist.', 'ux-studio' ), array( 'status' => 404 ) );
		}
		if ( '' === $id && count( $all ) >= self::MAX ) {
			return new WP_Error( 'uxstudio_too_many', __( 'Too many URL events.', 'ux-studio' ), array( 'status' => 400 ) );
		}

		$url = esc_url_raw( trim( (string) ( $input['url'] ?? '' ) ), array( 'http', 'https' ) );
		if ( '' === $url || strlen( $url ) > 2000 ) {
			return new WP_Error( 'uxstudio_bad_url', __( 'Enter a valid http:// or https:// address.', 'ux-studio' ), array( 'status' => 400 ) );
		}
		$guard = self::check_url( $url );
		if ( true !== $guard ) {
			return new WP_Error( 'uxstudio_blocked_url', $guard, array( 'status' => 400 ) );
		}

		$schedule = sanitize_key( (string) ( $input['schedule'] ?? '' ) );
		if ( '' !== $schedule && ! isset( wp_get_schedules()[ $schedule ] ) ) {
			return new WP_Error( 'uxstudio_bad_schedule', __( 'That interval does not exist.', 'ux-studio' ), array( 'status' => 400 ) );
		}

		$event = self::normalize(
			'' !== $id ? $id : substr( md5( uniqid( '', true ) ), 0, 8 ),
			array_merge(
				'' !== $id ? $all[ $id ] : array(),
				array(
					'name'      => (string) ( $input['name'] ?? '' ),
					'url'       => $url,
					'method'    => (string) ( $input['method'] ?? 'GET' ),
					'body'      => 'POST' === strtoupper( (string) ( $input['method'] ?? 'GET' ) ) ? (string) ( $input['body'] ?? '' ) : '',
					'schedule'  => $schedule,
					'timeout'   => (int) ( $input['timeout'] ?? 15 ),
					'enabled'   => ! empty( $input['enabled'] ),
					'first_run' => (int) ( $input['first_run'] ?? 0 ),
				)
			)
		);
		if ( null === $event ) {
			return new WP_Error( 'uxstudio_bad_url', __( 'Enter a valid http:// or https:// address.', 'ux-studio' ), array( 'status' => 400 ) );
		}
		if ( '' === $event['name'] ) {
			$event['name'] = (string) wp_parse_url( $url, PHP_URL_HOST );
		}

		$all[ $event['id'] ] = $event;
		self::store( $all );
		self::sync();
		return $event;
	}

	/**
	 * Store an event WITHOUT switching it on or scheduling it (the migration creates them this way and the switch turns them
	 * on). The same validation as save(); `$known_schedules` lets the caller vouch for intervals that exist but are not
	 * registered yet (the module registers its own intervals only while it is on).
	 *
	 * @param string               $id              Event id (hex).
	 * @param array<string, mixed> $fields          name, url, method, body, schedule, first_run.
	 * @param string[]             $known_schedules Interval names to accept besides the registered ones.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function add_disabled( string $id, array $fields, array $known_schedules = array() ) {
		$url = esc_url_raw( trim( (string) ( $fields['url'] ?? '' ) ), array( 'http', 'https' ) );
		if ( '' === $url || strlen( $url ) > 2000 ) {
			return new WP_Error( 'uxstudio_bad_url', __( 'Enter a valid http:// or https:// address.', 'ux-studio' ) );
		}
		$guard = self::check_url( $url );
		if ( true !== $guard ) {
			return new WP_Error( 'uxstudio_blocked_url', $guard );
		}
		$schedule = sanitize_key( (string) ( $fields['schedule'] ?? '' ) );
		if ( '' !== $schedule && ! isset( wp_get_schedules()[ $schedule ] ) && ! in_array( $schedule, $known_schedules, true ) ) {
			return new WP_Error( 'uxstudio_bad_schedule', __( 'That interval does not exist.', 'ux-studio' ) );
		}
		$event = self::normalize(
			$id,
			array(
				'name'      => (string) ( $fields['name'] ?? '' ),
				'url'       => $url,
				'method'    => (string) ( $fields['method'] ?? 'GET' ),
				'body'      => 'POST' === strtoupper( (string) ( $fields['method'] ?? 'GET' ) ) ? (string) ( $fields['body'] ?? '' ) : '',
				'schedule'  => $schedule,
				'enabled'   => false,
				'first_run' => (int) ( $fields['first_run'] ?? 0 ),
			)
		);
		if ( null === $event ) {
			return new WP_Error( 'uxstudio_bad_url', __( 'Enter a valid http:// or https:// address.', 'ux-studio' ) );
		}
		if ( '' === $event['name'] ) {
			$event['name'] = (string) wp_parse_url( $url, PHP_URL_HOST );
		}
		$all                 = self::all();
		$all[ $event['id'] ] = $event;
		self::store( $all );
		return $event;
	}

	/**
	 * Switch events on or off and bring WP-Cron in line.
	 *
	 * @param string[] $ids     Event ids.
	 * @param bool     $enabled New state.
	 * @return int How many events are in that state afterwards.
	 */
	public static function set_enabled( array $ids, bool $enabled ): int {
		$all   = self::all();
		$count = 0;
		foreach ( $ids as $id ) {
			if ( isset( $all[ (string) $id ] ) ) {
				$all[ (string) $id ]['enabled'] = $enabled;
				++$count;
			}
		}
		if ( $count ) {
			self::store( $all );
			self::sync();
		}
		return $count;
	}

	/**
	 * Delete an event and its WP-Cron entry.
	 *
	 * @param string $id Event id.
	 */
	public static function delete( string $id ): bool {
		$all = self::all();
		if ( ! isset( $all[ $id ] ) ) {
			return false;
		}
		self::unschedule( $id );
		unset( $all[ $id ] );
		self::store( $all );
		return true;
	}

	/**
	 * @param array<string, array<string, mixed>> $all Events.
	 */
	private static function store( array $all ): void {
		update_option( self::OPTION, $all, false );
	}

	/* ── Running ── */

	/**
	 * Request the address of an event and store the outcome.
	 *
	 * @param string $id Event id.
	 * @return array{status:string,code:int,error:string,ms:int}|null Null when the event does not exist.
	 */
	public static function execute( string $id ): ?array {
		$event = self::get( $id );
		if ( null === $event ) {
			return null;
		}

		$started = microtime( true );
		$result  = self::request( $event );
		$ms      = (int) round( ( microtime( true ) - $started ) * 1000 );

		$all = self::all();
		if ( isset( $all[ $id ] ) ) {
			$all[ $id ]['last_run']    = time();
			$all[ $id ]['last_code']   = $result['code'];
			$all[ $id ]['last_status'] = $result['status'];
			$all[ $id ]['last_error']  = mb_substr( $result['error'], 0, 300 );
			$all[ $id ]['last_ms']     = $ms;
			// A one-off event is done once it ran (its WP-Cron entry is gone), so it must not be scheduled again.
			if ( '' === $all[ $id ]['schedule'] ) {
				$all[ $id ]['enabled'] = false;
			}
			self::store( $all );
		}

		return array(
			'status' => $result['status'],
			'code'   => $result['code'],
			'error'  => $result['error'],
			'ms'     => $ms,
		);
	}

	/**
	 * The HTTP request of an event.
	 *
	 * @param array<string, mixed> $event Event.
	 * @return array{status:string,code:int,error:string}
	 */
	private static function request( array $event ): array {
		$url   = (string) $event['url'];
		$guard = self::check_url( $url );
		if ( true !== $guard ) {
			return array(
				'status' => 'blocked',
				'code'   => 0,
				'error'  => $guard,
			);
		}

		$args = array(
			'method'              => $event['method'],
			'timeout'             => (int) $event['timeout'],
			'redirection'         => 3,
			'user-agent'          => 'UX Studio Cron/' . ( defined( 'UXSTUDIO_VERSION' ) ? UXSTUDIO_VERSION : '1' ) . '; ' . home_url( '/' ),
			'limit_response_size' => 1048576,
		);
		if ( 'POST' === $event['method'] && '' !== $event['body'] ) {
			$args['body']    = $event['body'];
			$args['headers'] = array( 'Content-Type' => null !== json_decode( (string) $event['body'] ) ? 'application/json' : 'application/x-www-form-urlencoded' );
		}

		$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		if ( self::private_allowed( $url, $host ) ) {
			// Deliberately opened by code: no redirects (a hop could lead anywhere) and no safe-URL check.
			$args['redirection']        = 0;
			$args['reject_unsafe_urls'] = false;
			$response                   = wp_remote_request( $url, $args );
		} else {
			$response = wp_safe_remote_request( $url, $args );
		}

		if ( is_wp_error( $response ) ) {
			return array(
				'status' => 'error',
				'code'   => 0,
				'error'  => $response->get_error_message(),
			);
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		$ok   = $code >= 200 && $code < 300;
		return array(
			'status' => $ok ? 'ok' : 'error',
			'code'   => $code,
			'error'  => $ok ? '' : 'HTTP ' . $code,
		);
	}

	/* ── SSRF guard ── */

	/**
	 * Whether code has opened this address to private targets.
	 *
	 * @param string $url  Address.
	 * @param string $host Its host.
	 */
	private static function private_allowed( string $url, string $host ): bool {
		return (bool) apply_filters( 'uxstudio_cron_url_allow_private', false, $url, $host );
	}

	/**
	 * Is this address allowed as a target?
	 *
	 * @param string $url Address.
	 * @return true|string True, or the reason (translated) it is refused.
	 */
	public static function check_url( string $url ) {
		$parts  = wp_parse_url( $url );
		$scheme = strtolower( (string) ( $parts['scheme'] ?? '' ) );
		$host   = strtolower( trim( (string) ( $parts['host'] ?? '' ), '[]' ) );
		if ( ! in_array( $scheme, array( 'http', 'https' ), true ) || '' === $host ) {
			return __( 'Enter a valid http:// or https:// address.', 'ux-studio' );
		}
		if ( ! empty( $parts['user'] ) || ! empty( $parts['pass'] ) ) {
			return __( 'The address must not contain a user name or password.', 'ux-studio' );
		}
		if ( self::private_allowed( $url, $host ) ) {
			return true;
		}
		if ( 'localhost' === $host || '.localhost' === substr( $host, -10 ) ) {
			return self::blocked_text();
		}
		$ips = self::resolve( $host );
		if ( array() === $ips ) {
			return __( 'The address cannot be resolved.', 'ux-studio' );
		}
		foreach ( $ips as $ip ) {
			if ( ! self::is_public_ip( $ip ) ) {
				return self::blocked_text();
			}
		}
		return true;
	}

	private static function blocked_text(): string {
		return __( 'This address points to the server itself or to a private network, so it is blocked.', 'ux-studio' );
	}

	/**
	 * Every IP a host resolves to (a literal IP resolves to itself).
	 *
	 * @param string $host Host name or IP.
	 * @return string[]
	 */
	private static function resolve( string $host ): array {
		if ( false !== filter_var( $host, FILTER_VALIDATE_IP ) ) {
			return array( $host );
		}
		$ips = array();
		$a   = @gethostbynamel( $host ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( is_array( $a ) ) {
			$ips = $a;
		}
		if ( function_exists( 'dns_get_record' ) ) {
			$aaaa = @dns_get_record( $host, DNS_AAAA ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			foreach ( is_array( $aaaa ) ? $aaaa : array() as $record ) {
				if ( ! empty( $record['ipv6'] ) ) {
					$ips[] = (string) $record['ipv6'];
				}
			}
		}
		return array_values( array_unique( $ips ) );
	}

	/**
	 * Public, routable address? Private, loopback, link-local (cloud metadata), CGNAT, multicast, reserved and IPv4-mapped /
	 * 6to4 / Teredo forms of those are not.
	 *
	 * @param string $ip IP address.
	 */
	public static function is_public_ip( string $ip ): bool {
		if ( false === filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			return false;
		}
		if ( false === filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
			return false;
		}
		if ( false !== filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) {
			$n = ip2long( $ip );
			foreach ( array(
				array( '0.0.0.0', '0.255.255.255' ),
				array( '100.64.0.0', '100.127.255.255' ),
				array( '169.254.0.0', '169.254.255.255' ),
				array( '192.0.0.0', '192.0.0.255' ),
				array( '198.18.0.0', '198.19.255.255' ),
				array( '224.0.0.0', '255.255.255.255' ),
			) as $range ) {
				if ( $n >= ip2long( $range[0] ) && $n <= ip2long( $range[1] ) ) {
					return false;
				}
			}
			return true;
		}
		$packed = inet_pton( $ip );
		if ( false === $packed || 16 !== strlen( $packed ) ) {
			return false;
		}
		// ::/8 (unspecified, loopback, IPv4-compatible and IPv4-mapped), fe80::/10 and fec0::/10, ff00::/8 multicast, fc00::/7 unique local.
		$first = ord( $packed[0] );
		if ( 0 === $first || 0xff === $first || 0xfc === ( $first & 0xfe ) || ( 0xfe === $first && 0x80 === ( ord( $packed[1] ) & 0x80 ) ) || ( 0xfe === $first && 0xc0 === ( ord( $packed[1] ) & 0xc0 ) ) ) {
			return false;
		}
		// 6to4 (2002::/16) and Teredo (2001::/32) carry an IPv4 address that must be public too.
		if ( "\x20\x02" === substr( $packed, 0, 2 ) ) {
			return self::is_public_ip( (string) inet_ntop( substr( $packed, 2, 4 ) ) );
		}
		if ( "\x20\x01\x00\x00" === substr( $packed, 0, 4 ) ) {
			return self::is_public_ip( (string) inet_ntop( ~substr( $packed, 12, 4 ) ) );
		}
		return true;
	}

	/* ── WP-Cron entries ── */

	/**
	 * Make WP-Cron match the definitions: an enabled event has its entry (with its interval), a disabled one has none.
	 */
	public static function sync(): void {
		foreach ( self::all() as $id => $event ) {
			$args    = array( $id );
			$current = function_exists( 'wp_get_scheduled_event' ) ? wp_get_scheduled_event( self::HOOK, $args ) : false;

			if ( ! $event['enabled'] ) {
				if ( $current ) {
					wp_unschedule_event( (int) $current->timestamp, self::HOOK, $args );
				}
				continue;
			}
			if ( $current && (string) ( $current->schedule ?: '' ) === $event['schedule'] ) {
				continue;
			}
			if ( $current ) {
				wp_unschedule_event( (int) $current->timestamp, self::HOOK, $args );
			}
			$first = $event['first_run'] > time() ? $event['first_run'] : time() + 60;
			if ( '' === $event['schedule'] ) {
				wp_schedule_single_event( $first, self::HOOK, $args );
			} else {
				wp_schedule_event( $first, $event['schedule'], self::HOOK, $args );
			}
		}
	}

	/**
	 * Remove the WP-Cron entry of one event.
	 *
	 * @param string $id Event id.
	 */
	public static function unschedule( string $id ): void {
		$args = array( $id );
		$ts   = wp_next_scheduled( self::HOOK, $args );
		if ( false !== $ts ) {
			wp_unschedule_event( (int) $ts, self::HOOK, $args );
		}
	}

	/**
	 * Remove every entry (module switched off). The definitions stay.
	 */
	public static function unschedule_all(): void {
		foreach ( array_keys( self::all() ) as $id ) {
			self::unschedule( (string) $id );
		}
	}
}
