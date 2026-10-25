<?php
/**
 * Removes the WordPress fingerprints from the page head and the HTTP headers.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\SecurityOptimization;

defined( 'ABSPATH' ) || exit;

/**
 * Generator (meta tag, feeds) is handled by "Hide WordPress version". This adds the discovery links WordPress
 * prints on every page: RSD, Windows Live Writer manifest, short link and the REST API address, the last two also
 * as `Link:` HTTP headers. Removing the REST link does NOT switch the REST API off, it only stops announcing it.
 *
 * Paths below wp-content are never touched.
 */
final class HeadLinks {

	/**
	 * Core callbacks to remove: hook, callback, priority (as WordPress registers them in default-filters.php).
	 *
	 * @return array<int, array{0:string,1:string,2:int}>
	 */
	public static function actions(): array {
		return array(
			array( 'wp_head', 'rsd_link', 10 ),
			array( 'wp_head', 'wlwmanifest_link', 10 ),
			array( 'wp_head', 'wp_shortlink_wp_head', 10 ),
			array( 'wp_head', 'rest_output_link_wp_head', 10 ),
			array( 'template_redirect', 'wp_shortlink_header', 11 ),
			array( 'template_redirect', 'rest_output_link_header', 11 ),
		);
	}

	/**
	 * Remove them (call from a hook that runs after WordPress registered its defaults).
	 */
	public static function register(): void {
		foreach ( self::actions() as $row ) {
			remove_action( $row[0], $row[1], $row[2] );
		}
	}
}
