<?php
/**
 * Looks at the live site and server for the starter pack (WordPress glue around StarterPack).
 *
 * @package UxStudio
 */

namespace UxStudio\Core\Onboarding;

use UxStudio\Core\Migration\Overlaps;
use UxStudio\Core\Modules;
use UxStudio\Modules\SecurityOptimization\LoginUrl;
use UxStudio\Modules\SecurityOptimization\Module as Security;

defined( 'ABSPATH' ) || exit;

/**
 * Read only. The result feeds StarterPack::items().
 */
final class Environment {

	/**
	 * Detect everything the starter pack depends on.
	 *
	 * @param Modules $modules Module registry (for the Security and Bot Throttle settings).
	 * @return array<string, mixed>
	 */
	public static function detect( Modules $modules ): array {
		// phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- server variables are only tested for presence or compared with fixed words.
		$software = isset( $_SERVER['SERVER_SOFTWARE'] ) ? (string) $_SERVER['SERVER_SOFTWARE'] : '';
		$cf       = ! empty( $_SERVER['HTTP_CF_CONNECTING_IP'] );
		$xff      = ! empty( $_SERVER['HTTP_X_FORWARDED_FOR'] );
		// phpcs:enable

		$security = $modules->instance( 'security-optimization' );
		$login    = $security instanceof Security ? (array) $security->settings_values() : array();
		$collides = false;
		if ( $security instanceof Security ) {
			$collides = $security->login_slug_collides( StarterPack::LOGIN_SLUG );
		}
		$bot      = $modules->instance( 'bot-throttle' );
		$bot_vals = $bot ? (array) $bot->settings_values() : array();

		$roles = function_exists( 'wp_roles' ) ? array_keys( (array) wp_roles()->roles ) : array();

		return array(
			'woocommerce'         => class_exists( 'WooCommerce' ),
			'cloudflare'          => $cf,
			'xff'                 => $xff,
			'server'              => StarterPack::server_family( $software ),
			'server_software'     => $software,
			'htaccess_writable'   => self::htaccess_writable(),
			'pretty_permalinks'   => LoginUrl::permalinks_are_pretty( (string) get_option( 'permalink_structure', '' ) ),
			'login_slug_collides' => $collides,
			'login_url_in_use'    => ! empty( $login['login_url_enabled'] ) || '' !== trim( (string) ( $login['login_url'] ?? '' ) ),
			'site_name'           => wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES ),
			'login_logo_id'       => self::logo_id(),
			'roles'               => $roles,
			'bot_blacklist'       => (string) ( $bot_vals['blacklist_ua'] ?? StarterPack::default_env()['bot_blacklist'] ),
			'bot_whitelist'       => (string) ( $bot_vals['whitelist_ua'] ?? '' ),
			'blocked_settings'    => Overlaps::blocked_settings( 'security-optimization' ),
			'csp_mode'            => (string) ( $login['csp_mode'] ?? 'off' ),
			'reserved_users'      => $security instanceof Security ? self::reserved_users( $security ) : array(),
			'app_passwords'       => self::application_passwords(),
			'published_posts'     => self::published_posts(),
			'feed_plugins'        => self::plugin_names( StarterPack::FEED_PLUGINS ),
			'headless_plugins'    => self::plugin_names( StarterPack::HEADLESS_PLUGINS ),
			'header_plugins'      => self::plugin_names( StarterPack::HEADER_PLUGINS ),
			'captcha_set'         => ! empty( $login['captcha_enabled'] ) || '' !== trim( (string) ( $login['captcha_site_key'] ?? '' ) ) || ! empty( $login['has_captcha_secret_key'] ),
			'https_ready'         => 0 === strpos( home_url(), 'https://' ) && 0 === strpos( site_url(), 'https://' ) && is_ssl(),
		);
	}

	/**
	 * Logins of existing users that the Security module would treat as reserved (same matching as its block list).
	 *
	 * @param Security $security Security module.
	 * @return string[]
	 */
	private static function reserved_users( Security $security ): array {
		$out = array();
		foreach ( (array) get_users( array( 'fields' => array( 'user_login' ), 'number' => 2000 ) ) as $user ) {
			if ( $security->is_blocked_username( (string) $user->user_login ) ) {
				$out[] = (string) $user->user_login;
			}
		}
		return $out;
	}

	/**
	 * How many users hold Application Passwords, and how many passwords that is.
	 *
	 * @return array{users:int,passwords:int}
	 */
	private static function application_passwords(): array {
		global $wpdb;
		$users     = 0;
		$passwords = 0;
		$rows      = $wpdb->get_col( "SELECT meta_value FROM {$wpdb->usermeta} WHERE meta_key = '_application_passwords'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		foreach ( (array) $rows as $raw ) {
			$list = maybe_unserialize( $raw );
			if ( is_array( $list ) && $list ) {
				++$users;
				$passwords += count( $list );
			}
		}
		return array(
			'users'     => $users,
			'passwords' => $passwords,
		);
	}

	/**
	 * Published posts of type post.
	 */
	private static function published_posts(): int {
		$counts = wp_count_posts( 'post' );
		return isset( $counts->publish ) ? (int) $counts->publish : 0;
	}

	/**
	 * Names of the active plugins from a list (folder slug => name).
	 *
	 * @param array<string, string> $known Plugins to look for.
	 * @return string[]
	 */
	private static function plugin_names( array $known ): array {
		$out = array();
		foreach ( Overlaps::active_plugins() as $basename ) {
			$slug = dirname( $basename );
			if ( isset( $known[ $slug ] ) ) {
				$out[ $slug ] = $known[ $slug ];
			}
		}
		return array_values( $out );
	}

	/**
	 * Site logo (customizer), else the site icon; 0 when neither is set.
	 */
	private static function logo_id(): int {
		$logo = function_exists( 'get_theme_mod' ) ? (int) get_theme_mod( 'custom_logo', 0 ) : 0;
		if ( $logo > 0 ) {
			return $logo;
		}
		return (int) get_option( 'site_icon', 0 );
	}

	/**
	 * Can WordPress write the .htaccess file (or create it)?
	 */
	private static function htaccess_writable(): bool {
		if ( ! function_exists( 'get_home_path' ) && defined( 'ABSPATH' ) && is_readable( ABSPATH . 'wp-admin/includes/file.php' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		$home = function_exists( 'get_home_path' ) ? get_home_path() : ( defined( 'ABSPATH' ) ? ABSPATH : '' );
		if ( '' === $home ) {
			return false;
		}
		$file = $home . '.htaccess';
		return file_exists( $file ) ? is_writable( $file ) : is_writable( $home );
	}
}
