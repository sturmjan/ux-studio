<?php
/**
 * Maps a (bot category, load tier) pair to a throttle plan and applies it.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\BotThrottle;

defined( 'ABSPATH' ) || exit;

/**
 * Given the per-category rule and the current tier, decide the action
 * (pass / delay / microcache / block), the delay in ms and the HTTP status.
 */
final class Throttler {

	/** @var array<string,array{action:string}> */
	private array $rules;

	/** @var array<string,int> */
	private array $delays;

	/**
	 * @param array $rules  category id => [ action ].
	 * @param array $delays delay bounds (min_delay_ms, light_*, aggressive_*, red_*).
	 */
	public function __construct( array $rules = array(), array $delays = array() ) {
		$this->rules  = $rules;
		$this->delays = array_merge(
			array(
				'min_delay_ms'   => 0,
				'light_min'      => 300,
				'light_max'      => 1500,
				'aggressive_min' => 2000,
				'aggressive_max' => 5000,
				'red_min'        => 5000,
				'red_max'        => 10000,
			),
			$delays
		);
	}

	/**
	 * Planned action for a bot category at a given tier.
	 *
	 * @param string $category Bot category id.
	 * @param string $tier     Load tier constant.
	 * @return array{action:string,delay_ms:int,status:int}
	 */
	public function plan( string $category, string $tier ): array {
		$rule = $this->rules[ $category ]['action'] ?? $this->default_action_for_category( $category );

		// Search engines: never blocked, only mildly delayed, to avoid deindexing.
		if ( 'search_engines' === $category ) {
			switch ( $tier ) {
				case 'GREEN':
					return array( 'action' => 'pass', 'delay_ms' => 0, 'status' => 200 );
				case 'YELLOW':
					return array( 'action' => 'delay', 'delay_ms' => $this->delays['min_delay_ms'], 'status' => 200 );
				case 'ORANGE':
					return array( 'action' => 'delay', 'delay_ms' => $this->jitter( 500, 1500 ), 'status' => 200 );
				case 'RED':
					return array( 'action' => 'delay', 'delay_ms' => 1000, 'status' => 200 );
			}
		}

		switch ( $rule ) {
			case 'pass':
				return array( 'action' => 'pass', 'delay_ms' => 0, 'status' => 200 );

			case 'pass_with_min_delay':
				$d = 'GREEN' === $tier ? 0 : ( 'YELLOW' === $tier ? 200 : ( 'ORANGE' === $tier ? 800 : 1500 ) );
				return array( 'action' => $d > 0 ? 'delay' : 'pass', 'delay_ms' => $d, 'status' => 200 );

			case 'throttle_light':
				return $this->throttle_light( $tier );

			case 'throttle_aggressive':
				return $this->throttle_aggressive( $tier );

			case 'block':
				return array( 'action' => 'block', 'delay_ms' => 0, 'status' => 429 );

			default:
				return $this->throttle_light( $tier );
		}
	}

	/**
	 * @param string $tier Load tier constant.
	 * @return array{action:string,delay_ms:int,status:int}
	 */
	private function throttle_light( string $tier ): array {
		switch ( $tier ) {
			case 'GREEN':
				return array( 'action' => 'pass', 'delay_ms' => 0, 'status' => 200 );
			case 'YELLOW':
				return array( 'action' => 'delay', 'delay_ms' => $this->jitter( $this->delays['light_min'], $this->delays['light_max'] ), 'status' => 200 );
			case 'ORANGE':
				return array( 'action' => 'microcache', 'delay_ms' => $this->jitter( 1000, 3000 ), 'status' => 200 );
			case 'RED':
				return array( 'action' => 'microcache', 'delay_ms' => $this->jitter( $this->delays['red_min'], $this->delays['red_max'] ), 'status' => 200 );
		}
		return array( 'action' => 'pass', 'delay_ms' => 0, 'status' => 200 );
	}

	/**
	 * @param string $tier Load tier constant.
	 * @return array{action:string,delay_ms:int,status:int}
	 */
	private function throttle_aggressive( string $tier ): array {
		switch ( $tier ) {
			case 'GREEN':
				return array( 'action' => 'delay', 'delay_ms' => $this->jitter( 200, 800 ), 'status' => 200 );
			case 'YELLOW':
				return array( 'action' => 'delay', 'delay_ms' => $this->jitter( $this->delays['aggressive_min'], $this->delays['aggressive_max'] ), 'status' => 200 );
			case 'ORANGE':
				return array( 'action' => 'microcache', 'delay_ms' => $this->jitter( 3000, 6000 ), 'status' => 200 );
			case 'RED':
				return array( 'action' => 'block', 'delay_ms' => 0, 'status' => 503 );
		}
		return array( 'action' => 'pass', 'delay_ms' => 0, 'status' => 200 );
	}

	/**
	 * Sleep for the planned delay (capped at 10s).
	 *
	 * @param int $delay_ms Delay in milliseconds.
	 */
	public function apply_delay( int $delay_ms ): void {
		if ( $delay_ms <= 0 ) {
			return;
		}
		$delay_ms = min( 10000, $delay_ms );
		usleep( $delay_ms * 1000 );
	}

	/**
	 * Send a 503/429 block response with a Retry-After header, then the caller
	 * should exit.
	 *
	 * @param int $status      429 or 503.
	 * @param int $retry_after Retry-After seconds.
	 */
	public function send_block_response( int $status, int $retry_after = 60 ): void {
		if ( headers_sent() ) {
			return;
		}
		$status = 503 === $status ? 503 : 429;
		status_header( $status );
		nocache_headers();
		header( 'Retry-After: ' . max( 1, $retry_after ) );
		header( 'X-UXS-BotThrottle: ' . $status );
		echo 503 === $status
			? esc_html( "Service temporarily unavailable - please retry after {$retry_after}s." ) . "\n"
			: esc_html( "Rate limit exceeded - please retry after {$retry_after}s." ) . "\n";
	}

	/**
	 * @param int $min Lower bound.
	 * @param int $max Upper bound.
	 */
	private function jitter( int $min, int $max ): int {
		if ( $min >= $max ) {
			return $min;
		}
		return wp_rand( $min, $max );
	}

	/**
	 * @param string $category Bot category id.
	 */
	private function default_action_for_category( string $category ): string {
		$defaults = Signatures::categories();
		return $defaults[ $category ]['default_action'] ?? 'throttle_light';
	}
}
