<?php
/**
 * Plain-text helper shared by the chunker and the fallback embedding (the host-neutral stand-in for
 * wp_strip_all_tags()).
 *
 * @package Ux1\AiCore
 */

declare(strict_types=1);

namespace Ux1\AiCore\Rag;

final class TextCleaner {

	/** Removes script/style blocks (with their content) and all tags, then trims. */
	public static function strip_tags( string $text ): string {
		$text = (string) preg_replace( '#<(script|style)\b[^>]*>.*?</\1\s*>#is', '', $text );
		return trim( strip_tags( $text ) );
	}
}
