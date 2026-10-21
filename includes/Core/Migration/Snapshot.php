<?php
/**
 * Snapshot of everything a migration run may change, so the run can be undone exactly.
 *
 * @package UxStudio
 */

namespace UxStudio\Core\Migration;

use UxStudio\Core\DB;

defined( 'ABSPATH' ) || exit;

/**
 * Options: the previous value of every target option (or the fact that it did not
 * exist) is stored under the run id. Tables (phase F3): a full copy in a backup
 * table (`CREATE TABLE ... LIKE` + `INSERT ... SELECT`), recorded in the snapshot.
 * Snapshots live 30 days; a daily cron purges older ones (Bootstrap::purge()).
 *
 * The snapshot never touches the SOURCE plugin's data - only UX Studio's targets.
 */
final class Snapshot {

	public const KEEP_DAYS = 30;

	private const PREFIX = 'uxstudio_migration_snapshot_';

	/** Backup tables get this (shortened) prefix after the WP prefix. */
	private const TABLE_PREFIX = 'uxsmb_';

	/**
	 * Option that stores one snapshot.
	 *
	 * @param string $run_id Run id.
	 */
	public static function option_name( string $run_id ): string {
		return self::PREFIX . self::clean_id( $run_id );
	}

	/**
	 * Run ids are generated as `r` + hex; anything else is rejected before it reaches an option name.
	 *
	 * @param string $run_id Candidate id.
	 */
	public static function clean_id( string $run_id ): string {
		return (string) preg_replace( '/[^a-z0-9]/', '', strtolower( $run_id ) );
	}

	/**
	 * Whether a snapshot exists for the run.
	 *
	 * @param string $run_id Run id.
	 */
	public static function exists( string $run_id ): bool {
		return is_array( get_option( self::option_name( $run_id ), null ) );
	}

	/**
	 * Start a snapshot (idempotent: an existing one is kept).
	 *
	 * @param string $run_id Run id.
	 */
	public static function create( string $run_id ): void {
		if ( self::exists( $run_id ) ) {
			return;
		}
		update_option(
			self::option_name( $run_id ),
			array(
				'run_id'  => $run_id,
				'created' => time(),
				'options' => array(),
				'tables'  => array(),
			),
			false
		);
	}

	/**
	 * Remember the current state of options (only those not remembered yet in this run).
	 *
	 * @param string   $run_id Run id.
	 * @param string[] $names  Option names.
	 */
	public static function capture_options( string $run_id, array $names ): void {
		self::create( $run_id );
		$snap     = (array) get_option( self::option_name( $run_id ), array() );
		$captured = (array) ( $snap['options'] ?? array() );
		$missing  = new \stdClass();
		foreach ( array_unique( array_map( 'strval', $names ) ) as $name ) {
			if ( '' === $name || array_key_exists( $name, $captured ) ) {
				continue;
			}
			$value = get_option( $name, $missing );
			if ( $value === $missing ) {
				$captured[ $name ] = array( 'exists' => false );
			} else {
				$captured[ $name ] = array(
					'exists'   => true,
					'value'    => $value,
					'autoload' => self::autoload_of( $name ),
				);
			}
		}
		$snap['options'] = $captured;
		update_option( self::option_name( $run_id ), $snap, false );
	}

	/**
	 * Autoload flag of a stored option (true / false), null when it cannot be read.
	 *
	 * @param string $name Option name.
	 */
	private static function autoload_of( string $name ): ?bool {
		global $wpdb;
		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) || ! method_exists( $wpdb, 'get_var' ) || ! isset( $wpdb->options ) ) {
			return null;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- reading one flag for the snapshot.
		$flag = $wpdb->get_var( $wpdb->prepare( "SELECT autoload FROM {$wpdb->options} WHERE option_name = %s", $name ) );
		return null === $flag ? null : in_array( (string) $flag, array( 'yes', 'on', 'auto', 'auto-on' ), true );
	}

	/**
	 * Copy a whole table into a backup table before a run changes it (phase F3 sources).
	 *
	 * @param string $run_id Run id.
	 * @param string $table  Table name WITHOUT the WordPress prefix.
	 * @return bool False when the backup could not be made (the run must not continue).
	 */
	public static function capture_table( string $run_id, string $table ): bool {
		global $wpdb;
		$table = (string) preg_replace( '/[^a-z0-9_]/i', '', $table );
		if ( '' === $table ) {
			return false;
		}
		self::create( $run_id );
		$snap = (array) get_option( self::option_name( $run_id ), array() );
		if ( isset( $snap['tables'][ $table ] ) ) {
			return true;
		}
		// The module's table-version record goes back with the table: a table dropped by the
		// rollback must not stay "installed" in it.
		self::capture_options( $run_id, array( DB::MODULE_VERSIONS_OPTION ) );
		$snap = (array) get_option( self::option_name( $run_id ), array() );

		$real = $wpdb->prefix . $table;
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- backup of a fixed, validated table name.
		$exists = (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $real ) );
		if ( ! $exists ) {
			$snap['tables'][ $table ] = array(
				'existed' => false,
				'backup'  => '',
			);
			update_option( self::option_name( $run_id ), $snap, false );
			return true;
		}

		$backup = substr( $wpdb->prefix . self::TABLE_PREFIX . substr( self::clean_id( $run_id ), 0, 8 ) . '_' . $table, 0, 64 );
		$wpdb->query( "DROP TABLE IF EXISTS {$backup}" );
		$ok = false !== $wpdb->query( "CREATE TABLE {$backup} LIKE {$real}" )
			&& false !== $wpdb->query( "INSERT INTO {$backup} SELECT * FROM {$real}" );
		// phpcs:enable
		if ( ! $ok ) {
			return false;
		}
		$snap['tables'][ $table ] = array(
			'existed' => true,
			'backup'  => $backup,
		);
		update_option( self::option_name( $run_id ), $snap, false );
		return true;
	}

	/**
	 * Restore everything the snapshot holds: options get their old value back (or are
	 * deleted when they did not exist), tables are rebuilt from the backup.
	 *
	 * @param string $run_id Run id.
	 * @return array{restored:int,deleted:int,tables:int,errors:string[]}|null Null when there is no snapshot.
	 */
	public static function rollback( string $run_id ): ?array {
		$snap = get_option( self::option_name( $run_id ), null );
		if ( ! is_array( $snap ) ) {
			return null;
		}
		$out = array(
			'restored' => 0,
			'deleted'  => 0,
			'tables'   => 0,
			'errors'   => array(),
		);
		foreach ( (array) ( $snap['options'] ?? array() ) as $name => $state ) {
			if ( ! empty( $state['exists'] ) ) {
				$autoload = $state['autoload'] ?? null;
				// update_option() skips the write when the value looks unchanged, which is exactly right.
				update_option( (string) $name, $state['value'], null === $autoload ? null : (bool) $autoload );
				++$out['restored'];
			} elseif ( false !== get_option( (string) $name, false ) ) {
				delete_option( (string) $name );
				++$out['deleted'];
			}
		}
		foreach ( (array) ( $snap['tables'] ?? array() ) as $table => $state ) {
			if ( self::restore_table( (string) $table, (array) $state ) ) {
				++$out['tables'];
			} else {
				$out['errors'][] = sprintf(
					/* translators: %s: table name. */
					__( 'Table "%s" could not be restored.', 'ux-studio' ),
					(string) $table
				);
			}
		}
		MigrationMap::delete_run( $run_id );
		self::delete( $run_id );
		return $out;
	}

	/**
	 * Rebuild one table from its backup.
	 *
	 * @param string               $table Table name without prefix.
	 * @param array<string, mixed> $state Snapshot record.
	 */
	private static function restore_table( string $table, array $state ): bool {
		global $wpdb;
		$real = $wpdb->prefix . $table;
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- restore of a table the snapshot itself created.
		if ( empty( $state['existed'] ) ) {
			return false !== $wpdb->query( "DROP TABLE IF EXISTS {$real}" );
		}
		$backup = (string) ( $state['backup'] ?? '' );
		if ( '' === $backup || ! $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $backup ) ) ) {
			return false;
		}
		$ok = false !== $wpdb->query( "DROP TABLE IF EXISTS {$real}" )
			&& false !== $wpdb->query( "CREATE TABLE {$real} LIKE {$backup}" )
			&& false !== $wpdb->query( "INSERT INTO {$real} SELECT * FROM {$backup}" );
		// phpcs:enable
		return $ok;
	}

	/**
	 * Delete one snapshot including its backup tables.
	 *
	 * @param string $run_id Run id.
	 */
	public static function delete( string $run_id ): void {
		global $wpdb;
		$snap = get_option( self::option_name( $run_id ), null );
		if ( is_array( $snap ) && isset( $wpdb ) && is_object( $wpdb ) ) {
			foreach ( (array) ( $snap['tables'] ?? array() ) as $state ) {
				$backup = (string) ( $state['backup'] ?? '' );
				if ( '' !== $backup ) {
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- dropping our own backup table.
					$wpdb->query( "DROP TABLE IF EXISTS {$backup}" );
				}
			}
		}
		delete_option( self::option_name( $run_id ) );
	}

	/**
	 * Timestamp until which a run created at `$created` can be rolled back.
	 *
	 * @param int $created Unix time of the run.
	 */
	public static function expires( int $created ): int {
		return $created + self::KEEP_DAYS * DAY_IN_SECONDS;
	}

	/**
	 * Delete snapshots older than KEEP_DAYS (daily cron).
	 *
	 * @param int|null $now Test seam: current time.
	 * @return string[] Purged run ids.
	 */
	public static function purge_expired( ?int $now = null ): array {
		$now    = $now ?? time();
		$purged = array();
		Staging::purge_expired( $now );
		foreach ( self::stored_ids() as $id ) {
			$snap = get_option( self::option_name( $id ), null );
			if ( ! is_array( $snap ) ) {
				continue;
			}
			if ( self::expires( (int) ( $snap['created'] ?? 0 ) ) <= $now ) {
				self::delete( $id );
				History::update( $id, array( 'snapshot_purged' => true ) );
				$purged[] = $id;
			}
		}
		return $purged;
	}

	/**
	 * Run ids that have a stored snapshot (database scan; the run history as a fallback).
	 *
	 * @return string[]
	 */
	private static function stored_ids(): array {
		global $wpdb;
		$ids = array();
		if ( isset( $wpdb ) && is_object( $wpdb ) && method_exists( $wpdb, 'get_col' ) && isset( $wpdb->options ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- listing our own snapshot options.
			$names = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( self::PREFIX ) . '%' ) );
			foreach ( (array) $names as $name ) {
				$ids[] = substr( (string) $name, strlen( self::PREFIX ) );
			}
		}
		foreach ( History::all() as $run ) {
			if ( ! empty( $run['id'] ) && ! empty( $run['snapshot'] ) ) {
				$ids[] = self::clean_id( (string) $run['id'] );
			}
		}
		return array_values( array_unique( array_filter( $ids ) ) );
	}
}
