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
	 * One autoloaded option holding every module's schema version
	 * (module slug with underscores => int). Replaces the per-module
	 * `uxstudio_dbv_<slug>` options, which were not autoloaded and cost one
	 * SELECT per enabled module on every request.
	 */
	private const MODULE_VERSIONS_OPTION = 'uxstudio_module_db_versions';

	/** Prefix of the pre-v2 per-module schema version options. */
	private const LEGACY_DBV_PREFIX = 'uxstudio_dbv_';

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
		if ( $from < 2 ) {
			self::migrate_2();
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
	 * modules from ever touching the schema). Cheap on repeat calls: an array
	 * lookup in one autoloaded option once the module is at its current version.
	 *
	 * @param string   $module_id Module id (kebab-case).
	 * @param int      $version   Module's own schema version.
	 * @param callable $migrator  function( int $from ): void - runs dbDelta().
	 */
	public static function ensure_module_tables( string $module_id, int $version, callable $migrator ): void {
		$current = self::module_version( $module_id );
		if ( $current >= $version ) {
			return;
		}
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$migrator( $current );
		self::set_module_version( $module_id, $version );

		// A module's tables now exist. If legacy ux1 data for this module is
		// present and the new table is still empty, import it now (idempotent).
		Migrator::maybe_migrate_module_data( $module_id );
	}

	/**
	 * Installed schema version of a module (0 = never installed). Falls back
	 * to the pre-v2 per-module option once and folds it into the shared array,
	 * so a site that skipped migrate_2 (e.g. a module added later) still keeps
	 * its version instead of re-running dbDelta from scratch.
	 *
	 * @param string $module_id Module id (kebab-case).
	 */
	private static function module_version( string $module_id ): int {
		$slug     = str_replace( '-', '_', $module_id );
		$versions = get_option( self::MODULE_VERSIONS_OPTION, array() );
		if ( is_array( $versions ) && isset( $versions[ $slug ] ) ) {
			return (int) $versions[ $slug ];
		}
		$legacy = (int) get_option( self::LEGACY_DBV_PREFIX . $slug, 0 );
		if ( $legacy > 0 ) {
			self::set_module_version( $module_id, $legacy );
			delete_option( self::LEGACY_DBV_PREFIX . $slug );
		}
		return $legacy;
	}

	/**
	 * Persist a module's schema version into the shared autoloaded option.
	 *
	 * @param string $module_id Module id (kebab-case).
	 * @param int    $version   Schema version.
	 */
	private static function set_module_version( string $module_id, int $version ): void {
		$versions = get_option( self::MODULE_VERSIONS_OPTION, array() );
		$versions = is_array( $versions ) ? $versions : array();

		$versions[ str_replace( '-', '_', $module_id ) ] = $version;
		update_option( self::MODULE_VERSIONS_OPTION, $versions, true );
	}

	/**
	 * v2 (performance + legacy cleanup, one-time):
	 * - fold the per-module `uxstudio_dbv_*` options into one autoloaded array;
	 * - autoload the small module settings options (read on every request by
	 *   enabled modules - were stored with autoload off);
	 * - unschedule cron events the legacy ux1 plugin left behind;
	 * - run the legacy data imports added after some module tables already
	 *   existed (their first-boot hook in ensure_module_tables already fired).
	 */
	private static function migrate_2(): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one-off migration.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s",
				$wpdb->esc_like( self::LEGACY_DBV_PREFIX ) . '%'
			)
		);
		if ( $rows ) {
			$versions = get_option( self::MODULE_VERSIONS_OPTION, array() );
			$versions = is_array( $versions ) ? $versions : array();
			foreach ( $rows as $row ) {
				$slug = substr( $row->option_name, strlen( self::LEGACY_DBV_PREFIX ) );
				// Never downgrade a version already recorded in the new option.
				$versions[ $slug ] = max( (int) ( $versions[ $slug ] ?? 0 ), (int) $row->option_value );
			}
			update_option( self::MODULE_VERSIONS_OPTION, $versions, true );
			foreach ( $rows as $row ) {
				delete_option( $row->option_name );
			}
		}

		Migrator::autoload_module_settings( Modules::module_ids() );
		Handoff::clear_legacy_cron();

		foreach ( array( 'review-aggregator', 'instagram-feed', 'bot-throttle', 'exit-popup' ) as $module_id ) {
			Migrator::maybe_migrate_module_data( $module_id );
		}
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
