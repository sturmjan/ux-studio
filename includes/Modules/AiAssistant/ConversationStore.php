<?php
/**
 * Race-safe writes to the conversations table.
 *
 * The chat engine (a streamed answer can take a minute) and the handoff code
 * (request / cancel / operator messages) both edit the same row's `messages`
 * JSON. Saving a copy read at the start of a request would silently drop
 * everything the other side added meanwhile, so both go through here:
 *  - append_messages() re-reads the row and appends with a compare-and-swap
 *    UPDATE (retried), so concurrent appends are merged, never overwritten;
 *  - ensure_row() makes sure one session has exactly one row. Since module DB
 *    version 2 session_id has a UNIQUE key (the database rejects the second
 *    insert); sites that have not run that upgrade yet fall back to
 *    de-duplicating in code.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\AiAssistant;

defined( 'ABSPATH' ) || exit;

final class ConversationStore {

	private const MAX_ATTEMPTS = 5;

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'uxstudio_ai_assistant_conversations';
	}

	/**
	 * Id of the oldest row of a session, or 0 when there is none.
	 */
	public static function find_id( string $session_id ): int {
		global $wpdb;
		$table = self::table();
		return (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT MIN(id) FROM {$table} WHERE session_id = %s", $session_id ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);
	}

	/**
	 * Returns the id of the single row of a session, inserting $insert_data
	 * when none exists. If two requests insert at the same time (e.g. the chat
	 * finishing while the visitor pressed "call an operator"): with the UNIQUE
	 * key the second insert simply fails and the existing row is returned;
	 * without it (index not created yet) both rows exist, the row with the
	 * lowest id wins and the other one is removed - it is still empty, because
	 * message content is only ever written through append_messages().
	 *
	 * @param array<string,mixed> $insert_data Column values for a new row (must include session_id).
	 */
	public static function ensure_row( string $session_id, array $insert_data ): int {
		global $wpdb;

		$id = self::find_id( $session_id );
		if ( $id > 0 ) {
			return $id;
		}

		// A duplicate-key failure is expected here (the other request won): keep it out of the error log.
		$previous = $wpdb->suppress_errors( true );
		$inserted = $wpdb->insert( self::table(), $insert_data );
		$wpdb->suppress_errors( $previous );
		$mine = $inserted ? (int) $wpdb->insert_id : 0;

		$winner = self::find_id( $session_id );
		if ( $winner > 0 && $winner !== $mine ) {
			if ( $mine > 0 ) {
				$wpdb->delete( self::table(), array( 'id' => $mine ) );
			}
			return $winner;
		}

		return $mine;
	}

	/**
	 * Appends messages to a conversation without losing concurrent appends.
	 * Re-reads the row, appends, and writes only if `messages` is still what we
	 * read (compare-and-swap); on a lost race it retries from the fresh value.
	 *
	 * @param array<int,array<string,mixed>> $new_messages Messages to append.
	 * @param array<string,mixed>            $extra        Other columns to set in the same UPDATE.
	 * @param callable|null                  $extra_from_row function( object $row ): array<string,mixed> - extra columns derived from the freshly read row (e.g. counters).
	 * @return array<int,array<string,mixed>>|null The full message list written, or null when the row is gone / the write kept failing.
	 */
	public static function append_messages( int $conversation_id, array $new_messages, array $extra = array(), ?callable $extra_from_row = null ): ?array {
		global $wpdb;
		$table = self::table();

		for ( $attempt = 0; $attempt < self::MAX_ATTEMPTS; $attempt++ ) {
			$row = $wpdb->get_row(
				$wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $conversation_id ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			);
			if ( ! $row ) {
				return null;
			}

			$old_json = (string) $row->messages;
			$messages = json_decode( $old_json, true );
			$messages = is_array( $messages ) ? $messages : array();
			foreach ( $new_messages as $message ) {
				$messages[] = $message;
			}

			$data = array_merge(
				$extra,
				null !== $extra_from_row ? $extra_from_row( $row ) : array(),
				array( 'messages' => wp_json_encode( $messages ) )
			);

			// Compare-and-swap on the value we read. A NULL column cannot be compared with "=", and on the last
			// attempt we prefer a (very unlikely) lost update over silently dropping the new messages.
			$where = array( 'id' => $conversation_id );
			if ( null !== $row->messages && $attempt < self::MAX_ATTEMPTS - 1 ) {
				$where['messages'] = $old_json;
			}
			$affected = $wpdb->update( $table, $data, $where );
			if ( $affected ) {
				return $messages;
			}
		}

		return null;
	}
}
