<?php
/**
 * Third-party login - "link your account" admin notice and login-screen errors.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\ThirdPartyLogin;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Ported from the legacy third-party-login module:
 *  - renderDashboardNotice / ajaxDismissNotice: dismissible notice asking users
 *    without a linked provider to link one (notice_enabled / notice_text);
 *  - renderLoginError: shows the opaque `uxstudio_tpl_error` code that
 *    RestController::fail() / Module::maybe_start_handshake() redirect with.
 */
final class Notice {

	private const AJAX_ACTION    = 'uxstudio_tpl_dismiss_notice';
	private const NONCE_ACTION   = 'uxstudio_tpl_dismiss_notice';
	private const META_DISMISSED = 'uxstudio_tpl_notice_dismissed';
	private const LEGACY_META    = 'ux1_tpl_notice_dismissed';

	private Module $module;

	/**
	 * @param Module $module Owning module.
	 */
	public function __construct( Module $module ) {
		$this->module = $module;
	}

	/**
	 * Hook everything.
	 */
	public function boot(): void {
		add_filter( 'wp_login_errors', array( $this, 'add_login_error' ) );
		add_action( 'admin_notices', array( $this, 'render_notice' ) );
		add_action( 'wp_ajax_' . self::AJAX_ACTION, array( $this, 'ajax_dismiss' ) );
	}

	/**
	 * Default notice text.
	 */
	public static function default_text(): string {
		return __( 'For faster sign-in, link your account with an external provider in your profile settings.', 'ux-studio' );
	}

	/**
	 * Static, trusted message for each error code (the code is only used as a
	 * lookup key, never printed).
	 *
	 * @return array<string,string>
	 */
	private static function error_messages(): array {
		return array(
			'notlinked'         => __( 'This account is not linked to any user on this site. Sign in with your password first and link the account in your profile.', 'ux-studio' ),
			'role'              => __( 'Your role is not allowed to sign in through a third party.', 'ux-studio' ),
			'placeholder_email' => __( 'The provider did not send your email address. If you already have an account here, sign in with your password and link it in your profile.', 'ux-studio' ),
			'email_taken'       => __( 'This email is already registered. Sign in with your password first and link the provider in your profile.', 'ux-studio' ),
			'sub_taken'         => __( 'This external account is already linked to a different user.', 'ux-studio' ),
			'expired'           => __( 'The sign-in request expired. Please try again.', 'ux-studio' ),
			'session'           => __( 'The sign-in request does not belong to this browser. Please try again.', 'ux-studio' ),
			'signature'         => __( 'The sign-in response could not be verified.', 'ux-studio' ),
			'provider'          => __( 'This sign-in provider is not available.', 'ux-studio' ),
			'rate'              => __( 'Too many sign-in attempts. Please wait a few minutes and try again.', 'ux-studio' ),
			'config'            => __( 'Third-party sign-in is not configured on this site.', 'ux-studio' ),
			'invalid'           => __( 'The sign-in request was invalid. Please try again.', 'ux-studio' ),
		);
	}

	/**
	 * Add the error to the wp-login.php notices.
	 *
	 * @param mixed $errors Login errors.
	 * @return mixed
	 */
	public function add_login_error( $errors ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only; the code is only a lookup key.
		if ( ! $errors instanceof WP_Error || empty( $_GET['uxstudio_tpl_error'] ) ) {
			return $errors;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$code     = sanitize_key( wp_unslash( $_GET['uxstudio_tpl_error'] ) );
		$messages = self::error_messages();
		$text     = $messages[ $code ] ?? __( 'Sign-in failed. Please try again.', 'ux-studio' );

		$errors->add( 'uxstudio_tpl_error', esc_html( $text ) );
		return $errors;
	}

	/**
	 * Print the "link your account" notice.
	 */
	public function render_notice(): void {
		if ( ! is_user_logged_in() ) {
			return;
		}
		if ( ! $this->module->notice_enabled() || '' === $this->module->central_app_url() || ! $this->module->has_secret() ) {
			return;
		}

		$user = wp_get_current_user();
		if ( ! $this->module->is_user_allowed( $user ) ) {
			return;
		}
		if ( get_user_meta( $user->ID, self::META_DISMISSED, true ) || get_user_meta( $user->ID, self::LEGACY_META, true ) ) {
			return;
		}

		$providers = $this->module->enabled_providers();
		if ( empty( $providers ) ) {
			return;
		}
		foreach ( $providers as $provider ) {
			if ( get_user_meta( $user->ID, $this->module->sub_meta_key( $provider ), true ) ) {
				return; // Already linked to at least one enabled provider.
			}
		}

		$text = trim( $this->module->notice_text() );
		if ( '' === $text ) {
			$text = self::default_text();
		}
		$labels = $this->module->provider_labels();
		?>
		<div class="notice notice-info is-dismissible" id="uxstudio-tpl-notice" data-nonce="<?php echo esc_attr( wp_create_nonce( self::NONCE_ACTION ) ); ?>">
			<p style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;margin:.5em 0">
				<span><?php echo esc_html( $text ); ?></span>
				<?php foreach ( $providers as $provider ) : ?>
					<button type="button" class="button button-small uxstudio-tpl-link" data-provider="<?php echo esc_attr( $provider ); ?>">
						<?php echo esc_html( $labels[ $provider ] ?? $provider ); ?>
					</button>
				<?php endforeach; ?>
			</p>
		</div>
		<script>
		( function () {
			var box = document.getElementById( 'uxstudio-tpl-notice' );
			if ( ! box ) { return; }
			box.addEventListener( 'click', function ( e ) {
				var link = e.target.closest( '.uxstudio-tpl-link' );
				if ( link ) {
					link.disabled = true;
					fetch( <?php echo wp_json_encode( esc_url_raw( rest_url( 'uxstudio/v1/third-party-login/link/' ) ) ); ?> + encodeURIComponent( link.getAttribute( 'data-provider' ) ), {
						method: 'POST',
						credentials: 'same-origin',
						headers: { 'X-WP-Nonce': <?php echo wp_json_encode( wp_create_nonce( 'wp_rest' ) ); ?>, 'Content-Type': 'application/json' },
						body: '{}'
					} ).then( function ( r ) { return r.json(); } ).then( function ( j ) {
						if ( j && j.data && j.data.redirect ) { window.location.href = j.data.redirect; return; }
						link.disabled = false;
					} ).catch( function () { link.disabled = false; } );
					return;
				}
				if ( e.target.classList.contains( 'notice-dismiss' ) ) {
					var d = new FormData();
					d.append( 'action', <?php echo wp_json_encode( self::AJAX_ACTION ); ?> );
					d.append( 'nonce', box.getAttribute( 'data-nonce' ) );
					fetch( <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>, { method: 'POST', body: d, credentials: 'same-origin' } );
				}
			} );
		} )();
		</script>
		<?php
	}

	/**
	 * AJAX: remember that this user dismissed the notice.
	 */
	public function ajax_dismiss(): void {
		if ( ! is_user_logged_in() ) {
			wp_send_json_error( 'not_logged_in', 403 );
		}
		if ( ! check_ajax_referer( self::NONCE_ACTION, 'nonce', false ) ) {
			wp_send_json_error( 'bad_nonce', 400 );
		}
		update_user_meta( get_current_user_id(), self::META_DISMISSED, 1 );
		wp_send_json_success();
	}
}
