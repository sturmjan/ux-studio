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
 * Storage: APCu when available, otherwise a non-autoloaded option.
 */
final class LoadSampler {

	private const KEY      = 'uxstudio_bt_load_window';
	private const TIER_KEY = 'uxstudio_bt_current_tier';

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
		$now      = time();
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
	 * Current load score (0-100) and tier, with RED->lower hysteresis.
	 *
	 * @return array{tier:string,score:float}
	 */
	public function current_tier(): array {
		$window = $this->load_window();
		$score  = $this->compute_score( $window );
		$tier   = $this->score_to_tier( $score );

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
	 * @param array $window Sliding window entries.
	 */
	private function compute_score( array $window ): float {
		if ( empty( $window ) ) {
			return $this->system_load_percent() ?? 0.0;
		}

		$rt = array_column( $window, 'rt' );
		$q  = array_column( $window, 'q' );
		$m  = array_column( $window, 'm' );

		$avg_rt = array_sum( $rt ) / count( $rt );
		$avg_q  = array_sum( $q ) / count( $q );
		$avg_m  = array_sum( $m ) / count( $m );

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
		if ( function_exists( 'apcu_fetch' ) ) {
			$val = apcu_fetch( self::KEY, $ok );
			if ( $ok && is_array( $val ) ) {
				return $val;
			}
		}
		$val = get_option( self::KEY, array() );
		return is_array( $val ) ? $val : array();
	}

	/**
	 * @param array $window Sliding window entries.
	 */
	private function save_window( array $window ): void {
		if ( function_exists( 'apcu_store' ) ) {
			apcu_store( self::KEY, $window, 120 );
			return;
		}
		update_option( self::KEY, $window, false );
	}

	/**
	 * @return array{tier:string,since:int}|null
	 */
	private function load_cached_tier(): ?array {
		if ( function_exists( 'apcu_fetch' ) ) {
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
		if ( function_exists( 'apcu_store' ) ) {
			apcu_store( self::TIER_KEY, $entry, 300 );
		} else {
			update_option( self::TIER_KEY, $entry, false );
		}
	}
}
