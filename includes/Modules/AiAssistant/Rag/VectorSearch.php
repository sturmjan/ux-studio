<?php
/**
 * Hybrid RAG search: vector cosine similarity + FULLTEXT, merged and re-ranked.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\AiAssistant\Rag;

defined( 'ABSPATH' ) || exit;

final class VectorSearch {

	private VectorStore $store;
	private EmbeddingProviderInterface $embedding;
	private string $chat_target;

	public function __construct( string $chat_target = VectorStore::TARGET_PUBLIC ) {
		$this->store       = new VectorStore();
		$this->embedding   = EmbeddingFactory::create();
		$this->chat_target = VectorStore::normalize_target( $chat_target );
	}

	/**
	 * Whether vector search is functional, optionally for a specific chat_target.
	 */
	public static function is_available( ?string $chat_target = null ): bool {
		if ( ! EmbeddingFactory::is_vector_search_available() ) {
			return false;
		}
		return null === $chat_target || ( new VectorStore() )->has_vectors_for_target( $chat_target );
	}

	/**
	 * Embed the query and run a cosine-similarity search; falls back to
	 * FULLTEXT if embedding fails for any reason.
	 *
	 * @return array<int, array{chunk_text:string,similarity:float,source_type:string,source_id:?int,metadata:?array<string,mixed>}>
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
			return $this->store->search( $this->chat_target, $query_vector, $limit, $source_type );
		} catch ( \Throwable $e ) {
			return $this->store->fulltext_search( $this->chat_target, $query, $limit, $source_type );
		}
	}

	/**
	 * Combine vector (weight 0.7) and FULLTEXT (weight 0.3) results into one
	 * deduplicated, re-ranked list.
	 *
	 * @return array<int, array{chunk_text:string,score:float,source_type:string,source_id:?int,metadata:?array<string,mixed>,method:string}>
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
				$vector_results = $this->store->search( $this->chat_target, $query_vector, $limit, $source_type );
			}
		} catch ( \Throwable $e ) {
			// Vector search unavailable this run - fulltext below still runs.
		}

		try {
			$fulltext_results = $this->store->fulltext_search( $this->chat_target, $query, $limit, $source_type );
		} catch ( \Throwable $e ) {
			// FULLTEXT unavailable (e.g. table missing) - fall through with whatever vector results we have.
		}

		return $this->merge_results( $vector_results, $fulltext_results, $limit );
	}

	/**
	 * @param array<int, array{id:int,chunk_text:string,similarity:float,source_type:string,source_id:?int,metadata:?array<string,mixed>}> $vector_results   Vector hits.
	 * @param array<int, array{id:int,chunk_text:string,relevance:float,source_type:string,source_id:?int,metadata:?array<string,mixed>}>   $fulltext_results FULLTEXT hits.
	 * @return array<int, array{chunk_text:string,score:float,source_type:string,source_id:?int,metadata:?array<string,mixed>,method:string}>
	 */
	private function merge_results( array $vector_results, array $fulltext_results, int $limit ): array {
		$merged      = array();
		$max_relevance = 0.0;
		foreach ( $fulltext_results as $r ) {
			$max_relevance = max( $max_relevance, (float) ( $r['relevance'] ?? 0 ) );
		}

		foreach ( $vector_results as $result ) {
			$id            = $result['id'];
			$merged[ $id ] = array(
				'id'          => $id,
				'chunk_text'  => $result['chunk_text'],
				'score'       => $result['similarity'] * 0.7,
				'source_type' => $result['source_type'],
				'source_id'   => $result['source_id'],
				'metadata'    => $result['metadata'],
				'method'      => 'vector',
			);
		}

		foreach ( $fulltext_results as $result ) {
			$id              = $result['id'];
			$normalized      = $max_relevance > 0 ? ( $result['relevance'] / $max_relevance ) : 0;
			$fulltext_score  = $normalized * 0.3;

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

		return array_slice( $results, 0, $limit );
	}

	/**
	 * Render hybrid_search()/search() results as a text block suitable for
	 * inclusion in an AI prompt's context section.
	 *
	 * @param array<int, array<string, mixed>> $results Results from search()/hybrid_search().
	 */
	public function format_for_context( array $results ): string {
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

			$parts[] = $header . "\n" . trim( $result['chunk_text'] );
		}

		return implode( "\n\n---\n\n", $parts );
	}
}
