<?php
/**
 * Maps a script or iframe URL to a consent category.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\Consent;

use UxStudio\Modules\SecurityOptimization\CspServices;

defined( 'ABSPATH' ) || exit;

/**
 * Pure logic, no WordPress calls, so it is unit-tested without a site (tests/consent.php).
 *
 * The hosts and their categories come from CspServices::consent_catalogue() (PLAN.md 42.2: one catalogue). This class adds
 * what a host alone cannot tell: the path (a Google tag that carries a G- id is statistics, an AW- id is marketing),
 * the visitor's own rules, and the first-party exemption.
 */
final class Services {

	/** Consent categories a visitor can decide about (`necessary` is always on and has no switch). */
	public const CATEGORIES = array( 'preferences', 'statistics', 'marketing' );

	/**
	 * Parse the "own rules" textarea. One rule per line: `<pattern> <category>`. A pattern without a slash is a host
	 * (matches it and its subdomains), a pattern with a slash is a part of the URL. Category: preferences, statistics,
	 * marketing, or `necessary` (never block). Lines that do not parse are ignored.
	 *
	 * @param string $text Raw textarea.
	 * @return list<array{pattern:string, category:string}>
	 */
	public static function parse_rules( string $text ): array {
		$rules = array();
		foreach ( preg_split( '/\R/', $text ) ?: array() as $line ) {
			$line = trim( $line );
			if ( '' === $line || '#' === $line[0] ) {
				continue;
			}
			$parts = preg_split( '/\s+/', $line );
			if ( ! is_array( $parts ) || 2 !== count( $parts ) ) {
				continue;
			}
			$category = strtolower( (string) end( $parts ) );
			if ( ! in_array( $category, array_merge( self::CATEGORIES, array( 'necessary' ) ), true ) ) {
				continue;
			}
			$pattern = strtolower( (string) $parts[0] );
			if ( 1 !== preg_match( '#^[a-z0-9._~:/?&=%@+\-]{2,200}$#', $pattern ) ) {
				continue;
			}
			$rules[] = array(
				'pattern'  => $pattern,
				'category' => $category,
			);
		}
		return array_slice( $rules, 0, 100 );
	}

	/**
	 * The own rule that decides a host (a host pattern equal to the host or one of its parent domains), or null.
	 * Path patterns do not count: a host alone cannot tell.
	 *
	 * @param string                                       $host  Host name.
	 * @param list<array{pattern:string, category:string}> $rules Parsed own rules.
	 * @return array{pattern:string, category:string}|null
	 */
	public static function rule_for_host( string $host, array $rules ): ?array {
		$host = strtolower( trim( $host, '. ' ) );
		if ( '' === $host ) {
			return null;
		}
		foreach ( $rules as $rule ) {
			$pattern = $rule['pattern'];
			if ( false === strpos( $pattern, '/' ) && ( $host === $pattern || str_ends_with( $host, '.' . $pattern ) ) ) {
				return $rule;
			}
		}
		return null;
	}

	/**
	 * Consent category of a URL, or null when the URL needs no consent (first party, relative, unknown host, or an
	 * own rule that says `necessary`).
	 *
	 * `category` is one name or several separated by a space: the content may load when ANY of them is allowed (a tag
	 * manager container serves both statistics and marketing tags).
	 *
	 * @param string                                          $url       Absolute, protocol-relative or relative URL.
	 * @param string                                          $site_host Host of the site itself (never blocked).
	 * @param list<array{pattern:string, category:string}>    $rules     Parsed own rules.
	 * @return array{category:string, label:string, gcm:bool}|null
	 */
	public static function classify( string $url, string $site_host = '', array $rules = array() ): ?array {
		$url = trim( html_entity_decode( $url, ENT_QUOTES ) );
		if ( 0 === strpos( $url, '//' ) ) {
			$url = 'https:' . $url;
		}
		$parts = parse_url( $url );
		$host  = is_array( $parts ) && isset( $parts['host'] ) ? strtolower( (string) $parts['host'] ) : '';
		$path  = is_array( $parts ) ? (string) ( $parts['path'] ?? '' ) : '';
		$lower = strtolower( $url );

		// Own rules win over the catalogue (first match). They may also name a path of the site itself.
		foreach ( $rules as $rule ) {
			$pattern = $rule['pattern'];
			$hit     = false !== strpos( $pattern, '/' )
				? false !== strpos( $lower, $pattern )
				: ( '' !== $host && ( $host === $pattern || str_ends_with( $host, '.' . $pattern ) ) );
			if ( $hit ) {
				if ( 'necessary' === $rule['category'] ) {
					return null;
				}
				$known = '' !== $host ? CspServices::consent_for_host( $host ) : null;
				return array(
					'category' => $rule['category'],
					'label'    => null !== $known ? $known['label'] : ( '' !== $host ? $host : $pattern ),
					'gcm'      => false,
				);
			}
		}

		if ( '' === $host || '' !== $site_host && ( $host === $site_host || str_ends_with( $host, '.' . $site_host ) ) ) {
			return null;
		}

		// Google embeds live on google.com itself (reCAPTCHA does too and is necessary): decide by path. A host the
		// catalogue lists on its own (analytics.google.com) is decided by the catalogue.
		if ( ( 'google.com' === $host || str_ends_with( $host, '.google.com' ) || str_ends_with( $host, '.google.cz' ) || 'google.cz' === $host ) && null === CspServices::consent_for_host( $host ) ) {
			if ( 0 === strpos( $path, '/maps' ) || 0 === strpos( $host, 'maps.' ) ) {
				return array(
					'category' => 'marketing',
					'label'    => 'Google Maps',
					'gcm'      => false,
				);
			}
			return null;
		}

		$known = CspServices::consent_for_host( $host );
		if ( null === $known ) {
			return null;
		}
		$category = $known['category'];
		$label    = $known['label'];

		// Google tag manager family: the id says what the tag is for, and so which service (and which cookies) it is.
		if ( 'googletagmanager.com' === $known['domain'] ) {
			if ( false !== strpos( $path, '/gtag/js' ) ) {
				$query = is_array( $parts ) ? (string) ( $parts['query'] ?? '' ) : '';
				parse_str( html_entity_decode( $query ), $args );
				$id = strtoupper( (string) ( $args['id'] ?? '' ) );
				$category = ( 0 === strpos( $id, 'G-' ) || 0 === strpos( $id, 'GT-' ) || 0 === strpos( $id, 'UA-' ) ) ? 'statistics' : 'marketing';
				$label    = 'statistics' === $category ? 'Google Analytics' : 'Google Ads';
			} else {
				// gtm.js / ns.html: a container can hold both kinds of tags.
				$category = 'statistics marketing';
			}
		}

		return array(
			'category' => $category,
			'label'    => $label,
			'gcm'      => $known['gcm'],
		);
	}

	/**
	 * Category of a hard-coded inline snippet by its known signatures (page-buffer safety net only), or null.
	 *
	 * @param string $code Script body.
	 * @return array{category:string, label:string, gcm:bool}|null
	 */
	public static function classify_inline( string $code ): ?array {
		foreach ( CspServices::consent_inline_signatures() as $regex => $host ) {
			if ( 1 === preg_match( $regex, $code ) ) {
				$known = CspServices::consent_for_host( $host );
				if ( null === $known ) {
					continue;
				}
				$category = $known['category'];
				$label    = $known['label'];
				if ( 'googletagmanager.com' === $known['domain'] ) {
					// A bare gtag config / gtm snippet: statistics unless the snippet names an Ads id.
					$category = 1 === preg_match( '/[\'"](?:AW|DC)-/i', $code ) ? 'marketing' : ( 1 === preg_match( '#googletagmanager\.com/gtm#i', $code ) ? 'statistics marketing' : 'statistics' );
					$label    = 'marketing' === $category ? 'Google Ads' : ( 'statistics' === $category ? 'Google Analytics' : $known['label'] );
				}
				return array(
					'category' => $category,
					'label'    => $label,
					'gcm'      => $known['gcm'],
				);
			}
		}
		return null;
	}
}
