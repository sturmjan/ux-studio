<?php
/**
 * Tiny template engine of the legal pages: variables, flags, nothing else.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\Consent;

defined( 'ABSPATH' ) || exit;

/**
 * Pure logic (no WordPress calls), covered by tests/consent-legal.php.
 *
 * Syntax inside a template string:
 *
 *   {{name}}                    value of `name`, HTML-escaped
 *   {{{name}}}                  value of `name` as it is (only for HTML the generator built and escaped itself)
 *   {% if flag %}..{% endif %}  the part is kept when `flag` is truthy (non-empty string, true, non-zero)
 *   {% if flag %}..{% else %}..{% endif %}
 *   {% if not flag %}..{% endif %}
 *
 * Conditions nest. An unknown name is empty and falsy. Nothing in a template can run code.
 */
final class LegalTemplate {

	/**
	 * Render a template.
	 *
	 * @param string               $template Template text.
	 * @param array<string, mixed> $vars     Values by name (string, int, bool or list of strings, which counts as truthy when not empty).
	 */
	public static function render( string $template, array $vars ): string {
		$out = self::conditions( $template, $vars );
		$out = (string) preg_replace_callback(
			'/\{\{\{\s*([a-z0-9_]+)\s*\}\}\}/',
			static fn( array $m ): string => self::text( $vars[ $m[1] ] ?? '' ),
			$out
		);
		return (string) preg_replace_callback(
			'/\{\{\s*([a-z0-9_]+)\s*\}\}/',
			static fn( array $m ): string => htmlspecialchars( self::text( $vars[ $m[1] ] ?? '' ), ENT_QUOTES, 'UTF-8' ),
			$out
		);
	}

	/**
	 * Names used by a template (variables and flags), for the tests that check every name is provided.
	 *
	 * @param string $template Template text.
	 * @return list<string>
	 */
	public static function names( string $template ): array {
		preg_match_all( '/\{\{\{?\s*([a-z0-9_]+)\s*\}?\}\}|\{%\s*if\s+(?:not\s+)?([a-z0-9_]+)\s*%\}/', $template, $m, PREG_SET_ORDER );
		$names = array();
		foreach ( $m as $hit ) {
			$names[] = '' !== $hit[1] ? $hit[1] : ( $hit[2] ?? '' );
		}
		return array_values( array_unique( array_filter( $names ) ) );
	}

	/**
	 * Are the {% if %} / {% endif %} pairs balanced and the {% else %} placed inside one?
	 *
	 * @param string $template Template text.
	 */
	public static function balanced( string $template ): bool {
		$depth = 0;
		$else  = array();
		preg_match_all( '/\{%\s*(if\s+(?:not\s+)?[a-z0-9_]+|else|endif)\s*%\}/', $template, $m );
		foreach ( $m[1] as $tag ) {
			if ( 0 === strpos( $tag, 'if' ) ) {
				++$depth;
				$else[ $depth ] = false;
			} elseif ( 'else' === $tag ) {
				if ( $depth < 1 || $else[ $depth ] ) {
					return false;
				}
				$else[ $depth ] = true;
			} else {
				if ( $depth < 1 ) {
					return false;
				}
				--$depth;
			}
		}
		return 0 === $depth;
	}

	/**
	 * Resolve conditions from the innermost one outwards.
	 *
	 * @param string               $template Template text.
	 * @param array<string, mixed> $vars     Values.
	 */
	private static function conditions( string $template, array $vars ): string {
		$pattern = '/\{%\s*if\s+(not\s+)?([a-z0-9_]+)\s*%\}((?:(?!\{%\s*if\s).)*?)\{%\s*endif\s*%\}/s';
		for ( $i = 0; $i < 50; $i++ ) {
			$next = preg_replace_callback(
				$pattern,
				static function ( array $m ) use ( $vars ): string {
					$truthy = self::truthy( $vars[ $m[2] ] ?? null );
					if ( '' !== $m[1] ) {
						$truthy = ! $truthy;
					}
					$parts = preg_split( '/\{%\s*else\s*%\}/', $m[3], 2 );
					$yes   = (string) ( $parts[0] ?? '' );
					$no    = (string) ( $parts[1] ?? '' );
					return $truthy ? $yes : $no;
				},
				$template
			);
			if ( null === $next || $next === $template ) {
				break;
			}
			$template = $next;
		}
		return $template;
	}

	/**
	 * Truthiness of a value.
	 *
	 * @param mixed $value Value.
	 */
	private static function truthy( $value ): bool {
		if ( is_array( $value ) ) {
			return count( $value ) > 0;
		}
		if ( is_string( $value ) ) {
			return '' !== trim( $value ) && '0' !== $value;
		}
		return (bool) $value;
	}

	/**
	 * A value as text.
	 *
	 * @param mixed $value Value.
	 */
	private static function text( $value ): string {
		if ( is_array( $value ) ) {
			return implode( ', ', array_map( 'strval', $value ) );
		}
		if ( is_bool( $value ) ) {
			return $value ? '1' : '';
		}
		return (string) $value;
	}
}
