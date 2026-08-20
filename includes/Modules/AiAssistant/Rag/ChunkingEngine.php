<?php
/**
 * Splits text into overlapping chunks for the RAG pipeline.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\AiAssistant\Rag;

defined( 'ABSPATH' ) || exit;

final class ChunkingEngine {

	private int $max_chunk_size;
	private int $overlap;

	public function __construct( int $max_chunk_size = 800, int $overlap = 200 ) {
		$this->max_chunk_size = $max_chunk_size;
		$this->overlap        = min( $overlap, (int) ( $max_chunk_size / 2 ) );
	}

	/**
	 * Split text into chunks with overlap, paragraph/sentence-aware.
	 *
	 * @return array<int, array{text:string,index:int,char_count:int}>
	 */
	public function chunk( string $text ): array {
		$text = $this->clean_text( $text );
		if ( '' === $text ) {
			return array();
		}

		if ( mb_strlen( $text ) <= $this->max_chunk_size ) {
			return array( array( 'text' => $text, 'index' => 0, 'char_count' => mb_strlen( $text ) ) );
		}

		$segments = $this->normalize_segments( $this->split_paragraphs( $text ) );

		return $this->build_chunks_with_overlap( $segments );
	}

	/**
	 * Chunk a batch of texts, tagging each chunk with its originating index.
	 *
	 * @param array<int, string> $texts Texts.
	 * @return array<int, array{text:string,index:int,char_count:int,source_index:int}>
	 */
	public function chunk_batch( array $texts ): array {
		$all_chunks = array();

		foreach ( $texts as $source_index => $text ) {
			foreach ( $this->chunk( $text ) as $chunk ) {
				$chunk['source_index'] = $source_index;
				$all_chunks[]          = $chunk;
			}
		}

		return $all_chunks;
	}

	/**
	 * Strip HTML, decode entities, and normalize whitespace (preserving paragraphs).
	 */
	private function clean_text( string $text ): string {
		$text = wp_strip_all_tags( $text );
		$text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$text = (string) preg_replace( '/[^\S\n]+/', ' ', $text );
		$text = (string) preg_replace( '/\n{3,}/', "\n\n", $text );

		return trim( $text );
	}

	/**
	 * @return array<int, string>
	 */
	private function split_paragraphs( string $text ): array {
		$parts = preg_split( '/\n\s*\n/', $text );
		return array_values( array_filter( array_map( 'trim', (array) $parts ), static fn( string $p ): bool => '' !== $p ) );
	}

	/**
	 * Merge undersized paragraphs, split oversized ones by sentence.
	 *
	 * @param array<int, string> $paragraphs Paragraphs.
	 * @return array<int, string>
	 */
	private function normalize_segments( array $paragraphs ): array {
		$segments = array();
		$buffer   = '';

		foreach ( $paragraphs as $paragraph ) {
			if ( mb_strlen( $paragraph ) > $this->max_chunk_size ) {
				if ( '' !== $buffer ) {
					$segments[] = trim( $buffer );
					$buffer     = '';
				}

				$sentence_buffer = '';
				foreach ( $this->split_sentences( $paragraph ) as $sentence ) {
					if ( mb_strlen( $sentence_buffer . ' ' . $sentence ) > $this->max_chunk_size ) {
						if ( '' !== $sentence_buffer ) {
							$segments[] = trim( $sentence_buffer );
						}
						if ( mb_strlen( $sentence ) > $this->max_chunk_size ) {
							foreach ( $this->hard_split( $sentence ) as $part ) {
								$segments[] = $part;
							}
							$sentence_buffer = '';
						} else {
							$sentence_buffer = $sentence;
						}
					} else {
						$sentence_buffer = '' === $sentence_buffer ? $sentence : $sentence_buffer . ' ' . $sentence;
					}
				}
				if ( '' !== $sentence_buffer ) {
					$segments[] = trim( $sentence_buffer );
				}
				continue;
			}

			if ( '' !== $buffer && mb_strlen( $buffer . "\n\n" . $paragraph ) > $this->max_chunk_size ) {
				$segments[] = trim( $buffer );
				$buffer     = $paragraph;
			} else {
				$buffer = '' === $buffer ? $paragraph : $buffer . "\n\n" . $paragraph;
			}
		}

		if ( '' !== $buffer ) {
			$segments[] = trim( $buffer );
		}

		return $segments;
	}

	/**
	 * Split text into sentences (Czech/English punctuation aware).
	 *
	 * @return array<int, string>
	 */
	private function split_sentences( string $text ): array {
		$sentences = preg_split( '/(?<=[.!?])\s+(?=[\p{Lu}\p{N}])/u', $text, -1, PREG_SPLIT_NO_EMPTY );
		return array_values( array_filter( array_map( 'trim', (array) $sentences ), static fn( string $s ): bool => '' !== $s ) );
	}

	/**
	 * Hard-split oversized text at word boundaries as a last resort.
	 *
	 * @return array<int, string>
	 */
	private function hard_split( string $text ): array {
		$parts  = array();
		$length = mb_strlen( $text );
		$pos    = 0;

		while ( $pos < $length ) {
			$chunk = mb_substr( $text, $pos, $this->max_chunk_size );

			if ( $pos + $this->max_chunk_size < $length ) {
				$last_space = mb_strrpos( $chunk, ' ' );
				if ( false !== $last_space && $last_space > $this->max_chunk_size * 0.5 ) {
					$chunk = mb_substr( $chunk, 0, $last_space );
				}
			}

			$parts[] = trim( $chunk );
			$pos    += mb_strlen( $chunk );
		}

		return array_values( array_filter( $parts ) );
	}

	/**
	 * Build final chunks, prepending an overlap tail from the previous segment.
	 *
	 * @param array<int, string> $segments Segments.
	 * @return array<int, array{text:string,index:int,char_count:int}>
	 */
	private function build_chunks_with_overlap( array $segments ): array {
		if ( empty( $segments ) ) {
			return array();
		}

		$chunks    = array();
		$index     = 0;
		$prev_tail = '';

		foreach ( $segments as $segment ) {
			$text = $segment;

			if ( '' !== $prev_tail ) {
				$text = $prev_tail . ' ' . $text;
			}

			if ( mb_strlen( $text ) > $this->max_chunk_size ) {
				$text       = mb_substr( $text, 0, $this->max_chunk_size );
				$last_space = mb_strrpos( $text, ' ' );
				if ( false !== $last_space && $last_space > $this->max_chunk_size * 0.7 ) {
					$text = mb_substr( $text, 0, $last_space );
				}
			}

			$chunks[] = array( 'text' => trim( $text ), 'index' => $index, 'char_count' => mb_strlen( trim( $text ) ) );

			$seg_len   = mb_strlen( $segment );
			$prev_tail = $seg_len > $this->overlap ? mb_substr( $segment, $seg_len - $this->overlap ) : $segment;

			++$index;
		}

		return $chunks;
	}
}
