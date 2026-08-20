<?php
/**
 * User Switching module.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\UserSwitching;

use UxStudio\Modules\BaseModule;

defined( 'ABSPATH' ) || exit;

/**
 * Instantly switch to another user account and back. Ported 1:1 from the legacy
 * module (free + pro merged), including the "favourite user" quick switch. The
 * whole auth flow deliberately runs through WordPress auth cookies and nonce
 * URLs handled on init - NOT through REST, because setting auth cookies over
 * the REST API is unreliable. Every action is gated by a nonce and capability.
 */
final class Module extends BaseModule {

	/**
	 * Register hooks.
	 */
	public function boot(): void {
		// Capabilities.
		add_filter( 'user_has_cap', array( $this, 'filter_role_cap' ), 10, 4 );
		add_filter( 'map_meta_cap', array( $this, 'filter_role_meta_cap' ), 10, 4 );

		// User actions + action router.
		add_filter( 'user_row_actions', array( $this, 'filter_user_row_actions' ), 10, 2 );
		add_action( 'init', array( $this, 'user_role_init' ), 20 );
		add_action( 'admin_bar_menu', array( $this, 'admin_bar_menu' ), 11 );

		// Cookie handling. boot() already runs on plugins_loaded, so define the
		// constants immediately - hooking plugins_loaded here would be too late.
		$this->define_cookie_constants();
		add_action( 'wp_logout', array( $this, 'clear_olduser_cookie_action' ) );
		add_action( 'wp_login', array( $this, 'clear_olduser_cookie_action' ) );

		// Notices + UI.
		add_action( 'admin_notices', array( $this, 'admin_notices' ), 1 );
		add_action( 'wp_footer', array( $this, 'switch_footer_action' ) );
		add_action( 'personal_options', array( $this, 'show_switch_to_user_option_in_profile' ), 10, 1 );
		add_filter( 'login_message', array( $this, 'filter_login_message' ) );

		// Favourite user (merged from legacy pro).
		add_action( 'personal_options_update', array( $this, 'save_switch_user_default' ) );
		add_action( 'personal_options', array( $this, 'show_switch_user_default_field' ), 10, 1 );
		add_action( 'admin_bar_menu', array( $this, 'add_switch_user_default_to_admin_bar' ), 11 );
	}

	/**
	 * User Switching's virtual caps require the edit_users capability, so gate
	 * the module surface on it.
	 */
	public function capability(): string {
		return 'edit_users';
	}

	/**
	 * Define the cookie names used by the module.
	 */
	public function define_cookie_constants(): void {
		if ( ! defined( 'UXSTUDIO_SWITCHING_COOKIE' ) ) {
			define( 'UXSTUDIO_SWITCHING_COOKIE', 'uxstudio_user_switch_' . COOKIEHASH );
		}
		if ( ! defined( 'UXSTUDIO_SWITCHING_SECURE_COOKIE' ) ) {
			define( 'UXSTUDIO_SWITCHING_SECURE_COOKIE', 'uxstudio_user_switch_secure_' . COOKIEHASH );
		}
		if ( ! defined( 'UXSTUDIO_SWITCHING_OLDUSER_COOKIE' ) ) {
			define( 'UXSTUDIO_SWITCHING_OLDUSER_COOKIE', 'uxstudio_user_switch_olduser_' . COOKIEHASH );
		}
	}

	/**
	 * Adds a 'Switch back to {previous user}' link to the account menu.
	 *
	 * @param \WP_Admin_Bar $wp_admin_bar The admin bar object.
	 */
	public function admin_bar_menu( $wp_admin_bar ): void {
		if ( ! is_admin_bar_showing() ) {
			return;
		}

		$parent = $wp_admin_bar->get_node( 'user-actions' ) ? 'user-actions' : null;
		if ( ! $parent ) {
			return;
		}

		$old_user = self::get_old_user();
		if ( $old_user ) {
			$wp_admin_bar->add_node(
				array(
					'parent' => $parent,
					'id'     => 'switch-back',
					'title'  => esc_html( self::switch_back_message( $old_user ) ),
					'href'   => add_query_arg(
						array( 'redirect_to' => rawurlencode( self::current_url() ) ),
						self::move_back_url( $old_user )
					),
				)
			);
		}
	}

	/**
	 * Fetches the URL to redirect to for a given user (used after switching).
	 *
	 * @param \WP_User|null $new_user New user being switched to.
	 * @param \WP_User|null $old_user Original user switching from.
	 * @return string Redirect URL.
	 */
	public static function get_redirect( $new_user = null, $old_user = null ): string {
		$redirect_to           = '';
		$requested_redirect_to = '';
		$redirect_type         = null;

		if ( ! empty( $_REQUEST['redirect_to'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$redirect_to           = self::query_arg_remove( wp_unslash( $_REQUEST['redirect_to'] ) ); // phpcs:ignore WordPress.Security
			$requested_redirect_to = wp_unslash( $_REQUEST['redirect_to'] ); // phpcs:ignore WordPress.Security
			$redirect_type         = 'url';
		}

		if ( ! $new_user ) {
			/** This filter is documented in wp-login.php */
			$redirect_to = apply_filters( 'logout_redirect', $redirect_to, $requested_redirect_to, $old_user );
		} else {
			/** This filter is documented in wp-login.php */
			$redirect_to = apply_filters( 'login_redirect', $redirect_to, $requested_redirect_to, $new_user );
		}

		/**
		 * Filters the redirect location after a user switches or switches off.
		 *
		 * @param string        $redirect_to   Redirect URL.
		 * @param string|null   $redirect_type Redirect type.
		 * @param \WP_User|null $new_user      Target user.
		 * @param \WP_User|null $old_user      Original user.
		 */
		return (string) apply_filters( 'ux_studio/user_switching/redirect_to', $redirect_to, $redirect_type, $new_user, $old_user );
	}

	/**
	 * Authenticates an old user by verifying the latest entry in the auth cookie.
	 *
	 * @param \WP_User $user A WP_User object (usually from the logged_in cookie).
	 * @return bool Whether verification with the auth cookie passed.
	 */
	public static function authenticate_old_user( $user ): bool {
		$cookie = uxstudio_user_switching_get_auth_cookie();
		if ( empty( $cookie ) ) {
			return false;
		}

		$scheme      = self::secure_auth_cookie() ? 'secure_auth' : 'auth';
		$old_user_id = wp_validate_auth_cookie( end( $cookie ), $scheme );

		if ( ! $old_user_id ) {
			return false;
		}

		return ( $user->ID === $old_user_id );
	}

	/**
	 * Adds a 'Switch back to {user}' link to the login screen.
	 *
	 * @param string $message The login screen message.
	 * @return string
	 */
	public function filter_login_message( $message ): string {
		$old_user = self::get_old_user();

		if ( ! ( $old_user instanceof \WP_User ) ) {
			return $message;
		}

		$url = self::move_back_url( $old_user );

		if ( ! empty( $_REQUEST['interim-login'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$url = add_query_arg( array( 'interim-login' => '1' ), $url );
		} elseif ( ! empty( $_REQUEST['redirect_to'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$url = add_query_arg( array( 'redirect_to' => rawurlencode( wp_unslash( $_REQUEST['redirect_to'] ) ) ), $url ); // phpcs:ignore WordPress.Security
		}

		$icon = '<span class="dashicons dashicons-admin-users" style="color:#56c234" aria-hidden="true"></span> ';

		$link = sprintf(
			'<a href="%1$s" onclick="window.location.href=\'%1$s\';return false;">%2$s</a>',
			esc_url( $url ),
			esc_html( self::switch_back_message( $old_user ) )
		);

		$message = sprintf(
			'<div class="message" id="uxstudio-user-switching-switch-on">%s%s</div>',
			$icon,
			$link
		);

		/**
		 * Filters the login screen message when a user is switching.
		 *
		 * @param string $message The login message.
		 */
		return (string) apply_filters( 'ux_studio/user_switching/login_message', $message );
	}

	/**
	 * Filters a user's capabilities so they can be altered at runtime.
	 *
	 * @param array    $user_caps     User's capabilities.
	 * @param array    $required_caps Primitive capabilities required.
	 * @param array    $args          Arguments accompanying the capability check.
	 * @param \WP_User $user          The user object.
	 * @return array
	 */
	public function filter_role_cap( array $user_caps, array $required_caps, array $args, $user ): array {
		if ( 'switch_user_role' === $args[0] ) {
			if ( empty( $args[2] ) ) {
				$user_caps['switch_user_role'] = false;
				return $user_caps;
			}

			if ( array_key_exists( 'switch_users', $user_caps ) ) {
				$user_caps['switch_user_role'] = $user_caps['switch_users'];
				return $user_caps;
			}

			$user_caps['switch_user_role'] = ( user_can( $user->ID, 'edit_user', $args[2] ) && ( $args[2] !== $user->ID ) );
		} elseif ( 'switch_off' === $args[0] ) {
			if ( array_key_exists( 'switch_users', $user_caps ) ) {
				$user_caps['switch_off'] = $user_caps['switch_users'];
				return $user_caps;
			}

			$user_caps['switch_off'] = user_can( $user->ID, 'edit_users' );
		}

		return $user_caps;
	}

	/**
	 * Whether the current user checked 'Remember Me' when they logged in.
	 */
	public static function switch_remember(): bool {
		/** This filter is documented in wp-includes/pluggable.php */
		$cookie_life = apply_filters( 'auth_cookie_expiration', 172800, get_current_user_id(), false );
		$current     = wp_parse_auth_cookie( '', 'logged_in' );

		if ( ! $current ) {
			return false;
		}

		return ( intval( $current['expiration'] ) - time() > $cookie_life );
	}

	/**
	 * Filters the required primitive capabilities for a meta capability.
	 *
	 * @param array  $required_caps Required primitive capabilities.
	 * @param string $cap           Capability being checked.
	 * @param int    $user_id       ID of the user being checked.
	 * @param array  $args          Additional arguments.
	 * @return array
	 */
	public function filter_role_meta_cap( array $required_caps, $cap, $user_id, array $args ): array {
		if ( 'switch_user_role' === $cap ) {
			if ( empty( $args[0] ) || $args[0] === $user_id ) {
				$required_caps[] = 'do_not_allow';
			}
		}
		return $required_caps;
	}

	/**
	 * Adds a 'Switch To' link to each user row on the Users screen.
	 *
	 * @param array    $actions Action links.
	 * @param \WP_User $user    User object.
	 * @return array
	 */
	public function filter_user_row_actions( array $actions, $user ): array {
		$link = self::need_switch_url( $user );
		if ( ! $link ) {
			return $actions;
		}

		$actions['switch_user_role'] = sprintf(
			'<a href="%s">%s</a>',
			esc_url( $link ),
			esc_html__( 'Switch&nbsp;To', 'ux-studio' )
		);

		return $actions;
	}

	/**
	 * Returns the switch-to or switch-back URL for a given user.
	 *
	 * @param \WP_User $user User object.
	 * @return string|false
	 */
	public static function need_switch_url( $user ) {
		$old_user = self::get_old_user();

		if ( $old_user && ( $old_user->ID === $user->ID ) ) {
			return self::move_back_url( $old_user );
		} elseif ( current_user_can( 'switch_user_role', $user->ID ) ) {
			return self::switch_to_url( $user );
		}

		return false;
	}

	/**
	 * Nonce-secured URL to switch to a given user.
	 *
	 * @param \WP_User $user User to switch to.
	 * @return string
	 */
	public static function switch_to_url( $user ): string {
		$url = wp_nonce_url(
			add_query_arg(
				array(
					'action'  => 'switch_user_role',
					'user_id' => $user->ID,
					'nr'      => 1,
				),
				wp_login_url()
			),
			"switch_user_role_{$user->ID}"
		);

		/**
		 * Filters the URL for switching to a user.
		 *
		 * @param string   $url  Switch URL.
		 * @param \WP_User $user Target user.
		 */
		return (string) apply_filters( 'ux_studio/user_switching/switch_to_url', $url, $user );
	}

	/**
	 * Nonce-secured URL to switch back to the originating user.
	 *
	 * @param \WP_User $user User to switch back to.
	 * @return string
	 */
	public static function move_back_url( $user ): string {
		return wp_nonce_url(
			add_query_arg(
				array(
					'action' => 'switch_to_olduser',
					'nr'     => 1,
				),
				wp_login_url()
			),
			"switch_to_olduser_{$user->ID}"
		);
	}

	/**
	 * Nonce-secured URL to temporarily log out the current user.
	 *
	 * @param \WP_User $user User to switch off.
	 * @return string
	 */
	public static function switch_off_url( $user ): string {
		return wp_nonce_url(
			add_query_arg(
				array(
					'action' => 'switch_off',
					'nr'     => 1,
				),
				wp_login_url()
			),
			"switch_off_{$user->ID}"
		);
	}

	/**
	 * Message shown after switching to a user.
	 *
	 * @param \WP_User $user User switched to.
	 * @return string
	 */
	public static function switched_to_message( $user ): string {
		$message = sprintf(
			/* translators: 1: user display name; 2: username */
			__( 'Switched to %1$s (%2$s).', 'ux-studio' ),
			$user->display_name,
			$user->user_login
		);

		return str_replace( sprintf( ' (%s)', $user->user_login ), '', $message );
	}

	/**
	 * Message for the link to switch back to the original user.
	 *
	 * @param \WP_User $user User to switch back to.
	 * @return string
	 */
	public static function switch_back_message( $user ): string {
		return sprintf(
			/* translators: %s: user display name */
			__( 'Switch back to %s', 'ux-studio' ),
			$user->display_name
		);
	}

	/**
	 * Message shown after switching back to the original user.
	 *
	 * @param \WP_User $user User switched back to.
	 * @return string
	 */
	public static function switched_back_message( $user ): string {
		return sprintf(
			/* translators: %s: user display name */
			__( 'Switched back to %s.', 'ux-studio' ),
			$user->display_name
		);
	}

	/**
	 * The current URL.
	 */
	public static function current_url(): string {
		$host = isset( $_SERVER['HTTP_HOST'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) ) : '';
		$uri  = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
		return ( is_ssl() ? 'https://' : 'http://' ) . $host . $uri;
	}

	/**
	 * Removes common confirmation-style query args from a URL.
	 *
	 * @param string $url A URL.
	 * @return string
	 */
	public static function query_arg_remove( $url ): string {
		if ( function_exists( 'wp_removable_query_args' ) ) {
			$url = remove_query_arg( wp_removable_query_args(), $url );
		}
		return $url;
	}

	/**
	 * Whether the module's equivalent of the 'auth' cookie should be secure.
	 */
	public static function secure_auth_cookie(): bool {
		return ( is_ssl() && ( 'https' === wp_parse_url( wp_login_url(), PHP_URL_SCHEME ) ) );
	}

	/**
	 * Whether the module's equivalent of the 'logged_in' cookie should be secure.
	 */
	public static function secure_olduser_cookie(): bool {
		return ( is_ssl() && ( 'https' === wp_parse_url( home_url(), PHP_URL_SCHEME ) ) );
	}

	/**
	 * Validates the old user cookie and returns its user data.
	 *
	 * @return false|\WP_User
	 */
	public static function get_old_user() {
		$cookie = uxstudio_user_switching_get_olduser_cookie();
		if ( empty( $cookie ) ) {
			return false;
		}

		$old_user_id = wp_validate_auth_cookie( $cookie, 'logged_in' );
		if ( ! $old_user_id ) {
			return false;
		}

		$old_user = get_userdata( $old_user_id );

		/**
		 * Filters the old user object retrieved from the cookie.
		 *
		 * @param \WP_User|false $old_user The old user object.
		 */
		return apply_filters( 'ux_studio/user_switching/current_old_user', $old_user );
	}

	/**
	 * Routes actions depending on the 'action' query var (init handler).
	 */
	public function user_role_init(): void {
		if ( ! isset( $_REQUEST['action'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}

		$current_user = ( is_user_logged_in() ) ? wp_get_current_user() : null;

		switch ( $_REQUEST['action'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			case 'switch_user_role':
				$user_id = isset( $_REQUEST['user_id'] ) ? absint( $_REQUEST['user_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

				// Check authentication.
				if ( ! current_user_can( 'switch_user_role', $user_id ) ) {
					wp_die( esc_html__( 'Could not switch users.', 'ux-studio' ), '', array( 'response' => 403 ) );
				}

				// Check intent.
				check_admin_referer( "switch_user_role_{$user_id}" );

				$user = uxstudio_user_switching_switch_to_user( $user_id, self::switch_remember() );
				if ( ! $user ) {
					wp_die( esc_html__( 'Could not switch users.', 'ux-studio' ), '', array( 'response' => 404 ) );
				}

				$redirect_to = self::get_redirect( $user, $current_user );
				$args        = array( 'user_switched' => 'true' );

				if ( $redirect_to ) {
					wp_safe_redirect( add_query_arg( $args, $redirect_to ), 302 );
				} elseif ( ! current_user_can( 'read' ) ) {
					wp_safe_redirect( add_query_arg( $args, home_url() ), 302 );
				} else {
					wp_safe_redirect( add_query_arg( $args, admin_url() ), 302 );
				}
				exit;

			case 'switch_to_olduser':
				$old_user = self::get_old_user();
				if ( ! $old_user ) {
					wp_die( esc_html__( 'Could not switch users.', 'ux-studio' ), '', array( 'response' => 400 ) );
				}

				// Check authentication.
				if ( ! self::authenticate_old_user( $old_user ) ) {
					wp_die( esc_html__( 'Could not switch users.', 'ux-studio' ), '', array( 'response' => 403 ) );
				}

				// Check intent.
				check_admin_referer( "switch_to_olduser_{$old_user->ID}" );

				if ( ! uxstudio_user_switching_switch_to_user( $old_user->ID, self::switch_remember(), false ) ) {
					wp_die( esc_html__( 'Could not switch users.', 'ux-studio' ), '', array( 'response' => 404 ) );
				}

				if ( ! empty( $_REQUEST['interim-login'] ) && function_exists( 'login_header' ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
					$GLOBALS['interim_login'] = 'success';
					login_header( '', '' );
					exit;
				}

				$redirect_to = self::get_redirect( $old_user, $current_user );
				$args        = array(
					'user_switched' => 'true',
					'switched_back' => 'true',
				);

				if ( $redirect_to ) {
					wp_safe_redirect( add_query_arg( $args, $redirect_to ), 302 );
				} else {
					wp_safe_redirect( add_query_arg( $args, admin_url( 'users.php' ) ), 302 );
				}
				exit;

			case 'switch_off':
				// Check authentication.
				if ( ! $current_user || ! current_user_can( 'switch_off' ) ) {
					wp_die( esc_html__( 'Could not temporarily log out.', 'ux-studio' ), '', array( 'response' => 403 ) );
				}

				// Check intent.
				check_admin_referer( "switch_off_{$current_user->ID}" );

				$success = uxstudio_user_switching_switch_off_user();

				/**
				 * Filters the result of the switch off operation.
				 *
				 * @param bool $success Whether it succeeded.
				 */
				$success = apply_filters( 'ux_studio/user_switching/do_switch_off', $success );

				if ( ! $success ) {
					wp_die( esc_html__( 'Could not temporarily log out.', 'ux-studio' ), '', array( 'response' => 403 ) );
				}

				$redirect_to = self::get_redirect( null, $current_user );
				$args        = array( 'switched_off' => 'true' );

				if ( $redirect_to ) {
					wp_safe_redirect( add_query_arg( $args, $redirect_to ), 302 );
				} else {
					wp_safe_redirect( add_query_arg( $args, home_url() ), 302 );
				}
				exit;
		}
	}

	/**
	 * Displays the switched/switch-back notices in the admin area.
	 */
	public function admin_notices(): void {
		$user     = wp_get_current_user();
		$old_user = self::get_old_user();

		if ( $old_user ) {
			$just_switched = isset( $_GET['user_switched'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$message       = $just_switched ? self::switched_to_message( $user ) : '';

			$switch_back_url = add_query_arg(
				array( 'redirect_to' => rawurlencode( self::current_url() ) ),
				self::move_back_url( $old_user )
			);

			printf(
				'<div class="notice notice-success is-dismissible"><p><span class="dashicons dashicons-admin-users" style="color:#56c234" aria-hidden="true"></span> %s <a href="%s">%s</a>.</p></div>',
				$message ? esc_html( $message ) . ' ' : '',
				esc_url( $switch_back_url ),
				esc_html( self::switch_back_message( $old_user ) )
			);
		} elseif ( isset( $_GET['user_switched'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$message = isset( $_GET['switched_back'] ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				? self::switched_back_message( $user )
				: self::switched_to_message( $user );

			printf( '<div class="notice notice-success is-dismissible"><p>%s</p></div>', esc_html( $message ) );
		}
	}

	/**
	 * Displays the 'Switch back to {user}' link in the footer when switched.
	 */
	public function switch_footer_action(): void {
		$old_user = self::get_old_user();
		if ( ! $old_user ) {
			return;
		}

		$url = add_query_arg(
			array( 'redirect_to' => rawurlencode( self::current_url() ) ),
			self::move_back_url( $old_user )
		);

		printf(
			'<p id="uxstudio-user-switching-switch" style="position:fixed;bottom:40px;padding:10px;margin:0;right:10px;font-size:13px;z-index:99999;background-color:#fff;"><a href="%s" style="color:#0073AA;font-weight:600">%s</a></p>',
			esc_url( $url ),
			esc_html( self::switch_back_message( $old_user ) )
		);
	}

	/**
	 * Clears the cookies containing the originating user.
	 *
	 * @param bool $clear_all Whether to clear all cookies or just the latest.
	 */
	public static function clear_olduser_cookie( $clear_all = true ): void {
		$auth_cookie = uxstudio_user_switching_get_auth_cookie();

		if ( ! empty( $auth_cookie ) ) {
			array_pop( $auth_cookie );
		}

		if ( $clear_all || empty( $auth_cookie ) ) {
			/** Fires just before the switching cookies are cleared. */
			do_action( 'ux_studio/user_switching/clear_olduser_cookie' );

			/** Filter whether to send authentication cookies to the client. */
			if ( ! apply_filters( 'ux_studio/user_switching/send_auth_cookies', true ) ) {
				return;
			}

			$expire = time() - 31536000;
			setcookie( UXSTUDIO_SWITCHING_COOKIE, ' ', $expire, SITECOOKIEPATH, COOKIE_DOMAIN );
			setcookie( UXSTUDIO_SWITCHING_SECURE_COOKIE, ' ', $expire, SITECOOKIEPATH, COOKIE_DOMAIN );
			setcookie( UXSTUDIO_SWITCHING_OLDUSER_COOKIE, ' ', $expire, COOKIEPATH, COOKIE_DOMAIN );
			return;
		}

		$scheme      = self::secure_auth_cookie() ? 'secure_auth' : 'auth';
		$old_cookie  = end( $auth_cookie );
		$old_user_id = wp_validate_auth_cookie( $old_cookie, $scheme );

		if ( ! $old_user_id ) {
			return;
		}

		$parts = wp_parse_auth_cookie( $old_cookie, $scheme );
		if ( false === $parts ) {
			return;
		}

		uxstudio_user_switching_set_olduser_cookie( $old_user_id, true, $parts['token'] );
	}

	/**
	 * wp_logout / wp_login callback wrapper.
	 */
	public function clear_olduser_cookie_action(): void {
		self::clear_olduser_cookie( true );
	}

	/**
	 * Adds a 'Switch To' button in the personal options section of the profile.
	 *
	 * @param \WP_User $user The user being edited.
	 */
	public function show_switch_to_user_option_in_profile( $user ): void {
		if ( get_current_user_id() === $user->ID ) {
			return;
		}
		if ( ! current_user_can( 'switch_user_role', $user->ID ) ) {
			return;
		}

		$switch_url = self::need_switch_url( $user );
		if ( ! $switch_url ) {
			return;
		}
		?>
		<tr>
			<th scope="row"><?php esc_html_e( 'User Switching', 'ux-studio' ); ?></th>
			<td>
				<a href="<?php echo esc_url( $switch_url ); ?>" class="button button-secondary">
					<?php esc_html_e( 'Switch to this user', 'ux-studio' ); ?>
				</a>
				<p class="description">
					<?php esc_html_e( 'Instantly switch to this user account.', 'ux-studio' ); ?>
				</p>
			</td>
		</tr>
		<?php
	}

	/**
	 * Users the current user can switch to.
	 *
	 * @return \WP_User[]
	 */
	public static function users_available_to_switch_to(): array {
		if ( ! current_user_can( 'list_users' ) || ! current_user_can( 'edit_users' ) ) {
			return array();
		}

		$users = get_users(
			array(
				'exclude' => array( get_current_user_id() ),
				'fields'  => array( 'ID', 'user_login', 'display_name', 'user_email' ),
			)
		);

		$available = array();
		foreach ( $users as $user ) {
			if ( current_user_can( 'switch_user_role', $user->ID ) ) {
				$available[] = $user;
			}
		}

		return $available;
	}

	/* ----------------------------------------------------------------------
	 * Favourite user (merged from legacy pro)
	 * ------------------------------------------------------------------- */

	/**
	 * Reads the favourite user id, falling back to the legacy meta key.
	 *
	 * @param int $user_id User ID.
	 * @return int
	 */
	private function get_switch_user_default( int $user_id ): int {
		$value = get_user_meta( $user_id, 'uxstudio_switch_user_default', true );
		if ( '' === $value || false === $value ) {
			$value = get_user_meta( $user_id, 'wpextended_switch_user_default', true ); // Legacy fallback.
		}
		return (int) $value;
	}

	/**
	 * Adds the favourite user quick switch option to the admin bar.
	 *
	 * @param \WP_Admin_Bar $wp_admin_bar The admin bar object.
	 */
	public function add_switch_user_default_to_admin_bar( $wp_admin_bar ): void {
		if ( ! is_admin_bar_showing() ) {
			return;
		}

		$parent = $wp_admin_bar->get_node( 'user-actions' ) ? 'user-actions' : null;
		if ( ! $parent ) {
			return;
		}

		$current_user     = wp_get_current_user();
		$favorite_user_id = $this->get_switch_user_default( $current_user->ID );
		if ( ! $favorite_user_id ) {
			return;
		}

		$favorite_user = get_userdata( $favorite_user_id );
		if ( ! $favorite_user || ! current_user_can( 'switch_user_role', $favorite_user->ID ) ) {
			return;
		}

		$switch_url = self::need_switch_url( $favorite_user );
		if ( ! $switch_url ) {
			return;
		}

		$wp_admin_bar->add_node(
			array(
				'parent' => $parent,
				'id'     => 'switch-to-favorite',
				'title'  => sprintf(
					/* translators: %s: user display name */
					esc_html__( 'Switch to %s', 'ux-studio' ),
					$favorite_user->display_name
				),
				'href'   => add_query_arg(
					array( 'redirect_to' => rawurlencode( self::current_url() ) ),
					$switch_url
				),
			)
		);
	}

	/**
	 * Displays the favourite user selection field in the profile.
	 *
	 * @param \WP_User $user The user being edited.
	 */
	public function show_switch_user_default_field( $user ): void {
		if ( get_current_user_id() !== $user->ID && ! current_user_can( 'edit_user', $user->ID ) ) {
			return;
		}

		$available_users = self::users_available_to_switch_to();
		if ( empty( $available_users ) ) {
			return;
		}

		$current_favorite = $this->get_switch_user_default( $user->ID );
		?>
		<tr>
			<th scope="row"><?php esc_html_e( 'Switch User', 'ux-studio' ); ?></th>
			<td>
				<select name="uxstudio_switch_user_default" id="uxstudio_switch_user_default" class="regular-text">
					<option value=""><?php esc_html_e( 'None', 'ux-studio' ); ?></option>
					<?php foreach ( $available_users as $available_user ) : ?>
						<?php
						$display_text = $available_user->display_name;
						if ( $display_text !== $available_user->user_email ) {
							$display_text .= sprintf( ' (%s)', $available_user->user_email );
						}
						?>
						<option value="<?php echo esc_attr( $available_user->ID ); ?>" <?php selected( $current_favorite, $available_user->ID ); ?>>
							<?php echo esc_html( $display_text ); ?>
						</option>
					<?php endforeach; ?>
				</select>
				<p class="description">
					<?php esc_html_e( 'Select a user to add a default switch option in your admin bar.', 'ux-studio' ); ?>
				</p>
			</td>
		</tr>
		<?php
	}

	/**
	 * Saves the favourite user selection.
	 *
	 * @param int $user_id The user ID.
	 */
	public function save_switch_user_default( $user_id ): void {
		if ( ! current_user_can( 'edit_user', $user_id ) ) {
			return;
		}

		$nonce = isset( $_POST['_wpnonce'] ) ? sanitize_key( wp_unslash( $_POST['_wpnonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'update-user_' . $user_id ) ) {
			return;
		}

		if ( ! isset( $_POST['uxstudio_switch_user_default'] ) ) {
			delete_user_meta( $user_id, 'uxstudio_switch_user_default' );
			return;
		}

		$favorite_user_id = absint( $_POST['uxstudio_switch_user_default'] );

		if ( ! $favorite_user_id || ! current_user_can( 'switch_user_role', $favorite_user_id ) ) {
			delete_user_meta( $user_id, 'uxstudio_switch_user_default' );
			return;
		}

		update_user_meta( $user_id, 'uxstudio_switch_user_default', $favorite_user_id );
	}
}

/**
 * Gets the value of the cookie containing the originating user.
 *
 * @return string|false
 */
function uxstudio_user_switching_get_olduser_cookie() {
	if ( ! isset( $_COOKIE[ UXSTUDIO_SWITCHING_OLDUSER_COOKIE ] ) ) {
		return false;
	}
	return wp_unslash( $_COOKIE[ UXSTUDIO_SWITCHING_OLDUSER_COOKIE ] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
}

/**
 * Gets the auth cookie stack containing the list of originating users.
 *
 * @return array<int, string>
 */
function uxstudio_user_switching_get_auth_cookie(): array {
	$auth_cookie_name = Module::secure_auth_cookie()
		? UXSTUDIO_SWITCHING_SECURE_COOKIE
		: UXSTUDIO_SWITCHING_COOKIE;

	$cookie = null;
	if ( isset( $_COOKIE[ $auth_cookie_name ] ) && is_string( $_COOKIE[ $auth_cookie_name ] ) ) {
		$cookie = json_decode( wp_unslash( $_COOKIE[ $auth_cookie_name ] ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
	}

	if ( ! isset( $cookie ) || ! is_array( $cookie ) ) {
		$cookie = array();
	}

	return $cookie;
}

/**
 * Switches the current logged in user to the specified user.
 *
 * @param int  $user_id         The ID of the user to switch to.
 * @param bool $switch_remember Whether to remember the login.
 * @param bool $set_old_user    Whether to set the old user cookie.
 * @return \WP_User|false
 */
function uxstudio_user_switching_switch_to_user( $user_id, $switch_remember = false, $set_old_user = true ) {
	$user = get_userdata( $user_id );
	if ( ! $user ) {
		return false;
	}

	$old_user_id  = is_user_logged_in() ? get_current_user_id() : false;
	$old_token    = function_exists( 'wp_get_session_token' ) ? wp_get_session_token() : '';
	$auth_cookies = uxstudio_user_switching_get_auth_cookie();
	$auth_cookie  = end( $auth_cookies );
	$cookie_parts = $auth_cookie ? wp_parse_auth_cookie( $auth_cookie ) : false;

	if ( $set_old_user && $old_user_id ) {
		// Switching to another user.
		$new_token = '';
		uxstudio_user_switching_set_olduser_cookie( $old_user_id, false, $old_token );
	} else {
		// Switching back, either after switch off or after switching to another user.
		$new_token = ( $cookie_parts && isset( $cookie_parts['token'] ) ) ? $cookie_parts['token'] : '';
		Module::clear_olduser_cookie( false );
	}

	/** Attach the original user ID and session token to the new session. */
	$session_filter = function ( array $session ) use ( $old_user_id, $old_token ) {
		$session['switched_from_id']      = $old_user_id;
		$session['switched_from_session'] = $old_token;
		return $session;
	};

	add_filter( 'ux_studio/user_switching/attach_session_information', $session_filter, 99, 2 );

	wp_clear_auth_cookie();
	wp_set_auth_cookie( $user_id, $switch_remember, '', $new_token );
	wp_set_current_user( $user_id );

	remove_filter( 'ux_studio/user_switching/attach_session_information', $session_filter, 99 );

	if ( $set_old_user && $old_user_id ) {
		/**
		 * Fires when a user switches to another user account.
		 *
		 * @param int    $user_id     Target user ID.
		 * @param int    $old_user_id Original user ID.
		 * @param string $new_token   New session token.
		 * @param string $old_token   Old session token.
		 */
		do_action( 'ux_studio/user_switching/switch_to_user', $user_id, $old_user_id, $new_token, $old_token );
	} else {
		/**
		 * Fires when a user switches back to their originating account.
		 *
		 * @param int    $user_id     Target user ID.
		 * @param int    $old_user_id Original user ID.
		 * @param string $new_token   New session token.
		 * @param string $old_token   Old session token.
		 */
		do_action( 'ux_studio/user_switching/switch_back_user', $user_id, $old_user_id, $new_token, $old_token );
	}

	if ( $old_token && $old_user_id && ! $set_old_user ) {
		// When switching back, destroy the session for the old user.
		$manager = \WP_Session_Tokens::get_instance( $old_user_id );
		$manager->destroy( $old_token );
	}

	return $user;
}

/**
 * Switches off the current logged in user (temporary logout).
 *
 * @return bool
 */
function uxstudio_user_switching_switch_off_user(): bool {
	$old_user_id = get_current_user_id();
	if ( ! $old_user_id ) {
		return false;
	}

	$old_token = wp_get_session_token();

	uxstudio_user_switching_set_olduser_cookie( $old_user_id, false, $old_token );
	wp_clear_auth_cookie();
	wp_set_current_user( 0 );

	/**
	 * Fires when a user switches off.
	 *
	 * @param int    $old_user_id Original user ID.
	 * @param string $old_token   Old session token.
	 */
	do_action( 'ux_studio/user_switching/switch_off_user', $old_user_id, $old_token );

	return true;
}

/**
 * Sets authorisation cookies containing the originating user information.
 *
 * @param int    $old_user_id The ID of the old user.
 * @param bool   $pop         Whether to pop the auth cookie stack.
 * @param string $token       The auth token.
 */
function uxstudio_user_switching_set_olduser_cookie( $old_user_id, $pop = false, $token = '' ): void {
	$secure_auth_cookie    = Module::secure_auth_cookie();
	$secure_olduser_cookie = Module::secure_olduser_cookie();
	$expiration            = time() + 172800; // 48 hours.
	$auth_cookie           = uxstudio_user_switching_get_auth_cookie();
	$olduser_cookie        = wp_generate_auth_cookie( $old_user_id, $expiration, 'logged_in', $token );

	$auth_cookie_name = $secure_auth_cookie ? UXSTUDIO_SWITCHING_SECURE_COOKIE : UXSTUDIO_SWITCHING_COOKIE;
	$scheme           = $secure_auth_cookie ? 'secure_auth' : 'auth';

	if ( $pop ) {
		array_pop( $auth_cookie );
	} else {
		array_push( $auth_cookie, wp_generate_auth_cookie( $old_user_id, $expiration, $scheme, $token ) );
	}

	$auth_cookie = wp_json_encode( $auth_cookie );
	if ( false === $auth_cookie ) {
		return;
	}

	/**
	 * Fires immediately before the auth cookie is set.
	 *
	 * @param string $auth_cookie The auth cookie value.
	 * @param int    $expiration  Expiration timestamp.
	 * @param int    $old_user_id Old user ID.
	 * @param string $scheme      Auth scheme.
	 * @param string $token       Auth token.
	 */
	do_action( 'ux_studio/user_switching/set_auth_cookie', $auth_cookie, $expiration, $old_user_id, $scheme, $token );

	$scheme = 'logged_in';

	/**
	 * Fires immediately before the old user cookie is set.
	 *
	 * @param string $olduser_cookie The old user cookie value.
	 * @param int    $expiration     Expiration timestamp.
	 * @param int    $old_user_id    Old user ID.
	 * @param string $scheme         Auth scheme.
	 * @param string $token          Auth token.
	 */
	do_action( 'ux_studio/user_switching/set_olduser_cookie', $olduser_cookie, $expiration, $old_user_id, $scheme, $token );

	/** Allows preventing auth cookies from actually being sent to the client. */
	if ( ! apply_filters( 'ux_studio/user_switching/send_auth_cookies', true ) ) {
		return;
	}

	setcookie( $auth_cookie_name, $auth_cookie, $expiration, SITECOOKIEPATH, COOKIE_DOMAIN, $secure_auth_cookie, true );
	setcookie( UXSTUDIO_SWITCHING_OLDUSER_COOKIE, $olduser_cookie, $expiration, COOKIEPATH, COOKIE_DOMAIN, $secure_olduser_cookie, true );
}
