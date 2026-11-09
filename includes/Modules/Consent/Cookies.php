<?php
/**
 * Cookie catalogue lookups: name patterns, services with their cookies, durations in words.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\Consent;

use UxStudio\Modules\SecurityOptimization\CspServices;

defined( 'ABSPATH' ) || exit;

/**
 * Pure logic (no WordPress calls) on top of the ONE shared catalogue, CspServices::consent_services(), with the bundled
 * Open Cookie Database (OpenCookieDb) as the fallback for what the catalogue does not list. Tested in tests/consent.php.
 *
 * Languages: `cs` and `en`. Anything but `cs` gets English. Czech texts exist for the main services only; for the rest
 * the English text of the database is shown.
 */
final class Cookies {

	private const COUNTRIES = array(
		'US' => array( 'USA', 'USA' ),
		'CZ' => array( 'Czechia', 'Česko' ),
		'SK' => array( 'Slovakia', 'Slovensko' ),
		'IE' => array( 'Ireland', 'Irsko' ),
		'MT' => array( 'Malta', 'Malta' ),
		'PL' => array( 'Poland', 'Polsko' ),
		'FR' => array( 'France', 'Francie' ),
		'NZ' => array( 'New Zealand', 'Nový Zéland' ),
		'DE' => array( 'Germany', 'Německo' ),
		'NL' => array( 'Netherlands', 'Nizozemsko' ),
		'GB' => array( 'United Kingdom', 'Spojené království' ),
	);

	private const TRANSFERS = array(
		'us'       => array( 'The provider relies on the EU-US Data Privacy Framework or standard contractual clauses.', 'Poskytovatel spoléhá na rámec EU-US Data Privacy Framework nebo standardní smluvní doložky.' ),
		'adequacy' => array( 'A country with an EU adequacy decision.', 'Země s rozhodnutím EU o odpovídající ochraně údajů.' ),
		'scc'      => array( 'Several countries, standard contractual clauses.', 'Více zemí, standardní smluvní doložky.' ),
		'eea'      => array( 'Stays in the European Economic Area.', 'Zůstává v Evropském hospodářském prostoru.' ),
	);

	/**
	 * Language code used for texts: `cs` for a Czech locale, otherwise `en`.
	 *
	 * @param string $locale WordPress locale such as cs_CZ.
	 */
	public static function lang( string $locale ): string {
		return 0 === strpos( strtolower( $locale ), 'cs' ) ? 'cs' : 'en';
	}

	/**
	 * Does a cookie name match a catalogue pattern? `*` matches anything, a pattern starting with `~` is a regular
	 * expression (written without delimiters), anything else must be equal. Case matters, as it does in browsers.
	 *
	 * @param string $pattern Pattern from the catalogue.
	 * @param string $name    Cookie name.
	 */
	public static function pattern_matches( string $pattern, string $name ): bool {
		if ( '' === $pattern || '' === $name ) {
			return false;
		}
		if ( '~' === $pattern[0] ) {
			// Catalogue regexes are written by us; the delimiter is escaped so that a stray one cannot end the pattern.
			return 1 === @preg_match( '#^(?:' . str_replace( '#', '\#', substr( $pattern, 1 ) ) . ')$#', $name );
		}
		if ( false === strpos( $pattern, '*' ) ) {
			return $pattern === $name;
		}
		return 1 === preg_match( '#^' . str_replace( '\*', '.*', preg_quote( $pattern, '#' ) ) . '$#', $name );
	}

	/**
	 * Duration code => words. `session`, `30min`, `13mo`, `2y`... A text that is not a code is returned as it is.
	 *
	 * @param string $code Duration code.
	 * @param string $lang cs | en.
	 */
	public static function duration( string $code, string $lang ): string {
		$code = trim( $code );
		if ( '' === $code ) {
			return '';
		}
		$cs = 'cs' === $lang;
		if ( 'session' === $code ) {
			return $cs ? 'do konce relace' : 'session';
		}
		if ( 1 !== preg_match( '/^(\d+)(s|min|h|d|mo|y)$/', $code, $m ) ) {
			return $code;
		}
		$n = (int) $m[1];
		if ( $cs ) {
			// one, 2-4, five and more.
			$forms = array(
				's'   => array( 'sekunda', 'sekundy', 'sekund' ),
				'min' => array( 'minuta', 'minuty', 'minut' ),
				'h'   => array( 'hodina', 'hodiny', 'hodin' ),
				'd'   => array( 'den', 'dny', 'dní' ),
				'mo'  => array( 'měsíc', 'měsíce', 'měsíců' ),
				'y'   => array( 'rok', 'roky', 'let' ),
			);
			$f    = $forms[ $m[2] ];
			return $n . ' ' . ( 1 === $n ? $f[0] : ( $n >= 2 && $n <= 4 ? $f[1] : $f[2] ) );
		}
		$names = array(
			's'   => 'second',
			'min' => 'minute',
			'h'   => 'hour',
			'd'   => 'day',
			'mo'  => 'month',
			'y'   => 'year',
		);
		return $n . ' ' . $names[ $m[2] ] . ( 1 === $n ? '' : 's' );
	}

	/**
	 * Text of a bilingual field for a language.
	 *
	 * @param array<string, string> $pair [ 'en' => ..., 'cs' => ... ].
	 * @param string                $lang cs | en.
	 */
	private static function pick( array $pair, string $lang ): string {
		return (string) ( $pair[ $lang ] ?? $pair['en'] ?? '' );
	}

	/**
	 * What a cookie is: from the catalogue, otherwise from the Open Cookie Database, otherwise null.
	 *
	 * @param string $name Cookie name as seen in the browser.
	 * @param string $lang cs | en.
	 * @return array{name:string, service:string, category:string, purpose:string, duration:string, duration_code:string, processor:string, source:string}|null
	 */
	public static function match( string $name, string $lang = 'en' ): ?array {
		foreach ( CspServices::consent_services() as $label => $service ) {
			foreach ( $service['cookies'] as $row ) {
				if ( self::pattern_matches( (string) $row[0], $name ) ) {
					return array(
						'name'          => $name,
						'service'       => (string) $label,
						'category'      => $service['category'],
						'purpose'       => 'cs' === $lang ? (string) $row[2] : (string) $row[1],
						'duration'      => self::duration( (string) $row[3], $lang ),
						'duration_code' => (string) $row[3],
						'processor'     => $service['processor'],
						'source'        => 'catalogue',
					);
				}
			}
		}
		$ocd = OpenCookieDb::lookup( $name );
		if ( null === $ocd ) {
			return null;
		}
		return array(
			'name'          => $name,
			'service'       => $ocd['platform'],
			'category'      => $ocd['category'],
			'purpose'       => $ocd['description'],
			'duration'      => self::duration( $ocd['retention'], $lang ),
			'duration_code' => $ocd['retention'],
			'processor'     => $ocd['controller'],
			'source'        => 'ocd',
		);
	}

	/**
	 * One service with its cookies, ready to show: the catalogue entry, or for a service with only `ocd` platforms the
	 * database rows (at most 12). Null for a label the catalogue does not know.
	 *
	 * @param string $label Service label.
	 * @param string $lang  cs | en.
	 * @return array{label:string, category:string, processor:string, country:string, country_name:string, transfer:string, transfer_text:string, policy:string, note:string, cookies:list<array{name:string, purpose:string, duration:string, source:string}>}|null
	 */
	public static function service( string $label, string $lang = 'en' ): ?array {
		$service = CspServices::consent_service( $label );
		if ( null === $service ) {
			return null;
		}
		$cookies = array();
		foreach ( $service['cookies'] as $row ) {
			$cookies[] = array(
				'name'     => (string) $row[0],
				'purpose'  => 'cs' === $lang ? (string) $row[2] : (string) $row[1],
				'duration' => self::duration( (string) $row[3], $lang ),
				'source'   => 'catalogue',
			);
		}
		if ( array() === $cookies && array() !== $service['ocd'] ) {
			foreach ( OpenCookieDb::platform_cookies( $service['ocd'] ) as $row ) {
				$cookies[] = array(
					'name'     => $row['name'] . ( $row['wildcard'] ? '*' : '' ),
					'purpose'  => $row['description'],
					'duration' => self::duration( $row['retention'], $lang ),
					'source'   => 'ocd',
				);
			}
		}
		$country = $service['country'];
		$names   = self::COUNTRIES[ $country ] ?? null;
		return array(
			'label'         => $label,
			'category'      => $service['category'],
			'processor'     => $service['processor'],
			'country'       => $country,
			'country_name'  => null !== $names ? $names['cs' === $lang ? 1 : 0] : $country,
			'transfer'      => $service['transfer'],
			'transfer_text' => isset( self::TRANSFERS[ $service['transfer'] ] ) ? self::TRANSFERS[ $service['transfer'] ]['cs' === $lang ? 1 : 0] : '',
			'policy'        => $service['policy'],
			'note'          => self::pick( $service['note'], $lang ),
			'cookies'       => $cookies,
		);
	}

	/**
	 * Services of a site by category, each with its cookies. Labels the catalogue does not know stay in the list
	 * with an empty cookie list (they are services the owner named by hand).
	 *
	 * @param array<string, list<string>> $labels category => service labels.
	 * @param string                      $lang   cs | en.
	 * @return array<string, list<array<string, mixed>>>
	 */
	public static function services_by_category( array $labels, string $lang = 'en' ): array {
		$out = array();
		foreach ( array( 'necessary', 'preferences', 'statistics', 'marketing' ) as $category ) {
			$out[ $category ] = array();
			foreach ( array_values( array_unique( $labels[ $category ] ?? array() ) ) as $label ) {
				$service = self::service( (string) $label, $lang );
				if ( null === $service ) {
					$service = array(
						'label'         => (string) $label,
						'category'      => $category,
						'processor'     => '',
						'country'       => '',
						'country_name'  => '',
						'transfer'      => '',
						'transfer_text' => '',
						'policy'        => '',
						'note'          => '',
						'cookies'       => array(),
					);
				}
				// A service is shown under the category the site gave it (a tag manager can sit under statistics or marketing).
				$service['category'] = $category;
				$out[ $category ][]  = $service;
			}
		}
		return $out;
	}

	/**
	 * Every service label the catalogue has a cookie list or a note for.
	 *
	 * @return list<string>
	 */
	public static function known_labels(): array {
		return array_keys( CspServices::consent_services() );
	}
}
