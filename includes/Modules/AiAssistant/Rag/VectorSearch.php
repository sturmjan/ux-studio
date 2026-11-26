<?php
/**
 * Hybrid RAG search for one chat_target: thin adapter over the shared core (ux1-ai-core).
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\AiAssistant\Rag;

use Ux1\AiCore\Rag\VectorSearch as CoreVectorSearch;

defined( 'ABSPATH' ) || exit;

/**
 * Scoring (cosine, 0.1 threshold, 0.7/0.3 hybrid merge) and prompt formatting live in the core; this class wires
 * it to the $wpdb VectorStore and the best available embedding provider. Only vectors of the CURRENT embedding
 * model are searched (PLAN.md 45.5), so vectors of another model never mix into the results.
 */
final class VectorSearch {

	private CoreVectorSearch $search;

	public function __construct( string $chat_target = VectorStore::TARGET_PUBLIC ) {
		$this->search = new CoreVectorSearch( new VectorStore(), EmbeddingFactory::create(), VectorStore::normalize_target( $chat_target ) );
	}

	/**
	 * Whether vector search is functional, optionally for a specific chat_target: vectors made by the current
	 * embedding model must exist (vectors of another model are ignored by the search).
	 */
	public static function is_available( ?string $chat_target = null ): bool {
		if ( ! EmbeddingFactory::is_vector_search_available() ) {
			return false;
		}

		$model   = EmbeddingFactory::create()->get_model_name();
		$store   = new VectorStore();
		$targets = null === $chat_target ? VectorStore::TARGETS : array( $chat_target );
		foreach ( $targets as $target ) {
			if ( $store->has_vectors( $target, $model ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Embed the query and run a cosine-similarity search; falls back to FULLTEXT if embedding fails.
	 *
	 * @return array<int, array{chunk_text:string,similarity:float,source_type:string,source_id:?int,metadata:?array<string,mixed>}>
	 */
	public function search( string $query, int $limit = 5, ?string $source_type = null ): array {
		return $this->search->search( $query, $limit, $source_type );
	}

	/**
	 * Combine vector (weight 0.7) and FULLTEXT (weight 0.3) results into one deduplicated, re-ranked list.
	 *
	 * @return array<int, array{chunk_text:string,score:float,source_type:string,source_id:?int,metadata:?array<string,mixed>,method:string}>
	 */
	public function hybrid_search( string $query, int $limit = 10, ?string $source_type = null ): array {
		return $this->search->hybrid_search( $query, $limit, $source_type );
	}

	/**
	 * Render hybrid_search()/search() results as a text block for the context section of an AI prompt.
	 *
	 * @param array<int, array<string, mixed>> $results Results from search()/hybrid_search().
	 */
	public static function format_for_context( array $results ): string {
		return CoreVectorSearch::format_for_context( $results );
	}
}
