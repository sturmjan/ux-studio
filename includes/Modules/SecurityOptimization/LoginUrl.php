<?php
/**
 * Pure helper: where the login form and its links go once the custom login address is in force.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\SecurityOptimization;

defined( 'ABSPATH' ) || exit;

/**
 * wp-login.php builds the form action, "lost password", "register" and every redirect to the login with
 * site_url( 'wp-login.php?...' ). While the custom address is in force a direct request to wp-login.php is
 * denied, so all of those must point at the custom address, otherwise the form on the custom address posts to
 * a closed door (found live: the login page appeared, the login itself was refused).
 */
final class LoginUrl {

	private const FILE = 'wp-login.php';

	/**
	 * wp-login.php actions that ordinary visitors use and that must never be locked or rewritten to the secret
	 * address: the password form of a protected post.
	 */
	private const PUBLIC_ACTIONS = array( 'postpass' );

	/**
	 * Is this wp-login.php request (a site_url() path such as `wp-login.php?action=postpass`) a public action?
	 *
	 * @param string $path Path with optional query string.
	 */
	public static function is_public_action( string $path ): bool {
		$clean = ltrim( $path, '/' );
		if ( 0 !== strpos( $clean, self::FILE ) ) {
			return false;
		}
		$query = (string) parse_url( 'x://h/' . $clean, PHP_URL_QUERY ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- pure helper, no WordPress needed.
		parse_str( $query, $args );
		return isset( $args['action'] ) && is_string( $args['action'] ) && in_array( $args['action'], self::PUBLIC_ACTIONS, true );
	}

	/**
	 * Does the permalink structure give the custom address a route? An empty structure (plain `?p=123`) or
	 * `/index.php/...` (PATHINFO) does not serve `/my-login/`.
	 *
	 * @param string $structure Value of the permalink_structure option.
	 */
	public static function permalinks_are_pretty( string $structure ): bool {
		$structure = trim( $structure );
		return '' !== $structure && 0 !== strpos( $structure, '/index.php' ) && 0 !== strpos( $structure, 'index.php' );
	}

	/**
	 * Replace the wp-login.php part of a site_url() result.
	 *
	 * @param string $url      Result of site_url() (unused unless nothing to replace).
	 * @param string $path     Path argument of site_url(), e.g. `wp-login.php?action=lostpassword`.
	 * @param string $new_base Full custom login address, e.g. `https://example.com/blog/my-login/`.
	 * @return string The URL to use.
	 */
	public static function swap( string $url, string $path, string $new_base ): string {
		$clean = ltrim( $path, '/' );
		if ( '' === $new_base || 0 !== strpos( $clean, self::FILE ) ) {
			return $url;
		}
		$rest = substr( $clean, strlen( self::FILE ) );
		// Only the file itself (`wp-login.php`, optionally followed by a query or fragment), not `wp-login.php.bak`.
		if ( '' !== $rest && '?' !== $rest[0] && '#' !== $rest[0] ) {
			return $url;
		}
		return rtrim( $new_base, '/' ) . '/' . $rest;
	}
}
