<?php
/**
 * Plugin-impact benchmark: measure the frontend load cost of a plugin
 * activation/deactivation and estimate the per-plugin delta.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\PageLoad;

use UxStudio\Core\ActivityLog;
use UxStudio\Core\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Ported from the legacy page-load PluginTracker. On every plugin activation /
 * deactivation it records an "impact" row (with a "before" baseline sampled from
 * the historical log) and schedules a one-off cron benchmark that fires a few
 * uncached front-page requests, measures the new average and stores the diff.
 * The aggregated per-plugin impact is surfaced in the SPA.
 */
final class Benchmark {

	/** Cron hook name for the deferred benchmark run. */
	public const HOOK = 'uxstudio_page_load_benchmark';

	private Settings $settings;

	/**
	 * @param Settings $settings Owning module's settings instance.
	 */
	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Register plugin change tracking + the deferred benchmark cron handler.
	 * The cron handler is always registered so already-scheduled runs complete
	 * even if the feature is later disabled.
	 */
	public function register_hooks(): void {
		add_action( 'activated_plugin', array( $this, 'on_activated' ), 10, 1 );
		add_action( 'deactivated_plugin', array( $this, 'on_deactivated' ), 10, 1 );
		add_action( self::HOOK, array( $this, 'run' ), 10, 1 );
	}

	/**
	 * @param string $plugin Plugin file (relative to plugins dir).
	 */
	public function on_activated( string $plugin ): void {
		$this->record_event( 'activated', $plugin );
	}

	/**
	 * @param string $plugin Plugin file (relative to plugins dir).
	 */
	public function on_deactivated( string $plugin ): void {
		$this->record_event( 'deactivated', $plugin );
	}

	/**
	 * Whether the benchmark feature is enabled.
	 */
	private function enabled(): bool {
		return (bool) $this->settings->get( 'benchmark_enabled', true );
	}

	/**
	 * Number of HTTP requests per benchmark run, clamped to [1, 10].
	 */
	private function benchmark_count(): int {
		return max( 1, min( 10, (int) $this->settings->get( 'benchmark_count', 3 ) ) );
	}

	/**
	 * Record a plugin change event and schedule its deferred benchmark.
	 *
	 * @param string $type        'activated' | 'deactivated'.
	 * @param string $plugin_file Plugin file.
	 */
	private function record_event( string $type, string $plugin_file ): void {
		if ( ! $this->enabled() ) {
			return;
		}

		global $wpdb;

		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$all_plugins = get_plugins();
		$plugin_name = isset( $all_plugins[ $plugin_file ]['Name'] ) ? (string) $all_plugins[ $plugin_file ]['Name'] : $plugin_file;

		$before = $this->recent_avg_ms();

		$wpdb->insert(
			"{$wpdb->prefix}uxstudio_page_load_impact",
			array(
				'created_at'       => current_time( 'mysql' ),
				'source_type'      => 'plugin',
				'event_type'       => $type,
				'plugin_file'      => mb_substr( $plugin_file, 0, 255 ),
				'plugin_name'      => mb_substr( $plugin_name, 0, 191 ),
				'benchmark_before' => $before,
				'benchmark_after'  => null,
				'benchmark_diff'   => null,
				'benchmark_count'  => 0,
				'status'           => 'pending',
				'user_id'          => get_current_user_id(),
			),
			array( '%s', '%s', '%s', '%s', '%s', '%f', '%f', '%f', '%d', '%s', '%d' )
		);

		$event_id = (int) $wpdb->insert_id;
		if ( $event_id > 0 ) {
			wp_schedule_single_event( time() + 10, self::HOOK, array( $event_id ) );
		}
	}

	/**
	 * Average frontend load time (ms) over the last 3 days, used as the "before"
	 * baseline for a just-changed plugin configuration. Null when no data.
	 */
	private function recent_avg_ms(): ?float {
		global $wpdb;
		$avg = $wpdb->get_var(
			"SELECT AVG(load_time_ms) FROM {$wpdb->prefix}uxstudio_page_load_log
			WHERE created_at > DATE_SUB(NOW(), INTERVAL 3 DAY)"
		);
		return null === $avg ? null : round( (float) $avg, 2 );
	}

	/**
	 * Deferred cron benchmark: fire N uncached front-page requests, measure the
	 * average wall time and store it against the event as the "after" value.
	 *
	 * @param int $event_id Impact row id.
	 */
	public function run( int $event_id ): void {
		global $wpdb;
		$table = "{$wpdb->prefix}uxstudio_page_load_impact";

		$event = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $event_id ),
			ARRAY_A
		);
		if ( ! is_array( $event ) || 'completed' === ( $event['status'] ?? '' ) ) {
			return;
		}

		$wpdb->update( $table, array( 'status' => 'running' ), array( 'id' => $event_id ), array( '%s' ), array( '%d' ) );

		$count = $this->benchmark_count();
		$url   = home_url( '/' );
		$times = array();

		for ( $i = 0; $i < $count; $i++ ) {
			$start    = microtime( true );
			$response = wp_remote_get(
				$url,
				array(
					'timeout'   => 30,
					'sslverify' => false,
					'headers'   => array( 'Cache-Control' => 'no-cache' ),
					'cookies'   => array(),
				)
			);
			$elapsed = ( microtime( true ) - $start ) * 1000;

			if ( ! is_wp_error( $response ) && 200 === (int) wp_remote_retrieve_response_code( $response ) ) {
				$times[] = $elapsed;
			}
		}

		if ( empty( $times ) ) {
			$wpdb->update( $table, array( 'status' => 'failed' ), array( 'id' => $event_id ), array( '%s' ), array( '%d' ) );
			return;
		}

		$avg_after = round( array_sum( $times ) / count( $times ), 2 );
		$before    = isset( $event['benchmark_before'] ) && null !== $event['benchmark_before'] ? (float) $event['benchmark_before'] : null;
		$diff      = ( null !== $before && $before > 0 ) ? round( $avg_after - $before, 2 ) : null;

		$wpdb->update(
			$table,
			array(
				'benchmark_after' => $avg_after,
				'benchmark_diff'  => $diff,
				'benchmark_count' => count( $times ),
				'status'          => 'completed',
			),
			array( 'id' => $event_id ),
			array( '%f', '%f', '%d', '%s' ),
			array( '%d' )
		);

		ActivityLog::log(
			'page-load',
			'plugin_impact',
			'plugin',
			0,
			array(
				'plugin'    => (string) ( $event['plugin_file'] ?? '' ),
				'event'     => (string) ( $event['event_type'] ?? '' ),
				'after_ms'  => $avg_after,
				'diff_ms'   => $diff,
			)
		);
	}

	/**
	 * Aggregated per-plugin impact (completed benchmarks only), worst first.
	 *
	 * @return array<int, array{plugin_file:string, plugin_name:string, avg_impact_ms:float, max_impact_ms:float, event_count:int, last_event:string}>
	 */
	public function get_impacts(): array {
		global $wpdb;
		$rows = $wpdb->get_results(
			"SELECT plugin_file, plugin_name,
				ROUND(AVG(benchmark_diff), 2) avg_impact_ms,
				ROUND(MAX(benchmark_diff), 2) max_impact_ms,
				COUNT(*) event_count,
				MAX(created_at) last_event
			FROM {$wpdb->prefix}uxstudio_page_load_impact
			WHERE status = 'completed' AND benchmark_diff IS NOT NULL
			GROUP BY plugin_file, plugin_name
			ORDER BY avg_impact_ms DESC",
			ARRAY_A
		);

		$out = array();
		foreach ( (array) $rows as $row ) {
			$out[] = array(
				'plugin_file'   => (string) $row['plugin_file'],
				'plugin_name'   => (string) $row['plugin_name'],
				'avg_impact_ms' => (float) $row['avg_impact_ms'],
				'max_impact_ms' => (float) $row['max_impact_ms'],
				'event_count'   => (int) $row['event_count'],
				'last_event'    => (string) $row['last_event'],
			);
		}
		return $out;
	}

	/**
	 * Recent raw impact events (newest first) for the SPA events list.
	 *
	 * @param int $limit Max rows.
	 * @return array<int, array<string, mixed>>
	 */
	public function get_events( int $limit = 30 ): array {
		global $wpdb;
		$limit = max( 1, min( 100, $limit ) );
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, created_at, event_type, plugin_name, plugin_file,
					benchmark_before, benchmark_after, benchmark_diff, benchmark_count, status
				FROM {$wpdb->prefix}uxstudio_page_load_impact
				ORDER BY id DESC LIMIT %d",
				$limit
			),
			ARRAY_A
		);
		return is_array( $rows ) ? $rows : array();
	}
}
