<?php
/**
 * Month grid for the calendar views of both hosts: weeks x days with an in-month flag and the items of each day.
 *
 * @package Ux1\AiCore
 */

declare(strict_types=1);

namespace Ux1\AiCore\Content;

final class CalendarMonth {

	/**
	 * Builds the grid. Each week has 7 days; the grid covers whole weeks (4 to 6 of them) and shows the neighbouring
	 * months' days with in_month = false (their items are included too).
	 *
	 * @param int                                    $year       e.g. 2027
	 * @param int                                    $month      1..12
	 * @param string                                 $timezone   IANA timezone the items are bucketed in.
	 * @param array<int, array<string, mixed>>       $items      Any host rows; each needs the $datetime_key field
	 *                                                           (DateTimeInterface, or a string: without an offset it is read in $timezone).
	 * @param int                                    $week_start 1 = Monday (default), 7 = Sunday.
	 * @param string                                 $today      Y-m-d to flag as today; '' = no flag (keeps the helper clock-free).
	 * @param string                                 $datetime_key Field of an item holding its datetime.
	 * @return array{year:int,month:int,week_start:int,first_date:string,last_date:string,prev:array{year:int,month:int},next:array{year:int,month:int},weekdays:array<int,int>,weeks:array<int,array<int,array{date:string,day:int,weekday:int,in_month:bool,is_today:bool,items:array<int,array<string,mixed>>}>>}
	 */
	public static function build( int $year, int $month, string $timezone, array $items = array(), int $week_start = 1, string $today = '', string $datetime_key = 'datetime' ): array {
		if ( $month < 1 || $month > 12 ) {
			throw new \InvalidArgumentException( 'month must be 1..12.' );
		}
		$tz         = new \DateTimeZone( $timezone );
		$week_start = 7 === $week_start ? 7 : 1;

		$first   = new \DateTimeImmutable( sprintf( '%04d-%02d-01 12:00:00', $year, $month ), $tz );
		$days_in = (int) $first->format( 't' );
		$lead    = ( (int) $first->format( 'N' ) - $week_start + 7 ) % 7;
		$grid_start = $first->modify( '-' . $lead . ' days' );
		$weeks   = (int) ceil( ( $lead + $days_in ) / 7 );

		// Bucket the items by local date.
		$by_date = array();
		foreach ( $items as $item ) {
			$when = $item[ $datetime_key ] ?? null;
			if ( $when instanceof \DateTimeInterface ) {
				$local = \DateTimeImmutable::createFromInterface( $when )->setTimezone( $tz );
			} elseif ( is_string( $when ) && '' !== trim( $when ) ) {
				try {
					$local = ( new \DateTimeImmutable( $when, $tz ) )->setTimezone( $tz );
				} catch ( \Exception $e ) {
					continue;
				}
			} else {
				continue;
			}
			$by_date[ $local->format( 'Y-m-d' ) ][] = array( $local->getTimestamp(), $item );
		}

		$grid = array();
		for ( $w = 0; $w < $weeks; $w++ ) {
			$row = array();
			for ( $d = 0; $d < 7; $d++ ) {
				$day  = $grid_start->modify( '+' . ( $w * 7 + $d ) . ' days' );
				$date = $day->format( 'Y-m-d' );
				$list = $by_date[ $date ] ?? array();
				usort( $list, static fn( array $a, array $b ): int => $a[0] <=> $b[0] );
				$row[] = array(
					'date'     => $date,
					'day'      => (int) $day->format( 'j' ),
					'weekday'  => (int) $day->format( 'N' ),
					'in_month' => (int) $day->format( 'n' ) === $month && (int) $day->format( 'Y' ) === $year,
					'is_today' => '' !== $today && $today === $date,
					'items'    => array_map( static fn( array $pair ): array => $pair[1], $list ),
				);
			}
			$grid[] = $row;
		}

		$prev = $first->modify( '-1 month' );
		$next = $first->modify( '+1 month' );
		return array(
			'year'       => $year,
			'month'      => $month,
			'week_start' => $week_start,
			'first_date' => $grid[0][0]['date'],
			'last_date'  => $grid[ $weeks - 1 ][6]['date'],
			'prev'       => array(
				'year'  => (int) $prev->format( 'Y' ),
				'month' => (int) $prev->format( 'n' ),
			),
			'next'       => array(
				'year'  => (int) $next->format( 'Y' ),
				'month' => (int) $next->format( 'n' ),
			),
			'weekdays'   => 7 === $week_start ? array( 7, 1, 2, 3, 4, 5, 6 ) : array( 1, 2, 3, 4, 5, 6, 7 ),
			'weeks'      => $grid,
		);
	}
}
