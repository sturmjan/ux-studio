<?php
/**
 * WordPress.org plugin/theme info client for the Rollback Manager module.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\RollbackManager;

defined( 'ABSPATH' ) || exit;

/**
 * Thin wrapper around WordPress core's own plugins_api()/themes_api() (the
 * exact functions core uses on the Add Plugins / Add Themes screens). These
 * already talk to the JSON-based https://api.wordpress.org/*\/info/1.2/
 * endpoints via wp_remote_get(), so there is no need to hand-roll HTTP calls
 * or fall back to unserialize() of a remote response.
 *
 * CRITICAL SECURITY RULE: every download URL returned from here is checked
 * against a strict allow-list (host must be exactly downloads.wordpress.org
 * over https) before it is ever handed back to a caller. Callers must not
 * trust any other source for a package URL - never a client-supplied one.
 */
final class WordPressApi {

	private const ALLOWED_DOWNLOAD_HOST = 'downloads.wordpress.org';

	/**
	 * Raw wordpress.org info for a plugin or theme.
	 *
	 * @param string $type 'plugin'|'theme'.
	 * @param string $slug Plugin/theme slug.
	 * @return object|null
	 */
	public static function get_info( string $type, string $slug ) {
		if ( '' === $slug ) {
			return null;
		}

		$fields = array(
			'versions'      => true,
			'sections'      => false,
			'tags'          => false,
			'ratings'       => false,
			'reviews'       => false,
			'banners'       => false,
			'icons'         => false,
			'compatibility' => false,
			'description'   => false,
		);

		if ( 'plugin' === $type ) {
			if ( ! function_exists( 'plugins_api' ) ) {
				require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
			}
			$res = plugins_api(
				'plugin_information',
				array(
					'slug'   => $slug,
					'fields' => $fields,
				)
			);
		} elseif ( 'theme' === $type ) {
			if ( ! function_exists( 'themes_api' ) ) {
				require_once ABSPATH . 'wp-admin/includes/theme.php';
			}
			$res = themes_api(
				'theme_information',
				array(
					'slug'   => $slug,
					'fields' => $fields,
				)
			);
		} else {
			return null;
		}

		if ( is_wp_error( $res ) || ! is_object( $res ) ) {
			return null;
		}

		return $res;
	}

	/**
	 * Whether the given slug corresponds to a package actually hosted on
	 * wordpress.org (i.e. its current download link resolves to
	 * downloads.wordpress.org).
	 *
	 * @param string $type 'plugin'|'theme'.
	 * @param string $slug Plugin/theme slug.
	 */
	public static function is_from_wordpress_org( string $type, string $slug ): bool {
		$info = self::get_info( $type, $slug );
		if ( ! $info || empty( $info->download_link ) ) {
			return false;
		}
		return self::is_allowed_download_url( (string) $info->download_link );
	}

	/**
	 * Available versions for a plugin/theme, newest first. Only versions
	 * whose download URL resolves to the allowed wordpress.org host are
	 * returned; anything else (should never happen for a genuine wp.org
	 * response, but we do not trust it blindly) is silently dropped.
	 *
	 * @param string $type 'plugin'|'theme'.
	 * @param string $slug Plugin/theme slug.
	 * @return array<int, array{version:string, download_url:string}>
	 */
	public static function get_versions( string $type, string $slug ): array {
		$info = self::get_info( $type, $slug );
		if ( ! $info ) {
			return array();
		}

		$raw = array();
		if ( ! empty( $info->versions ) && is_array( $info->versions ) ) {
			$raw = $info->versions;
		} elseif ( ! empty( $info->version ) && ! empty( $info->download_link ) ) {
			$raw = array( (string) $info->version => (string) $info->download_link );
		}

		$versions = array();
		foreach ( $raw as $version => $download_url ) {
			$version = (string) $version;
			if ( '' === $version || 'trunk' === $version ) {
				continue;
			}
			$download_url = (string) $download_url;
			if ( '' === $download_url || ! self::is_allowed_download_url( $download_url ) ) {
				continue;
			}
			$versions[] = array(
				'version'      => $version,
				'download_url' => $download_url,
			);
		}

		usort(
			$versions,
			static function ( array $a, array $b ): int {
				return version_compare( $b['version'], $a['version'] );
			}
		);

		return $versions;
	}

	/**
	 * Resolve the (already host-validated) download URL for one specific
	 * version, or null if that version is not actually offered by
	 * wordpress.org for this slug. The version list is always re-fetched
	 * from wordpress.org here - a client can only ever request a version
	 * string, never a URL.
	 *
	 * @param string $type    'plugin'|'theme'.
	 * @param string $slug    Plugin/theme slug.
	 * @param string $version Requested version string.
	 */
	public static function resolve_download_url( string $type, string $slug, string $version ): ?string {
		foreach ( self::get_versions( $type, $slug ) as $entry ) {
			if ( $entry['version'] === $version ) {
				return self::is_allowed_download_url( $entry['download_url'] ) ? $entry['download_url'] : null;
			}
		}
		return null;
	}

	/**
	 * Strict allow-list check: only https://downloads.wordpress.org/... is
	 * ever considered a legitimate package URL.
	 *
	 * @param string $url Candidate download URL.
	 */
	public static function is_allowed_download_url( string $url ): bool {
		$scheme = wp_parse_url( $url, PHP_URL_SCHEME );
		$host   = wp_parse_url( $url, PHP_URL_HOST );
		return 'https' === $scheme && self::ALLOWED_DOWNLOAD_HOST === $host;
	}
}
