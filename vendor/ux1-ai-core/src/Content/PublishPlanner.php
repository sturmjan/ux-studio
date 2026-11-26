<?php
/**
 * Pure publication scheduling: N articles -> N publish datetimes. No randomness, no clock (pass $not_before for
 * "not in the past"), so the same request always gives the same plan.
 *
 * Days are chosen first (range: spread evenly over the allowed days of the range; period: X per day or per week),
 * then articles sharing a day get evenly spaced wall-clock slots inside the daily window. A slot that collides with an
 * occupied/planned time (closer than max(1 minute, min gap)) or lies before $not_before is nudged minute by minute
 * inside the window; if the whole day is full it spills over to the next allowed day (possibly past the range end).
 *
 * @package Ux1\AiCore
 */

declare(strict_types=1);

namespace Ux1\AiCore\Content;

final class PublishPlanner {

	/** How far (days) a spilled article may be pushed before planning gives up. */
	private const MAX_SPILL_DAYS = 400;

	/**
	 * @return array<int, \DateTimeImmutable> Ascending, in the request timezone, exactly $r->count items.
	 * @throws \RuntimeException When no allowed day exists (e.g. a range with no allowed weekday).
	 */
	public static function plan( PublishPlanRequest $r ): array {
		if ( 0 === $r->count ) {
			return array();
		}
		$tz   = new \DateTimeZone( $r->timezone );
		$gap  = max( 60, $r->min_gap_minutes * 60 );
		$busy = array();
		foreach ( $r->occupied as $when ) {
			$busy[] = ( $when instanceof \DateTimeInterface ? $when : new \DateTimeImmutable( (string) $when, $tz ) )->getTimestamp();
		}
		$not_before = $r->not_before ? $r->not_before->getTimestamp() : null;

		// "Not before" moves the first usable day instead of piling articles onto the spill-over day.
		$first = $r->start_date;
		if ( $r->not_before ) {
			$local = \DateTimeImmutable::createFromInterface( $r->not_before )->setTimezone( $tz );
			$day   = $local->format( 'Y-m-d' );
			if ( $day >= $first ) {
				$first = (int) $local->format( 'G' ) * 60 + (int) $local->format( 'i' ) >= $r->window_end_minute ? self::next_day( $day, $tz ) : $day;
			}
		}

		$per_day = PublishPlanRequest::MODE_RANGE === $r->mode ? self::range_days( $r, $tz, $first ) : self::period_days( $r, $tz, $first );

		$out = array();
		foreach ( $per_day as $date => $items_on_day ) {
			for ( $j = 0; $j < $items_on_day; $j++ ) {
				$window = $r->window_end_minute - $r->window_start_minute;
				$minute = $r->window_start_minute + intdiv( ( 2 * $j + 1 ) * $window, 2 * $items_on_day );
				$slot   = self::place( $date, $minute, $r, $tz, $busy, $gap, $not_before );
				$busy[] = $slot->getTimestamp();
				$out[]  = $slot;
			}
		}
		usort( $out, static fn( \DateTimeImmutable $a, \DateTimeImmutable $b ): int => $a->getTimestamp() <=> $b->getTimestamp() );
		return $out;
	}

	/** @return array<string, int> date => number of articles on it (range mode). */
	private static function range_days( PublishPlanRequest $r, \DateTimeZone $tz, string $first ): array {
		$days = array();
		for ( $date = $first, $n = 0; $date <= max( $r->end_date, $first ) && $n < 3700; $date = self::next_day( $date, $tz ), $n++ ) {
			if ( self::allowed( $date, $r, $tz ) ) {
				$days[] = $date;
			}
		}
		if ( ! $days ) {
			throw new \RuntimeException( 'Publish planner: the range contains no allowed weekday.' );
		}
		return self::distribute( $days, $r->count );
	}

	/** @return array<string, int> date => number of articles on it (period mode). */
	private static function period_days( PublishPlanRequest $r, \DateTimeZone $tz, string $first ): array {
		$out       = array();
		$remaining = $r->count;
		$date      = $first;

		if ( PublishPlanRequest::UNIT_DAY === $r->unit ) {
			for ( $n = 0; $remaining > 0 && $n < 3700; $n++, $date = self::next_day( $date, $tz ) ) {
				if ( ! self::allowed( $date, $r, $tz ) ) {
					continue;
				}
				$take         = min( $r->per, $remaining );
				$out[ $date ] = $take;
				$remaining   -= $take;
			}
		} else {
			// Weeks start on Monday; the first week only offers days from start_date on.
			$week_start = self::monday_of( $date, $tz );
			for ( $w = 0; $remaining > 0 && $w < 530; $w++, $week_start = self::add_days( $week_start, 7, $tz ) ) {
				$days = array();
				for ( $d = 0; $d < 7; $d++ ) {
					$day = self::add_days( $week_start, $d, $tz );
					if ( $day >= $first && self::allowed( $day, $r, $tz ) ) {
						$days[] = $day;
					}
				}
				if ( ! $days ) {
					continue;
				}
				$take = min( $r->per, $remaining );
				foreach ( self::distribute( $days, $take ) as $day => $count ) {
					$out[ $day ] = $count;
				}
				$remaining -= $take;
			}
		}
		if ( $remaining > 0 ) {
			throw new \RuntimeException( 'Publish planner: not enough allowed days to place all articles.' );
		}
		return $out;
	}

	/**
	 * Spreads $quota items evenly over $days (centred, so 2 items over Mon-Fri land on Tue and Thu).
	 *
	 * @param array<int, string> $days
	 * @return array<string, int>
	 */
	private static function distribute( array $days, int $quota ): array {
		$count = count( $days );
		$out   = array();
		for ( $i = 0; $i < $quota; $i++ ) {
			$index          = min( $count - 1, intdiv( ( 2 * $i + 1 ) * $count, 2 * $quota ) );
			$out[ $days[ $index ] ] = ( $out[ $days[ $index ] ] ?? 0 ) + 1;
		}
		return $out;
	}

	/**
	 * @param array<int, int> $busy Timestamps already taken.
	 */
	private static function place( string $date, int $preferred, PublishPlanRequest $r, \DateTimeZone $tz, array $busy, int $gap, ?int $not_before ): \DateTimeImmutable {
		$start = $r->window_start_minute;
		$end   = $r->window_end_minute;
		for ( $spill = 0; $spill <= self::MAX_SPILL_DAYS; ) {
			for ( $offset = 0; $offset <= $end - $start; $offset++ ) {
				foreach ( array( $preferred + $offset, $preferred - $offset ) as $minute ) {
					if ( $minute < $start || $minute > $end ) {
						continue;
					}
					$candidate = self::at( $date, $minute, $tz );
					if ( self::is_free( $candidate->getTimestamp(), $busy, $gap, $not_before ) ) {
						return $candidate;
					}
				}
			}
			// Day full (or in the past): move to the next allowed day.
			do {
				$date = self::next_day( $date, $tz );
				++$spill;
			} while ( ! self::allowed( $date, $r, $tz ) && $spill <= self::MAX_SPILL_DAYS );
		}
		throw new \RuntimeException( 'Publish planner: no free slot found.' );
	}

	/** @param array<int, int> $busy */
	private static function is_free( int $timestamp, array $busy, int $gap, ?int $not_before ): bool {
		if ( null !== $not_before && $timestamp < $not_before ) {
			return false;
		}
		foreach ( $busy as $other ) {
			if ( abs( $timestamp - $other ) < $gap ) {
				return false;
			}
		}
		return true;
	}

	/** Wall-clock time on a local date; a time skipped by the DST jump resolves forward (PHP semantics). */
	private static function at( string $date, int $minute, \DateTimeZone $tz ): \DateTimeImmutable {
		return ( new \DateTimeImmutable( $date . ' 00:00:00', $tz ) )->setTime( intdiv( $minute, 60 ), $minute % 60 );
	}

	private static function allowed( string $date, PublishPlanRequest $r, \DateTimeZone $tz ): bool {
		return in_array( (int) ( new \DateTimeImmutable( $date . ' 12:00:00', $tz ) )->format( 'N' ), $r->allowed_weekdays(), true );
	}

	private static function next_day( string $date, \DateTimeZone $tz ): string {
		return self::add_days( $date, 1, $tz );
	}

	/** Calendar-day arithmetic done at noon so a DST change never skips or repeats a date. */
	private static function add_days( string $date, int $days, \DateTimeZone $tz ): string {
		return ( new \DateTimeImmutable( $date . ' 12:00:00', $tz ) )->modify( ( $days >= 0 ? '+' : '' ) . $days . ' days' )->format( 'Y-m-d' );
	}

	private static function monday_of( string $date, \DateTimeZone $tz ): string {
		$n = (int) ( new \DateTimeImmutable( $date . ' 12:00:00', $tz ) )->format( 'N' );
		return self::add_days( $date, 1 - $n, $tz );
	}
}
