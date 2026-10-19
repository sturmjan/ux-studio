<?php
/**
 * Redeems an SSO token from the operator's browser (node side).
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\ContentSync;

use UxStudio\Core\ActivityLog;
use WP_User;

defined( 'ABSPATH' ) || exit;

/**
 * The operator's browser arrives at any URL with ?uxstudio-sso=TOKEN. We
 * validate TTL / UA / IP / single-use, map the operator to a WP user, log them
 * in via wp_set_auth_cookie() and redirect to a strictly whitelisted admin
 * path. Runs on `init` (priority 1) so it also fires on wp-login.php.
 */
final class SsoRedeemer {

	/** Session-token info key marking a session created by SSO. */
	private const SESSION_FLAG = 'uxstudio_sso';

	/** @var array<int, bool> Per-request cache: user id => session is SSO. */
	private static array $sso_session = array();

	/**
	 * user_has_cap: a session opened via SSO never gets unfiltered_html, so a
	 * leaked node key (-> editor session) can't plant stored XSS that would
	 * run in an administrator's browser.
	 *
	 * @param array<string, bool> $allcaps All caps of the user.
	 * @param array               $caps    Required primitive caps (unused).
	 * @param array               $args    Capability check args (unused).
	 * @param \WP_User|null       $user    User being checked.
	 * @return array<string, bool>
	 */
	public static function strip_unfiltered_html( $allcaps, $caps, $args, $user ) {
		if ( empty( $allcaps['unfiltered_html'] ) || ! $user instanceof WP_User || $user->ID !== get_current_user_id() ) {
			return $allcaps;
		}
		if ( ! isset( self::$sso_session[ $user->ID ] ) ) {
			$token   = wp_get_session_token();
			$session = '' !== $token ? \WP_Session_Tokens::get_instance( $user->ID )->get( $token ) : null;
			self::$sso_session[ $user->ID ] = is_array( $session ) && ! empty( $session[ self::SESSION_FLAG ] );
		}
		if ( self::$sso_session[ $user->ID ] ) {
			$allcaps['unfiltered_html'] = false;
		}
		return $allcaps;
	}

	public const QUERY_VAR = 'uxstudio-sso';

	/**
	 * Hook the redeemer.
	 */
	public function register(): void {
		add_action( 'init', array( $this, 'maybe_redeem' ), 1 );
		add_filter( 'user_has_cap', array( self::class, 'strip_unfiltered_html' ), 10, 4 );
	}

	/**
	 * Attempt redemption if the query var is present.
	 */
	public function maybe_redeem(): void {
		if ( empty( $_GET[ self::QUERY_VAR ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}
		$token = sanitize_text_field( wp_unslash( $_GET[ self::QUERY_VAR ] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! preg_match( '/^[a-f0-9]{32,128}$/i', $token ) ) {
			$this->fail( 'invalid_token_format' );
			return;
		}

		$row = SsoTokenStore::find_by_token( $token );
		if ( ! $row ) {
			$this->fail( 'token_not_found' );
			return;
		}

		$ip        = $this->client_ip();
		$max_fails = (int) Module::setting( 'sso_max_failed_redemptions', 5 );
		if ( $max_fails > 0 && SsoTokenStore::failed_for_ip( $ip, 60 ) >= $max_fails ) {
			$this->fail( 'rate_limited' );
			return;
		}

		if ( strtotime( (string) $row['expires_at'] ) < time() ) {
			SsoTokenStore::record_failure( (int) $row['id'] );
			$this->fail( 'token_expired' );
			return;
		}
		if ( ! empty( $row['consumed_at'] ) || ! empty( $row['revoked_at'] ) ) {
			SsoTokenStore::record_failure( (int) $row['id'] );
			$this->fail( 'token_used' );
			return;
		}

		if ( (bool) Module::setting( 'sso_require_ua_match', true ) ) {
			$ua_now = hash( 'sha256', isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '' );
			if ( ! empty( $row['ua_hash'] ) && ! hash_equals( (string) $row['ua_hash'], $ua_now ) ) {
				SsoTokenStore::record_failure( (int) $row['id'] );
				$this->fail( 'ua_mismatch' );
				return;
			}
		}
		if ( (bool) Module::setting( 'sso_require_ip_match', false ) ) {
			if ( ! empty( $row['ip'] ) && (string) $row['ip'] !== $ip ) {
				SsoTokenStore::record_failure( (int) $row['id'] );
				$this->fail( 'ip_mismatch' );
				return;
			}
		}

		$wp_user_id = (int) ( $row['user_id'] ?? 0 );
		if ( $wp_user_id > 0 ) {
			if ( ! get_user_by( 'id', $wp_user_id ) instanceof WP_User ) {
				SsoTokenStore::record_failure( (int) $row['id'] );
				$this->fail( 'cached_user_missing' );
				return;
			}
		} else {
			$mapped = SsoOperatorMapper::resolve(
				(string) $row['operator_email'],
				(int) $row['operator_id_central'],
				(string) $row['target_wp_role']
			);
			if ( empty( $mapped['user_id'] ) ) {
				SsoTokenStore::record_failure( (int) $row['id'] );
				$this->fail( 'user_mapping_failed:' . $mapped['source'] );
				return;
			}
			$wp_user_id = (int) $mapped['user_id'];
		}

		// Re-check at redeem time: the user may have been promoted, or the
		// setting switched off, since the token was issued (and the
		// resolve-at-redeem branch above was never checked at issue time).
		if ( SsoOperatorMapper::is_admin_level( $wp_user_id ) && ! Module::setting( 'sso_allow_admin', false ) ) {
			SsoTokenStore::record_failure( (int) $row['id'] );
			$this->fail( 'admin_sso_disabled' );
			return;
		}

		// Single-use: consume atomically; bail on a lost race.
		if ( ! SsoTokenStore::consume( (int) $row['id'] ) ) {
			$this->fail( 'race_condition' );
			return;
		}

		wp_clear_auth_cookie();
		wp_set_current_user( $wp_user_id );
		// Tag the new session so SSO sessions never get unfiltered_html.
		$tag = static function ( array $info ): array {
			$info[ self::SESSION_FLAG ] = true;
			return $info;
		};
		add_filter( 'attach_session_information', $tag );
		wp_set_auth_cookie( $wp_user_id, false, is_ssl() );
		remove_filter( 'attach_session_information', $tag );

		ActivityLog::log( 'content-sync', 'sso_login', 'user', $wp_user_id, array( 'operator_id' => (int) $row['operator_id_central'] ) );

		$return_to = $this->sanitize_return_to( (string) $row['return_to'] );
		wp_safe_redirect( admin_url() . ltrim( $return_to, '/' ), 302, 'uxstudio-content-sync' );
		exit;
	}

	/**
	 * Restrict return_to to a relative wp-admin path (no host/scheme injection).
	 *
	 * @param string $raw Raw return_to.
	 */
	private function sanitize_return_to( string $raw ): string {
		if ( '' === $raw || '/' !== $raw[0] || str_starts_with( $raw, '//' ) ) {
			return 'index.php';
		}
		$parts = wp_parse_url( $raw );
		if ( ! is_array( $parts ) || isset( $parts['host'] ) || isset( $parts['scheme'] ) ) {
			return 'index.php';
		}
		$path = (string) ( $parts['path'] ?? '' );
		if ( 0 !== strpos( $path, '/wp-admin/' ) ) {
			return 'index.php';
		}
		$rel = substr( $path, strlen( '/wp-admin/' ) );
		if ( ! empty( $parts['query'] ) ) {
			$rel .= '?' . $parts['query'];
		}
		return '' !== $rel ? $rel : 'index.php';
	}

	/**
	 * Best-effort client IP.
	 */
	private function client_ip(): string {
		return isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
	}

	/**
	 * Redirect to the login screen with an error code.
	 *
	 * @param string $reason Failure reason.
	 */
	private function fail( string $reason ): void {
		ActivityLog::log( 'content-sync', 'sso_failed', '', 0, array( 'reason' => $reason ) );
		wp_safe_redirect( wp_login_url() . '?uxstudio_sso_error=' . rawurlencode( $reason ), 302, 'uxstudio-content-sync' );
		exit;
	}
}
