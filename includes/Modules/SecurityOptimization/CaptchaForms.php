<?php
/**
 * CAPTCHA for the comment form and the WooCommerce forms.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\SecurityOptimization;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * The forms beyond wp-login.php (login, registration, lost password: CaptchaHandler / CaptchaGate).
 * They are chosen in `captcha_forms` next to the login forms and work with both placements, because the
 * standalone gate page exists only for wp-login.php. Every form is verified on the server; the widget is
 * only the way to get a token.
 *
 *  - `comment`            comment form (and anonymous comments through the REST API)
 *  - `woo_login`          WooCommerce My Account login
 *  - `woo_register`       WooCommerce My Account registration
 *  - `woo_lostpassword`   WooCommerce lost password
 *  - `woo_checkout`       classic WooCommerce checkout (the block checkout has no hook for a widget)
 *
 * Logged-in users are never asked (comments, checkout), and nothing here runs in wp-admin, so a CAPTCHA
 * problem can never lock an administrator out of the dashboard.
 */
final class CaptchaForms {

	public const FORMS = array( 'comment', 'woo_login', 'woo_register', 'woo_lostpassword', 'woo_checkout' );

	private Module $module;

	/** Whether a widget was printed on this page (the provider script and the reset helper go to the footer). */
	private bool $rendered = false;

	public function __construct( Module $module ) {
		$this->module = $module;
		$this->init();
	}

	/**
	 * Selected extra forms. Unlike the login forms, an unsaved setting means none.
	 *
	 * @return string[]
	 */
	public static function selected( Module $module ): array {
		return array_values( array_intersect( self::FORMS, array_map( 'strval', (array) $module->setting( 'captcha_forms', array() ) ) ) );
	}

	private function init(): void {
		if ( ! CaptchaVerifier::is_configured( $this->module ) ) {
			return;
		}
		$forms = self::selected( $this->module );
		if ( ! $forms ) {
			return;
		}

		if ( in_array( 'comment', $forms, true ) ) {
			add_filter( 'comment_form_submit_field', array( $this, 'comment_widget' ), 5 );
			add_action( 'pre_comment_on_post', array( $this, 'verify_comment' ), 1 );
			add_filter( 'rest_pre_insert_comment', array( $this, 'verify_rest_comment' ), 5, 2 );
		}
		if ( in_array( 'woo_login', $forms, true ) ) {
			add_action( 'woocommerce_login_form', fn() => $this->widget( 'woo_login' ) );
			add_filter( 'woocommerce_process_login_errors', array( $this, 'verify_woo_errors' ), 5 );
		}
		if ( in_array( 'woo_register', $forms, true ) ) {
			add_action( 'woocommerce_register_form', fn() => $this->widget( 'woo_register' ) );
			add_filter( 'woocommerce_process_registration_errors', array( $this, 'verify_woo_errors' ), 5 );
		}
		if ( in_array( 'woo_lostpassword', $forms, true ) ) {
			add_action( 'woocommerce_lostpassword_form', fn() => $this->widget( 'woo_lostpassword' ) );
			add_action( 'lostpassword_post', array( $this, 'verify_woo_lostpassword' ), 4, 1 );
		}
		if ( in_array( 'woo_checkout', $forms, true ) ) {
			add_action( 'woocommerce_after_order_notes', array( $this, 'checkout_widget' ) );
			add_action( 'woocommerce_checkout_process', array( $this, 'verify_checkout' ), 1 );
		}

		add_action( 'wp_footer', array( $this, 'footer' ), 5 );
	}

	/* ═══════════════════════════════════════════════════
	   Widget
	   ═══════════════════════════════════════════════════ */

	/**
	 * Print the widget (frontend, visitors only).
	 */
	public function widget( string $context = 'comment' ): void {
		if ( is_admin() || is_user_logged_in() ) {
			return;
		}
		$this->rendered = true;
		CaptchaVerifier::enqueue_provider_script( $this->module );
		CaptchaVerifier::render_widget( $this->module, $context );
	}

	/**
	 * Widget in front of the submit button of the comment form.
	 *
	 * @param string $submit_field Submit button markup.
	 */
	public function comment_widget( $submit_field ): string {
		ob_start();
		$this->widget( 'comment' );
		return ob_get_clean() . (string) $submit_field;
	}

	/**
	 * Widget on the classic checkout, outside the part WooCommerce re-renders on every change of the order.
	 */
	public function checkout_widget(): void {
		$this->widget( 'woo_checkout' );
	}

	/**
	 * Footer: the reCAPTCHA v3 token script, and a reset of the widget after the checkout reports an error
	 * (the token is single use, the next attempt needs a new one).
	 */
	public function footer(): void {
		if ( ! $this->rendered ) {
			return;
		}
		if ( 'recaptcha_v3' === CaptchaVerifier::provider( $this->module ) ) {
			CaptchaVerifier::render_v3_footer( $this->module );
		}
		?>
		<script>
		(function () {
			var reset = function () {
				if ( window.turnstile && typeof window.turnstile.reset === 'function' ) { window.turnstile.reset(); }
				if ( window.UxStudioShield ) { window.UxStudioShield.reset(); }
				if ( window.grecaptcha && typeof window.grecaptcha.reset === 'function' ) { try { window.grecaptcha.reset(); } catch ( e ) {} }
			};
			if ( window.jQuery ) { window.jQuery( document.body ).on( 'checkout_error', reset ); }
		})();
		</script>
		<style>.uxstudio-captcha-field{margin:0 0 16px}</style>
		<?php
	}

	/* ═══════════════════════════════════════════════════
	   Verification
	   ═══════════════════════════════════════════════════ */

	private function failed_text(): string {
		return __( 'CAPTCHA verification failed. Please try again.', 'ux-studio' );
	}

	/**
	 * Comment form: before WordPress processes the comment.
	 */
	public function verify_comment(): void {
		if ( is_user_logged_in() || CaptchaVerifier::verify_token( $this->module, null, 'comment' ) ) {
			return;
		}
		wp_die(
			'<p><strong>' . esc_html__( 'Error:', 'ux-studio' ) . '</strong> ' . esc_html( $this->failed_text() ) . '</p>',
			esc_html__( 'Comment Submission Failure', 'ux-studio' ),
			array(
				'response'  => 403,
				'back_link' => true,
			)
		);
	}

	/**
	 * Anonymous comment through the REST API: the token is sent as the request parameter of the provider
	 * (`cf-turnstile-response` / `g-recaptcha-response`). Without it the comment is refused.
	 *
	 * @param \stdClass|WP_Error $prepared Comment about to be inserted.
	 * @param \WP_REST_Request   $request  Request.
	 * @return \stdClass|WP_Error
	 */
	public function verify_rest_comment( $prepared, $request ) {
		if ( is_wp_error( $prepared ) || is_user_logged_in() ) {
			return $prepared;
		}
		$token = (string) $request->get_param( CaptchaVerifier::token_field( $this->module ) );
		if ( CaptchaVerifier::verify_token( $this->module, sanitize_text_field( $token ), 'comment' ) ) {
			return $prepared;
		}
		return new WP_Error( 'uxstudio_captcha_failed', $this->failed_text(), array( 'status' => 403 ) );
	}

	/**
	 * WooCommerce login and registration: they pass a WP_Error through the filter.
	 *
	 * @param WP_Error $errors Validation errors so far.
	 */
	public function verify_woo_errors( $errors ) {
		if ( ! ( $errors instanceof WP_Error ) || is_user_logged_in() ) {
			return $errors;
		}
		if ( ! CaptchaVerifier::verify_token( $this->module, null, isset( $_POST['register'] ) ? 'woo_register' : 'woo_login' ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$errors->add( 'uxstudio_captcha_failed', $this->failed_text() );
		}
		return $errors;
	}

	/**
	 * WooCommerce lost password goes through core's `lostpassword_post`; only its own form is checked here
	 * (the WordPress form is the business of CaptchaHandler / CaptchaGate).
	 *
	 * @param WP_Error $errors Errors of the request.
	 */
	public function verify_woo_lostpassword( $errors ): void {
		if ( ! ( $errors instanceof WP_Error ) || ! isset( $_POST['wc_reset_password'] ) || is_user_logged_in() ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			return;
		}
		if ( ! CaptchaVerifier::verify_token( $this->module, null, 'woo_lostpassword' ) ) {
			$errors->add( 'uxstudio_captcha_failed', $this->failed_text() );
		}
	}

	/**
	 * Classic checkout: an error notice stops the order.
	 */
	public function verify_checkout(): void {
		if ( is_user_logged_in() || CaptchaVerifier::verify_token( $this->module, null, 'woo_checkout' ) ) {
			return;
		}
		if ( function_exists( 'wc_add_notice' ) ) {
			wc_add_notice( $this->failed_text(), 'error' );
		}
	}
}
