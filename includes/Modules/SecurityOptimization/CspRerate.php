<?php
/**
 * Re-rating of the CSP violations collected before the rating existed (schema v5), in small batches.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\SecurityOptimization;

defined( 'ABSPATH' ) || exit;

/**
 * The v5 schema step used to rate every stored row inside the one request that first loaded the new code.
 * On a site with a long violation history that request could run into the PHP time limit. Now the schema step
 * (dbDelta, fast) only calls start(), and the rows are rated here, BATCH at a time, by a short WP-Cron chain
 * protected by a lock.
 *
 * Rows that existed before the update never trigger the high-risk e-mail: the e-mail is for NEW reports
 * (see legacy_max_id()). An administrator sees them in the CSP overview as before.
 */
final class CspRerate {

	/** WP-Cron hook of one batch. */
	public const HOOK = 'uxstudio_csp_rerate';

	/** Autoloaded: array{last_id:int,max_id:int,started:int} while work is left; the option is gone when done. */
	public const STATE_OPTION = 'uxstudio_csp_rerate_state';

	/** Autoloaded int: the highest row id that existed when the update ran (kept for good). */
	public const LEGACY_OPTION = 'uxstudio_csp_legacy_max_id';

	/** Non-autoloaded lock (add_option is atomic): unix time it was taken. */
	public const LOCK_OPTION = 'uxstudio_csp_rerate_lock';

	public const BATCH = 200;

	/** A lock older than this is a crashed run and is taken over. */
	private const LOCK_TTL = 300;

	/** Delay between two batches. */
	private const DELAY = 20;

	/** @var int|null Per request cache of legacy_max_id(). */
	private static ?int $legacy = null;

	/**
	 * Called from the schema step (cheap): remember which rows are "old" and queue the batches.
	 */
	public static function start(): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$max = (int) $wpdb->get_var( 'SELECT MAX(id) FROM ' . CspLogger::table() );
		update_option( self::LEGACY_OPTION, max( $max, (int) get_option( self::LEGACY_OPTION, 0 ) ), true );
		self::$legacy = null;
		if ( $max > 0 ) {
			update_option(
				self::STATE_OPTION,
				array(
					'last_id' => 0,
					'max_id'  => $max,
					'started' => time(),
				),
				true
			);
		}
	}

	/**
	 * Is there still work?
	 */
	public static function pending(): bool {
		return is_array( get_option( self::STATE_OPTION, false ) );
	}

	/**
	 * Keep one batch event scheduled while work is left (cheap: one autoloaded option on normal requests).
	 */
	public static function ensure_scheduled(): void {
		if ( self::pending() && ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_single_event( time() + self::DELAY, self::HOOK );
		}
	}

	/**
	 * Highest id of a row that existed before the update. Such rows never cause the high-risk e-mail.
	 */
	public static function legacy_max_id(): int {
		if ( null === self::$legacy ) {
			self::$legacy = (int) get_option( self::LEGACY_OPTION, 0 );
		}
		return self::$legacy;
	}

	/**
	 * Rate the next batch of old rows.
	 *
	 * @return int Rows rated (0 when nothing was left or another run holds the lock).
	 */
	public static function run_batch(): int {
		$state = get_option( self::STATE_OPTION, false );
		if ( ! is_array( $state ) ) {
			return 0;
		}
		if ( ! self::take_lock() ) {
			return 0;
		}
		global $wpdb;
		$table = CspLogger::table();
		$site  = CspLogger::site_host();
		$last  = (int) ( $state['last_id'] ?? 0 );
		$max   = (int) ( $state['max_id'] ?? 0 );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT id, blocked_uri, directive FROM {$table} WHERE id > %d AND id <= %d ORDER BY id ASC LIMIT %d", $last, $max, self::BATCH ), ARRAY_A );
		foreach ( $rows as $row ) {
			// Advance past the row first: one poison row must not stall every later batch.
			$last = (int) $row['id'];
			try {
				$rating = CspRisk::rate( (string) $row['blocked_uri'], (string) $row['directive'], $site );
				$wpdb->update(
					$table,
					array(
						'risk_level'      => $rating['level'],
						'risk_score'      => $rating['score'],
						'risk_reasons'    => wp_json_encode( $rating['reasons'] ),
						'service'         => $rating['service'],
						'directive'       => CspPolicy::base_directive( (string) strtok( (string) $row['directive'], ' ' ) ),
						// The domain-age check refines the rating again; it must not be skipped for this row.
						'risk_checked_at' => null,
					),
					array( 'id' => (int) $row['id'] )
				);
			} catch ( \Throwable $e ) {
				error_log( sprintf( 'UX Studio: CSP re-rating skipped row %d: %s', $last, $e->getMessage() ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			}
		}
		if ( count( $rows ) < self::BATCH ) {
			delete_option( self::STATE_OPTION );
		} else {
			$state['last_id'] = $last;
			update_option( self::STATE_OPTION, $state, true );
			if ( ! wp_next_scheduled( self::HOOK ) ) {
				wp_schedule_single_event( time() + self::DELAY, self::HOOK );
			}
		}
		delete_option( self::LOCK_OPTION );
		return count( $rows );
	}

	/**
	 * Atomic lock: add_option() fails when the row exists. A stale lock (crashed run) is taken over.
	 */
	private static function take_lock(): bool {
		if ( add_option( self::LOCK_OPTION, (string) time(), '', 'no' ) ) {
			return true;
		}
		$taken = (int) get_option( self::LOCK_OPTION, 0 );
		if ( time() - $taken > self::LOCK_TTL ) {
			update_option( self::LOCK_OPTION, (string) time(), false );
			return true;
		}
		return false;
	}
}
