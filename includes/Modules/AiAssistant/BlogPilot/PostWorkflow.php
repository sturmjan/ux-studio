<?php
/**
 * What happens to a generated post after it was written: preview, approve (publish now, schedule at a chosen datetime
 * or at the next planned slot), bulk approve with automatic dates, change the date of a scheduled post, reject (trash).
 *
 * Every method checks the capability for the post itself (edit_post / publish_post / delete_post), not only the route's
 * general one, and returns a WP_Error with an HTTP status instead of throwing.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\AiAssistant\BlogPilot;

use DateTimeImmutable;
use DateTimeZone;
use WP_Error;

defined( 'ABSPATH' ) || exit;

final class PostWorkflow {

	/** Statuses whose date may be set to a future moment. */
	public const SCHEDULABLE = array( 'future', 'draft', 'pending' );

	/** Statuses that wait for a decision in the review list. */
	public const REVIEWABLE = array( 'draft', 'pending' );

	/** @var callable():DateTimeImmutable */
	private $clock;

	/**
	 * @param callable|null $clock Returns "now" in the site time zone (test seam).
	 */
	public function __construct( ?callable $clock = null ) {
		$this->clock = $clock ?? static fn(): DateTimeImmutable => new DateTimeImmutable( 'now', new DateTimeZone( wp_timezone_string() ) );
	}

	/**
	 * Parses 'Y-m-d H:i' / 'Y-m-d\TH:i' (optionally with seconds) as a wall-clock time in the given zone.
	 */
	public static function parse_local( string $raw, DateTimeZone $tz ): ?DateTimeImmutable {
		$raw = trim( $raw );
		if ( 1 !== preg_match( '/^(\d{4})-(\d{2})-(\d{2})[T ]([01]\d|2[0-3]):([0-5]\d)(?::([0-5]\d))?$/', $raw, $m ) ) {
			return null;
		}
		if ( ! checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) ) {
			return null;
		}
		$dt = DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', sprintf( '%s-%s-%s %s:%s:%s', $m[1], $m[2], $m[3], $m[4], $m[5], $m[6] ?? '00' ), $tz );
		return false === $dt ? null : $dt;
	}

	/**
	 * Sets the publication datetime of a draft / pending / scheduled post and makes it a scheduled (`future`) post.
	 *
	 * @return array{id:int,status:string,datetime:string}|WP_Error
	 */
	public function schedule( int $post_id, string $datetime ) {
		$post = $this->load( $post_id, 'edit_post' );
		if ( $post instanceof WP_Error ) {
			return $post;
		}
		if ( ! in_array( $post->post_status, self::SCHEDULABLE, true ) ) {
			return new WP_Error( 'uxstudio_post_status', __( 'Only a draft, pending or scheduled post can be scheduled.', 'ux-studio' ), array( 'status' => 409 ) );
		}
		if ( 'future' !== $post->post_status && ! current_user_can( 'publish_post', $post_id ) ) {
			return new WP_Error( 'uxstudio_forbidden', __( 'You are not allowed to schedule this post.', 'ux-studio' ), array( 'status' => 403 ) );
		}

		$now = ( $this->clock )();
		$dt  = self::parse_local( $datetime, $now->getTimezone() );
		if ( null === $dt ) {
			return new WP_Error( 'uxstudio_invalid_date', __( 'Enter a valid date and time.', 'ux-studio' ), array( 'status' => 422 ) );
		}
		if ( $dt->getTimestamp() < $now->getTimestamp() + 60 ) {
			return new WP_Error( 'uxstudio_date_in_past', __( 'Choose a moment in the future.', 'ux-studio' ), array( 'status' => 422 ) );
		}

		return $this->apply_schedule( $post_id, $dt );
	}

	/**
	 * Approves a reviewed post.
	 *
	 * @param string                    $mode     now | schedule | plan.
	 * @param string                    $datetime Used by `schedule` (site time zone).
	 * @param array<string, mixed>|null $plan     Used by `plan`; defaults to the plan of the generator that wrote the post.
	 * @return array<string, mixed>|WP_Error
	 */
	public function approve( int $post_id, string $mode, string $datetime = '', ?array $plan = null ) {
		$post = $this->load( $post_id, 'publish_post' );
		if ( $post instanceof WP_Error ) {
			return $post;
		}
		if ( ! in_array( $post->post_status, self::REVIEWABLE, true ) ) {
			return new WP_Error( 'uxstudio_post_status', __( 'Only a draft or pending post can be approved.', 'ux-studio' ), array( 'status' => 409 ) );
		}

		if ( 'now' === $mode ) {
			$now    = ( $this->clock )();
			$result = wp_update_post(
				array(
					'ID'            => $post_id,
					'post_status'   => 'publish',
					'post_date'     => $now->format( 'Y-m-d H:i:s' ),
					'post_date_gmt' => $now->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' ),
					'edit_date'     => true,
				),
				true
			);
			if ( is_wp_error( $result ) ) {
				return new WP_Error( 'uxstudio_update_failed', $result->get_error_message(), array( 'status' => 500 ) );
			}
			return array(
				'id'       => $post_id,
				'status'   => 'publish',
				'datetime' => $now->format( 'Y-m-d H:i:s' ),
			);
		}

		if ( 'schedule' === $mode ) {
			return $this->schedule( $post_id, $datetime );
		}

		if ( 'plan' === $mode ) {
			$now      = ( $this->clock )();
			$plan     = $plan ?? $this->plan_of_post( $post_id );
			$timezone = $now->getTimezone()->getName();
			try {
				$slots = PublishSlots::plan( $plan, 1, PublishSlots::occupied( $now->format( 'Y-m-d H:i:s' ) ), $now, $timezone );
			} catch ( \Throwable $e ) {
				return new WP_Error( 'uxstudio_plan_failed', $e->getMessage(), array( 'status' => 422 ) );
			}
			return $this->apply_schedule( $post_id, $slots[0] );
		}

		return new WP_Error( 'uxstudio_invalid_mode', __( 'Choose publish now, a date or the planned slot.', 'ux-studio' ), array( 'status' => 400 ) );
	}

	/**
	 * Approves several posts at once and gives them planned datetimes.
	 *
	 * @param array<int, int>           $post_ids
	 * @param array<string, mixed>|null $plan     Plan to use; null = default plan.
	 * @return array{scheduled:array<int,array{id:int,datetime:string}>,skipped:array<int,array{id:int,reason:string}>}|WP_Error
	 */
	public function bulk_approve( array $post_ids, ?array $plan = null ) {
		$valid   = array();
		$skipped = array();
		foreach ( array_values( array_unique( array_filter( array_map( 'absint', $post_ids ) ) ) ) as $id ) {
			$post = $this->load( $id, 'publish_post' );
			if ( $post instanceof WP_Error ) {
				$skipped[] = array(
					'id'     => $id,
					'reason' => $post->get_error_code(),
				);
				continue;
			}
			if ( ! in_array( $post->post_status, self::REVIEWABLE, true ) ) {
				$skipped[] = array(
					'id'     => $id,
					'reason' => 'uxstudio_post_status',
				);
				continue;
			}
			$valid[ $id ] = (string) $post->post_date;
		}

		$scheduled = array();
		if ( $valid ) {
			asort( $valid ); // The oldest drafts go first.
			$now      = ( $this->clock )();
			$timezone = $now->getTimezone()->getName();
			try {
				$slots = PublishSlots::plan( $plan ?? array(), count( $valid ), PublishSlots::occupied( $now->format( 'Y-m-d H:i:s' ), array_keys( $valid ) ), $now, $timezone );
			} catch ( \Throwable $e ) {
				return new WP_Error( 'uxstudio_plan_failed', $e->getMessage(), array( 'status' => 422 ) );
			}

			$i = 0;
			foreach ( array_keys( $valid ) as $id ) {
				$done = $this->apply_schedule( $id, $slots[ $i++ ] );
				if ( $done instanceof WP_Error ) {
					$skipped[] = array(
						'id'     => $id,
						'reason' => $done->get_error_code(),
					);
					continue;
				}
				$scheduled[] = array(
					'id'       => $id,
					'datetime' => $done['datetime'],
				);
			}
		}

		return array(
			'scheduled' => $scheduled,
			'skipped'   => $skipped,
		);
	}

	/**
	 * Rejects a generated post: moves it to the trash.
	 *
	 * @return array{id:int,status:string}|WP_Error
	 */
	public function reject( int $post_id ) {
		$post = $this->load( $post_id, 'delete_post' );
		if ( $post instanceof WP_Error ) {
			return $post;
		}
		if ( 'publish' === $post->post_status ) {
			return new WP_Error( 'uxstudio_post_status', __( 'A published post cannot be rejected here.', 'ux-studio' ), array( 'status' => 409 ) );
		}
		if ( ! wp_trash_post( $post_id ) ) {
			return new WP_Error( 'uxstudio_trash_failed', __( 'Could not move the post to the trash.', 'ux-studio' ), array( 'status' => 500 ) );
		}
		return array(
			'id'     => $post_id,
			'status' => 'trash',
		);
	}

	/**
	 * Sanitised content for the review preview.
	 *
	 * @return array<string, mixed>|WP_Error
	 */
	public function preview( int $post_id ) {
		$post = $this->load( $post_id, 'edit_post' );
		if ( $post instanceof WP_Error ) {
			return $post;
		}
		return array(
			'id'       => $post_id,
			'title'    => wp_strip_all_tags( (string) $post->post_title ),
			'status'   => (string) $post->post_status,
			'datetime' => (string) $post->post_date,
			'excerpt'  => wp_strip_all_tags( (string) $post->post_excerpt ),
			'content'  => wp_kses_post( (string) $post->post_content ),
			'edit_url' => (string) get_edit_post_link( $post_id, 'raw' ),
		);
	}

	/**
	 * @return array{id:int,status:string,datetime:string}|WP_Error
	 */
	private function apply_schedule( int $post_id, DateTimeImmutable $dt ) {
		$result = wp_update_post(
			array(
				'ID'            => $post_id,
				'post_status'   => 'future',
				'post_date'     => $dt->format( 'Y-m-d H:i:s' ),
				'post_date_gmt' => $dt->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' ),
				'edit_date'     => true,
			),
			true
		);
		if ( is_wp_error( $result ) ) {
			return new WP_Error( 'uxstudio_update_failed', $result->get_error_message(), array( 'status' => 500 ) );
		}
		return array(
			'id'       => $post_id,
			'status'   => 'future',
			'datetime' => $dt->format( 'Y-m-d H:i:s' ),
		);
	}

	/**
	 * Loads a blog post and checks the capability for that very post.
	 *
	 * @return object|WP_Error
	 */
	private function load( int $post_id, string $capability ) {
		$post = $post_id > 0 ? get_post( $post_id ) : null;
		if ( ! $post || 'post' !== $post->post_type ) {
			return new WP_Error( 'uxstudio_post_not_found', __( 'Post not found.', 'ux-studio' ), array( 'status' => 404 ) );
		}
		if ( ! current_user_can( $capability, $post_id ) ) {
			return new WP_Error( 'uxstudio_forbidden', __( 'You are not allowed to do this with this post.', 'ux-studio' ), array( 'status' => 403 ) );
		}
		return $post;
	}

	/**
	 * The publish plan of the generator that wrote the post (default plan when it is gone or has none).
	 *
	 * @return array<string, mixed>
	 */
	private function plan_of_post( int $post_id ): array {
		$manager = new GeneratorManager();
		$row     = $manager->get_generated_row( $post_id );
		if ( $row ) {
			$generator = $manager->get( (int) $row->generator_id );
			if ( $generator ) {
				return (array) ( ( (array) $generator->config )['publish_plan'] ?? array() );
			}
		}
		return array();
	}
}
