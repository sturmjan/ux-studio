<?php
/**
 * Input of PublishPlanner. Dates are local calendar dates in $timezone, times are wall-clock minutes in $timezone.
 *
 * @package Ux1\AiCore
 */

declare(strict_types=1);

namespace Ux1\AiCore\Content;

final class PublishPlanRequest {

	public const MODE_RANGE  = 'range';
	public const MODE_PERIOD = 'period';
	public const UNIT_DAY    = 'day';
	public const UNIT_WEEK   = 'week';

	public readonly int $window_start_minute;

	public readonly int $window_end_minute;

	/**
	 * @param int                                       $count           Articles to schedule (>= 0).
	 * @param string                                    $mode            'range' = spread evenly between $start_date and $end_date;
	 *                                                                   'period' = $per articles per $unit counted from $start_date.
	 * @param string                                    $start_date      Y-m-d, first day that may be used.
	 * @param string                                    $end_date        Y-m-d, last day (range mode only; ignored for period).
	 * @param int                                       $per             Period mode: articles per day / week (1..100).
	 * @param string                                    $unit            Period mode: 'day' or 'week' (weeks start on Monday; the first one may be partial).
	 * @param string                                    $window_start    H:i, earliest publish time of a day.
	 * @param string                                    $window_end      H:i, latest publish time of a day (greater than the start).
	 * @param string                                    $timezone        IANA timezone, e.g. Europe/Prague.
	 * @param array<int, int>                           $weekdays        Allowed ISO weekdays (1 = Monday ... 7 = Sunday); default all.
	 * @param array<int, \DateTimeInterface|string>     $occupied        Datetimes already used (strings without an offset are read in $timezone).
	 * @param int                                       $min_gap_minutes Minimum distance to occupied/planned times; 0 still keeps one minute apart.
	 * @param \DateTimeInterface|null                   $not_before      Nothing is planned earlier than this instant (e.g. "now").
	 */
	public function __construct(
		public readonly int $count,
		public readonly string $mode,
		public readonly string $start_date,
		public readonly string $end_date = '',
		public readonly int $per = 1,
		public readonly string $unit = self::UNIT_DAY,
		public readonly string $window_start = '08:00',
		public readonly string $window_end = '11:00',
		public readonly string $timezone = 'Europe/Prague',
		public readonly array $weekdays = array( 1, 2, 3, 4, 5, 6, 7 ),
		public readonly array $occupied = array(),
		public readonly int $min_gap_minutes = 0,
		public readonly ?\DateTimeInterface $not_before = null
	) {
		if ( $count < 0 ) {
			throw new \InvalidArgumentException( 'count must not be negative.' );
		}
		if ( ! in_array( $mode, array( self::MODE_RANGE, self::MODE_PERIOD ), true ) ) {
			throw new \InvalidArgumentException( 'mode must be "range" or "period".' );
		}
		if ( ! self::is_date( $start_date ) ) {
			throw new \InvalidArgumentException( 'start_date must be a valid Y-m-d date.' );
		}
		if ( self::MODE_RANGE === $mode && ( ! self::is_date( $end_date ) || $end_date < $start_date ) ) {
			throw new \InvalidArgumentException( 'range mode needs a valid end_date that is not before start_date.' );
		}
		if ( self::MODE_PERIOD === $mode && ( $per < 1 || $per > 100 || ! in_array( $unit, array( self::UNIT_DAY, self::UNIT_WEEK ), true ) ) ) {
			throw new \InvalidArgumentException( 'period mode needs per 1..100 and unit "day" or "week".' );
		}
		try {
			new \DateTimeZone( $timezone );
		} catch ( \Exception $e ) {
			throw new \InvalidArgumentException( 'Unknown timezone: ' . $timezone );
		}
		$this->window_start_minute = self::minute_of_day( $window_start );
		$this->window_end_minute   = self::minute_of_day( $window_end );
		if ( $this->window_end_minute <= $this->window_start_minute ) {
			throw new \InvalidArgumentException( 'window_end must be later than window_start.' );
		}
		$valid = array_filter( $weekdays, static fn( $d ): bool => is_int( $d ) && $d >= 1 && $d <= 7 );
		if ( ! $valid ) {
			throw new \InvalidArgumentException( 'weekdays must contain at least one ISO weekday (1-7).' );
		}
	}

	/** @return array<int, int> Valid ISO weekdays. */
	public function allowed_weekdays(): array {
		return array_values( array_unique( array_filter( $this->weekdays, static fn( $d ): bool => is_int( $d ) && $d >= 1 && $d <= 7 ) ) );
	}

	private static function is_date( string $date ): bool {
		$d = \DateTimeImmutable::createFromFormat( '!Y-m-d', $date );
		return false !== $d && $d->format( 'Y-m-d' ) === $date;
	}

	private static function minute_of_day( string $time ): int {
		if ( ! preg_match( '/^([01]?\d|2[0-3]):([0-5]\d)$/', trim( $time ), $m ) ) {
			throw new \InvalidArgumentException( 'Time must be H:i, got "' . $time . '".' );
		}
		return (int) $m[1] * 60 + (int) $m[2];
	}
}
