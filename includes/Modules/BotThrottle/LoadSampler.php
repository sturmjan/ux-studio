<?php
/**
 * Sliding-window server load sampling -> traffic tier (GREEN/YELLOW/ORANGE/RED).
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\BotThrottle;

defined( 'ABSPATH' ) || exit;

/**
 * Records per-request metrics (response time, query count, peak memory) into a
 * 60-second sliding window and derives a 0-100 load score + tier from it.
 * Storage: APCu when available (every request, raw entries). Without APCu a
 * small per-10s-bucket aggregate (count + sums) lives in a non-autoloaded
 * option and only 1 in SAMPLE_RATE requests writes it - the score only needs
 * averages, which sampling keeps unbiased, and the option is no longer
 * rewritten (and raced) on every request.
 */
final class LoadSampler {

	private const KEY      = 'uxstudio_bt_load_window';
	private const AGG_KEY  = 'uxstudio_bt_load_agg';
	private const TIER_KEY = 'uxstudio_bt_current_tier';

	/** Without APCu, 1 in N requests is recorded (filter: uxstudio_bt_sample_rate). */
	private const SAMPLE_RATE = 20;

	/** Aggregate bucket width in seconds. */
	private const BUCKET = 10;

	public const TIER_GREEN  = 'GREEN';
	public const TIER_YELLOW = 'YELLOW';
	public const TIER_ORANGE = 'ORANGE';
	public const TIER_RED    = 'RED';

	/** @var array{yellow:int,orange:int,red:int} */
	private array $thresholds;

	/**
	 * @param array $thresholds yellow/orange/red score cut-offs.
	 */
	public function __construct( array $thresholds = array() ) {
		$this->thresholds = array_merge(
			array(
				'yellow' => 50,
				'orange' => 75,
				'red'    => 90,
			),
			$thresholds
		);
	}

	/**
	 * Record one finished request's metrics into the sliding window.
	 *
	 * @param float $response_time_ms Wall-clock request time in ms.
	 * @param int   $queries          Number of DB queries.
	 * @param float $mem_mb           Peak memory in MB.
	 */
	public function record( float $response_time_ms, int $queries, float $mem_mb ): void {
		$now = time();

		if ( ! self::has_apcu() ) {
			$rate = max( 1, (int) apply_filters( 'uxstudio_bt_sample_rate', self::SAMPLE_RATE ) );
			if ( $rate > 1 && 1 !== wp_rand( 1, $rate ) ) {
				return;
			}
			$this->record_aggregate( $now, $response_time_ms, $queries, $mem_mb );
			return;
		}

		$window   = $this->load_window();
		$window[] = array(
			't'  => $now,
			'rt' => $response_time_ms,
			'q'  => $queries,
			'm'  => $mem_mb,
		);

		$window = array_values( array_filter( $window, static fn ( $e ) => ( $now - $e['t'] ) <= 60 ) );
		if ( count( $window ) > 500 ) {
			$window = array_slice( $window, -500 );
		}
		$this->save_window( $window );
	}

	/**
	 * Fold one sampled request into the per-bucket aggregate option (no APCu).
	 *
	 * @param int   $now              Current timestamp.
	 * @param float $response_time_ms Wall-clock request time in ms.
	 * @param int   $queries          Number of DB queries.
	 * @param float $mem_mb           Peak memory in MB.
	 */
	private function record_aggregate( int $now, float $response_time_ms, int $queries, float $mem_mb ): void {
		$agg    = $this->load_aggregate( $now );
		$bucket = $now - ( $now % self::BUCKET );
		$row    = $agg[ $bucket ] ?? array( 0, 0.0, 0, 0.0 );

		$agg[ $bucket ] = array(
			(int) $row[0] + 1,
			round( (float) $row[1] + $response_time_ms, 1 ),
			(int) $row[2] + $queries,
			round( (float) $row[3] + $mem_mb, 1 ),
		);
		update_option( self::AGG_KEY, $agg, false );
	}

	/**
	 * Aggregate buckets from the last 60 s: bucket start => [count, rt sum, q sum, mem sum].
	 *
	 * @param int $now Current timestamp.
	 * @return array<int,array{0:int,1:float,2:int,3:float}>
	 */
	private function load_aggregate( int $now ): array {
		$agg = get_option( self::AGG_KEY, array() );
		if ( ! is_array( $agg ) ) {
			return array();
		}
		return array_filter(
			$agg,
			static fn ( $row, $bucket ) => ( $now - (int) $bucket ) <= 60 && is_array( $row ) && 4 === count( $row ),
			ARRAY_FILTER_USE_BOTH
		);
	}

	/**
	 * Window totals regardless of storage: [count, rt sum, q sum, mem sum].
	 *
	 * @return array{0:int,1:float,2:float,3:float}
	 */
	private function totals(): array {
		$n  = 0;
		$rt = 0.0;
		$q  = 0.0;
		$m  = 0.0;
		if ( self::has_apcu() ) {
			foreach ( $this->load_window() as $e ) {
				++$n;
				$rt += (float) $e['rt'];
				$q  += (float) $e['q'];
				$m  += (float) $e['m'];
			}
		} else {
			foreach ( $this->load_aggregate( time() ) as $row ) {
				$n  += (int) $row[0];
				$rt += (float) $row[1];
				$q  += (float) $row[2];
				$m  += (float) $row[3];
			}
		}
		return array( $n, $rt, $q, $m );
	}

	private static function has_apcu(): bool {
		return function_exists( 'apcu_fetch' ) && function_exists( 'apcu_enabled' ) && apcu_enabled();
	}

	/**
	 * Current load score (0-100) and tier, with RED->lower hysteresis.
	 *
	 * @return array{tier:string,score:float}
	 */
	public function current_tier(): array {
		$score = $this->compute_score( $this->totals() );
		$tier  = $this->score_to_tier( $score );

		// Hysteresis: hold RED for up to 30s while the score is still above 80,
		// so a brief dip doesn't flap the tier back and forth.
		$cached = $this->load_cached_tier();
		if ( null !== $cached && self::TIER_RED === $cached['tier'] && self::TIER_RED !== $tier ) {
			if ( time() - $cached['since'] < 30 && $score > 80 ) {
				$tier = self::TIER_RED;
			}
		}

		if ( null === $cached || $cached['tier'] !== $tier ) {
			$this->save_cached_tier( $tier );
		}

		return array(
			'tier'  => $tier,
			'score' => $score,
		);
	}

	/**
	 * Weighted score from window averages, falling back to system load when the
	 * window is empty.
	 *
	 * @param array{0:int,1:float,2:float,3:float} $totals Window totals [count, rt, q, mem].
	 */
	private function compute_score( array $totals ): float {
		list( $n, $rt, $q, $m ) = $totals;
		if ( $n <= 0 ) {
			return $this->system_load_percent() ?? 0.0;
		}

		$avg_rt = $rt / $n;
		$avg_q  = $q / $n;
		$avg_m  = $m / $n;

		$rt_score  = min( 100, ( $avg_rt / 2000 ) * 100 );  // 2s = 100.
		$q_score   = min( 100, ( $avg_q / 100 ) * 100 );    // 100 queries = 100.
		$m_score   = min( 100, ( $avg_m / 256 ) * 100 );    // 256 MB = 100.
		$sys_score = $this->system_load_percent() ?? 0;

		return round( ( $rt_score * 0.40 ) + ( $q_score * 0.30 ) + ( $m_score * 0.20 ) + ( $sys_score * 0.10 ), 1 );
	}

	/**
	 * System load average as a percentage of cores, or null when unavailable
	 * (e.g. Windows). UXSTUDIO_BT_CPU_CORES overrides the core count.
	 */
	private function system_load_percent(): ?float {
		if ( ! function_exists( 'sys_getloadavg' ) ) {
			return null;
		}
		$load = @sys_getloadavg();
		if ( ! is_array( $load ) || ! isset( $load[0] ) ) {
			return null;
		}
		$cores = (int) ( defined( 'UXSTUDIO_BT_CPU_CORES' ) ? UXSTUDIO_BT_CPU_CORES : 4 );
		return min( 100, ( $load[0] / max( 1, $cores ) ) * 100 );
	}

	/**
	 * @param float $score Load score 0-100.
	 */
	private function score_to_tier( float $score ): string {
		if ( $score >= $this->thresholds['red'] ) {
			return self::TIER_RED;
		}
		if ( $score >= $this->thresholds['orange'] ) {
			return self::TIER_ORANGE;
		}
		if ( $score >= $this->thresholds['yellow'] ) {
			return self::TIER_YELLOW;
		}
		return self::TIER_GREEN;
	}

	/**
	 * @return array<int,array{t:int,rt:float,q:int,m:float}>
	 */
	private function load_window(): array {
		$val = apcu_fetch( self::KEY, $ok );
		return $ok && is_array( $val ) ? $val : array();
	}

	/**
	 * @param array $window Sliding window entries.
	 */
	private function save_window( array $window ): void {
		apcu_store( self::KEY, $window, 120 );
	}

	/**
	 * Drop the stored window/aggregate (module switched off). The pre-aggregate
	 * option (~35 kB raw entries) is removed too.
	 */
	public static function purge(): void {
		delete_option( self::KEY );
		delete_option( self::AGG_KEY );
		delete_option( self::TIER_KEY );
		if ( self::has_apcu() ) {
			apcu_delete( self::KEY );
			apcu_delete( self::TIER_KEY );
		}
	}

	/**
	 * @return array{tier:string,since:int}|null
	 */
	private function load_cached_tier(): ?array {
		if ( self::has_apcu() ) {
			$val = apcu_fetch( self::TIER_KEY, $ok );
			if ( $ok && is_array( $val ) ) {
				return $val;
			}
		}
		$val = get_option( self::TIER_KEY, null );
		return is_array( $val ) ? $val : null;
	}

	/**
	 * @param string $tier Tier constant.
	 */
	private function save_cached_tier( string $tier ): void {
		$entry = array(
			'tier'  => $tier,
			'since' => time(),
		);
		if ( self::has_apcu() ) {
			apcu_store( self::TIER_KEY, $entry, 300 );
		} else {
			update_option( self::TIER_KEY, $entry, false );
		}
	}
}
