<?php
/**
 * PSR-4 autoloader for the UxStudio namespace.
 *
 * @package UxStudio
 */

namespace UxStudio;

defined( 'ABSPATH' ) || exit;

/**
 * Maps UxStudio\Foo\Bar to includes/Foo/Bar.php.
 */
final class Autoloader {

	/**
	 * Register the autoloader.
	 */
	public static function register(): void {
		spl_autoload_register( array( self::class, 'load' ) );
	}

	/**
	 * Load a class file for a UxStudio class name.
	 *
	 * @param string $class Fully qualified class name.
	 */
	public static function load( string $class ): void {
		if ( ! str_starts_with( $class, 'UxStudio\\' ) ) {
			return;
		}
		$relative = substr( $class, strlen( 'UxStudio\\' ) );
		$path     = UXSTUDIO_PATH . 'includes/' . str_replace( '\\', '/', $relative ) . '.php';
		if ( is_readable( $path ) ) {
			require_once $path;
		}
	}
}
