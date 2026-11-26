<?php
/**
 * Contract of an embedding provider (text -> fixed-size vector).
 *
 * @package Ux1\AiCore
 */

declare(strict_types=1);

namespace Ux1\AiCore\Rag;

interface EmbeddingProvider {

	/** Provider id ('openai', 'fallback'). */
	public function get_id(): string;

	/**
	 * Embedding model id. Hosts store it next to every vector and search only vectors of the CURRENT model, so a
	 * model must change its id whenever its vectors stop being comparable with the old ones.
	 */
	public function get_model_name(): string;

	/** Vector dimensionality. */
	public function get_dimensions(): int;

	/**
	 * Embeds one text. For the same text the vector equals the one embed_batch() returns for it.
	 *
	 * @return array<int, float>
	 * @throws \RuntimeException When the provider fails.
	 */
	public function embed( string $text ): array;

	/**
	 * Embeds many texts (one vector per text, same order).
	 *
	 * @param array<int, string> $texts Texts.
	 * @return array<int, array<int, float>>
	 * @throws \RuntimeException When the provider fails.
	 */
	public function embed_batch( array $texts ): array;

	/** Whether the provider is usable right now (has credentials etc.). */
	public function is_available(): bool;
}
