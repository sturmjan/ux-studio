<?php
/**
 * Freshness of the current login (O4: writes need a login younger than 15 min).
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\DnsManager;

use WP_Session_Tokens;

defined( 'ABSPATH' ) || exit;

final class Reauth {

	public const MAX_AGE = 900;

	/**
	 * Unix time of the login that created the current session token, 0 if unknown.
	 */
	public static function login_time(): int {
		$uid = get_current_user_id();
		if ( $uid <= 0 ) {
			return 0;
		}
		$session = WP_Session_Tokens::get_instance( $uid )->get( wp_get_session_token() );
		return is_array( $session ) ? (int) ( $session['login'] ?? 0 ) : 0;
	}

	public static function is_fresh(): bool {
		$t = self::login_time();
		return $t > 0 && ( time() - $t ) <= self::MAX_AGE;
	}

	/**
	 * URL that forces a new login and returns to the DNS module.
	 */
	public static function login_url(): string {
		return wp_login_url( admin_url( 'admin.php?page=ux-studio#/module?id=dns-manager' ), true );
	}
}
