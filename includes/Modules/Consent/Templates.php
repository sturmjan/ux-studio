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
 * switches), cookie (dark rounded card with round buttons), slim (one thin bar). The behaviour is the same in all of them.
 */
final class Templates {

	/** The first one is the default and the fallback for any other value. */
	public const ALL = array( 'classic', 'cookiebot', 'cookie', 'slim' );

	/** Colour settings: key => array( CSS variable, the default of the stylesheet ). */
	public const COLOURS = array(
		'color_bg'          => array( '--uxc-bg', '#ffffff' ),
		'color_text'        => array( '--uxc-fg', '#1d2327' ),
		'color_accent'      => array( '--uxc-ac', '#1d4ed8' ),
		'color_accent_text' => array( '--uxc-ac-fg', '#ffffff' ),
	);

	/** Defaults of the dark card. They replace a PAIR of colours (background + text, buttons + button text) only while neither was changed. */
	private const CARD = array(
		'color_bg'          => '#2a2e32',
		'color_text'        => '#f4f5f6',
		'color_accent'      => '#f5b942',
		'color_accent_text' => '#1d1608',
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
		$card = 'cookie' === self::normalize( $template );
		$css  = '';
		foreach ( self::COLOURS as $key => $pair ) {
			$value = strtolower( (string) ( $values[ $key ] ?? '' ) );
			if ( '' === $value ) {
				continue;
			}
			if ( $card ) {
				$mate     = in_array( $key, array( 'color_bg', 'color_text' ), true ) ? array( 'color_bg', 'color_text' ) : array( 'color_accent', 'color_accent_text' );
				$pristine = true;
				foreach ( $mate as $other ) {
					$pristine = $pristine && strtolower( (string) ( $values[ $other ] ?? self::COLOURS[ $other ][1] ) ) === self::COLOURS[ $other ][1];
				}
				if ( $pristine ) {
					$value = self::CARD[ $key ];
				}
			}
			if ( $value !== $pair[1] ) {
				$css .= $pair[0] . ':' . $value . ';';
			}
		}
		return $css;
	}
}
