<?php
/**
 * Source: PublishPress Future (Post Expirator).
 *
 * @package UxStudio\Core\Migration
 */

namespace UxStudio\Core\Migration\Sources;

use UxStudio\Core\Migration\AbstractMetaSource;
use UxStudio\Core\Migration\Mapping;

defined( 'ABSPATH' ) || exit;

/**
 * Target module `auto-unpublish`: post meta `_uxstudio_auto_unpublish` (the unpublish time as a
 * site-local MySQL date) plus a single cron event `ux_studio/auto_unpublish_post`; the module's
 * hourly sweep unpublishes anything past due, even without the event.
 *
 * BEHAVIOUR-CHANGING: with the module ON the dates wait in the staging area (otherwise two plugins
 * would unpublish the same post), with it OFF they are written at once.
 *
 *  - Version 3 and later: table `{prefix}ppfuture_actions_args` (enabled rows: `scheduled_date` in UTC,
 *    `args` JSON with `expireType`).
 *  - Older versions and the block editor fallback: post meta `_expiration-date` (UTC timestamp) with
 *    `_expiration-date-status` = saved and the action in `_expiration-date-type` / `_expiration-date-options`.
 *
 * The module only moves a post to DRAFT. Other actions (trash, delete, private, sticky, categories) have no
 * equivalent and are reported; dates in the past are not migrated (they would unpublish the post at once).
 */
final class AutoUnpublishSource extends AbstractMetaSource {

	private const META_KEY = '_uxstudio_auto_unpublish';

	private const CRON_HOOK = 'ux_studio/auto_unpublish_post';

	protected function meta_type(): string {
		return 'post';
	}

	protected function data_kind(): string {
		return 'unpublish';
	}

	protected function data_label(): string {
		return __( 'Scheduled unpublishing', 'ux-studio' );
	}

	/**
	 * Whether a table exists.
	 *
	 * @param string $table Full table name.
	 */
	private function table_exists( string $table ): bool {
		global $wpdb;
		return $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ); // phpcs:ignore WordPress.DB
	}

	/**
	 * Can this expire action be reproduced (post to draft)?
	 *
	 * @param array<string, mixed> $args Action arguments.
	 */
	private function is_draft_action( array $args ): bool {
		$type = (string) ( $args['expireType'] ?? '' );
		return 'draft' === $type || ( 'change-status' === $type && 'draft' === (string) ( $args['newStatus'] ?? '' ) );
	}

	protected function build(): array {
		global $wpdb;
		$out  = array(
			'items'   => array(),
			'skipped' => array(),
		);
		$seen = array();
		$now  = time();

		$add = function ( int $post_id, int $ts, array $args ) use ( &$out, &$seen, $now ): void {
			if ( isset( $seen[ $post_id ] ) || $post_id <= 0 ) {
				return;
			}
			$seen[ $post_id ] = true;
			if ( ! $this->is_draft_action( $args ) ) {
				$kind                    = (string) ( $args['expireType'] ?? 'unknown' );
				$kind                   .= 'change-status' === $kind ? ':' . (string) ( $args['newStatus'] ?? '' ) : '';
				$out['skipped'][ 'action:' . $kind ] = ( $out['skipped'][ 'action:' . $kind ] ?? 0 ) + 1;
				return;
			}
			if ( $ts <= $now ) {
				$out['skipped']['past'] = ( $out['skipped']['past'] ?? 0 ) + 1;
				return;
			}
			$post = function_exists( 'get_post' ) ? get_post( $post_id ) : null;
			if ( ! $post ) {
				$out['skipped']['missing'] = ( $out['skipped']['missing'] ?? 0 ) + 1;
				return;
			}
			$out['items'][] = array(
				'key'    => 'pe:' . $post_id,
				'label'  => '' !== (string) $post->post_title ? (string) $post->post_title : '#' . $post_id,
				'id'     => $post_id,
				'meta'   => array( self::META_KEY => (string) wp_date( 'Y-m-d H:i:s', $ts ) ),
				'extra'  => array(
					'ts'      => $ts,
					'publish' => 'publish' === $post->post_status,
				),
				'review' => false,
			);
		};

		$table = $wpdb->prefix . 'ppfuture_actions_args';
		if ( $this->table_exists( $table ) ) {
			$rows = $wpdb->get_results( "SELECT post_id, scheduled_date, args FROM {$table} WHERE enabled = 1 ORDER BY id ASC", ARRAY_A ); // phpcs:ignore WordPress.DB
			foreach ( is_array( $rows ) ? $rows : array() as $row ) {
				$args = json_decode( (string) $row['args'], true );
				$add( (int) $row['post_id'], (int) strtotime( (string) $row['scheduled_date'] . ' UTC' ), is_array( $args ) ? $args : array() );
			}
		}

		// Legacy post meta (also what the plugin kept before version 3).
		$metas = $wpdb->get_results( "SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_expiration-date' ORDER BY meta_id ASC", ARRAY_A ); // phpcs:ignore WordPress.DB
		foreach ( is_array( $metas ) ? $metas : array() as $row ) {
			$post_id = (int) $row['post_id'];
			$status  = (string) get_post_meta( $post_id, '_expiration-date-status', true );
			if ( ! in_array( $status, array( 'saved', '1' ), true ) ) {
				continue;
			}
			$opts = get_post_meta( $post_id, '_expiration-date-options', true );
			$args = is_array( $opts ) ? $opts : array();
			if ( empty( $args['expireType'] ) ) {
				$args['expireType'] = (string) get_post_meta( $post_id, '_expiration-date-type', true );
				$args['newStatus']  = (string) get_post_meta( $post_id, '_expiration-date-post-status', true );
			}
			$add( $post_id, (int) $row['meta_value'], $args );
		}
		return $out;
	}

	protected function map( bool $active ): Mapping {
		$m = new Mapping();
		$c = $this->collect();
		if ( ! $c['items'] && ! $c['skipped'] ) {
			$m->note( __( 'The plugin has no scheduled actions stored, there is nothing to transfer.', 'ux-studio' ) );
			return $m;
		}
		foreach ( $c['skipped'] as $reason => $n ) {
			if ( 'past' === $reason ) {
				$m->lossy(
					sprintf(
						/* translators: %d: number of scheduled actions. */
						__( '%d scheduled actions are already in the past and are not transferred (they would unpublish the post at once).', 'ux-studio' ),
						$n
					)
				);
			} elseif ( 'missing' === $reason ) {
				$m->lossy(
					sprintf(
						/* translators: %d: number of scheduled actions. */
						__( '%d scheduled actions belong to posts that no longer exist.', 'ux-studio' ),
						$n
					)
				);
			} else {
				$m->lossy(
					sprintf(
						/* translators: 1: number of scheduled actions, 2: action of the plugin. */
						__( '%1$d scheduled actions "%2$s" have no equivalent: the module only moves a post to draft.', 'ux-studio' ),
						$n,
						substr( $reason, 7 )
					)
				);
			}
		}
		$m->note( __( 'Each date is copied to the post as the module keeps it. With the module on it is only prepared and written at the switch.', 'ux-studio' ) );
		return $m;
	}

	protected function data_plan( array $options ): array {
		return array(
			array(
				'key'   => 'unpublish',
				'label' => __( 'Scheduled unpublishing', 'ux-studio' ),
				'count' => count( $this->collect()['items'] ),
			),
		);
	}

	protected function after_unwrite( int $id, array $item ): void {
		$ts = wp_next_scheduled( self::CRON_HOOK, array( $id ) );
		if ( $ts ) {
			wp_unschedule_event( $ts, self::CRON_HOOK, array( $id ) );
		}
	}

	protected function after_write( int $id, array $item, array &$maps ): void {
		$extra = (array) ( $item['extra'] ?? array() );
		$ts    = (int) ( $extra['ts'] ?? 0 );
		if ( $ts > time() && ! empty( $extra['publish'] ) && ! wp_next_scheduled( self::CRON_HOOK, array( $id ) ) ) {
			wp_schedule_single_event( $ts, self::CRON_HOOK, array( $id ) );
			$maps[] = array( 'cron_event', $ts . '|' . self::CRON_HOOK . '|' . $id );
		}
	}
}
