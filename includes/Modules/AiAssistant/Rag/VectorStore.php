<?php
/**
 * Vector storage and cosine-similarity search over uxstudio_ai_assistant_vectors.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\AiAssistant\Rag;

defined( 'ABSPATH' ) || exit;

/**
 * Every vector belongs to one chat_target ('public' or 'internal'). The same
 * chunk may be stored for both targets - the embedding is computed once and
 * inserted once per target (see TrainingSourceManager), so duplicating a
 * source across both chats costs no extra embedding API calls. Search always
 * filters by chat_target so the public chat never sees internal-only vectors.
 */
final class VectorStore {

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
	 * Cosine-similarity nearest-neighbour search within one chat_target.
	 *
	 * @param array<int, float> $query_vector Query embedding.
	 * @return array<int, array{id:int,chunk_text:string,similarity:float,source_type:string,source_id:?int,metadata:?array<string,mixed>}>
	 */
	public function search( string $chat_target, array $query_vector, int $limit = 5, ?string $source_type = null ): array {
		global $wpdb;

		$chat_target = self::normalize_target( $chat_target );
		$where       = $wpdb->prepare( ' WHERE chat_target = %s', $chat_target );
		if ( null !== $source_type ) {
			$where .= $wpdb->prepare( ' AND source_type = %s', $source_type );
		}

		$batch_size = 200;
		$offset     = 0;
		$results    = array();

		while ( true ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $where built exclusively from $wpdb->prepare() fragments above.
			$rows = $wpdb->get_results(
				"SELECT id, source_type, source_id, chunk_text, vector, metadata FROM {$this->table}{$where} LIMIT {$batch_size} OFFSET {$offset}",
				ARRAY_A
			);

			if ( empty( $rows ) ) {
				break;
			}

			foreach ( $rows as $row ) {
				$stored_vector = json_decode( (string) $row['vector'], true );
				if ( ! is_array( $stored_vector ) || empty( $stored_vector ) ) {
					continue;
				}

				$similarity = $this->cosine_similarity( $query_vector, $stored_vector );
				if ( $similarity < 0.1 ) {
					continue;
				}

				$results[] = array(
					'id'          => (int) $row['id'],
					'chunk_text'  => $row['chunk_text'],
					'similarity'  => $similarity,
					'source_type' => $row['source_type'],
					'source_id'   => $row['source_id'] ? (int) $row['source_id'] : null,
					'metadata'    => $row['metadata'] ? json_decode( (string) $row['metadata'], true ) : null,
				);
			}

			$offset += $batch_size;
			if ( count( $rows ) < $batch_size ) {
				break;
			}
		}

		usort( $results, static fn( array $a, array $b ): int => $b['similarity'] <=> $a['similarity'] );

		return array_slice( $results, 0, $limit );
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
	 * Cosine similarity between two equal-length vectors (0.0 on mismatch).
	 *
	 * @param array<int, float> $a First vector.
	 * @param array<int, float> $b Second vector.
	 */
	public function cosine_similarity( array $a, array $b ): float {
		$len = count( $a );
		if ( $len !== count( $b ) || 0 === $len ) {
			return 0.0;
		}

		$dot = 0.0;
		$na  = 0.0;
		$nb  = 0.0;

		for ( $i = 0; $i < $len; $i++ ) {
			$dot += $a[ $i ] * $b[ $i ];
			$na  += $a[ $i ] * $a[ $i ];
			$nb  += $b[ $i ] * $b[ $i ];
		}

		$na = sqrt( $na );
		$nb = sqrt( $nb );

		if ( 0.0 === $na || 0.0 === $nb ) {
			return 0.0;
		}

		return $dot / ( $na * $nb );
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
	 * Whether any vectors exist for the given chat_target.
	 */
	public function has_vectors_for_target( string $chat_target ): bool {
		global $wpdb;

		$count = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$this->table} WHERE chat_target = %s", self::normalize_target( $chat_target ) )
		);

		return $count > 0;
	}

	/**
	 * Delete all vectors.
	 */
	public function truncate(): void {
		global $wpdb;
		$wpdb->query( "TRUNCATE TABLE {$this->table}" );
	}
}
