<?php
/**
 * Extracts plain text from uploaded knowledge base documents and splits it
 * into chunks suitable for storage/search.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\AiAssistant;

defined( 'ABSPATH' ) || exit;

/**
 * Ported from the legacy ai-assistant module's DocumentParser. Supports
 * PDF (via the pdftotext CLI when available, else a best-effort regex-based
 * fallback), DOCX (via ZipArchive), and plain TXT/MD.
 */
final class DocumentParser {

	/**
	 * Extract plain text from a file based on its type.
	 *
	 * @param string $file_path Absolute path to the file.
	 * @param string $file_type Extension: pdf, docx, txt, md.
	 *
	 * @throws \RuntimeException When extraction fails.
	 */
	public static function parse( string $file_path, string $file_type ): string {
		if ( ! file_exists( $file_path ) || ! is_readable( $file_path ) ) {
			throw new \RuntimeException( __( 'The file does not exist or is not readable.', 'ux-studio' ) );
		}

		switch ( strtolower( $file_type ) ) {
			case 'docx':
				return self::parse_docx( $file_path );
			case 'pdf':
				return self::parse_pdf( $file_path );
			case 'txt':
			case 'md':
				return self::parse_plain_text( $file_path );
			default:
				throw new \RuntimeException( __( 'Unsupported file type.', 'ux-studio' ) );
		}
	}

	/**
	 * Split text into knowledge-base-sized chunks (paragraph-aware, falls
	 * back to sentence splitting for oversized paragraphs).
	 *
	 * @return array<int, string>
	 */
	public static function chunk( string $text, int $max_chunk_size = 1500 ): array {
		$text = trim( $text );
		if ( '' === $text ) {
			return array();
		}

		$paragraphs    = preg_split( '/\n\s*\n/', $text );
		$chunks        = array();
		$current_chunk = '';

		foreach ( (array) $paragraphs as $paragraph ) {
			$paragraph = trim( (string) $paragraph );
			if ( '' === $paragraph ) {
				continue;
			}

			if ( mb_strlen( $paragraph ) > $max_chunk_size ) {
				if ( '' !== $current_chunk ) {
					$chunks[]      = $current_chunk;
					$current_chunk = '';
				}

				$sentence_buffer = '';
				foreach ( self::split_into_sentences( $paragraph ) as $sentence ) {
					$sentence = trim( $sentence );
					if ( '' === $sentence ) {
						continue;
					}
					if ( '' === $sentence_buffer ) {
						$sentence_buffer = $sentence;
					} elseif ( mb_strlen( $sentence_buffer . ' ' . $sentence ) <= $max_chunk_size ) {
						$sentence_buffer .= ' ' . $sentence;
					} else {
						$chunks[]        = $sentence_buffer;
						$sentence_buffer = $sentence;
					}
				}
				if ( '' !== $sentence_buffer ) {
					$chunks[] = $sentence_buffer;
				}
				continue;
			}

			if ( '' === $current_chunk ) {
				$current_chunk = $paragraph;
			} elseif ( mb_strlen( $current_chunk . "\n\n" . $paragraph ) <= $max_chunk_size ) {
				$current_chunk .= "\n\n" . $paragraph;
			} else {
				$chunks[]      = $current_chunk;
				$current_chunk = $paragraph;
			}
		}

		if ( '' !== $current_chunk ) {
			$chunks[] = $current_chunk;
		}

		return array_values(
			array_filter(
				array_map( 'trim', $chunks ),
				static fn( string $c ): bool => '' !== $c
			)
		);
	}

	/**
	 * Parse a DOCX file using ZipArchive.
	 */
	private static function parse_docx( string $file_path ): string {
		if ( ! class_exists( 'ZipArchive' ) ) {
			throw new \RuntimeException( __( 'ZipArchive is unavailable. Install the php-zip extension.', 'ux-studio' ) );
		}

		$zip    = new \ZipArchive();
		$opened = $zip->open( $file_path );
		if ( true !== $opened ) {
			throw new \RuntimeException( __( 'Could not open the DOCX file.', 'ux-studio' ) );
		}

		$xml = $zip->getFromName( 'word/document.xml' );
		$zip->close();

		if ( false === $xml ) {
			throw new \RuntimeException( __( 'The DOCX file does not contain word/document.xml.', 'ux-studio' ) );
		}

		$text = (string) preg_replace( '/<\/w:p>/', "\n\n", $xml );
		$text = (string) preg_replace( '/<w:br[^>]*\/>/', "\n", $text );
		$text = (string) preg_replace( '/<w:tab\/>/', "\t", $text );
		$text = wp_strip_all_tags( $text );
		$text = html_entity_decode( $text, ENT_QUOTES | ENT_XML1, 'UTF-8' );
		$text = (string) preg_replace( '/\n{3,}/', "\n\n", $text );

		return trim( $text );
	}

	/**
	 * Parse a PDF file - pdftotext (poppler-utils) first, then a best-effort
	 * regex fallback for environments without it.
	 */
	private static function parse_pdf( string $file_path ): string {
		$text = self::parse_pdf_with_pdftotext( $file_path );
		if ( null !== $text ) {
			return $text;
		}

		$text = self::parse_pdf_basic( $file_path );
		if ( null !== $text ) {
			return $text;
		}

		throw new \RuntimeException( __( 'Could not extract text from the PDF. Install pdftotext (poppler-utils) for reliable extraction.', 'ux-studio' ) );
	}

	/**
	 * Try the pdftotext CLI, if available.
	 */
	private static function parse_pdf_with_pdftotext( string $file_path ): ?string {
		if ( ! function_exists( 'shell_exec' ) ) {
			return null;
		}

		$check = @shell_exec( 'pdftotext -v 2>&1' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions.shell_exec_shell_exec
		if ( null === $check || false === stripos( (string) $check, 'pdftotext' ) ) {
			return null;
		}

		$escaped = escapeshellarg( $file_path );
		$output  = @shell_exec( 'pdftotext ' . $escaped . ' - 2>/dev/null' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions.shell_exec_shell_exec

		if ( null === $output || '' === trim( (string) $output ) ) {
			return null;
		}

		return trim( (string) $output );
	}

	/**
	 * Best-effort PDF text extraction without external tools: decompresses
	 * FlateDecode streams and reads text between BT/ET markers.
	 */
	private static function parse_pdf_basic( string $file_path ): ?string {
		$content = file_get_contents( $file_path );
		if ( false === $content ) {
			return null;
		}

		$text = self::extract_pdf_text( self::decompress_pdf_streams( $content ) );
		if ( '' === trim( $text ) ) {
			$text = self::extract_pdf_text( $content );
		}

		return '' === trim( $text ) ? null : trim( $text );
	}

	/**
	 * Decompress FlateDecode streams within a raw PDF byte string.
	 */
	private static function decompress_pdf_streams( string $content ): string {
		$result = $content;

		if ( preg_match_all( '/stream\s*\n(.+?)\nendstream/s', $content, $matches ) ) {
			foreach ( $matches[1] as $stream ) {
				$decoded = @gzuncompress( $stream ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
				if ( false === $decoded ) {
					$decoded = @gzinflate( $stream ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
				}
				if ( false !== $decoded ) {
					$result .= "\n" . $decoded;
				}
			}
		}

		return $result;
	}

	/**
	 * Extract text between BT/ET operators (Tj / TJ text-showing operators).
	 */
	private static function extract_pdf_text( string $content ): string {
		$texts = array();

		if ( preg_match_all( '/BT\s*(.+?)\s*ET/s', $content, $bt_matches ) ) {
			foreach ( $bt_matches[1] as $block ) {
				if ( preg_match_all( '/\((.+?)\)\s*Tj/s', $block, $tj_matches ) ) {
					foreach ( $tj_matches[1] as $text ) {
						$texts[] = self::decode_pdf_string( $text );
					}
				}
				if ( preg_match_all( '/\[(.+?)\]\s*TJ/s', $block, $tj_array_matches ) ) {
					foreach ( $tj_array_matches[1] as $array_content ) {
						if ( preg_match_all( '/\((.+?)\)/', $array_content, $inner_matches ) ) {
							foreach ( $inner_matches[1] as $text ) {
								$texts[] = self::decode_pdf_string( $text );
							}
						}
					}
				}
			}
		}

		return implode( ' ', $texts );
	}

	/**
	 * Decode basic PDF string escape sequences.
	 */
	private static function decode_pdf_string( string $str ): string {
		$replacements = array(
			'\\n'  => "\n",
			'\\r'  => "\r",
			'\\t'  => "\t",
			'\\\\' => '\\',
			'\\('  => '(',
			'\\)'  => ')',
		);

		$str = str_replace( array_keys( $replacements ), array_values( $replacements ), $str );

		return (string) preg_replace_callback(
			'/\\\\([0-7]{1,3})/',
			static fn( array $m ): string => chr( (int) octdec( $m[1] ) ),
			$str
		);
	}

	/**
	 * Read a plain text/markdown file.
	 */
	private static function parse_plain_text( string $file_path ): string {
		$content = file_get_contents( $file_path );
		if ( false === $content ) {
			throw new \RuntimeException( __( 'Could not read the file.', 'ux-studio' ) );
		}

		return trim( $content );
	}

	/**
	 * Split text into sentences on . ! ? boundaries.
	 *
	 * @return array<int, string>
	 */
	private static function split_into_sentences( string $text ): array {
		$sentences = preg_split( '/(?<=[.!?])\s+/', $text );
		return false === $sentences ? array( $text ) : $sentences;
	}
}
