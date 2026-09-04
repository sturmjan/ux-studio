<?php
/**
 * Renders a raw meta value according to a configured field type.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\AdminColumns;

defined( 'ABSPATH' ) || exit;

/**
 * Turns the raw value(s) of a meta-sourced column into the appropriate visual
 * representation (image, boolean, date, url, email, color swatch, related post
 * link, number or plain text). Ported and expanded from the legacy
 * FieldRenderer, which only returned raw meta strings.
 *
 * Every method returns FULLY ESCAPED HTML so callers can echo/return it
 * directly. The renderer never trusts stored values: URLs go through esc_url,
 * text through esc_html, colors through sanitize_hex_color, etc.
 */
final class FieldRenderer {

	/** Supported field-type renderers (the "renders as" selector). */
	public const TYPES = array( 'text', 'number', 'boolean', 'date', 'image', 'url', 'email', 'color', 'post' );

	/**
	 * Whether a field type is one we know how to render.
	 *
	 * @param string $type Candidate field type.
	 * @return bool
	 */
	public static function is_valid_type( string $type ): bool {
		return in_array( $type, self::TYPES, true );
	}

	/**
	 * Render the raw meta value(s) for a column.
	 *
	 * @param string             $field_type One of self::TYPES.
	 * @param array<int, mixed>  $values     Raw meta values (get_*_meta without $single).
	 * @return string Escaped HTML (may be empty).
	 */
	public function render( string $field_type, array $values ): string {
		$values = array_values(
			array_filter(
				$values,
				static fn( $value ) => '' !== $value && null !== $value && array() !== $value
			)
		);
		if ( empty( $values ) ) {
			return '';
		}

		switch ( $field_type ) {
			case 'number':
				return $this->render_number( $values );
			case 'boolean':
				return $this->render_boolean( $values[0] );
			case 'date':
				return $this->render_dates( $values );
			case 'image':
				return $this->render_images( $values );
			case 'url':
				return $this->render_urls( $values );
			case 'email':
				return $this->render_emails( $values );
			case 'color':
				return $this->render_colors( $values );
			case 'post':
				return $this->render_posts( $values );
			case 'text':
			default:
				return esc_html( implode( ', ', array_map( array( $this, 'to_string' ), $values ) ) );
		}
	}

	/**
	 * Coerce any stored value into a display string (arrays are imploded).
	 *
	 * @param mixed $value Stored value.
	 * @return string
	 */
	private function to_string( $value ): string {
		if ( is_array( $value ) ) {
			return implode( ', ', array_map( array( $this, 'to_string' ), $value ) );
		}
		if ( is_bool( $value ) ) {
			return $value ? '1' : '0';
		}
		return is_scalar( $value ) ? (string) $value : '';
	}

	/**
	 * Numbers, formatted for the site locale.
	 *
	 * @param array<int, mixed> $values Values.
	 * @return string
	 */
	private function render_number( array $values ): string {
		$parts = array();
		foreach ( $values as $value ) {
			$string = $this->to_string( $value );
			if ( is_numeric( $string ) ) {
				$decimals = ( (float) $string === (float) (int) $string ) ? 0 : 2;
				$parts[]  = number_format_i18n( (float) $string, $decimals );
			} else {
				$parts[] = $string;
			}
		}
		return esc_html( implode( ', ', $parts ) );
	}

	/**
	 * Boolean truthiness rendered as a coloured check / dash.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	private function render_boolean( $value ): string {
		$string = strtolower( trim( $this->to_string( $value ) ) );
		$truthy = in_array( $string, array( '1', 'true', 'yes', 'on', 'y' ), true )
			|| ( is_numeric( $string ) && 0.0 !== (float) $string );

		if ( $truthy ) {
			return sprintf(
				'<span style="color:#46b450;font-weight:600" title="%s">%s</span>',
				esc_attr__( 'Yes', 'ux-studio' ),
				esc_html( _x( '✓', 'boolean true mark', 'ux-studio' ) )
			);
		}
		return sprintf(
			'<span style="color:#a00" title="%s">%s</span>',
			esc_attr__( 'No', 'ux-studio' ),
			esc_html( _x( '–', 'boolean false mark', 'ux-studio' ) )
		);
	}

	/**
	 * One or more dates, formatted with the site date format via wp_date().
	 *
	 * @param array<int, mixed> $values Values.
	 * @return string
	 */
	private function render_dates( array $values ): string {
		$format = (string) get_option( 'date_format', 'Y-m-d' );
		$parts  = array();
		foreach ( $values as $value ) {
			$string    = $this->to_string( $value );
			$timestamp = is_numeric( $string ) ? (int) $string : strtotime( $string );
			$parts[]   = ( false === $timestamp || 0 === $timestamp )
				? esc_html( $string )
				: esc_html( (string) wp_date( $format, $timestamp ) );
		}
		return implode( ', ', $parts );
	}

	/**
	 * Attachment thumbnails. Values may be attachment IDs or image URLs.
	 *
	 * @param array<int, mixed> $values Values.
	 * @return string
	 */
	private function render_images( array $values ): string {
		$parts = array();
		foreach ( $values as $value ) {
			$string = $this->to_string( $value );
			if ( is_numeric( $string ) ) {
				$image = wp_get_attachment_image( (int) $string, array( 60, 60 ) );
				if ( '' !== $image ) {
					$parts[] = $image; // Core-generated, already safe.
					continue;
				}
			}
			if ( filter_var( $string, FILTER_VALIDATE_URL ) ) {
				$parts[] = sprintf(
					'<img src="%s" alt="" style="max-width:60px;height:auto;vertical-align:middle" />',
					esc_url( $string )
				);
			}
		}
		return implode( ' ', $parts );
	}

	/**
	 * Clickable links.
	 *
	 * @param array<int, mixed> $values Values.
	 * @return string
	 */
	private function render_urls( array $values ): string {
		$parts = array();
		foreach ( $values as $value ) {
			$string = $this->to_string( $value );
			$url    = esc_url( $string );
			if ( '' === $url ) {
				$parts[] = esc_html( $string );
				continue;
			}
			$parts[] = sprintf(
				'<a href="%s" target="_blank" rel="noopener noreferrer">%s</a>',
				$url,
				esc_html( $string )
			);
		}
		return implode( ', ', $parts );
	}

	/**
	 * mailto links with a light anti-spam obfuscation.
	 *
	 * @param array<int, mixed> $values Values.
	 * @return string
	 */
	private function render_emails( array $values ): string {
		$parts = array();
		foreach ( $values as $value ) {
			$string = $this->to_string( $value );
			if ( ! is_email( $string ) ) {
				$parts[] = esc_html( $string );
				continue;
			}
			$safe    = antispambot( $string );
			$parts[] = sprintf(
				'<a href="%s">%s</a>',
				esc_url( 'mailto:' . $safe, array( 'mailto' ) ),
				esc_html( $safe )
			);
		}
		return implode( ', ', $parts );
	}

	/**
	 * Colour swatches with the value beside them.
	 *
	 * @param array<int, mixed> $values Values.
	 * @return string
	 */
	private function render_colors( array $values ): string {
		$parts = array();
		foreach ( $values as $value ) {
			$string = trim( $this->to_string( $value ) );
			if ( '' === $string ) {
				continue;
			}
			// Prefer strict hex validation; fall back to a sanitized token for
			// named/rgb() values so a bad value can never inject CSS.
			$hex   = sanitize_hex_color( $string );
			$color = ( null !== $hex ) ? $hex : preg_replace( '/[^a-zA-Z0-9#(),.%\s]/', '', $string );

			$parts[] = sprintf(
				'<span style="display:inline-flex;align-items:center;gap:6px"><span style="display:inline-block;width:14px;height:14px;border:1px solid #ccc;border-radius:3px;background:%s"></span>%s</span>',
				esc_attr( (string) $color ),
				esc_html( $string )
			);
		}
		return implode( ' ', $parts );
	}

	/**
	 * Related posts rendered as linked titles. Values are post IDs.
	 *
	 * @param array<int, mixed> $values Values.
	 * @return string
	 */
	private function render_posts( array $values ): string {
		$ids = array();
		foreach ( $values as $value ) {
			// A meta value may itself hold a comma-separated / array list of IDs.
			foreach ( preg_split( '/[\s,]+/', $this->to_string( $value ) ) as $piece ) {
				if ( is_numeric( $piece ) ) {
					$ids[] = (int) $piece;
				}
			}
		}

		$parts = array();
		foreach ( array_unique( $ids ) as $id ) {
			$title = get_the_title( $id );
			if ( '' === $title ) {
				continue;
			}
			$link = get_edit_post_link( $id );
			if ( $link ) {
				$parts[] = sprintf( '<a href="%s">%s</a>', esc_url( $link ), esc_html( $title ) );
			} else {
				$parts[] = esc_html( $title );
			}
		}
		return implode( ', ', $parts );
	}
}
