<?php
/**
 * Publish plan of a Blog Pilot generator / bulk approval: validation of the stored plan config and the bridge to the
 * shared core planner (Ux1\AiCore\Content\PublishPlanner). Times are wall-clock in the site time zone; datetimes already
 * taken by scheduled (`future`) posts are passed to the planner as "occupied".
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\AiAssistant\BlogPilot;

use DateTimeImmutable;
use DateTimeZone;
use Ux1\AiCore\Content\PublishPlanner;
use Ux1\AiCore\Content\PublishPlanRequest;

defined( 'ABSPATH' ) || exit;

final class PublishSlots {

	/**
	 * Default plan: one article per day on any weekday, between 08:00 and 11:00, starting today.
	 *
	 * @var array<string, mixed>
	 */
	public const DEFAULTS = array(
		'mode'            => 'period',
		'start_date'      => '',
		'end_date'        => '',
		'per'             => 1,
		'unit'            => 'day',
		'window_start'    => '08:00',
		'window_end'      => '11:00',
		'weekdays'        => array( 1, 2, 3, 4, 5, 6, 7 ),
		'min_gap_minutes' => 0,
	);

	/**
	 * Cleans a plan coming from the client or storage into the canonical shape. Never throws: anything invalid falls
	 * back to the default for that key.
	 *
	 * @param mixed $raw
	 * @return array<string, mixed>
	 */
	public static function normalize( $raw ): array {
		$raw  = is_array( $raw ) ? $raw : array();
		$plan = self::DEFAULTS;

		$plan['mode'] = in_array( $raw['mode'] ?? '', array( 'range', 'period' ), true ) ? $raw['mode'] : 'period';
		foreach ( array( 'start_date', 'end_date' ) as $key ) {
			$plan[ $key ] = self::valid_date( (string) ( $raw[ $key ] ?? '' ) ) ? (string) $raw[ $key ] : '';
		}
		$plan['per']  = max( 1, min( 100, (int) ( $raw['per'] ?? 1 ) ) );
		$plan['unit'] = in_array( $raw['unit'] ?? '', array( 'day', 'week' ), true ) ? $raw['unit'] : 'day';

		$start = self::valid_time( (string) ( $raw['window_start'] ?? '' ) ) ? self::pad_time( (string) $raw['window_start'] ) : self::DEFAULTS['window_start'];
		$end   = self::valid_time( (string) ( $raw['window_end'] ?? '' ) ) ? self::pad_time( (string) $raw['window_end'] ) : self::DEFAULTS['window_end'];
		if ( $end <= $start ) {
			$start = self::DEFAULTS['window_start'];
			$end   = self::DEFAULTS['window_end'];
		}
		$plan['window_start'] = $start;
		$plan['window_end']   = $end;

		$days = array();
		foreach ( (array) ( $raw['weekdays'] ?? array() ) as $day ) {
			$day = (int) $day;
			if ( $day >= 1 && $day <= 7 ) {
				$days[] = $day;
			}
		}
		$days = array_values( array_unique( $days ) );
		sort( $days );
		$plan['weekdays'] = $days ? $days : self::DEFAULTS['weekdays'];

		$plan['min_gap_minutes'] = max( 0, min( 1440, (int) ( $raw['min_gap_minutes'] ?? 0 ) ) );

		return $plan;
	}

	/**
	 * Strict check of a plan sent by the client (the stored plan is normalised leniently, but a request with a nonsense
	 * plan should be told so).
	 *
	 * @param mixed $raw
	 * @return string|null Error message, or null when the plan is usable.
	 */
	public static function validate( $raw ): ?string {
		if ( ! is_array( $raw ) ) {
			return __( 'The publish plan must be an object.', 'ux-studio' );
		}
		if ( isset( $raw['mode'] ) && ! in_array( $raw['mode'], array( 'range', 'period' ), true ) ) {
			return __( 'The publish plan mode must be "range" or "period".', 'ux-studio' );
		}
		foreach ( array( 'start_date', 'end_date' ) as $key ) {
			if ( isset( $raw[ $key ] ) && '' !== $raw[ $key ] && ! self::valid_date( (string) $raw[ $key ] ) ) {
				return __( 'The publish plan has an invalid date.', 'ux-studio' );
			}
		}
		if ( 'range' === ( $raw['mode'] ?? '' ) ) {
			if ( empty( $raw['start_date'] ) || empty( $raw['end_date'] ) || (string) $raw['end_date'] < (string) $raw['start_date'] ) {
				return __( 'A date range needs a start date and an end date that is not before it.', 'ux-studio' );
			}
		}
		foreach ( array( 'window_start', 'window_end' ) as $key ) {
			if ( isset( $raw[ $key ] ) && ! self::valid_time( (string) $raw[ $key ] ) ) {
				return __( 'The publish plan has an invalid time.', 'ux-studio' );
			}
		}
		if ( isset( $raw['window_start'], $raw['window_end'] ) && self::pad_time( (string) $raw['window_end'] ) <= self::pad_time( (string) $raw['window_start'] ) ) {
			return __( 'The end of the time window must be later than its start.', 'ux-studio' );
		}
		if ( isset( $raw['per'] ) && ( (int) $raw['per'] < 1 || (int) $raw['per'] > 100 ) ) {
			return __( 'Posts per day or week must be between 1 and 100.', 'ux-studio' );
		}
		if ( isset( $raw['weekdays'] ) && ( ! is_array( $raw['weekdays'] ) || ! array_filter( $raw['weekdays'], static fn( $d ): bool => (int) $d >= 1 && (int) $d <= 7 ) ) ) {
			return __( 'Choose at least one weekday.', 'ux-studio' );
		}
		return null;
	}

	/**
	 * Plans `$count` publication datetimes (ascending, site time zone, never before `$now`).
	 *
	 * @param array<string, mixed>                  $plan     Plan (any shape; normalised here).
	 * @param array<int, string|\DateTimeInterface> $occupied Datetimes already taken (strings are read in the site time zone).
	 * @return array<int, DateTimeImmutable>
	 * @throws \InvalidArgumentException|\RuntimeException From the core planner when the plan cannot be satisfied.
	 */
	public static function plan( array $plan, int $count, array $occupied, DateTimeImmutable $now, string $timezone ): array {
		$plan  = self::normalize( $plan );
		$tz    = new DateTimeZone( $timezone );
		$now   = $now->setTimezone( $tz );
		$today = $now->format( 'Y-m-d' );
		$start = '' !== $plan['start_date'] && $plan['start_date'] > $today ? $plan['start_date'] : $today;
		$mode  = $plan['mode'];
		$end   = $plan['end_date'];

		if ( 'range' === $mode && ( '' === $end || $end < $start ) ) {
			// The range is over (or has no end): carry on with the regular rhythm from today.
			$mode = 'period';
		}
		if ( 'period' === $mode ) {
			$start = self::first_free_start( $start, (int) $plan['per'], (string) $plan['unit'], $occupied, $tz );
		}

		$request = new PublishPlanRequest(
			$count,
			$mode,
			$start,
			'range' === $mode ? $end : '',
			(int) $plan['per'],
			(string) $plan['unit'],
			(string) $plan['window_start'],
			(string) $plan['window_end'],
			$timezone,
			array_map( 'intval', (array) $plan['weekdays'] ),
			$occupied,
			(int) $plan['min_gap_minutes'],
			$now
		);

		return PublishPlanner::plan( $request );
	}

	/**
	 * Local datetimes ('Y-m-d H:i:s' in the site time zone) of all scheduled posts, optionally without some post ids.
	 *
	 * @param array<int, int> $exclude_ids Post ids that are being re-planned right now.
	 * @return array<int, string>
	 */
	public static function occupied( string $from_local, array $exclude_ids = array() ): array {
		global $wpdb;

		$exclude = array_values( array_filter( array_map( 'absint', $exclude_ids ) ) );
		$sql     = "SELECT post_date FROM {$wpdb->posts} WHERE post_type = %s AND post_status = %s AND post_date >= %s";
		$params  = array( 'post', 'future', $from_local );
		if ( $exclude ) {
			$sql   .= ' AND ID NOT IN (' . implode( ',', array_fill( 0, count( $exclude ), '%d' ) ) . ')';
			$params = array_merge( $params, $exclude );
		}
		$rows = $wpdb->get_col( $wpdb->prepare( $sql, ...$params ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return array_map( 'strval', is_array( $rows ) ? $rows : array() );
	}

	/**
	 * Moves the start of a period plan past days (or weeks) that already hold the planned number of posts.
	 *
	 * @param array<int, string|\DateTimeInterface> $occupied
	 */
	private static function first_free_start( string $start, int $per, string $unit, array $occupied, DateTimeZone $tz ): string {
		$taken_at = array();
		foreach ( $occupied as $when ) {
			try {
				$taken_at[] = $when instanceof \DateTimeInterface
					? DateTimeImmutable::createFromInterface( $when )->setTimezone( $tz )
					: new DateTimeImmutable( (string) $when, $tz );
			} catch ( \Exception $e ) {
				continue;
			}
		}
		if ( ! $taken_at ) {
			return $start;
		}

		$date = DateTimeImmutable::createFromFormat( '!Y-m-d', $start, $tz );
		if ( false === $date ) {
			return $start;
		}
		for ( $i = 0; $i < 400; $i++ ) {
			if ( 'week' === $unit ) {
				$monday = $date->modify( 'monday this week' );
				$from   = $monday->format( 'Y-m-d' );
				$to     = $monday->modify( '+6 days' )->format( 'Y-m-d' );
				$taken  = count( array_filter( $taken_at, static fn( DateTimeImmutable $d ): bool => $d->format( 'Y-m-d' ) >= $from && $d->format( 'Y-m-d' ) <= $to ) );
				if ( $taken < $per ) {
					break;
				}
				$date = $monday->modify( '+7 days' );
			} else {
				$day   = $date->format( 'Y-m-d' );
				$taken = count( array_filter( $taken_at, static fn( DateTimeImmutable $d ): bool => $d->format( 'Y-m-d' ) === $day ) );
				if ( $taken < $per ) {
					break;
				}
				$date = $date->modify( '+1 day' );
			}
		}
		return $date->format( 'Y-m-d' );
	}

	private static function valid_date( string $date ): bool {
		$d = DateTimeImmutable::createFromFormat( '!Y-m-d', $date );
		return false !== $d && $d->format( 'Y-m-d' ) === $date;
	}

	private static function valid_time( string $time ): bool {
		return 1 === preg_match( '/^([01]?\d|2[0-3]):[0-5]\d$/', trim( $time ) );
	}

	private static function pad_time( string $time ): string {
		return str_pad( trim( $time ), 5, '0', STR_PAD_LEFT );
	}
}
