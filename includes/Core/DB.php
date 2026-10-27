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
	public const MODULE_VERSIONS_OPTION = 'uxstudio_module_db_versions';

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
		// A safe-preview request persists nothing (no repair, no schema upgrade).
		if ( \UxStudio\Core\Migration\Preview::is_active() ) {
			return;
		}
		// One-time settings key remap for sites migrated before the remaps existed.
		Migrator::maybe_repair_settings();

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
		if ( $current >= $version || \UxStudio\Core\Migration\Preview::is_active() ) {
			return;
		}
		// A failed attempt is retried later, not on every request.
		if ( false !== get_transient( self::FAIL_TRANSIENT_PREFIX . str_replace( '-', '_', $module_id ) ) ) {
			return;
		}
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		global $wpdb;
		$wpdb->last_error = ''; // A stale error of an earlier, unrelated query must not count.
		$errors_before = self::structural_error_count();
		// Tables dbDelta() was asked to create: after the step each of them must exist.
		$expected = array();
		$capture  = static function ( $queries ) use ( &$expected ) {
			foreach ( (array) $queries as $query ) {
				if ( 1 === preg_match( '/^\s*CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?`?([A-Za-z0-9_$]+)`?/i', (string) $query, $m ) ) {
					$expected[] = $m[1];
				}
			}
			return $queries;
		};
		add_filter( 'dbdelta_create_queries', $capture, PHP_INT_MAX );
		try {
			$migrator( $current );
		} finally {
			remove_filter( 'dbdelta_create_queries', $capture, PHP_INT_MAX );
		}
		if ( self::structural_error_count() > $errors_before || self::last_query_failed_structurally() || self::missing_table( $expected ) ) {
			// dbDelta()/CREATE/ALTER failed (permissions, a full disk, a broken index ...): do NOT record the
			// version, otherwise the missing table/column would never be created again.
			self::report_failed_migration( $module_id, $current, $version );
			return;
		}
		self::set_module_version( $module_id, $version );

		// A module's tables now exist. If legacy ux1 data for this module is
		// present and the new table is still empty, import it now (idempotent).
		Migrator::maybe_migrate_module_data( $module_id );
	}

	/** Transient prefix: a module whose schema step failed is not retried for FAIL_RETRY seconds. */
	private const FAIL_TRANSIENT_PREFIX = 'uxstudio_dbfail_';

	/** Retry delay after a failed schema step. */
	private const FAIL_RETRY = 15 * MINUTE_IN_SECONDS;

	/**
	 * Is this SQL a structural statement (the ones whose failure leaves a table or column missing)? dbDelta()
	 * itself runs a DESCRIBE on tables that do not exist yet, which fails by design, so only CREATE/ALTER count.
	 *
	 * @param string $sql SQL text.
	 */
	private static function is_structural_sql( string $sql ): bool {
		return 1 === preg_match( '/^\s*(CREATE|ALTER)\s/i', $sql );
	}

	/**
	 * Is this MySQL error harmless for a repeated schema step? dbDelta() can emit a failing ALTER for a key or
	 * column that is already there (1060, 1061, 1050, 1091); the end state is what the step wanted.
	 *
	 * @param string $error Error text.
	 */
	private static function is_benign_schema_error( string $error ): bool {
		return 1 === preg_match( '/Duplicate (key name|column name)|already exists|check that (column|key)(\/key)? exists|Can.t DROP/i', $error );
	}

	/**
	 * Is this failed statement a real schema failure (CREATE/ALTER with an error that matters)?
	 *
	 * @param string $sql   SQL text.
	 * @param string $error Error text.
	 */
	private static function is_blocking_failure( string $sql, string $error ): bool {
		return self::is_structural_sql( $sql ) && ! self::is_benign_schema_error( $error );
	}

	/**
	 * First expected table that does not exist after the step, or ''. Skipped without a usable wpdb.
	 *
	 * @param string[] $tables Table names dbDelta() was asked to create.
	 */
	private static function missing_table( array $tables ): string {
		global $wpdb;
		if ( ! is_object( $wpdb ) || ! method_exists( $wpdb, 'get_var' ) || ! method_exists( $wpdb, 'esc_like' ) ) {
			return '';
		}
		foreach ( array_unique( $tables ) as $table ) {
			if ( $table !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) ) {
				return $table;
			}
		}
		return '';
	}

	/**
	 * How many CREATE/ALTER statements have failed so far in this request (wpdb keeps every failed query in
	 * the global $EZSQL_ERROR, also when errors are suppressed).
	 */
	private static function structural_error_count(): int {
		$count = 0;
		foreach ( (array) ( $GLOBALS['EZSQL_ERROR'] ?? array() ) as $entry ) {
			if ( is_array( $entry ) && self::is_blocking_failure( (string) ( $entry['query'] ?? '' ), (string) ( $entry['error_str'] ?? '' ) ) ) {
				++$count;
			}
		}
		return $count;
	}

	/**
	 * Did the very last query of the schema step fail, and was it a CREATE/ALTER?
	 */
	private static function last_query_failed_structurally(): bool {
		global $wpdb;
		$error = (string) ( $wpdb->last_error ?? '' );
		return '' !== $error && self::is_blocking_failure( (string) ( $wpdb->last_query ?? '' ), $error );
	}

	/**
	 * A schema step failed: remember it for a while (so it is retried later, not on every request) and say so
	 * once a day in the Activity Log and in the PHP error log.
	 *
	 * @param string $module_id Module id.
	 * @param int    $from      Version it was at.
	 * @param int    $version   Version it wanted to reach.
	 */
	private static function report_failed_migration( string $module_id, int $from, int $version ): void {
		global $wpdb;
		$slug = str_replace( '-', '_', $module_id );
		set_transient( self::FAIL_TRANSIENT_PREFIX . $slug, 1, self::FAIL_RETRY );
		if ( false !== get_transient( self::FAIL_TRANSIENT_PREFIX . 'logged_' . $slug ) ) {
			return;
		}
		set_transient( self::FAIL_TRANSIENT_PREFIX . 'logged_' . $slug, 1, DAY_IN_SECONDS );
		$error = (string) ( $wpdb->last_error ?? '' );
		// last_error only knows the last query; the failed CREATE/ALTER may have been an earlier one.
		foreach ( array_reverse( (array) ( $GLOBALS['EZSQL_ERROR'] ?? array() ) ) as $entry ) {
			if ( is_array( $entry ) && self::is_blocking_failure( (string) ( $entry['query'] ?? '' ), (string) ( $entry['error_str'] ?? '' ) ) ) {
				$error = (string) ( $entry['error_str'] ?? $error );
				break;
			}
		}
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		error_log( sprintf( 'UX Studio: database upgrade of module %s (%d -> %d) failed, will retry: %s', $module_id, $from, $version, $error ) );
		ActivityLog::log(
			'core',
			'db_migration_failed',
			'module',
			0,
			array(
				'module' => $module_id,
				'from'   => $from,
				'to'     => $version,
				'error'  => $error,
			)
		);
	}

	/**
	 * Installed schema version of a module (0 = none), for modules that want to notice a vanished table.
	 *
	 * @param string $module_id Module id (kebab-case).
	 */
	public static function module_schema_version( string $module_id ): int {
		return self::module_version( $module_id );
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
