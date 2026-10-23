<?php
/**
 * Local risk rating of a host reported by the browser (no network calls).
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\SecurityOptimization;

defined( 'ABSPATH' ) || exit;

/**
 * Allowlist thinking, not blocklist (PLAN-strazce-integrity.md): an
 * unknown host is reported as such even with no signal against it. Signals
 * only raise the priority. Domain age (RDAP) is added later by the
 * maintenance cron through with_domain_age().
 */
final class CspRisk {

	public const LEVEL_SELF    = 'self';
	public const LEVEL_KNOWN   = 'known';
	public const LEVEL_UNKNOWN = 'unknown';
	public const LEVEL_MEDIUM  = 'medium';
	public const LEVEL_HIGH    = 'high';

	private const MEDIUM_FROM = 20;
	private const HIGH_FROM   = 50;

	/** TLDs that dominate abuse statistics (cheap, often throw-away domains). */
	private const RISKY_TLDS = array( 'lol', 'top', 'xyz', 'icu', 'sbs', 'cfd', 'click', 'pw', 'tk', 'ml', 'ga', 'cf', 'gq', 'buzz', 'rest', 'monster', 'cyou', 'bond', 'quest', 'zip', 'mov', 'gdn', 'bar', 'surf', 'cam', 'boats', 'hair', 'beauty', 'autos', 'skin', 'mom', 'live', 'cc', 'su', 'ru' );

	/** Hosting of arbitrary content, tunnels and dynamic DNS - legit for developers, odd on a production site. */
	private const ODD_HOSTS = array( 'pastebin.com', 'raw.githubusercontent.com', 'gist.githubusercontent.com', 'ngrok.io', 'ngrok-free.app', 'ngrok.app', 'trycloudflare.com', 'duckdns.org', 'no-ip.com', 'ddns.net', 'serveo.net', 'loca.lt', 'glitch.me', 'workers.dev', 'pages.dev', 'netlify.app', 'vercel.app', 'firebaseapp.com', 'web.app', 'blogspot.com', '000webhostapp.com', 'repl.co' );

	/** Second-level labels under which registrations happen (co.uk, com.au...). */
	private const SECOND_LEVEL = array( 'co', 'com', 'net', 'org', 'gov', 'edu', 'ac' );

	/** Directives where a foreign origin can run code or carry data out. */
	private const ACTIVE_DIRECTIVES = array( 'script-src', 'connect-src', 'frame-src', 'worker-src', 'form-action', 'object-src' );

	/**
	 * Rate one reported resource.
	 *
	 * @param string $blocked_uri Reported blocked URI (or keyword: inline, eval, data...).
	 * @param string $directive   Effective directive.
	 * @param string $site_host   This site's host.
	 * @param string $sample      Script sample, if the browser sent one.
	 * @return array{level:string,score:int,reasons:array<int,string>,service:string}
	 */
	public static function rate( string $blocked_uri, string $directive, string $site_host, string $sample = '' ): array {
		$directive = CspPolicy::base_directive( $directive );
		$keyword   = strtolower( trim( $blocked_uri ) );

		if ( in_array( $keyword, array( 'inline', 'eval', 'wasm-eval' ), true ) ) {
			$reasons = array();
			$score   = 0;
			if ( '' !== $sample && preg_match( '/\b(eval|atob|fromCharCode|unescape)\s*\(|document\.write|\\\\x[0-9a-f]{2}/i', $sample ) ) {
				$score    += 30;
				$reasons[] = 'obfuscated_inline';
			}
			return self::result( $score, $reasons, '', self::LEVEL_UNKNOWN );
		}

		$parsed = wp_parse_url( $blocked_uri );
		$host   = is_array( $parsed ) ? strtolower( (string) ( $parsed['host'] ?? '' ) ) : '';
		$scheme = is_array( $parsed ) ? strtolower( (string) ( $parsed['scheme'] ?? '' ) ) : '';
		if ( '' === $host ) {
			// data:, blob: and friends - not an external origin.
			return self::result( 0, array(), '', self::LEVEL_UNKNOWN );
		}

		$site_host = strtolower( $site_host );
		$site_root = self::registrable_domain( $site_host );
		if ( $host === $site_host || ( '' !== $site_root && self::registrable_domain( $host ) === $site_root ) ) {
			return self::result( 0, array(), '', self::LEVEL_SELF );
		}

		$service = CspServices::label_for_host( $host );
		$reasons = array();
		$score   = 0;

		if ( 'http' === $scheme || 'ws' === $scheme ) {
			$score    += 20;
			$reasons[] = 'insecure_scheme';
		}

		if ( filter_var( $host, FILTER_VALIDATE_IP ) || str_starts_with( $host, '[' ) ) {
			$score    += 40;
			$reasons[] = 'ip_address';
		}

		if ( str_contains( $host, 'xn--' ) ) {
			$score    += 20;
			$reasons[] = 'punycode';
		}

		if ( '' === $service ) {
			$root = self::registrable_domain( $host );
			$tld  = (string) substr( (string) strrchr( $host, '.' ), 1 );

			if ( in_array( $tld, self::RISKY_TLDS, true ) ) {
				$score    += 30;
				$reasons[] = 'risky_tld';
			}

			foreach ( self::ODD_HOSTS as $odd ) {
				if ( $host === $odd || str_ends_with( $host, '.' . $odd ) ) {
					$score    += 30;
					$reasons[] = 'odd_host';
					break;
				}
			}

			$lookalike = self::lookalike_of( $root );
			if ( '' !== $lookalike ) {
				// A typo of a known name is a classic phishing/skimmer trick; the
				// same name under another TLD may just be a country version.
				$score    += str_starts_with( $lookalike, 'typo:' ) ? 50 : 25;
				$reasons[] = $lookalike;
			}

			if ( self::looks_random( $root ) ) {
				// Together with a risky TLD this is the throw-away domain pattern
				// (kjhg.lol from the seco-traktory incident): 30 + 20 = high.
				$score    += 20;
				$reasons[] = 'random_name';
			}
		}

		if ( ! in_array( $directive, self::ACTIVE_DIRECTIVES, true ) ) {
			// A foreign image/font/style can't run code; halve the weight.
			$score = (int) floor( $score / 2 );
		}

		$fallback = '' === $service ? self::LEVEL_UNKNOWN : self::LEVEL_KNOWN;
		return self::result( $score, $reasons, $service, $fallback );
	}

	/**
	 * Fold the registration date (RDAP) into an existing rating.
	 *
	 * @param array{level:string,score:int,reasons:array<int,string>,service:string} $rating    Rating from rate().
	 * @param int                                                                     $created   Registration timestamp, 0 = unknown.
	 * @param string                                                                  $directive Effective directive.
	 * @return array{level:string,score:int,reasons:array<int,string>,service:string}
	 */
	public static function with_domain_age( array $rating, int $created, string $directive ): array {
		if ( $created <= 0 || in_array( $rating['level'], array( self::LEVEL_SELF, self::LEVEL_KNOWN ), true ) ) {
			return $rating;
		}
		$age_days = (int) floor( ( time() - $created ) / DAY_IN_SECONDS );
		$add      = 0;
		if ( $age_days < 30 ) {
			$add                 = 40;
			$rating['reasons'][] = 'new_domain';
		} elseif ( $age_days < 180 ) {
			$add                 = 15;
			$rating['reasons'][] = 'young_domain';
		}
		if ( 0 === $add ) {
			return $rating;
		}
		if ( ! in_array( CspPolicy::base_directive( $directive ), self::ACTIVE_DIRECTIVES, true ) ) {
			$add = (int) floor( $add / 2 );
		}
		return self::result( $rating['score'] + $add, $rating['reasons'], $rating['service'], self::LEVEL_UNKNOWN );
	}

	/**
	 * @param array<int,string> $reasons Reason codes.
	 * @return array{level:string,score:int,reasons:array<int,string>,service:string}
	 */
	private static function result( int $score, array $reasons, string $service, string $fallback ): array {
		$level = $fallback;
		if ( $score >= self::HIGH_FROM ) {
			$level = self::LEVEL_HIGH;
		} elseif ( $score >= self::MEDIUM_FROM ) {
			$level = self::LEVEL_MEDIUM;
		}
		return array(
			'level'   => $level,
			'score'   => min( 100, $score ),
			'reasons' => array_values( array_unique( $reasons ) ),
			'service' => $service,
		);
	}

	/**
	 * Registrable domain (example.co.uk, example.cz) without a public-suffix
	 * list: last two labels, or three under a common second-level label.
	 */
	public static function registrable_domain( string $host ): string {
		$host = strtolower( trim( $host, '.' ) );
		if ( '' === $host || filter_var( $host, FILTER_VALIDATE_IP ) ) {
			return $host;
		}
		$labels = explode( '.', $host );
		$count  = count( $labels );
		if ( $count <= 2 ) {
			return $host;
		}
		$take = in_array( $labels[ $count - 2 ], self::SECOND_LEVEL, true ) ? 3 : 2;
		return implode( '.', array_slice( $labels, -$take ) );
	}

	/**
	 * Known service domain a host imitates, as `typo:<domain>`
	 * (gooogletagmanager.com) or `brand:<domain>` (stripe.co - same name,
	 * other TLD), or '' when it imitates none.
	 */
	public static function lookalike_of( string $root ): string {
		$name = self::name_part( $root );
		if ( strlen( $name ) < 5 ) {
			return '';
		}
		$brand = '';
		foreach ( array_keys( CspServices::catalogue() ) as $known ) {
			$known_name = self::name_part( $known );
			if ( strlen( $known_name ) < 5 ) {
				continue;
			}
			if ( $root === $known ) {
				return '';
			}
			if ( $name === $known_name ) {
				$brand = 'brand:' . $known;
				continue;
			}
			if ( abs( strlen( $name ) - strlen( $known_name ) ) <= 2 && levenshtein( $name, $known_name ) <= 2 ) {
				return 'typo:' . $known;
			}
		}
		return $brand;
	}

	private static function name_part( string $root ): string {
		$dot = strpos( $root, '.' );
		return false === $dot ? $root : substr( $root, 0, $dot );
	}

	/**
	 * Machine-generated looking name: long run of consonants, many digits
	 * mixed with letters, or no vowel at all in a 5+ character name.
	 */
	public static function looks_random( string $root ): bool {
		$name = self::name_part( $root );
		$name = str_replace( '-', '', $name );
		if ( strlen( $name ) < 5 ) {
			// kjhg.lol: four letters and no vowel is exactly the throw-away pattern.
			return strlen( $name ) >= 4 && ! preg_match( '/[aeiouy]/', $name );
		}
		if ( ! preg_match( '/[aeiouy]/', $name ) ) {
			return true;
		}
		if ( preg_match( '/[bcdfghjklmnpqrstvwxz]{5,}/', $name ) ) {
			return true;
		}
		$digits = (int) preg_match_all( '/\d/', $name );
		return $digits >= 3 && $digits < strlen( $name ) && preg_match( '/\d[a-z]+\d/', $name );
	}
}
