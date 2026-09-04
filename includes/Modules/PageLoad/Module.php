<?php
/**
 * Page Load module - sample and report server-side page response times, DB
 * queries and peak memory, with an admin-bar indicator and a per-plugin load
 * impact benchmark.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\PageLoad;

use UxStudio\Modules\BaseModule;

defined( 'ABSPATH' ) || exit;

/**
 * Ported/redesigned from the legacy page-load module. Tracks per-request wall
 * time, DB query count and peak memory into a sampled log; shows the current
 * request's metrics in an admin-bar node for admins; and benchmarks the load
 * impact of each plugin activation/deactivation (Benchmark) for the SPA.
 */
final class Module extends BaseModule {

	private const DAILY_SUMMARY_LOCK = 'uxstudio_page_load_daily_summary_lock';

	private ?Benchmark $benchmark = null;

	/**
	 * Register hooks.
	 */
	public function boot(): void {
		\UxStudio\Core\DB::ensure_module_tables(
			'page-load',
			2,
			function ( int $from ): void {
				global $wpdb;
				$charset = $wpdb->get_charset_collate();
				// dbDelta is idempotent: fresh install creates the tables, an
				// upgrade from v1 ALTERs in the new query_count/memory columns
				// and adds the impact table.
				dbDelta(
					"CREATE TABLE {$wpdb->prefix}uxstudio_page_load_log (
						id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
						created_at DATETIME NOT NULL,
						url VARCHAR(500) NOT NULL DEFAULT '',
						load_time_ms INT UNSIGNED NOT NULL DEFAULT 0,
						query_count INT UNSIGNED NOT NULL DEFAULT 0,
						memory_peak_kb INT UNSIGNED NOT NULL DEFAULT 0,
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
				dbDelta(
					"CREATE TABLE {$wpdb->prefix}uxstudio_page_load_impact (
						id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
						created_at DATETIME NOT NULL,
						source_type VARCHAR(20) NOT NULL DEFAULT 'plugin',
						event_type VARCHAR(20) NOT NULL DEFAULT '',
						plugin_file VARCHAR(255) NOT NULL DEFAULT '',
						plugin_name VARCHAR(191) NOT NULL DEFAULT '',
						benchmark_before FLOAT NULL,
						benchmark_after FLOAT NULL,
						benchmark_diff FLOAT NULL,
						benchmark_count INT UNSIGNED NOT NULL DEFAULT 0,
						status VARCHAR(20) NOT NULL DEFAULT 'pending',
						user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
						PRIMARY KEY  (id),
						KEY created_at (created_at),
						KEY plugin_file (plugin_file)
					) {$charset};"
				);
			}
		);

		add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );
		add_action( 'shutdown', array( $this, 'maybe_log_timing' ) );

		// Per-plugin impact benchmark (also registers the cron handler so
		// already-scheduled runs complete regardless of the current setting).
		$this->benchmark()->register_hooks();

		// Admin-bar indicator for admins (frontend + wp-admin).
		if ( (bool) $this->settings->get( 'admin_bar_enabled', true ) ) {
			( new AdminBar() )->register();
		}
	}

	/**
	 * Lazily-built benchmark helper.
	 */
	private function benchmark(): Benchmark {
		if ( null === $this->benchmark ) {
			$this->benchmark = new Benchmark( $this->settings );
		}
		return $this->benchmark;
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
			array(
				'key'     => 'admin_bar_enabled',
				'type'    => 'toggle',
				'label'   => __( 'Admin-bar indicator', 'ux-studio' ),
				'help'    => __( 'Show the current page load time, DB queries and peak memory in the admin bar (admins only).', 'ux-studio' ),
				'default' => true,
			),
			array(
				'key'     => 'benchmark_enabled',
				'type'    => 'toggle',
				'label'   => __( 'Plugin impact benchmark', 'ux-studio' ),
				'help'    => __( 'When a plugin is activated or deactivated, measure the change in front-page load time.', 'ux-studio' ),
				'default' => true,
			),
			array(
				'key'     => 'benchmark_count',
				'type'    => 'number',
				'label'   => __( 'Benchmark requests', 'ux-studio' ),
				'help'    => __( 'How many front-page requests to average per benchmark run (1-10).', 'ux-studio' ),
				'default' => 3,
			),
		);
	}

	/**
	 * Sample and record the current frontend page's server-side response
	 * time, DB query count and peak memory (hooked on shutdown, guarded so
	 * only real frontend GET page views are recorded).
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

		$load_time_ms   = (int) round( ( microtime( true ) - (float) $_SERVER['REQUEST_TIME_FLOAT'] ) * 1000 );
		$query_count    = function_exists( 'get_num_queries' ) ? (int) get_num_queries() : 0;
		$memory_peak_kb = (int) round( memory_get_peak_usage( true ) / 1024 );
		$url            = $this->current_url();

		global $wpdb;
		$wpdb->insert(
			"{$wpdb->prefix}uxstudio_page_load_log",
			array(
				'created_at'     => current_time( 'mysql' ),
				'url'            => $url,
				'load_time_ms'   => max( 0, $load_time_ms ),
				'query_count'    => max( 0, $query_count ),
				'memory_peak_kb' => max( 0, $memory_peak_kb ),
			),
			array( '%s', '%s', '%d', '%d', '%d' )
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
	 * Overview for the last 24h: summary stats (incl. query/memory) plus an
	 * hour-bucketed breakdown (plain table, not a charting library).
	 *
	 * @return array{summary: array<string, mixed>, hourly: array<int, array<string, mixed>>}
	 */
	public function get_overview(): array {
		global $wpdb;
		$since = gmdate( 'Y-m-d H:i:s', current_time( 'timestamp', true ) - DAY_IN_SECONDS );

		$summary_row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT AVG(load_time_ms) avg_ms, MAX(load_time_ms) max_ms, MIN(load_time_ms) min_ms,
					AVG(query_count) avg_q, AVG(memory_peak_kb) avg_mem_kb, MAX(memory_peak_kb) max_mem_kb, COUNT(*) cnt
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
			'avg_queries'  => $summary_row ? round( (float) $summary_row['avg_q'], 1 ) : 0.0,
			'avg_memory_mb' => $summary_row ? round( ( (float) $summary_row['avg_mem_kb'] ) / 1024, 1 ) : 0.0,
			'max_memory_mb' => $summary_row ? round( ( (float) $summary_row['max_mem_kb'] ) / 1024, 1 ) : 0.0,
			'sample_count' => $summary_row ? (int) $summary_row['cnt'] : 0,
		);

		$hourly_rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT DATE_FORMAT(created_at, '%%Y-%%m-%%d %%H:00:00') AS hour_bucket, AVG(load_time_ms) avg_ms, AVG(query_count) avg_q, COUNT(*) cnt
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
				'hour'        => (string) $row['hour_bucket'],
				'avg_ms'      => round( (float) $row['avg_ms'], 2 ),
				'avg_queries' => round( (float) $row['avg_q'], 1 ),
				'count'       => (int) $row['cnt'],
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
				"SELECT id, created_at, url, load_time_ms, query_count, memory_peak_kb FROM {$wpdb->prefix}uxstudio_page_load_log ORDER BY id DESC LIMIT %d",
				$limit
			),
			ARRAY_A
		);
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Plugin-impact benchmark payload for the SPA: aggregated per-plugin
	 * impact plus the recent raw benchmark events.
	 *
	 * @return array{impacts: array<int, array<string, mixed>>, events: array<int, array<string, mixed>>}
	 */
	public function get_impact(): array {
		return array(
			'impacts' => $this->benchmark()->get_impacts(),
			'events'  => $this->benchmark()->get_events( 30 ),
		);
	}
}
