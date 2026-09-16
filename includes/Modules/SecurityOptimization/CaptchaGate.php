<?php
/**
 * Standalone "verify you're not a robot" page shown BEFORE wp-login.php,
 * instead of a CAPTCHA widget inline in the login form.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\SecurityOptimization;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Alternative to CaptchaHandler (captcha_placement=gate vs =inline) - ported
 * from the same pattern built for centrani-app's own login on 16.9.2026, on
 * the user's request: the visitor must pass a dedicated verification page
 * before wp-login.php renders anything, rather than solving a widget that
 * sits inside the credentials form.
 *
 * Because wp-admin redirects every logged-out request to wp-login.php, this
 * gate also covers the WordPress admin - not just the login form - for free.
 *
 * Verification is remembered via a signed, httponly SESSION cookie (cleared
 * when the browser closes, matching the "whole session" choice made for
 * centrani-app) capped at TTL as defense-in-depth against a copied cookie
 * value outliving a browser session that never really closes (sleep, etc.).
 * There is no server-side session in WordPress, so the cookie carries an
 * HMAC over its own issue time (wp_salt('auth')) instead of a stored token -
 * nothing to look up, nothing forgeable without the site's secret.
 */
final class CaptchaGate {

	private const COOKIE_NAME = 'uxstudio_human_verified';
	private const TTL         = 12 * HOUR_IN_SECONDS;
	private const NONCE_ACTION = 'uxstudio_human_verify';

	private Module $module;

	public function __construct( Module $module ) {
		$this->module = $module;
		$this->init();
	}

	private function init(): void {
		if ( ! CaptchaVerifier::is_configured( $this->module ) ) {
			return;
		}

		// Priority 5: after custom-login-url's block_direct_wp_login (priority 1),
		// so a 404'd slug still 404s instead of redirecting to the verify page.
		add_action( 'login_init', array( $this, 'maybe_gate' ), 5 );
		add_action( 'login_form_uxstudio_verify', array( $this, 'render_gate_page' ) );
		add_action( 'login_enqueue_scripts', array( $this, 'maybe_enqueue_script' ) );
	}

	/* ═══════════════════════════════════════════════════
	   Gate
	   ═══════════════════════════════════════════════════ */

	private function gated_actions(): array {
		/**
		 * Login form actions that require human verification first.
		 * 'register' is deliberately excluded by default - self-registration
		 * is off on most sites and, where it isn't, its own registration_errors
		 * CAPTCHA check (CaptchaHandler-style) is a smaller surface to gate.
		 *
		 * @param array<int,string> $actions wp-login.php $_REQUEST['action'] values.
		 */
		return (array) apply_filters( 'uxstudio_human_verify_gated_actions', array( 'login', 'lostpassword' ) );
	}

	private function current_action(): string {
		$action = isset( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( $_REQUEST['action'] ) ) : 'login'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return '' === $action ? 'login' : $action;
	}

	/**
	 * Full URL of the current request, built from scheme + host + REQUEST_URI -
	 * NOT via site_url($uri), which would double the path when WordPress lives
	 * in a subdirectory (REQUEST_URI already includes it).
	 */
	private function current_full_url(): string {
		// NOT sanitize_text_field() - it strips every %XX octet (header-injection
		// defense), which would mangle the percent-encoded nested redirect_to=
		// WordPress itself puts on this URL (e.g. wp-admin -> wp-login.php
		// redirect). This value is never echoed as HTML, only rawurlencode()'d
		// into our own query string and later re-validated by wp_safe_redirect()
		// / wp_validate_redirect() before it's ever used as a destination.
		$host = isset( $_SERVER['HTTP_HOST'] ) ? (string) wp_unslash( $_SERVER['HTTP_HOST'] ) : (string) wp_parse_url( home_url(), PHP_URL_HOST );
		$uri  = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : '/wp-login.php';
		$host = preg_replace( '/[^a-zA-Z0-9.\-:]/', '', $host );
		return ( is_ssl() ? 'https://' : 'http://' ) . $host . $uri;
	}

	/**
	 * Runs on 'login_init' - fires for every wp-login.php hit, GET or POST,
	 * before anything is rendered or authenticated. A bot POSTing credentials
	 * straight to wp-login.php without ever loading the form gets redirected
	 * away exactly like a normal GET visit - the POST body is simply dropped,
	 * matching the centrani-app behaviour this was ported from.
	 */
	public function maybe_gate(): void {
		if ( is_user_logged_in() ) {
			return;
		}

		$action = $this->current_action();
		if ( 'uxstudio_verify' === $action ) {
			return;
		}
		if ( ! in_array( $action, $this->gated_actions(), true ) ) {
			return;
		}
		if ( $this->is_verified() ) {
			return;
		}

		$verify_url = add_query_arg(
			array(
				'action' => 'uxstudio_verify',
				'r'      => rawurlencode( $this->current_full_url() ),
			),
			wp_login_url()
		);

		wp_safe_redirect( $verify_url );
		exit;
	}

	public function maybe_enqueue_script(): void {
		if ( 'uxstudio_verify' !== $this->current_action() ) {
			return;
		}
		CaptchaVerifier::enqueue_provider_script( $this->module );
		echo '<style>.uxstudio-captcha-field{margin:0 0 16px;display:flex;justify-content:center}</style>';
	}

	/**
	 * Renders the standalone verify page using WordPress's own login chrome
	 * (login_header()/login_footer()) so it looks native, then exits - this
	 * runs from login_form_uxstudio_verify, which WordPress calls for any
	 * unrecognised $action right before it would otherwise fall through to
	 * rendering the default login form.
	 */
	public function render_gate_page(): void {
		$return = wp_validate_redirect(
			isset( $_GET['r'] ) ? (string) wp_unslash( $_GET['r'] ) : '', // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			admin_url()
		);

		$error = null;

		if ( 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
			$nonce_ok = isset( $_POST['uxstudio_verify_nonce'] )
				&& wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['uxstudio_verify_nonce'] ) ), self::NONCE_ACTION );

			if ( ! $nonce_ok ) {
				$error = new WP_Error( 'uxstudio_verify_failed', __( 'Security check expired, please try again.', 'ux-studio' ) );
			} elseif ( ! CaptchaVerifier::verify_token( $this->module ) ) {
				$error = new WP_Error( 'uxstudio_verify_failed', __( 'Please confirm you are not a robot.', 'ux-studio' ) );
			} else {
				$this->set_verified_cookie();
				wp_safe_redirect( $return );
				exit;
			}
		}

		login_header( __( 'Human verification', 'ux-studio' ), '', $error );
		?>
		<form name="uxstudio-verify-form" id="uxstudio-verify-form" method="post"
			action="<?php echo esc_url( add_query_arg( array( 'action' => 'uxstudio_verify', 'r' => rawurlencode( $return ) ), wp_login_url() ) ); ?>">
			<p><?php esc_html_e( 'Please confirm you are not a robot before continuing to the login page.', 'ux-studio' ); ?></p>
			<?php CaptchaVerifier::render_widget( $this->module ); ?>
			<?php wp_nonce_field( self::NONCE_ACTION, 'uxstudio_verify_nonce' ); ?>
			<p class="submit">
				<input type="submit" name="wp-submit" id="wp-submit" class="button button-primary button-large"
					value="<?php esc_attr_e( 'Continue', 'ux-studio' ); ?>">
			</p>
		</form>
		<p id="backtoblog">
			<a href="<?php echo esc_url( home_url( '/' ) ); ?>">
				<?php
				/* translators: %s: site title. */
				echo esc_html( sprintf( __( '&larr; Go to %s', 'ux-studio' ), get_bloginfo( 'title', 'display' ) ) );
				?>
			</a>
		</p>
		<?php
		if ( 'recaptcha_v3' === CaptchaVerifier::provider( $this->module ) ) {
			CaptchaVerifier::render_v3_footer( $this->module );
		}
		login_footer();
		exit;
	}

	/* ═══════════════════════════════════════════════════
	   Verified-cookie (no server session in WordPress)
	   ═══════════════════════════════════════════════════ */

	private function sign( string $issued ): string {
		return hash_hmac( 'sha256', self::NONCE_ACTION . '|' . $issued, wp_salt( 'auth' ) );
	}

	private function is_verified(): bool {
		$cookie = isset( $_COOKIE[ self::COOKIE_NAME ] ) ? (string) wp_unslash( $_COOKIE[ self::COOKIE_NAME ] ) : '';
		if ( '' === $cookie || false === strpos( $cookie, '.' ) ) {
			return false;
		}
		list( $issued, $sig ) = explode( '.', $cookie, 2 );
		if ( ! ctype_digit( $issued ) ) {
			return false;
		}
		if ( (int) $issued + self::TTL < time() ) {
			return false;
		}
		return hash_equals( $this->sign( $issued ), $sig );
	}

	private function set_verified_cookie(): void {
		$issued = (string) time();
		$value  = $issued . '.' . $this->sign( $issued );

		// expires=0 -> session cookie, cleared when the browser actually closes;
		// the HMAC TTL above is the hard cap for a session that never does.
		setcookie(
			self::COOKIE_NAME,
			$value,
			array(
				'expires'  => 0,
				'path'     => defined( 'COOKIEPATH' ) && COOKIEPATH ? COOKIEPATH : '/',
				'domain'   => defined( 'COOKIE_DOMAIN' ) && COOKIE_DOMAIN ? COOKIE_DOMAIN : '',
				'secure'   => is_ssl(),
				'httponly' => true,
				'samesite' => 'Lax',
			)
		);
		$_COOKIE[ self::COOKIE_NAME ] = $value;
	}
}
