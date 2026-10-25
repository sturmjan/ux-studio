<?php
/**
 * Outgoing request to an address typed by a user, with the checked IP pinned (no DNS rebinding).
 *
 * @package UxStudio
 */

namespace UxStudio\Core;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * "Resolve, check that every IP is public, then let WordPress resolve again and connect" has a gap: a DNS
 * server that answers a public address to the check and an internal one a moment later (DNS rebinding)
 * gets the request into the private network. This helper closes it for the features that fetch a URL
 * chosen by a user (URL events of the Cron control, the image download of Auto Image Upload):
 *
 *  - the host is resolved ONCE, every address must pass the caller's check,
 *  - cURL is told to connect to exactly that address (`CURLOPT_RESOLVE` through the `http_api_curl` action;
 *    TLS still validates the certificate for the host name),
 *  - redirects are followed here, hop by hop, each hop going through the same resolve / check / pin
 *    (WordPress would follow them itself and resolve again).
 *
 * Needs the cURL transport, which WordPress uses wherever cURL exists. Without it the request goes out
 * unpinned (still through `wp_safe_remote_request`, so the usual checks apply) - the gap then stays open.
 */
final class PinnedHttp {

	private const MAX_HOPS = 3;

	/** @var callable|null Test seam: fn( string $host ): string[]. */
	private static $resolver = null;

	/** @var callable|null Test seam replacing wp_safe_remote_request. */
	private static $transport = null;

	/**
	 * Replace DNS (tests).
	 *
	 * @param callable|null $resolver Resolver.
	 */
	public static function set_resolver( ?callable $resolver ): void {
		self::$resolver = $resolver;
	}

	/**
	 * Replace the HTTP call (tests).
	 *
	 * @param callable|null $transport fn( string $url, array $args ): array|WP_Error.
	 */
	public static function set_transport( ?callable $transport ): void {
		self::$transport = $transport;
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

	/**
	 * Every IP a host resolves to (a literal IP resolves to itself).
	 *
	 * @param string $host Host name or IP.
	 * @return string[]
	 */
	public static function resolve( string $host ): array {
		$host = trim( strtolower( $host ), '[]' );
		if ( null !== self::$resolver ) {
			return array_values( (array) ( self::$resolver )( $host ) );
		}
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
	 * Send a request, following up to three redirects, every hop checked and pinned.
	 *
	 * @param string               $method Method.
	 * @param string               $url    Address.
	 * @param array<string, mixed> $args   Arguments of wp_safe_remote_request().
	 * @param callable             $ip_ok  fn( string $ip ): bool - may the request go to this address?
	 * @return array<string, mixed>|WP_Error Response of the last hop.
	 */
	public static function request( string $method, string $url, array $args, callable $ip_ok ) {
		$args['method'] = strtoupper( $method );
		for ( $hop = 0; $hop <= self::MAX_HOPS; $hop++ ) {
			$parts  = wp_parse_url( $url );
			$scheme = strtolower( (string) ( $parts['scheme'] ?? '' ) );
			$host   = strtolower( trim( (string) ( $parts['host'] ?? '' ), '[]' ) );
			if ( ! in_array( $scheme, array( 'http', 'https' ), true ) || '' === $host ) {
				return new WP_Error( 'uxstudio_pinned_scheme', 'Only http:// and https:// addresses are allowed.' );
			}
			$port = (int) ( $parts['port'] ?? ( 'https' === $scheme ? 443 : 80 ) );

			$ips = self::resolve( $host );
			if ( array() === $ips ) {
				return new WP_Error( 'uxstudio_pinned_dns', 'The address cannot be resolved.' );
			}
			foreach ( $ips as $ip ) {
				if ( ! $ip_ok( $ip ) ) {
					return new WP_Error( 'uxstudio_pinned_blocked', 'This address points to the server itself or to a private network, so it is blocked.' );
				}
			}
			// IPv4 first (broadest support), else the first address.
			$pin = $ips[0];
			foreach ( $ips as $ip ) {
				if ( false !== filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) {
					$pin = $ip;
					break;
				}
			}

			$hook = static function ( $handle ) use ( $host, $port, $pin ): void {
				if ( defined( 'CURLOPT_RESOLVE' ) && ( is_resource( $handle ) || $handle instanceof \CurlHandle ) ) {
					$address = false !== strpos( $pin, ':' ) ? '[' . $pin . ']' : $pin;
					curl_setopt( $handle, CURLOPT_RESOLVE, array( $host . ':' . $port . ':' . $address ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_setopt
				}
			};
			$hop_args                = $args;
			$hop_args['redirection'] = 0;
			add_action( 'http_api_curl', $hook, 10, 1 );
			try {
				$response = null !== self::$transport ? ( self::$transport )( $url, $hop_args ) : wp_safe_remote_request( $url, $hop_args );
			} finally {
				remove_action( 'http_api_curl', $hook, 10 );
			}
			if ( is_wp_error( $response ) ) {
				return $response;
			}

			$code     = (int) wp_remote_retrieve_response_code( $response );
			$location = wp_remote_retrieve_header( $response, 'location' );
			$location = is_array( $location ) ? (string) reset( $location ) : (string) $location;
			if ( $code >= 300 && $code < 400 && '' !== $location && 304 !== $code ) {
				$url = self::absolute( $location, $url );
				if ( in_array( $code, array( 301, 302, 303 ), true ) && 'GET' !== $args['method'] && 'HEAD' !== $args['method'] ) {
					$args['method'] = 'GET';
					unset( $args['body'] );
				}
				continue;
			}
			return $response;
		}
		return new WP_Error( 'uxstudio_pinned_redirects', 'Too many redirects.' );
	}

	/**
	 * Absolute address of a Location header.
	 *
	 * @param string $location Location value.
	 * @param string $base     Address that answered.
	 */
	public static function absolute( string $location, string $base ): string {
		if ( 1 === preg_match( '#^https?://#i', $location ) ) {
			return $location;
		}
		$p      = wp_parse_url( $base );
		$origin = ( $p['scheme'] ?? 'http' ) . '://' . ( $p['host'] ?? '' ) . ( isset( $p['port'] ) ? ':' . $p['port'] : '' );
		if ( 0 === strpos( $location, '//' ) ) {
			return ( $p['scheme'] ?? 'http' ) . ':' . $location;
		}
		if ( 0 === strpos( $location, '/' ) ) {
			return $origin . $location;
		}
		$dir = isset( $p['path'] ) ? preg_replace( '#/[^/]*$#', '/', (string) $p['path'] ) : '/';
		return $origin . $dir . $location;
	}
}
