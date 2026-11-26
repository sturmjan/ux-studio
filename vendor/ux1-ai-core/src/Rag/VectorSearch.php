<?php
/**
 * Hybrid RAG search: cosine similarity over the stored vectors of the CURRENT embedding model plus the store's
 * fulltext search, merged and re-ranked. Pure logic over the VectorStore and EmbeddingProvider ports.
 *
 * @package Ux1\AiCore
 */

declare(strict_types=1);

namespace Ux1\AiCore\Rag;

final class VectorSearch {

	/** Minimum cosine similarity for a vector hit. */
	public const MIN_SIMILARITY = 0.1;

	/** Weight of the vector score in the hybrid merge. */
	public const VECTOR_WEIGHT = 0.7;

	/** Weight of the (normalised) fulltext score in the hybrid merge. */
	public const FULLTEXT_WEIGHT = 0.3;

	private const BATCH_SIZE = 200;

	public function __construct(
		private readonly VectorStore $store,
		private readonly EmbeddingProvider $embedding,
		private readonly string $target
	) {}

	/** Whether the target has vectors that this search can use (made by the current embedding model). */
	public function is_available(): bool {
		return $this->store->has_vectors( $this->target, $this->embedding->get_model_name() );
	}

	/**
	 * Embeds the query and runs a cosine search; falls back to fulltext when embedding or the vector scan fails.
	 *
	 * @return array<int, array{id:int,chunk_text:string,similarity:float,source_type:string,source_id:?int,metadata:?array<string,mixed>}>
	 */
	public function search( string $query, int $limit = 5, ?string $source_type = null ): array {
		if ( '' === trim( $query ) ) {
			return array();
		}

		try {
			$query_vector = $this->embedding->embed( $query );
			if ( empty( $query_vector ) ) {
				return array();
			}
			return $this->vector_search( $query_vector, $limit, $source_type );
		} catch ( \Throwable $e ) {
			return $this->store->fulltext_search( $this->target, $query, $limit, $source_type );
		}
	}

	/**
	 * Vector (weight 0.7) and fulltext (weight 0.3) results merged into one deduplicated, re-ranked list.
	 *
	 * @return array<int, array{id:int,chunk_text:string,score:float,source_type:string,source_id:?int,metadata:?array<string,mixed>,method:string}>
	 */
	public function hybrid_search( string $query, int $limit = 10, ?string $source_type = null ): array {
		if ( '' === trim( $query ) ) {
			return array();
		}

		$vector_results   = array();
		$fulltext_results = array();

		try {
			$query_vector = $this->embedding->embed( $query );
			if ( ! empty( $query_vector ) ) {
				// Twice the limit: identical chunks are dropped in merge_results(), which must still fill $limit.
				$vector_results = $this->vector_search( $query_vector, $limit * 2, $source_type );
			}
		} catch ( \Throwable $e ) {
			// Vector search unavailable this run: the fulltext part below still runs.
			unset( $e );
		}

		try {
			$fulltext_results = $this->store->fulltext_search( $this->target, $query, $limit * 2, $source_type );
		} catch ( \Throwable $e ) {
			// Fulltext unavailable: keep whatever the vector search found.
			unset( $e );
		}

		return self::merge_results( $vector_results, $fulltext_results, $limit );
	}

	/**
	 * @param array<int, float> $query_vector Query embedding.
	 * @return array<int, array{id:int,chunk_text:string,similarity:float,source_type:string,source_id:?int,metadata:?array<string,mixed>}>
	 */
	private function vector_search( array $query_vector, int $limit, ?string $source_type ): array {
		$results = array();

		foreach ( $this->store->candidate_batches( $this->target, $this->embedding->get_model_name(), $source_type, self::BATCH_SIZE ) as $rows ) {
			foreach ( $rows as $row ) {
				$stored = $row['vector'] ?? array();
				if ( ! is_array( $stored ) || empty( $stored ) ) {
					continue;
				}

				$similarity = self::cosine_similarity( $query_vector, $stored );
				if ( $similarity < self::MIN_SIMILARITY ) {
					continue;
				}

				$results[] = array(
					'id'          => (int) $row['id'],
					'chunk_text'  => $row['chunk_text'],
					'similarity'  => $similarity,
					'source_type' => $row['source_type'],
					'source_id'   => $row['source_id'] ?? null,
					'metadata'    => $row['metadata'] ?? null,
				);
			}
		}

		usort( $results, static fn( array $a, array $b ): int => $b['similarity'] <=> $a['similarity'] );

		return array_slice( $results, 0, $limit );
	}

	/**
	 * Cosine similarity of two equal-length vectors (0.0 on mismatch or a zero vector).
	 *
	 * @param array<int, float> $a First vector.
	 * @param array<int, float> $b Second vector.
	 */
	public static function cosine_similarity( array $a, array $b ): float {
		$len = count( $a );
		if ( $len !== count( $b ) || 0 === $len ) {
			return 0.0;
		}

		$a   = array_values( $a );
		$b   = array_values( $b );
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
	 * @param array<int, array{id:int,chunk_text:string,similarity:float,source_type:string,source_id:?int,metadata:?array<string,mixed>}> $vector_results   Vector hits.
	 * @param array<int, array{id:int,chunk_text:string,relevance:float,source_type:string,source_id:?int,metadata:?array<string,mixed>}>   $fulltext_results Fulltext hits.
	 * @return array<int, array{id:int,chunk_text:string,score:float,source_type:string,source_id:?int,metadata:?array<string,mixed>,method:string}>
	 */
	public static function merge_results( array $vector_results, array $fulltext_results, int $limit ): array {
		$merged        = array();
		$max_relevance = 0.0;
		foreach ( $fulltext_results as $r ) {
			$max_relevance = max( $max_relevance, (float) ( $r['relevance'] ?? 0 ) );
		}

		foreach ( $vector_results as $result ) {
			$id            = $result['id'];
			$merged[ $id ] = array(
				'id'          => $id,
				'chunk_text'  => $result['chunk_text'],
				'score'       => $result['similarity'] * self::VECTOR_WEIGHT,
				'source_type' => $result['source_type'],
				'source_id'   => $result['source_id'],
				'metadata'    => $result['metadata'],
				'method'      => 'vector',
			);
		}

		foreach ( $fulltext_results as $result ) {
			$id             = $result['id'];
			$normalized     = $max_relevance > 0 ? ( $result['relevance'] / $max_relevance ) : 0;
			$fulltext_score = $normalized * self::FULLTEXT_WEIGHT;

			if ( isset( $merged[ $id ] ) ) {
				$merged[ $id ]['score'] += $fulltext_score;
				$merged[ $id ]['method'] = 'hybrid';
			} else {
				$merged[ $id ] = array(
					'id'          => $id,
					'chunk_text'  => $result['chunk_text'],
					'score'       => $fulltext_score,
					'source_type' => $result['source_type'],
					'source_id'   => $result['source_id'],
					'metadata'    => $result['metadata'],
					'method'      => 'fulltext',
				);
			}
		}

		$results = array_values( $merged );
		usort( $results, static fn( array $a, array $b ): int => $b['score'] <=> $a['score'] );

		// Different rows can carry the same text (e.g. products sharing a description). A repeated chunk adds
		// nothing to the prompt and pushes other sources out, so only the best-scored copy is kept.
		$seen   = array();
		$unique = array();
		foreach ( $results as $result ) {
			$key = md5( (string) preg_replace( '/\s+/u', ' ', mb_strtolower( trim( (string) $result['chunk_text'] ) ) ) );
			if ( isset( $seen[ $key ] ) ) {
				continue;
			}
			$seen[ $key ] = true;
			$unique[]     = $result;
		}

		return array_slice( $unique, 0, $limit );
	}

	/**
	 * Renders search()/hybrid_search() results as a text block for the context section of an AI prompt.
	 *
	 * @param array<int, array<string, mixed>> $results Results from search()/hybrid_search().
	 */
	public static function format_for_context( array $results ): string {
		if ( empty( $results ) ) {
			return '';
		}

		$parts = array();
		foreach ( $results as $result ) {
			$score = round( ( $result['score'] ?? $result['similarity'] ?? 0 ) * 100 );

			$info = array();
			if ( ! empty( $result['metadata']['title'] ) ) {
				$info[] = $result['metadata']['title'];
			}
			if ( ! empty( $result['metadata']['url'] ) ) {
				$info[] = $result['metadata']['url'];
			}

			$header = "[Source: {$result['source_type']}";
			if ( ! empty( $info ) ) {
				$header .= ' - ' . implode( ' | ', $info );
			}
			$header .= " | Relevance: {$score}%]";

			$parts[] = $header . "\n" . trim( (string) $result['chunk_text'] );
		}

		return implode( "\n\n---\n\n", $parts );
	}
}
