<?php
/**
 * PSR-4 autoloader for Ux1\AiCore (no Composer: CA has none, the plugin vendors this copy).
 *
 * @package Ux1\AiCore
 */

declare(strict_types=1);

spl_autoload_register(
	static function ( string $class ): void {
		$prefix = 'Ux1\\AiCore\\';
		if ( strncmp( $class, $prefix, strlen( $prefix ) ) !== 0 ) {
			return;
		}
		$file = __DIR__ . '/src/' . str_replace( '\\', '/', substr( $class, strlen( $prefix ) ) ) . '.php';
		if ( is_file( $file ) ) {
			require $file;
		}
	}
);
