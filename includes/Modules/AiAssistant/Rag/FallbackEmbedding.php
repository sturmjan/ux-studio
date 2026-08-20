<?php
/**
 * TF-IDF pseudo-embedding provider, used when no OpenAI API key is configured.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\AiAssistant\Rag;

defined( 'ABSPATH' ) || exit;

/**
 * Never requires network access or credentials, so RAG search always keeps
 * working (with lower semantic quality than a real embedding model) even
 * when no embedding API key is configured.
 */
final class FallbackEmbedding implements EmbeddingProviderInterface {

	private const DIMENSIONS = 256;

	/** @var array<string, int> Document frequency per term, built per embed_batch() call. */
	private array $doc_frequency = array();

	private int $total_docs = 0;

	public function get_id(): string {
		return 'fallback';
	}

	public function get_model_name(): string {
		return 'tf-idf-' . self::DIMENSIONS;
	}

	public function get_dimensions(): int {
		return self::DIMENSIONS;
	}

	public function embed( string $text ): array {
		return $this->text_to_vector( $text );
	}

	public function embed_batch( array $texts ): array {
		$this->build_vocabulary( $texts );

		$embeddings = array();
		foreach ( $texts as $text ) {
			$embeddings[] = $this->text_to_vector( $text );
		}
		return $embeddings;
	}

	public function is_available(): bool {
		return true;
	}

	/**
	 * Convert text to a fixed-dimension, L2-normalized hash-bucketed TF-IDF vector.
	 *
	 * @return array<int, float>
	 */
	private function text_to_vector( string $text ): array {
		$tokens = $this->tokenize( $text );

		if ( empty( $tokens ) ) {
			return array_fill( 0, self::DIMENSIONS, 0.0 );
		}

		$tf     = array_count_values( $tokens );
		$max_tf = max( $tf );

		$vector = array_fill( 0, self::DIMENSIONS, 0.0 );

		foreach ( $tf as $term => $count ) {
			$normalized_tf = 0.5 + 0.5 * ( $count / $max_tf );

			$idf = 1.0;
			if ( $this->total_docs > 0 && isset( $this->doc_frequency[ $term ] ) ) {
				$idf = log( 1 + $this->total_docs / ( 1 + $this->doc_frequency[ $term ] ) );
			}

			$tfidf = $normalized_tf * $idf;

			$hash  = crc32( $term );
			$index = abs( $hash ) % self::DIMENSIONS;
			$sign  = $hash > 0 ? 1 : -1;

			$vector[ $index ] += $sign * $tfidf;
		}

		$norm = 0.0;
		foreach ( $vector as $v ) {
			$norm += $v * $v;
		}
		$norm = sqrt( $norm );

		if ( $norm > 0 ) {
			for ( $i = 0; $i < self::DIMENSIONS; $i++ ) {
				$vector[ $i ] /= $norm;
			}
		}

		return $vector;
	}

	/**
	 * Build the document-frequency table used for IDF weighting.
	 *
	 * @param array<int, string> $texts Batch of texts.
	 */
	private function build_vocabulary( array $texts ): void {
		$this->doc_frequency = array();
		$this->total_docs    = count( $texts );

		foreach ( $texts as $text ) {
			foreach ( array_unique( $this->tokenize( $text ) ) as $token ) {
				$this->doc_frequency[ $token ] = ( $this->doc_frequency[ $token ] ?? 0 ) + 1;
			}
		}
	}

	/**
	 * Lowercase, strip HTML/stop-words, split into word tokens (Czech + English).
	 *
	 * @return array<int, string>
	 */
	private function tokenize( string $text ): array {
		$text = mb_strtolower( $text, 'UTF-8' );
		$text = wp_strip_all_tags( $text );
		$text = (string) preg_replace( '/\s+/', ' ', $text );

		preg_match_all( '/[\p{L}\p{N}]{2,}/u', $text, $matches );
		$tokens = $matches[0] ?? array();

		$stop_words = array(
			'a', 'i', 'o', 'v', 'k', 'z', 's', 'na', 'je', 'se', 'to', 'do', 'za', 'pro', 'ale', 'jak', 'tak',
			'aby', 'ten', 'jeho', 'jsou', 'být', 'nebo', 'při', 'od', 'po', 'pod', 'nad', 'mezi', 'jako', 'když', 'než',
			'the', 'is', 'at', 'of', 'on', 'and', 'or', 'in', 'to', 'for', 'it', 'an', 'be', 'as', 'by', 'this', 'that', 'with', 'from', 'not', 'are',
		);

		return array_values( array_filter( $tokens, static fn( string $t ): bool => ! in_array( $t, $stop_words, true ) ) );
	}
}
