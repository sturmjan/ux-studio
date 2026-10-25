<?php
/**
 * Pure rules of the Link Manager module (no WordPress calls, covered by tests/content-b3.php): the per-domain rules,
 * the list of domains that are never external, host matching and the classification of one address.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\LinkManager;

defined( 'ABSPATH' ) || exit;

/**
 * Rule lines look like `partner.example *.shop.example/affiliate/* tab=no rel=sponsored,nofollow icon=no`:
 * a pattern, then any of `tab=` (open in a new tab: yes / no), `rel=` (the exact rel tokens to add, `none` = no
 * rel) and `icon=` (yes / no). The first line whose pattern matches wins.
 */
final class LinkRules {

	/** rel tokens a rule may set. */
	public const REL_TOKENS = array( 'noopener', 'noreferrer', 'nofollow', 'sponsored', 'ugc', 'external' );

	/** The rel tokens added when nothing is configured (the behaviour before the rules existed). */
	public const DEFAULT_REL = array( 'noopener', 'noreferrer', 'nofollow' );

	/**
	 * Parse rule lines.
	 *
	 * @param string $text Textarea content.
	 * @return array{rules:array<int,array{pattern:string,tab:?bool,rel:?array,icon:?bool,line:int}>,errors:array<int,array{line:int,text:string}>}
	 */
	public static function parse_rules( string $text ): array {
		$rules  = array();
		$errors = array();
		foreach ( preg_split( '/\r\n|\r|\n/', $text ) ?: array() as $i => $raw ) {
			$line = trim( self::strip_comment( $raw ) );
			if ( '' === $line ) {
				continue;
			}
			$parts   = preg_split( '/\s+/', $line ) ?: array();
			$pattern = self::normalize_pattern( (string) array_shift( $parts ) );
			$rule    = array(
				'pattern' => $pattern,
				'tab'     => null,
				'rel'     => null,
				'icon'    => null,
				'line'    => $i + 1,
			);
			$ok      = '' !== $pattern;
			foreach ( $parts as $part ) {
				if ( false === strpos( $part, '=' ) ) {
					$ok = false;
					continue;
				}
				list( $key, $value ) = explode( '=', $part, 2 );
				$key                 = strtolower( trim( $key ) );
				$value               = strtolower( trim( $value ) );
				if ( 'tab' === $key || 'icon' === $key ) {
					$bool = self::parse_bool( $value );
					if ( null === $bool ) {
						$ok = false;
						continue;
					}
					$rule[ $key ] = $bool;
				} elseif ( 'rel' === $key ) {
					$rule['rel'] = self::parse_rel( $value );
					// Nothing recognised and not an explicit "none": a typo, not "no rel".
					if ( array() === $rule['rel'] && ! in_array( $value, array( 'none', '-', '' ), true ) ) {
						$ok = false;
					}
				} else {
					$ok = false;
				}
			}
			// A line with only a pattern changes nothing and is most likely a typo.
			if ( ! $ok || ( null === $rule['tab'] && null === $rule['rel'] && null === $rule['icon'] ) ) {
				$errors[] = array(
					'line' => $i + 1,
					'text' => trim( $raw ),
				);
				continue;
			}
			$rules[] = $rule;
		}
		return array(
			'rules'  => $rules,
			'errors' => $errors,
		);
	}

	/**
	 * Parse a list of patterns (one per line or separated by commas).
	 *
	 * @param string $text Textarea content.
	 * @return string[]
	 */
	public static function parse_patterns( string $text ): array {
		$out = array();
		foreach ( preg_split( '/[\r\n,]+/', $text ) ?: array() as $raw ) {
			$pattern = self::normalize_pattern( trim( self::strip_comment( $raw ) ) );
			if ( '' !== $pattern && ! in_array( $pattern, $out, true ) ) {
				$out[] = $pattern;
			}
		}
		return $out;
	}

	/**
	 * Remove a `#` comment (a `#` right after the host part would be a fragment, which never belongs to a pattern).
	 *
	 * @param string $line Raw line.
	 */
	private static function strip_comment( string $line ): string {
		$pos = strpos( $line, '#' );
		return false === $pos ? $line : substr( $line, 0, $pos );
	}

	/**
	 * Lower case, no scheme, no leading `www.`.
	 *
	 * @param string $pattern Raw pattern.
	 */
	public static function normalize_pattern( string $pattern ): string {
		$pattern = strtolower( trim( $pattern ) );
		$pattern = (string) preg_replace( '#^(https?:)?//#', '', $pattern );
		$pattern = (string) preg_replace( '#^www\.#', '', $pattern );
		return $pattern;
	}

	/**
	 * @param string $value yes / no and their aliases.
	 */
	private static function parse_bool( string $value ): ?bool {
		if ( in_array( $value, array( 'yes', 'ano', 'true', '1', 'new', '_blank', 'on' ), true ) ) {
			return true;
		}
		if ( in_array( $value, array( 'no', 'ne', 'false', '0', 'same', '_self', 'off' ), true ) ) {
			return false;
		}
		return null;
	}

	/**
	 * Parse a `rel=` value: tokens separated by commas, only known tokens survive, `none` or empty = no rel at all.
	 *
	 * @param string $value Value.
	 * @return string[]
	 */
	public static function parse_rel( string $value ): array {
		$out = array();
		foreach ( preg_split( '/[,\s]+/', strtolower( $value ) ) ?: array() as $token ) {
			if ( in_array( $token, self::REL_TOKENS, true ) && ! in_array( $token, $out, true ) ) {
				$out[] = $token;
			}
		}
		return $out;
	}

	/**
	 * Whether a pattern matches an address. A pattern with a slash is compared with `host/path` (anchored at the
	 * start), any other with the host. `*` stands for any characters. A bare `example.com` is the host
	 * `example.com` (and `www.example.com`), `*.example.com` its subdomains.
	 *
	 * @param string $pattern   Normalised pattern.
	 * @param string $host      Host of the address (lower case, no www).
	 * @param string $host_path `host/path?query` of the address (lower case, no www).
	 */
	public static function matches( string $pattern, string $host, string $host_path ): bool {
		if ( '' === $pattern ) {
			return false;
		}
		$subject = false !== strpos( $pattern, '/' ) ? $host_path : $host;
		if ( false === strpos( $pattern, '*' ) ) {
			// A path pattern without a star is a prefix on a whole path segment.
			if ( false !== strpos( $pattern, '/' ) ) {
				$p = rtrim( $pattern, '/' );
				return $subject === $p || 0 === strpos( $subject, $p . '/' ) || 0 === strpos( $subject, $p . '?' );
			}
			return $subject === $pattern;
		}
		$regex = '/^' . str_replace( '\*', '.*', preg_quote( $pattern, '/' ) ) . '$/s';
		return \UxStudio\Core\SafeRegex::match( $regex, $subject );
	}

	/**
	 * Split an address into the parts the rules look at.
	 *
	 * @param string $url Href.
	 * @return array{host:string,host_path:string,scheme:string}|null null = no host (relative address or not a web address).
	 */
	public static function parts( string $url ): ?array {
		$url = trim( $url );
		if ( 0 === strpos( $url, '//' ) ) {
			$url = 'https:' . $url;
		}
		$parsed = parse_url( $url );
		if ( ! is_array( $parsed ) || empty( $parsed['host'] ) ) {
			return null;
		}
		$host = (string) preg_replace( '#^www\.#', '', strtolower( (string) $parsed['host'] ) );
		$path = strtolower( (string) ( $parsed['path'] ?? '' ) );
		$path = '' === $path ? '/' : $path;
		return array(
			'host'      => $host,
			'host_path' => $host . $path . ( isset( $parsed['query'] ) ? '?' . strtolower( (string) $parsed['query'] ) : '' ),
			'scheme'    => strtolower( (string) ( $parsed['scheme'] ?? '' ) ),
		);
	}

	/**
	 * First rule that matches.
	 *
	 * @param array<int,array<string,mixed>> $rules     Parsed rules.
	 * @param string                         $host      Host.
	 * @param string                         $host_path Host and path.
	 * @return array<string,mixed>|null
	 */
	public static function find_rule( array $rules, string $host, string $host_path ): ?array {
		foreach ( $rules as $rule ) {
			if ( self::matches( (string) $rule['pattern'], $host, $host_path ) ) {
				return $rule;
			}
		}
		return null;
	}

	/**
	 * Whether the address is listed in the patterns.
	 *
	 * @param string[] $patterns  Patterns.
	 * @param string   $host      Host.
	 * @param string   $host_path Host and path.
	 */
	public static function listed( array $patterns, string $host, string $host_path ): bool {
		foreach ( $patterns as $pattern ) {
			if ( self::matches( (string) $pattern, $host, $host_path ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Registrable domain of a host (the last two labels, three after a second level such as co.uk): what "my
	 * subdomains" are measured against.
	 *
	 * @param string $host Host, lower case.
	 */
	public static function base_domain( string $host ): string {
		$host   = (string) preg_replace( '#^www\.#', '', strtolower( $host ) );
		$labels = explode( '.', $host );
		$n      = count( $labels );
		if ( $n <= 2 ) {
			return $host;
		}
		$second_level = array( 'co', 'com', 'org', 'net', 'gov', 'edu', 'ac' );
		$take         = in_array( $labels[ $n - 2 ], $second_level, true ) && strlen( $labels[ $n - 1 ] ) === 2 ? 3 : 2;
		return implode( '.', array_slice( $labels, -$take ) );
	}

	/**
	 * Classify one href.
	 *
	 * @param string   $href                 Href attribute.
	 * @param string   $home_host            Host of the site (lower case, no www).
	 * @param bool     $subdomains_internal  Treat subdomains of the site's domain as internal.
	 * @param string[] $internal_patterns    Patterns of addresses that are never external.
	 * @return array{kind:string,host:string,host_path:string}
	 *         kind: skip (not a web address: mailto, tel, javascript ...), anchor, internal, external.
	 */
	public static function classify( string $href, string $home_host, bool $subdomains_internal, array $internal_patterns ): array {
		$href  = trim( $href );
		$empty = array(
			'kind'      => 'internal',
			'host'      => '',
			'host_path' => '',
		);
		if ( '' === $href || 0 === strpos( $href, '#' ) ) {
			return array( 'kind' => 'anchor' ) + $empty;
		}
		if ( 1 === preg_match( '#^([a-z][a-z0-9+.\-]*):#i', $href, $m ) && ! in_array( strtolower( $m[1] ), array( 'http', 'https' ), true ) ) {
			return array( 'kind' => 'skip' ) + $empty;
		}
		$parts = self::parts( $href );
		if ( null === $parts ) {
			return $empty; // Relative address.
		}
		$host = $parts['host'];
		if ( $host === $home_host ) {
			return array( 'kind' => 'internal' ) + $parts;
		}
		if ( $subdomains_internal && '' !== $home_host ) {
			$base = self::base_domain( $home_host );
			if ( $host === $base || '.' . $base === substr( $host, -strlen( $base ) - 1 ) ) {
				return array( 'kind' => 'internal' ) + $parts;
			}
		}
		if ( self::listed( $internal_patterns, $host, $parts['host_path'] ) ) {
			return array( 'kind' => 'internal' ) + $parts;
		}
		return array( 'kind' => 'external' ) + $parts;
	}

	/**
	 * Add tokens to a rel attribute value without duplicates; existing values stay.
	 *
	 * @param string   $current Current rel attribute.
	 * @param string[] $tokens  Tokens to add.
	 */
	public static function merge_rel( string $current, array $tokens ): string {
		$list = '' === trim( $current ) ? array() : preg_split( '/\s+/', trim( $current ) );
		foreach ( $tokens as $token ) {
			if ( ! in_array( $token, $list, true ) ) {
				$list[] = $token;
			}
		}
		return implode( ' ', $list );
	}
}
