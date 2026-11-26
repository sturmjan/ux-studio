<?php
/**
 * Splits text into chunks for the RAG pipeline: paragraph-aware, then sentence-aware, then a hard split at word
 * boundaries, with an optional overlap tail copied from the previous chunk.
 *
 * One implementation, several parameter sets (UX1 Studio: 800/200 for training sources, 1500/0 for documents).
 *
 * @package Ux1\AiCore
 */

declare(strict_types=1);

namespace Ux1\AiCore\Rag;

final class ChunkingEngine {

	private int $max_chunk_size;
	private int $overlap;

	/**
	 * @param int  $max_chunk_size Maximum chunk length in characters.
	 * @param int  $overlap        Characters of the previous segment prepended to the next chunk (capped at half the size).
	 * @param bool $strip_html     Strip tags and decode entities first (false for text that is already plain).
	 */
	public function __construct( int $max_chunk_size = 800, int $overlap = 200, private readonly bool $strip_html = true ) {
		$this->max_chunk_size = max( 1, $max_chunk_size );
		$this->overlap        = max( 0, min( $overlap, intdiv( $this->max_chunk_size, 2 ) ) );
	}

	/**
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

		return $this->build_chunks_with_overlap( $this->normalize_segments( $this->split_paragraphs( $text ) ) );
	}

	/**
	 * Chunks a batch of texts, tagging each chunk with the key of its source text.
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

	private function clean_text( string $text ): string {
		if ( $this->strip_html ) {
			$text = TextCleaner::strip_tags( $text );
			$text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		}
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
	 * Merges undersized paragraphs, splits oversized ones by sentence.
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
	 * Splits text into sentences (Czech/English punctuation aware).
	 *
	 * @return array<int, string>
	 */
	private function split_sentences( string $text ): array {
		$sentences = preg_split( '/(?<=[.!?])\s+(?=[\p{Lu}\p{N}])/u', $text, -1, PREG_SPLIT_NO_EMPTY );
		return array_values( array_filter( array_map( 'trim', (array) $sentences ), static fn( string $s ): bool => '' !== $s ) );
	}

	/**
	 * Hard-splits oversized text at word boundaries as a last resort.
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
	 * Builds the final chunks, prepending an overlap tail from the previous segment.
	 *
	 * @param array<int, string> $segments Segments.
	 * @return array<int, array{text:string,index:int,char_count:int}>
	 */
	private function build_chunks_with_overlap( array $segments ): array {
		$chunks    = array();
		$index     = 0;
		$prev_tail = '';

		foreach ( $segments as $segment ) {
			$text = '' !== $prev_tail ? $prev_tail . ' ' . $segment : $segment;

			if ( mb_strlen( $text ) > $this->max_chunk_size ) {
				$text       = mb_substr( $text, 0, $this->max_chunk_size );
				$last_space = mb_strrpos( $text, ' ' );
				if ( false !== $last_space && $last_space > $this->max_chunk_size * 0.7 ) {
					$text = mb_substr( $text, 0, $last_space );
				}
			}

			$text     = trim( $text );
			$chunks[] = array( 'text' => $text, 'index' => $index, 'char_count' => mb_strlen( $text ) );

			$seg_len   = mb_strlen( $segment );
			$prev_tail = ( $this->overlap > 0 && $seg_len > $this->overlap ) ? mb_substr( $segment, $seg_len - $this->overlap ) : ( $this->overlap > 0 ? $segment : '' );

			++$index;
		}

		return $chunks;
	}
}
