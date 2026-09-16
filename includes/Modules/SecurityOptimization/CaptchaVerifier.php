<?php
/**
 * Shared Cloudflare Turnstile / Google reCAPTCHA plumbing.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\SecurityOptimization;

defined( 'ABSPATH' ) || exit;

/**
 * Provider-agnostic CAPTCHA helpers shared by CaptchaHandler (widget inline in
 * the WordPress login form) and CaptchaGate (standalone "verify you're not a
 * robot" page shown before the login form). Both read the same
 * captcha_provider/captcha_site_key/captcha_secret_key settings, so the
 * verification call and markup live here once instead of twice.
 */
final class CaptchaVerifier {

	public static function provider( Module $module ): string {
		$p = (string) $module->setting( 'captcha_provider', 'turnstile' );
		return in_array( $p, array( 'turnstile', 'recaptcha_v2', 'recaptcha_v3' ), true ) ? $p : 'turnstile';
	}

	public static function site_key( Module $module ): string {
		return trim( (string) $module->setting( 'captcha_site_key', '' ) );
	}

	private static function secret_key( Module $module ): string {
		return trim( $module->captcha_secret_key() );
	}

	public static function is_configured( Module $module ): bool {
		return '' !== self::site_key( $module ) && '' !== self::secret_key( $module );
	}

	public static function get_client_ip(): string {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		return filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '';
	}

	/**
	 * Enqueue the provider's JS SDK. Safe to call from login_enqueue_scripts.
	 */
	public static function enqueue_provider_script( Module $module ): void {
		switch ( self::provider( $module ) ) {
			case 'recaptcha_v2':
				wp_enqueue_script( 'uxstudio-recaptcha', 'https://www.google.com/recaptcha/api.js', array(), null, true );
				break;
			case 'recaptcha_v3':
				wp_enqueue_script(
					'uxstudio-recaptcha',
					'https://www.google.com/recaptcha/api.js?render=' . rawurlencode( self::site_key( $module ) ),
					array(),
					null,
					true
				);
				break;
			default:
				wp_enqueue_script( 'uxstudio-turnstile', 'https://challenges.cloudflare.com/turnstile/v0/api.js', array(), null, true );
				break;
		}
	}

	/**
	 * Widget markup (checkbox / invisible token field, depending on provider).
	 */
	public static function render_widget( Module $module ): void {
		$provider = self::provider( $module );
		$site_key = self::site_key( $module );

		echo '<div class="uxstudio-captcha-field">';

		if ( 'recaptcha_v2' === $provider ) {
			printf( '<div class="g-recaptcha" data-sitekey="%s"></div>', esc_attr( $site_key ) );
		} elseif ( 'recaptcha_v3' === $provider ) {
			echo '<input type="hidden" name="g-recaptcha-response" class="uxstudio-recaptcha-v3-token" value="">';
		} else {
			printf( '<div class="cf-turnstile" data-sitekey="%s"></div>', esc_attr( $site_key ) );
		}

		echo '</div>';
	}

	/**
	 * reCAPTCHA v3 has no visible widget - it silently (re)executes on every
	 * form submit and drops the score token into a hidden field.
	 */
	public static function render_v3_footer( Module $module ): void {
		$site_key = self::site_key( $module );
		?>
		<script>
		(function () {
			if ( typeof grecaptcha === 'undefined' ) { return; }
			var siteKey = <?php echo wp_json_encode( $site_key ); ?>;
			function refresh() {
				grecaptcha.ready( function () {
					grecaptcha.execute( siteKey, { action: 'login' } ).then( function ( token ) {
						document.querySelectorAll( '.uxstudio-recaptcha-v3-token' ).forEach( function ( el ) {
							el.value = token;
						} );
					} );
				} );
			}
			refresh();
			document.addEventListener( 'submit', function () { refresh(); }, true );
			setInterval( refresh, 90000 );
		})();
		</script>
		<?php
	}

	/**
	 * Verify the token from $_POST against the provider's siteverify endpoint.
	 * Fails OPEN on a provider outage (5xx / network error) - a CAPTCHA outage
	 * must never turn into a site-wide login lockout.
	 */
	public static function verify_token( Module $module ): bool {
		$provider = self::provider( $module );

		if ( 'turnstile' === $provider ) {
			$token    = isset( $_POST['cf-turnstile-response'] ) ? sanitize_text_field( wp_unslash( $_POST['cf-turnstile-response'] ) ) : '';
			$endpoint = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';
		} else {
			$token    = isset( $_POST['g-recaptcha-response'] ) ? sanitize_text_field( wp_unslash( $_POST['g-recaptcha-response'] ) ) : '';
			$endpoint = 'https://www.google.com/recaptcha/api/siteverify';
		}

		if ( '' === $token ) {
			return false;
		}

		$response = wp_remote_post(
			$endpoint,
			array(
				'timeout' => 10,
				'body'    => array(
					'secret'   => self::secret_key( $module ),
					'response' => $token,
					'remoteip' => self::get_client_ip(),
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return true;
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $data ) || empty( $data['success'] ) ) {
			return false;
		}

		if ( 'recaptcha_v3' === $provider ) {
			$threshold = (float) $module->setting( 'captcha_recaptcha_v3_threshold', 0.5 );
			$score     = isset( $data['score'] ) ? (float) $data['score'] : 0.0;
			if ( $score < $threshold ) {
				return false;
			}
		}

		return true;
	}
}
