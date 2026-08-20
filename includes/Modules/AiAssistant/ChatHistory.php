<?php
/**
 * Per-user chat history for the internal (admin-side) AI assistant chat.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\AiAssistant;

defined( 'ABSPATH' ) || exit;

/**
 * Thin CRUD wrapper around uxstudio_ai_assistant_chat_history. Not used by
 * the public chat widget (which persists to uxstudio_ai_assistant_conversations
 * instead, see ChatEngine) - this is for a logged-in user's own saved
 * conversations with the assistant.
 */
final class ChatHistory {

	private string $table;

	public function __construct() {
		global $wpdb;
		$this->table = $wpdb->prefix . 'uxstudio_ai_assistant_chat_history';
	}

	/**
	 * Save or update a conversation and return its id.
	 *
	 * @param array<int, array{role:string,content:string}> $messages Full message list.
	 */
	public function save( int $user_id, array $messages, string $provider, string $model, ?int $conversation_id = null ): int {
		global $wpdb;

		$title = $this->generate_title( $messages );

		if ( $conversation_id ) {
			$wpdb->update(
				$this->table,
				array(
					'messages'   => wp_json_encode( $messages ),
					'updated_at' => current_time( 'mysql' ),
				),
				array(
					'id'      => $conversation_id,
					'user_id' => $user_id,
				),
				array( '%s', '%s' ),
				array( '%d', '%d' )
			);

			return $conversation_id;
		}

		$wpdb->insert(
			$this->table,
			array(
				'user_id'  => $user_id,
				'title'    => $title,
				'messages' => wp_json_encode( $messages ),
				'provider' => $provider,
				'model'    => $model,
			),
			array( '%d', '%s', '%s', '%s', '%s' )
		);

		return (int) $wpdb->insert_id;
	}

	/**
	 * @return array{id:int,title:string,messages:array,provider:string,model:string}|null
	 */
	public function get( int $conversation_id, int $user_id ): ?array {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$this->table} WHERE id = %d AND user_id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$conversation_id,
				$user_id
			),
			ARRAY_A
		);

		if ( ! $row ) {
			return null;
		}

		$row['messages'] = json_decode( (string) $row['messages'], true ) ?: array();
		return $row;
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public function list( int $user_id, int $limit = 20 ): array {
		global $wpdb;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, title, provider, model, created_at, updated_at
				 FROM {$this->table}
				 WHERE user_id = %d
				 ORDER BY updated_at DESC
				 LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$user_id,
				$limit
			),
			ARRAY_A
		);

		return $rows ?: array();
	}

	public function delete( int $conversation_id, int $user_id ): bool {
		global $wpdb;
		return (bool) $wpdb->delete(
			$this->table,
			array(
				'id'      => $conversation_id,
				'user_id' => $user_id,
			),
			array( '%d', '%d' )
		);
	}

	/**
	 * @param array<int, array{role:string,content:string}> $messages Message list.
	 */
	private function generate_title( array $messages ): string {
		foreach ( $messages as $message ) {
			if ( 'user' === ( $message['role'] ?? '' ) ) {
				$title = mb_substr( wp_strip_all_tags( (string) $message['content'] ), 0, 80 );
				return '' !== $title ? $title : __( 'New conversation', 'ux-studio' );
			}
		}
		return __( 'New conversation', 'ux-studio' );
	}
}
