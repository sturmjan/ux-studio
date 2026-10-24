<?php
/**
 * List of finished migration runs (what the wizard shows as "run history").
 *
 * @package UxStudio
 */

namespace UxStudio\Core\Migration;

defined( 'ABSPATH' ) || exit;

/**
 * One option, newest first, capped. Only real runs are listed; dry runs leave no trace here.
 */
final class History {

	private const OPTION = 'uxstudio_migration_runs';
	private const MAX    = 30;

	/**
	 * All remembered runs, newest first.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function all(): array {
		return array_values( array_filter( (array) get_option( self::OPTION, array() ), 'is_array' ) );
	}

	/**
	 * Add (or replace, same id) a run record.
	 *
	 * @param array<string, mixed> $run Record with at least `id`.
	 */
	public static function put( array $run ): void {
		$list = array_values(
			array_filter(
				self::all(),
				static fn( array $r ): bool => ( $r['id'] ?? '' ) !== ( $run['id'] ?? '' )
			)
		);
		array_unshift( $list, $run );
		update_option( self::OPTION, array_slice( $list, 0, self::MAX ), false );
		// A migration ran: the Modules screen hint (cached count) is obsolete.
		if ( function_exists( 'delete_transient' ) ) {
			AutoStart::flush_hint_cache();
		}
	}

	/**
	 * Merge fields into an existing record.
	 *
	 * @param string               $id     Run id.
	 * @param array<string, mixed> $fields Fields to set.
	 */
	public static function update( string $id, array $fields ): void {
		$list    = self::all();
		$changed = false;
		foreach ( $list as $i => $run ) {
			if ( ( $run['id'] ?? '' ) === $id ) {
				$list[ $i ] = array_merge( $run, $fields );
				$changed    = true;
			}
		}
		if ( $changed ) {
			update_option( self::OPTION, $list, false );
		}
	}

	/**
	 * One record.
	 *
	 * @param string $id Run id.
	 * @return array<string, mixed>|null
	 */
	public static function find( string $id ): ?array {
		foreach ( self::all() as $run ) {
			if ( ( $run['id'] ?? '' ) === $id ) {
				return $run;
			}
		}
		return null;
	}
}
