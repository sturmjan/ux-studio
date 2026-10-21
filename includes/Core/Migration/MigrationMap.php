<?php
/**
 * Mapping table `uxstudio_migration_map`: what in the source ended up where in UX Studio.
 *
 * @package UxStudio
 */

namespace UxStudio\Core\Migration;

use UxStudio\Core\DB;

defined( 'ABSPATH' ) || exit;

/**
 * Unique on (source_plugin, source_key, target_type), so a re-run updates the same
 * row instead of adding a second one - the basis of idempotent migrations with data
 * (F3: redirects, snippets, folders ...). Settings-only sources record
 * `setting` rows (target_id = `<option>:<key>`), which also tells the admin what a
 * run touched.
 */
final class MigrationMap {

	public const MODULE  = 'core-migration';
	public const VERSION = 1;

	/** Full table name. */
	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'uxstudio_migration_map';
	}

	/**
	 * Create the table when missing (cheap on repeat calls, see DB::ensure_module_tables).
	 */
	public static function ensure_table(): void {
		DB::ensure_module_tables(
			self::MODULE,
			self::VERSION,
			static function (): void {
				global $wpdb;
				$charset = $wpdb->get_charset_collate();
				$table   = self::table();
				dbDelta(
					"CREATE TABLE {$table} (
						id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
						source_plugin VARCHAR(64) NOT NULL,
						source_key VARCHAR(191) NOT NULL,
						target_type VARCHAR(32) NOT NULL,
						target_id VARCHAR(191) NOT NULL DEFAULT '',
						run_id VARCHAR(32) NOT NULL DEFAULT '',
						created_at DATETIME NOT NULL,
						PRIMARY KEY  (id),
						UNIQUE KEY source_map (source_plugin, source_key, target_type),
						KEY run_id (run_id)
					) {$charset};"
				);
			}
		);
	}

	/**
	 * Record (or update) one mapping.
	 *
	 * @param string $source_plugin Plugin slug.
	 * @param string $source_key    Identifier of the source object.
	 * @param string $target_type   `setting`, later `redirect`, `snippet`, ...
	 * @param string $target_id     Identifier in UX Studio.
	 * @param string $run_id        Run that wrote it.
	 */
	public static function put( string $source_plugin, string $source_key, string $target_type, string $target_id, string $run_id ): void {
		global $wpdb;
		self::ensure_table();
		// REPLACE honours the unique key, so a repeated run overwrites instead of duplicating.
		$wpdb->replace(
			self::table(),
			array(
				'source_plugin' => substr( $source_plugin, 0, 64 ),
				'source_key'    => substr( $source_key, 0, 191 ),
				'target_type'   => substr( $target_type, 0, 32 ),
				'target_id'     => substr( $target_id, 0, 191 ),
				'run_id'        => substr( $run_id, 0, 32 ),
				'created_at'    => current_time( 'mysql' ),
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s' )
		);
	}

	/**
	 * Target of an earlier mapping, or null.
	 *
	 * @param string $source_plugin Plugin slug.
	 * @param string $source_key    Source object id.
	 * @param string $target_type   Target type.
	 */
	public static function get( string $source_plugin, string $source_key, string $target_type ): ?string {
		global $wpdb;
		self::ensure_table();
		$table = self::table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- own table.
		$value = $wpdb->get_var( $wpdb->prepare( "SELECT target_id FROM {$table} WHERE source_plugin = %s AND source_key = %s AND target_type = %s", $source_plugin, $source_key, $target_type ) );
		return null === $value ? null : (string) $value;
	}

	/**
	 * Forget the rows a run wrote (after its rollback they would describe data that is gone).
	 *
	 * @param string $run_id Run id.
	 */
	public static function delete_run( string $run_id ): void {
		global $wpdb;
		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) ) {
			return;
		}
		self::ensure_table();
		$wpdb->delete( self::table(), array( 'run_id' => substr( $run_id, 0, 32 ) ), array( '%s' ) );
	}
}
