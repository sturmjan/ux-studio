<?php
/**
 * Source: Simple Cloudflare Turnstile, Advanced Google reCAPTCHA.
 *
 * @package UxStudio
 */

namespace UxStudio\Core\Migration\Sources;

use UxStudio\Core\Migration\AbstractSource;
use UxStudio\Core\Migration\Mapping;

defined( 'ABSPATH' ) || exit;

/**
 * Target: Security Optimization CAPTCHA keys. The plugins draw the widget inside the
 * forms, so the module is set to the `inline` placement; the secret key goes through
 * Security::store_secret into the same encrypted option the module reads.
 *
 *  - Simple Cloudflare Turnstile: options `cfturnstile_key`, `cfturnstile_secret`,
 *    `cfturnstile_login`, `cfturnstile_register`, `cfturnstile_reset`, `cfturnstile_comment`,
 *    `cfturnstile_woo_login` / `_woo_register` / `_woo_reset` / `_woo_checkout`, `cfturnstile_theme`, `cfturnstile_size`.
 *  - Advanced Google reCAPTCHA: option `wpcaptcha_options` { captcha: recaptchav2 |
 *    recaptchav3 | builtin | disabled, captcha_site_key, captcha_secret_key,
 *    captcha_show_login / _wp_registration / _wp_lost_password / _wp_comment / _woo_login /
 *    _woo_registration / _woo_lost_password / _woo_checkout, captcha_theme, captcha_size }.
 *
 * Forms of the module (PLAN.md 27, A1): login, register, lostpassword, comment, woo_login, woo_register,
 * woo_lostpassword, woo_checkout; the widget look is `captcha_theme` and `captcha_size`.
 */
final class CaptchaSource extends AbstractSource {

	private const MODULE = 'security-optimization';

	/** Option the module reads its (encrypted) secret key from. */
	public const SECRET_OPTION = 'uxstudio_secret_security_optimization_captcha_secret_key';

	protected function option_names(): array {
		return 'advanced-google-recaptcha' === $this->slug ? array( 'wpcaptcha_options' ) : array( 'cfturnstile_key', 'cfturnstile_secret' );
	}

	protected function map( bool $active ): Mapping {
		return 'advanced-google-recaptcha' === $this->slug ? $this->map_recaptcha() : $this->map_turnstile();
	}

	/**
	 * Queue provider, keys, forms and widget look.
	 *
	 * @param Mapping  $m        Mapping.
	 * @param string   $provider `turnstile` | `recaptcha_v2` | `recaptcha_v3`.
	 * @param string   $site_key Site key.
	 * @param string   $secret   Secret key.
	 * @param string[] $forms    Module form ids (`login`, `register`, `lostpassword`, `comment`, `woo_*`).
	 * @param string   $origin   Origin label.
	 * @param string   $theme    Widget theme `auto` | `light` | `dark` ('' = the plugin has none, the module keeps its own).
	 * @param string   $size     Widget size `normal` | `compact` | `flexible` ('' = the same).
	 */
	private function fill( Mapping $m, string $provider, string $site_key, string $secret, array $forms, string $origin, string $theme = '', string $size = '' ): void {
		$ready = '' !== $site_key && '' !== $secret;
		$m->set( self::MODULE, 'captcha_provider', $provider, __( 'CAPTCHA provider', 'ux-studio' ), $origin );
		$m->set( self::MODULE, 'captcha_key_source', 'manual', __( 'Key source', 'ux-studio' ), $origin );
		$m->set( self::MODULE, 'captcha_site_key', $site_key, __( 'Site key', 'ux-studio' ), $origin );
		$m->secret( self::SECRET_OPTION, $secret, __( 'Secret key', 'ux-studio' ), $origin );
		$m->set( self::MODULE, 'captcha_placement', 'inline', __( 'Placement', 'ux-studio' ), $origin );
		$m->set( self::MODULE, 'captcha_mode', 'always', __( 'Enforcement mode (inline placement only)', 'ux-studio' ), $origin );
		$m->set( self::MODULE, 'captcha_forms', $forms, __( 'Protected forms', 'ux-studio' ), $origin );
		if ( in_array( $theme, array( 'auto', 'light', 'dark' ), true ) ) {
			$m->set( self::MODULE, 'captcha_theme', $theme, __( 'Widget theme', 'ux-studio' ), $origin );
		}
		if ( in_array( $size, array( 'normal', 'compact', 'flexible' ), true ) ) {
			$m->set( self::MODULE, 'captcha_size', $size, __( 'Widget size', 'ux-studio' ), $origin );
		}
		$m->set( self::MODULE, 'captcha_enabled', $ready && array() !== $forms, __( 'Enable CAPTCHA', 'ux-studio' ), $origin );
		if ( ! $ready ) {
			$m->warn( __( 'The site key or the secret key is empty, so the CAPTCHA stays switched off in the module.', 'ux-studio' ) );
		}
		if ( array() === $forms ) {
			$m->warn( __( 'The plugin protects none of the forms the module can protect, so the CAPTCHA stays switched off in the module.', 'ux-studio' ) );
		}
		$m->warn( __( 'Once the module is on, the CAPTCHA appears in the forms listed above with these keys. Make sure the keys are valid for this domain.', 'ux-studio' ) );
	}

	/**
	 * Simple Cloudflare Turnstile.
	 */
	private function map_turnstile(): Mapping {
		$m      = new Mapping();
		$key    = trim( (string) $this->opt( 'cfturnstile_key' ) );
		$secret = trim( (string) $this->opt( 'cfturnstile_secret' ) );
		if ( '' === $key && '' === $secret ) {
			$m->note( __( 'The plugin has no keys stored, there is nothing to transfer.', 'ux-studio' ) );
			return $m;
		}
		$forms = array();
		foreach ( array(
			'cfturnstile_login'        => 'login',
			'cfturnstile_register'     => 'register',
			'cfturnstile_reset'        => 'lostpassword',
			'cfturnstile_comment'      => 'comment',
			'cfturnstile_woo_login'    => 'woo_login',
			'cfturnstile_woo_register' => 'woo_register',
			'cfturnstile_woo_reset'    => 'woo_lostpassword',
			'cfturnstile_woo_checkout' => 'woo_checkout',
		) as $option => $form ) {
			if ( ! empty( $this->opt( $option ) ) ) {
				$forms[] = $form;
			}
		}
		$this->fill( $m, 'turnstile', $key, $secret, $forms, 'cfturnstile_key', (string) $this->opt( 'cfturnstile_theme' ), (string) $this->opt( 'cfturnstile_size' ) );

		$other = array();
		foreach ( array(
			'cfturnstile_cf7'            => 'Contact Form 7',
			'cfturnstile_wpforms'        => 'WPForms',
			'cfturnstile_elementor'      => 'Elementor',
			'cfturnstile_gravity'        => 'Gravity Forms',
			'cfturnstile_fluent'         => 'Fluent Forms',
			'cfturnstile_bbpress_create' => 'bbPress',
			'cfturnstile_buddypress'     => 'BuddyPress',
			'cfturnstile_edd_checkout'   => 'Easy Digital Downloads',
		) as $option => $label ) {
			if ( ! empty( $this->opt( $option ) ) ) {
				$other[] = $label;
			}
		}
		if ( $other ) {
			$m->lossy(
				sprintf(
					/* translators: %s: comma-separated list of plugins. */
					__( 'The module does not protect forms of other plugins. Not transferred: %s.', 'ux-studio' ),
					implode( ', ', $other )
				)
			);
		}
		$m->lossy( __( 'The widget language, the failsafe and the allow lists of the plugin are not transferred.', 'ux-studio' ) );
		return $m;
	}

	/**
	 * Advanced Google reCAPTCHA.
	 */
	private function map_recaptcha(): Mapping {
		$m = new Mapping();
		$o = $this->opt( 'wpcaptcha_options' );
		if ( ! is_array( $o ) ) {
			$m->note( __( 'The plugin has no stored settings, there is nothing to transfer.', 'ux-studio' ) );
			return $m;
		}
		$type = (string) ( $o['captcha'] ?? 'disabled' );
		if ( 'recaptchav2' === $type || 'recaptchav3' === $type ) {
			$forms = array();
			foreach ( array(
				'captcha_show_login'             => 'login',
				'captcha_show_wp_registration'   => 'register',
				'captcha_show_wp_lost_password'  => 'lostpassword',
				'captcha_show_wp_comment'        => 'comment',
				'captcha_show_woo_login'         => 'woo_login',
				'captcha_show_woo_registration'  => 'woo_register',
				'captcha_show_woo_lost_password' => 'woo_lostpassword',
				'captcha_show_woo_checkout'      => 'woo_checkout',
			) as $key => $form ) {
				if ( ! empty( $o[ $key ] ) ) {
					$forms[] = $form;
				}
			}
			$this->fill(
				$m,
				'recaptchav3' === $type ? 'recaptcha_v3' : 'recaptcha_v2',
				trim( (string) ( $o['captcha_site_key'] ?? '' ) ),
				trim( (string) ( $o['captcha_secret_key'] ?? '' ) ),
				$forms,
				'wpcaptcha_options',
				(string) ( $o['captcha_theme'] ?? '' ),
				(string) ( $o['captcha_size'] ?? '' )
			);
		} elseif ( 'builtin' === $type ) {
			$m->lossy( __( 'The plugin uses its own built-in CAPTCHA, which the module does not have. Choose Cloudflare Turnstile or reCAPTCHA in the module.', 'ux-studio' ) );
		} else {
			$m->note( __( 'The CAPTCHA is switched off in the plugin, there is nothing to transfer.', 'ux-studio' ) );
		}

		if ( ! empty( $o['login_protection'] ) ) {
			$m->lossy(
				sprintf(
					/* translators: 1: number of attempts, 2: lockout minutes. */
					__( 'The login limits of this plugin (%1$d attempts, lockout %2$d min) are not transferred by this source. Set them in the module (Limit login attempts) or migrate Limit Login Attempts Reloaded / Loginizer.', 'ux-studio' ),
					(int) ( $o['max_login_retries'] ?? 3 ),
					(int) ( $o['lockout_length'] ?? 60 )
				)
			);
		}
		if ( ! empty( $o['login_url'] ) ) {
			$m->lossy( __( 'The custom login address of this plugin is not transferred.', 'ux-studio' ) );
		}
		if ( 'none' !== (string) ( $o['country_blocking_mode'] ?? 'none' ) ) {
			$m->lossy( __( 'The country blocking of this plugin is not transferred, set it in the Firewall of Security Optimization.', 'ux-studio' ) );
		}
		return $m;
	}
}
