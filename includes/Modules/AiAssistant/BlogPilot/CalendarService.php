<?php
/**
 * Data of the Blog Pilot calendar: the month grid of the core (CalendarMonth) filled with the posts Blog Pilot generated
 * and every post that is scheduled (`future`), in the site time zone.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\AiAssistant\BlogPilot;

use Ux1\AiCore\Content\CalendarMonth;

defined( 'ABSPATH' ) || exit;

final class CalendarService {

	public const POST_META = '_uxstudio_blog_pilot';

	/**
	 * @return array<string, mixed>|\WP_Error The CalendarMonth structure plus `timezone` and `now`.
	 */
	public function month( int $year, int $month ) {
		if ( $year < 2000 || $year > 2100 || $month < 1 || $month > 12 ) {
			return new \WP_Error( 'uxstudio_invalid_month', __( 'Choose a month between 2000 and 2100.', 'ux-studio' ), array( 'status' => 400 ) );
		}

		$timezone   = wp_timezone_string();
		$week_start = (int) get_option( 'start_of_week', 1 );
		$week_start = 0 === $week_start ? 7 : max( 1, min( 7, $week_start ) );
		$today      = (string) wp_date( 'Y-m-d' );

		$grid = CalendarMonth::build( $year, $month, $timezone, array(), $week_start, $today );

		$items = array();
		foreach ( $this->rows( (string) $grid['first_date'], (string) $grid['last_date'] ) as $row ) {
			$id = (int) $row->ID;
			if ( ! current_user_can( 'edit_post', $id ) ) {
				continue;
			}
			$when    = (string) $row->post_date;
			$items[] = array(
				'id'             => $id,
				'title'          => '' !== trim( (string) $row->post_title ) ? wp_strip_all_tags( (string) $row->post_title ) : __( '(no title)', 'ux-studio' ),
				'status'         => (string) $row->post_status,
				'datetime'       => $when,
				'time'           => substr( $when, 11, 5 ),
				'generated'      => null !== $row->generated && '' !== (string) $row->generated,
				'can_reschedule' => in_array( (string) $row->post_status, PostWorkflow::SCHEDULABLE, true ),
				'edit_url'       => (string) get_edit_post_link( $id, 'raw' ),
			);
		}

		$result             = CalendarMonth::build( $year, $month, $timezone, $items, $week_start, $today );
		$result['timezone'] = $timezone;
		$result['now']      = (string) wp_date( 'Y-m-d H:i' );

		return $result;
	}

	/**
	 * Posts of the visible grid: generated ones (any status that has a place in a calendar) and every scheduled one.
	 *
	 * @return array<int, object>
	 */
	private function rows( string $first_date, string $last_date ): array {
		global $wpdb;

		$to   = ( new \DateTimeImmutable( $last_date ) )->modify( '+1 day' )->format( 'Y-m-d' );
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT p.ID, p.post_title, p.post_status, p.post_date, m.meta_id AS generated
				FROM {$wpdb->posts} p
				LEFT JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = %s
				WHERE p.post_type = %s
					AND p.post_status IN (%s, %s, %s, %s)
					AND p.post_date >= %s AND p.post_date < %s
					AND ( p.post_status = %s OR m.meta_id IS NOT NULL )
				ORDER BY p.post_date ASC
				LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				self::POST_META,
				'post',
				'future',
				'publish',
				'draft',
				'pending',
				$first_date . ' 00:00:00',
				$to . ' 00:00:00',
				'future',
				500
			)
		);

		return is_array( $rows ) ? $rows : array();
	}
}
