<?php
/**
 * Token scopes of the MCP server.
 *
 * @package UxStudio
 */

namespace UxStudio\Core\Mcp;

defined( 'ABSPATH' ) || exit;

/**
 * read       - inspect: modules, settings (without secrets), overlaps, migration state, logs.
 * write      - change modules and settings, ignore overlaps, redirects. Includes read.
 * migration  - run, verify, preview, switch and undo migrations from other plugins. Includes read.
 * admin      - everything above (and the only scope that may handle secrets).
 */
final class Scopes {

	public const READ      = 'read';
	public const WRITE     = 'write';
	public const MIGRATION = 'migration';
	public const ADMIN     = 'admin';

	/**
	 * All scopes in display order.
	 *
	 * @return string[]
	 */
	public static function all(): array {
		return array( self::READ, self::WRITE, self::MIGRATION, self::ADMIN );
	}

	/**
	 * Keep only known scopes, unique, in canonical order.
	 *
	 * @param mixed $scopes Raw list.
	 * @return string[]
	 */
	public static function normalize( $scopes ): array {
		$scopes = array_map( 'strval', is_array( $scopes ) ? $scopes : array() );
		return array_values( array_intersect( self::all(), $scopes ) );
	}

	/**
	 * Scopes a token effectively has (admin = all, write and migration include read).
	 *
	 * @param string[] $granted Scopes stored on the token.
	 * @return string[]
	 */
	public static function expand( array $granted ): array {
		$granted = self::normalize( $granted );
		if ( in_array( self::ADMIN, $granted, true ) ) {
			return self::all();
		}
		if ( $granted ) {
			$granted[] = self::READ;
		}
		return self::normalize( $granted );
	}

	/**
	 * Does a token with these scopes pass an ability that needs `$required`?
	 *
	 * @param string[] $granted  Scopes stored on the token.
	 * @param string   $required Scope the ability needs.
	 */
	public static function allows( array $granted, string $required ): bool {
		return in_array( $required, self::expand( $granted ), true );
	}
}
