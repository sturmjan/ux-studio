<?php
/**
 * Legacy login options that live outside Login.php: the `form_options`
 * checklist (`hide_login_logo`) and `login_form_text_hover_color`.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\AdminCustomiser;

use UxStudio\Core\Settings;

defined( 'ABSPATH' ) || exit;

final class LoginExtras {

	/** Is "Hide Login Logo" ticked in the legacy `form_options` checklist? */
	public static function logo_hidden( Settings $settings ): bool {
		$options = $settings->get( 'form_options', array() );
		return is_array( $options ) && in_array( 'hide_login_logo', $options, true );
	}

	/**
	 * CSS for the hide-logo option and the form text hover/focus color.
	 *
	 * @param Settings $settings Module settings.
	 * @param bool     $split    Whether the split layout is active.
	 */
	public static function css( Settings $settings, bool $split ): string {
		$css = '';

		if ( self::logo_hidden( $settings ) ) {
			$css .= '#login .wp-login-logo,#login>h1:not(.uxstudio-login-brand__title),.uxstudio-login-media__logo,.uxstudio-login-brand__icon{display:none!important}';
			if ( ! $split ) {
				// Keep the form where it was: the hidden logo block was ~100px tall.
				$css .= '#login{padding-top:calc(5% + 100px)}';
			}
		}

		$hover = (string) sanitize_hex_color( (string) $settings->get( 'login_form_text_hover_color', '' ) );
		if ( '' !== $hover ) {
			$css .= '#login form a:hover,#login form a:focus,#login #nav a:hover,#login #nav a:focus,#login #backtoblog a:hover,#login #backtoblog a:focus{color:' . $hover . '!important}';
			$css .= '#login form input[type=text]:focus,#login form input[type=password]:focus,#login form input[type=email]:focus{border-color:' . $hover . '!important;box-shadow:0 0 0 1px ' . $hover . '!important}';
		}

		return $css;
	}
}
