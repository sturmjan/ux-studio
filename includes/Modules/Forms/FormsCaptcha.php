<?php
/**
 * The CAPTCHA field of the Forms module: which provider it uses, how it is drawn and checked.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\Forms;

use UxStudio\Core\ActivityLog;
use UxStudio\Modules\SecurityOptimization\CaptchaVerifier;
use UxStudio\Modules\SecurityOptimization\Module as SecurityModule;
use UxStudio\Modules\SecurityOptimization\ShieldRuntime;
use UxStudio\Modules\SecurityOptimization\TurnstileCaProvisioner;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Independent of the Security module's CAPTCHA switch (`captcha_enabled` decides about the login forms only). The
 * provider of a form is worked out from what the site has, in this order:
 *
 *   1. Cloudflare Turnstile keys from the central application, while the site is connected,
 *   2. the site's own keys (Turnstile or reCAPTCHA), stored in the Security options,
 *   3. the key-less Shield.
 *
 * The keys are the same pair the login forms use: one store (Security::store_secret + the Security option), so a pair
 * provisioned here also protects the login and nothing is provisioned twice. The pure order lives in
 * SecurityOptimization\CaptchaResolver.
 *
 * A form that had a CAPTCHA field before this existed keeps working without any change of its data. What differs:
 * before, the field did nothing unless the Security CAPTCHA switch was on and keys were set (a "not configured"
 * note was shown and every submission passed); now it always protects, with the Shield when there are no keys.
 */
final class FormsCaptcha {

	/** Name of the posted Shield answer of one form: unique per form, so two forms on a page never share one. */
	public static function field_name( int $form_id ): string {
		return 'uxs_shield_f' . $form_id;
	}

	private static function security(): ?SecurityModule {
		$module = \UxStudio\Plugin::instance()->modules->instance( 'security-optimization' );
		return $module instanceof SecurityModule ? $module : null;
	}

	/**
	 * Config of the field, or null when the Security code is not there (never in a normal install).
	 *
	 * @return array<string,mixed>|null
	 */
	public static function config(): ?array {
		$module = self::security();
		return $module ? CaptchaVerifier::forms_config( $module ) : null;
	}

	/**
	 * What the builder shows: the provider in use, where its keys come from and what can be done about it.
	 *
	 * @return array{provider:string,source:string,connected:bool,has_keys:bool,keys_from_ca:bool,can_provision:bool}
	 */
	public static function status(): array {
		$module = self::security();
		if ( ! $module ) {
			return array(
				'provider'      => 'shield',
				'source'        => 'shield',
				'connected'     => false,
				'has_keys'      => false,
				'keys_from_ca'  => false,
				'can_provision' => false,
			);
		}
		$state  = $module->captcha_state();
		$config = CaptchaVerifier::forms_config( $module );
		return array(
			'provider'      => (string) $config['provider'],
			'source'        => (string) $config['source'],
			'connected'     => $state['connected'],
			'has_keys'      => $state['has_keys'],
			'keys_from_ca'  => $state['ca_keys'],
			// Own keys are never overwritten behind the administrator's back: only an empty store, or a pair that
			// came from the central application itself (a refresh), can be provisioned.
			'can_provision' => $state['connected'] && ( ! $state['has_keys'] || $state['ca_keys'] ),
		);
	}

	/**
	 * Markup of the field.
	 *
	 * @param int $form_id Form id.
	 */
	public static function render( int $form_id ): string {
		$config = self::config();
		if ( null === $config || ! CaptchaVerifier::config_ready( $config ) ) {
			return '<p class="uxs-fp-captcha-placeholder">' . esc_html__( 'Spam protection is not configured for this site yet.', 'ux-studio' ) . '</p>';
		}
		CaptchaVerifier::enqueue( $config );
		ob_start();
		CaptchaVerifier::render(
			$config,
			'forms',
			array( 'field' => self::field_name( $form_id ) )
		);
		return (string) ob_get_clean();
	}

	/**
	 * Check the answer that came with a submission.
	 *
	 * @param int $form_id Form id.
	 * @return string '' when it passes, otherwise a sentence for the visitor.
	 */
	public static function verify( int $form_id ): string {
		$config = self::config();
		if ( null === $config || ! CaptchaVerifier::config_ready( $config ) ) {
			return ''; // Nothing to check against (keys half stored): a half-set CAPTCHA must not block every submission.
		}
		$code = CaptchaVerifier::verify_code( $config, null, 'forms', self::field_name( $form_id ) );
		if ( '' === $code ) {
			return '';
		}
		if ( 'shield' === $config['provider'] ) {
			return ShieldRuntime::message( $code );
		}
		return __( 'Spam verification failed, please try again.', 'ux-studio' );
	}

	/**
	 * Fetch Turnstile keys for this site from the central application and store them where the login forms look too.
	 *
	 * @return array<string,mixed>|WP_Error The new status, or the error with the text the central application gave.
	 */
	public static function provision() {
		$module = self::security();
		if ( ! $module ) {
			return new WP_Error( 'uxstudio_forms_captcha_no_security', __( 'The Security module is not available.', 'ux-studio' ), array( 'status' => 409 ) );
		}
		if ( ! TurnstileCaProvisioner::is_connected() ) {
			return new WP_Error( 'uxstudio_forms_captcha_not_connected', __( 'Connect the site to the central application first.', 'ux-studio' ), array( 'status' => 409 ) );
		}
		$status = self::status();
		if ( $status['has_keys'] && ! $status['keys_from_ca'] ) {
			return new WP_Error( 'uxstudio_forms_captcha_own_keys', __( 'The site already has its own CAPTCHA keys. Remove them in Security > CAPTCHA first if you want keys from the central application.', 'ux-studio' ), array( 'status' => 409 ) );
		}

		$keys = TurnstileCaProvisioner::provision( $module );
		if ( is_wp_error( $keys ) ) {
			ActivityLog::log( 'forms', 'captcha_provision_failed', 'turnstile', 0, array( 'error' => $keys->get_error_message() ) );
			return new WP_Error( 'uxstudio_forms_captcha_provision', $keys->get_error_message(), array( 'status' => 502 ) );
		}

		$module->store_captcha_keys( $keys['site_key'], $keys['secret_key'], 'ca' );
		ActivityLog::log( 'forms', 'captcha_provisioned', 'turnstile', 0, array( 'source' => 'ca' ) );
		return self::status();
	}
}
