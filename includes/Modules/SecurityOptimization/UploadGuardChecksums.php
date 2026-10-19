<?php
/**
 * Upload Guard checksums: compares plugin files with WordPress.org.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\SecurityOptimization;

defined( 'ABSPATH' ) || exit;

/**
 * Same idea as Wordfence / `wp plugin verify-checksums`: a plugin file whose
 * MD5 matches the official package of the installed version is clean, no
 * matter how many eval/exec words it contains. Only files that differ from
 * the package, or are not in it at all, are worth a heuristic look.
 *
 * Checksums come from downloads.wordpress.org/plugin-checksums/<slug>/<ver>.json
 * and are cached per slug+version in a transient for 30 days (PHP-family
 * entries only, a few hundred KB at most). Plugins not hosted on WordPress.org (premium,
 * custom) get a negative cache entry and fall back to heuristics.
 */
final class UploadGuardChecksums {

	public const MATCH        = 'match';
	public const MODIFIED     = 'modified';
	public const UNKNOWN      = 'unknown';
	public const UNVERIFIABLE = 'unverifiable';

	private const TRANSIENT_PREFIX = 'uxs_sec_cs_';
	/** Checksums of a released version never change - keep them a month. */
	private const CACHE_TTL = 30 * DAY_IN_SECONDS;

	/** "Not on WordPress.org" is re-checked weekly. */
	private const MISS_TTL = WEEK_IN_SECONDS;

	/** @var array<string,string>|null Plugin folder => installed version. */
	private ?array $versions = null;

	/** @var array<string,array<string,string>|false> In-request cache: "slug@ver" => path => md5 (false = not on wp.org). */
	private array $maps = array();

	/**
	 * Compare a file inside wp-content/plugins with the official package.
	 *
	 * @return array{state:string,plugin:string,version:string}
	 */
	public function verify( string $path ): array {
		$path        = wp_normalize_path( $path );
		$plugins_dir = wp_normalize_path( WP_PLUGIN_DIR ) . '/';
		$result      = array(
			'state'   => self::UNVERIFIABLE,
			'plugin'  => '',
			'version' => '',
		);

		if ( 0 !== strpos( $path, $plugins_dir ) ) {
			return $result;
		}
		$relative = substr( $path, strlen( $plugins_dir ) );
		$slash    = strpos( $relative, '/' );
		if ( false === $slash ) {
			return $result; // Single-file plugin: no folder, no package to compare with.
		}

		$slug    = substr( $relative, 0, $slash );
		$inner   = substr( $relative, $slash + 1 );
		$version = $this->versions()[ $slug ] ?? '';

		$result['plugin']  = $slug;
		$result['version'] = $version;
		if ( '' === $version ) {
			return $result;
		}

		$map = $this->checksum_map( $slug, $version );
		if ( false === $map ) {
			return $result;
		}

		if ( ! isset( $map[ $inner ] ) ) {
			$result['state'] = self::UNKNOWN;
			return $result;
		}

		$md5             = md5_file( $path );
		$result['state'] = ( false !== $md5 && in_array( $md5, explode( ',', $map[ $inner ] ), true ) ) ? self::MATCH : self::MODIFIED;
		return $result;
	}

	/**
	 * @return array<string,string>|false Inner path => comma-separated md5s, or false when unavailable.
	 */
	private function checksum_map( string $slug, string $version ) {
		$key = $slug . '@' . $version;
		if ( array_key_exists( $key, $this->maps ) ) {
			return $this->maps[ $key ];
		}

		$transient = self::TRANSIENT_PREFIX . md5( $key );
		$cached    = get_transient( $transient );
		if ( is_array( $cached ) && array_key_exists( 'files', $cached ) ) {
			$this->maps[ $key ] = is_array( $cached['files'] ) ? $cached['files'] : false;
			return $this->maps[ $key ];
		}

		$map = $this->fetch( $slug, $version );
		set_transient( $transient, array( 'files' => $map ), false === $map ? self::MISS_TTL : self::CACHE_TTL );
		$this->maps[ $key ] = $map;
		return $map;
	}

	/**
	 * @return array<string,string>|false
	 */
	private function fetch( string $slug, string $version ) {
		$url      = sprintf( 'https://downloads.wordpress.org/plugin-checksums/%s/%s.json', rawurlencode( $slug ), rawurlencode( $version ) );
		$response = wp_remote_get( $url, array( 'timeout' => 15 ) );
		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return false;
		}

		$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $data ) || ! is_array( $data['files'] ?? null ) ) {
			return false;
		}

		$map = array();
		foreach ( $data['files'] as $file => $sums ) {
			$ext = strtolower( pathinfo( (string) $file, PATHINFO_EXTENSION ) );
			if ( ! in_array( $ext, UploadGuardScope::PHP_EXTENSIONS, true ) ) {
				continue;
			}
			// Usually one hash; some entries list several valid ones.
			$valid = array_filter(
				array_map( 'strtolower', array_map( 'strval', (array) ( is_array( $sums ) ? ( $sums['md5'] ?? array() ) : array() ) ) ),
				static fn( string $md5 ): bool => 32 === strlen( $md5 )
			);
			if ( $valid ) {
				$map[ (string) $file ] = implode( ',', $valid );
			}
		}
		return $map;
	}

	/**
	 * @return array<string,string> Plugin folder => installed version.
	 */
	private function versions(): array {
		if ( null !== $this->versions ) {
			return $this->versions;
		}
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$this->versions = array();
		foreach ( get_plugins() as $file => $data ) {
			$slash = strpos( (string) $file, '/' );
			if ( false !== $slash ) {
				$this->versions[ substr( (string) $file, 0, $slash ) ] = (string) ( $data['Version'] ?? '' );
			}
		}
		return $this->versions;
	}
}
