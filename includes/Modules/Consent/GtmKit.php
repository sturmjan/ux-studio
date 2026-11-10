<?php
/**
 * Hooks that make GTM Kit follow the cookie banner.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\Consent;

defined( 'ABSPATH' ) || exit;

/**
 * GTM Kit prints the Google Tag Manager container as an inline script (id `gtmkit-container-*`) and can emit its own
 * Consent Mode defaults. With this module on:
 *
 *  - the defaults come from us only (ConsentMode prints them first in the head), so the two never disagree;
 *  - GTM Kit's own consent-platform detection is told that one is active;
 *  - in basic mode the container is held back like any other tag until statistics or marketing is allowed
 *    (advanced mode leaves it alone: the container reads the Consent Mode signals).
 *
 * The filters used are the ones GTM Kit documents (`gtmkit_consent_default_settings_enabled`, `gtmkit_active_cmp`,
 * `gtmkit_cmp_display_name`) plus core's `wp_inline_script_attributes`. Everything here is a no-op without GTM Kit.
 */
final class GtmKit {

	/** Slug the module reports to GTM Kit as the active consent platform. */
	private const CMP_SLUG = 'uxstudio-consent';

	/**
	 * @param Module $module The consent module.
	 */
	public static function register( Module $module ): void {
		add_filter(
			'gtmkit_consent_default_settings_enabled',
			static function ( $enabled ) use ( $module ) {
				return 'off' === $module->consent_mode() ? $enabled : false;
			}
		);
		add_filter(
			'gtmkit_active_cmp',
			static function ( $detected ) {
				return is_string( $detected ) && '' !== $detected ? $detected : self::CMP_SLUG;
			}
		);
		add_filter(
			'gtmkit_cmp_display_name',
			static function ( $name, $slug = '' ) {
				return self::CMP_SLUG === $slug ? 'UX Studio Cookie Consent' : $name;
			},
			10,
			2
		);
		add_filter(
			'wp_inline_script_attributes',
			static function ( $attributes ) use ( $module ) {
				if ( ! is_array( $attributes ) || ! isset( $attributes['id'] ) || 0 !== strpos( (string) $attributes['id'], 'gtmkit-container' ) ) {
					return $attributes;
				}
				$attrs = Gate::attrs( 'statistics marketing', true );
				if ( '' === $attrs || ! (bool) $module->settings_value( 'block_scripts', true ) ) {
					return $attributes;
				}
				$attributes['type']     = 'text/plain';
				$attributes['data-uxc'] = 'statistics marketing';
				return $attributes;
			},
			20
		);
	}

	/**
	 * Services this integration adds to the banner's lists (label => category).
	 *
	 * @return array<string, string>
	 */
	public static function services(): array {
		return defined( 'GTMKIT_FILE' ) ? array( 'Google Tag Manager' => 'marketing' ) : array();
	}
}
