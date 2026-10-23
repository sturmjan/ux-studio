<?php
/**
 * Own CSS for the admin area and for the login page (two settings, sanitized, never printed on the front end).
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\AdminCustomiser;

use UxStudio\Core\CssSanitizer;
use UxStudio\Core\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * `admin_custom_css` is printed in the `<head>` of every wp-admin screen (`admin_head`), `login_custom_css` in the head of
 * the login screens (`login_head`, which is also the lost-password and registration screen). There is no hook on the front end,
 * no file is written and no JavaScript is supported (own admin JavaScript stays out of scope on purpose: it would be code
 * execution from a setting).
 *
 * The stored value is sanitized on save (`Settings`, field type `css`) and once more when it is printed, so a value that got
 * into the option another way (a database edit, an import) cannot break out of the `<style>` element either.
 */
final class CustomCss {

	/**
	 * Hook the two printers.
	 */
	public static function register(): void {
		add_action( 'admin_head', array( self::class, 'print_admin' ), 1000 );
		add_action( 'login_head', array( self::class, 'print_login' ), 1000 );
	}

	/**
	 * The CSS to print for one setting (empty string = nothing).
	 *
	 * @param string $key `admin_custom_css` or `login_custom_css`.
	 */
	public static function css( string $key ): string {
		return CssSanitizer::clean( (string) ( new Settings( 'uxstudio_admin_customiser' ) )->get( $key, '' ) );
	}

	/**
	 * `<style>` element for a CSS string, or '' when there is none.
	 *
	 * @param string $id  Element id.
	 * @param string $css Already sanitized CSS.
	 */
	public static function style_tag( string $id, string $css ): string {
		if ( '' === $css ) {
			return '';
		}
		return '<style id="' . esc_attr( $id ) . '">' . "\n" . $css . "\n</style>\n";
	}

	/**
	 * Print the admin CSS.
	 */
	public static function print_admin(): void {
		echo self::style_tag( 'uxstudio-admin-custom-css', self::css( 'admin_custom_css' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CssSanitizer output inside a <style> element.
	}

	/**
	 * Print the login page CSS.
	 */
	public static function print_login(): void {
		echo self::style_tag( 'uxstudio-login-custom-css', self::css( 'login_custom_css' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CssSanitizer output inside a <style> element.
	}
}
