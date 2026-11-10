<?php
/**
 * Banner templates: the list, the fallback and the colour defaults of each (pure, no WordPress needed).
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\Consent;

defined( 'ABSPATH' ) || exit;

/**
 * The look of the banner is a template (PLAN 42.20): classic (box or bar, the original look), cookiebot (wide panel with tabs and
 * switches), cookie (light rounded card with round buttons), slim (one thin bar). The behaviour is the same in all of them.
 */
final class Templates {

	/** The first one is the default and the fallback for any other value. */
	/** Where the small UX1 signature in the banner points. */
	public const BY_URL = 'https://ux1.cz/';

	/** The signature link of the banner: the address, or an empty string when the site owner has switched it off. */
	public static function by( bool $show ): string {
		return $show ? self::BY_URL : '';
	}

	public const ALL = array( 'classic', 'cookiebot', 'cookie', 'slim' );

	/** Colour settings: key => array( CSS variable, the default of the stylesheet ). */
	public const COLOURS = array(
		'color_bg'          => array( '--uxc-bg', '#ffffff' ),
		'color_text'        => array( '--uxc-fg', '#1d2327' ),
		'color_accent'      => array( '--uxc-ac', '#1d4ed8' ),
		'color_accent_text' => array( '--uxc-ac-fg', '#ffffff' ),
	);

	/**
	 * @param string $value Stored value.
	 */
	public static function normalize( string $value ): string {
		return in_array( $value, self::ALL, true ) ? $value : self::ALL[0];
	}

	/**
	 * Name of the extra style file of a template ('' for classic, which lives in the base style).
	 *
	 * @param string $template Template.
	 * @param string $min      '.min' or ''.
	 */
	public static function style_file( string $template, string $min ): string {
		$template = self::normalize( $template );
		return 'classic' === $template ? '' : 'consent-' . $template . $min . '.css';
	}

	/**
	 * CSS variables that differ from the stylesheet defaults.
	 *
	 * @param string                $template Template.
	 * @param array<string, string> $values   Hex colour by setting key (already sanitized; '' = invalid, skipped).
	 */
	public static function variables( string $template, array $values ): string {
		$css = '';
		foreach ( self::COLOURS as $key => $pair ) {
			$value = strtolower( (string) ( $values[ $key ] ?? '' ) );
			if ( '' !== $value && $value !== $pair[1] ) {
				$css .= $pair[0] . ':' . $value . ';';
			}
		}
		return $css;
	}

	/** Defaults of the options of each template (PLAN 42.22) = the look the template had before it had options. */
	public const DEFAULTS = array(
		'tabs_place'  => 'center',
		'tabs_about'  => true,
		'tabs_width'  => 880,
		'card_icon'   => true,
		'card_shape'  => 'round',
		'card_radius' => 22,
		'slim_place'  => 'bottom',
		'slim_link'   => true,
	);

	/** Allowed range of the numbers (px). */
	public const RANGES = array(
		'tabs_width'  => array( 480, 1400 ),
		'card_radius' => array( 0, 40 ),
	);

	/**
	 * A number of an option inside its range (an empty or non-numeric value is the default).
	 *
	 * @param string $key   Option key (see RANGES).
	 * @param mixed  $value Stored value.
	 */
	public static function number( string $key, $value ): int {
		if ( ! is_numeric( $value ) ) {
			return (int) self::DEFAULTS[ $key ];
		}
		return max( self::RANGES[ $key ][0], min( self::RANGES[ $key ][1], (int) $value ) );
	}

	/**
	 * The options of the chosen template for the banner script (`cfg.o`): only that template's, short keys, so the
	 * config of a page does not grow. Classic has none (its layout and side are `layout` and `pos`).
	 *
	 * @param string               $template Template.
	 * @param array<string, mixed> $values   Stored settings by key.
	 * @return array<string, mixed>
	 */
	public static function options( string $template, array $values ): array {
		$get = static fn( string $key ) => $values[ $key ] ?? self::DEFAULTS[ $key ];
		switch ( self::normalize( $template ) ) {
			case 'cookiebot':
				return array(
					'place' => 'full' === (string) $get( 'tabs_place' ) ? 'full' : 'center',
					'about' => (bool) $get( 'tabs_about' ),
				);
			case 'cookie':
				return array(
					'icon'  => (bool) $get( 'card_icon' ),
					'shape' => 'rect' === (string) $get( 'card_shape' ) ? 'rect' : 'round',
				);
			case 'slim':
				return array(
					'place' => 'top' === (string) $get( 'slim_place' ) ? 'top' : 'bottom',
					'link'  => (bool) $get( 'slim_link' ),
				);
		}
		return array();
	}

	/**
	 * CSS variables for the numeric options of the chosen template, only the ones that differ from the stylesheet.
	 *
	 * @param string               $template Template.
	 * @param array<string, mixed> $values   Stored settings by key.
	 */
	public static function size_variables( string $template, array $values ): string {
		$css = '';
		switch ( self::normalize( $template ) ) {
			case 'cookiebot':
				$w = self::number( 'tabs_width', $values['tabs_width'] ?? null );
				if ( self::DEFAULTS['tabs_width'] !== $w ) {
					$css .= '--uxc-w:' . $w . 'px;';
				}
				break;
			case 'cookie':
				$r = self::number( 'card_radius', $values['card_radius'] ?? null );
				if ( self::DEFAULTS['card_radius'] !== $r ) {
					$css .= '--uxc-cr:' . $r . 'px;';
				}
				break;
		}
		return $css;
	}
}
