<?php
/**
 * Sanitizer for custom CSS that is printed inside a <style> element of the admin area or the login page.
 *
 * @package UxStudio
 */

namespace UxStudio\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Custom CSS is written by an administrator, but it is stored in an option and printed raw into the page, so it must never be
 * able to leave the <style> element (that would be stored XSS), and it has a size limit so one setting cannot bloat every
 * admin page. Nothing here executes or fetches anything: the goal is "CSS stays CSS".
 *
 * What is removed or neutralised:
 *  - control characters and NUL bytes;
 *  - `<style`, `</style`, `<script`, `</script` (any case, with spaces) and HTML comment markers;
 *  - every other `<` that starts something tag-like (`<a`, `</`, `<!`, `<?`) becomes the CSS escape `\3C ` (still valid in strings);
 *  - legacy script-in-CSS vectors: `expression(`, `javascript:`, `vbscript:`, `behavior:`, `-moz-binding`;
 *  - everything past MAX_BYTES (cut at a whole character).
 * A `<` followed by a space or a digit is left alone (media query ranges such as `(width < 600px)` are valid CSS).
 */
final class CssSanitizer {

	/** Largest stored custom CSS in bytes (about 2000 lines). */
	public const MAX_BYTES = 51200;

	/**
	 * Clean a custom CSS string.
	 *
	 * @param mixed $css Raw CSS.
	 */
	public static function clean( $css ): string {
		$css = is_string( $css ) ? $css : '';
		$css = str_replace( array( "\r\n", "\r" ), "\n", $css );
		// Valid UTF-8 only, no control characters except tab and newline.
		if ( function_exists( 'mb_convert_encoding' ) ) {
			$css = (string) mb_convert_encoding( $css, 'UTF-8', 'UTF-8' );
		}
		$css = (string) preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $css );

		// Repeat until stable: removing one marker must not assemble another (`<</style>style`).
		for ( $i = 0; $i < 10; $i++ ) {
			$before = $css;
			$css    = (string) preg_replace( '#<\s*/?\s*(?:style|script)#i', '', $css );
			$css    = str_replace( array( '<!--', '-->' ), '', $css );
			$css    = (string) preg_replace( '/(?:expression\s*\(|javascript\s*:|vbscript\s*:|behaviou?r\s*:|-moz-binding)/i', '', $css );
			if ( $css === $before ) {
				break;
			}
		}
		// What is still tag-like cannot be CSS: write it as an escape.
		$css = (string) preg_replace_callback( '#<(?=[/A-Za-z!?])#', static fn(): string => '\\3C ', $css );

		$css = trim( $css );
		if ( strlen( $css ) > self::MAX_BYTES ) {
			$css = function_exists( 'mb_strcut' ) ? mb_strcut( $css, 0, self::MAX_BYTES, 'UTF-8' ) : substr( $css, 0, self::MAX_BYTES );
			$cut = strrpos( $css, '}' );
			if ( false !== $cut ) {
				$css = substr( $css, 0, $cut + 1 );
			}
		}
		return $css;
	}
}
