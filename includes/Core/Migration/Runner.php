<?php
/**
 * Resumable migration runner: a small state machine kept in an option, driven in
 * batches by the browser (one REST call = one batch).
 *
 * @package UxStudio
 */

namespace UxStudio\Core\Migration;

use UxStudio\Core\ActivityLog;

defined( 'ABSPATH' ) || exit;

/**
 * State option `uxstudio_migration_run`:
 *
 *   id, dry_run, status (running | done | rolled_back), started, finished, user_id,
 *   snapshot (bool), sources[] (ids in run order),
 *   per_source[id] = { status: pending | running | done | error, label, module_name,
 *                      cursor, progress, written, unchanged, items[], errors[] },
 *   verify[id] = checks[]
 *
 * A reload of the wizard reads this and carries on where the run stopped.
 * Order of a real run: snapshot of every target option FIRST, then the sources.
 */
final class Runner {

	public const OPTION = 'uxstudio_migration_run';

	/** A "running" state older than this is considered abandoned and may be replaced. */
	private const STALE_AFTER = HOUR_IN_SECONDS;

	/**
	 * Current / last run state.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function state(): ?array {
		$state = get_option( self::OPTION, null );
		return is_array( $state ) && ! empty( $state['id'] ) ? $state : null;
	}

	/**
	 * Save a state.
	 *
	 * @param array<string, mixed> $state State.
	 */
	private static function save( array $state ): void {
		update_option( self::OPTION, $state, false );
	}

	/**
	 * Site-local MySQL datetime (display goes through the SPA formatDate()).
	 *
	 * @param int $ts Unix time.
	 */
	public static function mysql_time( int $ts ): string {
		return function_exists( 'wp_date' ) ? (string) wp_date( 'Y-m-d H:i:s', $ts ) : gmdate( 'Y-m-d H:i:s', $ts );
	}

	/**
	 * Keep only the options a source declares, as booleans (defaults for the rest).
	 *
	 * @param Source               $source Source.
	 * @param array<string, mixed> $given  What the client sent.
	 * @return array<string, bool>
	 */
	public static function clean_options( Source $source, array $given ): array {
		$out = array();
		foreach ( $source->options() as $option ) {
			$key         = (string) $option['key'];
			$out[ $key ] = array_key_exists( $key, $given ) ? (bool) $given[ $key ] : (bool) $option['default'];
		}
		return $out;
	}

	/**
	 * Begin a run. A real run takes the snapshot before anything is written.
	 *
	 * @param string[]                           $ids     Source ids.
	 * @param bool                               $dry_run Compute only.
	 * @param array<string, array<string, bool>> $options Per-source options (`source id => key => bool`).
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function start( array $ids, bool $dry_run, array $options = array() ) {
		$ids = array_values( array_unique( array_map( 'strval', $ids ) ) );
		if ( ! $ids ) {
			return new \WP_Error( 'uxstudio_migration_empty', __( 'Select at least one plugin to migrate.', 'ux-studio' ), array( 'status' => 400 ) );
		}
		$sources = array();
		foreach ( $ids as $id ) {
			$source = SourceRegistry::get( $id );
			if ( ! $source ) {
				return new \WP_Error( 'uxstudio_migration_unknown', __( 'Unknown migration source.', 'ux-studio' ), array( 'status' => 400 ) );
			}
			$sources[ $id ] = $source;
		}

		$current = self::state();
		// A dry run left half-way writes nothing, so it never blocks a new run; only a real one does.
		if ( $current && 'running' === $current['status'] && empty( $current['dry_run'] ) && (int) ( $current['started'] ?? 0 ) > time() - self::STALE_AFTER ) {
			return new \WP_Error(
				'uxstudio_migration_busy',
				__( 'Another migration is still running. Finish it first.', 'ux-studio' ),
				array(
					'status' => 409,
					'run'    => $current,
				)
			);
		}

		$run_id = 'r' . bin2hex( random_bytes( 6 ) );
		$now    = time();
		$state  = array(
			'id'         => $run_id,
			'dry_run'    => $dry_run,
			'status'     => 'running',
			'started'    => $now,
			'started_at' => self::mysql_time( $now ),
			'finished'   => 0,
			'user_id'    => function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0,
			'snapshot'   => false,
			'sources'    => $ids,
			'per_source' => array(),
			'verify'     => array(),
		);
		foreach ( $sources as $id => $source ) {
			$state['per_source'][ $id ] = array(
				'status'      => 'pending',
				'label'       => $source->label(),
				'module_name' => ActivationGuard::module_name( $source->target_module() ),
				'cursor'      => null,
				'progress'    => 0,
				'processed'   => 0,
				'total'       => 0,
				'written'     => 0,
				'unchanged'   => 0,
				'staged'      => 0,
				'options'     => self::clean_options( $source, (array) ( $options[ $id ] ?? array() ) ),
				'items'       => array(),
				'errors'      => array(),
			);
		}

		if ( ! $dry_run ) {
			// 1) Snapshot of every option any selected source may write.
			$names  = array();
			$tables = array();
			foreach ( $sources as $source ) {
				$names  = array_merge( $names, $source->target_options() );
				$tables = array_merge( $tables, $source->target_tables() );
			}
			Snapshot::create( $run_id );
			Snapshot::capture_options( $run_id, $names );
			foreach ( array_unique( $tables ) as $table ) {
				if ( ! Snapshot::capture_table( $run_id, (string) $table ) ) {
					// No backup, no write: undo what the snapshot already holds and refuse the run.
					Snapshot::rollback( $run_id );
					return new \WP_Error(
						'uxstudio_migration_backup_failed',
						sprintf(
							/* translators: %s: table name. */
							__( 'The backup of the table "%s" failed, nothing was changed.', 'ux-studio' ),
							(string) $table
						),
						array( 'status' => 500 )
					);
				}
			}
			$state['snapshot'] = true;

			ActivityLog::log( 'core', 'migration_started', 'migration', 0, array( 'run' => $run_id, 'sources' => $ids ) );
		}
		self::save( $state );
		return $state;
	}

	/**
	 * Process the next pending source (one batch).
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function step() {
		$state = self::state();
		if ( ! $state || 'running' !== $state['status'] ) {
			return new \WP_Error( 'uxstudio_migration_no_run', __( 'No migration is running.', 'ux-studio' ), array( 'status' => 409 ) );
		}

		$next = null;
		foreach ( (array) $state['sources'] as $id ) {
			if ( in_array( $state['per_source'][ $id ]['status'] ?? '', array( 'pending', 'running' ), true ) ) {
				$next = (string) $id;
				break;
			}
		}
		if ( null === $next ) {
			return self::finish( $state );
		}

		$source = SourceRegistry::get( $next );
		$row    = $state['per_source'][ $next ];
		if ( ! $source ) {
			$row['status']   = 'error';
			$row['errors'][] = __( 'Unknown migration source.', 'ux-studio' );
		} else {
			$row['status'] = 'running';
			try {
				$result = $source->migrate(
					array(
						'run_id'  => $state['id'],
						'cursor'  => $row['cursor'],
						'options' => (array) ( $row['options'] ?? array() ),
					),
					(bool) $state['dry_run']
				);
				$row['progress']  = (int) ( $result['progress'] ?? 0 );
				$row['cursor']    = $result['cursor'] ?? null;
				$row['written']  += (int) ( $result['written'] ?? 0 );
				$row['staged']    = (int) ( $row['staged'] ?? 0 ) + (int) ( $result['staged'] ?? 0 );
				$row['total']     = (int) ( $result['total'] ?? $row['total'] ?? 0 );
				$row['processed'] = (int) ( $result['processed'] ?? $row['processed'] ?? 0 );
				$row['unchanged'] = (int) ( $row['unchanged'] ?? 0 ) + (int) ( $result['unchanged'] ?? 0 );
				$row['items']     = array_merge( (array) $row['items'], (array) ( $result['items'] ?? array() ) );
				$row['errors']    = array_merge( (array) $row['errors'], array_map( 'strval', (array) ( $result['errors'] ?? array() ) ) );
				if ( ! empty( $result['done'] ) ) {
					$row['status']   = $row['errors'] ? 'error' : 'done';
					$row['progress'] = 100;
				}
			} catch ( \Throwable $e ) {
				$row['status']   = 'error';
				$row['errors'][] = $e->getMessage();
			}
		}
		$state['per_source'][ $next ] = $row;
		self::save( $state );

		// Last one done? Close the run in the same call so the browser needs no extra round trip.
		foreach ( (array) $state['sources'] as $id ) {
			if ( in_array( $state['per_source'][ $id ]['status'] ?? '', array( 'pending', 'running' ), true ) ) {
				return $state;
			}
		}
		return self::finish( $state );
	}

	/**
	 * Options chosen for a source in the current / last run (empty when there is none).
	 *
	 * @param string $id Source id.
	 * @return array<string, bool>
	 */
	public static function options_of( string $id ): array {
		$state = self::state();
		return $state ? (array) ( $state['per_source'][ $id ]['options'] ?? array() ) : array();
	}

	/**
	 * Close a run, record it in the history.
	 *
	 * @param array<string, mixed> $state State.
	 * @return array<string, mixed>
	 */
	private static function finish( array $state ): array {
		$state['status']   = 'done';
		$state['finished'] = time();
		self::save( $state );

		$summary = array();
		$errors  = 0;
		foreach ( (array) $state['per_source'] as $id => $row ) {
			$summary[] = array(
				'id'      => (string) $id,
				'label'   => (string) $row['label'],
				'status'  => (string) $row['status'],
				'written' => (int) $row['written'],
				'staged'  => (int) ( $row['staged'] ?? 0 ),
			);
			$errors   += 'error' === $row['status'] ? 1 : 0;
		}
		if ( $state['dry_run'] ) {
			ActivityLog::log( 'core', 'migration_dry_run', 'migration', 0, array( 'run' => $state['id'], 'sources' => $state['sources'], 'errors' => $errors ) );
			return $state;
		}
		// How the covered options and tables look NOW: a later rollback compares against it.
		Snapshot::seal( (string) $state['id'] );
		History::put(
			array(
				'id'         => $state['id'],
				'started'    => $state['started'],
				'started_at' => $state['started_at'],
				'finished'   => $state['finished'],
				'user_id'    => $state['user_id'],
				'sources'    => $summary,
				'errors'     => $errors,
				'snapshot'   => (bool) $state['snapshot'],
				'rolled_back' => 0,
			)
		);
		ActivityLog::log( 'core', 'migration_finished', 'migration', 0, array( 'run' => $state['id'], 'sources' => $state['sources'], 'errors' => $errors ) );
		return $state;
	}

	/**
	 * Run the verify() of the selected sources (default: the sources of the last run).
	 *
	 * @param string[] $ids Source ids; empty = those of the current state.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function verify( array $ids = array() ) {
		$state = self::state();
		if ( ! $ids && $state ) {
			$ids = (array) $state['sources'];
		}
		if ( ! $ids ) {
			return new \WP_Error( 'uxstudio_migration_empty', __( 'Select at least one plugin to migrate.', 'ux-studio' ), array( 'status' => 400 ) );
		}
		PluginInfo::flush();
		$out = array();
		foreach ( $ids as $id ) {
			$source = SourceRegistry::get( (string) $id );
			if ( ! $source ) {
				return new \WP_Error( 'uxstudio_migration_unknown', __( 'Unknown migration source.', 'ux-studio' ), array( 'status' => 400 ) );
			}
			try {
				$out[ $id ] = $source->verify();
			} catch ( \Throwable $e ) {
				$out[ $id ] = array(
					array(
						'id'     => 'error',
						'label'  => __( 'Verification failed', 'ux-studio' ),
						'ok'     => false,
						'detail' => $e->getMessage(),
					),
				);
			}
		}
		if ( $state ) {
			$state['verify'] = $out;
			self::save( $state );
		}
		return $out;
	}

	/**
	 * Undo a real run from its snapshot.
	 *
	 * @param string $run_id Run id.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function rollback( string $run_id ) {
		$run_id = Snapshot::clean_id( $run_id );
		$record = History::find( $run_id );
		if ( ! $record || empty( $record['snapshot'] ) ) {
			return new \WP_Error( 'uxstudio_migration_no_snapshot', __( 'This run has no snapshot to restore.', 'ux-studio' ), array( 'status' => 404 ) );
		}
		if ( ! empty( $record['applied'] ) ) {
			// The switch wrote prepared values and switched data on; the snapshot no longer describes the
			// site. With the switch record at hand, undo the switch first, then roll back as usual.
			if ( ! Switcher::has_switch( $run_id ) ) {
				return new \WP_Error( 'uxstudio_migration_applied', __( 'This run has already been switched on, so the snapshot no longer matches the site. Undo the switch first.', 'ux-studio' ), array( 'status' => 409 ) );
			}
			$reverted = Switcher::revert( $run_id );
			if ( is_wp_error( $reverted ) ) {
				return $reverted;
			}
			$record = History::find( $run_id ) ?? $record;
		}
		if ( ! empty( $record['rolled_back'] ) ) {
			return new \WP_Error( 'uxstudio_migration_rolled_back', __( 'This run was already rolled back.', 'ux-studio' ), array( 'status' => 409 ) );
		}
		if ( ! Snapshot::exists( $run_id ) || Snapshot::expires( (int) $record['started'] ) <= time() ) {
			return new \WP_Error( 'uxstudio_migration_expired', __( 'The snapshot of this run has expired.', 'ux-studio' ), array( 'status' => 410 ) );
		}
		$result = Snapshot::rollback( $run_id );
		Staging::discard( $run_id );
		Preview::stop( $run_id );
		if ( null === $result ) {
			return new \WP_Error( 'uxstudio_migration_no_snapshot', __( 'This run has no snapshot to restore.', 'ux-studio' ), array( 'status' => 404 ) );
		}
		History::update( $run_id, array( 'rolled_back' => time() ) );

		$state = self::state();
		if ( $state && $state['id'] === $run_id ) {
			$state['status'] = 'rolled_back';
			self::save( $state );
		}
		ActivityLog::log( 'core', 'migration_rolled_back', 'migration', 0, array( 'run' => $run_id ) + $result );
		return $result;
	}

	/**
	 * What undoing a run would cost: the modules and tables it restores, and whether any of it changed after the
	 * run (a rollback puts the state from BEFORE the run back, so later changes in the same places are lost).
	 *
	 * @param string $run_id Run id.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function rollback_impact( string $run_id ) {
		$run_id = Snapshot::clean_id( $run_id );
		$record = History::find( $run_id );
		$drift  = Snapshot::drift( $run_id );
		if ( ! $record || null === $drift ) {
			return new \WP_Error( 'uxstudio_migration_no_snapshot', __( 'This run has no snapshot to restore.', 'ux-studio' ), array( 'status' => 404 ) );
		}
		$modules = array();
		foreach ( (array) ( $record['sources'] ?? array() ) as $row ) {
			$source = SourceRegistry::get( (string) ( $row['id'] ?? '' ) );
			if ( $source ) {
				$id             = $source->target_module();
				$modules[ $id ] = array(
					'id'   => $id,
					'name' => ActivationGuard::module_name( $id ),
				);
			}
		}
		// Option names of module settings are `uxstudio_<module id with underscores>`: show the module's name.
		$by_option = array();
		foreach ( \UxStudio\Core\Modules::module_ids() as $module_id ) {
			$by_option[ 'uxstudio_' . str_replace( '-', '_', $module_id ) ] = ActivationGuard::module_name( $module_id );
		}
		$changed_settings = array();
		foreach ( $drift['options'] as $name ) {
			$changed_settings[ $by_option[ $name ] ?? $name ] = true;
		}
		return array(
			'run_id'           => $run_id,
			'modules'          => array_values( $modules ),
			'tables'           => $drift['all_tables'],
			'sealed'           => $drift['sealed'],
			'changed'          => $drift['changed'],
			// Unknown (no fingerprint stored) is treated as possibly changed: the stronger warning applies.
			'needs_ack'        => ! $drift['sealed'] || $drift['changed'],
			'changed_settings' => array_keys( $changed_settings ),
			'changed_tables'   => $drift['tables'],
		);
	}

	/**
	 * History for the UI, with the rollback window resolved.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function history(): array {
		$out = array();
		foreach ( History::all() as $run ) {
			$run['rollback_until'] = self::mysql_time( Snapshot::expires( (int) ( $run['started'] ?? 0 ) ) );
			$run['staging']        = Staging::summary( (string) $run['id'] );
			$run['switch_active']    = ! empty( $run['switched'] ) && empty( $run['switch_reverted'] );
			$run['can_revert_switch'] = $run['switch_active'] && Switcher::can_revert( (string) $run['id'] );
			$run['revert_until']     = $run['can_revert_switch'] ? self::mysql_time( (int) Switcher::record( (string) $run['id'] )['created'] + Switcher::KEEP_DAYS * DAY_IN_SECONDS ) : '';
			$run['preview_ready']    = Preview::entry( (string) $run['id'] ) !== null;
			$run['can_rollback']   = ! empty( $run['snapshot'] ) && empty( $run['rolled_back'] ) && empty( $run['snapshot_purged'] ) && ( empty( $run['applied'] ) || $run['can_revert_switch'] )
				&& Snapshot::expires( (int) ( $run['started'] ?? 0 ) ) > time() && Snapshot::exists( (string) $run['id'] );
			// The wizard only needs the totals of the test results, not every row.
			$run['preview']     = isset( $run['preview'] ) ? array_intersect_key( (array) $run['preview'], array_flip( array( 'at', 'counts' ) ) ) : null;
			$run['switch_live'] = isset( $run['switch_live'] ) ? Preview::counts( (array) $run['switch_live'] ) : null;
			$out[]                 = $run;
		}
		return $out;
	}
}
