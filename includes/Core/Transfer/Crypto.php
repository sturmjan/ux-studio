<?php
/**
 * Passphrase encryption of the secrets block of an export (libsodium, argon2id + XSalsa20-Poly1305).
 *
 * @package UxStudio
 */

namespace UxStudio\Core\Transfer;

defined( 'ABSPATH' ) || exit;

/**
 * Only the secrets block is encrypted; the rest of the file is readable on purpose (a person can see what
 * it changes). Format of the block (`secrets` in the file):
 *
 *   { "kdf": "argon2id", "v": 1, "opslimit": 3, "memlimit": 67108864,
 *     "salt": base64, "nonce": base64, "ciphertext": base64 }
 *
 * The key is derived with sodium_crypto_pwhash from the passphrase and a random 16 byte salt, the plaintext
 * is a JSON object (module id => setting key => value) sealed with sodium_crypto_secretbox and a random
 * 24 byte nonce. secretbox is authenticated: a wrong passphrase and a damaged file both simply fail to open,
 * and nothing about the secrets is revealed either way. No passphrase and no plaintext is ever logged.
 */
final class Crypto {

	public const KDF         = 'argon2id';
	public const VERSION     = 1;
	public const MIN_PASS    = 12;
	public const MAX_PASS    = 1024;
	private const MAX_OPS    = 8;
	private const MAX_MEM    = 268435456; // 256 MB: an upper bound for what a (possibly hostile) file may ask for.
	private const MIN_MEM    = 8192;

	/**
	 * Is libsodium with argon2id there? (WordPress ships a pure PHP fallback for secretbox but none for argon2id.)
	 */
	public static function available(): bool {
		// WordPress defines pure PHP stand-ins for the sodium functions; argon2id has none that works, so the real extension counts.
		return extension_loaded( 'sodium' )
			&& function_exists( 'sodium_crypto_pwhash' )
			&& function_exists( 'sodium_crypto_secretbox' )
			&& defined( 'SODIUM_CRYPTO_PWHASH_ALG_ARGON2ID13' )
			&& defined( 'SODIUM_CRYPTO_PWHASH_SALTBYTES' );
	}

	/**
	 * Check a new passphrase and its confirmation.
	 *
	 * @param string $pass    Passphrase.
	 * @param string $confirm Confirmation.
	 * @return string Empty when fine, otherwise an error code: `short`, `long`, `mismatch`.
	 */
	public static function check_new_passphrase( string $pass, string $confirm ): string {
		$length = function_exists( 'mb_strlen' ) ? mb_strlen( $pass, 'UTF-8' ) : strlen( $pass );
		if ( $length < self::MIN_PASS ) {
			return 'short';
		}
		if ( strlen( $pass ) > self::MAX_PASS ) {
			return 'long';
		}
		return hash_equals( $pass, $confirm ) ? '' : 'mismatch';
	}

	/**
	 * Encrypt a secrets map.
	 *
	 * @param array<string, array<string, string>> $secrets Module id => key => value.
	 * @param string                               $pass    Passphrase.
	 * @return array<string, mixed>|null The block for the file, null when sodium is missing or the key derivation failed.
	 */
	public static function encrypt( array $secrets, string $pass ): ?array {
		if ( ! self::available() ) {
			return null;
		}
		$plain = json_encode( $secrets, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		if ( false === $plain ) {
			return null;
		}
		$ops = defined( 'SODIUM_CRYPTO_PWHASH_OPSLIMIT_MODERATE' ) ? (int) SODIUM_CRYPTO_PWHASH_OPSLIMIT_MODERATE : 3;
		$mem = defined( 'SODIUM_CRYPTO_PWHASH_MEMLIMIT_INTERACTIVE' ) ? (int) SODIUM_CRYPTO_PWHASH_MEMLIMIT_INTERACTIVE : 67108864;
		try {
			$salt  = random_bytes( SODIUM_CRYPTO_PWHASH_SALTBYTES );
			$nonce = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
			$key   = sodium_crypto_pwhash( SODIUM_CRYPTO_SECRETBOX_KEYBYTES, $pass, $salt, $ops, $mem, SODIUM_CRYPTO_PWHASH_ALG_ARGON2ID13 );
			$box   = sodium_crypto_secretbox( $plain, $nonce, $key );
		} catch ( \Throwable $e ) {
			return null;
		}
		sodium_memzero( $key );
		sodium_memzero( $plain );
		return array(
			'kdf'        => self::KDF,
			'v'          => self::VERSION,
			'opslimit'   => $ops,
			'memlimit'   => $mem,
			'salt'       => base64_encode( $salt ),
			'nonce'      => base64_encode( $nonce ),
			'ciphertext' => base64_encode( $box ),
		);
	}

	/**
	 * Is the block shaped like one we wrote (before any expensive work)?
	 *
	 * @param mixed $block Value of `secrets` from the file.
	 */
	public static function valid_block( $block ): bool {
		if ( ! is_array( $block ) || self::KDF !== ( $block['kdf'] ?? null ) || 1 !== (int) ( $block['v'] ?? 0 ) ) {
			return false;
		}
		foreach ( array( 'salt', 'nonce', 'ciphertext' ) as $part ) {
			if ( ! isset( $block[ $part ] ) || ! is_string( $block[ $part ] ) || false === base64_decode( $block[ $part ], true ) ) {
				return false;
			}
		}
		$ops = (int) ( $block['opslimit'] ?? 0 );
		$mem = (int) ( $block['memlimit'] ?? 0 );
		return $ops >= 1 && $ops <= self::MAX_OPS && $mem >= self::MIN_MEM && $mem <= self::MAX_MEM;
	}

	/**
	 * Open the block.
	 *
	 * @param mixed  $block Value of `secrets` from the file.
	 * @param string $pass  Passphrase.
	 * @return array<string, array<string, string>>|null Null for a wrong passphrase, a damaged block or missing sodium
	 *                                                    (the caller says one message for all of them).
	 */
	public static function decrypt( $block, string $pass ): ?array {
		if ( ! self::available() || ! self::valid_block( $block ) || '' === $pass || strlen( $pass ) > self::MAX_PASS ) {
			return null;
		}
		$salt  = (string) base64_decode( $block['salt'], true );
		$nonce = (string) base64_decode( $block['nonce'], true );
		$box   = (string) base64_decode( $block['ciphertext'], true );
		if ( strlen( $salt ) !== SODIUM_CRYPTO_PWHASH_SALTBYTES || strlen( $nonce ) !== SODIUM_CRYPTO_SECRETBOX_NONCEBYTES || strlen( $box ) < SODIUM_CRYPTO_SECRETBOX_MACBYTES ) {
			return null;
		}
		try {
			$key   = sodium_crypto_pwhash( SODIUM_CRYPTO_SECRETBOX_KEYBYTES, $pass, $salt, (int) $block['opslimit'], (int) $block['memlimit'], SODIUM_CRYPTO_PWHASH_ALG_ARGON2ID13 );
			$plain = sodium_crypto_secretbox_open( $box, $nonce, $key );
		} catch ( \Throwable $e ) {
			return null;
		}
		sodium_memzero( $key );
		if ( false === $plain ) {
			return null;
		}
		$data = json_decode( $plain, true, 8 );
		sodium_memzero( $plain );
		if ( ! is_array( $data ) ) {
			return null;
		}
		$out = array();
		foreach ( $data as $module => $values ) {
			if ( ! is_string( $module ) || ! is_array( $values ) ) {
				return null;
			}
			foreach ( $values as $key_name => $value ) {
				if ( ! is_string( $key_name ) || ! is_string( $value ) ) {
					return null;
				}
				$out[ $module ][ $key_name ] = $value;
			}
		}
		return $out;
	}
}
