<?php
/**
 * Whitelist sanitiser for article HTML coming from a model. Output is a fragment containing only the allowed tags
 * with only the allowed attributes; scripts, styles, event handlers (on*), inline styles and javascript: URLs are gone.
 * It is a content-quality filter, not a replacement for the host's own output escaping on publish (wp_kses etc.).
 *
 * @package Ux1\AiCore
 */

declare(strict_types=1);

namespace Ux1\AiCore\Content;

final class ArticleHtml {

	/** Tags kept as they are. h1 becomes h2, b becomes strong, i becomes em; everything else is unwrapped. */
	public const ALLOWED_TAGS = array( 'h2', 'h3', 'h4', 'p', 'ul', 'ol', 'li', 'strong', 'em', 'a', 'blockquote', 'br', 'table', 'thead', 'tbody', 'tr', 'th', 'td' );

	/** Tags dropped together with their content. */
	private const DROP_WITH_CONTENT = array( 'script', 'style', 'iframe', 'object', 'embed', 'form', 'input', 'button', 'select', 'textarea', 'noscript', 'template', 'svg', 'math', 'link', 'meta', 'head', 'title', 'applet', 'audio', 'video', 'canvas' );

	private const RENAME = array(
		'h1' => 'h2',
		'h5' => 'h4',
		'h6' => 'h4',
		'b'  => 'strong',
		'i'  => 'em',
	);

	/** @param bool $allow_images Keep <img src alt> (an image without alt gets an empty alt attribute, never a missing one). */
	public static function clean( string $html, bool $allow_images = false ): string {
		$html = trim( $html );
		$html = (string) preg_replace( '/^```(?:html)?\s*|\s*```$/i', '', $html );
		if ( '' === trim( $html ) ) {
			return '';
		}

		$previous = libxml_use_internal_errors( true );
		$doc      = new \DOMDocument( '1.0', 'UTF-8' );
		$doc->loadHTML( '<?xml encoding="utf-8" ?><html><body>' . $html . '</body></html>', LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_HTML_NODEFDTD );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );

		$body = $doc->getElementsByTagName( 'body' )->item( 0 );
		if ( ! $body instanceof \DOMElement ) {
			return '';
		}
		self::walk( $body, $doc, $allow_images );

		$out = '';
		foreach ( iterator_to_array( $body->childNodes ) as $child ) {
			$out .= $doc->saveHTML( $child );
		}
		$out = (string) preg_replace( '/<p>\s*(?:<br\s*\/?>)?\s*<\/p>/i', '', $out );
		return trim( $out );
	}

	private static function walk( \DOMNode $parent, \DOMDocument $doc, bool $allow_images ): void {
		foreach ( iterator_to_array( $parent->childNodes ) as $node ) {
			if ( $node instanceof \DOMComment || $node instanceof \DOMProcessingInstruction ) {
				$parent->removeChild( $node );
				continue;
			}
			if ( ! $node instanceof \DOMElement ) {
				continue;
			}
			$name = strtolower( $node->tagName );
			if ( in_array( $name, self::DROP_WITH_CONTENT, true ) ) {
				$parent->removeChild( $node );
				continue;
			}
			if ( isset( self::RENAME[ $name ] ) ) {
				$new = $doc->createElement( self::RENAME[ $name ] );
				while ( $node->firstChild ) {
					$new->appendChild( $node->firstChild );
				}
				$parent->replaceChild( $new, $node );
				$node = $new;
				$name = $new->tagName;
			}
			if ( 'img' === $name && $allow_images ) {
				self::filter_attributes( $node, array( 'src', 'alt' ) );
				if ( ! $node->hasAttribute( 'alt' ) ) {
					$node->setAttribute( 'alt', '' );
				}
				continue;
			}
			if ( ! in_array( $name, self::ALLOWED_TAGS, true ) ) {
				self::walk( $node, $doc, $allow_images );
				while ( $node->firstChild ) {
					$parent->insertBefore( $node->firstChild, $node );
				}
				$parent->removeChild( $node );
				continue;
			}
			self::filter_attributes( $node, 'a' === $name ? array( 'href', 'title' ) : array() );
			self::walk( $node, $doc, $allow_images );
		}
	}

	/** @param array<int, string> $keep */
	private static function filter_attributes( \DOMElement $el, array $keep ): void {
		foreach ( iterator_to_array( $el->attributes ) as $attr ) {
			$attr_name = strtolower( $attr->name );
			if ( ! in_array( $attr_name, $keep, true ) ) {
				$el->removeAttribute( $attr->name );
				continue;
			}
			if ( ( 'href' === $attr_name || 'src' === $attr_name ) && ! self::is_safe_url( $attr->value ) ) {
				$el->removeAttribute( $attr->name );
			}
		}
	}

	private static function is_safe_url( string $url ): bool {
		$url = trim( (string) preg_replace( '/[\x00-\x20]+/', '', $url ) );
		if ( '' === $url ) {
			return false;
		}
		if ( preg_match( '/^([a-z][a-z0-9+.-]*):/i', $url, $m ) ) {
			return in_array( strtolower( $m[1] ), array( 'http', 'https', 'mailto', 'tel' ), true );
		}
		return true; // Relative URL or #anchor.
	}
}
