<?php
/**
 * Client IP resolution for rate limiting and visitor hashing.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\Analytics;

defined( 'ABSPATH' ) || exit;

/**
 * Proxy headers such as X-Forwarded-For can be set by anyone, so they are
 * only trusted when the request came from a known reverse proxy. Configure
 * trusted proxies via the `ux_studio/analytics/trusted_proxies` filter
 * (array of IPs). Without configuration, only REMOTE_ADDR is used.
 */
final class ClientIp {

	private const PROXY_HEADERS = array( 'HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP' );

	/**
	 * Resolve the client IP. Empty string if it cannot be determined.
	 */
	public static function get(): string {
		$remote = isset( $_SERVER['REMOTE_ADDR'] ) ? trim( (string) $_SERVER['REMOTE_ADDR'] ) : ''; // phpcs:ignore
		if ( ! filter_var( $remote, FILTER_VALIDATE_IP ) ) {
			$remote = '';
		}

		if ( '' === $remote || ! self::is_trusted_proxy( $remote ) ) {
			return $remote;
		}

		foreach ( self::PROXY_HEADERS as $header ) {
			if ( empty( $_SERVER[ $header ] ) ) {
				continue;
			}
			$value = (string) $_SERVER[ $header ]; // phpcs:ignore
			if ( false !== strpos( $value, ',' ) ) {
				$value = trim( explode( ',', $value )[0] );
			}
			$value = trim( $value );
			if ( filter_var( $value, FILTER_VALIDATE_IP ) ) {
				return $value;
			}
		}

		return $remote;
	}

	/**
	 * Whether the request comes from a configured reverse proxy.
	 */
	private static function is_trusted_proxy( string $remote ): bool {
		$trusted = array();
		if ( defined( 'UXSTUDIO_ANALYTICS_TRUSTED_PROXIES' ) ) {
			$raw     = constant( 'UXSTUDIO_ANALYTICS_TRUSTED_PROXIES' );
			$trusted = is_array( $raw ) ? $raw : array_map( 'trim', explode( ',', (string) $raw ) );
		}

		/**
		 * List of reverse proxy IPs whose forwarding headers should be trusted.
		 *
		 * @param array $trusted Array of IPs.
		 */
		$trusted = (array) apply_filters( 'ux_studio/analytics/trusted_proxies', $trusted );

		foreach ( $trusted as $proxy ) {
			if ( trim( (string) $proxy ) === $remote ) {
				return true;
			}
		}

		return false;
	}
}
