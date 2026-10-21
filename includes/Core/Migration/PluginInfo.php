<?php
/**
 * Which foreign plugins are installed / active on this site.
 *
 * @package UxStudio
 */

namespace UxStudio\Core\Migration;

defined( 'ABSPATH' ) || exit;

/**
 * Looks plugins up by FOLDER slug (the same key the Catalog uses). Reads only
 * `get_plugins()` and the active list - never loads or activates anything.
 */
final class PluginInfo {

	/** @var array<string, array{basename:string,name:string,version:string,active:bool}>|null */
	private static ?array $cache = null;

	/** Forget the per-request cache. */
	public static function flush(): void {
		self::$cache = null;
		Overlaps::flush();
	}

	/**
	 * Installed plugins by folder slug.
	 *
	 * @return array<string, array{basename:string,name:string,version:string,active:bool}>
	 */
	public static function all(): array {
		if ( null !== self::$cache ) {
			return self::$cache;
		}
		if ( ! function_exists( 'get_plugins' ) && defined( 'ABSPATH' ) && is_readable( ABSPATH . 'wp-admin/includes/plugin.php' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$active = Overlaps::active_plugins();
		$out    = array();
		foreach ( function_exists( 'get_plugins' ) ? (array) get_plugins() : array() as $basename => $headers ) {
			$slug = Catalog::slug_of( (string) $basename );
			// The first file of a folder wins, an active one beats an inactive one.
			$is_active = in_array( (string) $basename, $active, true );
			if ( isset( $out[ $slug ] ) && ( $out[ $slug ]['active'] || ! $is_active ) ) {
				continue;
			}
			$out[ $slug ] = array(
				'basename' => (string) $basename,
				'name'     => (string) ( $headers['Name'] ?? '' ),
				'version'  => (string) ( $headers['Version'] ?? '' ),
				'active'   => $is_active,
			);
		}
		self::$cache = $out;
		return $out;
	}

	/**
	 * One plugin by slug, or null when it is not installed.
	 *
	 * @param string $slug Folder slug.
	 * @return array{basename:string,name:string,version:string,active:bool}|null
	 */
	public static function find( string $slug ): ?array {
		return self::all()[ $slug ] ?? null;
	}
}
