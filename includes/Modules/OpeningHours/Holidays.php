<?php
/**
 * Czech public holidays (closed days) for the opening-hours module.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\OpeningHours;

defined( 'ABSPATH' ) || exit;

/**
 * Computes Czech statutory public holidays for a given year, including the
 * movable Easter dates (Meeus/Jones/Butcher algorithm - no ext-calendar
 * dependency). Ported from the legacy HolidayCalculator.
 */
final class Holidays {

	/**
	 * Czech public holidays for a year as [ date => label ] (Y-m-d keys).
	 *
	 * @param int $year Four-digit year.
	 * @return array<string,string>
	 */
	public static function for_year( int $year ): array {
		$easter_ts     = strtotime( self::easter_sunday( $year ) );
		$good_friday   = gmdate( 'Y-m-d', strtotime( '-2 days', $easter_ts ) );
		$easter_monday = gmdate( 'Y-m-d', strtotime( '+1 day', $easter_ts ) );

		return array(
			sprintf( '%d-01-01', $year ) => __( 'New Year / Restoration Day of the Czech State', 'ux-studio' ),
			$good_friday                 => __( 'Good Friday', 'ux-studio' ),
			$easter_monday               => __( 'Easter Monday', 'ux-studio' ),
			sprintf( '%d-05-01', $year ) => __( 'Labour Day', 'ux-studio' ),
			sprintf( '%d-05-08', $year ) => __( 'Victory Day', 'ux-studio' ),
			sprintf( '%d-07-05', $year ) => __( 'Saints Cyril and Methodius Day', 'ux-studio' ),
			sprintf( '%d-07-06', $year ) => __( 'Jan Hus Day', 'ux-studio' ),
			sprintf( '%d-09-28', $year ) => __( 'Czech Statehood Day', 'ux-studio' ),
			sprintf( '%d-10-28', $year ) => __( 'Independent Czechoslovak State Day', 'ux-studio' ),
			sprintf( '%d-11-17', $year ) => __( 'Struggle for Freedom and Democracy Day', 'ux-studio' ),
			sprintf( '%d-12-24', $year ) => __( 'Christmas Eve', 'ux-studio' ),
			sprintf( '%d-12-25', $year ) => __( 'Christmas Day', 'ux-studio' ),
			sprintf( '%d-12-26', $year ) => __( 'St. Stephen\'s Day', 'ux-studio' ),
		);
	}

	/**
	 * Holiday label for a Y-m-d date, or null if it is not a public holiday.
	 *
	 * @param string $date Y-m-d.
	 */
	public static function label_for( string $date ): ?string {
		$year = (int) substr( $date, 0, 4 );
		$list = self::for_year( $year );
		return $list[ $date ] ?? null;
	}

	/**
	 * Easter Sunday (Gregorian) as Y-m-d via the anonymous Gregorian algorithm.
	 *
	 * @param int $year Four-digit year.
	 */
	public static function easter_sunday( int $year ): string {
		$a     = $year % 19;
		$b     = intdiv( $year, 100 );
		$c     = $year % 100;
		$d     = intdiv( $b, 4 );
		$e     = $b % 4;
		$f     = intdiv( $b + 8, 25 );
		$g     = intdiv( $b - $f + 1, 3 );
		$h     = ( 19 * $a + $b - $d - $g + 15 ) % 30;
		$i     = intdiv( $c, 4 );
		$k     = $c % 4;
		$l     = ( 32 + 2 * $e + 2 * $i - $h - $k ) % 7;
		$m     = intdiv( $a + 11 * $h + 22 * $l, 451 );
		$month = intdiv( $h + $l - 7 * $m + 114, 31 );
		$day   = ( ( $h + $l - 7 * $m + 114 ) % 31 ) + 1;

		return sprintf( '%04d-%02d-%02d', $year, $month, $day );
	}
}
