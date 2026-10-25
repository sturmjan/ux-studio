<?php
/**
 * Source: Simple History, WP Activity Log (WP Security Audit Log), Activity Log (AryoLabs), Activity Logs (Adminify).
 *
 * @package UxStudio
 */

namespace UxStudio\Core\Migration\Sources;

use UxStudio\Core\Migration\AbstractSource;
use UxStudio\Core\Migration\Mapping;

defined( 'ABSPATH' ) || exit;

/**
 * Target module `activity-log`: only the setting `retention_days` (0 = keep forever). The recorded history of the
 * other plugins is NOT imported - their event formats differ too much to map honestly, and the new log starts
 * from the moment the module is on. The plan says so.
 *
 * Counted as behaviour-changing: a shorter retention makes the module delete older entries, so with the module
 * on the value waits in the staging area like every other setting.
 *
 *  - Simple History: option `simple_history_retention_days`.
 *  - WP Activity Log: options `wsal_pruning-date-e` (yes = delete old) and `wsal_pruning-date` ("3 months").
 *  - Activity Log (Aryo): option `activity-log-settings` { logs_lifespan } days.
 *  - Activity Logs (Adminify): option `_adminify_activity_logs_days`.
 */
final class ActivityLogSource extends AbstractSource {

	private const MODULE = 'activity-log';

	protected function option_names(): array {
		switch ( $this->slug ) {
			case 'simple-history':
				return array( 'simple_history_retention_days' );
			case 'wp-security-audit-log':
				return array( 'wsal_pruning-date-e', 'wsal_pruning-date' );
			case 'aryo-activity-log':
				return array( 'activity-log-settings' );
			default:
				return array( '_adminify_activity_logs_days' );
		}
	}

	/**
	 * "3 months" / "1 year" / "30 days" → days.
	 *
	 * @param string $period Period as WP Activity Log stores it.
	 */
	public static function period_to_days( string $period ): int {
		if ( ! preg_match( '/^\s*(\d+)\s*(day|week|month|year)s?\s*$/i', $period, $m ) ) {
			return 0;
		}
		$factor = array(
			'day'   => 1,
			'week'  => 7,
			'month' => 30,
			'year'  => 365,
		)[ strtolower( $m[2] ) ];
		return (int) $m[1] * $factor;
	}

	protected function map( bool $active ): Mapping {
		$m    = new Mapping();
		$days = null;
		$from = $this->option_names()[0];
		switch ( $this->slug ) {
			case 'simple-history':
				$v    = $this->opt( 'simple_history_retention_days' );
				$days = null === $v ? null : max( 0, (int) $v );
				break;
			case 'wp-security-audit-log':
				if ( null !== $this->opt( 'wsal_pruning-date' ) || null !== $this->opt( 'wsal_pruning-date-e' ) ) {
					$on   = in_array( strtolower( (string) $this->opt( 'wsal_pruning-date-e' ) ), array( 'yes', '1', 'true' ), true );
					$days = $on ? self::period_to_days( (string) $this->opt( 'wsal_pruning-date' ) ) : 0;
					$from = 'wsal_pruning-date';
					if ( in_array( strtolower( (string) $this->opt( 'wsal_pruning-limit-e' ) ), array( 'yes', '1', 'true' ), true ) ) {
						$m->lossy( __( 'Keeping only the latest N events has no equivalent; the module deletes entries by age only.', 'ux-studio' ) );
					}
				}
				break;
			case 'aryo-activity-log':
				$o = $this->opt( 'activity-log-settings' );
				if ( is_array( $o ) && isset( $o['logs_lifespan'] ) ) {
					$days = max( 0, (int) $o['logs_lifespan'] );
					$from = 'activity-log-settings:logs_lifespan';
				}
				break;
			default:
				$v    = $this->opt( '_adminify_activity_logs_days' );
				$days = null === $v ? null : max( 0, (int) $v );
				break;
		}
		if ( null === $days ) {
			$m->note( __( 'The plugin keeps its default retention, there is nothing to transfer.', 'ux-studio' ) );
		} else {
			$m->set( self::MODULE, 'retention_days', $days, __( 'Retention (days)', 'ux-studio' ), $from );
		}
		$m->lossy( __( 'The recorded history of the plugin is not imported (the event formats differ), the new log starts when the module is on. Notifications, filters and exports of the old plugin are not transferred.', 'ux-studio' ) );
		return $m;
	}
}
