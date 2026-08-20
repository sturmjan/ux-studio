<?php
/**
 * Database install / upgrade with versioned migrations.
 *
 * @package UxStudio
 */

namespace UxStudio\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Owns core tables and the DB version lifecycle. Module-specific tables are
 * created by their module's migrate() via the same versioning hook.
 */
final class DB {

	private const OPTION = 'uxstudio_db_version';

	/**
	 * Activation hook: run migrations from scratch, then import legacy data
	 * from ux1-wordpress-customizer if present (idempotent, safe to re-run).
	 */
	public static function activate(): void {
		self::migrate( 0 );
		update_option( self::OPTION, UXSTUDIO_DB_VERSION );
		Migrator::run();
	}

	/**
	 * Upgrade path on normal boot (after plugin update).
	 */
	public static function maybe_upgrade(): void {
		$current = (int) get_option( self::OPTION, 0 );
		if ( $current >= UXSTUDIO_DB_VERSION ) {
			return;
		}
		self::migrate( $current );
		update_option( self::OPTION, UXSTUDIO_DB_VERSION );
	}

	/**
	 * Run migrations newer than $from.
	 *
	 * @param int $from Currently installed DB version.
	 */
	private static function migrate( int $from ): void {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		if ( $from < 1 ) {
			self::migrate_1();
		}

		/**
		 * Lets modules run their own versioned migrations.
		 *
		 * @param int $from Previously installed DB version.
		 */
		do_action( 'ux_studio/db_migrate', $from );
	}

	/**
	 * Lazily creates/upgrades a single module's own tables. Called from the
	 * module's boot() (which only runs for enabled modules - keeps unused
	 * modules from ever touching the schema). Cheap on repeat calls: a single
	 * option read once the module is at its current version.
	 *
	 * @param string   $module_id Module id (kebab-case).
	 * @param int      $version   Module's own schema version.
	 * @param callable $migrator  function( int $from ): void - runs dbDelta().
	 */
	public static function ensure_module_tables( string $module_id, int $version, callable $migrator ): void {
		$option  = 'uxstudio_dbv_' . str_replace( '-', '_', $module_id );
		$current = (int) get_option( $option, 0 );
		if ( $current >= $version ) {
			return;
		}
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$migrator( $current );
		update_option( $option, $version, false );
	}

	/**
	 * v1: activity/audit log table (shared by all modules).
	 */
	private static function migrate_1(): void {
		global $wpdb;
		$charset = $wpdb->get_charset_collate();
		dbDelta(
			"CREATE TABLE {$wpdb->prefix}uxstudio_activity_log (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				created_at DATETIME NOT NULL,
				user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
				module VARCHAR(64) NOT NULL DEFAULT '',
				action VARCHAR(64) NOT NULL DEFAULT '',
				object_type VARCHAR(64) NOT NULL DEFAULT '',
				object_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
				meta LONGTEXT NULL,
				PRIMARY KEY  (id),
				KEY created_at (created_at),
				KEY module_action (module, action)
			) {$charset};"
		);
	}
}
