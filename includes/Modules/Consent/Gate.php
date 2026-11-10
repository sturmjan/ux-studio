<?php
/**
 * What other modules ask the consent module (is it on, how to hold a script back).
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\Consent;

defined( 'ABSPATH' ) || exit;

/**
 * A module that prints its own tracking snippet (Pixel & Tag Manager) asks `Gate::attrs()` for the attributes to put
 * on the `<script>`. Without the consent module, or on a screen where the banner does not run, it is an empty string
 * and nothing changes.
 */
final class Gate {

	private static ?Module $module = null;

	/**
	 * @param Module $module The booted consent module.
	 */
	public static function boot( Module $module ): void {
		self::$module = $module;
	}

	/**
	 * Is the consent module on AND is this a request where the banner runs (the public site, not the admin, REST, AJAX,
	 * feeds, the Customizer or a page-builder editor)?
	 */
	public static function active(): bool {
		return null !== self::$module && self::$module->runs_here();
	}

	/**
	 * Consent Mode setting: off, basic or advanced.
	 */
	public static function mode(): string {
		return null === self::$module ? 'off' : self::$module->consent_mode();
	}

	/**
	 * Attributes that hold a script back until a category is allowed, with a leading space, or an empty string.
	 *
	 * @param string $category    preferences | statistics | marketing (or "statistics marketing" for any of them).
	 * @param bool   $google_tag  The script is a Google tag that reads Consent Mode: in advanced mode it loads at once
	 *                            (it sends no cookies and no identifiers while consent is denied).
	 */
	public static function attrs( string $category, bool $google_tag = false ): string {
		if ( ! self::active() ) {
			return '';
		}
		if ( $google_tag && 'advanced' === self::mode() ) {
			return '';
		}
		$allowed = array_merge( Services::CATEGORIES );
		foreach ( explode( ' ', $category ) as $name ) {
			if ( ! in_array( $name, $allowed, true ) ) {
				return '';
			}
		}
		return ' type="text/plain" data-uxc="' . $category . '"';
	}
}
