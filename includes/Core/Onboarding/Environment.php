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
		);
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
