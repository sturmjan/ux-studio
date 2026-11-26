<?php
/**
 * Vector storage over uxstudio_ai_assistant_vectors (the $wpdb side of the core VectorStore port).
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\AiAssistant\Rag;

use Ux1\AiCore\Rag\VectorStore as VectorStorePort;

defined( 'ABSPATH' ) || exit;

/**
 * Every vector belongs to one chat_target ('public' or 'internal'). The same
 * chunk may be stored for both targets - the embedding is computed once and
 * inserted once per target (see TrainingSourceManager), so duplicating a
 * source across both chats costs no extra embedding API calls. Search always
 * filters by chat_target so the public chat never sees internal-only vectors.
 *
 * This is the $wpdb implementation of the core's Ux1\AiCore\Rag\VectorStore port: scoring lives in the core
 * (VectorSearch); this class only reads and writes rows, and always filters candidates by embedding_model.
 */
final class VectorStore implements VectorStorePort {

	public const TARGET_PUBLIC   = 'public';
	public const TARGET_INTERNAL = 'internal';
	public const TARGETS         = array( self::TARGET_PUBLIC, self::TARGET_INTERNAL );

	private string $table;
	private string $sources_table;

	public function __construct() {
		global $wpdb;
		$this->table         = "{$wpdb->prefix}uxstudio_ai_assistant_vectors";
		$this->sources_table = "{$wpdb->prefix}uxstudio_ai_assistant_training_sources";
	}

	/**
	 * Coerce an arbitrary value to a valid chat_target, defaulting to 'public'.
	 */
	public static function normalize_target( ?string $target ): string {
		return in_array( $target, self::TARGETS, true ) ? $target : self::TARGET_PUBLIC;
	}

	/**
	 * Insert one vector for a single chat_target.
	 *
	 * @param array<int, float> $vector   Embedding vector.
	 * @param array<string, mixed> $metadata Optional metadata (title/url/etc).
	 */
	public function insert( string $chat_target, string $source_type, ?int $source_id, string $chunk_text, int $chunk_index, array $vector, string $embedding_model, array $metadata = array() ): int {
		global $wpdb;

		$wpdb->insert(
			$this->table,
			array(
				'chat_target'     => self::normalize_target( $chat_target ),
				'source_type'     => $source_type,
				'source_id'       => $source_id,
				'chunk_text'      => $chunk_text,
				'chunk_index'     => $chunk_index,
				'vector'          => wp_json_encode( $vector ),
				'embedding_model' => $embedding_model,
				'metadata'        => empty( $metadata ) ? null : wp_json_encode( $metadata ),
				'created_at'      => current_time( 'mysql' ),
			),
			array( '%s', '%s', '%d', '%s', '%d', '%s', '%s', '%s', '%s' )
		);

		return (int) $wpdb->insert_id;
	}

	/**
	 * Insert a batch of vectors (each item must include 'chat_target').
	 *
	 * @param array<int, array{chat_target:string,source_type:string,source_id:?int,chunk_text:string,chunk_index:int,vector:array<int,float>,metadata?:array<string,mixed>}> $items Items to insert.
	 */
	public function insert_batch( array $items, string $embedding_model ): int {
		global $wpdb;

		if ( empty( $items ) ) {
			return 0;
		}

		$inserted = 0;

		foreach ( array_chunk( $items, 50 ) as $batch ) {
			$placeholders = array();
			$values       = array();

			foreach ( $batch as $item ) {
				$placeholders[] = '(%s, %s, %d, %s, %d, %s, %s, %s, NOW())';
				$values[]       = self::normalize_target( $item['chat_target'] ?? self::TARGET_PUBLIC );
				$values[]       = $item['source_type'];
				$values[]       = $item['source_id'] ?? 0;
				$values[]       = $item['chunk_text'];
				$values[]       = $item['chunk_index'];
				$values[]       = wp_json_encode( $item['vector'] );
				$values[]       = $embedding_model;
				$values[]       = empty( $item['metadata'] ) ? null : wp_json_encode( $item['metadata'] );
			}

			$sql = "INSERT INTO {$this->table} (chat_target, source_type, source_id, chunk_text, chunk_index, vector, embedding_model, metadata, created_at) VALUES "
				. implode( ', ', $placeholders );

			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- placeholders assembled above, values bound via $wpdb->prepare().
			$wpdb->query( $wpdb->prepare( $sql, $values ) );
			$inserted += count( $batch );
		}

		return $inserted;
	}

	/**
	 * Delete vectors for a source. Pass $chat_target = null to delete across all targets.
	 */
	public function delete_by_source( ?string $chat_target, string $source_type, ?int $source_id = null ): int {
		global $wpdb;

		$where = array( 'source_type' => $source_type );
		if ( null !== $chat_target ) {
			$where['chat_target'] = self::normalize_target( $chat_target );
		}
		if ( null !== $source_id ) {
			$where['source_id'] = $source_id;
		}

		return (int) $wpdb->delete( $this->table, $where );
	}

	/**
	 * Distinct source ids that have vectors of a source type.
	 *
	 * @return array<int, int>
	 */
	public function source_ids( string $source_type ): array {
		global $wpdb;
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT source_id FROM {$this->table} WHERE source_type = %s AND source_id IS NOT NULL ORDER BY source_id", $source_type ) );
		return array_map( 'intval', (array) $ids );
	}

	/**
	 * Chat targets a source has vectors for.
	 *
	 * @return array<int, string>
	 */
	public function targets_for_source( string $source_type, int $source_id ): array {
		global $wpdb;
		$targets = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT chat_target FROM {$this->table} WHERE source_type = %s AND source_id = %d", $source_type, $source_id ) );
		return array_values( array_map( 'strval', (array) $targets ) );
	}

	/**
	 * Highest vector id of a source (0 when none), used to replace a source's vectors without a gap.
	 */
	public function max_id_for_source( string $source_type, int $source_id ): int {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT MAX(id) FROM {$this->table} WHERE source_type = %s AND source_id = %d", $source_type, $source_id ) );
	}

	/**
	 * Delete a source's vectors with id up to (and including) $max_id; newer rows are kept.
	 */
	public function delete_source_up_to( string $source_type, int $source_id, int $max_id ): int {
		global $wpdb;
		return (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$this->table} WHERE source_type = %s AND source_id = %d AND id <= %d", $source_type, $source_id, $max_id ) );
	}

	/**
	 * Core port: candidate vectors of one chat_target made by ONE embedding model, in batches (vectors of
	 * different models are not comparable, so they are never returned together).
	 *
	 * @return \Generator<int, array<int, array{id:int,chunk_text:string,vector:array<int,float>,source_type:string,source_id:?int,metadata:?array<string,mixed>}>>
	 */
	public function candidate_batches( string $target, string $embedding_model, ?string $source_type, int $batch_size ): iterable {
		global $wpdb;

		$where = $wpdb->prepare( ' WHERE chat_target = %s AND embedding_model = %s', self::normalize_target( $target ), $embedding_model );
		if ( null !== $source_type ) {
			$where .= $wpdb->prepare( ' AND source_type = %s', $source_type );
		}

		$batch_size = max( 1, $batch_size );
		$offset     = 0;

		while ( true ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $where built exclusively from $wpdb->prepare() fragments above.
			$rows = $wpdb->get_results(
				"SELECT id, source_type, source_id, chunk_text, vector, metadata FROM {$this->table}{$where} ORDER BY id LIMIT {$batch_size} OFFSET {$offset}",
				ARRAY_A
			);

			if ( empty( $rows ) ) {
				return;
			}

			$batch = array();
			foreach ( $rows as $row ) {
				$vector = json_decode( (string) $row['vector'], true );
				if ( ! is_array( $vector ) || empty( $vector ) ) {
					continue;
				}
				$batch[] = array(
					'id'          => (int) $row['id'],
					'chunk_text'  => $row['chunk_text'],
					'vector'      => $vector,
					'source_type' => $row['source_type'],
					'source_id'   => $row['source_id'] ? (int) $row['source_id'] : null,
					'metadata'    => $row['metadata'] ? json_decode( (string) $row['metadata'], true ) : null,
				);
			}

			if ( ! empty( $batch ) ) {
				yield $batch;
			}

			$offset += $batch_size;
			if ( count( $rows ) < $batch_size ) {
				return;
			}
		}
	}

	/**
	 * FULLTEXT search over chunk_text within one chat_target.
	 *
	 * @return array<int, array{id:int,chunk_text:string,relevance:float,source_type:string,source_id:?int,metadata:?array<string,mixed>}>
	 */
	public function fulltext_search( string $chat_target, string $query, int $limit = 10, ?string $source_type = null ): array {
		global $wpdb;

		$chat_target = self::normalize_target( $chat_target );
		$where       = $wpdb->prepare( ' AND chat_target = %s', $chat_target );
		if ( null !== $source_type ) {
			$where .= $wpdb->prepare( ' AND source_type = %s', $source_type );
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $where built exclusively from $wpdb->prepare() fragments above.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, source_type, source_id, chunk_text, metadata, MATCH(chunk_text) AGAINST(%s IN NATURAL LANGUAGE MODE) AS relevance
				 FROM {$this->table}
				 WHERE MATCH(chunk_text) AGAINST(%s IN NATURAL LANGUAGE MODE){$where}
				 ORDER BY relevance DESC
				 LIMIT %d",
				$query,
				$query,
				$limit
			),
			ARRAY_A
		);

		$results = array();
		foreach ( (array) $rows as $row ) {
			$results[] = array(
				'id'          => (int) $row['id'],
				'chunk_text'  => $row['chunk_text'],
				'relevance'   => (float) $row['relevance'],
				'source_type' => $row['source_type'],
				'source_id'   => $row['source_id'] ? (int) $row['source_id'] : null,
				'metadata'    => $row['metadata'] ? json_decode( (string) $row['metadata'], true ) : null,
			);
		}

		return $results;
	}

	/**
	 * Aggregate stats for the RAG admin UI.
	 *
	 * @return array<string, mixed>
	 */
	public function get_stats(): array {
		global $wpdb;

		return array(
			'total_vectors'    => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$this->table}" ),
			'by_source_type'   => $wpdb->get_results( "SELECT source_type, COUNT(*) as count FROM {$this->table} GROUP BY source_type ORDER BY count DESC", ARRAY_A ) ?: array(),
			'by_chat_target'   => $wpdb->get_results( "SELECT chat_target, COUNT(*) as count FROM {$this->table} GROUP BY chat_target", ARRAY_A ) ?: array(),
			'by_model'         => $wpdb->get_results( "SELECT embedding_model, COUNT(*) as count FROM {$this->table} GROUP BY embedding_model", ARRAY_A ) ?: array(),
			'total_sources'    => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$this->sources_table}" ),
			'pending_sources'  => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$this->sources_table} WHERE status = %s", 'pending' ) ),
		);
	}

	/**
	 * Number of vectors for a source (optionally scoped to a chat_target).
	 */
	public function count_by_source( string $source_type, ?int $source_id = null, ?string $chat_target = null ): int {
		global $wpdb;

		$where = $wpdb->prepare( 'source_type = %s', $source_type );
		if ( null !== $source_id ) {
			$where .= $wpdb->prepare( ' AND source_id = %d', $source_id );
		}
		if ( null !== $chat_target ) {
			$where .= $wpdb->prepare( ' AND chat_target = %s', self::normalize_target( $chat_target ) );
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $where built exclusively from $wpdb->prepare() fragments above.
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$this->table} WHERE {$where}" );
	}

	/**
	 * Whether the chat_target has any vector made by the given embedding model.
	 */
	public function has_vectors( string $target, string $embedding_model ): bool {
		global $wpdb;

		$count = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$this->table} WHERE chat_target = %s AND embedding_model = %s LIMIT 1", self::normalize_target( $target ), $embedding_model )
		);

		return $count > 0;
	}

	/**
	 * Number of vectors stored with any of the given embedding model ids.
	 *
	 * @param array<int, string> $models Model ids.
	 */
	public function count_by_models( array $models ): int {
		global $wpdb;

		if ( empty( $models ) ) {
			return 0;
		}

		$placeholders = implode( ', ', array_fill( 0, count( $models ), '%s' ) );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- placeholders only, values bound by prepare().
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$this->table} WHERE embedding_model IN ({$placeholders})", $models ) );
	}

	/**
	 * Rows (id, chunk_text) stored with any of the given embedding model ids, for re-embedding.
	 *
	 * @param array<int, string> $models Model ids.
	 * @return array<int, array{id:int,chunk_text:string}>
	 */
	public function rows_by_models( array $models, int $limit ): array {
		global $wpdb;

		if ( empty( $models ) ) {
			return array();
		}

		$placeholders = implode( ', ', array_fill( 0, count( $models ), '%s' ) );
		$args         = array_merge( $models, array( max( 1, $limit ) ) );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- placeholders only, values bound by prepare().
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id, chunk_text FROM {$this->table} WHERE embedding_model IN ({$placeholders}) ORDER BY id LIMIT %d", $args ), ARRAY_A );

		return array_map( static fn( array $r ): array => array( 'id' => (int) $r['id'], 'chunk_text' => (string) $r['chunk_text'] ), (array) $rows );
	}

	/**
	 * Replace the vector (and its model id) of one stored chunk.
	 *
	 * @param array<int, float> $vector Embedding vector.
	 */
	public function update_vector( int $id, array $vector, string $embedding_model ): void {
		global $wpdb;

		$wpdb->update(
			$this->table,
			array(
				'vector'          => wp_json_encode( $vector ),
				'embedding_model' => $embedding_model,
			),
			array( 'id' => $id ),
			array( '%s', '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Delete all vectors.
	 */
	public function truncate(): void {
		global $wpdb;
		$wpdb->query( "TRUNCATE TABLE {$this->table}" );
	}
}
