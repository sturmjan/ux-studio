<?php
/**
 * Two-factor authentication (TOTP): the second login step, the profile screen, role requirement.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\SecurityOptimization;

use UxStudio\Core\ActivityLog;
use UxStudio\Core\Totp;
use WP_Error;
use WP_User;

defined( 'ABSPATH' ) || exit;

/**
 * How the login works (PLAN.md 27, A1):
 *
 *  1. The user sends the login form to wp-login.php (or to the custom login address). The password is
 *     checked by core as always, after the lockout and the CAPTCHA (`authenticate` runs them first).
 *  2. This class hooks `authenticate` as the very last filter. When the user has a second factor and the
 *     request is that login form, it shows the second step INSTEAD of logging in: no cookie is set yet,
 *     `wp_login` has not fired, the waiting login is only a hashed token in user meta (10 minutes).
 *  3. The second step posts to `?action=uxstudio_2fa` (`login_form_uxstudio_2fa`, so the custom login
 *     address keeps working). A valid app code or recovery code sets the cookie and fires `wp_login`
 *     (the lockout counters are reset only now), then the usual `login_redirect`.
 *  4. Any other way to log in with a password (WooCommerce form, AJAX login, XML-RPC) is refused for an
 *     account with a second factor, fail closed. Application passwords (REST, XML-RPC) are not affected:
 *     they are separate credentials and never reach this filter as a password login.
 *
 * Wrong codes: 5 per 10 minutes per user, then the account waits out the window (logged). The kill switch
 * `UXSTUDIO_DISABLE_2FA` in wp-config.php switches the whole feature off (see TwoFactorStore::killed()).
 */
final class TwoFactor {

	private Module $module;

	/** Set by core when the password that just passed was an application password (REST, XML-RPC). */
	private static bool $app_password = false;

	/** What the role requirement decided at this login (`grace` | `forced` | ''), read by login_redirect. */
	private static string $setup_state = '';

	public function __construct( Module $module ) {
		$this->module = $module;
	}

	/**
	 * Hook everything when the feature is on and the kill switch is not set.
	 * Must run after the CAPTCHA and lockout filters are registered (same priority, registration order).
	 *
	 * @param Module $module Security module.
	 */
	public static function register( Module $module ): void {
		if ( ! TwoFactorStore::active( (bool) $module->setting( 'two_factor_enabled', false ) ) ) {
			return;
		}
		$self = new self( $module );

		add_action( 'application_password_did_authenticate', array( self::class, 'mark_application_password' ) );
		add_filter( 'authenticate', array( $self, 'gate' ), PHP_INT_MAX, 1 );
		add_action( 'login_form_uxstudio_2fa', array( $self, 'second_step' ) );
		add_filter( 'login_message', array( $self, 'login_message' ) );
		add_action( 'wp_login', array( $self, 'after_login' ), 10, 2 );
		add_filter( 'login_redirect', array( $self, 'login_redirect' ), 99, 3 );

		add_action( 'admin_init', array( $self, 'enforce_setup' ) );
		add_action( 'admin_notices', array( $self, 'setup_notice' ) );
		add_action( 'admin_notices', array( $self, 'reset_notice' ) );

		add_action( 'show_user_profile', array( $self, 'render_profile' ) );
		add_action( 'edit_user_profile', array( $self, 'render_profile' ) );
		add_action( 'wp_ajax_uxstudio_2fa', array( $self, 'ajax' ) );
		add_action( 'admin_post_uxstudio_2fa_reset', array( $self, 'handle_reset' ) );
		add_filter( 'user_row_actions', array( $self, 'row_actions' ), 10, 2 );
		add_filter( 'manage_users_columns', array( $self, 'users_column' ) );
		add_filter( 'manage_users_custom_column', array( $self, 'users_column_value' ), 10, 3 );
	}

	/**
	 * Core says: this login was an application password.
	 */
	public static function mark_application_password(): void {
		self::$app_password = true;
	}

	/**
	 * Activity Log entry about a user's second factor.
	 *
	 * @param string               $action Event key.
	 * @param int                  $uid    User the event is about.
	 * @param array<string, mixed> $meta   Context (never a secret or a code).
	 */
	public static function log( string $action, int $uid, array $meta = array() ): void {
		$user = get_userdata( $uid );
		ActivityLog::log( 'security-optimization', $action, 'user', $uid, $meta + array( 'login' => $user ? $user->user_login : '' ) );
	}

	/**
	 * Remove the second factor of a user (own switch-off after re-authentication, or an administrator's reset).
	 *
	 * @param int  $uid      Target user.
	 * @param bool $by_admin Whether somebody else did it.
	 */
	public static function reset_user( int $uid, bool $by_admin ): void {
		TwoFactorStore::disable( $uid );
		self::log( $by_admin ? 'two_factor_reset' : 'two_factor_disabled', $uid, $by_admin ? array( 'by' => get_current_user_id() ) : array() );
	}

	/* ═══════════════════════════════════════════════════
	   Login
	   ═══════════════════════════════════════════════════ */

	private function is_login_form_post(): bool {
		return did_action( 'login_init' ) > 0 && 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? '' ) && isset( $_POST['log'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
	}

	/**
	 * Last word of the `authenticate` chain. Anything but a user that passed everything is left alone.
	 *
	 * @param WP_User|WP_Error|null $user Result of the chain so far.
	 * @return WP_User|WP_Error|null
	 */
	public function gate( $user ) {
		$via_app_password = self::$app_password;
		self::$app_password = false;

		if ( ! ( $user instanceof WP_User ) || $via_app_password || ! TwoFactorStore::is_enabled( $user->ID ) ) {
			return $user;
		}

		if ( ! $this->is_login_form_post() ) {
			return new WP_Error(
				'uxstudio_2fa_required',
				__( '<strong>Error:</strong> This account uses two-step verification. Sign in on the WordPress login page.', 'ux-studio' )
			);
		}

		$token = TwoFactorStore::issue_login_token( $user->ID );
		$this->render_challenge( $user, $token, null );
		exit;
	}

	/**
	 * The second step page (inside wp-login.php, so its look and the custom login address are kept).
	 *
	 * @param WP_User       $user  User whose password was right.
	 * @param string        $token Token of the waiting login.
	 * @param WP_Error|null $error Message above the form.
	 */
	private function render_challenge( WP_User $user, string $token, ?WP_Error $error ): void {
		// phpcs:disable WordPress.Security.NonceVerification, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$redirect_to = isset( $_REQUEST['redirect_to'] ) ? esc_url_raw( wp_unslash( $_REQUEST['redirect_to'] ) ) : '';
		$remember    = ! empty( $_REQUEST['rememberme'] );
		// phpcs:enable
		$action = add_query_arg( 'action', 'uxstudio_2fa', wp_login_url() );

		// The module's "generic login error" filter would turn "the code is not correct" into "check your user name
		// and password"; here the password is known to be right, so the real message is shown.
		remove_filter( 'login_errors', array( $this->module, 'sanitize_login_errors' ) );

		nocache_headers();
		login_header(
			__( 'Two-step verification', 'ux-studio' ),
			'<p class="message">' . esc_html__( 'Enter the 6-digit code from your authenticator app. Lost the phone? Use one of your recovery codes instead.', 'ux-studio' ) . '</p>',
			$error
		);
		?>
		<form name="uxstudio_2fa_form" id="uxstudio_2fa_form" action="<?php echo esc_url( $action ); ?>" method="post" autocomplete="off">
			<p>
				<label for="uxstudio_2fa_code"><?php esc_html_e( 'Code or recovery code', 'ux-studio' ); ?></label>
				<input type="text" name="uxstudio_2fa_code" id="uxstudio_2fa_code" class="input" value="" size="20" autocomplete="one-time-code" autocapitalize="off" spellcheck="false" required />
			</p>
			<input type="hidden" name="uxstudio_2fa_user" value="<?php echo (int) $user->ID; ?>" />
			<input type="hidden" name="uxstudio_2fa_token" value="<?php echo esc_attr( $token ); ?>" />
			<input type="hidden" name="redirect_to" value="<?php echo esc_attr( $redirect_to ); ?>" />
			<?php if ( $remember ) : ?>
				<input type="hidden" name="rememberme" value="forever" />
			<?php endif; ?>
			<p class="submit">
				<input type="submit" name="wp-submit" id="wp-submit" class="button button-primary button-large" value="<?php esc_attr_e( 'Verify', 'ux-studio' ); ?>" />
			</p>
		</form>
		<p id="nav"><a href="<?php echo esc_url( wp_login_url() ); ?>"><?php esc_html_e( 'Back to the login', 'ux-studio' ); ?></a></p>
		<?php
		login_footer( 'uxstudio_2fa_code' );
	}

	/**
	 * `login_form_uxstudio_2fa`: validate the second step and finish the login.
	 */
	public function second_step(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) || ! isset( $_POST['uxstudio_2fa_user'], $_POST['uxstudio_2fa_token'] ) ) {
			wp_safe_redirect( wp_login_url() );
			exit;
		}
		$uid   = absint( $_POST['uxstudio_2fa_user'] );
		$token = sanitize_text_field( wp_unslash( $_POST['uxstudio_2fa_token'] ) );
		$code  = isset( $_POST['uxstudio_2fa_code'] ) ? sanitize_text_field( wp_unslash( $_POST['uxstudio_2fa_code'] ) ) : '';
		// phpcs:enable

		$user = $uid > 0 ? get_userdata( $uid ) : false;
		if ( ! $user || ! TwoFactorStore::is_enabled( $uid ) || ! TwoFactorStore::check_login_token( $uid, $token ) ) {
			wp_safe_redirect( add_query_arg( 'uxstudio_2fa', 'expired', wp_login_url() ) );
			exit;
		}

		$res = TwoFactorStore::attempt( $uid, $code );
		if ( ! $res['ok'] ) {
			if ( $res['just_locked'] ) {
				self::log( 'two_factor_locked', $uid, array( 'minutes' => (int) ceil( TwoFactorStore::WINDOW / 60 ) ) );
			}
			if ( ! $res['locked'] || $res['just_locked'] ) {
				self::log( 'two_factor_failed', $uid, array( 'ip' => CaptchaVerifier::get_client_ip( $this->module ) ) );
			}
			$error = new WP_Error(
				'uxstudio_2fa_failed',
				$res['locked']
					? sprintf(
						/* translators: %d: minutes. */
						__( '<strong>Error:</strong> Too many wrong codes. Try again in %d minutes.', 'ux-studio' ),
						max( 1, (int) ceil( $res['retry_in'] / 60 ) )
					)
					: __( '<strong>Error:</strong> The code is not correct.', 'ux-studio' )
			);
			$this->render_challenge( $user, $token, $error );
			exit;
		}

		if ( 'recovery' === $res['method'] ) {
			self::log( 'two_factor_recovery_used', $uid, array( 'left' => TwoFactorStore::recovery_left( $uid ) ) );
		}
		TwoFactorStore::clear_login_token( $uid );

		$remember = ! empty( $_POST['rememberme'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		wp_set_auth_cookie( $uid, $remember );
		wp_set_current_user( $uid );
		/** This action is documented in wp-includes/user.php */
		do_action( 'wp_login', $user->user_login, $user );

		$requested   = isset( $_POST['redirect_to'] ) ? esc_url_raw( wp_unslash( $_POST['redirect_to'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$redirect_to = '' !== $requested ? $requested : admin_url();
		/** This filter is documented in wp-login.php */
		$redirect_to = apply_filters( 'login_redirect', $redirect_to, $requested, $user );
		wp_safe_redirect( $redirect_to );
		exit;
	}

	/**
	 * Message on the login page after a second step expired.
	 *
	 * @param string $message Existing message HTML.
	 */
	public function login_message( $message ): string {
		if ( isset( $_GET['uxstudio_2fa'] ) && 'expired' === $_GET['uxstudio_2fa'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$message .= '<p class="message">' . esc_html__( 'The verification took too long or was interrupted. Sign in again.', 'ux-studio' ) . '</p>';
		}
		return (string) $message;
	}

	/* ═══════════════════════════════════════════════════
	   Role requirement
	   ═══════════════════════════════════════════════════ */

	/**
	 * @return string[]
	 */
	private function required_roles(): array {
		return array_values( array_filter( array_map( 'strval', (array) $this->module->setting( 'two_factor_required_roles', array() ) ) ) );
	}

	private function grace_total(): int {
		return max( 0, min( 30, (int) $this->module->setting( 'two_factor_grace_logins', TwoFactorStore::DEFAULT_GRACE ) ) );
	}

	/**
	 * A completed login (password only, or password + second step): decide the role requirement.
	 *
	 * @param string  $user_login Login.
	 * @param WP_User $user       User.
	 */
	public function after_login( $user_login, $user = null ): void {
		self::$setup_state = '';
		if ( ! ( $user instanceof WP_User ) || ! $this->required_roles() ) {
			return;
		}
		$state = TwoFactorStore::login_gate( $user->ID, (array) $user->roles, $this->required_roles(), $this->grace_total() );
		if ( 'none' === $state ) {
			return;
		}
		self::$setup_state = $state;
		self::log( 'forced' === $state ? 'two_factor_setup_forced' : 'two_factor_grace_used', $user->ID, array( 'left' => TwoFactorStore::grace_left( $user->ID, $this->grace_total() ) ) );
	}

	/**
	 * Send a user who still has to set the factor up to the profile screen, once, right after the login.
	 *
	 * @param string                $redirect_to Target.
	 * @param string                $requested   Requested target.
	 * @param WP_User|WP_Error|null $user        User.
	 */
	public function login_redirect( $redirect_to, $requested = '', $user = null ): string {
		if ( '' !== self::$setup_state && $user instanceof WP_User ) {
			return admin_url( 'profile.php?uxstudio_2fa_setup=1#uxstudio-2fa' );
		}
		return (string) $redirect_to;
	}

	/**
	 * Forced state: only the profile (where the setup is) and the plumbing it needs stay reachable.
	 * The user can always finish the setup or log out; nobody is locked out silently.
	 */
	public function enforce_setup(): void {
		if ( wp_doing_ajax() || wp_doing_cron() || ! is_user_logged_in() ) {
			return;
		}
		$uid = get_current_user_id();
		if ( ! TwoFactorStore::is_forced( $uid ) ) {
			return;
		}
		$user = wp_get_current_user();
		if ( ! TwoFactorStore::role_requires( (array) $user->roles, $this->required_roles() ) ) {
			TwoFactorStore::login_gate( $uid, (array) $user->roles, array(), 0 ); // The requirement was lifted: clear the flag.
			return;
		}
		global $pagenow;
		if ( in_array( (string) $pagenow, array( 'profile.php', 'admin-post.php', 'async-upload.php' ), true ) ) {
			return;
		}
		wp_safe_redirect( admin_url( 'profile.php?uxstudio_2fa_setup=1#uxstudio-2fa' ) );
		exit;
	}

	/**
	 * Notice on every admin screen while the role requires 2FA and none is set up.
	 */
	public function setup_notice(): void {
		global $pagenow;
		$uid  = get_current_user_id();
		$user = wp_get_current_user();
		if ( ! $uid || 'profile.php' === $pagenow || TwoFactorStore::is_enabled( $uid ) || ! TwoFactorStore::role_requires( (array) $user->roles, $this->required_roles() ) ) {
			return;
		}
		$link = '<a href="' . esc_url( admin_url( 'profile.php?uxstudio_2fa_setup=1#uxstudio-2fa' ) ) . '">' . esc_html__( 'Set it up now', 'ux-studio' ) . '</a>';
		if ( TwoFactorStore::is_forced( $uid ) ) {
			$text = esc_html__( 'Your role requires two-step verification. Set it up to continue using the administration.', 'ux-studio' );
		} else {
			$left = TwoFactorStore::grace_left( $uid, $this->grace_total() );
			$text = sprintf(
				/* translators: %d: number of logins. */
				esc_html( _n( 'Your role requires two-step verification. You can postpone it for %d more login.', 'Your role requires two-step verification. You can postpone it for %d more logins.', $left, 'ux-studio' ) ),
				$left
			);
		}
		echo '<div class="notice notice-warning"><p>' . $text . ' ' . $link . '</p></div>'; // phpcs:ignore WordPress.Security.EscapeOutput
	}

	/**
	 * Confirmation after an administrator reset somebody's second factor.
	 */
	public function reset_notice(): void {
		if ( isset( $_GET['uxstudio_2fa_reset'] ) && current_user_can( 'edit_users' ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Two-step verification of the user was reset. They can set it up again in their profile.', 'ux-studio' ) . '</p></div>';
		}
	}

	/* ═══════════════════════════════════════════════════
	   Profile screen
	   ═══════════════════════════════════════════════════ */

	/**
	 * Section on the profile screen: own setup, or the administrator's view of somebody else's.
	 *
	 * @param WP_User $profile_user User being edited.
	 */
	public function render_profile( $profile_user ): void {
		if ( ! ( $profile_user instanceof WP_User ) ) {
			return;
		}
		$is_self = get_current_user_id() === $profile_user->ID;
		if ( ! $is_self && ! current_user_can( 'edit_users' ) ) {
			return;
		}
		$enabled = TwoFactorStore::is_enabled( $profile_user->ID );
		?>
		<h2 id="uxstudio-2fa"><?php esc_html_e( 'Two-step verification', 'ux-studio' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Authenticator app', 'ux-studio' ); ?></th>
				<td>
		<?php
		if ( ! $is_self ) {
			echo '<p>' . esc_html( $enabled ? __( 'On.', 'ux-studio' ) : __( 'Off.', 'ux-studio' ) ) . '</p>';
			if ( $enabled && current_user_can( 'edit_user', $profile_user->ID ) ) {
				printf(
					'<p><a class="button" href="%s" onclick="return confirm(%s);">%s</a></p><p class="description">%s</p>',
					esc_url( $this->reset_url( $profile_user->ID ) ),
					esc_attr( wp_json_encode( __( 'Reset two-step verification of this user? They will log in with the password only until they set it up again.', 'ux-studio' ) ) ),
					esc_html__( 'Reset two-step verification', 'ux-studio' ),
					esc_html__( 'For a user who lost the phone and the recovery codes.', 'ux-studio' )
				);
			}
			echo '</td></tr></table>';
			return;
		}

		$forced = TwoFactorStore::is_forced( $profile_user->ID );
		if ( ! $enabled && ( $forced || ! empty( $_GET['uxstudio_2fa_setup'] ) ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			echo '<p><strong>' . esc_html( $forced ? __( 'Your role requires two-step verification. Set it up below to continue.', 'ux-studio' ) : __( 'Your role requires two-step verification. Set it up below.', 'ux-studio' ) ) . '</strong></p>';
		}
		?>
					<div id="uxstudio-2fa-box" data-enabled="<?php echo $enabled ? '1' : '0'; ?>" data-left="<?php echo (int) TwoFactorStore::recovery_left( $profile_user->ID ); ?>"></div>
				</td>
			</tr>
		</table>
		<?php
		$this->print_profile_script();
	}

	private function reset_url( int $uid ): string {
		return wp_nonce_url( admin_url( 'admin-post.php?action=uxstudio_2fa_reset&user=' . $uid ), 'uxstudio_2fa_reset_' . $uid );
	}

	/**
	 * The small script that drives the setup box (no dependency, admin screen only).
	 */
	private function print_profile_script(): void {
		$t = array(
			'off'        => __( 'Two-step verification is off. With it, a stolen password alone is not enough to log in.', 'ux-studio' ),
			'on'         => __( 'Two-step verification is on.', 'ux-studio' ),
			'left'       => __( 'Unused recovery codes:', 'ux-studio' ),
			'setup'      => __( 'Set up', 'ux-studio' ),
			'step1'      => __( '1. In your authenticator app (Google Authenticator, Microsoft Authenticator, Authy, 1Password ...) add an account and enter this key:', 'ux-studio' ),
			'copy'       => __( 'Copy', 'ux-studio' ),
			'copied'     => __( 'Copied', 'ux-studio' ),
			'openlink'   => __( 'On the phone you can also open this link:', 'ux-studio' ),
			'step2'      => __( '2. Enter the 6-digit code the app shows:', 'ux-studio' ),
			'confirm'    => __( 'Confirm and turn on', 'ux-studio' ),
			'cancel'     => __( 'Cancel', 'ux-studio' ),
			'codes'      => __( 'Recovery codes. Each works once and they are shown only now. Store them somewhere safe (a password manager, paper).', 'ux-studio' ),
			'done'       => __( 'Done', 'ux-studio' ),
			'regen'      => __( 'New recovery codes', 'ux-studio' ),
			'disable'    => __( 'Turn off', 'ux-studio' ),
			'reauth'     => __( 'Enter your password or a code from the app:', 'ux-studio' ),
			'run'        => __( 'Continue', 'ux-studio' ),
			'wait'       => __( 'Working ...', 'ux-studio' ),
			'failed'     => __( 'It did not work. Reload the page and try again.', 'ux-studio' ),
		);
		$cfg = array(
			'ajax'  => admin_url( 'admin-ajax.php' ),
			'nonce' => wp_create_nonce( 'uxstudio_2fa_profile' ),
			't'     => $t,
		);
		?>
		<script>
		(function () {
			var cfg = <?php echo wp_json_encode( $cfg ); ?>, box = document.getElementById('uxstudio-2fa-box');
			if (!box) { return; }
			var t = cfg.t;
			function el(tag, text, cls) { var e = document.createElement(tag); if (text) { e.textContent = text; } if (cls) { e.className = cls; } return e; }
			function call(op, extra) {
				var body = new URLSearchParams(Object.assign({ action: 'uxstudio_2fa', nonce: cfg.nonce, op: op }, extra || {}));
				return fetch(cfg.ajax, { method: 'POST', credentials: 'same-origin', body: body }).then(function (r) { return r.json(); });
			}
			function msg(text, bad) { var m = box.querySelector('.uxs-2fa-msg'); if (!m) { m = el('p', '', 'uxs-2fa-msg'); box.appendChild(m); } m.textContent = text; m.style.color = bad ? '#b32d2e' : ''; }
			function fail(res) { msg(res && res.data && res.data.message ? res.data.message : t.failed, true); }
			function button(text, primary, fn) { var b = el('button', text, 'button' + (primary ? ' button-primary' : '')); b.type = 'button'; b.addEventListener('click', fn); return b; }
			function copyBtn(getText) { return button(t.copy, false, function (ev) { navigator.clipboard.writeText(getText()).then(function () { ev.target.textContent = t.copied; }); }); }
			function showCodes(codes) {
				box.textContent = '';
				box.appendChild(el('p', t.codes));
				var pre = el('pre', codes.join('\n')); pre.style.cssText = 'font-size:15px;line-height:1.7;user-select:all'; box.appendChild(pre);
				var row = el('p'); row.appendChild(copyBtn(function () { return codes.join('\n'); })); row.appendChild(document.createTextNode(' '));
				row.appendChild(button(t.done, true, function () { window.location.reload(); }));
				box.appendChild(row);
			}
			function reauth(op, label) {
				box.querySelectorAll('.uxs-2fa-reauth').forEach(function (n) { n.remove(); });
				var w = el('p', '', 'uxs-2fa-reauth'); w.appendChild(el('label', t.reauth)); w.appendChild(document.createElement('br'));
				var inp = el('input'); inp.type = 'password'; inp.autocomplete = 'off'; inp.className = 'regular-text'; w.appendChild(inp); w.appendChild(document.createTextNode(' '));
				w.appendChild(button(t.run, true, function () {
					msg(t.wait);
					call(op, { secret: inp.value }).then(function (res) {
						if (!res.success) { return fail(res); }
						if (res.data.codes) { showCodes(res.data.codes); } else { window.location.reload(); }
					}).catch(function () { msg(t.failed, true); });
				}));
				box.appendChild(w); inp.focus();
			}
			function render() {
				box.textContent = '';
				if (box.dataset.enabled === '1') {
					box.appendChild(el('p', t.on + ' ' + t.left + ' ' + box.dataset.left + '.'));
					var r = el('p'); r.appendChild(button(t.regen, false, function () { reauth('regen'); })); r.appendChild(document.createTextNode(' ')); r.appendChild(button(t.disable, false, function () { reauth('disable'); })); box.appendChild(r);
				} else {
					box.appendChild(el('p', t.off));
					var p = el('p'); p.appendChild(button(t.setup, true, start)); box.appendChild(p);
				}
			}
			function start() {
				msg(t.wait);
				call('start').then(function (res) {
					if (!res.success) { return fail(res); }
					box.textContent = '';
					box.appendChild(el('p', t.step1));
					var key = el('p'); var code = el('code', res.data.display); code.style.cssText = 'font-size:16px;padding:4px 8px;user-select:all'; key.appendChild(code); key.appendChild(document.createTextNode(' ')); key.appendChild(copyBtn(function () { return res.data.secret; })); box.appendChild(key);
					var link = el('p'); link.appendChild(document.createTextNode(t.openlink + ' ')); var a = el('a', res.data.uri); a.href = res.data.uri; a.style.wordBreak = 'break-all'; link.appendChild(a); box.appendChild(link);
					box.appendChild(el('p', t.step2));
					var w = el('p'); var inp = el('input'); inp.type = 'text'; inp.inputMode = 'numeric'; inp.autocomplete = 'one-time-code'; inp.maxLength = 7; inp.className = 'regular-text'; inp.style.cssText = 'max-width:10em;letter-spacing:.2em'; w.appendChild(inp); w.appendChild(document.createTextNode(' '));
					w.appendChild(button(t.confirm, true, function () {
						msg(t.wait);
						call('confirm', { code: inp.value }).then(function (r2) { if (!r2.success) { return fail(r2); } showCodes(r2.data.codes); }).catch(function () { msg(t.failed, true); });
					}));
					w.appendChild(document.createTextNode(' ')); w.appendChild(button(t.cancel, false, render)); box.appendChild(w); inp.focus();
				}).catch(function () { msg(t.failed, true); });
			}
			render();
		})();
		</script>
		<?php
	}

	/* ═══════════════════════════════════════════════════
	   AJAX (own profile) and the administrator's reset
	   ═══════════════════════════════════════════════════ */

	/**
	 * Re-authentication for the sensitive operations: the account password or a valid code. Wrong tries
	 * count against the same limiter as the login.
	 *
	 * @param int    $uid   User id.
	 * @param string $input Password or code.
	 * @return string '' when good, otherwise the message.
	 */
	private function reauth( int $uid, string $input ): string {
		$left = TwoFactorStore::lock_remaining( $uid );
		if ( $left > 0 ) {
			/* translators: %d: minutes. */
			return sprintf( __( 'Too many wrong attempts. Try again in %d minutes.', 'ux-studio' ), max( 1, (int) ceil( $left / 60 ) ) );
		}
		$user = get_userdata( $uid );
		if ( $user && '' !== $input && wp_check_password( $input, $user->user_pass, $uid ) ) {
			return '';
		}
		$res = TwoFactorStore::attempt( $uid, $input );
		if ( $res['ok'] ) {
			return '';
		}
		if ( $res['just_locked'] ) {
			self::log( 'two_factor_locked', $uid, array( 'minutes' => (int) ceil( TwoFactorStore::WINDOW / 60 ), 'where' => 'profile' ) );
		}
		self::log( 'two_factor_failed', $uid, array( 'where' => 'profile' ) );
		return __( 'The password or the code is not correct.', 'ux-studio' );
	}

	/**
	 * `wp_ajax_uxstudio_2fa`: start, confirm, regenerate, disable. Always about the current user.
	 */
	public function ajax(): void {
		check_ajax_referer( 'uxstudio_2fa_profile', 'nonce' );
		$uid = get_current_user_id();
		if ( ! $uid ) {
			wp_send_json_error( array( 'message' => __( 'You are not logged in.', 'ux-studio' ) ), 403 );
		}
		$op     = isset( $_POST['op'] ) ? sanitize_key( wp_unslash( $_POST['op'] ) ) : '';
		$secret = isset( $_POST['secret'] ) ? (string) wp_unslash( $_POST['secret'] ) : ''; // Password, not sanitized on purpose.
		$code   = isset( $_POST['code'] ) ? sanitize_text_field( wp_unslash( $_POST['code'] ) ) : '';

		switch ( $op ) {
			case 'start':
				if ( TwoFactorStore::is_enabled( $uid ) ) {
					wp_send_json_error( array( 'message' => __( 'Two-step verification is already on.', 'ux-studio' ) ), 409 );
				}
				$new    = TwoFactorStore::begin_setup( $uid );
				$user   = wp_get_current_user();
				$issuer = trim( wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES ) );
				wp_send_json_success(
					array(
						'secret'  => $new,
						'display' => Totp::format_for_display( $new ),
						'uri'     => Totp::provisioning_uri( $new, $user->user_login, '' !== $issuer ? $issuer : (string) wp_parse_url( home_url(), PHP_URL_HOST ) ),
					)
				);
				break;

			case 'confirm':
				$codes = TwoFactorStore::confirm_setup( $uid, $code );
				if ( null === $codes ) {
					$left = TwoFactorStore::lock_remaining( $uid );
					wp_send_json_error(
						array(
							'message' => $left > 0
								/* translators: %d: minutes. */
								? sprintf( __( 'Too many wrong attempts. Try again in %d minutes.', 'ux-studio' ), max( 1, (int) ceil( $left / 60 ) ) )
								: __( 'The code is not correct. Check the time on your phone and try the next code.', 'ux-studio' ),
						),
						400
					);
				}
				self::log( 'two_factor_enabled', $uid );
				wp_send_json_success( array( 'codes' => $codes ) );
				break;

			case 'regen':
			case 'disable':
				if ( ! TwoFactorStore::is_enabled( $uid ) ) {
					wp_send_json_error( array( 'message' => __( 'Two-step verification is not on.', 'ux-studio' ) ), 409 );
				}
				$problem = $this->reauth( $uid, $secret );
				if ( '' !== $problem ) {
					wp_send_json_error( array( 'message' => $problem ), 403 );
				}
				if ( 'regen' === $op ) {
					$codes = TwoFactorStore::generate_recovery_codes( $uid );
					self::log( 'two_factor_recovery_regenerated', $uid );
					wp_send_json_success( array( 'codes' => $codes ) );
				}
				self::reset_user( $uid, false );
				wp_send_json_success( array( 'disabled' => true ) );
				break;
		}
		wp_send_json_error( array( 'message' => __( 'Unknown action.', 'ux-studio' ) ), 400 );
	}

	/**
	 * `admin_post_uxstudio_2fa_reset`: an administrator resets somebody's second factor (nonce + capability).
	 */
	public function handle_reset(): void {
		$target = isset( $_GET['user'] ) ? absint( $_GET['user'] ) : 0;
		check_admin_referer( 'uxstudio_2fa_reset_' . $target );
		if ( $target < 1 || ! current_user_can( 'edit_users' ) || ! current_user_can( 'edit_user', $target ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'ux-studio' ), '', array( 'response' => 403 ) );
		}
		self::reset_user( $target, true );
		wp_safe_redirect( add_query_arg( 'uxstudio_2fa_reset', '1', admin_url( 'users.php' ) ) );
		exit;
	}

	/**
	 * Row action on the Users screen.
	 *
	 * @param array<string, string> $actions Row actions.
	 * @param WP_User               $user    Row user.
	 * @return array<string, string>
	 */
	public function row_actions( $actions, $user ): array {
		$actions = (array) $actions;
		if ( $user instanceof WP_User && TwoFactorStore::is_enabled( $user->ID ) && current_user_can( 'edit_users' ) && current_user_can( 'edit_user', $user->ID ) ) {
			$actions['uxstudio_2fa_reset'] = sprintf(
				'<a href="%s" onclick="return confirm(%s);">%s</a>',
				esc_url( $this->reset_url( $user->ID ) ),
				esc_attr( wp_json_encode( __( 'Reset two-step verification of this user? They will log in with the password only until they set it up again.', 'ux-studio' ) ) ),
				esc_html__( 'Reset 2FA', 'ux-studio' )
			);
		}
		return $actions;
	}

	/**
	 * @param array<string, string> $columns Columns of the Users table.
	 * @return array<string, string>
	 */
	public function users_column( $columns ): array {
		$columns = (array) $columns;
		if ( current_user_can( 'edit_users' ) ) {
			$columns['uxstudio_2fa'] = __( '2FA', 'ux-studio' );
		}
		return $columns;
	}

	/**
	 * @param string $value       Current cell.
	 * @param string $column_name Column id.
	 * @param int    $user_id     Row user.
	 */
	public function users_column_value( $value, $column_name, $user_id ): string {
		if ( 'uxstudio_2fa' !== $column_name ) {
			return (string) $value;
		}
		return TwoFactorStore::is_enabled( (int) $user_id ) ? esc_html__( 'On', 'ux-studio' ) : '&mdash;';
	}

	/* ═══════════════════════════════════════════════════
	   For the settings screen (REST)
	   ═══════════════════════════════════════════════════ */

	/**
	 * Overview for the Security settings topic.
	 *
	 * @param Module $module Security module.
	 * @return array<string, mixed>
	 */
	public static function status( Module $module ): array {
		$names    = function_exists( 'wp_roles' ) ? wp_roles()->get_names() : array();
		$required = array_values( array_filter( array_map( 'strval', (array) $module->setting( 'two_factor_required_roles', array() ) ) ) );
		$grace    = max( 0, min( 30, (int) $module->setting( 'two_factor_grace_logins', TwoFactorStore::DEFAULT_GRACE ) ) );
		$role_of  = static function ( WP_User $u ) use ( $names ): string {
			$labels = array();
			foreach ( (array) $u->roles as $role ) {
				$labels[] = isset( $names[ $role ] ) ? translate_user_role( $names[ $role ] ) : (string) $role;
			}
			return implode( ', ', $labels );
		};

		$users = array();
		foreach ( get_users( array( 'meta_key' => 'uxstudio_2fa_enabled', 'meta_value' => '1', 'number' => 500, 'orderby' => 'login' ) ) as $u ) { // phpcs:ignore WordPress.DB.SlowDBQuery
			if ( ! TwoFactorStore::is_enabled( $u->ID ) ) {
				continue;
			}
			$users[] = array(
				'id'            => $u->ID,
				'login'         => $u->user_login,
				'name'          => $u->display_name,
				'roles'         => $role_of( $u ),
				'recovery_left' => TwoFactorStore::recovery_left( $u->ID ),
			);
		}

		$missing = array();
		if ( $required ) {
			foreach ( get_users( array( 'role__in' => $required, 'number' => 500, 'orderby' => 'login' ) ) as $u ) {
				if ( TwoFactorStore::is_enabled( $u->ID ) ) {
					continue;
				}
				$missing[] = array(
					'id'         => $u->ID,
					'login'      => $u->user_login,
					'name'       => $u->display_name,
					'roles'      => $role_of( $u ),
					'grace_left' => TwoFactorStore::grace_left( $u->ID, $grace ),
					'forced'     => TwoFactorStore::is_forced( $u->ID ),
				);
			}
		}

		return array(
			'feature'     => (bool) $module->setting( 'two_factor_enabled', false ),
			'killed'      => TwoFactorStore::killed(),
			'kill_switch' => TwoFactorStore::KILL_SWITCH,
			'users'       => $users,
			'missing'     => $missing,
		);
	}
}
