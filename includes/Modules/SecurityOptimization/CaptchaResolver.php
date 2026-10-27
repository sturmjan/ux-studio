<?php
/**
 * Which CAPTCHA a form really uses (pure decisions).
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\SecurityOptimization;

defined( 'ABSPATH' ) || exit;

/**
 * Two questions, both answered without touching WordPress so they are tested as tables:
 *
 *  - resolve(): where the keys come from. The order is the same for the login forms (provider `auto`) and for the
 *    CAPTCHA field of the Forms module: keys from the central application (only while the site is connected), then
 *    the site's own keys, then the key-less Shield.
 *  - provider(): the CAPTCHA that the Security module uses for its forms, from the `captcha_provider` setting.
 */
final class CaptchaResolver {

	public const KEY_PROVIDERS = array( 'turnstile', 'recaptcha_v2', 'recaptcha_v3' );
	public const SETTINGS      = array( 'auto', 'turnstile', 'recaptcha_v2', 'recaptcha_v3', 'shield' );

	public const SOURCE_CA     = 'ca';
	public const SOURCE_OWN    = 'own';
	public const SOURCE_SHIELD = 'shield';

	/**
	 * Where the keys come from.
	 *
	 * @param bool $connected The site is connected to the central application (URL and node key present).
	 * @param bool $ca_keys   A key pair that came from the central application is stored.
	 * @param bool $own_keys  A key pair that the administrator brought is stored (stale keys of the central application
	 *                        count too once the connection is gone: they still work, so nothing is switched off silently).
	 * @return string ca | own | shield
	 */
	public static function resolve( bool $connected, bool $ca_keys, bool $own_keys ): string {
		if ( $connected && $ca_keys ) {
			return self::SOURCE_CA;
		}
		if ( $own_keys ) {
			return self::SOURCE_OWN;
		}
		return self::SOURCE_SHIELD;
	}

	/**
	 * The inputs of resolve() from what is stored.
	 *
	 * @param bool   $has_keys   Site key and secret key are both stored.
	 * @param string $key_source `captcha_key_source`: manual | ca | self_api.
	 * @param bool   $connected  The site is connected to the central application.
	 * @return array{ca_keys:bool,own_keys:bool}
	 */
	public static function key_flags( bool $has_keys, string $key_source, bool $connected ): array {
		$from_ca = $has_keys && 'ca' === $key_source;
		return array(
			'ca_keys'  => $from_ca,
			'own_keys' => $has_keys && ! ( $from_ca && $connected ),
		);
	}

	/**
	 * Setting value, or the default of the site: an install that never stored a CAPTCHA setting gets `auto`, a site
	 * that already has some keeps the old default (Turnstile), so nothing changes for it.
	 *
	 * @param mixed $stored  Stored `captcha_provider` (null = never saved).
	 * @param bool  $legacy  The module's options already hold any `captcha_*` value.
	 */
	public static function setting( $stored, bool $legacy ): string {
		if ( is_string( $stored ) && in_array( $stored, self::SETTINGS, true ) ) {
			return $stored;
		}
		return $legacy ? 'turnstile' : 'auto';
	}

	/**
	 * The provider the Security module's forms use.
	 *
	 * @param string $setting      Value from setting().
	 * @param string $own_provider Type of the site's own keys (`captcha_own_provider`), used when the setting is `auto`.
	 * @param bool   $connected    Connected to the central application.
	 * @param bool   $ca_keys      See resolve().
	 * @param bool   $own_keys     See resolve().
	 * @return array{provider:string,source:string} provider: turnstile | recaptcha_v2 | recaptcha_v3 | shield
	 */
	public static function provider( string $setting, string $own_provider, bool $connected, bool $ca_keys, bool $own_keys ): array {
		if ( 'shield' === $setting ) {
			return array(
				'provider' => 'shield',
				'source'   => self::SOURCE_SHIELD,
			);
		}
		if ( in_array( $setting, self::KEY_PROVIDERS, true ) ) {
			// An explicit provider keeps its old rule: it works with its keys, whoever supplied them.
			return array(
				'provider' => $setting,
				'source'   => $ca_keys && $connected ? self::SOURCE_CA : self::SOURCE_OWN,
			);
		}
		$own    = in_array( $own_provider, self::KEY_PROVIDERS, true ) ? $own_provider : 'turnstile';
		$source = self::resolve( $connected, $ca_keys, $own_keys );
		return array(
			// The central application only hands out Turnstile keys.
			'provider' => self::SOURCE_CA === $source ? 'turnstile' : ( self::SOURCE_OWN === $source ? $own : 'shield' ),
			'source'   => $source,
		);
	}

	/**
	 * The provider of the CAPTCHA field in a form: keys first, the Shield when there are none. The Security module's
	 * `captcha_enabled` switch has nothing to say here.
	 *
	 * @param string $own_provider Type of the site's own keys (see provider()).
	 * @param string $setting      The Security `captcha_provider` setting (a key based choice names the type of own keys).
	 * @param bool   $connected    Connected to the central application.
	 * @param bool   $ca_keys      See resolve().
	 * @param bool   $own_keys     See resolve().
	 * @return array{provider:string,source:string}
	 */
	public static function for_forms( string $own_provider, string $setting, bool $connected, bool $ca_keys, bool $own_keys ): array {
		$type = in_array( $setting, self::KEY_PROVIDERS, true ) ? $setting : $own_provider;
		return self::provider( 'auto', $type, $connected, $ca_keys, $own_keys );
	}
}
