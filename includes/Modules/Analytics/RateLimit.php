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
	 * Check and record one attempt.
	 *
	 * @param string $action Endpoint identifier, e.g. 'hit'.
	 * @param int    $max    Max attempts within the window.
	 * @param int    $window Window length in seconds.
	 * @return bool True when the limit is exceeded (request should be dropped).
	 */
	public static function exceeded( string $action, int $max, int $window = HOUR_IN_SECONDS ): bool {
		if ( $max <= 0 ) {
			return false;
		}

		$key  = self::key( $action );
		$now  = time();
		$data = get_transient( $key );

		if ( ! is_array( $data ) || empty( $data['reset'] ) || $data['reset'] <= $now ) {
			$data = array(
				'count' => 0,
				'reset' => $now + $window,
			);
		}

		++$data['count'];
		set_transient( $key, $data, max( 1, $data['reset'] - $now ) );

		return $data['count'] > $max;
	}

	/**
	 * Transient key, scoped by client IP.
	 */
	private static function key( string $action ): string {
		$scope = $action . '|' . ClientIp::get();
		return 'uxstudio_analytics_rl_' . substr( hash( 'sha256', $scope . '|' . wp_salt() ), 0, 40 );
	}
}
