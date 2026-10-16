<?php
/**
 * URL normalisation shared by the rule store, the front-end engine and the
 * 404 log, so a source saved by a human, an import or an AI agent always
 * produces the same match key as the live request.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\Redirects;

defined( 'ABSPATH' ) || exit;

/**
 * Match key = site-relative path, percent-decoded, lowercased, without a
 * trailing slash (except "/"), plus an optional query string with its params
 * sorted. Case and trailing slash are ignored on purpose: old sites often ran
 * on case-insensitive servers and search engines keep both variants indexed.
 */
final class Url {

	/** Paths a rule may never take over (would lock the admin out). */
	private const RESERVED = array( '/wp-admin', '/wp-login.php', '/wp-json', '/wp-cron.php', '/xmlrpc.php' );

	/**
	 * Subdirectory WordPress lives in ('' at the domain root, '/pobyty' locally).
	 */
	public static function home_path(): string {
		return rtrim( (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH ), '/' );
	}

	/**
	 * Host of this site, lowercase.
	 */
	public static function home_host(): string {
		return strtolower( (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST ) );
	}

	/**
	 * Split a source (full URL of the old or this site, or a path) into its
	 * normalised path and query. A full URL of THIS site loses the WordPress
	 * subdirectory; a foreign-host URL (the old domain) keeps its path as is.
	 *
	 * @param string $raw Source as typed.
	 * @return array{path:string, query:string}
	 */
	public static function parse_source( string $raw ): array {
		$parts = wp_parse_url( trim( $raw ) );
		if ( false === $parts ) {
			return array( 'path' => '', 'query' => '' );
		}
		$path = (string) ( $parts['path'] ?? '/' );
		if ( isset( $parts['host'] ) && strtolower( (string) $parts['host'] ) === self::home_host() ) {
			$path = self::strip_home_path( $path );
		}
		return array(
			'path'  => self::normalize_path( $path ),
			'query' => self::normalize_query( (string) ( $parts['query'] ?? '' ) ),
		);
	}

	/**
	 * The current request as [path, query] relative to the site root.
	 *
	 * @return array{path:string, query:string}
	 */
	public static function request(): array {
		$uri   = (string) wp_unslash( $_SERVER['REQUEST_URI'] ?? '/' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- normalised below, never output.
		$path  = (string) wp_parse_url( $uri, PHP_URL_PATH );
		$query = (string) wp_parse_url( $uri, PHP_URL_QUERY );
		return array(
			'path'  => self::normalize_path( self::strip_home_path( $path ) ),
			'query' => self::normalize_query( $query ),
		);
	}

	/**
	 * Remove the WordPress subdirectory prefix from a path.
	 *
	 * @param string $path Raw path.
	 */
	public static function strip_home_path( string $path ): string {
		$home = self::home_path();
		if ( '' !== $home && 0 === stripos( $path, $home ) ) {
			$rest = substr( $path, strlen( $home ) );
			if ( '' === $rest || '/' === $rest[0] ) {
				return '' === $rest ? '/' : $rest;
			}
		}
		return $path;
	}

	/**
	 * Normalise a path into its match form.
	 *
	 * @param string $path Raw path.
	 */
	public static function normalize_path( string $path ): string {
		$path = rawurldecode( $path );
		$path = '/' . ltrim( (string) preg_replace( '~/{2,}~', '/', $path ), '/' );
		if ( '/' !== $path ) {
			$path = rtrim( $path, '/' );
		}
		return mb_strtolower( $path, 'UTF-8' );
	}

	/**
	 * Normalise a query string: decoded params sorted so "?b=2&a=1" and
	 * "?a=1&b=2" are the same key. Keys are lowercased, values kept as is.
	 *
	 * @param string $query Raw query string (no leading "?").
	 */
	public static function normalize_query( string $query ): string {
		$pairs = array();
		foreach ( explode( '&', $query ) as $pair ) {
			if ( '' === $pair ) {
				continue;
			}
			$kv      = explode( '=', $pair, 2 );
			$pairs[] = strtolower( rawurldecode( str_replace( '+', ' ', $kv[0] ) ) ) . ( isset( $kv[1] ) ? '=' . rawurldecode( str_replace( '+', ' ', $kv[1] ) ) : '' );
		}
		sort( $pairs, SORT_STRING );
		return implode( '&', $pairs );
	}

	/**
	 * Match key for a path + normalised query.
	 *
	 * @param string $path  Normalised path.
	 * @param string $query Normalised query.
	 */
	public static function key( string $path, string $query = '' ): string {
		return '' === $query ? $path : $path . '?' . $query;
	}

	/**
	 * Index hash of a rule source (or target) key.
	 *
	 * @param string $match_type exact|regex|target.
	 * @param string $key        Match key or regex pattern.
	 */
	public static function hash( string $match_type, string $key ): string {
		return sha1( $match_type . '|' . $key );
	}

	/**
	 * Whether a normalised path belongs to WordPress internals.
	 *
	 * @param string $path Normalised path.
	 */
	public static function is_reserved( string $path ): bool {
		foreach ( self::RESERVED as $reserved ) {
			if ( $path === $reserved || str_starts_with( $path, $reserved . '/' ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Whether a target is a site-relative path or a URL on this host.
	 *
	 * @param string $url Target.
	 */
	public static function is_internal( string $url ): bool {
		if ( str_starts_with( $url, '/' ) && ! str_starts_with( $url, '//' ) ) {
			return true;
		}
		$host = wp_parse_url( $url, PHP_URL_HOST );
		return is_string( $host ) && strtolower( $host ) === self::home_host();
	}

	/**
	 * Site-relative form of an internal URL ("/kontakt/?a=1"), keeping the
	 * original case and slash so the visitor lands on the canonical address.
	 *
	 * @param string $url Internal target.
	 */
	public static function to_relative( string $url ): string {
		if ( str_starts_with( $url, '/' ) && ! str_starts_with( $url, '//' ) ) {
			return $url;
		}
		$parts = wp_parse_url( $url );
		$path  = self::strip_home_path( (string) ( $parts['path'] ?? '/' ) );
		return $path . ( isset( $parts['query'] ) ? '?' . $parts['query'] : '' ) . ( isset( $parts['fragment'] ) ? '#' . $parts['fragment'] : '' );
	}

	/**
	 * Absolute URL for a stored target (relative paths go through home_url so
	 * the WordPress subdirectory and current scheme are applied).
	 *
	 * @param string $target Stored target.
	 */
	public static function absolute( string $target ): string {
		return self::is_internal( $target ) && str_starts_with( $target, '/' ) ? home_url( $target ) : $target;
	}

	/**
	 * Match key of an internal target (for chain detection), or '' for
	 * external targets.
	 *
	 * @param string $target Stored target.
	 */
	public static function target_key( string $target ): string {
		if ( '' === $target || ! self::is_internal( $target ) ) {
			return '';
		}
		$relative = self::to_relative( $target );
		$parts    = wp_parse_url( $relative );
		return self::key(
			self::normalize_path( (string) ( $parts['path'] ?? '/' ) ),
			self::normalize_query( (string) ( $parts['query'] ?? '' ) )
		);
	}
}
