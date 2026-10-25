<?php
/**
 * Domain registration date via RDAP (the JSON successor of WHOIS).
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\SecurityOptimization;

defined( 'ABSPATH' ) || exit;

/**
 * rdap.org redirects to the registry of the TLD (rdap.nic.cz for .cz), no
 * key needed. Only ever called from the maintenance cron, never while a
 * report is received. Results are cached per registrable domain: 30 days
 * for an answer, 1 day for a failure (some TLDs have no RDAP at all).
 */
final class CspRdap {

	private const ENDPOINT    = 'https://rdap.org/domain/';
	private const CACHE_OK    = 30 * DAY_IN_SECONDS;
	private const CACHE_ERROR = DAY_IN_SECONDS;

	/**
	 * Registration timestamp of the domain, or 0 when unknown.
	 */
	public static function registered_at( string $domain ): int {
		$domain = strtolower( trim( $domain ) );
		if ( '' === $domain || ! preg_match( '/^[a-z0-9-]+(\.[a-z0-9-]+)+$/', $domain ) ) {
			return 0;
		}

		$key    = 'uxstudio_csp_rdap_' . md5( $domain );
		$cached = get_transient( $key );
		if ( false !== $cached ) {
			return (int) $cached;
		}

		$created = self::fetch( $domain );
		set_transient( $key, $created, $created > 0 ? self::CACHE_OK : self::CACHE_ERROR );
		return $created;
	}

	private static function fetch( string $domain ): int {
		$response = wp_safe_remote_get(
			self::ENDPOINT . rawurlencode( $domain ),
			array(
				'timeout'     => 6,
				'redirection' => 3,
				'headers'     => array( 'Accept' => 'application/rdap+json, application/json' ),
				'user-agent'  => 'UX Studio CSP (WordPress)',
			)
		);
		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return 0;
		}

		$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $data ) || ! is_array( $data['events'] ?? null ) ) {
			return 0;
		}

		foreach ( $data['events'] as $event ) {
			if ( is_array( $event ) && 'registration' === ( $event['eventAction'] ?? '' ) ) {
				$time = strtotime( (string) ( $event['eventDate'] ?? '' ) );
				return false === $time ? 0 : $time;
			}
		}
		return 0;
	}
}
