<?php
/**
 * FAQ CRUD + search over uxstudio_ai_assistant_faqs.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\AiAssistant;

defined( 'ABSPATH' ) || exit;

/**
 * Ported from the legacy ai-assistant module's FaqManager.
 */
final class FaqManager {

	/**
	 * FULLTEXT search with a LIKE fallback, filtered to the chat target
	 * (public/internal). Called by the chat engine - the return shape
	 * (associative arrays keyed like the table columns) is part of the
	 * cross-module contract and must not change without updating the caller.
	 *
	 * @param string $query       Search query.
	 * @param int    $limit       Max results.
	 * @param string $chat_target 'public' or 'internal'.
	 * @return array<int, array<string, mixed>>
	 */
	public static function search( string $query, int $limit, string $chat_target ): array {
		global $wpdb;

		$query = trim( $query );
		if ( '' === $query ) {
			return array();
		}

		$flag  = 'internal' === $chat_target ? 'use_internal' : 'use_public';
		$table = "{$wpdb->prefix}uxstudio_ai_assistant_faqs";
		$limit = max( 1, $limit );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $flag is one of two hardcoded column names, never user input.
		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table}
				 WHERE is_active = 1 AND {$flag} = 1 AND MATCH(question, answer) AGAINST(%s IN NATURAL LANGUAGE MODE)
				 LIMIT %d",
				$query,
				$limit
			),
			ARRAY_A
		);

		if ( ! empty( $results ) ) {
			return $results;
		}

		$like = '%' . $wpdb->esc_like( $query ) . '%';

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table}
				 WHERE is_active = 1 AND {$flag} = 1 AND question LIKE %s
				 ORDER BY sort_order ASC, created_at DESC
				 LIMIT %d",
				$like,
				$limit
			),
			ARRAY_A
		);

		return is_array( $results ) ? $results : array();
	}

	/**
	 * Insert a FAQ entry.
	 *
	 * @param array $data { question, answer, category?, sort_order?, is_active?, use_public?, use_internal? }.
	 */
	public function create( array $data ): int {
		global $wpdb;

		$wpdb->insert(
			"{$wpdb->prefix}uxstudio_ai_assistant_faqs",
			array(
				'question'     => mb_substr( (string) $data['question'], 0, 500 ),
				'answer'       => (string) $data['answer'],
				'category'     => (string) ( $data['category'] ?? '' ),
				'sort_order'   => (int) ( $data['sort_order'] ?? 0 ),
				'is_active'    => array_key_exists( 'is_active', $data ) ? (int) (bool) $data['is_active'] : 1,
				'use_public'   => array_key_exists( 'use_public', $data ) ? (int) (bool) $data['use_public'] : 1,
				'use_internal' => array_key_exists( 'use_internal', $data ) ? (int) (bool) $data['use_internal'] : 0,
				'created_at'   => current_time( 'mysql' ),
				'updated_at'   => current_time( 'mysql' ),
			),
			array( '%s', '%s', '%s', '%d', '%d', '%d', '%d', '%s', '%s' )
		);

		return (int) $wpdb->insert_id;
	}

	/**
	 * Update FAQ fields (question/answer/category/sort_order/is_active/use_public/use_internal).
	 *
	 * @param int   $id   FAQ id.
	 * @param array $data Fields to update.
	 */
	public function update( int $id, array $data ): bool {
		global $wpdb;

		$allowed = array(
			'question'     => '%s',
			'answer'       => '%s',
			'category'     => '%s',
			'sort_order'   => '%d',
			'is_active'    => '%d',
			'use_public'   => '%d',
			'use_internal' => '%d',
		);

		$update = array();
		$format = array();
		foreach ( $allowed as $key => $fmt ) {
			if ( ! array_key_exists( $key, $data ) ) {
				continue;
			}
			$update[ $key ] = '%d' === $fmt ? (int) $data[ $key ] : (string) $data[ $key ];
			$format[]       = $fmt;
		}

		if ( empty( $update ) ) {
			return true;
		}

		$update['updated_at'] = current_time( 'mysql' );
		$format[]              = '%s';

		$updated = $wpdb->update( "{$wpdb->prefix}uxstudio_ai_assistant_faqs", $update, array( 'id' => $id ), $format, array( '%d' ) );

		return false !== $updated;
	}

	/**
	 * Delete a FAQ entry.
	 *
	 * @param int $id FAQ id.
	 */
	public function delete( int $id ): bool {
		global $wpdb;

		return false !== $wpdb->delete( "{$wpdb->prefix}uxstudio_ai_assistant_faqs", array( 'id' => $id ), array( '%d' ) );
	}

	/**
	 * One FAQ by id.
	 *
	 * @param int $id FAQ id.
	 */
	public function get( int $id ): ?array {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$wpdb->prefix}uxstudio_ai_assistant_faqs WHERE id = %d", $id ),
			ARRAY_A
		);

		return is_array( $row ) ? $row : null;
	}

	/**
	 * Paginated FAQ list for the admin UI (all FAQs, any target/status).
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function get_all(): array {
		global $wpdb;

		$rows = $wpdb->get_results(
			"SELECT * FROM {$wpdb->prefix}uxstudio_ai_assistant_faqs ORDER BY sort_order ASC, created_at DESC",
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Record helpful/not-helpful feedback from the chat widget. Called by the
	 * chat engine (optionally, via method_exists()) - keep this static and
	 * keep this exact signature.
	 */
	public static function record_feedback( int $id, bool $helpful ): void {
		global $wpdb;

		$column = $helpful ? 'helpful_yes' : 'helpful_no';
		$wpdb->query(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $column is one of two hardcoded column names, never user input.
				"UPDATE {$wpdb->prefix}uxstudio_ai_assistant_faqs SET {$column} = {$column} + 1 WHERE id = %d",
				$id
			)
		);
	}

	/**
	 * Increment a FAQ's view counter.
	 */
	public static function increment_views( int $id ): void {
		global $wpdb;

		$wpdb->query(
			$wpdb->prepare( "UPDATE {$wpdb->prefix}uxstudio_ai_assistant_faqs SET views = views + 1 WHERE id = %d", $id )
		);
	}

	/**
	 * Distinct non-empty categories.
	 *
	 * @return array<int, string>
	 */
	public function get_categories(): array {
		global $wpdb;

		$rows = $wpdb->get_col(
			"SELECT DISTINCT category FROM {$wpdb->prefix}uxstudio_ai_assistant_faqs WHERE category != '' ORDER BY category ASC"
		);

		return is_array( $rows ) ? $rows : array();
	}
}
