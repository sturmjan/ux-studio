<?php
/**
 * One-time clean-up for the UNIQUE session_id key on the conversations table (module DB version 2).
 *
 * Until then nothing stopped two rows for one session (a chat save and an operator request inserting at the same
 * moment), and the code only de-duplicated afterwards. Before the UNIQUE key can be created the duplicates have to
 * be merged: the row with the lowest id survives, it receives the messages of the others in time order and the
 * most advanced handoff state, and the extra rows are deleted.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\AiAssistant;

defined( 'ABSPATH' ) || exit;

final class ConversationDedupe {

	public const UNIQUE_KEY = 'session_id_unique';

	/** Sessions merged per query round (keeps the memory use of a site with many duplicates bounded). */
	private const BATCH = 200;

	/** Handoff progress, higher = more advanced (what has to survive a merge). */
	private const HANDOFF_RANK = array(
		HandoffManager::STATUS_AI        => 0,
		HandoffManager::STATUS_CLOSED    => 1,
		HandoffManager::STATUS_REQUESTED => 2,
		HandoffManager::STATUS_ACTIVE    => 3,
	);

	/**
	 * Merge every session that has more than one row.
	 *
	 * @return int Number of rows removed.
	 */
	public static function merge_all(): int {
		global $wpdb;
		$table   = ConversationStore::table();
		$removed = 0;

		// Each round removes the duplicates of its sessions, so the next round sees the next ones.
		for ( $guard = 0; $guard < 1000; $guard++ ) {
			$sessions = $wpdb->get_col(
				$wpdb->prepare( "SELECT session_id FROM {$table} GROUP BY session_id HAVING COUNT(*) > 1 LIMIT %d", self::BATCH ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			);
			if ( empty( $sessions ) ) {
				break;
			}
			$progress = 0;
			foreach ( $sessions as $session_id ) {
				$progress += self::merge_session( (string) $session_id );
			}
			$removed += $progress;
			if ( 0 === $progress ) {
				break; // Nothing could be deleted (DB error) - do not loop forever.
			}
		}

		return $removed;
	}

	/**
	 * Merge the rows of one session into the lowest id.
	 *
	 * @return int Number of rows removed.
	 */
	public static function merge_session( string $session_id ): int {
		global $wpdb;
		$table = ConversationStore::table();

		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE session_id = %s ORDER BY id ASC", $session_id ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);
		if ( ! is_array( $rows ) || count( $rows ) < 2 ) {
			return 0;
		}

		$merged  = self::merge_rows( $rows );
		$keep_id = (int) $rows[0]['id'];

		// Update the survivor first; the extras are deleted only when that worked.
		$updated = $wpdb->update( $table, $merged, array( 'id' => $keep_id ) );
		if ( false === $updated ) {
			return 0;
		}

		$removed = 0;
		foreach ( array_slice( $rows, 1 ) as $row ) {
			$removed += (int) $wpdb->delete( $table, array( 'id' => (int) $row['id'] ) );
		}
		return $removed;
	}

	/**
	 * Pure merge of the rows (ordered by id, at least two) of one session into the column values of the survivor.
	 *
	 * @param array<int, array<string, mixed>> $rows Rows of one session, lowest id first.
	 * @return array<string, mixed> Columns to write to the first row.
	 */
	public static function merge_rows( array $rows ): array {
		$messages = array();
		foreach ( $rows as $row ) {
			$decoded = json_decode( (string) ( $row['messages'] ?? '' ), true );
			foreach ( is_array( $decoded ) ? $decoded : array() as $message ) {
				$messages[] = $message;
			}
		}
		// Time order; messages without a timestamp (chat turns) keep their place relative to their own row.
		$indexed = array();
		foreach ( $messages as $i => $message ) {
			$indexed[] = array( $i, is_array( $message ) ? (string) ( $message['timestamp'] ?? '' ) : '', $message );
		}
		$last_stamp = '';
		foreach ( $indexed as $k => $entry ) {
			if ( '' === $entry[1] ) {
				$indexed[ $k ][1] = $last_stamp;
			} else {
				$last_stamp = $entry[1];
			}
		}
		usort(
			$indexed,
			static fn( array $a, array $b ): int => array( $a[1], $a[0] ) <=> array( $b[1], $b[0] )
		);
		$messages = array_map( static fn( array $e ) => $e[2], $indexed );

		// Handoff: the most advanced state wins; ties go to the lowest id. Its companion columns come with it.
		$best = $rows[0];
		foreach ( $rows as $row ) {
			if ( self::rank( $row ) > self::rank( $best ) ) {
				$best = $row;
			}
		}

		// Rating: the latest non-zero one (higher id = later).
		$rating = 0;
		$feedback = (string) ( $rows[0]['rating_feedback'] ?? '' );
		foreach ( $rows as $row ) {
			if ( (int) ( $row['rating'] ?? 0 ) > 0 ) {
				$rating   = (int) $row['rating'];
				$feedback = (string) ( $row['rating_feedback'] ?? '' );
			}
		}

		$max_str = static function ( string $column ) use ( $rows ): ?string {
			$values = array_filter( array_map( static fn( array $r ) => $r[ $column ] ?? null, $rows ), static fn( $v ): bool => null !== $v && '' !== $v && '0000-00-00 00:00:00' !== $v );
			return empty( $values ) ? null : (string) max( $values );
		};
		$max_int = static fn( string $column ): int => (int) max( array_map( static fn( array $r ): int => (int) ( $r[ $column ] ?? 0 ), $rows ) );
		$first   = static function ( string $column ) use ( $rows ): string {
			foreach ( $rows as $row ) {
				if ( '' !== (string) ( $row[ $column ] ?? '' ) ) {
					return (string) $row[ $column ];
				}
			}
			return '';
		};

		return array(
			'messages'             => wp_json_encode( $messages, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
			'visitor_ip'           => $first( 'visitor_ip' ),
			'visitor_user_agent'   => $first( 'visitor_user_agent' ),
			'page_url'             => $first( 'page_url' ),
			'status'               => $best['status'] ?? ( $rows[0]['status'] ?? 'active' ),
			'gdpr_consent'         => $max_int( 'gdpr_consent' ),
			'rating'               => $rating,
			'rating_feedback'      => $feedback,
			'handoff_status'       => (string) ( $best['handoff_status'] ?? HandoffManager::STATUS_AI ),
			'handoff_requested_at' => $best['handoff_requested_at'] ?? null,
			'handoff_assigned_to'  => $best['handoff_assigned_to'] ?? null,
			'handoff_started_at'   => $best['handoff_started_at'] ?? null,
			'operator_unread'      => $max_int( 'operator_unread' ),
			'customer_unread'      => $max_int( 'customer_unread' ),
			'last_message_at'      => $max_str( 'last_message_at' ),
			'updated_at'           => $max_str( 'updated_at' ) ?? ( $rows[0]['updated_at'] ?? null ),
		);
	}

	/**
	 * Make sure the UNIQUE key exists (the dbDelta of the migration normally created it): merge any duplicates
	 * that appeared in the meantime, add the key if missing and drop the old plain `session_id` index it replaces.
	 */
	public static function ensure_unique_key(): void {
		global $wpdb;
		$table = ConversationStore::table();

		if ( ! self::has_index( self::UNIQUE_KEY ) ) {
			self::merge_all();
			$wpdb->query( "ALTER TABLE {$table} ADD UNIQUE KEY " . self::UNIQUE_KEY . ' (session_id)' ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}

		if ( self::has_index( self::UNIQUE_KEY ) && self::has_index( 'session_id' ) ) {
			$wpdb->query( "ALTER TABLE {$table} DROP INDEX session_id" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
	}

	/**
	 * Whether the conversations table has an index of that name.
	 */
	public static function has_index( string $name ): bool {
		global $wpdb;
		$table = ConversationStore::table();
		$found = $wpdb->get_var( $wpdb->prepare( "SHOW INDEX FROM {$table} WHERE Key_name = %s", $name ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return null !== $found && '' !== $found;
	}

	/**
	 * @param array<string, mixed> $row Conversation row.
	 */
	private static function rank( array $row ): int {
		return self::HANDOFF_RANK[ (string) ( $row['handoff_status'] ?? '' ) ] ?? 0;
	}
}
