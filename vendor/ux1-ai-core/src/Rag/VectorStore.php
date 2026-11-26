<?php
/**
 * Storage port of the RAG search. The host (WordPress $wpdb, CA PDO, ...) implements it; the core never touches a
 * database. A "target" is an opaque partition id of the host (UX1 Studio: chat_target 'public'/'internal').
 *
 * @package Ux1\AiCore
 */

declare(strict_types=1);

namespace Ux1\AiCore\Rag;

interface VectorStore {

	/**
	 * Iterates the candidate vectors of one target, in batches, restricted to vectors made by ONE embedding model
	 * (vectors of different models are not comparable).
	 *
	 * @param string      $target          Partition id.
	 * @param string      $embedding_model Only vectors stored with this model id.
	 * @param string|null $source_type     Optional source type filter.
	 * @param int         $batch_size      Rows per batch the implementation should read at a time.
	 * @return iterable<int, array<int, array{id:int,chunk_text:string,vector:array<int,float>,source_type:string,source_id:?int,metadata:?array<string,mixed>}>> Batches of rows.
	 */
	public function candidate_batches( string $target, string $embedding_model, ?string $source_type, int $batch_size ): iterable;

	/**
	 * Fulltext search of the chunk text (any embedding model).
	 *
	 * @return array<int, array{id:int,chunk_text:string,relevance:float,source_type:string,source_id:?int,metadata:?array<string,mixed>}>
	 */
	public function fulltext_search( string $target, string $query, int $limit, ?string $source_type ): array;

	/** Whether the target has any vector made by the given model. */
	public function has_vectors( string $target, string $embedding_model ): bool;
}
