<?php
/**
 * Page Load module - sample and report server-side page response times.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\PageLoad;

use UxStudio\Modules\BaseModule;

defined( 'ABSPATH' ) || exit;

/**
 * Ported/redesigned from the legacy page-load module. Simplified: only the
 * overall page load time is tracked (no per-plugin/per-module breakdown),
 * plus a lightweight "events" table used for a once-per-day rollup marker
 * so historical trends survive log pruning.
 */
final class Module extends BaseModule {

	private const DAILY_SUMMARY_LOCK = 'uxstudio_page_load_daily_summary_lock';

	/**
	 * Register hooks.
	 */
	public function boot(): void {
		\UxStudio\Core\DB::ensure_module_tables(
			'page-load',
			1,
			function ( int $from ): void {
				global $wpdb;
				$charset = $wpdb->get_charset_collate();
				dbDelta(
					"CREATE TABLE {$wpdb->prefix}uxstudio_page_load_log (
						id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
						created_at DATETIME NOT NULL,
						url VARCHAR(500) NOT NULL DEFAULT '',
						load_time_ms INT UNSIGNED NOT NULL DEFAULT 0,
						PRIMARY KEY  (id),
						KEY created_at (created_at)
					) {$charset};"
				);
				dbDelta(
					"CREATE TABLE {$wpdb->prefix}uxstudio_page_load_events (
						id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
						created_at DATETIME NOT NULL,
						event_type VARCHAR(50) NOT NULL DEFAULT '',
						payload LONGTEXT NULL,
						PRIMARY KEY  (id),
						KEY created_at (created_at)
					) {$charset};"
				);
			}
		);

		add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );
		add_action( 'shutdown', array( $this, 'maybe_log_timing' ) );
	}

	/**
	 * Register the module REST controller.
	 */
	public function register_rest_routes(): void {
		( new RestController( $this ) )->register_routes();
	}

	/**
	 * REST controller class.
	 */
	public function rest_controller(): ?string {
		return RestController::class;
	}

	/**
	 * Settings schema for the generic renderer / embedded Settings tab.
	 */
	public function settings_schema(): array {
		return array(
			array(
				'key'     => 'sample_rate',
				'type'    => 'number',
				'label'   => __( 'Sample rate (%)', 'ux-studio' ),
				'help'    => __( 'Percentage of frontend GET requests to time and record (0-100).', 'ux-studio' ),
				'default' => 10,
			),
			array(
				'key'     => 'retention_days',
				'type'    => 'number',
				'label'   => __( 'Retention (days)', 'ux-studio' ),
				'help'    => __( 'Log rows older than this are pruned automatically.', 'ux-studio' ),
				'default' => 30,
			),
		);
	}

	/**
	 * Sample and record the current frontend page's server-side response
	 * time (hooked on shutdown, guarded heavily so only real frontend GET
	 * page views are ever recorded).
	 */
	public function maybe_log_timing(): void {
		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() ) {
			return;
		}
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return;
		}
		if ( ! isset( $_SERVER['REQUEST_METHOD'] ) || 'GET' !== $_SERVER['REQUEST_METHOD'] ) {
			return;
		}

		$sample_rate = $this->sample_rate();
		if ( 0 === $sample_rate ) {
			return;
		}
		if ( mt_rand( 1, 100 ) > $sample_rate ) {
			return;
		}

		if ( ! isset( $_SERVER['REQUEST_TIME_FLOAT'] ) ) {
			return;
		}

		$load_time_ms = (int) round( ( microtime( true ) - (float) $_SERVER['REQUEST_TIME_FLOAT'] ) * 1000 );
		$url           = $this->current_url();

		global $wpdb;
		$wpdb->insert(
			"{$wpdb->prefix}uxstudio_page_load_log",
			array(
				'created_at'   => current_time( 'mysql' ),
				'url'          => $url,
				'load_time_ms' => max( 0, $load_time_ms ),
			),
			array( '%s', '%s', '%d' )
		);

		// Opportunistic pruning: ~2% chance per logged request.
		if ( 1 === mt_rand( 1, 50 ) ) {
			$this->prune_old_rows();
		}

		$this->maybe_record_daily_summary();
	}

	/**
	 * Current sample rate, clamped to [0, 100].
	 */
	private function sample_rate(): int {
		$sample_rate = (int) $this->settings->get( 'sample_rate', 10 );
		return max( 0, min( 100, $sample_rate ) );
	}

	/**
	 * Current retention window in days, clamped to a sane minimum.
	 */
	private function retention_days(): int {
		$retention_days = (int) $this->settings->get( 'retention_days', 30 );
		return max( 1, $retention_days );
	}

	/**
	 * Build + sanitize/truncate the current request URL for storage.
	 */
	private function current_url(): string {
		$host = isset( $_SERVER['HTTP_HOST'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) ) : '';
		$uri  = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
		$url  = ( is_ssl() ? 'https://' : 'http://' ) . $host . $uri;
		$url  = esc_url_raw( $url );
		return mb_substr( $url, 0, 500 );
	}

	/**
	 * Delete log rows older than the retention window.
	 */
	private function prune_old_rows(): void {
		global $wpdb;
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->prefix}uxstudio_page_load_log WHERE created_at < DATE_SUB(NOW(), INTERVAL %d DAY)",
				$this->retention_days()
			)
		);
	}

	/**
	 * Opportunistically write one 'daily_summary' event row per day,
	 * self-guarded by a transient lock so it only ever happens once per
	 * day even under concurrent requests. Cheap to call on every sampled
	 * request.
	 */
	private function maybe_record_daily_summary(): void {
		if ( false !== get_transient( self::DAILY_SUMMARY_LOCK ) ) {
			return;
		}
		set_transient( self::DAILY_SUMMARY_LOCK, 1, DAY_IN_SECONDS );

		global $wpdb;
		$date = current_time( 'Y-m-d' );
		$row  = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT AVG(load_time_ms) avg_ms, MAX(load_time_ms) max_ms, MIN(load_time_ms) min_ms, COUNT(*) cnt
				FROM {$wpdb->prefix}uxstudio_page_load_log
				WHERE created_at >= %s",
				$date . ' 00:00:00'
			),
			ARRAY_A
		);

		if ( ! is_array( $row ) || (int) ( $row['cnt'] ?? 0 ) === 0 ) {
			return;
		}

		$payload = wp_json_encode(
			array(
				'avg_ms'       => round( (float) $row['avg_ms'], 2 ),
				'max_ms'       => (int) $row['max_ms'],
				'min_ms'       => (int) $row['min_ms'],
				'sample_count' => (int) $row['cnt'],
				'date'         => $date,
			)
		);

		$wpdb->insert(
			"{$wpdb->prefix}uxstudio_page_load_events",
			array(
				'created_at' => current_time( 'mysql' ),
				'event_type' => 'daily_summary',
				'payload'    => $payload,
			),
			array( '%s', '%s', '%s' )
		);
	}

	/**
	 * Overview for the last 24h: summary stats plus an hour-bucketed
	 * breakdown (plain table, not a charting library).
	 *
	 * @return array{summary: array{avg_ms: float, max_ms: int, min_ms: int, sample_count: int}, hourly: array<int, array{hour: string, avg_ms: float, count: int}>}
	 */
	public function get_overview(): array {
		global $wpdb;
		$since = gmdate( 'Y-m-d H:i:s', current_time( 'timestamp', true ) - DAY_IN_SECONDS );

		$summary_row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT AVG(load_time_ms) avg_ms, MAX(load_time_ms) max_ms, MIN(load_time_ms) min_ms, COUNT(*) cnt
				FROM {$wpdb->prefix}uxstudio_page_load_log
				WHERE created_at >= %s",
				$since
			),
			ARRAY_A
		);

		$summary = array(
			'avg_ms'       => $summary_row ? round( (float) $summary_row['avg_ms'], 2 ) : 0.0,
			'max_ms'       => $summary_row ? (int) $summary_row['max_ms'] : 0,
			'min_ms'       => $summary_row ? (int) $summary_row['min_ms'] : 0,
			'sample_count' => $summary_row ? (int) $summary_row['cnt'] : 0,
		);

		$hourly_rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT DATE_FORMAT(created_at, '%%Y-%%m-%%d %%H:00:00') AS hour_bucket, AVG(load_time_ms) avg_ms, COUNT(*) cnt
				FROM {$wpdb->prefix}uxstudio_page_load_log
				WHERE created_at >= %s
				GROUP BY hour_bucket
				ORDER BY hour_bucket ASC",
				$since
			),
			ARRAY_A
		);

		$hourly = array();
		foreach ( (array) $hourly_rows as $row ) {
			$hourly[] = array(
				'hour'   => (string) $row['hour_bucket'],
				'avg_ms' => round( (float) $row['avg_ms'], 2 ),
				'count'  => (int) $row['cnt'],
			);
		}

		return array(
			'summary' => $summary,
			'hourly'  => $hourly,
		);
	}

	/**
	 * Last N raw log rows, newest first.
	 *
	 * @param int $limit Max rows to return.
	 * @return array<int, array<string, mixed>>
	 */
	public function get_recent_log( int $limit = 50 ): array {
		global $wpdb;
		$limit = max( 1, min( 200, $limit ) );

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, created_at, url, load_time_ms FROM {$wpdb->prefix}uxstudio_page_load_log ORDER BY id DESC LIMIT %d",
				$limit
			),
			ARRAY_A
		);
		return is_array( $rows ) ? $rows : array();
	}
}
