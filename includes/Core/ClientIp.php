<?php
/**
 * Shared client IP resolution.
 *
 * @package UxStudio
 */

namespace UxStudio\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Forwarding headers (CF-Connecting-IP, X-Forwarded-For) can be sent by anyone,
 * so they are only trusted when REMOTE_ADDR itself belongs to a proxy we trust:
 *
 * - `none`       REMOTE_ADDR only (unspoofable).
 * - `cloudflare` CF-Connecting-IP, but only when REMOTE_ADDR is a Cloudflare
 *                edge (built-in ranges) or a configured trusted proxy.
 * - `xff`        X-Forwarded-For walked from the RIGHT, skipping trusted hops
 *                (configured proxies + private/loopback ranges). The leftmost
 *                entries are client-controlled and never used blindly.
 * - `auto`       Headers only from configured trusted proxies / Cloudflare.
 *
 * Trusted proxies: constant UXSTUDIO_TRUSTED_PROXIES (array or comma list of
 * IPs/CIDRs) or the `uxstudio_trusted_proxies` filter.
 */
final class ClientIp {

	/**
	 * Cloudflare edge ranges (https://www.cloudflare.com/ips/). Filterable via
	 * `uxstudio_cloudflare_ranges`.
	 */
	private const CLOUDFLARE_RANGES = array(
		'173.245.48.0/20',
		'103.21.244.0/22',
		'103.22.200.0/22',
		'103.31.4.0/22',
		'141.101.64.0/18',
		'108.162.192.0/18',
		'190.93.240.0/20',
		'188.114.96.0/20',
		'197.234.240.0/22',
		'198.41.128.0/17',
		'162.158.0.0/15',
		'104.16.0.0/13',
		'104.24.0.0/14',
		'172.64.0.0/13',
		'131.0.72.0/22',
		'2400:cb00::/32',
		'2606:4700::/32',
		'2803:f800::/32',
		'2405:b500::/32',
		'2405:8100::/32',
		'2a06:98c0::/29',
		'2c0f:f248::/32',
	);

	/** Private / loopback ranges treated as trusted hops in `xff` mode. */
	private const PRIVATE_RANGES = array(
		'127.0.0.0/8',
		'10.0.0.0/8',
		'172.16.0.0/12',
		'192.168.0.0/16',
		'::1/128',
		'fc00::/7',
	);

	/**
	 * Resolve the client IP. Empty string if it cannot be determined.
	 *
	 * @param string $mode none|cloudflare|xff|auto.
	 */
	public static function get( string $mode = 'auto' ): string {
		$remote = self::remote_addr();
		if ( '' === $remote || 'none' === $mode ) {
			return $remote;
		}

		$configured = self::configured_proxies();

		if ( 'cloudflare' === $mode || 'auto' === $mode ) {
			$from_edge = self::in_ranges( $remote, self::cloudflare_ranges() ) || self::in_ranges( $remote, $configured );
			if ( $from_edge ) {
				$cf = self::header_ip( 'HTTP_CF_CONNECTING_IP' );
				if ( '' !== $cf ) {
					return $cf;
				}
			}
			if ( 'cloudflare' === $mode ) {
				return $remote;
			}
		}

		$trusted = 'xff' === $mode ? array_merge( $configured, self::PRIVATE_RANGES ) : $configured;
		if ( ! self::in_ranges( $remote, $trusted ) ) {
			return $remote;
		}

		$xff = isset( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ? (string) wp_unslash( $_SERVER['HTTP_X_FORWARDED_FOR'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		if ( '' === $xff ) {
			$real = self::header_ip( 'HTTP_X_REAL_IP' );
			return '' !== $real ? $real : $remote;
		}

		$hops = array_reverse( array_map( 'trim', explode( ',', $xff ) ) );
		foreach ( $hops as $hop ) {
			if ( ! filter_var( $hop, FILTER_VALIDATE_IP ) ) {
				// Malformed hop: stop walking, anything further left is untrustworthy.
				break;
			}
			if ( ! self::in_ranges( $hop, $trusted ) ) {
				return $hop;
			}
		}

		return $remote;
	}

	/**
	 * Whether an IP falls in any of the given IPs / CIDR ranges.
	 *
	 * @param string   $ip     IP address.
	 * @param string[] $ranges IPs or CIDRs.
	 */
	public static function in_ranges( string $ip, array $ranges ): bool {
		$bin = @inet_pton( $ip ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( false === $bin ) {
			return false;
		}
		foreach ( $ranges as $range ) {
			$range = trim( (string) $range );
			if ( '' === $range ) {
				continue;
			}
			if ( false === strpos( $range, '/' ) ) {
				if ( @inet_pton( $range ) === $bin ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
					return true;
				}
				continue;
			}
			list( $net, $bits ) = explode( '/', $range, 2 );
			$net_bin            = @inet_pton( $net ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			$bits               = (int) $bits;
			if ( false === $net_bin || strlen( $net_bin ) !== strlen( $bin ) || $bits < 0 || $bits > strlen( $bin ) * 8 ) {
				continue;
			}
			$full = intdiv( $bits, 8 );
			$rest = $bits % 8;
			if ( substr( $bin, 0, $full ) !== substr( $net_bin, 0, $full ) ) {
				continue;
			}
			if ( 0 === $rest ) {
				return true;
			}
			$mask = ( 0xFF << ( 8 - $rest ) ) & 0xFF;
			if ( ( ord( $bin[ $full ] ) & $mask ) === ( ord( $net_bin[ $full ] ) & $mask ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Cloudflare edges + configured proxies, e.g. for standalone scripts
	 * (AI Panel rescue.php) that mirror this logic without loading WordPress.
	 *
	 * @return string[]
	 */
	public static function trusted_proxy_ranges(): array {
		return array_values( array_unique( array_merge( self::cloudflare_ranges(), self::configured_proxies() ) ) );
	}

	private static function remote_addr(): string {
		$remote = isset( $_SERVER['REMOTE_ADDR'] ) ? trim( (string) $_SERVER['REMOTE_ADDR'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		return filter_var( $remote, FILTER_VALIDATE_IP ) ? $remote : '';
	}

	private static function header_ip( string $key ): string {
		if ( empty( $_SERVER[ $key ] ) ) {
			return '';
		}
		$value = trim( (string) wp_unslash( $_SERVER[ $key ] ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		return filter_var( $value, FILTER_VALIDATE_IP ) ? $value : '';
	}

	/**
	 * @return string[]
	 */
	private static function configured_proxies(): array {
		$trusted = array();
		foreach ( array( 'UXSTUDIO_TRUSTED_PROXIES', 'UXSTUDIO_ANALYTICS_TRUSTED_PROXIES' ) as $const ) {
			if ( defined( $const ) ) {
				$raw     = constant( $const );
				$trusted = array_merge( $trusted, is_array( $raw ) ? $raw : array_map( 'trim', explode( ',', (string) $raw ) ) );
			}
		}
		/** Legacy filter name kept for sites that configured Analytics already. */
		$trusted = (array) apply_filters( 'ux_studio/analytics/trusted_proxies', $trusted );
		/**
		 * Reverse proxy IPs / CIDRs whose forwarding headers are trusted.
		 *
		 * @param string[] $trusted IPs or CIDRs.
		 */
		return array_values( array_filter( array_map( 'strval', (array) apply_filters( 'uxstudio_trusted_proxies', $trusted ) ) ) );
	}

	/**
	 * @return string[]
	 */
	private static function cloudflare_ranges(): array {
		return (array) apply_filters( 'uxstudio_cloudflare_ranges', self::CLOUDFLARE_RANGES );
	}
}
