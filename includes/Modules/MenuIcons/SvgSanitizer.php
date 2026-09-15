<?php
/**
 * Strict allowlist SVG sanitizer for user-supplied menu item icons.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\MenuIcons;

defined( 'ABSPATH' ) || exit;

/**
 * Menu icon SVG is rendered inline, site-wide, for every visitor - unlike a
 * regular file upload it is never served as a static asset with its own
 * content type, so it is a direct stored-XSS surface if pasted raw. This
 * sanitizer keeps a hard allowlist of tags/attributes and rejects anything
 * else outright (no "strip and hope", the whole value is dropped instead).
 */
final class SvgSanitizer {

	/**
	 * Allowed tags and, per tag, their allowed attributes.
	 *
	 * @var array<string, string[]>
	 */
	private const ALLOWED = array(
		'svg'      => array( 'viewbox', 'width', 'height', 'fill', 'stroke', 'stroke-width', 'stroke-linecap', 'stroke-linejoin', 'xmlns' ),
		'path'     => array( 'd', 'fill', 'stroke', 'stroke-width', 'stroke-linecap', 'stroke-linejoin' ),
		'g'        => array( 'fill', 'stroke', 'transform' ),
		'circle'   => array( 'cx', 'cy', 'r', 'fill', 'stroke', 'stroke-width' ),
		'ellipse'  => array( 'cx', 'cy', 'rx', 'ry', 'fill', 'stroke', 'stroke-width' ),
		'rect'     => array( 'x', 'y', 'width', 'height', 'rx', 'ry', 'fill', 'stroke', 'stroke-width' ),
		'line'     => array( 'x1', 'y1', 'x2', 'y2', 'stroke', 'stroke-width', 'stroke-linecap' ),
		'polyline' => array( 'points', 'fill', 'stroke', 'stroke-width', 'stroke-linecap', 'stroke-linejoin' ),
		'polygon'  => array( 'points', 'fill', 'stroke', 'stroke-width' ),
	);

	/**
	 * Maximum accepted input length, to keep postmeta sane and bound parse cost.
	 */
	private const MAX_LENGTH = 20000;

	/**
	 * Sanitize a user-supplied SVG string.
	 *
	 * @param string $raw Raw, unslashed SVG markup.
	 * @return string Sanitized `<svg>...</svg>` markup, or '' if the input is
	 *                empty, oversized, not well-formed XML, has no root `<svg>`
	 *                element, or contains anything outside the allowlist.
	 */
	public static function sanitize( string $raw ): string {
		$raw = trim( $raw );

		if ( '' === $raw || strlen( $raw ) > self::MAX_LENGTH ) {
			return '';
		}

		// Fast reject: these must never survive even a parse error path.
		if ( false !== stripos( $raw, '<script' ) || false !== stripos( $raw, 'javascript:' ) ) {
			return '';
		}

		$dom = new \DOMDocument();

		$internal_errors = libxml_use_internal_errors( true );
		$prev_entity_loader_exists = function_exists( 'libxml_disable_entity_loader' );
		// phpcs:ignore WordPress.WP.DeprecatedFunctions.libxml_disable_entity_loaderFound -- defence in depth on older PHP/libxml; no-op on PHP 8+.
		$prev_entity_loader = $prev_entity_loader_exists ? libxml_disable_entity_loader( true ) : null;

		$loaded = $dom->loadXML( $raw, LIBXML_NONET );

		libxml_clear_errors();
		libxml_use_internal_errors( $internal_errors );
		if ( $prev_entity_loader_exists ) {
			// phpcs:ignore WordPress.WP.DeprecatedFunctions.libxml_disable_entity_loaderFound
			libxml_disable_entity_loader( $prev_entity_loader );
		}

		if ( ! $loaded || ! $dom->documentElement ) {
			return '';
		}

		$root = $dom->documentElement;
		if ( 'svg' !== strtolower( $root->nodeName ) ) {
			return '';
		}

		if ( ! self::clean_node( $root ) ) {
			return '';
		}

		$output = $dom->saveXML( $root );
		return is_string( $output ) ? $output : '';
	}

	/**
	 * Recursively validate a node against the allowlist, stripping disallowed
	 * attributes and rejecting (returning false) on any disallowed element,
	 * comment, processing instruction, or entity reference.
	 *
	 * @param \DOMNode $node Node to validate in place.
	 */
	private static function clean_node( \DOMNode $node ): bool {
		if ( XML_COMMENT_NODE === $node->nodeType || XML_PI_NODE === $node->nodeType || XML_ENTITY_REF_NODE === $node->nodeType ) {
			return false;
		}

		if ( XML_TEXT_NODE === $node->nodeType || XML_CDATA_SECTION_NODE === $node->nodeType ) {
			return true;
		}

		if ( XML_ELEMENT_NODE !== $node->nodeType ) {
			return false;
		}

		/** @var \DOMElement $node */
		$tag = strtolower( $node->nodeName );
		if ( ! isset( self::ALLOWED[ $tag ] ) ) {
			return false;
		}

		if ( $node->hasAttributes() ) {
			$allowed_attrs = self::ALLOWED[ $tag ];
			// Snapshot first: DOMNamedNodeMap mutates live while iterating.
			$attrs = array();
			foreach ( $node->attributes as $attr ) {
				$attrs[] = $attr;
			}
			foreach ( $attrs as $attr ) {
				$name = strtolower( $attr->nodeName );
				if ( 0 === strpos( $name, 'on' ) || ! in_array( $name, $allowed_attrs, true ) ) {
					$node->removeAttribute( $attr->nodeName );
					continue;
				}
				if ( false !== stripos( $attr->nodeValue, 'javascript:' ) || false !== stripos( $attr->nodeValue, 'data:' ) ) {
					$node->removeAttribute( $attr->nodeName );
				}
			}
		}

		if ( $node->hasChildNodes() ) {
			$children = array();
			foreach ( $node->childNodes as $child ) {
				$children[] = $child;
			}
			foreach ( $children as $child ) {
				if ( ! self::clean_node( $child ) ) {
					return false;
				}
			}
		}

		return true;
	}
}
