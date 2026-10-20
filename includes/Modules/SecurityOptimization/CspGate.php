<?php
/**
 * Conditions for switching CSP to Enforce.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\SecurityOptimization;

defined( 'ABSPATH' ) || exit;

/**
 * Enforce blocks for real, so it is only offered once the test policy has
 * been running long enough without anything it would block. The settings
 * save path enforces this server-side; an explicit override ("I understand
 * the risk") is possible but never stored.
 */
final class CspGate {

	public const TEST_DAYS = 7;

	/**
	 * @return array<int,array{id:string,ok:bool,value:int}>
	 */
	public static function checks(): array {
		$state = CspPolicy::state();
		$stats = CspLogger::stats();

		$test_days = 'report-only' === $state['mode'] && $state['since'] > 0
			? (int) floor( ( time() - $state['since'] ) / DAY_IN_SECONDS )
			: 0;

		return array(
			array(
				'id'    => 'header_delivered',
				'ok'    => CspHeaderProbe::passed_recently(),
				'value' => 0,
			),
			array(
				'id'    => 'test_days',
				'ok'    => $test_days >= self::TEST_DAYS,
				'value' => $test_days,
			),
			array(
				'id'    => 'no_test_violations',
				'ok'    => 0 === $stats['candidate_open_7d'],
				'value' => $stats['candidate_open_7d'],
			),
			array(
				'id'    => 'no_high_risk',
				'ok'    => 0 === $stats['high'],
				'value' => $stats['high'],
			),
		);
	}

	public static function ready(): bool {
		foreach ( self::checks() as $check ) {
			if ( ! $check['ok'] ) {
				return false;
			}
		}
		return true;
	}
}
