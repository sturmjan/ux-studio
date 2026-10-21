<?php
/**
 * Source: WP Crontrol.
 *
 * @package UxStudio
 */

namespace UxStudio\Core\Migration\Sources;

use UxStudio\Core\Migration\AbstractSource;
use UxStudio\Core\Migration\Mapping;
use UxStudio\Modules\CronControl\Module as CronModule;

defined( 'ABSPATH' ) || exit;

/**
 * Target module `cron-control`. WP Crontrol keeps the site's own cron intervals in the option
 * `crontrol_schedules` (`name => { interval, display }`). Without them the events that use such an interval
 * would stay stuck once the plugin is gone, so they are copied into the module's option
 * `uxstudio_cron_schedules` (which the module registers with WordPress, see CronControl\Module::filter_cron_schedules).
 *
 * HARMLESS: an interval definition does nothing by itself, so it is written directly even when the module is on.
 * An interval that already exists in UX Studio keeps its own value.
 *
 * Not transferred, only counted: events that run PHP code (`crontrol_cron_job`) or call a URL
 * (`crontrol_url_cron_job`) - the module has no such event types - and paused hooks.
 */
final class CronSource extends AbstractSource {

	private const OPTION = CronModule::SCHEDULES_OPTION;

	protected function changes_behavior(): bool {
		return false;
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
	 * Events created through the plugin's code / URL features, and paused hooks.
	 *
	 * @return array{php:int,url:int,paused:int}
	 */
	private function special_events(): array {
		$out = array(
			'php'    => 0,
			'url'    => 0,
			'paused' => count( (array) $this->opt( 'wp_crontrol_paused' ) ),
		);
		$cron = function_exists( '_get_cron_array' ) ? _get_cron_array() : array();
		foreach ( is_array( $cron ) ? $cron : array() as $events ) {
			foreach ( (array) $events as $hook => $instances ) {
				if ( 'crontrol_cron_job' === $hook ) {
					$out['php'] += count( (array) $instances );
				} elseif ( 'crontrol_url_cron_job' === $hook ) {
					$out['url'] += count( (array) $instances );
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
					__( '%d cron events run PHP code entered in WP Crontrol. The module cannot create them. Re-create them as a Code Snippet that hooks into a scheduled event.', 'ux-studio' ),
					$e['php']
				)
			);
		}
		if ( $e['url'] > 0 ) {
			$m->lossy(
				sprintf(
					/* translators: %d: number of events. */
					__( '%d cron events call a URL on a schedule. The module cannot create them; set up the call at your hosting or in the central app instead.', 'ux-studio' ),
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
		return 'schedule';
	}

	protected function data_label(): string {
		return __( 'Cron intervals', 'ux-studio' );
	}

	protected function data_total( array $options ): int {
		return count( $this->schedules() );
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
				'key'   => 'php',
				'label' => __( 'PHP events (not transferred)', 'ux-studio' ),
				'count' => $e['php'],
			),
			array(
				'key'   => 'url',
				'label' => __( 'URL events (not transferred)', 'ux-studio' ),
				'count' => $e['url'],
			),
		);
	}

	protected function read_batch( $position, int $limit, array $options ): array {
		$all    = $this->schedules();
		$offset = (int) $position;
		return array(
			'items' => array_slice( $all, $offset, $limit ),
			'next'  => $offset + $limit,
			'done'  => $offset + $limit >= count( $all ),
		);
	}

	protected function extra_target_options(): array {
		return array( self::OPTION );
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

	protected function preview_item( array $item, array $ctx ): array {
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
		$have                  = (array) get_option( self::OPTION, array() );
		$have[ $item['name'] ] = array(
			'interval' => (int) $item['interval'],
			'display'  => (string) $item['display'],
		);
		update_option( self::OPTION, $have, true );
		return $res;
	}

	protected function verify_item( array $item ): array {
		$same = 'same' === $this->state( $item );
		return array(
			'ok'     => $same,
			'detail' => $same ? '' : __( 'The interval is not in UX Studio.', 'ux-studio' ),
		);
	}
}
