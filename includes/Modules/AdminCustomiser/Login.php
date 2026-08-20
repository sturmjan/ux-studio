<?php
/**
 * Login screen customisation: custom logo (points at the site's home URL
 * instead of wordpress.org) and a custom background color.
 *
 * Ported from the legacy admin-customiser module (Login.php). The legacy
 * version exposed a much larger set of color fields (login_bg_color,
 * login_text_color, login_form_bg_color, login_form_text_color,
 * login_form_border_color, login_form_border_radius, login_input_*,
 * login_button_*) plus a "Remember me" default-checked toggle and a custom
 * login message. The new settings contract only has `login_logo` and
 * `login_background_color`, so only those two are wired up; everything else
 * is dropped rather than left half-configurable.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\AdminCustomiser;

use UxStudio\Core\Settings;

defined( 'ABSPATH' ) || exit;

final class Login {

	private Settings $settings;

	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Register hooks. Only instantiated when login_customization_enabled=true.
	 */
	public function register(): void {
		add_action( 'login_enqueue_scripts', array( $this, 'print_styles' ) );
		add_filter( 'login_headerurl', array( $this, 'logo_url' ) );
		add_filter( 'login_headertext', array( $this, 'logo_title' ) );
	}

	/** Point the login logo link at the site's home URL. */
	public function logo_url(): string {
		return home_url();
	}

	/** Use the site name as the logo's title/alt text. */
	public function logo_title(): string {
		return get_bloginfo( 'name' );
	}

	/**
	 * Inline <style> for the login logo and background color.
	 */
	public function print_styles(): void {
		$logo_id = (int) $this->settings->get( 'login_logo', 0 );
		$bg      = (string) $this->settings->get( 'login_background_color', '' );

		$css = '';

		if ( $logo_id > 0 ) {
			$logo_url = wp_get_attachment_image_url( $logo_id, 'full' );
			if ( $logo_url ) {
				$css .= '#login h1 a, .login h1 a {';
				$css .= 'background-image: url(' . esc_url( $logo_url ) . ') !important;';
				$css .= 'background-size: contain; background-position: center; background-repeat: no-repeat;';
				$css .= 'width: 100%; height: 80px;';
				$css .= '}';
			}
		}

		if ( '' !== $bg && preg_match( '/^#[0-9a-fA-F]{3,8}$/', $bg ) ) {
			$css .= 'body.login { background-color: ' . esc_attr( $bg ) . ' !important; }';
		}

		if ( '' === $css ) {
			return;
		}

		printf( '<style id="uxstudio-login-customiser">%s</style>', $css ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}
}
