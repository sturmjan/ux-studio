<?php
/**
 * Country-level IP blocking helper (fetches aggregated CIDR ranges per country).
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\SecurityOptimization;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * The site has no local GeoIP database - instead it downloads (and caches for
 * 7 days) the aggregated CIDR list for a country from ipdeny.com (free, no
 * API key). Ported 1:1 from the legacy CountryBlocklist.
 *
 * Source: https://www.ipdeny.com/ipblocks/
 */
final class CountryBlocklist {

	private const CACHE_PREFIX = 'uxstudio_ipban_cc_';
	private const CACHE_TTL    = 7 * DAY_IN_SECONDS;

	private const URL_V4 = 'https://www.ipdeny.com/ipblocks/data/aggregated/%s-aggregated.zone';
	private const URL_V6 = 'https://www.ipdeny.com/ipv6/ipaddresses/aggregated/%s-aggregated.zone';

	/**
	 * Download (and cache) CIDR ranges for a given ISO 3166-1 alpha-2 country code.
	 *
	 * @param string $code Two-letter country code, e.g. "CN".
	 * @return array|WP_Error List of CIDR strings, or error.
	 */
	public function fetch_ranges( string $code ) {
		$code = strtolower( preg_replace( '/[^a-z]/i', '', $code ) );
		if ( 2 !== strlen( $code ) ) {
			return new WP_Error( 'uxstudio_invalid_country', __( 'Invalid country code.', 'ux-studio' ) );
		}

		$cache_key = self::CACHE_PREFIX . $code;
		$cached    = get_transient( $cache_key );
		if ( is_array( $cached ) && ! empty( $cached ) ) {
			return $cached;
		}

		$cidrs = array();

		foreach ( array( self::URL_V4, self::URL_V6 ) as $i => $tpl ) {
			$url  = sprintf( $tpl, $code );
			$resp = wp_remote_get(
				$url,
				array(
					'timeout'    => 20,
					'user-agent' => 'UXStudio-Security/1.0',
				)
			);

			if ( is_wp_error( $resp ) ) {
				if ( 0 === $i ) {
					return new WP_Error(
						'uxstudio_download_failed',
						/* translators: %s: error message */
						sprintf( __( 'Could not download country ranges: %s', 'ux-studio' ), $resp->get_error_message() )
					);
				}
				continue;
			}

			$http_code = (int) wp_remote_retrieve_response_code( $resp );
			if ( 200 !== $http_code ) {
				if ( 0 === $i ) {
					return new WP_Error(
						'uxstudio_download_failed',
						/* translators: %d: HTTP status code */
						sprintf( __( 'Server returned error %d while downloading country ranges.', 'ux-studio' ), $http_code )
					);
				}
				continue;
			}

			$body = (string) wp_remote_retrieve_body( $resp );
			foreach ( preg_split( '/\r\n|\r|\n/', $body ) as $line ) {
				$line = trim( $line );
				if ( '' !== $line && '#' !== $line[0] && false !== strpos( $line, '/' ) ) {
					$cidrs[] = $line;
				}
			}
		}

		if ( empty( $cidrs ) ) {
			return new WP_Error( 'uxstudio_no_ranges', __( 'No ranges found for this country.', 'ux-studio' ) );
		}

		set_transient( $cache_key, $cidrs, self::CACHE_TTL );
		return $cidrs;
	}

	/**
	 * Human-readable country name for a code (or the code itself if unknown).
	 */
	public function get_country_name( string $code ): string {
		$code = strtoupper( $code );
		$list = self::get_countries();
		return $list[ $code ] ?? $code;
	}

	/**
	 * ISO 3166-1 alpha-2 => English name, for the select UI.
	 *
	 * @return array<string,string>
	 */
	public static function get_countries(): array {
		return array(
			'AF' => 'Afghanistan', 'AL' => 'Albania', 'DZ' => 'Algeria', 'AD' => 'Andorra',
			'AO' => 'Angola', 'AR' => 'Argentina', 'AM' => 'Armenia', 'AU' => 'Australia',
			'AT' => 'Austria', 'AZ' => 'Azerbaijan', 'BH' => 'Bahrain', 'BD' => 'Bangladesh',
			'BY' => 'Belarus', 'BE' => 'Belgium', 'BZ' => 'Belize', 'BJ' => 'Benin',
			'BO' => 'Bolivia', 'BA' => 'Bosnia and Herzegovina', 'BW' => 'Botswana', 'BR' => 'Brazil',
			'BN' => 'Brunei', 'BG' => 'Bulgaria', 'BF' => 'Burkina Faso', 'BI' => 'Burundi',
			'KH' => 'Cambodia', 'CM' => 'Cameroon', 'CA' => 'Canada', 'CL' => 'Chile',
			'CN' => 'China', 'CO' => 'Colombia', 'CR' => 'Costa Rica', 'HR' => 'Croatia',
			'CU' => 'Cuba', 'CY' => 'Cyprus', 'CZ' => 'Czechia', 'CD' => 'Congo (DR)',
			'DK' => 'Denmark', 'DO' => 'Dominican Republic', 'EC' => 'Ecuador', 'EG' => 'Egypt',
			'SV' => 'El Salvador', 'EE' => 'Estonia', 'ET' => 'Ethiopia', 'FI' => 'Finland',
			'FR' => 'France', 'GE' => 'Georgia', 'DE' => 'Germany', 'GH' => 'Ghana',
			'GR' => 'Greece', 'GT' => 'Guatemala', 'HN' => 'Honduras', 'HK' => 'Hong Kong',
			'HU' => 'Hungary', 'IS' => 'Iceland', 'IN' => 'India', 'ID' => 'Indonesia',
			'IR' => 'Iran', 'IQ' => 'Iraq', 'IE' => 'Ireland', 'IL' => 'Israel',
			'IT' => 'Italy', 'CI' => 'Ivory Coast', 'JM' => 'Jamaica', 'JP' => 'Japan',
			'JO' => 'Jordan', 'KZ' => 'Kazakhstan', 'KE' => 'Kenya', 'KP' => 'North Korea',
			'KR' => 'South Korea', 'KW' => 'Kuwait', 'KG' => 'Kyrgyzstan', 'LA' => 'Laos',
			'LV' => 'Latvia', 'LB' => 'Lebanon', 'LY' => 'Libya', 'LI' => 'Liechtenstein',
			'LT' => 'Lithuania', 'LU' => 'Luxembourg', 'MK' => 'North Macedonia', 'MG' => 'Madagascar',
			'MW' => 'Malawi', 'MY' => 'Malaysia', 'MV' => 'Maldives', 'ML' => 'Mali',
			'MT' => 'Malta', 'MR' => 'Mauritania', 'MX' => 'Mexico', 'MD' => 'Moldova',
			'MC' => 'Monaco', 'MN' => 'Mongolia', 'ME' => 'Montenegro', 'MA' => 'Morocco',
			'MZ' => 'Mozambique', 'MM' => 'Myanmar', 'NA' => 'Namibia', 'NP' => 'Nepal',
			'NL' => 'Netherlands', 'NZ' => 'New Zealand', 'NI' => 'Nicaragua', 'NE' => 'Niger',
			'NG' => 'Nigeria', 'NO' => 'Norway', 'OM' => 'Oman', 'PK' => 'Pakistan',
			'PA' => 'Panama', 'PG' => 'Papua New Guinea', 'PY' => 'Paraguay', 'PE' => 'Peru',
			'PH' => 'Philippines', 'PL' => 'Poland', 'PT' => 'Portugal', 'QA' => 'Qatar',
			'RO' => 'Romania', 'RU' => 'Russia', 'RW' => 'Rwanda', 'SA' => 'Saudi Arabia',
			'SN' => 'Senegal', 'RS' => 'Serbia', 'SG' => 'Singapore', 'SK' => 'Slovakia',
			'SI' => 'Slovenia', 'SO' => 'Somalia', 'ZA' => 'South Africa', 'SS' => 'South Sudan',
			'ES' => 'Spain', 'LK' => 'Sri Lanka', 'SD' => 'Sudan', 'SE' => 'Sweden',
			'CH' => 'Switzerland', 'SY' => 'Syria', 'TW' => 'Taiwan', 'TJ' => 'Tajikistan',
			'TZ' => 'Tanzania', 'TH' => 'Thailand', 'TG' => 'Togo', 'TT' => 'Trinidad and Tobago',
			'TN' => 'Tunisia', 'TR' => 'Turkey', 'TM' => 'Turkmenistan', 'UG' => 'Uganda',
			'UA' => 'Ukraine', 'AE' => 'United Arab Emirates', 'GB' => 'United Kingdom', 'US' => 'United States',
			'UY' => 'Uruguay', 'UZ' => 'Uzbekistan', 'VE' => 'Venezuela', 'VN' => 'Vietnam',
			'YE' => 'Yemen', 'ZM' => 'Zambia', 'ZW' => 'Zimbabwe',
		);
	}
}
