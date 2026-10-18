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

	/** Option holding the enabled UX Studio module ids (Core\Modules::OPTION). */
	private const MODULES_OPTION = 'uxstudio_active_modules';

	/** More toggled modules than this in one write are logged without a benchmark. */
	private const MAX_MODULE_BENCHMARKS = 3;

	private Settings $settings;

	/** @var string[]|null Module ids captured before the current option write. */
	private ?array $modules_before = null;

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

		// UX Studio module toggles (ModuleTracker port): Core\Modules persists the
		// enabled-module list in one option, so compare it before/after the write.
		add_filter( 'pre_update_option_' . self::MODULES_OPTION, array( $this, 'capture_modules_before' ), 1, 2 );
		add_action( 'update_option_' . self::MODULES_OPTION, array( $this, 'on_modules_changed' ), 10, 2 );
	}

	/**
	 * Remember the module list as it was before the write.
	 *
	 * @param mixed $new_value New value.
	 * @param mixed $old_value Old value.
	 * @return mixed
	 */
	public function capture_modules_before( $new_value, $old_value ) {
		if ( null === $this->modules_before ) {
			$this->modules_before = self::normalise_ids( $old_value );
		}
		return $new_value;
	}

	/**
	 * Diff the module list and record an impact event per toggled module.
	 * The very first write of the option (initial setup / migration) is not a
	 * user toggle and is deliberately not tracked.
	 *
	 * @param mixed $old_value Old value.
	 * @param mixed $new_value New value.
	 */
	public function on_modules_changed( $old_value, $new_value ): void {
		$before               = $this->modules_before ?? self::normalise_ids( $old_value );
		$this->modules_before = null;
		$after                = self::normalise_ids( $new_value );

		$activated   = array_values( array_diff( $after, $before ) );
		$deactivated = array_values( array_diff( $before, $after ) );
		$total       = count( $activated ) + count( $deactivated );
		if ( 0 === $total || ! $this->enabled() ) {
			return;
		}

		// A bulk change ("deactivate all") would queue dozens of benchmark
		// requests; only the first few get measured, the rest are just logged.
		$measure = $total <= self::MAX_MODULE_BENCHMARKS;
		foreach ( $activated as $id ) {
			$this->record_event( 'activated', $id, 'module', $this->module_name( $id ), $measure );
		}
		foreach ( $deactivated as $id ) {
			$this->record_event( 'deactivated', $id, 'module', $this->module_name( $id ), $measure );
		}
	}

	/**
	 * Option value -> sorted list of string ids.
	 *
	 * @param mixed $value Raw option value.
	 * @return string[]
	 */
	private static function normalise_ids( $value ): array {
		$ids = array_values( array_unique( array_map( 'strval', is_array( $value ) ? $value : array() ) ) );
		sort( $ids );
		return $ids;
	}

	/**
	 * Human readable label for a module id.
	 *
	 * @param string $id Module id.
	 */
	private function module_name( string $id ): string {
		$name = $id;
		$all  = \UxStudio\Plugin::instance()->modules->all();
		if ( ! empty( $all[ $id ]['name'] ) ) {
			$name = (string) $all[ $id ]['name'];
		}
		/* translators: %s: UX Studio module name */
		return sprintf( __( 'UX Studio module: %s', 'ux-studio' ), $name );
	}

	/**
	 * @param string $plugin Plugin file (relative to plugins dir).
	 */
	public function on_activated( string $plugin ): void {
		$this->record_event( 'activated', $plugin, 'plugin', '', true );
	}

	/**
	 * @param string $plugin Plugin file (relative to plugins dir).
	 */
	public function on_deactivated( string $plugin ): void {
		$this->record_event( 'deactivated', $plugin, 'plugin', '', true );
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
	 * @param string $plugin_file Plugin file, or the module id for source_type 'module'.
	 * @param string $source_type 'plugin' | 'module'.
	 * @param string $name        Display name (resolved from the plugin list when empty).
	 * @param bool   $measure     Schedule the deferred benchmark (otherwise status 'skipped').
	 */
	private function record_event( string $type, string $plugin_file, string $source_type = 'plugin', string $name = '', bool $measure = true ): void {
		if ( ! $this->enabled() ) {
			return;
		}

		global $wpdb;

		$plugin_name = $name;
		if ( '' === $plugin_name ) {
			if ( ! function_exists( 'get_plugins' ) ) {
				require_once ABSPATH . 'wp-admin/includes/plugin.php';
			}
			$all_plugins = get_plugins();
			$plugin_name = isset( $all_plugins[ $plugin_file ]['Name'] ) ? (string) $all_plugins[ $plugin_file ]['Name'] : $plugin_file;
		}

		$before = $this->recent_avg_ms();

		$wpdb->insert(
			"{$wpdb->prefix}uxstudio_page_load_impact",
			array(
				'created_at'       => current_time( 'mysql' ),
				'source_type'      => $source_type,
				'event_type'       => $type,
				'plugin_file'      => mb_substr( $plugin_file, 0, 255 ),
				'plugin_name'      => mb_substr( $plugin_name, 0, 191 ),
				'benchmark_before' => $before,
				'benchmark_after'  => null,
				'benchmark_diff'   => null,
				'benchmark_count'  => 0,
				'status'           => $measure ? 'pending' : 'skipped',
				'user_id'          => get_current_user_id(),
			),
			array( '%s', '%s', '%s', '%s', '%s', '%f', '%f', '%f', '%d', '%s', '%d' )
		);

		$event_id = (int) $wpdb->insert_id;
		if ( $event_id > 0 && $measure ) {
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
