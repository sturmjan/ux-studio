<?php
/**
 * Upload Guard scope: what gets scanned, what is never touched.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\SecurityOptimization;

defined( 'ABSPATH' ) || exit;

/**
 * Single source of truth for paths. Two separate questions:
 *
 *  - area() / is_excluded(): is the file scanned at all, and how. Our own
 *    plugins and bundled third-party libraries are excluded in code (not via
 *    an empty-by-default filter): a scanner that reads its own signature file
 *    or Predis' docblocks only produces false alarms.
 *  - protection_reason(): may a human move this file to quarantine. Core,
 *    active plugins/themes, drop-ins and our own code are refused - moving a
 *    file the site loads turns a false positive into an outage (16.-17. 10.
 *    2026: auto-quarantine moved UploadGuardDetectionEngine.php itself and
 *    every request ended in a fatal error).
 */
final class UploadGuardScope {

	public const AREA_UPLOADS    = 'uploads';
	public const AREA_PLUGINS    = 'plugins';
	public const AREA_MU_PLUGINS = 'mu-plugins';
	public const AREA_THEMES     = 'themes';
	public const AREA_CONTENT    = 'wp-content';
	public const AREA_OTHER      = 'other';

	/** Directory names holding bundled third-party libraries. */
	private const LIBRARY_DIRS = array( 'vendor', 'vendor-prefixed', 'vendor_prefixed', 'node_modules', 'bower_components', 'dependencies' );

	/** Plugin folders that are ours (current and legacy). */
	private const OWN_PLUGINS = array( 'ux-studio', 'ux1-wordpress-customizer' );

	/** wp-content root files WordPress loads by itself. */
	private const DROPINS = array( 'advanced-cache.php', 'object-cache.php', 'db.php', 'db-error.php', 'install.php', 'maintenance.php', 'php-error.php', 'fatal-error-handler.php', 'sunrise.php', 'blog-deleted.php', 'blog-inactive.php', 'blog-suspended.php' );

	public const PHP_EXTENSIONS = array( 'php', 'phtml', 'phar', 'php3', 'php4', 'php5', 'php7', 'php8', 'pht', 'phps' );

	/** Non-PHP types only scanned in uploads (code folders are full of minified JS). */
	public const UPLOAD_EXTENSIONS = array( 'js', 'svg', 'html', 'htm' );

	/**
	 * Which part of the site a path belongs to.
	 */
	public static function area( string $path ): string {
		$path = wp_normalize_path( $path );
		foreach ( self::dirs()['areas'] as $area => $dir ) {
			if ( self::is_under( $path, $dir ) ) {
				return $area;
			}
		}
		return self::AREA_OTHER;
	}

	/**
	 * Files the scanner never reads: our own plugins, bundled libraries, the
	 * quarantine folder, plus anything added via the
	 * uxstudio_security_scan_whitelist_paths filter or the "Skipped paths"
	 * setting (upload_guard_whitelist_paths).
	 */
	public static function is_excluded( string $path ): bool {
		$path = wp_normalize_path( $path );
		$dirs = self::dirs();

		foreach ( $dirs['own'] as $dir ) {
			if ( self::is_under( $path, $dir ) ) {
				return true;
			}
		}

		if ( self::is_under( $path, $dirs['quarantine'] ) ) {
			return true;
		}

		// A library folder anywhere inside plugins/themes/mu-plugins.
		if ( in_array( self::area( $path ), array( self::AREA_PLUGINS, self::AREA_THEMES, self::AREA_MU_PLUGINS ), true ) ) {
			$segments = explode( '/', $path );
			array_pop( $segments ); // The file name itself.
			if ( array_intersect( array_map( 'strtolower', $segments ), self::LIBRARY_DIRS ) ) {
				return true;
			}
		}

		foreach ( $dirs['whitelist'] as $prefix ) {
			if ( 0 === strpos( $path, $prefix ) ) {
				return true;
			}
		}

		// Relative entries from the setting match whole path segments anywhere
		// ("plugins/cache-plugin" matches .../wp-content/plugins/cache-plugin/x.php
		// but not .../plugins/cache-plugin-pro/x.php).
		foreach ( $dirs['whitelist_relative'] as $fragment ) {
			if ( false !== strpos( $path . '/', '/' . $fragment . '/' ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Raw `upload_guard_whitelist_paths` value; until it is first saved in
	 * Studio, the migrated legacy `lwug_whitelist_paths`.
	 */
	public static function whitelist_setting(): string {
		$settings = (array) get_option( 'uxstudio_security_optimization', array() );
		$raw      = array_key_exists( 'upload_guard_whitelist_paths', $settings ) ? $settings['upload_guard_whitelist_paths'] : ( $settings['lwug_whitelist_paths'] ?? '' );
		return is_string( $raw ) ? $raw : '';
	}

	/**
	 * Split the "Skipped paths" setting into absolute prefixes and relative
	 * segment fragments. Entries that would skip everything ("/", "*", a bare
	 * drive) are ignored.
	 *
	 * @return array{absolute:string[],relative:string[]}
	 */
	private static function parse_whitelist_setting(): array {
		$absolute = array();
		$relative = array();
		foreach ( explode( "\n", str_replace( "\r", '', self::whitelist_setting() ) ) as $line ) {
			$line = trim( wp_normalize_path( trim( $line ) ) );
			if ( '' === $line || '#' === $line[0] || false !== strpos( $line, '*' ) || false !== strpos( $line, '..' ) ) {
				continue;
			}
			if ( '/' === $line[0] || 1 === preg_match( '#^[A-Za-z]:/#', $line ) ) {
				$line = untrailingslashit( $line );
				if ( strlen( $line ) > 3 ) {
					$absolute[] = $line;
				}
				continue;
			}
			$line = trim( $line, '/' );
			if ( '' !== $line ) {
				$relative[] = $line;
			}
		}
		return array(
			'absolute' => $absolute,
			'relative' => array_values( array_unique( $relative ) ),
		);
	}

	/**
	 * Whether a directory can be skipped entirely while walking the tree
	 * (cheaper than filtering every file below it). Decides by path string
	 * only, so the walker can call it for every entry without a stat().
	 */
	public static function is_excluded_dir( string $dir ): bool {
		$dir = wp_normalize_path( $dir );
		if ( in_array( strtolower( wp_basename( $dir ) ), self::LIBRARY_DIRS, true ) && self::AREA_UPLOADS !== self::area( $dir ) ) {
			return true;
		}
		return self::is_excluded( trailingslashit( $dir ) . '.dir' );
	}

	/**
	 * Is this file type scanned in this area?
	 */
	public static function is_relevant( string $path, string $area ): bool {
		$ext = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
		if ( in_array( $ext, self::PHP_EXTENSIONS, true ) ) {
			return true;
		}
		return self::AREA_UPLOADS === $area && in_array( $ext, self::UPLOAD_EXTENSIONS, true );
	}

	/**
	 * Why a file must not be moved to quarantine, or '' when it may be.
	 */
	public static function protection_reason( string $path ): string {
		$path = wp_normalize_path( $path );
		$real = realpath( $path );
		if ( false !== $real ) {
			$path = wp_normalize_path( $real );
		}

		foreach ( self::dirs()['own'] as $dir ) {
			if ( self::is_under( $path, $dir ) ) {
				return __( 'The file belongs to UX Studio itself.', 'ux-studio' );
			}
		}

		$content = wp_normalize_path( WP_CONTENT_DIR );
		if ( self::is_under( $path, wp_normalize_path( ABSPATH ) ) && ! self::is_under( $path, $content ) ) {
			return __( 'The file is part of WordPress core. Reinstall WordPress (Dashboard > Updates) instead of moving it.', 'ux-studio' );
		}

		if ( self::AREA_CONTENT === self::area( $path ) && in_array( wp_basename( $path ), self::DROPINS, true ) && dirname( $path ) === $content ) {
			return __( 'The file is a WordPress drop-in loaded on every request. Remove it manually once you are sure.', 'ux-studio' );
		}

		$plugin = self::active_plugin_for( $path );
		if ( '' !== $plugin ) {
			/* translators: %s: plugin folder or file name */
			return sprintf( __( 'The file belongs to the active plugin "%s". Deactivate the plugin first, or reinstall it from its source.', 'ux-studio' ), $plugin );
		}

		foreach ( array_unique( array( get_stylesheet_directory(), get_template_directory() ) ) as $theme_dir ) {
			if ( self::is_under( $path, wp_normalize_path( $theme_dir ) ) ) {
				return __( 'The file belongs to the active theme. Switch themes first, or reinstall the theme from its source.', 'ux-studio' );
			}
		}

		if ( in_array( $path, array_map( 'wp_normalize_path', get_included_files() ), true ) ) {
			return __( 'The file is loaded by the site right now.', 'ux-studio' );
		}

		return '';
	}

	/**
	 * Folder (or single-file name) of the active plugin that owns $path, or ''.
	 */
	private static function active_plugin_for( string $path ): string {
		$plugins_dir = wp_normalize_path( WP_PLUGIN_DIR );
		if ( ! self::is_under( $path, $plugins_dir ) ) {
			return '';
		}
		$relative = ltrim( substr( $path, strlen( $plugins_dir ) ), '/' );
		$slug     = strtok( $relative, '/' );
		$is_dir   = false !== strpos( $relative, '/' );

		$active = (array) get_option( 'active_plugins', array() );
		if ( is_multisite() ) {
			$active = array_merge( $active, array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) );
		}
		foreach ( $active as $plugin_file ) {
			$plugin_file = (string) $plugin_file;
			if ( $is_dir ? 0 === strpos( $plugin_file, $slug . '/' ) : $plugin_file === $relative ) {
				return (string) $slug;
			}
		}
		return '';
	}

	/**
	 * Normalized directories, resolved once per request - a full scan asks
	 * about tens of thousands of paths and each lookup would otherwise run
	 * the upload_dir/theme_root filters again.
	 *
	 * @return array{areas:array<string,string>,own:string[],quarantine:string,whitelist:string[],whitelist_relative:string[]}
	 */
	private static function dirs(): array {
		static $dirs = null;
		if ( null !== $dirs ) {
			return $dirs;
		}

		$uploads = wp_upload_dir( null, false );
		$areas   = array_filter(
			array(
				self::AREA_UPLOADS    => empty( $uploads['basedir'] ) ? '' : $uploads['basedir'],
				self::AREA_MU_PLUGINS => WPMU_PLUGIN_DIR,
				self::AREA_PLUGINS    => WP_PLUGIN_DIR,
				self::AREA_THEMES     => get_theme_root(),
				self::AREA_CONTENT    => WP_CONTENT_DIR,
			)
		);

		$own = array( UXSTUDIO_PATH );
		foreach ( self::OWN_PLUGINS as $slug ) {
			$own[] = WP_PLUGIN_DIR . '/' . $slug;
		}

		/**
		 * Extra path prefixes Upload Guard never scans.
		 *
		 * @param string[] $paths Absolute path prefixes.
		 */
		$whitelist = (array) apply_filters( 'uxstudio_security_scan_whitelist_paths', array() );
		$setting   = self::parse_whitelist_setting();

		$normalize = static fn( $dir ): string => untrailingslashit( wp_normalize_path( trim( (string) $dir ) ) );
		$dirs      = array(
			'areas'              => array_map( $normalize, $areas ),
			'own'                => array_values( array_unique( array_map( $normalize, $own ) ) ),
			'quarantine'         => $normalize( UploadGuardQuarantine::get_path() ),
			'whitelist'          => array_values( array_filter( array_map( $normalize, array_merge( $whitelist, $setting['absolute'] ) ) ) ),
			'whitelist_relative' => $setting['relative'],
		);
		return $dirs;
	}

	private static function is_under( string $path, string $dir ): bool {
		$dir = untrailingslashit( $dir );
		return '' !== $dir && 0 === strpos( $path, $dir . '/' );
	}
}
