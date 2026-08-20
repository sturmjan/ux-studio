<?php
/**
 * CAPTCHA protection for login / register / lost-password forms.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\SecurityOptimization;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Ported from the legacy CaptchaHandler. Supports Cloudflare Turnstile and
 * Google reCAPTCHA v2/v3, switchable in settings. The site key is public
 * (plain setting); the secret key is stored via Security::store_secret() and
 * is only ever read server-side through Module::captcha_secret_key() -
 * never exposed through REST.
 */
final class CaptchaHandler {

	private const FAIL_TRANSIENT_PREFIX = 'uxstudio_captcha_fail_';
	private const FAIL_WINDOW           = 900; // 15 min.

	private Module $module;

	public function __construct( Module $module ) {
		$this->module = $module;
		$this->init();
	}

	private function init(): void {
		if ( ! $this->is_configured() ) {
			return;
		}

		add_action( 'login_enqueue_scripts', array( $this, 'enqueue_provider_script' ) );
		add_action( 'login_form', array( $this, 'render_widget' ) );
		// Priority 40: after AttemptsHandler's authenticate filter (30), so the
		// attempt limit is checked first and CAPTCHA only kicks in afterwards.
		add_filter( 'authenticate', array( $this, 'verify_login' ), 40, 3 );

		add_action( 'register_form', array( $this, 'render_widget' ) );
		add_filter( 'registration_errors', array( $this, 'verify_registration' ), 10, 3 );

		add_action( 'lostpassword_form', array( $this, 'render_widget' ) );
		add_action( 'lostpassword_post', array( $this, 'verify_lost_password' ), 10, 1 );

		add_action( 'wp_login_failed', array( $this, 'record_failure' ) );
		add_action( 'wp_login', array( $this, 'clear_failures' ), 10, 0 );

		if ( 'recaptcha_v3' === $this->provider() ) {
			add_action( 'login_footer', array( $this, 'render_v3_footer' ) );
		}
	}

	/* ═══════════════════════════════════════════════════
	   Settings / helpers
	   ═══════════════════════════════════════════════════ */

	private function provider(): string {
		$p = (string) $this->module->setting( 'captcha_provider', 'turnstile' );
		return in_array( $p, array( 'turnstile', 'recaptcha_v2', 'recaptcha_v3' ), true ) ? $p : 'turnstile';
	}

	private function site_key(): string {
		return trim( (string) $this->module->setting( 'captcha_site_key', '' ) );
	}

	private function secret_key(): string {
		return trim( $this->module->captcha_secret_key() );
	}

	private function is_configured(): bool {
		return '' !== $this->site_key() && '' !== $this->secret_key();
	}

	private function should_enforce(): bool {
		$mode = (string) $this->module->setting( 'captcha_mode', 'always' );
		if ( 'adaptive' !== $mode ) {
			return true;
		}

		$threshold = max( 1, (int) $this->module->setting( 'captcha_adaptive_threshold', 2 ) );
		return $this->get_failure_count() >= $threshold;
	}

	private function get_client_ip(): string {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		return filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '';
	}

	private function fail_transient_key(): string {
		return self::FAIL_TRANSIENT_PREFIX . md5( $this->get_client_ip() );
	}

	private function get_failure_count(): int {
		return (int) get_transient( $this->fail_transient_key() );
	}

	public function record_failure(): void {
		if ( 'adaptive' !== (string) $this->module->setting( 'captcha_mode', 'always' ) ) {
			return;
		}
		if ( '' === $this->get_client_ip() ) {
			return;
		}
		set_transient( $this->fail_transient_key(), $this->get_failure_count() + 1, self::FAIL_WINDOW );
	}

	public function clear_failures(): void {
		if ( '' === $this->get_client_ip() ) {
			return;
		}
		delete_transient( $this->fail_transient_key() );
	}

	private function is_interactive_login_post(): bool {
		return isset( $_POST['log'] ) && 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? '' );
	}

	/* ═══════════════════════════════════════════════════
	   Front-end
	   ═══════════════════════════════════════════════════ */

	public function enqueue_provider_script(): void {
		switch ( $this->provider() ) {
			case 'recaptcha_v2':
				wp_enqueue_script( 'uxstudio-recaptcha', 'https://www.google.com/recaptcha/api.js', array(), null, true );
				break;
			case 'recaptcha_v3':
				wp_enqueue_script(
					'uxstudio-recaptcha',
					'https://www.google.com/recaptcha/api.js?render=' . rawurlencode( $this->site_key() ),
					array(),
					null,
					true
				);
				break;
			default:
				wp_enqueue_script( 'uxstudio-turnstile', 'https://challenges.cloudflare.com/turnstile/v0/api.js', array(), null, true );
				break;
		}

		echo '<style>.uxstudio-captcha-field{margin:0 0 16px}</style>';
	}

	public function render_widget(): void {
		$provider = $this->provider();
		$site_key = $this->site_key();

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

	public function render_v3_footer(): void {
		$site_key = $this->site_key();
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

	/* ═══════════════════════════════════════════════════
	   Verification
	   ═══════════════════════════════════════════════════ */

	/**
	 * @param \WP_User|WP_Error|null $user     Incoming authenticate() value.
	 * @param string                 $username Attempted username.
	 * @param string                 $password Attempted password (unused).
	 * @return \WP_User|WP_Error|null
	 */
	public function verify_login( $user, $username, $password ) {
		if ( is_wp_error( $user ) || ! $this->is_interactive_login_post() ) {
			return $user;
		}
		if ( empty( $username ) || ! $this->should_enforce() ) {
			return $user;
		}

		if ( ! $this->verify_token() ) {
			return new WP_Error( 'uxstudio_captcha_failed', __( 'CAPTCHA verification failed. Please try again.', 'ux-studio' ) );
		}

		return $user;
	}

	/**
	 * @param WP_Error $errors                Registration errors.
	 * @param string   $sanitized_user_login  Sanitized username (unused).
	 * @param string   $user_email            Email (unused).
	 */
	public function verify_registration( $errors, $sanitized_user_login, $user_email ) {
		if ( ! $this->verify_token() ) {
			$errors->add( 'uxstudio_captcha_failed', __( '<strong>Error:</strong> CAPTCHA verification failed. Please try again.', 'ux-studio' ) );
		}
		return $errors;
	}

	/**
	 * @param WP_Error $errors Lost-password errors.
	 */
	public function verify_lost_password( $errors ): void {
		if ( ! ( $errors instanceof WP_Error ) ) {
			return;
		}
		if ( ! $this->verify_token() ) {
			$errors->add( 'uxstudio_captcha_failed', __( '<strong>Error:</strong> CAPTCHA verification failed. Please try again.', 'ux-studio' ) );
		}
	}

	private function verify_token(): bool {
		$provider = $this->provider();

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
					'secret'   => $this->secret_key(),
					'response' => $token,
					'remoteip' => $this->get_client_ip(),
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			// Fail-open on provider outage - a CAPTCHA outage must not lock out login.
			return true;
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $data ) || empty( $data['success'] ) ) {
			return false;
		}

		if ( 'recaptcha_v3' === $provider ) {
			$threshold = (float) $this->module->setting( 'captcha_recaptcha_v3_threshold', 0.5 );
			$score     = isset( $data['score'] ) ? (float) $data['score'] : 0.0;
			if ( $score < $threshold ) {
				return false;
			}
		}

		return true;
	}
}
