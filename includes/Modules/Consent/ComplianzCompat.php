<?php
/**
 * Shortcodes and the block of Complianz, answered by this module while Complianz itself is not running.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\Consent;

defined( 'ABSPATH' ) || exit;

/**
 * After the switch the old plugin is off, but content the migration did not rewrite (page builder data, widgets, text in
 * custom fields) can still carry `[cmplz-manage-consent]` and friends. Instead of printing raw shortcodes, the same names
 * give our equivalents. If Complianz is active (its shortcode exists) nothing is registered, so the two never compete.
 */
final class ComplianzCompat {

	/** Hook in (called from Module::boot()). */
	public static function boot(): void {
		add_action( 'init', array( self::class, 'register' ), 20 );
	}

	/** Register the compatible shortcodes and the block when Complianz is not there. */
	public static function register(): void {
		if ( shortcode_exists( 'cmplz-document' ) ) {
			return;
		}
		add_shortcode( 'cmplz-document', array( self::class, 'document' ) );
		foreach ( array( 'cmplz-manage-consent', 'cmplz-revoke-link', 'cmplz-accept-link' ) as $tag ) {
			add_shortcode( $tag, array( self::class, 'button' ) );
		}
		add_shortcode( 'cmplz-cookies', array( self::class, 'cookies' ) );
		if ( function_exists( 'register_block_type' ) && class_exists( '\WP_Block_Type_Registry' ) && ! \WP_Block_Type_Registry::get_instance()->is_registered( 'complianz/document' ) ) {
			register_block_type(
				'complianz/document',
				array( 'render_callback' => array( self::class, 'block' ) )
			);
		}
	}

	/**
	 * [cmplz-document type="cookie-statement" region="eu"] gives our document of the same kind.
	 *
	 * @param mixed $atts Attributes.
	 */
	public static function document( $atts ): string {
		$atts = shortcode_atts(
			array(
				'type'   => '',
				'region' => '',
			),
			is_array( $atts ) ? $atts : array(),
			'cmplz-document'
		);
		return self::render( (string) $atts['type'], (string) $atts['region'] );
	}

	/**
	 * Render a Complianz document type as our document, or nothing for a type we do not produce.
	 *
	 * @param string $type   Complianz type.
	 * @param string $region Region.
	 */
	private static function render( string $type, string $region ): string {
		if ( 1 === preg_match( '/^(.*?)-(us|uk|ca|au|za|br)$/', $type, $m ) ) {
			$type   = $m[1];
			$region = $m[2];
		}
		$doc = ComplianzMap::doc_for( $type, $region );
		if ( null === $doc ) {
			return '<!-- ' . esc_html( 'Complianz document "' . $type . '" is not produced by UX1 Studio.' ) . ' -->';
		}
		return Legal::shortcode( array( 'doc' => $doc ) );
	}

	/**
	 * Render callback of the block `complianz/document`.
	 *
	 * @param mixed $attributes Block attributes.
	 */
	public static function block( $attributes ): string {
		$a = is_array( $attributes ) ? $attributes : array();
		if ( 'unlink' === ( $a['documentSyncStatus'] ?? '' ) && isset( $a['customDocument'] ) && is_string( $a['customDocument'] ) ) {
			return wp_kses_post( $a['customDocument'] );
		}
		return self::render( (string) ( $a['selectedDocument'] ?? '' ), '' );
	}

	/**
	 * Every settings link of Complianz is the settings button.
	 *
	 * @param mixed $atts Attributes (`text` becomes the label).
	 */
	public static function button( $atts ): string {
		$atts = shortcode_atts( array( 'text' => '' ), is_array( $atts ) ? $atts : array() );
		return do_shortcode( '[uxstudio_cookie_settings' . ( '' !== trim( (string) $atts['text'] ) ? ' label="' . esc_attr( str_replace( '"', '', (string) $atts['text'] ) ) . '"' : '' ) . ']' );
	}

	/** [cmplz-cookies] is the table of services and cookies. */
	public static function cookies(): string {
		return do_shortcode( '[uxstudio_cookie_table]' );
	}
}
