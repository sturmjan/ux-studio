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
	 * Begin a run. A real run takes the snapshot before anything is written.
	 *
	 * @param string[] $ids     Source ids.
	 * @param bool     $dry_run Compute only.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function start( array $ids, bool $dry_run ) {
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
				'written'     => 0,
				'unchanged'   => 0,
				'items'       => array(),
				'errors'      => array(),
			);
		}

		if ( ! $dry_run ) {
			// 1) Snapshot of every option any selected source may write.
			$names = array();
			foreach ( $sources as $source ) {
				$names = array_merge( $names, $source->target_options() );
			}
			Snapshot::create( $run_id );
			Snapshot::capture_options( $run_id, $names );
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
						'run_id' => $state['id'],
						'cursor' => $row['cursor'],
					),
					(bool) $state['dry_run']
				);
				$row['progress']  = (int) ( $result['progress'] ?? 0 );
				$row['cursor']    = $result['cursor'] ?? null;
				$row['written']  += (int) ( $result['written'] ?? 0 );
				$row['unchanged'] = (int) ( $result['unchanged'] ?? 0 );
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
			);
			$errors   += 'error' === $row['status'] ? 1 : 0;
		}
		if ( $state['dry_run'] ) {
			ActivityLog::log( 'core', 'migration_dry_run', 'migration', 0, array( 'run' => $state['id'], 'sources' => $state['sources'], 'errors' => $errors ) );
			return $state;
		}
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
		if ( ! empty( $record['rolled_back'] ) ) {
			return new \WP_Error( 'uxstudio_migration_rolled_back', __( 'This run was already rolled back.', 'ux-studio' ), array( 'status' => 409 ) );
		}
		if ( ! Snapshot::exists( $run_id ) || Snapshot::expires( (int) $record['started'] ) <= time() ) {
			return new \WP_Error( 'uxstudio_migration_expired', __( 'The snapshot of this run has expired.', 'ux-studio' ), array( 'status' => 410 ) );
		}
		$result = Snapshot::rollback( $run_id );
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
	 * History for the UI, with the rollback window resolved.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function history(): array {
		$out = array();
		foreach ( History::all() as $run ) {
			$run['rollback_until'] = self::mysql_time( Snapshot::expires( (int) ( $run['started'] ?? 0 ) ) );
			$run['can_rollback']   = ! empty( $run['snapshot'] ) && empty( $run['rolled_back'] ) && empty( $run['snapshot_purged'] )
				&& Snapshot::expires( (int) ( $run['started'] ?? 0 ) ) > time() && Snapshot::exists( (string) $run['id'] );
			$out[]                 = $run;
		}
		return $out;
	}
}
