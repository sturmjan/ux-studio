<?php
/**
 * SVG sanitization service (ported whole from the legacy shared service).
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\SvgUpload;

defined( 'ABSPATH' ) || exit;

/**
 * Provides secure SVG content sanitization. DOM-based (not regex) with a strict
 * allowlist of tags/attributes; fail-closed on invalid input.
 */
final class Sanitizer {

	/**
	 * Allowed SVG tags.
	 *
	 * @var string[]
	 */
	private static array $allowed_tags = array(
		'svg', 'path', 'circle', 'rect', 'line', 'polyline', 'polygon', 'ellipse',
		'text', 'tspan', 'g', 'defs', 'clipPath', 'mask', 'filter', 'feGaussianBlur',
		'feColorMatrix', 'feBlend', 'feComposite', 'feFlood', 'feOffset', 'feMerge',
		'feMergeNode', 'feMorphology', 'feTile', 'feTurbulence', 'feDisplacementMap',
		'feConvolveMatrix', 'feDiffuseLighting', 'feSpecularLighting', 'feDistantLight',
		'fePointLight', 'feSpotLight', 'feImage', 'feFuncR', 'feFuncG', 'feFuncB',
		'feFuncA', 'feComponentTransfer', 'feDropShadow', 'animate', 'animateTransform',
		'animateMotion', 'set', 'use', 'symbol', 'marker', 'pattern', 'linearGradient',
		'radialGradient', 'stop', 'metadata', 'title', 'desc', 'style',
	);

	/**
	 * Allowed SVG attributes.
	 *
	 * @var string[]
	 */
	private static array $allowed_attributes = array(
		'id', 'class', 'style', 'transform', 'fill', 'fill-opacity', 'fill-rule',
		'stroke', 'stroke-width', 'stroke-opacity', 'stroke-linecap', 'stroke-linejoin',
		'stroke-dasharray', 'stroke-dashoffset', 'opacity', 'visibility', 'display',
		'font-family', 'font-size', 'font-weight', 'font-style', 'text-anchor',
		'dominant-baseline', 'alignment-baseline', 'baseline-shift', 'letter-spacing',
		'word-spacing', 'text-decoration', 'text-transform', 'direction', 'unicode-bidi',
		'writing-mode', 'text-orientation', 'glyph-orientation-horizontal',
		'glyph-orientation-vertical', 'kerning', 'color', 'color-interpolation',
		'color-interpolation-filters', 'color-rendering', 'flood-color', 'flood-opacity',
		'lighting-color', 'stop-color', 'stop-opacity', 'clip-path', 'clip-rule',
		'mask', 'filter', 'cursor', 'pointer-events', 'overflow', 'marker-start',
		'marker-mid', 'marker-end', 'markerUnits', 'markerWidth', 'markerHeight',
		'refX', 'refY', 'orient', 'patternUnits', 'patternContentUnits', 'patternTransform',
		'x', 'y', 'width', 'height', 'rx', 'ry', 'cx', 'cy', 'r', 'x1', 'y1', 'x2', 'y2',
		'points', 'd', 'pathLength', 'viewBox', 'preserveAspectRatio', 'xmlns',
		'xmlns:xlink', 'xlink:href', 'xlink:title', 'xlink:show', 'xlink:actuate',
		'xml:space', 'xml:lang', 'xml:base', 'xml:id', 'xml:class', 'xml:style',
		'xml:title', 'xml:desc', 'xml:metadata', 'xml:defs', 'xml:use', 'xml:symbol',
		'xml:marker', 'xml:pattern', 'xml:linearGradient', 'xml:radialGradient',
		'xml:stop', 'xml:animate', 'xml:animateTransform', 'xml:animateMotion',
		'xml:set', 'xml:filter', 'xml:feGaussianBlur', 'xml:feColorMatrix',
		'xml:feBlend', 'xml:feComposite', 'xml:feFlood', 'xml:feOffset',
		'xml:feMerge', 'xml:feMergeNode', 'xml:feMorphology', 'xml:feTile',
		'xml:feTurbulence', 'xml:feDisplacementMap', 'xml:feConvolveMatrix',
		'xml:feDiffuseLighting', 'xml:feSpecularLighting', 'xml:feDistantLight',
		'xml:fePointLight', 'xml:feSpotLight', 'xml:feImage', 'xml:feFuncR',
		'xml:feFuncG', 'xml:feFuncB', 'xml:feFuncA', 'xml:feComponentTransfer',
		'xml:feDropShadow',
	);

	/**
	 * Sanitize SVG content by removing dangerous elements and attributes.
	 *
	 * DOM parser (not regex) + allowlist. Fail-closed: invalid SVG returns an
	 * empty string. DOCTYPE/ENTITY are stripped to prevent XXE/billion-laughs and
	 * external entity loading is disabled.
	 *
	 * @param string $content SVG content.
	 * @return string Sanitized SVG content.
	 */
	public static function sanitize( $content ): string {
		if ( empty( $content ) ) {
			return (string) $content;
		}

		// Remove DOCTYPE/ENTITY (XXE, billion laughs) and PHP markers before parsing.
		$content = preg_replace( '/<!DOCTYPE[^>]*>/is', '', (string) $content );
		$content = preg_replace( '/<!ENTITY[^>]*>/is', '', (string) $content );
		$content = preg_replace( '/<\?php/i', '', (string) $content );

		// Disable network loading of external entities (XXE) across PHP versions.
		if ( function_exists( 'libxml_set_external_entity_loader' ) ) {
			libxml_set_external_entity_loader(
				static function () {
					return null;
				}
			);
		}
		$previous = libxml_use_internal_errors( true );

		$dom = new \DOMDocument();
		// LIBXML_NONET = no network; without LIBXML_NOENT entities are not expanded.
		$loaded = $dom->loadXML( $content, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING );

		libxml_clear_errors();
		libxml_use_internal_errors( $previous );

		// Fail-closed: unreadable/invalid SVG or non-svg root is discarded.
		if ( ! $loaded || ! $dom->documentElement ) {
			return '';
		}
		if ( 'svg' !== strtolower( $dom->documentElement->nodeName ) ) {
			return '';
		}

		self::sanitize_node( $dom->documentElement );

		$output = $dom->saveXML( $dom->documentElement );
		return false !== $output ? $output : '';
	}

	/**
	 * Recursively clean a DOM node: disallowed elements, attributes and unsafe refs.
	 *
	 * @param \DOMNode $node Node to clean.
	 */
	private static function sanitize_node( \DOMNode $node ): void {
		if ( null === $node->childNodes ) {
			return;
		}

		// Iterate backwards to allow safe removal during traversal.
		for ( $i = $node->childNodes->length - 1; $i >= 0; $i-- ) {
			$child = $node->childNodes->item( $i );
			if ( null === $child ) {
				continue;
			}

			// Comments and processing instructions can carry payloads -> remove.
			if ( XML_COMMENT_NODE === $child->nodeType || XML_PI_NODE === $child->nodeType ) {
				$node->removeChild( $child );
				continue;
			}

			if ( XML_ELEMENT_NODE !== $child->nodeType ) {
				continue;
			}

			/** @var \DOMElement $child */
			$tag = strtolower( $child->localName ?: $child->nodeName );

			// Disallowed element -> delete the whole subtree.
			if ( ! in_array( $tag, array_map( 'strtolower', self::$allowed_tags ), true ) ) {
				$node->removeChild( $child );
				continue;
			}

			// <style>: drop on dangerous CSS constructs.
			if ( 'style' === $tag ) {
				$css = (string) $child->textContent;
				if ( preg_match( '/url\s*\(|@import|expression\s*\(|javascript:|vbscript:/i', $css ) ) {
					$node->removeChild( $child );
					continue;
				}
			}

			self::sanitize_element_attributes( $child );
			self::sanitize_node( $child );
		}
	}

	/**
	 * Clean one element's attributes against the allowlist + block unsafe values.
	 *
	 * @param \DOMElement $el Element to clean.
	 */
	private static function sanitize_element_attributes( \DOMElement $el ): void {
		if ( null === $el->attributes ) {
			return;
		}

		$allowed = array_map( 'strtolower', self::$allowed_attributes );

		for ( $i = $el->attributes->length - 1; $i >= 0; $i-- ) {
			$attr = $el->attributes->item( $i );
			if ( ! $attr instanceof \DOMAttr ) {
				continue;
			}

			$name       = strtolower( $attr->nodeName ); // Incl. prefix (xlink:href).
			$local_name = strtolower( $attr->localName ?: $attr->nodeName );
			$value      = (string) $attr->nodeValue;

			// 1) Event handlers (onload, onclick, ...) -> always remove.
			if ( 0 === strpos( $local_name, 'on' ) ) {
				$el->removeAttributeNode( $attr );
				continue;
			}

			// 2) href / xlink:href -> only internal (#id) or data:image (not svg).
			if ( 'href' === $local_name ) {
				if ( ! self::is_safe_href( $value ) ) {
					$el->removeAttributeNode( $attr );
				}
				continue;
			}

			// 3) Attribute outside the allowlist (localName and full name) -> remove.
			if ( ! in_array( $local_name, $allowed, true ) && ! in_array( $name, $allowed, true ) ) {
				$el->removeAttributeNode( $attr );
				continue;
			}

			// 4) Unsafe values in otherwise-allowed attributes.
			if ( preg_match( '/javascript:|vbscript:|expression\s*\(|<script/i', $value ) ) {
				$el->removeAttributeNode( $attr );
				continue;
			}
			if ( 'style' === $local_name && preg_match( '/url\s*\(|@import/i', $value ) ) {
				$el->removeAttributeNode( $attr );
			}
		}
	}

	/**
	 * Safe target for href/xlink:href: only internal fragment or data:image (raster).
	 *
	 * @param string $value Attribute value.
	 */
	private static function is_safe_href( string $value ): bool {
		$value = trim( $value );
		if ( '' === $value ) {
			return false;
		}
		if ( '#' === $value[0] ) {
			return true;
		}
		return (bool) preg_match( '#^data:image/(png|jpeg|gif|webp);base64,#i', $value );
	}

	/**
	 * Validate SVG content (quick pre-flight check).
	 *
	 * @param string $content SVG content.
	 * @return bool True if it looks like a safe SVG.
	 */
	public static function validate( $content ): bool {
		if ( empty( $content ) ) {
			return false;
		}

		if ( false === strpos( (string) $content, '<svg' ) ) {
			return false;
		}

		$dangerous_patterns = array(
			'/<script/i',
			'/on\w+=/i',
			'/<foreignObject/i',
			'/javascript:/i',
			'/vbscript:/i',
			'/data:text\/html/i',
		);

		foreach ( $dangerous_patterns as $pattern ) {
			if ( preg_match( $pattern, (string) $content ) ) {
				return false;
			}
		}

		return true;
	}
}
