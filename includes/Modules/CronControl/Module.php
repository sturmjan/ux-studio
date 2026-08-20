<?php
/**
 * Cron Control module - inspect and manage WP-Cron scheduled events.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\CronControl;

use UxStudio\Core\ActivityLog;
use UxStudio\Modules\BaseModule;

defined( 'ABSPATH' ) || exit;

/**
 * Read/inspect/manage-only module over WP-Cron's existing scheduled events
 * table (`_get_cron_array()` / `wp_unschedule_event()` / `do_action_ref_array()`).
 *
 * This module never writes to wp-config.php, .htaccess, or the mu-plugins
 * directory and never attempts to configure real server-side cron - it only
 * reports on and manages events that are already scheduled in WP-Cron.
 */
final class Module extends BaseModule {

	/**
	 * Seconds past a scheduled timestamp before we consider WP-Cron "late"
	 * (a rough signal that pseudo-cron isn't being triggered by traffic).
	 */
	private const LATE_THRESHOLD = 300;

	/**
	 * Register hooks. No DB table, no persisted settings - just REST routes.
	 */
	public function boot(): void {
		add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );
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
	 * Overall WP-Cron status snapshot.
	 *
	 * @return array{disable_wp_cron:bool,alternate_wp_cron:bool,next_scheduled:int|null,total_events:int,late:bool}
	 */
	public function get_status(): array {
		$cron = $this->cron_array();

		$next_scheduled = null;
		$total_events   = 0;

		foreach ( $cron as $timestamp => $hooks ) {
			if ( ! is_int( $timestamp ) ) {
				continue;
			}
			if ( null === $next_scheduled ) {
				$next_scheduled = $timestamp;
			}
			foreach ( $hooks as $args_signatures ) {
				$total_events += count( $args_signatures );
			}
		}

		$late = null !== $next_scheduled && ( time() - $next_scheduled ) > self::LATE_THRESHOLD;

		return array(
			'disable_wp_cron'   => defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON,
			'alternate_wp_cron' => defined( 'ALTERNATE_WP_CRON' ) && ALTERNATE_WP_CRON,
			'next_scheduled'    => $next_scheduled,
			'total_events'      => $total_events,
			'late'              => $late,
		);
	}

	/**
	 * Flat list of scheduled events, sorted ascending by timestamp.
	 *
	 * @return array<int, array{hook:string,timestamp:int,next_run_human:string,schedule:string|null,interval:int|null,args:array}>
	 */
	public function get_events(): array {
		$cron       = $this->cron_array();
		$schedules  = wp_get_schedules();
		$events     = array();

		foreach ( $cron as $timestamp => $hooks ) {
			if ( ! is_int( $timestamp ) ) {
				continue;
			}
			foreach ( $hooks as $hook => $args_signatures ) {
				foreach ( $args_signatures as $entry ) {
					$schedule = isset( $entry['schedule'] ) && $entry['schedule'] ? (string) $entry['schedule'] : null;
					$interval = null;
					if ( null !== $schedule && isset( $schedules[ $schedule ]['interval'] ) ) {
						$interval = (int) $schedules[ $schedule ]['interval'];
					}

					$events[] = array(
						'hook'            => (string) $hook,
						'timestamp'       => (int) $timestamp,
						'next_run_human'  => $this->human_time( (int) $timestamp ),
						'schedule'        => $schedule,
						'interval'        => $interval,
						'args'            => isset( $entry['args'] ) && is_array( $entry['args'] ) ? array_values( $entry['args'] ) : array(),
					);
				}
			}
		}

		usort(
			$events,
			static function ( array $a, array $b ): int {
				return $a['timestamp'] <=> $b['timestamp'];
			}
		);

		return $events;
	}

	/**
	 * Run a scheduled event immediately, synchronously, in this request.
	 *
	 * Only runs events that are actually present in the WP-Cron table for the
	 * given (hook, timestamp, args) triple - this must never become a generic
	 * "run any action" endpoint.
	 *
	 * @param string $hook      Hook name.
	 * @param int    $timestamp Scheduled timestamp.
	 * @param array  $args      Event args.
	 * @return array{success:bool,message:string}
	 */
	public function run_event( string $hook, int $timestamp, array $args ): array {
		if ( ! $this->event_exists( $hook, $timestamp, $args ) ) {
			return array(
				'success' => false,
				'message' => __( 'That scheduled event could not be found.', 'ux-studio' ),
			);
		}

		do_action_ref_array( $hook, $args );

		ActivityLog::log( 'cron-control', 'run_event', 'cron_hook', 0, array( 'hook' => $hook ) );

		return array(
			'success' => true,
			'message' => __( 'Event executed.', 'ux-studio' ),
		);
	}

	/**
	 * Delete (unschedule) an event.
	 *
	 * @param string $hook      Hook name.
	 * @param int    $timestamp Scheduled timestamp.
	 * @param array  $args      Event args.
	 * @return array{success:bool,message:string}
	 */
	public function delete_event( string $hook, int $timestamp, array $args ): array {
		if ( ! $this->event_exists( $hook, $timestamp, $args ) ) {
			return array(
				'success' => false,
				'message' => __( 'That scheduled event could not be found.', 'ux-studio' ),
			);
		}

		$result = wp_unschedule_event( $timestamp, $hook, $args );

		if ( is_wp_error( $result ) || false === $result ) {
			return array(
				'success' => false,
				'message' => is_wp_error( $result ) ? $result->get_error_message() : __( 'Failed to delete the event.', 'ux-studio' ),
			);
		}

		ActivityLog::log( 'cron-control', 'delete_event', 'cron_hook', 0, array( 'hook' => $hook ) );

		return array(
			'success' => true,
			'message' => __( 'Event deleted.', 'ux-studio' ),
		);
	}

	/**
	 * Raw WP-Cron array (timestamp => hook => args-signature => entry),
	 * guarded in case the private core helper is ever unavailable.
	 *
	 * @return array<int|string, mixed>
	 */
	private function cron_array(): array {
		if ( ! function_exists( '_get_cron_array' ) ) {
			return array();
		}
		$cron = _get_cron_array();
		return is_array( $cron ) ? $cron : array();
	}

	/**
	 * Whether the given (hook, timestamp, args) triple is still scheduled.
	 *
	 * @param string $hook      Hook name.
	 * @param int    $timestamp Scheduled timestamp.
	 * @param array  $args      Event args.
	 */
	private function event_exists( string $hook, int $timestamp, array $args ): bool {
		$cron = $this->cron_array();
		if ( ! isset( $cron[ $timestamp ][ $hook ] ) ) {
			return false;
		}

		$key = md5( serialize( $args ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize

		return isset( $cron[ $timestamp ][ $hook ][ $key ] );
	}

	/**
	 * Human-readable relative/absolute time for a timestamp.
	 *
	 * @param int $timestamp Unix timestamp.
	 */
	private function human_time( int $timestamp ): string {
		$now = time();
		if ( $timestamp >= $now ) {
			/* translators: %s: human-readable time difference */
			return sprintf( __( 'in %s', 'ux-studio' ), human_time_diff( $now, $timestamp ) );
		}
		/* translators: %s: human-readable time difference */
		return sprintf( __( '%s ago', 'ux-studio' ), human_time_diff( $timestamp, $now ) );
	}
}
