<?php
/**
 * GDPR/retention auto-cleanup: an optional, per-form `retention_days` setting
 * (PLAN.md 20.2/20.6/20.11 F4) purged by one daily WP-Cron sweep.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\Forms;

defined( 'ABSPATH' ) || exit;

/**
 * The archive is permanent by default (PLAN.md 20.6) - `retention_days` is
 * `null` unless an admin explicitly opts a specific form into auto-deletion
 * from its Settings tab. This class only ever removes submissions belonging
 * to a form that has that setting turned on; forms without it are never
 * touched by the sweep. Follows the same schedule/unschedule pattern as
 * SecurityOptimization\CspUploadGuardBootstrap::ensure_cron_scheduled()/on_deactivate().
 */
final class Retention {

	public const CRON_HOOK = 'uxstudio_forms_retention_cron';

	/**
	 * Schedule the daily sweep if it isn't already - called from Module::boot().
	 */
	public static function ensure_cron_scheduled(): void {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( strtotime( 'tomorrow 03:20' ), 'daily', self::CRON_HOOK );
		}
	}

	/**
	 * Unschedules the sweep. Does NOT delete any data by itself - call from
	 * the plugin's own deactivation hook if desired.
	 */
	public static function on_deactivate(): void {
		$timestamp = wp_next_scheduled( self::CRON_HOOK );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, self::CRON_HOOK );
		}
	}

	/**
	 * Cron callback: for every form with a positive `retention_days`, delete
	 * submissions (+ their files/action log, via Submissions::delete()) older
	 * than that many days. Cheap to run daily - the forms table itself is
	 * small (one row per form, not per submission).
	 */
	public static function run(): void {
		global $wpdb;
		$rows = $wpdb->get_results( "SELECT id, settings_json FROM {$wpdb->prefix}uxstudio_forms", ARRAY_A );
		if ( ! is_array( $rows ) ) {
			return;
		}

		foreach ( $rows as $row ) {
			$settings = json_decode( (string) $row['settings_json'], true );
			$days     = is_array( $settings ) ? ( $settings['retention_days'] ?? null ) : null;
			if ( ! is_numeric( $days ) || (int) $days <= 0 ) {
				continue;
			}
			Submissions::delete_older_than( (int) $row['id'], (int) $days );
		}
	}
}
