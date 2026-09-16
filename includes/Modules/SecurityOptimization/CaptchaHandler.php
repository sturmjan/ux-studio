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
 * Inline placement: renders the CAPTCHA widget INSIDE the standard WordPress
 * login/register/lost-password forms. See CaptchaGate for the alternative
 * "standalone page before the form" placement (captcha_placement=gate) -
 * Module::boot() instantiates exactly one of the two, never both.
 *
 * Provider/verification plumbing lives in CaptchaVerifier, shared with
 * CaptchaGate.
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
		if ( ! CaptchaVerifier::is_configured( $this->module ) ) {
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

		if ( 'recaptcha_v3' === CaptchaVerifier::provider( $this->module ) ) {
			add_action( 'login_footer', array( $this, 'render_v3_footer' ) );
		}
	}

	/* ═══════════════════════════════════════════════════
	   Adaptive mode - failure counter
	   ═══════════════════════════════════════════════════ */

	private function should_enforce(): bool {
		$mode = (string) $this->module->setting( 'captcha_mode', 'always' );
		if ( 'adaptive' !== $mode ) {
			return true;
		}

		$threshold = max( 1, (int) $this->module->setting( 'captcha_adaptive_threshold', 2 ) );
		return $this->get_failure_count() >= $threshold;
	}

	private function fail_transient_key(): string {
		return self::FAIL_TRANSIENT_PREFIX . md5( CaptchaVerifier::get_client_ip() );
	}

	private function get_failure_count(): int {
		return (int) get_transient( $this->fail_transient_key() );
	}

	public function record_failure(): void {
		if ( 'adaptive' !== (string) $this->module->setting( 'captcha_mode', 'always' ) ) {
			return;
		}
		if ( '' === CaptchaVerifier::get_client_ip() ) {
			return;
		}
		set_transient( $this->fail_transient_key(), $this->get_failure_count() + 1, self::FAIL_WINDOW );
	}

	public function clear_failures(): void {
		if ( '' === CaptchaVerifier::get_client_ip() ) {
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
		CaptchaVerifier::enqueue_provider_script( $this->module );
		echo '<style>.uxstudio-captcha-field{margin:0 0 16px}</style>';
	}

	public function render_widget(): void {
		CaptchaVerifier::render_widget( $this->module );
	}

	public function render_v3_footer(): void {
		CaptchaVerifier::render_v3_footer( $this->module );
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

		if ( ! CaptchaVerifier::verify_token( $this->module ) ) {
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
		if ( ! CaptchaVerifier::verify_token( $this->module ) ) {
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
		if ( ! CaptchaVerifier::verify_token( $this->module ) ) {
			$errors->add( 'uxstudio_captcha_failed', __( '<strong>Error:</strong> CAPTCHA verification failed. Please try again.', 'ux-studio' ) );
		}
	}
}
