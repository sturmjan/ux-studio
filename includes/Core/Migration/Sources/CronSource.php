<?php
/**
 * Source: WP Crontrol.
 *
 * @package UxStudio
 */

namespace UxStudio\Core\Migration\Sources;

use UxStudio\Core\Migration\AbstractSource;
use UxStudio\Core\Migration\Activatable;
use UxStudio\Core\Migration\Mapping;
use UxStudio\Modules\CronControl\Module as CronModule;
use UxStudio\Modules\CronControl\UrlEvents;

defined( 'ABSPATH' ) || exit;

/**
 * Target module `cron-control`. WP Crontrol keeps the site's own cron intervals in the option `crontrol_schedules`
 * (`name => { interval, display }`). Without them the events that use such an interval would stay stuck once the plugin is
 * gone, so they are copied into the module's option `uxstudio_cron_schedules` (which the module registers with WordPress,
 * see CronControl\Module::filter_cron_schedules).
 *
 * HARMLESS: an interval definition does nothing by itself, so it is written directly even when the module is on. An interval
 * that already exists in UX Studio keeps its own value.
 *
 * URL EVENTS (PLAN.md 27.10): the events of WP Crontrol on the hook `crontrol_url_cron_job` (args: one array with `url`,
 * `method`, optional `name`; the schedule and the next run are those of the WP-Cron entry) become URL events of the module.
 * They change what the site does (a call to an outside address), and WP Crontrol keeps running its own copies until it is
 * deactivated, so they are created SWITCHED OFF and listed in the staging area; `Staging::apply()` switches them on and
 * schedules them. An address that points to the server itself or a private network is refused by the module's SSRF guard and
 * skipped with the reason.
 *
 * Not transferred, only counted: events that run PHP code (`crontrol_cron_job`) - PLAN.md 27.3 C, the module never runs code
 * from settings - and paused hooks.
 */
final class CronSource extends AbstractSource implements Activatable {

	private const OPTION = CronModule::SCHEDULES_OPTION;

	protected function changes_behavior(): bool {
		// Only the URL events do something while the old plugin runs.
		return $this->special_events()['url'] > 0;
	}

	protected function option_names(): array {
		return array( 'crontrol_schedules', 'wp_crontrol_paused' );
	}

	/**
	 * Well-formed custom intervals of the plugin.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function schedules(): array {
		$out = array();
		foreach ( (array) $this->opt( 'crontrol_schedules' ) as $name => $schedule ) {
			$key      = sanitize_key( (string) $name );
			$interval = is_array( $schedule ) ? (int) ( $schedule['interval'] ?? 0 ) : 0;
			if ( '' === $key || $interval < 1 ) {
				continue;
			}
			$display = sanitize_text_field( (string) ( $schedule['display'] ?? $key ) );
			$out[]   = array(
				'type'     => 'schedule',
				'key'      => 'schedule:' . $key,
				'label'    => $display . ' (' . $key . ')',
				'name'     => $key,
				'interval' => $interval,
				'display'  => $display,
			);
		}
		return $out;
	}

	/**
	 * The URL events in WP-Cron.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function url_items(): array {
		$out  = array();
		$cron = function_exists( '_get_cron_array' ) ? _get_cron_array() : array();
		foreach ( is_array( $cron ) ? $cron : array() as $timestamp => $events ) {
			foreach ( (array) ( is_array( $events ) ? $events : array() ) as $hook => $instances ) {
				if ( 'crontrol_url_cron_job' !== $hook ) {
					continue;
				}
				foreach ( (array) $instances as $sig => $entry ) {
					$args = (array) ( $entry['args'][0] ?? array() );
					$url  = trim( (string) ( $args['url'] ?? '' ) );
					if ( '' === $url ) {
						continue;
					}
					$schedule = ! empty( $entry['schedule'] ) ? sanitize_key( (string) $entry['schedule'] ) : '';
					$method   = 'POST' === strtoupper( (string) ( $args['method'] ?? 'GET' ) ) ? 'POST' : 'GET';
					$key      = 'url:' . substr( md5( $url . '|' . $method . '|' . $schedule ), 0, 12 );
					if ( isset( $out[ $key ] ) ) {
						continue; // The same call twice in the queue.
					}
					$out[ $key ] = array(
						'type'      => 'url',
						'key'       => $key,
						'label'     => $method . ' ' . $url,
						'id'        => substr( md5( 'crontrol|' . $key ), 0, 8 ),
						'name'      => sanitize_text_field( (string) ( $args['name'] ?? '' ) ),
						'url'       => $url,
						'method'    => $method,
						'schedule'  => $schedule,
						'first_run' => (int) $timestamp,
					);
				}
			}
		}
		return array_values( $out );
	}

	/**
	 * Events created through the plugin's code / URL features, and paused hooks.
	 *
	 * @return array{php:int,url:int,paused:int}
	 */
	private function special_events(): array {
		$out = array(
			'php'    => 0,
			'url'    => count( $this->url_items() ),
			'paused' => count( (array) $this->opt( 'wp_crontrol_paused' ) ),
		);
		$cron = function_exists( '_get_cron_array' ) ? _get_cron_array() : array();
		foreach ( is_array( $cron ) ? $cron : array() as $events ) {
			foreach ( (array) $events as $hook => $instances ) {
				if ( 'crontrol_cron_job' === $hook ) {
					$out['php'] += count( (array) $instances );
				}
			}
		}
		return $out;
	}

	protected function map( bool $active ): Mapping {
		$m = new Mapping();
		$e = $this->special_events();
		if ( ! $this->schedules() && 0 === $e['php'] + $e['url'] + $e['paused'] ) {
			$m->note( __( 'The plugin has no custom schedules or events stored, there is nothing to transfer.', 'ux-studio' ) );
			return $m;
		}
		if ( $e['php'] > 0 ) {
			$m->lossy(
				sprintf(
					/* translators: %d: number of events. */
					__( '%d cron events run PHP code entered in WP Crontrol. The module cannot create them (it never runs code from its settings). Re-create them as a Code Snippet that hooks into a scheduled event.', 'ux-studio' ),
					$e['php']
				)
			);
		}
		if ( $e['url'] > 0 ) {
			$m->note(
				sprintf(
					/* translators: %d: number of events. */
					__( '%d cron events call a URL. They are created in the module turned off and start at the switch, so no call is made twice while WP Crontrol is still active. WP Crontrol\'s own copies stay in WP-Cron and do nothing once the plugin is removed.', 'ux-studio' ),
					$e['url']
				)
			);
		}
		if ( $e['paused'] > 0 ) {
			$m->lossy(
				sprintf(
					/* translators: %d: number of hooks. */
					__( '%d paused hooks stay as they are in WordPress, but the module has no pause function to show or change that.', 'ux-studio' ),
					$e['paused']
				)
			);
		}
		$m->note( __( 'Events themselves live in WordPress and keep running; only the custom intervals need to be moved so they survive the removal of WP Crontrol.', 'ux-studio' ) );
		return $m;
	}

	protected function data_kind(): string {
		return 'cron';
	}

	protected function data_label(): string {
		return __( 'Cron intervals and URL events', 'ux-studio' );
	}

	protected function data_total( array $options ): int {
		return count( $this->schedules() ) + count( $this->url_items() );
	}

	protected function data_plan( array $options ): array {
		$e = $this->special_events();
		return array(
			array(
				'key'   => 'schedule',
				'label' => __( 'Custom cron intervals', 'ux-studio' ),
				'count' => count( $this->schedules() ),
			),
			array(
				'key'   => 'url',
				'label' => __( 'URL events', 'ux-studio' ),
				'count' => $e['url'],
			),
			array(
				'key'   => 'php',
				'label' => __( 'PHP events (not transferred)', 'ux-studio' ),
				'count' => $e['php'],
			),
		);
	}

	protected function read_batch( $position, int $limit, array $options ): array {
		$all    = array_merge( $this->schedules(), $this->url_items() );
		$offset = (int) $position;
		return array(
			'items' => array_slice( $all, $offset, $limit ),
			'next'  => $offset + $limit,
			'done'  => $offset + $limit >= count( $all ),
		);
	}

	protected function extra_target_options(): array {
		return array( self::OPTION, UrlEvents::OPTION );
	}

	/**
	 * Names of all intervals the migration knows: the plugin's own (moved into the module option) count even while the module,
	 * which registers them, is off.
	 *
	 * @return string[]
	 */
	private function known_schedules(): array {
		return array_merge( array_column( $this->schedules(), 'name' ), array_keys( (array) get_option( self::OPTION, array() ) ) );
	}

	/**
	 * Compare an interval with what UX Studio already has.
	 *
	 * @param array<string, mixed> $item Item.
	 * @return string `same`, `missing` or `differs`.
	 */
	private function state( array $item ): string {
		$have = (array) get_option( self::OPTION, array() );
		if ( ! isset( $have[ $item['name'] ] ) ) {
			return 'missing';
		}
		return (int) ( $have[ $item['name'] ]['interval'] ?? 0 ) === (int) $item['interval'] ? 'same' : 'differs';
	}

	/**
	 * Compare a URL event with what UX Studio already has.
	 *
	 * @param array<string, mixed> $item Item.
	 * @return string `same` or `missing`.
	 */
	private function url_state( array $item ): string {
		$event = UrlEvents::get( (string) $item['id'] );
		return $event && $event['url'] === $item['url'] && $event['method'] === $item['method'] && $event['schedule'] === $item['schedule'] ? 'same' : 'missing';
	}

	/**
	 * Why a URL event cannot be created, '' when it can.
	 *
	 * @param array<string, mixed> $item Item.
	 */
	private function url_problem( array $item ): string {
		if ( ! in_array( $item['schedule'], array_merge( array( '' ), $this->known_schedules() ), true ) && ! isset( wp_get_schedules()[ $item['schedule'] ] ) ) {
			return __( 'The interval of this event does not exist.', 'ux-studio' );
		}
		$guard = UrlEvents::check_url( (string) $item['url'] );
		return true === $guard ? '' : (string) $guard;
	}

	protected function preview_item( array $item, array $ctx ): array {
		if ( 'url' === ( $item['type'] ?? '' ) ) {
			if ( 'same' === $this->url_state( $item ) ) {
				return array( 'status' => 'unchanged' );
			}
			$problem = $this->url_problem( $item );
			return '' === $problem ? array( 'status' => 'created' ) : array(
				'status'  => 'skipped',
				'message' => $problem,
			);
		}
		switch ( $this->state( $item ) ) {
			case 'same':
				return array( 'status' => 'unchanged' );
			case 'differs':
				return array(
					'status'  => 'skipped',
					'message' => __( 'UX Studio already has an interval with this name and a different length, it is kept.', 'ux-studio' ),
				);
			default:
				return array( 'status' => 'created' );
		}
	}

	protected function apply_item( array $item, array $ctx ): array {
		$res = $this->preview_item( $item, $ctx );
		if ( 'created' !== $res['status'] ) {
			return $res;
		}
		if ( 'url' === ( $item['type'] ?? '' ) ) {
			$event = UrlEvents::add_disabled( (string) $item['id'], $item, $this->known_schedules() );
			if ( is_wp_error( $event ) ) {
				return array(
					'status'  => 'skipped',
					'message' => $event->get_error_message(),
				);
			}
			// Created OFF: the switch (Staging::apply) turns it on. The definition comes back with the option snapshot on rollback.
			return array(
				'status'   => 'created',
				'activate' => (string) $item['id'],
			);
		}
		$have                  = (array) get_option( self::OPTION, array() );
		$have[ $item['name'] ] = array(
			'interval' => (int) $item['interval'],
			'display'  => (string) $item['display'],
		);
		update_option( self::OPTION, $have, true );
		return $res;
	}

	protected function verify_item( array $item ): array {
		if ( 'url' === ( $item['type'] ?? '' ) ) {
			$same = 'same' === $this->url_state( $item );
			return array(
				'ok'     => $same,
				'detail' => $same ? '' : ( '' !== $this->url_problem( $item ) ? $this->url_problem( $item ) : __( 'The event is not in UX Studio.', 'ux-studio' ) ),
			);
		}
		$same = 'same' === $this->state( $item );
		return array(
			'ok'     => $same,
			'detail' => $same ? '' : __( 'The interval is not in UX Studio.', 'ux-studio' ),
		);
	}

	public function activate( string $kind, array $ids, array $data = array() ): int {
		return 'cron' === $kind ? UrlEvents::set_enabled( array_map( 'strval', $ids ), true ) : 0;
	}

	public function deactivate( string $kind, array $ids, array $data = array() ): int {
		return 'cron' === $kind ? UrlEvents::set_enabled( array_map( 'strval', $ids ), false ) : 0;
	}
}
