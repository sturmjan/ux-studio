<?php
/**
 * Source: Limit Login Attempts Reloaded, WPS Hide Login, Loginizer.
 *
 * @package UxStudio
 */

namespace UxStudio\Core\Migration\Sources;

use UxStudio\Core\Migration\AbstractSource;
use UxStudio\Core\Migration\Mapping;

defined( 'ABSPATH' ) || exit;

/**
 * Target: Security Optimization login keys (`limit_login_attempts`, `max_attempts`,
 * `lockout_time` in minutes, `notify_admin`, `login_url_enabled`, `login_url`,
 * `disabled_behavior`, `url_redirect`).
 */
final class LoginSource extends AbstractSource {

	private const MODULE = 'security-optimization';

	protected function option_names(): array {
		switch ( $this->slug ) {
			case 'wps-hide-login':
				return array( 'whl_page', 'whl_redirect_admin' );
			case 'loginizer':
				return array( 'loginizer_options' );
			default:
				return array( 'limit_login_allowed_retries', 'limit_login_lockout_duration', 'limit_login_allowed_lockouts', 'limit_login_lockout_notify' );
		}
	}

	public function smoke_checks(): array {
		if ( 'wps-hide-login' !== $this->slug ) {
			return array();
		}
		$settings = (array) ( $this->mapping()->settings[ self::MODULE ] ?? array() );
		$slug     = trim( (string) ( $settings['login_url'] ?? '' ), '/' );
		if ( empty( $settings['login_url_enabled'] ) || '' === $slug ) {
			return array();
		}
		return array(
			array(
				'id'       => 'login',
				'label'    => __( 'New login address works, wp-login.php is closed', 'ux-studio' ),
				'type'     => 'login_slug',
				'slug'     => $slug,
				'behavior' => (string) ( $settings['disabled_behavior'] ?? '404' ),
				'redirect' => (string) ( $settings['url_redirect'] ?? '' ),
				'replaces' => array( 'wp-login' ),
			),
		);
	}

	protected function map( bool $active ): Mapping {
		switch ( $this->slug ) {
			case 'wps-hide-login':
				return $this->map_wps( $active );
			case 'loginizer':
				return $this->map_loginizer( $active );
			default:
				return $this->map_llar( $active );
		}
	}

	/**
	 * Limit Login Attempts Reloaded: `limit_login_*` options (durations in seconds).
	 *
	 * @param bool $active Plugin active.
	 */
	private function map_llar( bool $active ): Mapping {
		$m = new Mapping();
		if ( ! $active && ! $this->has_data() ) {
			$m->note( __( 'The plugin has no stored settings, there is nothing to transfer.', 'ux-studio' ) );
			return $m;
		}
		$retries  = $this->opt( 'limit_login_allowed_retries' );
		$duration = $this->opt( 'limit_login_lockout_duration' );
		$notify   = $this->opt( 'limit_login_lockout_notify' );

		$m->set( self::MODULE, 'limit_login_attempts', true, __( 'Limit login attempts', 'ux-studio' ), 'limit_login_allowed_retries' );
		$m->set( self::MODULE, 'max_attempts', (int) ( $retries ?? 4 ), __( 'Max attempts', 'ux-studio' ), 'limit_login_allowed_retries' );
		$m->set( self::MODULE, 'lockout_time', max( 1, (int) round( (int) ( $duration ?? 1200 ) / 60 ) ), __( 'Lockout time (minutes)', 'ux-studio' ), 'limit_login_lockout_duration' );
		$m->set( self::MODULE, 'notify_admin', in_array( 'email', explode( ',', (string) ( $notify ?? 'email' ) ), true ), __( 'Email admin on lockout', 'ux-studio' ), 'limit_login_lockout_notify' );

		$m->lossy(
			sprintf(
				/* translators: 1: number of lockouts, 2: hours. */
				__( 'Longer lockouts after repeated lockouts (the plugin: after %1$d lockouts for %2$d h) do not exist in the module, every lockout lasts the same time.', 'ux-studio' ),
				(int) ( $this->opt( 'limit_login_allowed_lockouts' ) ?? 4 ),
				(int) round( (int) ( $this->opt( 'limit_login_long_duration' ) ?? 86400 ) / 3600 )
			)
		);
		$lists = array();
		foreach ( array( 'whitelist', 'blacklist', 'whitelist_usernames', 'blacklist_usernames' ) as $list ) {
			if ( ! empty( $this->opt( 'limit_login_' . $list ) ) ) {
				$lists[] = $list;
			}
		}
		if ( $lists ) {
			$m->lossy( __( 'The lists of allowed and blocked IP addresses and user names are not transferred. Add them in the Firewall of Security Optimization.', 'ux-studio' ) );
		}
		if ( 'local' !== (string) ( $this->opt( 'limit_login_active_app' ) ?? 'local' ) ) {
			$m->lossy( __( 'The cloud protection of the plugin has no equivalent in the module.', 'ux-studio' ) );
		}
		return $m;
	}

	/**
	 * Loginizer: `loginizer_options` { max_retries, lockout_time (s), max_lockouts, lockouts_extend (s),
	 * notify_email (lockouts before a mail, 0 = never), notify_email_address }.
	 *
	 * @param bool $active Plugin active.
	 */
	private function map_loginizer( bool $active ): Mapping {
		$m = new Mapping();
		$o = $this->opt( 'loginizer_options' );
		if ( ! is_array( $o ) && ! $active ) {
			$m->note( __( 'The plugin has no stored settings, there is nothing to transfer.', 'ux-studio' ) );
			return $m;
		}
		$o = is_array( $o ) ? $o : array();

		$m->set( self::MODULE, 'limit_login_attempts', empty( $this->opt( 'loginizer_disable_brute' ) ), __( 'Limit login attempts', 'ux-studio' ), 'loginizer_disable_brute' );
		$m->set( self::MODULE, 'max_attempts', (int) ( empty( $o['max_retries'] ) ? 3 : $o['max_retries'] ), __( 'Max attempts', 'ux-studio' ), 'loginizer_options:max_retries' );
		$m->set( self::MODULE, 'lockout_time', max( 1, (int) round( (int) ( empty( $o['lockout_time'] ) ? 900 : $o['lockout_time'] ) / 60 ) ), __( 'Lockout time (minutes)', 'ux-studio' ), 'loginizer_options:lockout_time' );
		$m->set( self::MODULE, 'notify_admin', ! empty( $o['notify_email'] ), __( 'Email admin on lockout', 'ux-studio' ), 'loginizer_options:notify_email' );

		$m->lossy( __( 'Longer lockouts after repeated lockouts do not exist in the module, every lockout lasts the same time.', 'ux-studio' ) );
		if ( ! empty( $o['notify_email_address'] ) ) {
			$m->lossy( __( 'The module e-mails the site administrator; the separate address set in Loginizer is not transferred.', 'ux-studio' ) );
		}
		if ( ! empty( $this->opt( 'loginizer_blacklist' ) ) || ! empty( $this->opt( 'loginizer_whitelist' ) ) ) {
			$m->lossy( __( 'The lists of allowed and blocked IP addresses are not transferred. Add them in the Firewall of Security Optimization.', 'ux-studio' ) );
		}
		$m->lossy( __( 'Loginizer CAPTCHA, two-factor authentication, passwordless login and social login are not transferred.', 'ux-studio' ) );
		return $m;
	}

	/**
	 * WPS Hide Login: `whl_page` (slug, default `login`) and `whl_redirect_admin`
	 * (where a visitor of the old URL goes: `404` or a slug).
	 *
	 * @param bool $active Plugin active.
	 */
	private function map_wps( bool $active ): Mapping {
		$m    = new Mapping();
		$slug = trim( (string) ( $this->opt( 'whl_page' ) ?? '' ), '/' );
		if ( '' === $slug ) {
			if ( ! $active ) {
				$m->note( __( 'The plugin has no stored settings, there is nothing to transfer.', 'ux-studio' ) );
				return $m;
			}
			$slug = 'login'; // The plugin's default.
		}
		$redirect = trim( (string) ( $this->opt( 'whl_redirect_admin' ) ?? '' ), '/' );
		if ( '' === $redirect ) {
			$redirect = '404';
		}

		$m->set( self::MODULE, 'login_url_enabled', true, __( 'Enable custom login URL', 'ux-studio' ), 'whl_page' );
		$m->set( self::MODULE, 'login_url', $slug, __( 'Custom login slug', 'ux-studio' ), 'whl_page' );
		if ( false !== strpos( $redirect, '404' ) ) {
			$m->set( self::MODULE, 'disabled_behavior', '404', __( 'Direct wp-login.php behavior', 'ux-studio' ), 'whl_redirect_admin' );
		} else {
			$m->set( self::MODULE, 'disabled_behavior', 'url_redirect', __( 'Direct wp-login.php behavior', 'ux-studio' ), 'whl_redirect_admin' );
			$m->set( self::MODULE, 'url_redirect', $redirect, __( 'Redirect path', 'ux-studio' ), 'whl_redirect_admin' );
		}

		$base = function_exists( 'home_url' ) ? trailingslashit( home_url() ) : '/';
		$m->warn(
			sprintf(
				/* translators: %s: new login address. */
				__( 'Once the module is switched on, the login address changes for everyone to %s. Save the new address before you switch over, the old one stops working.', 'ux-studio' ),
				$base . $slug . '/'
			)
		);
		return $m;
	}
}
