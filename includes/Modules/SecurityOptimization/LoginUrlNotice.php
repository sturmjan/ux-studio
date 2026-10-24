<?php
/**
 * One-time warning that the custom login address is really in force (release safety).
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\SecurityOptimization;

use UxStudio\Core\ActivityLog;
use UxStudio\Core\Upgrade;

defined( 'ABSPATH' ) || exit;

/**
 * Until this release the "custom login URL" setting silently never worked on some sites (subdirectory
 * installs, slug collision check), so a site could have it switched on without wp-login.php ever being
 * blocked. The fixes make it work, and an update would suddenly lock the usual address. Therefore, on the first
 * admin page load after the update on a site where the lock is really active:
 *
 *  - every administrator sees a persistent notice with the full new address, a copy button, an explanation and a
 *    link to Security > Login to turn it off (until that user dismisses it),
 *  - ONE e-mail with the address goes to the site e-mail (never repeated, the state option remembers it).
 *
 * The setting itself is never changed here. The decision "first load, lock active" is a pure function.
 */
final class LoginUrlNotice {

	/** Site option: array{time:int,version:string,active:bool,url:string,mail:string}. Its existence = already handled. */
	public const STATE = 'uxstudio_login_url_notice';

	public const DISMISS_META = 'uxstudio_login_url_notice_dismissed';

	public const ACTION = 'uxstudio_dismiss_login_url_notice';

	/** @var callable|null Test seam: replaces wp_mail(). Receives (to, subject, body), returns bool. */
	private static $mailer = null;

	/**
	 * Replace the mail function (tests only).
	 *
	 * @param callable|null $mailer Mailer.
	 */
	public static function set_mailer( ?callable $mailer ): void {
		self::$mailer = $mailer;
	}

	/**
	 * Wire the hooks. Called from the module boot, i.e. only while the Security module is enabled.
	 *
	 * @param Module $module Security module.
	 */
	public static function register( Module $module ): void {
		add_action(
			'admin_init',
			static function () use ( $module ): void {
				if ( ( function_exists( 'wp_doing_ajax' ) && wp_doing_ajax() ) || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
					return;
				}
				self::handle_first_load( $module->login_lock_status() );
			},
			5
		);
		add_action(
			'admin_notices',
			static function () use ( $module ): void {
				if ( ! self::visible_for( get_current_user_id() ) ) {
					return;
				}
				$status = $module->login_lock_status();
				if ( $status['active'] ) {
					// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside render().
					echo self::render( $status['url'], self::dismiss_url(), self::settings_url() );
					wp_enqueue_script( 'uxstudio-login-url-notice', UXSTUDIO_URL . 'assets/js/login-url-notice.js', array(), UXSTUDIO_VERSION, true );
				}
			}
		);
		add_action(
			'admin_notices',
			static function () use ( $module ): void {
				if ( ! current_user_can( 'manage_options' ) ) {
					return;
				}
				$status = $module->login_lock_status();
				if ( 'no_pretty_permalinks' === $status['reason'] ) {
					// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside render_permalinks_notice().
					echo self::render_permalinks_notice( admin_url( 'options-permalink.php' ), self::settings_url() );
				}
			}
		);
		add_action( 'admin_post_' . self::ACTION, array( self::class, 'handle_dismiss' ) );
	}

	/**
	 * What to do with the current state of the lock.
	 *
	 * @param array{handled:bool,active:bool,legacy:bool} $ctx `handled`: the state option exists, `active`: the lock is in force, `legacy`: the address was configured before this release.
	 * @return string `done` (already handled), `record` (nothing to warn about, just remember we looked), `notify`.
	 */
	public static function decide( array $ctx ): string {
		if ( ! empty( $ctx['handled'] ) ) {
			return 'done';
		}
		// Only a site that had the address configured BEFORE this release is told about it; a site that sets
		// it up after the update did it on purpose and knows the address.
		return ( ! empty( $ctx['active'] ) && ! empty( $ctx['legacy'] ) ) ? 'notify' : 'record';
	}

	/**
	 * First admin page load of the module: remember it, and when the lock is in force send the one e-mail.
	 *
	 * @param array{active:bool,slug:string,url:string,reason:string} $status Lock status of the module.
	 * @return string Decision taken (see decide()).
	 */
	public static function handle_first_load( array $status ): string {
		$decision = self::decide(
			array(
				'handled' => false !== get_option( self::STATE, false ),
				'active'  => ! empty( $status['active'] ),
				'legacy'  => Upgrade::login_lock_was_configured(),
			)
		);
		if ( 'done' === $decision ) {
			return $decision;
		}
		$state = array(
			'time'    => time(),
			'version' => defined( 'UXSTUDIO_VERSION' ) ? UXSTUDIO_VERSION : '',
			'active'  => 'notify' === $decision,
			'url'     => 'notify' === $decision ? (string) $status['url'] : '',
			'mail'    => 'none',
		);
		// Written BEFORE the mail goes out: a crash or a second parallel request can never send it twice.
		update_option( self::STATE, $state, false );
		if ( 'notify' === $decision ) {
			$sent          = self::send_mail( (string) $status['url'] );
			$state['mail'] = $sent ? 'sent' : 'failed';
			update_option( self::STATE, $state, false );
			ActivityLog::log( 'security-optimization', 'login_url_notice', 'setting', 0, array( 'url' => (string) $status['url'], 'mail' => $state['mail'] ) );
		}
		return $decision;
	}

	/**
	 * Should this user see the notice?
	 *
	 * @param int $user_id User id.
	 */
	public static function visible_for( int $user_id ): bool {
		$state = get_option( self::STATE, false );
		if ( ! is_array( $state ) || empty( $state['active'] ) ) {
			return false;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return false;
		}
		return '' === (string) get_user_meta( $user_id, self::DISMISS_META, true );
	}

	/**
	 * Send the one e-mail to the site e-mail address.
	 *
	 * @param string $url New login address.
	 */
	private static function send_mail( string $url ): bool {
		$to      = (string) get_option( 'admin_email', '' );
		$subject = sprintf(
			/* translators: %s: site name. */
			__( '[%s] Your custom login address is in force', 'ux-studio' ),
			wp_specialchars_decode( (string) get_option( 'blogname', '' ), ENT_QUOTES )
		);
		$body = sprintf(
			/* translators: 1: new login address, 2: link to the setting. */
			__(
				"UX Studio has been updated. The custom login address you set up earlier is in force.\n\nLog in here:\n%1\$s\n\nThe usual address (wp-login.php) does not show the login form. If you already log in this way, nothing changes for you. Save the address in your password manager.\n\nIf you do not want this, switch it off in UX Studio > Security > Login:\n%2\$s\n\nIf you cannot log in at all, add this line to wp-config.php and the old address works again:\ndefine( 'UXSTUDIO_DISABLE_LOGIN_LOCK', true );",
				'ux-studio'
			),
			$url,
			self::settings_url()
		);
		if ( '' === $to ) {
			return false;
		}
		if ( null !== self::$mailer ) {
			return (bool) ( self::$mailer )( $to, $subject, $body );
		}
		return (bool) wp_mail( $to, $subject, $body );
	}

	/**
	 * HTML of the notice (everything escaped).
	 *
	 * @param string $url          New login address.
	 * @param string $dismiss_url  Link that hides the notice for this user.
	 * @param string $settings_url Link to Security > Login.
	 */
	public static function render( string $url, string $dismiss_url, string $settings_url ): string {
		// `below-h2` keeps WordPress and the Admin Customiser from moving or hiding this one: it must be seen.
		return '<div id="uxstudio-login-url-notice" class="notice notice-warning below-h2 uxstudio-login-url-notice">'
			. '<p><strong>' . esc_html__( 'The custom login address of this site is in force.', 'ux-studio' ) . '</strong></p>'
			. '<p>' . esc_html__( 'The custom login address set in UX Studio is in force. The usual wp-login.php does not show the login form. Log in here and save the address:', 'ux-studio' ) . '</p>'
			. '<p><code class="uxstudio-login-url-notice__url">' . esc_html( $url ) . '</code> '
			. '<button type="button" class="button" data-copy="' . esc_attr( $url ) . '" data-done="' . esc_attr__( 'Copied', 'ux-studio' ) . '">' . esc_html__( 'Copy the address', 'ux-studio' ) . '</button></p>'
			. '<p>' . esc_html__( 'You did not want this? Switch it off in the settings. Nothing was changed automatically.', 'ux-studio' ) . '</p>'
			. '<p><a class="button button-primary" href="' . esc_url( $settings_url ) . '">' . esc_html__( 'Open Security > Login', 'ux-studio' ) . '</a> '
			. '<a class="button" href="' . esc_url( $dismiss_url ) . '">' . esc_html__( 'I have saved it, hide this notice', 'ux-studio' ) . '</a></p>'
			. '</div>';
	}

	/**
	 * Warning that the custom login address is switched on but NOT enforced because the site has plain
	 * permalinks (the address would be a 404 and wp-login.php would close for good). Everything escaped.
	 *
	 * @param string $permalinks_url Link to Settings > Permalinks.
	 * @param string $settings_url   Link to Security > Login.
	 */
	public static function render_permalinks_notice( string $permalinks_url, string $settings_url ): string {
		return '<div id="uxstudio-login-url-permalinks" class="notice notice-warning below-h2">'
			. '<p><strong>' . esc_html__( 'The custom login address is switched on, but it is not enforced.', 'ux-studio' ) . '</strong></p>'
			. '<p>' . esc_html__( 'This site uses plain permalinks, where the custom login address cannot work. To keep you from being locked out, wp-login.php stays open. Choose a pretty permalink structure (for example Post name) and the custom address starts to apply.', 'ux-studio' ) . '</p>'
			. '<p><a class="button button-primary" href="' . esc_url( $permalinks_url ) . '">' . esc_html__( 'Open Settings > Permalinks', 'ux-studio' ) . '</a> '
			. '<a class="button" href="' . esc_url( $settings_url ) . '">' . esc_html__( 'Open Security > Login', 'ux-studio' ) . '</a></p>'
			. '</div>';
	}

	/**
	 * Link to the Login topic of the Security module.
	 */
	public static function settings_url(): string {
		return admin_url( 'admin.php?page=ux-studio#/module?id=security-optimization&tab=login' );
	}

	/**
	 * Nonce link that dismisses the notice for the current user.
	 */
	public static function dismiss_url(): string {
		return wp_nonce_url( admin_url( 'admin-post.php?action=' . self::ACTION ), self::ACTION );
	}

	/**
	 * admin-post handler: hide the notice for this user only.
	 */
	public static function handle_dismiss(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'ux-studio' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( self::ACTION );
		update_user_meta( get_current_user_id(), self::DISMISS_META, (string) time() );
		wp_safe_redirect( wp_get_referer() ?: admin_url() );
		exit;
	}
}
