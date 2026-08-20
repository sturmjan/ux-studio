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
 *  3. Full wp-content scan: builds a bounded file worklist once, then
 *     processes it N files at a time via chained wp_schedule_single_event
 *     calls (never a single unbounded loop/request) until the list is
 *     empty - used for both the manual "Scan now" REST action and the
 *     nightly cron.
 */
final class UploadGuardScanner {

	public const QUEUE_BATCH_EVENT = 'uxstudio_security_queue_scan_event';
	public const FULL_SCAN_EVENT   = 'uxstudio_security_full_scan_event';
	public const DAILY_CRON_HOOK   = 'uxstudio_security_daily_scan';

	private const WORKLIST_OPTION = 'uxstudio_security_scan_worklist';
	private const STATUS_OPTION   = 'uxstudio_security_scan_status';
	private const STATS_OPTION    = 'uxstudio_security_scan_stats';

	private const QUEUE_BATCH_SIZE = 25;
	private const FULL_BATCH_SIZE  = 40;
	private const MAX_WORKLIST     = 20000;

	/** Max file size scanned, in bytes (10 MB). Larger files are skipped. */
	private const MAX_FILE_SIZE = 10 * 1024 * 1024;

	private UploadGuardDetectionEngine $engine;

	public function __construct() {
		$this->engine = new UploadGuardDetectionEngine( array(), $this->whitelist_paths() );
	}

	/**
	 * @return string[] Extensions eligible for scanning.
	 */
	public static function relevant_extensions(): array {
		/**
		 * Filter the list of file extensions Upload Guard scans.
		 *
		 * @param string[] $extensions Extensions without a leading dot.
		 */
		return (array) apply_filters(
			'uxstudio_security_scan_extensions',
			array( 'php', 'phtml', 'phar', 'js', 'svg', 'html', 'htm', 'zip' )
		);
	}

	/**
	 * @return string[] Path prefixes to always treat as clean.
	 */
	private function whitelist_paths(): array {
		return (array) apply_filters( 'uxstudio_security_scan_whitelist_paths', array() );
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

		$extension = strtolower( pathinfo( $file_path, PATHINFO_EXTENSION ) );
		if ( ! in_array( $extension, self::relevant_extensions(), true ) ) {
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

		$extension = strtolower( pathinfo( $file_path, PATHINFO_EXTENSION ) );
		$result    = $this->engine->scan( $file_path, $extension );

		$this->cache_hash( $file_path, $hash );

		if ( $result['score'] <= 0 ) {
			$wpdb->delete( $table, array( 'id' => (int) $row->id ), array( '%d' ) );
			return null;
		}

		$data = array(
			'finding_type' => 'malware',
			'severity'     => $result['severity'],
			'hash'         => $hash,
			'details'      => wp_json_encode(
				array(
					'score'   => $result['score'],
					'matches' => array_slice( $result['matches'], 0, 25 ),
				)
			),
			'status'       => 'scanned',
			'scanned_at'   => current_time( 'mysql' ),
		);

		if ( 'critical' === $result['severity'] && $this->auto_quarantine_enabled() ) {
			$quarantine = UploadGuardQuarantine::quarantine_file( $file_path, (string) $row->id );
			if ( ! empty( $quarantine['success'] ) ) {
				$data['status']  = 'quarantined';
				$decoded         = json_decode( $data['details'], true );
				$decoded['quarantine_path'] = $quarantine['quarantine_path'];
				$data['details'] = wp_json_encode( $decoded );
			}
		}

		$wpdb->update( $table, $data, array( 'id' => (int) $row->id ), array( '%s', '%s', '%s', '%s', '%s', '%s' ), array( '%d' ) );

		return array_merge( array( 'file_path' => $file_path ), $data );
	}

	/**
	 * @return bool Whether critical findings should be auto-quarantined.
	 */
	private function auto_quarantine_enabled(): bool {
		return (bool) apply_filters( 'uxstudio_security_auto_quarantine', true );
	}

	/* ==========================================================
	 * Full wp-content scan (batched worklist)
	 * ========================================================== */

	/**
	 * Kick off (or resume) a full wp-content injection scan. Called by the
	 * manual "Scan now" REST action and by the daily cron. Builds a bounded
	 * worklist once, then processes it via chained single events.
	 */
	public function trigger_full_scan(): void {
		$status = $this->get_status();
		if ( 'running' === ( $status['state'] ?? '' ) ) {
			return; // Already in progress.
		}

		$worklist = $this->build_worklist();

		update_option(
			self::STATUS_OPTION,
			array(
				'state'      => 'running',
				'total'      => count( $worklist ),
				'remaining'  => count( $worklist ),
				'scanned'    => 0,
				'detections' => 0,
				'started_at' => current_time( 'mysql' ),
			),
			false
		);
		update_option( self::WORKLIST_OPTION, $worklist, false );

		if ( ! wp_next_scheduled( self::FULL_SCAN_EVENT ) ) {
			wp_schedule_single_event( time() + 5, self::FULL_SCAN_EVENT );
		}
	}

	/**
	 * Process one batch of the full-scan worklist; reschedules itself until
	 * the worklist is empty.
	 */
	public function run_full_scan_batch(): void {
		$worklist = get_option( self::WORKLIST_OPTION, array() );
		if ( ! is_array( $worklist ) || empty( $worklist ) ) {
			$this->finish_full_scan();
			return;
		}

		$batch    = array_splice( $worklist, 0, self::FULL_BATCH_SIZE );
		$status   = $this->get_status();
		$detected = array();

		foreach ( $batch as $file_path ) {
			$finding = $this->scan_full_scan_file( $file_path );
			if ( null !== $finding ) {
				$detected[] = $finding;
			}
		}

		$status['scanned']    = (int) ( $status['scanned'] ?? 0 ) + count( $batch );
		$status['remaining']  = count( $worklist );
		$status['detections'] = (int) ( $status['detections'] ?? 0 ) + count( $detected );

		update_option( self::WORKLIST_OPTION, $worklist, false );
		update_option( self::STATUS_OPTION, $status, false );

		if ( ! empty( $detected ) ) {
			( new UploadGuardNotifier() )->notify( $detected );
		}

		if ( ! empty( $worklist ) ) {
			if ( ! wp_next_scheduled( self::FULL_SCAN_EVENT ) ) {
				wp_schedule_single_event( time() + 5, self::FULL_SCAN_EVENT );
			}
			return;
		}

		$this->finish_full_scan();
	}

	/**
	 * Finalize a completed full scan run.
	 */
	private function finish_full_scan(): void {
		$status               = $this->get_status();
		$status['state']      = 'idle';
		$status['finished_at'] = current_time( 'mysql' );
		update_option( self::STATUS_OPTION, $status, false );
		delete_option( self::WORKLIST_OPTION );

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
	 * Scan a single file found during the full wp-content walk. Skips
	 * unchanged files via the shared hash cache and never quarantines
	 * automatically outside of 'critical' severity.
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

		$extension = strtolower( pathinfo( $file_path, PATHINFO_EXTENSION ) );
		$result    = $this->engine->scan( $file_path, $extension );

		$this->cache_hash( $file_path, $hash );

		if ( $result['score'] <= 0 || 'low' === $result['severity'] ) {
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
			'details'      => wp_json_encode(
				array(
					'score'   => $result['score'],
					'matches' => array_slice( $result['matches'], 0, 25 ),
					'source'  => 'fullscan',
				)
			),
			'scanned_at'   => current_time( 'mysql' ),
		);

		if ( 'critical' === $result['severity'] && $this->auto_quarantine_enabled() ) {
			$quarantine = UploadGuardQuarantine::quarantine_file( $file_path, 'fs' . time() . wp_generate_password( 6, false ) );
			if ( ! empty( $quarantine['success'] ) ) {
				$data['status']              = 'quarantined';
				$decoded                     = json_decode( $data['details'], true );
				$decoded['quarantine_path']  = $quarantine['quarantine_path'];
				$data['details']             = wp_json_encode( $decoded );
			}
		}

		$wpdb->insert( $table, $data, array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' ) );

		return $data;
	}

	/**
	 * Build a bounded list of candidate files under wp-content, excluding
	 * the quarantine directory itself.
	 *
	 * @return string[]
	 */
	private function build_worklist(): array {
		$root = wp_normalize_path( WP_CONTENT_DIR );
		if ( ! is_dir( $root ) ) {
			return array();
		}

		$extensions     = self::relevant_extensions();
		$quarantine_dir = wp_normalize_path( UploadGuardQuarantine::get_path() );
		$files          = array();

		try {
			$iterator = new \RecursiveIteratorIterator(
				new \RecursiveDirectoryIterator( $root, \RecursiveDirectoryIterator::SKIP_DOTS )
			);
		} catch ( \Throwable $e ) {
			return array();
		}

		foreach ( $iterator as $file_info ) {
			if ( count( $files ) >= self::MAX_WORKLIST ) {
				break;
			}
			if ( ! $file_info->isFile() ) {
				continue;
			}

			$path = wp_normalize_path( $file_info->getPathname() );
			if ( $quarantine_dir && 0 === strpos( $path, $quarantine_dir ) ) {
				continue;
			}

			$ext = strtolower( $file_info->getExtension() );
			if ( 'zip' === $ext || ! in_array( $ext, $extensions, true ) ) {
				// ZIP archives are only scanned when uploaded (queue path); a
				// full-tree ZIP scan is too expensive to run unattended.
				continue;
			}

			$files[] = $path;
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
