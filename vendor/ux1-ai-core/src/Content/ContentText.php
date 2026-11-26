<?php
/**
 * Text helpers shared by the content pipeline: tolerant JSON extraction from model output, title normalisation for
 * de-duplication, Unicode-aware word counting and word-boundary truncation.
 *
 * @package Ux1\AiCore
 */

declare(strict_types=1);

namespace Ux1\AiCore\Content;

final class ContentText {

	/**
	 * Extracts the first JSON object/array from model output: tolerates ```json fences, prose around the JSON,
	 * raw line breaks inside strings and trailing commas. Returns null when nothing decodes.
	 *
	 * @return array<mixed>|null
	 */
	public static function extract_json( string $raw ): ?array {
		$raw = trim( $raw );
		if ( preg_match( '/```(?:json)?\s*(.*?)```/is', $raw, $m ) && '' !== trim( $m[1] ) ) {
			$data = self::decode_candidate( trim( $m[1] ) );
			if ( null !== $data ) {
				return $data;
			}
		}
		$data = self::decode_candidate( $raw );
		if ( null !== $data ) {
			return $data;
		}
		$length = strlen( $raw );
		for ( $i = 0; $i < $length; $i++ ) {
			if ( '{' !== $raw[ $i ] && '[' !== $raw[ $i ] ) {
				continue;
			}
			$end = self::balanced_end( $raw, $i );
			if ( null === $end ) {
				continue;
			}
			$data = self::decode_candidate( substr( $raw, $i, $end - $i + 1 ) );
			if ( null !== $data ) {
				return $data;
			}
		}
		return null;
	}

	/** @return array<mixed>|null */
	private static function decode_candidate( string $json ): ?array {
		$data = json_decode( $json, true );
		if ( is_array( $data ) ) {
			return $data;
		}
		$data = json_decode( self::repair( $json ), true );
		return is_array( $data ) ? $data : null;
	}

	/** Escapes raw control characters inside strings and drops trailing commas. */
	private static function repair( string $json ): string {
		$out       = '';
		$in_string = false;
		$escaped   = false;
		$length    = strlen( $json );
		$bs        = chr( 92 );
		for ( $i = 0; $i < $length; $i++ ) {
			$c = $json[ $i ];
			if ( $in_string ) {
				if ( $escaped ) {
					$escaped = false;
				} elseif ( $bs === $c ) {
					$escaped = true;
				} elseif ( '"' === $c ) {
					$in_string = false;
				} elseif ( "\n" === $c ) {
					$c = $bs . 'n';
				} elseif ( "\r" === $c ) {
					$c = $bs . 'r';
				} elseif ( "\t" === $c ) {
					$c = $bs . 't';
				}
				$out .= $c;
				continue;
			}
			if ( '"' === $c ) {
				$in_string = true;
			} elseif ( '}' === $c || ']' === $c ) {
				$out = (string) preg_replace( '/,\s*$/', '', $out );
			}
			$out .= $c;
		}
		return $out;
	}

	/** Index of the bracket closing the one at $start (string-aware), or null. */
	private static function balanced_end( string $s, int $start ): ?int {
		$depth     = 0;
		$in_string = false;
		$escaped   = false;
		$length    = strlen( $s );
		$bs        = chr( 92 );
		for ( $i = $start; $i < $length; $i++ ) {
			$c = $s[ $i ];
			if ( $in_string ) {
				if ( $escaped ) {
					$escaped = false;
				} elseif ( $bs === $c ) {
					$escaped = true;
				} elseif ( '"' === $c ) {
					$in_string = false;
				}
				continue;
			}
			if ( '"' === $c ) {
				$in_string = true;
			} elseif ( '{' === $c || '[' === $c ) {
				++$depth;
			} elseif ( '}' === $c || ']' === $c ) {
				--$depth;
				if ( 0 === $depth ) {
					return $i;
				}
			}
		}
		return null;
	}

	/** English name of a language code for use in prompts; unknown codes are passed through. */
	public static function language_name( string $code ): string {
		$names = array(
			'cs' => 'Czech', 'sk' => 'Slovak', 'en' => 'English', 'de' => 'German', 'pl' => 'Polish', 'fr' => 'French',
			'es' => 'Spanish', 'it' => 'Italian', 'pt' => 'Portuguese', 'nl' => 'Dutch', 'hu' => 'Hungarian',
			'ro' => 'Romanian', 'ru' => 'Russian', 'uk' => 'Ukrainian',
		);
		return $names[ strtolower( trim( $code ) ) ] ?? ( '' !== trim( $code ) ? trim( $code ) : 'English' );
	}

	/** Lower-case, diacritics-free, alphanumerics-only form of a title, for duplicate detection. */
	public static function normalize( string $text ): string {
		$text = mb_strtolower( html_entity_decode( strip_tags( $text ), ENT_QUOTES, 'UTF-8' ), 'UTF-8' );
		$text = strtr(
			$text,
			array(
				'á' => 'a', 'à' => 'a', 'â' => 'a', 'ä' => 'a', 'ã' => 'a', 'å' => 'a', 'ą' => 'a', 'č' => 'c', 'ć' => 'c', 'ç' => 'c',
				'ď' => 'd', 'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e', 'ě' => 'e', 'ę' => 'e', 'í' => 'i', 'ì' => 'i', 'î' => 'i',
				'ï' => 'i', 'ĺ' => 'l', 'ľ' => 'l', 'ł' => 'l', 'ň' => 'n', 'ń' => 'n', 'ñ' => 'n', 'ó' => 'o', 'ò' => 'o', 'ô' => 'o',
				'ö' => 'o', 'õ' => 'o', 'ő' => 'o', 'ř' => 'r', 'ŕ' => 'r', 'š' => 's', 'ś' => 's', 'ß' => 'ss', 'ť' => 't', 'ú' => 'u',
				'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'ů' => 'u', 'ű' => 'u', 'ý' => 'y', 'ÿ' => 'y', 'ž' => 'z', 'ź' => 'z', 'ż' => 'z',
			)
		);
		$text = (string) preg_replace( '/[^\p{L}\p{N}]+/u', ' ', $text );
		return trim( $text );
	}

	/** Words in the visible text of an HTML fragment; unlike str_word_count() it does not split on diacritics. */
	public static function word_count( string $html ): int {
		$text = html_entity_decode( strip_tags( str_replace( array( '</p>', '</li>', '</h2>', '</h3>', '<br>', '<br/>', '<br />' ), ' ', $html ) ), ENT_QUOTES, 'UTF-8' );
		return (int) preg_match_all( '/[\p{L}\p{N}]+(?:[\'’-][\p{L}\p{N}]+)*/u', $text );
	}

	/** Truncates at a word boundary to at most $max characters (no ellipsis). */
	public static function truncate( string $text, int $max ): string {
		$text = trim( (string) preg_replace( '/\s+/u', ' ', $text ) );
		if ( mb_strlen( $text, 'UTF-8' ) <= $max ) {
			return $text;
		}
		$cut   = mb_substr( $text, 0, $max, 'UTF-8' );
		$space = mb_strrpos( $cut, ' ', 0, 'UTF-8' );
		if ( false !== $space && $space > $max * 0.6 ) {
			$cut = mb_substr( $cut, 0, $space, 'UTF-8' );
		}
		return rtrim( $cut, ' ,;:-–—' );
	}
}
