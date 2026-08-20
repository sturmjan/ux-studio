<?php
/**
 * Contract every embedding provider adapter must implement.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\AiAssistant\Rag;

defined( 'ABSPATH' ) || exit;

interface EmbeddingProviderInterface {

	/** Provider id ('openai', 'fallback'). */
	public function get_id(): string;

	/** Embedding model name. */
	public function get_model_name(): string;

	/** Embedding vector dimensionality. */
	public function get_dimensions(): int;

	/**
	 * Embed a single text.
	 *
	 * @return array<int, float>
	 */
	public function embed( string $text ): array;

	/**
	 * Embed a batch of texts (more efficient than N single calls where the
	 * underlying API supports it).
	 *
	 * @param array<int, string> $texts Texts to embed.
	 * @return array<int, array<int, float>>
	 */
	public function embed_batch( array $texts ): array;

	/** Whether this provider is currently usable (has credentials, etc). */
	public function is_available(): bool;
}
