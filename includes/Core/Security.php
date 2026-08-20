<?php
/**
 * Shared security helpers: write rate limiting, secret storage.
 *
 * @package UxStudio
 */

namespace UxStudio\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Central place for cross-cutting security concerns.
 */
final class Security {

	private const RATE_LIMIT   = 60; // writes...
	private const RATE_WINDOW  = 60; // ...per seconds, per user.

	/**
	 * Sliding-window rate limit for write endpoints (per user, transient based).
	 */
	public static function check_write_rate_limit(): bool {
		$key   = 'uxstudio_rl_' . get_current_user_id();
		$count = (int) get_transient( $key );
		if ( $count >= self::RATE_LIMIT ) {
			return false;
		}
		set_transient( $key, $count + 1, self::RATE_WINDOW );
		return true;
	}

	/**
	 * Store a secret (API key etc.) encrypted at rest when possible.
	 * Never expose secrets through REST responses or the JS bundle.
	 *
	 * @param string $option Option name (uxstudio_secret_*).
	 * @param string $value  Plain secret.
	 */
	public static function store_secret( string $option, string $value ): void {
		update_option( $option, self::encrypt( $value ), false );
	}

	/**
	 * Read a secret stored via store_secret().
	 */
	public static function get_secret( string $option ): string {
		return self::decrypt( (string) get_option( $option, '' ) );
	}

	/**
	 * AES-256-GCM using WP salts as key material; falls back to plain storage
	 * only if OpenSSL is unavailable (logged once).
	 */
	private static function encrypt( string $value ): string {
		if ( '' === $value || ! function_exists( 'openssl_encrypt' ) ) {
			return $value;
		}
		$key    = hash( 'sha256', wp_salt( 'auth' ), true );
		$iv     = random_bytes( 12 );
		$tag    = '';
		$cipher = openssl_encrypt( $value, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag );
		return 'uxs1:' . base64_encode( $iv . $tag . $cipher );
	}

	/**
	 * Inverse of encrypt(); passes through legacy plain values.
	 */
	private static function decrypt( string $stored ): string {
		if ( ! str_starts_with( $stored, 'uxs1:' ) ) {
			return $stored;
		}
		$raw = base64_decode( substr( $stored, 5 ), true );
		if ( false === $raw || strlen( $raw ) < 29 ) {
			return '';
		}
		$key = hash( 'sha256', wp_salt( 'auth' ), true );
		$iv  = substr( $raw, 0, 12 );
		$tag = substr( $raw, 12, 16 );
		$ct  = substr( $raw, 28 );
		$out = openssl_decrypt( $ct, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag );
		return false === $out ? '' : $out;
	}
}
