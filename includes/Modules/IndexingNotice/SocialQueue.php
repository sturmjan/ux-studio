<?php
/**
 * Queue of changed addresses waiting for a social preview refresh and an IndexNow ping (PLAN.md 39.5).
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\IndexingNotice;

defined( 'ABSPATH' ) || exit;

/**
 * Pure logic on a plain array, so the dedupe and timing rules are tested without WordPress
 * (tests/indexing-queue.php). The state is one option without autoload:
 *
 *   items  url => { post_id, due (unix time), steps (social | indexnow), attempts }
 *   sent   url => unix time of the last successful run
 *
 * Rules: one entry per address (a second save before the run only merges); an address is not due before
 * DELAY seconds after the save (page caches have to be purged first, Facebook fetches the HTML at once) and
 * not before MIN_GAP seconds after its last run; a failed step is retried a few times, then dropped.
 */
final class SocialQueue {

	/** Option holding the queue (no autoload). */
	public const OPTION = 'uxstudio_indexing_queue';

	/** Seconds between the save and the run. */
	public const DELAY = 120;

	/** Same address at most once in this many seconds. */
	public const MIN_GAP = 600;

	/** Tries of one step before it is dropped. */
	public const MAX_ATTEMPTS = 3;

	/** Addresses per run through the Central App (its own limit is 50). */
	public const BATCH_CA = 50;

	/** Addresses per run with own keys (one Graph API call each, inside one request). */
	public const BATCH_OWN = 20;

	public const STEP_SOCIAL   = 'social';
	public const STEP_INDEXNOW = 'indexnow';

	/**
	 * A stored value made into a well formed state.
	 *
	 * @param mixed $raw Stored option value.
	 * @return array{items:array<string,array{post_id:int,due:int,steps:string[],attempts:int}>,sent:array<string,int>}
	 */
	public static function normalize( $raw ): array {
		$out = array(
			'items' => array(),
			'sent'  => array(),
		);
		if ( ! is_array( $raw ) ) {
			return $out;
		}
		foreach ( (array) ( $raw['items'] ?? array() ) as $url => $item ) {
			if ( ! is_string( $url ) || '' === $url || ! is_array( $item ) ) {
				continue;
			}
			$steps = array_values( array_intersect( array( self::STEP_SOCIAL, self::STEP_INDEXNOW ), array_map( 'strval', (array) ( $item['steps'] ?? array() ) ) ) );
			if ( ! $steps ) {
				continue;
			}
			$out['items'][ $url ] = array(
				'post_id'  => (int) ( $item['post_id'] ?? 0 ),
				'due'      => (int) ( $item['due'] ?? 0 ),
				'steps'    => $steps,
				'attempts' => (int) ( $item['attempts'] ?? 0 ),
			);
		}
		foreach ( (array) ( $raw['sent'] ?? array() ) as $url => $time ) {
			if ( is_string( $url ) && '' !== $url ) {
				$out['sent'][ $url ] = (int) $time;
			}
		}
		return $out;
	}

	/**
	 * Put an address in the queue.
	 *
	 * @param array<string, mixed> $queue   State.
	 * @param string               $url     Address.
	 * @param int                  $post_id Post the address belongs to.
	 * @param int                  $now     Unix time.
	 * @param string[]             $steps   Steps to run.
	 * @return array<string, mixed> New state.
	 */
	public static function add( array $queue, string $url, int $post_id, int $now, array $steps ): array {
		$queue = self::normalize( $queue );
		$steps = array_values( array_intersect( array( self::STEP_SOCIAL, self::STEP_INDEXNOW ), $steps ) );
		if ( '' === $url || ! $steps ) {
			return $queue;
		}
		$due = max( $now + self::DELAY, ( $queue['sent'][ $url ] ?? 0 ) + self::MIN_GAP );
		if ( isset( $queue['items'][ $url ] ) ) {
			// A second save before the run: merge, keep the earlier time, the post may have changed again.
			$item                   = $queue['items'][ $url ];
			$item['steps']          = array_values( array_unique( array_merge( $item['steps'], $steps ) ) );
			$item['post_id']        = $post_id ?: $item['post_id'];
			$item['due']            = min( $item['due'], $due );
			$queue['items'][ $url ] = $item;
			return $queue;
		}
		$queue['items'][ $url ] = array(
			'post_id'  => $post_id,
			'due'      => $due,
			'steps'    => $steps,
			'attempts' => 0,
		);
		return $queue;
	}

	/**
	 * Entries that are due, oldest first.
	 *
	 * @param array<string, mixed> $queue State.
	 * @param int                  $now   Unix time.
	 * @param int                  $limit Most entries.
	 * @return array<string, array{post_id:int,due:int,steps:string[],attempts:int}>
	 */
	public static function due( array $queue, int $now, int $limit ): array {
		$queue = self::normalize( $queue );
		$due   = array_filter( $queue['items'], static fn( array $item ): bool => $item['due'] <= $now );
		uasort( $due, static fn( array $a, array $b ): int => $a['due'] <=> $b['due'] );
		return array_slice( $due, 0, max( 0, $limit ), true );
	}

	/**
	 * Time of the next entry, or null when the queue is empty.
	 *
	 * @param array<string, mixed> $queue State.
	 */
	public static function next_due( array $queue ): ?int {
		$queue = self::normalize( $queue );
		if ( ! $queue['items'] ) {
			return null;
		}
		return min( array_column( $queue['items'], 'due' ) );
	}

	/**
	 * Record what happened to the steps of one address.
	 *
	 * @param array<string, mixed>           $queue    State.
	 * @param string                         $url      Address.
	 * @param array<string, string|int>      $outcomes Step => `done` | `drop` | seconds to wait before the next try.
	 * @param int                            $now      Unix time.
	 * @return array<string, mixed> New state.
	 */
	public static function settle( array $queue, string $url, array $outcomes, int $now ): array {
		$queue = self::normalize( $queue );
		if ( ! isset( $queue['items'][ $url ] ) ) {
			return $queue;
		}
		$item  = $queue['items'][ $url ];
		$keep  = array();
		$retry = 0;
		foreach ( $item['steps'] as $step ) {
			$outcome = $outcomes[ $step ] ?? 'done';
			if ( 'done' === $outcome ) {
				$queue['sent'][ $url ] = $now;
				continue;
			}
			if ( 'drop' === $outcome ) {
				continue;
			}
			if ( $item['attempts'] + 1 >= self::MAX_ATTEMPTS ) {
				continue;
			}
			$keep[] = $step;
			$retry  = max( $retry, (int) $outcome );
		}
		if ( ! $keep ) {
			unset( $queue['items'][ $url ] );
			return self::prune( $queue, $now );
		}
		$queue['items'][ $url ] = array(
			'post_id'  => $item['post_id'],
			'due'      => $now + max( 30, $retry ),
			'steps'    => $keep,
			'attempts' => $item['attempts'] + 1,
		);
		return self::prune( $queue, $now );
	}

	/**
	 * Forget old "sent" marks (they matter only for MIN_GAP).
	 *
	 * @param array<string, mixed> $queue State.
	 * @param int                  $now   Unix time.
	 * @return array<string, mixed>
	 */
	public static function prune( array $queue, int $now ): array {
		$queue = self::normalize( $queue );
		foreach ( $queue['sent'] as $url => $time ) {
			if ( $time + self::MIN_GAP < $now ) {
				unset( $queue['sent'][ $url ] );
			}
		}
		return $queue;
	}
}
