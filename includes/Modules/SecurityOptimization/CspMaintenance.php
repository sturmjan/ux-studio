<?php
/**
 * CSP background work: domain-age rating, retention, self-test, rollback, e-mail.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\SecurityOptimization;

use UxStudio\Core\ActivityLog;

defined( 'ABSPATH' ) || exit;

/**
 * One hourly WP-Cron event while CSP is on. Nothing here runs while a
 * browser report is received - that path only writes one row.
 */
final class CspMaintenance {

	public const CRON_HOOK   = 'uxstudio_csp_maintenance';
	public const NOTIFY_HOOK = 'uxstudio_csp_notify';

	private const DAILY_OPTION    = 'uxstudio_csp_daily_run';
	private const NOTIFIED_OPTION = 'uxstudio_csp_notified_at';

	/** RDAP lookups per run - a slow registry must not hold the cron long. */
	private const AGE_BATCH = 8;

	/** High-risk e-mail at most this often. */
	private const NOTIFY_WINDOW = 6 * HOUR_IN_SECONDS;

	/** Auto-rollback: window after enabling Enforce and its thresholds. */
	private const ROLLBACK_WINDOW = 72 * HOUR_IN_SECONDS;
	private const ROLLBACK_ROWS   = 3;
	private const ROLLBACK_HITS   = 3;

	/** Distinct reporting clients each rolled-back row (and the set overall) needs. */
	private const ROLLBACK_REPORTERS = 3;

	/** Settings option of the Security module. */
	private const SETTINGS_OPTION = 'uxstudio_security_optimization';

	/**
	 * Hook the cron handlers and keep the event scheduled only while CSP is on.
	 */
	public static function register( string $mode ): void {
		add_action( self::CRON_HOOK, array( self::class, 'run' ) );
		add_action( self::NOTIFY_HOOK, array( self::class, 'notify_high_risk' ) );

		$scheduled = wp_next_scheduled( self::CRON_HOOK );
		if ( 'off' === $mode ) {
			if ( $scheduled ) {
				wp_clear_scheduled_hook( self::CRON_HOOK );
			}
			return;
		}
		if ( ! $scheduled ) {
			wp_schedule_event( time() + 5 * MINUTE_IN_SECONDS, 'hourly', self::CRON_HOOK );
		}
	}

	public static function unschedule(): void {
		wp_clear_scheduled_hook( self::CRON_HOOK );
		wp_clear_scheduled_hook( self::NOTIFY_HOOK );
	}

	/**
	 * Ask for a high-risk e-mail shortly (batches a burst of new rows into one).
	 */
	public static function queue_notification(): void {
		if ( ! wp_next_scheduled( self::NOTIFY_HOOK ) ) {
			wp_schedule_single_event( time() + 2 * MINUTE_IN_SECONDS, self::NOTIFY_HOOK );
		}
	}

	/**
	 * Hourly run.
	 */
	public static function run(): void {
		$settings = (array) get_option( self::SETTINGS_OPTION, array() );
		$mode     = (string) ( $settings['csp_mode'] ?? 'off' );
		if ( 'off' === $mode ) {
			return;
		}

		self::rate_domain_ages();

		if ( 'enforce' === $mode && ! empty( $settings['csp_auto_rollback'] ?? true ) ) {
			self::maybe_roll_back();
		}

		$last_daily = (int) get_option( self::DAILY_OPTION, 0 );
		if ( time() - $last_daily >= DAY_IN_SECONDS ) {
			update_option( self::DAILY_OPTION, time(), false );
			CspLogger::purge();
			CspHeaderProbe::run( $mode, (int) ( $settings['csp_sample_rate'] ?? 100 ) );
		}
	}

	/**
	 * Refine the rating of new unknown hosts with their registration date.
	 */
	private static function rate_domain_ages(): void {
		$site       = CspLogger::site_host();
		$escalated  = false;
		foreach ( CspLogger::rows_needing_age_check( self::AGE_BATCH ) as $row ) {
			$host    = (string) $row['blocked_host'];
			$created = '' !== $host && str_contains( $host, '.' ) ? CspRdap::registered_at( CspRisk::registrable_domain( $host ) ) : 0;
			$rating  = CspRisk::rate( (string) $row['blocked_uri'], (string) $row['directive'], $site, (string) $row['script_sample'] );
			$rating  = CspRisk::with_domain_age( $rating, $created, (string) $row['directive'] );
			CspLogger::update_rating( (int) $row['id'], $rating, $created );

			// Rows that existed before the update never e-mail (only reports received after it do).
			if ( CspRisk::LEVEL_HIGH === $rating['level'] && CspRisk::LEVEL_HIGH !== $row['risk_level'] && 'open' === $row['status'] && (int) $row['id'] > CspRerate::legacy_max_id() ) {
				$escalated = true;
			}
		}
		if ( $escalated ) {
			self::queue_notification();
		}
	}

	/**
	 * Enforce just broke something visitors use: several new blocked
	 * script/connect/frame origins, each reported repeatedly, within 72 h
	 * of switching on. Go back to the test policy and tell the admin.
	 * High-risk rows don't count - blocking those is the point.
	 */
	private static function maybe_roll_back(): void {
		$state = CspPolicy::state();
		if ( $state['enforce_since'] <= 0 || time() - $state['enforce_since'] > self::ROLLBACK_WINDOW ) {
			return;
		}

		// The report token is public (it is in every response header), so one
		// client can inflate hit counts. Only rows reported by several distinct
		// clients count, and the rows together need that many as well.
		$rows = CspLogger::blocked_since( gmdate( 'Y-m-d H:i:s', $state['enforce_since'] ), self::ROLLBACK_HITS, self::ROLLBACK_REPORTERS );
		if ( count( $rows ) < self::ROLLBACK_ROWS ) {
			return;
		}
		$all = array();
		foreach ( $rows as $row ) {
			$all = array_merge( $all, CspLogger::reporter_list( (string) ( $row['reporters'] ?? '' ) ) );
		}
		if ( count( array_unique( $all ) ) < self::ROLLBACK_REPORTERS ) {
			return;
		}

		$settings             = (array) get_option( self::SETTINGS_OPTION, array() );
		$settings['csp_mode'] = 'report-only';
		update_option( self::SETTINGS_OPTION, $settings );
		CspPolicy::record_mode( 'report-only' );

		ActivityLog::log(
			'security-optimization',
			'csp_auto_rollback',
			'',
			0,
			array( 'hosts' => array_values( array_unique( array_map( static fn( $r ) => (string) $r['blocked_host'], $rows ) ) ) )
		);

		self::mail(
			/* translators: %s: site name */
			sprintf( __( '[%s] CSP switched back to test mode', 'ux-studio' ), get_bloginfo( 'name' ) ),
			__( 'Content-Security-Policy in Enforce mode blocked several sources visitors kept loading, so it was switched back to the test mode (Report only) automatically. Nothing is blocked now. Review the sources in the CSP tab, approve the legitimate ones and enable Enforce again.', 'ux-studio' ),
			$rows
		);
	}

	/**
	 * E-mail about new high-risk rows since the last e-mail (throttled).
	 */
	public static function notify_high_risk(): void {
		$last = (int) get_option( self::NOTIFIED_OPTION, 0 );
		if ( time() - $last < self::NOTIFY_WINDOW ) {
			// Try again when the window ends instead of dropping the news.
			if ( ! wp_next_scheduled( self::NOTIFY_HOOK ) ) {
				wp_schedule_single_event( $last + self::NOTIFY_WINDOW + 60, self::NOTIFY_HOOK );
			}
			return;
		}

		$since = gmdate( 'Y-m-d H:i:s', $last > 0 ? $last : time() - DAY_IN_SECONDS );
		$rows  = array_filter(
			CspLogger::get_violations(
				array(
					'status'   => 'open',
					'level'    => 'high',
					'per_page' => 50,
				)
			),
			static fn( array $row ): bool => (int) $row['id'] > CspRerate::legacy_max_id() && ( (string) ( $row['risk_checked_at'] ?? '' ) >= $since || (string) $row['first_seen'] >= $since )
		);
		if ( empty( $rows ) ) {
			return;
		}

		update_option( self::NOTIFIED_OPTION, time(), false );
		self::mail(
			/* translators: %s: site name */
			sprintf( __( '[%s] Suspicious source loaded on the website', 'ux-studio' ), get_bloginfo( 'name' ) ),
			__( 'Visitor browsers reported that the website loads content from a source rated as high risk. This is the typical sign of injected code (for example a fake "verify you are human" page or a payment skimmer). Check where the source comes from (plugin, theme, tag manager) and remove it if nobody added it on purpose.', 'ux-studio' ),
			array_values( $rows )
		);
	}

	/**
	 * @param array<int,array<string,mixed>> $rows Rows to list.
	 */
	private static function mail( string $subject, string $intro, array $rows ): void {
		$to = (string) get_option( 'admin_email' );
		if ( '' === $to ) {
			return;
		}

		$list = '';
		foreach ( array_slice( $rows, 0, 20 ) as $row ) {
			$list .= sprintf(
				'<tr><td style="padding:6px 10px;border:1px solid #ddd;"><strong>%s</strong><br><span style="color:#646970;">%s</span></td><td style="padding:6px 10px;border:1px solid #ddd;">%s</td></tr>',
				esc_html( (string) $row['blocked_host'] ),
				esc_html( (string) $row['blocked_uri'] ),
				esc_html( (string) $row['directive'] )
			);
		}

		$body  = '<html><body style="font-family:sans-serif;">';
		$body .= '<p>' . esc_html( $intro ) . '</p>';
		$body .= '<table style="border-collapse:collapse;width:100%;"><tbody>' . $list . '</tbody></table>';
		$body .= '<p><a href="' . esc_url( admin_url( 'admin.php?page=ux-studio#/module?id=security-optimization&tab=csp' ) ) . '">' . esc_html__( 'Open the CSP overview', 'ux-studio' ) . '</a></p>';
		$body .= '</body></html>';

		wp_mail( $to, $subject, $body, array( 'Content-Type: text/html; charset=UTF-8' ) );
	}
}
