<?php
/**
 * Self-test: does the CSP header the plugin sends actually reach the browser?
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\SecurityOptimization;

defined( 'ABSPATH' ) || exit;

/**
 * Two things make a CSP silently useless and neither shows in PHP:
 *
 * 1. The server rewrites it - Hostinger/LiteSpeed replaces every policy with
 *    `upgrade-insecure-requests` padded with spaces to the original length.
 * 2. A page cache serves HTML without PHP running, so no header is sent.
 *
 * The probe loads the home page over HTTP like a visitor would: once with
 * the probe flag + a cache buster (proves the delivery path), once plain
 * (what a cached page looks like). Result is stored for the CSP tab and the
 * Enforce gate.
 */
final class CspHeaderProbe {

	public const OPTION = 'uxstudio_csp_probe';

	/** A passed probe is trusted for the gate this long. */
	public const VALID_FOR = 7 * DAY_IN_SECONDS;

	/**
	 * Run both requests and store the result.
	 *
	 * @param string $mode       Current CSP mode.
	 * @param int    $sample_pct Report-Only sampling percent (plain check only makes sense at 100).
	 * @return array<string,mixed> Stored result.
	 */
	public static function run( string $mode, int $sample_pct ): array {
		$home   = trailingslashit( home_url( '/' ) );
		$forced = add_query_arg(
			array(
				CspPolicy::PROBE_PARAM => CspPolicy::probe_token(),
				'_uxs'                 => wp_generate_password( 8, false ),
			),
			$home
		);

		$first  = self::fetch( $forced );
		$result = array(
			'checked_at' => time(),
			'mode'       => $mode,
			'ok'         => false,
			'reason'     => $first['reason'],
			'status'     => $first['status'],
			'seen'       => $first['value'],
			'cached_ok'  => null,
		);

		if ( 'ok' === $first['reason'] ) {
			$result['ok'] = true;

			// The plain URL can only be expected to carry the header when every
			// page view gets it: Enforce always, Report-Only at 100 % sampling.
			if ( 'enforce' === $mode || ( in_array( $mode, array( 'audit', 'report-only' ), true ) && $sample_pct >= 100 ) ) {
				$plain               = self::fetch( $home );
				$result['cached_ok'] = 'ok' === $plain['reason'];
			}
		}

		update_option( self::OPTION, $result, false );
		return $result;
	}

	/**
	 * @return array<string,mixed>
	 */
	public static function last(): array {
		$value = get_option( self::OPTION, array() );
		return is_array( $value ) ? $value : array();
	}

	/**
	 * Is there a passed probe recent enough for the Enforce gate?
	 */
	public static function passed_recently(): bool {
		$last = self::last();
		return ! empty( $last['ok'] ) && ( time() - (int) ( $last['checked_at'] ?? 0 ) ) < self::VALID_FOR;
	}

	/**
	 * @return array{reason:string,status:int,value:string}
	 */
	private static function fetch( string $url ): array {
		$response = wp_remote_get(
			$url,
			array(
				'timeout'     => 15,
				'redirection' => 3,
				// Loopback to our own host - same rule as core's Site Health loopback test.
				'sslverify'   => (bool) apply_filters( 'https_local_ssl_verify', false ),
				'user-agent'  => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0 Safari/537.36',
				'headers'     => array(
					'Accept'                    => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
					'Accept-Language'           => 'cs,en;q=0.8',
					'Upgrade-Insecure-Requests' => '1',
					'Sec-Fetch-Dest'            => 'document',
					'Sec-Fetch-Mode'            => 'navigate',
					'Sec-Fetch-Site'            => 'none',
				),
				'cookies'     => array(),
			)
		);

		if ( is_wp_error( $response ) ) {
			return array(
				'reason' => 'request_failed',
				'status' => 0,
				'value'  => substr( $response->get_error_message(), 0, 200 ),
			);
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$value  = '';
		foreach ( array( 'content-security-policy-report-only', 'content-security-policy' ) as $name ) {
			// Several headers of one name come back as an array.
			$value = trim( implode( ', ', (array) wp_remote_retrieve_header( $response, $name ) ) );
			if ( '' !== $value ) {
				break;
			}
		}

		if ( $status >= 400 ) {
			$reason = 'http_error';
		} elseif ( '' === $value ) {
			$reason = 'missing';
		} elseif ( ! str_contains( $value, 'csp-report' ) ) {
			// Our policy always ends with the report-uri; anything else means
			// something between PHP and the browser replaced it.
			$reason = 'rewritten';
		} else {
			$reason = 'ok';
		}

		return array(
			'reason' => $reason,
			'status' => $status,
			'value'  => substr( $value, 0, 300 ),
		);
	}
}
