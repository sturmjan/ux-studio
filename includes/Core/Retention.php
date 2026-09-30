<?php
/**
 * Shared log-table retention helper.
 *
 * @package UxStudio
 */

namespace UxStudio\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Daily WP-Cron purge of rows older than N days from a module's log/stats
 * table. Modules schedule their own hook in boot() via ensure_scheduled(),
 * call purge() from the hook callback and unschedule() from on_disable().
 *
 * Deletes run in bounded batches (LIMIT) so a first purge of a huge table
 * never locks it for long; whatever is left is picked up the next day.
 */
final class Retention {

	/** Default retention in days for modules without a setting. */
	public const DEFAULT_DAYS = 180;

	/** Rows per DELETE batch. */
	private const BATCH = 5000;

	/** Max batches per run. */
	private const MAX_BATCHES = 20;

	/**
	 * Schedule a daily event unless it already is.
	 *
	 * @param string $hook Cron hook name.
	 */
	public static function ensure_scheduled( string $hook ): void {
		if ( ! wp_next_scheduled( $hook ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', $hook );
		}
	}

	/**
	 * Remove every scheduled occurrence of a hook.
	 *
	 * @param string $hook Cron hook name.
	 */
	public static function unschedule( string $hook ): void {
		wp_clear_scheduled_hook( $hook );
	}

	/**
	 * Retention (days) for a module without its own setting: DEFAULT_DAYS,
	 * filterable per table via `uxstudio_retention_days`. 0 = keep forever.
	 *
	 * @param string $table Table name without the WP prefix (e.g. uxstudio_popup_stats).
	 */
	public static function days_for( string $table ): int {
		/**
		 * Retention in days for a UX Studio log/stats table. 0 = keep forever.
		 *
		 * @param int    $days  Default retention.
		 * @param string $table Table name without the WP prefix.
		 */
		return max( 0, (int) apply_filters( 'uxstudio_retention_days', self::DEFAULT_DAYS, $table ) );
	}

	/**
	 * Delete rows whose $column is older than $days. The cutoff is computed in
	 * site-local time by default (most tables store current_time('mysql')),
	 * pass $gmt = true for columns stored in UTC. A few hours of offset is
	 * irrelevant at day granularity either way.
	 *
	 * @param string $table  Table name without the WP prefix.
	 * @param int    $days   Retention in days; <= 0 keeps everything.
	 * @param string $column DATETIME column (should be indexed).
	 * @param bool   $gmt    Whether the column is stored in UTC.
	 * @return int Rows deleted.
	 */
	public static function purge( string $table, int $days, string $column = 'created_at', bool $gmt = false ): int {
		if ( $days <= 0 ) {
			return 0;
		}
		global $wpdb;

		$table  = $wpdb->prefix . preg_replace( '/[^A-Za-z0-9_]/', '', $table );
		$column = preg_replace( '/[^A-Za-z0-9_]/', '', $column );
		$ts     = time() - $days * DAY_IN_SECONDS;
		$cutoff = $gmt ? gmdate( 'Y-m-d H:i:s', $ts ) : wp_date( 'Y-m-d H:i:s', $ts );

		// Table may not exist yet (module enabled but never booted a request).
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) !== $table ) {
			return 0;
		}

		$total = 0;
		for ( $i = 0; $i < self::MAX_BATCHES; $i++ ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- identifiers are sanitized above.
			$deleted = (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE {$column} < %s LIMIT %d", $cutoff, self::BATCH ) );
			$total  += $deleted;
			if ( $deleted < self::BATCH ) {
				break;
			}
		}
		return $total;
	}
}
