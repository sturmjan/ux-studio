<?php
/**
 * Local, dependency-free pseudo-embedding: hash-bucketed term-frequency vector (256 dimensions, L2-normalised).
 * Used when no embedding API key is configured, so RAG search keeps working with lower semantic quality.
 *
 * The scheme is deterministic and independent of any batch: embed( $t ) === embed_batch( [ ..., $t, ... ] )[ n ].
 * The first version weighted terms by IDF computed from the batch being indexed while queries used IDF = 1, so
 * query and document vectors of the same text differed (PLAN 45.5). That version used the model id LEGACY_MODEL;
 * this one has MODEL, so hosts never mix the two (see LEGACY_MODELS).
 *
 * @package Ux1\AiCore
 */

declare(strict_types=1);

namespace Ux1\AiCore\Rag;

final class FallbackEmbedding implements EmbeddingProvider {

	public const DIMENSIONS = 256;

	/** Current model id. */
	public const MODEL = 'tf-idf-256-v2';

	/** Ids of earlier, inconsistent versions: their stored vectors must be re-embedded, never searched with MODEL. */
	public const LEGACY_MODELS = array( 'tf-idf-256' );

	private const STOP_WORDS = array(
		'a', 'i', 'o', 'v', 'k', 'z', 's', 'na', 'je', 'se', 'to', 'do', 'za', 'pro', 'ale', 'jak', 'tak',
		'aby', 'ten', 'jeho', 'jsou', 'být', 'nebo', 'při', 'od', 'po', 'pod', 'nad', 'mezi', 'jako', 'když', 'než',
		'the', 'is', 'at', 'of', 'on', 'and', 'or', 'in', 'to', 'for', 'it', 'an', 'be', 'as', 'by', 'this', 'that', 'with', 'from', 'not', 'are',
	);

	public function get_id(): string {
		return 'fallback';
	}

	public function get_model_name(): string {
		return self::MODEL;
	}

	public function get_dimensions(): int {
		return self::DIMENSIONS;
	}

	public function embed( string $text ): array {
		return $this->text_to_vector( $text );
	}

	public function embed_batch( array $texts ): array {
		$embeddings = array();
		foreach ( $texts as $text ) {
			$embeddings[] = $this->text_to_vector( (string) $text );
		}
		return $embeddings;
	}

	public function is_available(): bool {
		return true;
	}

	/**
	 * @return array<int, float>
	 */
	private function text_to_vector( string $text ): array {
		$tokens = $this->tokenize( $text );
		$vector = array_fill( 0, self::DIMENSIONS, 0.0 );
		if ( empty( $tokens ) ) {
			return $vector;
		}

		$tf     = array_count_values( $tokens );
		$max_tf = max( $tf );

		foreach ( $tf as $term => $count ) {
			$weight = 0.5 + 0.5 * ( $count / $max_tf );
			$hash   = crc32( (string) $term );
			$index  = abs( $hash ) % self::DIMENSIONS;
			$sign   = $hash > 0 ? 1 : -1;

			$vector[ $index ] += $sign * $weight;
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
	 * Lowercase, strip HTML and stop-words, split into word tokens (Czech + English).
	 *
	 * @return array<int, string>
	 */
	private function tokenize( string $text ): array {
		$text = TextCleaner::strip_tags( mb_strtolower( $text, 'UTF-8' ) );
		$text = (string) preg_replace( '/\s+/', ' ', $text );

		preg_match_all( '/[\p{L}\p{N}]{2,}/u', $text, $matches );
		$tokens = $matches[0] ?? array();

		return array_values( array_filter( $tokens, static fn( string $t ): bool => ! in_array( $t, self::STOP_WORDS, true ) ) );
	}
}
