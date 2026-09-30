<?php
/**
 * Hourly write-quota for the public (unauthenticated) hit-collector endpoint.
 *
 * Security::check_write_rate_limit() keys its bucket by get_current_user_id(),
 * which is 0 for every anonymous visitor and would throttle all of them
 * together. This module needs a bucket keyed by client IP instead.
 *
 * This is a generous fallback, not bot defense (see BotThrottle\Guard for
 * that, shared with the rest of the site) - it just caps how many rows one
 * IP can write into the stats buffer per hour, so a runaway script can't
 * flood the analytics DB table even while staying under the burst-ban
 * threshold.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\Analytics;

defined( 'ABSPATH' ) || exit;

final class RateLimit {

	/**
	 * Check and record one attempt. APCu when available, otherwise a single
	 * upsert into the small `uxstudio_analytics_rate` table (fixed windows),
	 * so a hit no longer rewrites transient option rows.
	 *
	 * @param string $action Endpoint identifier, e.g. 'hit'.
	 * @param int    $max    Max attempts within the window.
	 * @param int    $window Window length in seconds.
	 * @return bool True when the limit is exceeded (request should be dropped).
	 */
	public static function exceeded( string $action, int $max, int $window = HOUR_IN_SECONDS ): bool {
		global $wpdb;

		if ( $max <= 0 ) {
			return false;
		}

		$window = max( 1, $window );
		$key    = self::key( $action );
		$slot   = intdiv( time(), $window ) * $window;

		if ( function_exists( 'apcu_inc' ) && function_exists( 'apcu_enabled' ) && apcu_enabled() ) {
			$apcu_key = 'uxstudio_analytics_rl_' . $key . '_' . $slot;
			apcu_add( $apcu_key, 0, $window + 1 );
			return (int) apcu_inc( $apcu_key ) > $max;
		}

		$table = self::table();
		// A fresh row affects 1 row (count 1); an update hands the new count
		// back through LAST_INSERT_ID(expr) - no follow-up SELECT.
		$affected = $wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$table} (k, window_start, hits) VALUES (%s, %d, 1)
				ON DUPLICATE KEY UPDATE hits = LAST_INSERT_ID(hits + 1)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$key,
				$slot
			)
		);
		if ( false === $affected ) {
			return false; // Table missing (fail open - this is only a fallback cap).
		}
		$count = 1 === (int) $affected ? 1 : (int) $wpdb->insert_id;

		if ( 1 === wp_rand( 1, 200 ) ) {
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE window_start < %d", time() - 2 * DAY_IN_SECONDS ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}

		return $count > $max;
	}

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'uxstudio_analytics_rate';
	}

	/**
	 * Row key, scoped by client IP (never stored raw).
	 */
	private static function key( string $action ): string {
		$scope = $action . '|' . ClientIp::get();
		return substr( hash( 'sha256', $scope . '|' . wp_salt() ), 0, 40 );
	}
}
