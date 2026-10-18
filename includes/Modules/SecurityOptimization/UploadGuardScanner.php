<?php
/**
 * Upload Guard scanner: queues uploads and runs batched detection scans.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\SecurityOptimization;

defined( 'ABSPATH' ) || exit;

/**
 * Three scan paths, all batched - none ever scans inline during a page
 * request for longer than a single small file read:
 *
 *  1. Upload-time queue: wp_handle_upload / add_attachment hooks are
 *     synchronous but only INSERT a lightweight 'queued' row (<5ms) - no
 *     file content is read there. A single_event is scheduled a few
 *     seconds later to actually scan the queue off-request.
 *  2. Queue batch: scans up to N queued rows per invocation.
 *  3. Full scan: builds a bounded file worklist once into the
 *     uxstudio_security_scan_queue table, then processes it N files at a
 *     time via chained wp_schedule_single_event calls (never a single
 *     unbounded loop/request) until the queue is empty - used for both the
 *     manual "Scan now" REST action and the nightly cron. A watchdog
 *     resumes or ends a run whose event chain broke.
 *
 * The scanner only READS and REPORTS. It never moves, renames or deletes a
 * file - quarantine is a separate manual action per finding (see
 * UploadGuardRestController::quarantine_finding). Scope and exclusions live
 * in UploadGuardScope; plugin files matching the official WordPress.org
 * package are clean without any heuristics (UploadGuardChecksums).
 */
final class UploadGuardScanner {

	public const QUEUE_BATCH_EVENT = 'uxstudio_security_queue_scan_event';
	public const FULL_SCAN_EVENT   = 'uxstudio_security_full_scan_event';
	public const DAILY_CRON_HOOK   = 'uxstudio_security_daily_scan';

	/**
	 * Pre-v2 storage of the whole worklist in ONE option (2+ MB, rewritten
	 * after every batch). Replaced by the uxstudio_security_scan_queue table;
	 * only still referenced so upgrades/finishes delete it.
	 */
	public const LEGACY_WORKLIST_OPTION = 'uxstudio_security_scan_worklist';

	private const STATUS_OPTION = 'uxstudio_security_scan_status';
	private const STATS_OPTION  = 'uxstudio_security_scan_stats';

	private const QUEUE_BATCH_SIZE   = 25;
	private const FULL_BATCH_SIZE    = 40;
	private const MAX_WORKLIST       = 20000;
	private const QUEUE_INSERT_CHUNK = 500;

	/** Watchdog: no batch progress for this long = the chain is broken, reschedule. */
	private const STALL_TIMEOUT = 15 * MINUTE_IN_SECONDS;

	/** Watchdog: no progress for this long = give up and end the scan as interrupted. */
	private const STALL_ABORT = DAY_IN_SECONDS;

	/** Max file size scanned, in bytes (10 MB). Larger files are skipped. */
	private const MAX_FILE_SIZE = 10 * 1024 * 1024;

	/** Severities stored as findings; low/clean results are not reported. */
	private const REPORTED_SEVERITIES = array( 'medium', 'high', 'critical' );

	private UploadGuardDetectionEngine $engine;

	private UploadGuardChecksums $checksums;

	public function __construct() {
		$this->engine    = new UploadGuardDetectionEngine();
		$this->checksums = new UploadGuardChecksums();
	}

	/* ==========================================================
	 * Upload-time queueing (synchronous, minimal overhead)
	 * ========================================================== */

	/**
	 * Hooked to the wp_handle_upload filter. Only queues metadata - never
	 * reads file content here, so it cannot add meaningful latency to the
	 * upload request.
	 *
	 * @param array  $upload  Array with 'file', 'url', 'type' keys.
	 * @param string $context Upload context ('upload' or 'sideload').
	 * @return array Unmodified $upload.
	 */
	public function handle_upload( array $upload, string $context = 'upload' ): array {
		if ( ! empty( $upload['error'] ) || empty( $upload['file'] ) || ! is_string( $upload['file'] ) ) {
			return $upload;
		}

		$this->queue_file( $upload['file'] );

		return $upload;
	}

	/**
	 * Hooked to add_attachment. Catches attachments created without going
	 * through wp_handle_upload (e.g. some programmatic sideloads).
	 *
	 * @param int $attachment_id Attachment post id.
	 */
	public function handle_add_attachment( int $attachment_id ): void {
		$file = get_attached_file( $attachment_id );
		if ( is_string( $file ) && '' !== $file ) {
			$this->queue_file( $file );
		}
	}

	/**
	 * Queue a file path for scanning if its extension is relevant and it is
	 * not already queued.
	 */
	private function queue_file( string $file_path ): void {
		global $wpdb;

		if ( UploadGuardScope::is_excluded( $file_path ) ) {
			return;
		}
		$extension = strtolower( pathinfo( $file_path, PATHINFO_EXTENSION ) );
		if ( 'zip' !== $extension && ! UploadGuardScope::is_relevant( $file_path, UploadGuardScope::area( $file_path ) ) ) {
			return;
		}

		$table = $wpdb->prefix . 'uxstudio_security_findings';

		$already_queued = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$table} WHERE file_path = %s AND status = 'queued'", // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				$file_path
			)
		);
		if ( $already_queued ) {
			return;
		}

		$wpdb->insert(
			$table,
			array(
				'created_at' => current_time( 'mysql' ),
				'file_path'  => $file_path,
				'status'     => 'queued',
				'details'    => wp_json_encode( array( 'user_id' => get_current_user_id() ) ),
			),
			array( '%s', '%s', '%s', '%s' )
		);

		// Scan soon, off-request - never block the upload response itself.
		if ( ! wp_next_scheduled( self::QUEUE_BATCH_EVENT ) ) {
			wp_schedule_single_event( time() + 10, self::QUEUE_BATCH_EVENT );
		}
	}

	/* ==========================================================
	 * Queue batch scan
	 * ========================================================== */

	/**
	 * Scan up to QUEUE_BATCH_SIZE queued rows. Reschedules itself if the
	 * queue is not empty afterwards.
	 */
	public function run_queue_batch(): void {
		global $wpdb;
		$table = $wpdb->prefix . 'uxstudio_security_findings';

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE status = 'queued' ORDER BY id ASC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				self::QUEUE_BATCH_SIZE
			)
		);

		$new_findings = array();
		foreach ( $rows as $row ) {
			$finding = $this->scan_row( $row );
			if ( null !== $finding ) {
				$new_findings[] = $finding;
			}
		}

		if ( ! empty( $new_findings ) ) {
			( new UploadGuardNotifier() )->notify( $new_findings );
		}

		// More queued work arrived while we scanned - keep draining.
		$remaining = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE status = 'queued'" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		if ( $remaining > 0 && ! wp_next_scheduled( self::QUEUE_BATCH_EVENT ) ) {
			wp_schedule_single_event( time() + 10, self::QUEUE_BATCH_EVENT );
		}
	}

	/**
	 * Scan a single queued row and update/delete it in place.
	 *
	 * @param object $row Row from uxstudio_security_findings.
	 * @return array|null The updated finding data if a detection was recorded, else null.
	 */
	private function scan_row( object $row ): ?array {
		global $wpdb;
		$table     = $wpdb->prefix . 'uxstudio_security_findings';
		$file_path = (string) $row->file_path;

		if ( ! file_exists( $file_path ) ) {
			$wpdb->delete( $table, array( 'id' => (int) $row->id ), array( '%d' ) );
			return null;
		}

		$size = (int) filesize( $file_path );
		if ( $size <= 0 || $size > self::MAX_FILE_SIZE ) {
			$wpdb->delete( $table, array( 'id' => (int) $row->id ), array( '%d' ) );
			return null;
		}

		$hash = hash_file( 'sha256', $file_path );
		if ( false === $hash ) {
			$wpdb->delete( $table, array( 'id' => (int) $row->id ), array( '%d' ) );
			return null;
		}

		if ( $this->is_hash_cleared( $file_path, $hash ) ) {
			$wpdb->delete( $table, array( 'id' => (int) $row->id ), array( '%d' ) );
			$this->cache_hash( $file_path, $hash );
			return null;
		}

		$result = $this->evaluate( $file_path );

		$this->cache_hash( $file_path, $hash );

		if ( null === $result ) {
			$wpdb->delete( $table, array( 'id' => (int) $row->id ), array( '%d' ) );
			return null;
		}

		$data = array(
			'finding_type' => 'malware',
			'severity'     => $result['severity'],
			'hash'         => $hash,
			'details'      => wp_json_encode( $result['details'] ),
			'status'       => 'scanned',
			'scanned_at'   => current_time( 'mysql' ),
		);

		$wpdb->update( $table, $data, array( 'id' => (int) $row->id ), array( '%s', '%s', '%s', '%s', '%s', '%s' ), array( '%d' ) );

		return array_merge( array( 'file_path' => $file_path ), $data );
	}

	/**
	 * Decide whether a file is worth reporting, combining where it lives with
	 * what is in it:
	 *  - plugin file identical to the official WordPress.org package: clean;
	 *    differing from it / not in it: reported with that as the reason;
	 *  - PHP in uploads: reported whenever it contains any executable code
	 *    (an empty "Silence is golden" index.php is fine);
	 *  - everything else: token-level heuristics of the detection engine.
	 *
	 * @return array{severity:string,details:array}|null Null = nothing to report.
	 */
	public function evaluate( string $file_path ): ?array {
		if ( UploadGuardScope::is_excluded( $file_path ) ) {
			return null;
		}

		$area      = UploadGuardScope::area( $file_path );
		$extension = strtolower( pathinfo( $file_path, PATHINFO_EXTENSION ) );
		// Upload-time queue may hold a ZIP; anything else outside the scope
		// (e.g. a queue built by an older version) is not ours to judge.
		if ( 'zip' !== $extension && ! UploadGuardScope::is_relevant( $file_path, $area ) ) {
			return null;
		}
		$is_php    = in_array( $extension, UploadGuardScope::PHP_EXTENSIONS, true );
		$context   = array();
		$official  = null;

		if ( UploadGuardScope::AREA_PLUGINS === $area && $is_php ) {
			$official = $this->checksums->verify( $file_path );
			if ( UploadGuardChecksums::MATCH === $official['state'] ) {
				return null;
			}
			if ( UploadGuardChecksums::MODIFIED === $official['state'] ) {
				$context[] = $this->context_match(
					'modified_official',
					35,
					/* translators: 1: plugin folder, 2: version */
					sprintf( __( 'Differs from the official package of %1$s %2$s on WordPress.org', 'ux-studio' ), $official['plugin'], $official['version'] )
				);
			} elseif ( UploadGuardChecksums::UNKNOWN === $official['state'] ) {
				$context[] = $this->context_match(
					'not_in_official',
					20,
					/* translators: 1: plugin folder, 2: version */
					sprintf( __( 'Not part of the official package of %1$s %2$s on WordPress.org', 'ux-studio' ), $official['plugin'], $official['version'] )
				);
			}
		}

		$result = $this->engine->scan( $file_path, $extension );

		if ( UploadGuardScope::AREA_UPLOADS === $area && $is_php ) {
			if ( ! $result['has_code'] ) {
				return null;
			}
			$context[] = $this->context_match( 'php_in_uploads', 35, __( 'PHP code in the uploads folder (uploads should only hold media)', 'ux-studio' ) );
		}

		$matches = array_merge( $context, $result['matches'] );
		$score   = (int) array_sum( array_column( $matches, 'weight' ) );
		$severity = $this->engine->calculate_severity( $score );
		if ( ! in_array( $severity, self::REPORTED_SEVERITIES, true ) ) {
			return null;
		}

		$details = array(
			'score'   => $score,
			'area'    => $area,
			'matches' => array_slice( $matches, 0, 25 ),
		);
		if ( null !== $official && '' !== $official['plugin'] ) {
			$details['official'] = $official;
		}

		return array(
			'severity' => $severity,
			'details'  => $details,
		);
	}

	/**
	 * A match that comes from the file's context rather than its content.
	 */
	private function context_match( string $name, int $weight, string $label ): array {
		return array(
			'name'    => $name,
			'weight'  => $weight,
			'label'   => $label,
			'line'    => 0,
			'snippet' => '',
			'count'   => 1,
		);
	}

	/* ==========================================================
	 * Full wp-content scan (batched worklist)
	 * ========================================================== */

	/**
	 * Kick off a full wp-content injection scan. Called by the manual "Scan
	 * now" REST action and by the daily cron. Builds a bounded worklist once
	 * into the queue table (one row per file - NOT one huge option rewritten
	 * after every batch), then processes it via chained single events.
	 */
	public function trigger_full_scan(): void {
		$this->watchdog();
		$status = $this->get_status();
		if ( 'running' === ( $status['state'] ?? '' ) ) {
			return; // Already in progress (and not stuck - the watchdog just checked).
		}

		$worklist = $this->build_worklist();

		$this->clear_queue();
		$this->enqueue_paths( $worklist );

		update_option(
			self::STATUS_OPTION,
			array(
				'state'            => 'running',
				'total'            => count( $worklist ),
				'remaining'        => count( $worklist ),
				'scanned'          => 0,
				'detections'       => 0,
				'started_at'       => current_time( 'mysql' ),
				'last_progress_at' => time(),
			),
			false
		);

		if ( ! wp_next_scheduled( self::FULL_SCAN_EVENT ) ) {
			wp_schedule_single_event( time() + 5, self::FULL_SCAN_EVENT );
		}
	}

	/**
	 * Process one batch of the full-scan queue; reschedules itself until the
	 * queue is empty. The batch is claimed (deleted) BEFORE scanning, so a
	 * file that fatals the scanner is skipped next time instead of wedging
	 * the scan on the same batch forever.
	 */
	public function run_full_scan_batch(): void {
		global $wpdb;
		$queue = self::queue_table();

		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT id, file_path FROM {$queue} ORDER BY id ASC LIMIT %d", self::FULL_BATCH_SIZE ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);
		if ( empty( $rows ) ) {
			$this->finish_full_scan();
			return;
		}

		$last_id = (int) end( $rows )->id;
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$queue} WHERE id <= %d", $last_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$detected = array();
		foreach ( $rows as $row ) {
			$finding = $this->scan_full_scan_file( (string) $row->file_path );
			if ( null !== $finding ) {
				$detected[] = $finding;
			}
		}

		$remaining = $this->queue_count();

		$status                     = $this->get_status();
		$status['scanned']          = (int) ( $status['scanned'] ?? 0 ) + count( $rows );
		$status['remaining']        = $remaining;
		$status['detections']       = (int) ( $status['detections'] ?? 0 ) + count( $detected );
		$status['last_progress_at'] = time();
		update_option( self::STATUS_OPTION, $status, false );

		if ( ! empty( $detected ) ) {
			( new UploadGuardNotifier() )->notify( $detected );
		}

		if ( $remaining > 0 ) {
			if ( ! wp_next_scheduled( self::FULL_SCAN_EVENT ) ) {
				wp_schedule_single_event( time() + 5, self::FULL_SCAN_EVENT );
			}
			return;
		}

		$this->finish_full_scan();
	}

	/**
	 * Recover a full scan stuck in `running` - e.g. the chained event was lost
	 * (cron cleared, fatal mid-batch, module toggled), or a pre-queue-table scan
	 * whose worklist lived in the old option. Cheap: one option read when idle.
	 *
	 * Acts only once nothing has progressed for STALL_TIMEOUT:
	 *  - queue empty            -> finish (marked interrupted when the run never
	 *                              progressed at all - the legacy worklist case)
	 *  - stalled >= STALL_ABORT -> give up: mark interrupted, clear the queue
	 *  - otherwise              -> reschedule the lost batch event
	 */
	public function watchdog(): void {
		$status = $this->get_status();
		if ( 'running' !== ( $status['state'] ?? '' ) ) {
			return;
		}

		$last     = (int) ( $status['last_progress_at'] ?? 0 );
		$idle_for = $last ? time() - $last : PHP_INT_MAX;

		// A batch takes seconds and the next one is chained 5s later, so recent
		// progress means healthy - even if the event is momentarily unscheduled
		// because WP-Cron is executing it right now (don't double-schedule).
		if ( $idle_for < self::STALL_TIMEOUT ) {
			return;
		}

		if ( 0 === $this->queue_count() ) {
			$this->finish_full_scan( 0 === $last );
			return;
		}

		if ( $idle_for >= self::STALL_ABORT ) {
			$this->abort_full_scan();
			return;
		}

		if ( ! wp_next_scheduled( self::FULL_SCAN_EVENT ) ) {
			wp_schedule_single_event( time() + 5, self::FULL_SCAN_EVENT );
		}
	}

	/**
	 * End a running full scan without finishing it (module switched off,
	 * hopelessly stalled). Keeps the last-run stats untouched.
	 */
	public function abort_full_scan(): void {
		$status = $this->get_status();
		if ( 'running' !== ( $status['state'] ?? '' ) ) {
			return;
		}
		$this->finish_full_scan( true );
	}

	/**
	 * Finalize a full scan run.
	 *
	 * @param bool $interrupted True when the run was cut short - status says so
	 *                          and the "last completed scan" stats are kept.
	 */
	private function finish_full_scan( bool $interrupted = false ): void {
		$status                = $this->get_status();
		$status['state']       = 'idle';
		$status['finished_at'] = current_time( 'mysql' );
		$status['interrupted'] = $interrupted;
		update_option( self::STATUS_OPTION, $status, false );
		$this->clear_queue();
		delete_option( self::LEGACY_WORKLIST_OPTION );
		wp_clear_scheduled_hook( self::FULL_SCAN_EVENT );

		if ( $interrupted ) {
			return;
		}

		update_option(
			self::STATS_OPTION,
			array(
				'last_scan_at'     => current_time( 'mysql' ),
				'files_scanned'    => $status['scanned'] ?? 0,
				'detections_found' => $status['detections'] ?? 0,
			),
			false
		);
	}

	/* ----- queue table helpers ----- */

	public static function queue_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'uxstudio_security_scan_queue';
	}

	private function queue_count(): int {
		global $wpdb;
		return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . self::queue_table() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	private function clear_queue(): void {
		global $wpdb;
		$wpdb->query( 'DELETE FROM ' . self::queue_table() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Bulk-insert paths in chunks (a few hundred rows per statement).
	 *
	 * @param string[] $paths File paths.
	 */
	private function enqueue_paths( array $paths ): void {
		global $wpdb;
		$queue = self::queue_table();
		foreach ( array_chunk( $paths, self::QUEUE_INSERT_CHUNK ) as $chunk ) {
			$placeholders = implode( ',', array_fill( 0, count( $chunk ), '(%s)' ) );
			$wpdb->query( $wpdb->prepare( "INSERT INTO {$queue} (file_path) VALUES {$placeholders}", $chunk ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
	}

	/**
	 * @return array{state:string,total:int,remaining:int,scanned:int,detections:int}
	 */
	public function get_status(): array {
		$status = get_option( self::STATUS_OPTION, array() );
		return is_array( $status ) ? array_merge(
			array(
				'state'      => 'idle',
				'total'      => 0,
				'remaining'  => 0,
				'scanned'    => 0,
				'detections' => 0,
			),
			$status
		) : array(
			'state'      => 'idle',
			'total'      => 0,
			'remaining'  => 0,
			'scanned'    => 0,
			'detections' => 0,
		);
	}

	/**
	 * @return array Persisted last-run stats.
	 */
	public function get_stats(): array {
		$stats = get_option( self::STATS_OPTION, array() );
		return is_array( $stats ) ? $stats : array();
	}

	/**
	 * Scan a single file found during the full walk. Skips unchanged files
	 * via the shared hash cache. Report only - never touches the file.
	 *
	 * @return array|null Finding data if a detection was recorded, else null.
	 */
	private function scan_full_scan_file( string $file_path ): ?array {
		global $wpdb;

		if ( ! file_exists( $file_path ) ) {
			return null;
		}
		$size = (int) filesize( $file_path );
		if ( $size <= 0 || $size > self::MAX_FILE_SIZE ) {
			return null;
		}

		$hash = hash_file( 'sha256', $file_path );
		if ( false === $hash ) {
			return null;
		}

		if ( $this->is_hash_cleared( $file_path, $hash ) ) {
			return null;
		}

		$result = $this->evaluate( $file_path );

		$this->cache_hash( $file_path, $hash );

		if ( null === $result ) {
			return null;
		}

		$table = $wpdb->prefix . 'uxstudio_security_findings';
		$data  = array(
			'created_at'   => current_time( 'mysql' ),
			'file_path'    => $file_path,
			'finding_type' => 'malware',
			'severity'     => $result['severity'],
			'hash'         => $hash,
			'status'       => 'scanned',
			'details'      => wp_json_encode( array_merge( $result['details'], array( 'source' => 'fullscan' ) ) ),
			'scanned_at'   => current_time( 'mysql' ),
		);

		$wpdb->insert( $table, $data, array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' ) );

		return $data;
	}

	/**
	 * Build a bounded list of candidate files, most suspicious places first:
	 * uploads (where no PHP belongs), mu-plugins, wp-content root drop-ins,
	 * then plugins and themes. Never walks other wp-content folders (cache,
	 * upgrade, backups...) and prunes excluded folders (our own plugins,
	 * vendor/node_modules...) without descending into them.
	 *
	 * @return string[]
	 */
	private function build_worklist(): array {
		$uploads = wp_upload_dir( null, false );
		$roots   = array_filter(
			array(
				empty( $uploads['basedir'] ) ? '' : $uploads['basedir'],
				WPMU_PLUGIN_DIR,
				WP_PLUGIN_DIR,
				get_theme_root(),
			)
		);

		$files = $this->root_php_files( WP_CONTENT_DIR );

		foreach ( $roots as $root ) {
			$root = wp_normalize_path( (string) $root );
			if ( ! is_dir( $root ) ) {
				continue;
			}
			$area = UploadGuardScope::area( $root . '/x' );

			try {
				$iterator = new \RecursiveIteratorIterator(
					new \RecursiveCallbackFilterIterator(
						new \RecursiveDirectoryIterator( $root, \RecursiveDirectoryIterator::SKIP_DOTS ),
						// Name-only check, no stat: isDir() per entry costs ~1 ms on
						// some filesystems and uploads easily hold 60k+ images.
						static function ( \SplFileInfo $item ): bool {
							return ! UploadGuardScope::is_excluded_dir( $item->getPathname() );
						}
					)
				);
				foreach ( $iterator as $file_info ) {
					if ( count( $files ) >= self::MAX_WORKLIST ) {
						break 2;
					}
					$path = wp_normalize_path( $file_info->getPathname() );
					if ( UploadGuardScope::is_relevant( $path, $area ) && ! UploadGuardScope::is_excluded( $path ) && $file_info->isFile() ) {
						$files[] = $path;
					}
				}
			} catch ( \Throwable $e ) {
				continue; // Unreadable folder: scan the rest.
			}
		}

		return array_slice( array_values( array_unique( $files ) ), 0, self::MAX_WORKLIST );
	}

	/**
	 * PHP files directly in a folder (drop-ins in wp-content), not recursive.
	 *
	 * @return string[]
	 */
	private function root_php_files( string $dir ): array {
		$files = array();
		foreach ( glob( trailingslashit( $dir ) . '*' ) ?: array() as $path ) {
			$path = wp_normalize_path( $path );
			if ( is_file( $path ) && in_array( strtolower( pathinfo( $path, PATHINFO_EXTENSION ) ), UploadGuardScope::PHP_EXTENSIONS, true ) ) {
				$files[] = $path;
			}
		}
		return $files;
	}

	/**
	 * Check the shared hash cache table: true if this exact file+hash pair
	 * was already scanned and is either unchanged since the last pass or
	 * explicitly approved by an admin.
	 */
	private function is_hash_cleared( string $file_path, string $hash ): bool {
		global $wpdb;
		$table = $wpdb->prefix . 'uxstudio_security_file_hashes';
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT hash, approved FROM {$table} WHERE file_path = %s", $file_path ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		if ( ! $row ) {
			return false;
		}

		return $row->hash === $hash;
	}

	/**
	 * Upsert the scan-time hash cache for a file.
	 */
	private function cache_hash( string $file_path, string $hash ): void {
		global $wpdb;
		$table = $wpdb->prefix . 'uxstudio_security_file_hashes';

		$exists = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE file_path = %s", $file_path ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		if ( $exists ) {
			$wpdb->update(
				$table,
				array(
					'hash'       => $hash,
					'updated_at' => current_time( 'mysql' ),
				),
				array( 'file_path' => $file_path ),
				array( '%s', '%s' ),
				array( '%s' )
			);
			return;
		}

		$wpdb->insert(
			$table,
			array(
				'file_path'  => $file_path,
				'hash'       => $hash,
				'approved'   => 0,
				'updated_at' => current_time( 'mysql' ),
			),
			array( '%s', '%s', '%d', '%s' )
		);
	}

	/* ==========================================================
	 * Daily cron
	 * ========================================================== */

	/**
	 * Nightly: drain any stuck queue, run the full scan, check integrity.
	 */
	public function run_daily_cron(): void {
		$this->run_queue_batch();
		$this->trigger_full_scan();
		( new UploadGuardIntegrityChecker() )->run();
	}
}
