<?php
/**
 * TOTP (RFC 6238) without an external library.
 *
 * @package UxStudio
 */

namespace UxStudio\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Port of the Central app's `core/Totp.php` (SHA1, 30 s step, 6 digits, base32 secret as every
 * authenticator app expects it - RFC 4648, no padding). Pure functions, no WordPress calls, so the
 * RFC 6238 Appendix B vectors can be tested without a site.
 */
final class Totp {

	private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

	public const PERIOD = 30;
	public const DIGITS = 6;

	/**
	 * A new shared secret: 20 random bytes (160 bit, the minimum recommended for SHA1), base32.
	 */
	public static function generate_secret(): string {
		return self::base32_encode( random_bytes( 20 ) );
	}

	/**
	 * `otpauth://` URI for the authenticator app (the secret is typed in or the link is opened on the phone).
	 *
	 * @param string $secret        Base32 secret.
	 * @param string $account_label Account shown in the app (user login or e-mail).
	 * @param string $issuer        Site name.
	 */
	public static function provisioning_uri( string $secret, string $account_label, string $issuer ): string {
		return sprintf(
			'otpauth://totp/%s:%s?secret=%s&issuer=%s&algorithm=SHA1&digits=%d&period=%d',
			rawurlencode( $issuer ),
			rawurlencode( $account_label ),
			$secret,
			rawurlencode( $issuer ),
			self::DIGITS,
			self::PERIOD
		);
	}

	/**
	 * The code for a moment (default: now).
	 *
	 * @param string   $secret    Base32 secret.
	 * @param int|null $timestamp Unix time.
	 * @param int      $digits    Code length (RFC 6238 Appendix B uses 8).
	 */
	public static function code( string $secret, ?int $timestamp = null, int $digits = self::DIGITS ): string {
		return self::hotp( $secret, intdiv( $timestamp ?? time(), self::PERIOD ), $digits );
	}

	/**
	 * Check a code with a tolerance of +-$window steps (a phone clock that runs a little off).
	 * Returns the time-step counter that matched, so the caller can refuse the same step twice
	 * (replay), or null when nothing matched. The comparison is constant time.
	 *
	 * @param string   $secret    Base32 secret.
	 * @param string   $code      What the user typed (spaces are ignored).
	 * @param int      $window    Steps before and after the current one.
	 * @param int|null $timestamp Unix time (tests), default now.
	 */
	public static function verify( string $secret, string $code, int $window = 1, ?int $timestamp = null ): ?int {
		$code = (string) preg_replace( '/\s+/', '', $code );
		if ( 1 !== preg_match( '/^\d{6}$/', $code ) ) {
			return null;
		}
		$counter = intdiv( $timestamp ?? time(), self::PERIOD );
		$found   = null;
		// Every step is compared (no early return), so the time does not tell which step matched.
		for ( $i = -$window; $i <= $window; $i++ ) {
			if ( hash_equals( self::hotp( $secret, $counter + $i ), $code ) && null === $found ) {
				$found = $counter + $i;
			}
		}
		return $found;
	}

	/**
	 * Secret in groups of four, for reading from the screen.
	 *
	 * @param string $secret Base32 secret.
	 */
	public static function format_for_display( string $secret ): string {
		return trim( chunk_split( $secret, 4, ' ' ) );
	}

	/**
	 * HOTP value of a counter (RFC 4226 dynamic truncation).
	 *
	 * @param string $base32_secret Base32 secret.
	 * @param int    $counter       Moving factor.
	 * @param int    $digits        Code length.
	 */
	private static function hotp( string $base32_secret, int $counter, int $digits = self::DIGITS ): string {
		$key  = self::base32_decode( $base32_secret );
		$hash = hash_hmac( 'sha1', pack( 'N*', 0, $counter ), $key, true );
		$off  = ord( $hash[19] ) & 0x0F;
		$part = ( ( ord( $hash[ $off ] ) & 0x7F ) << 24 )
			| ( ( ord( $hash[ $off + 1 ] ) & 0xFF ) << 16 )
			| ( ( ord( $hash[ $off + 2 ] ) & 0xFF ) << 8 )
			| ( ord( $hash[ $off + 3 ] ) & 0xFF );
		return str_pad( (string) ( $part % ( 10 ** $digits ) ), $digits, '0', STR_PAD_LEFT );
	}

	/**
	 * RFC 4648 base32 (no padding).
	 *
	 * @param string $binary Raw bytes.
	 */
	public static function base32_encode( string $binary ): string {
		$bits = '';
		foreach ( str_split( $binary ) as $byte ) {
			$bits .= str_pad( decbin( ord( $byte ) ), 8, '0', STR_PAD_LEFT );
		}
		$out = '';
		foreach ( str_split( $bits, 5 ) as $chunk ) {
			$out .= self::ALPHABET[ bindec( str_pad( $chunk, 5, '0', STR_PAD_RIGHT ) ) ];
		}
		return $out;
	}

	/**
	 * Inverse of base32_encode(); characters outside the alphabet are dropped.
	 *
	 * @param string $base32 Encoded text.
	 */
	public static function base32_decode( string $base32 ): string {
		$base32 = strtoupper( (string) preg_replace( '/[^A-Z2-7]/i', '', $base32 ) );
		$bits   = '';
		foreach ( str_split( $base32 ) as $char ) {
			$pos = strpos( self::ALPHABET, $char );
			if ( false !== $pos ) {
				$bits .= str_pad( decbin( $pos ), 5, '0', STR_PAD_LEFT );
			}
		}
		$bytes = '';
		foreach ( str_split( $bits, 8 ) as $chunk ) {
			if ( 8 === strlen( $chunk ) ) {
				$bytes .= chr( bindec( $chunk ) );
			}
		}
		return $bytes;
	}
}
