<?php
/**
 * VAPID (Voluntary Application Server Identification) keypair management.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\PushNotifications;

use UxStudio\Core\Security;

defined( 'ABSPATH' ) || exit;

/**
 * Generates a P-256 EC keypair for Web Push via the openssl PHP extension
 * (no external library). The private key is stored encrypted at rest via
 * Security::store_secret() and is NEVER exposed through REST; the public key
 * is plain by definition (it ships to the browser as part of PushManager
 * .subscribe()) and is stored in a dedicated option.
 */
final class Vapid {

	private const SECRET_PRIVATE = 'uxstudio_secret_push_vapid_private';
	private const OPTION_PUBLIC  = 'uxstudio_push_vapid_public';

	/**
	 * openssl.cnf hint so openssl_pkey_new can find its config on Windows/XAMPP
	 * (on Linux the default config is used and this returns an empty array).
	 *
	 * @return array<string,string>
	 */
	private static function openssl_config(): array {
		foreach ( array( 'D:/xampp/apache/conf/openssl.cnf', 'D:/xampp/php/extras/ssl/openssl.cnf', 'D:/xampp/apache/bin/openssl.cnf' ) as $path ) {
			if ( file_exists( $path ) ) {
				return array( 'config' => $path );
			}
		}
		return array();
	}

	/**
	 * The current public key (base64url, uncompressed EC point), generating
	 * a fresh keypair on first access if none exists yet.
	 */
	public function public_key(): string {
		$public = (string) get_option( self::OPTION_PUBLIC, '' );
		if ( '' === $public || '' === Security::get_secret( self::SECRET_PRIVATE ) ) {
			// Prefer the legacy plugin's keypair so already-subscribed browsers
			// keep working; a fresh pair would orphan every existing subscriber.
			if ( ! $this->import_legacy() ) {
				$this->generate();
			}
			$public = (string) get_option( self::OPTION_PUBLIC, '' );
		}
		return $public;
	}

	/**
	 * Import the keypair of the legacy ux1 push-notifications module (option
	 * `ux1_push_vapid_keys`, AES-256-GCM with a key derived from AUTH_KEY, JSON
	 * of base64url raw public point + raw 32-byte private scalar). Only used
	 * when no keypair exists yet; the key is verified (the private scalar must
	 * reproduce the public point) before it is stored.
	 *
	 * @return bool True when a legacy keypair was imported.
	 */
	public function import_legacy(): bool {
		$blob = (string) get_option( 'ux1_push_vapid_keys', '' );
		if ( '' === $blob || ! function_exists( 'openssl_decrypt' ) || ! function_exists( 'openssl_pkey_get_private' ) ) {
			return false;
		}
		$raw = base64_decode( $blob, true );
		if ( false === $raw || strlen( $raw ) < 28 ) {
			return false;
		}
		$secret = defined( 'AUTH_KEY' ) ? (string) AUTH_KEY : 'ux1-push-default-key';
		$key    = hash( 'sha256', $secret . 'ux1-push-vapid', true );
		$plain  = openssl_decrypt( substr( $raw, 28 ), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, substr( $raw, 0, 12 ), substr( $raw, 12, 16 ) );
		$data   = false === $plain ? null : json_decode( $plain, true );
		if ( ! is_array( $data ) || empty( $data['publicKey'] ) || empty( $data['privateKey'] ) ) {
			return false;
		}

		$public  = WebPushCrypto::b64u_decode( (string) $data['publicKey'] );
		$private = WebPushCrypto::b64u_decode( (string) $data['privateKey'] );
		if ( 65 !== strlen( $public ) || "\x04" !== $public[0] || 32 !== strlen( $private ) ) {
			return false;
		}

		// SEC1 ECPrivateKey (RFC 5915) for prime256v1, wrapped as PEM.
		$der = "\x30\x77\x02\x01\x01\x04\x20" . $private
			. "\xa0\x0a\x06\x08\x2a\x86\x48\xce\x3d\x03\x01\x07"
			. "\xa1\x44\x03\x42\x00" . $public;
		$pem = "-----BEGIN EC PRIVATE KEY-----\n" . chunk_split( base64_encode( $der ), 64, "\n" ) . "-----END EC PRIVATE KEY-----\n";

		$resource = openssl_pkey_get_private( $pem );
		if ( false === $resource ) {
			return false;
		}
		$details = openssl_pkey_get_details( $resource );
		if ( ! is_array( $details ) || empty( $details['ec']['x'] ) || empty( $details['ec']['y'] ) ) {
			return false;
		}
		$derived = "\x04" . str_pad( (string) $details['ec']['x'], 32, "\x00", STR_PAD_LEFT ) . str_pad( (string) $details['ec']['y'], 32, "\x00", STR_PAD_LEFT );
		if ( ! hash_equals( $public, $derived ) ) {
			return false;
		}

		Security::store_secret( self::SECRET_PRIVATE, $pem );
		update_option( self::OPTION_PUBLIC, $this->base64url_encode( $public ), false );
		return true;
	}

	/** Whether a keypair currently exists. */
	public function has_keys(): bool {
		return '' !== (string) get_option( self::OPTION_PUBLIC, '' ) && '' !== Security::get_secret( self::SECRET_PRIVATE );
	}

	/**
	 * Generate (or regenerate) the VAPID keypair. Requires the openssl PHP
	 * extension with EC support.
	 *
	 * @return bool True on success.
	 */
	public function generate(): bool {
		if ( ! function_exists( 'openssl_pkey_new' ) ) {
			return false;
		}

		$resource = openssl_pkey_new(
			array_merge(
				array(
					'private_key_type' => OPENSSL_KEYTYPE_EC,
					'curve_name'       => 'prime256v1',
				),
				self::openssl_config()
			)
		);
		if ( false === $resource ) {
			return false;
		}

		$exported = openssl_pkey_export( $resource, $private_pem, null, self::openssl_config() );
		$details  = openssl_pkey_get_details( $resource );
		if ( ! $exported || false === $details || empty( $details['ec']['x'] ) || empty( $details['ec']['y'] ) ) {
			return false;
		}

		$x = str_pad( (string) $details['ec']['x'], 32, "\x00", STR_PAD_LEFT );
		$y = str_pad( (string) $details['ec']['y'], 32, "\x00", STR_PAD_LEFT );
		$public_point = "\x04" . $x . $y;

		Security::store_secret( self::SECRET_PRIVATE, $private_pem );
		update_option( self::OPTION_PUBLIC, $this->base64url_encode( $public_point ), false );

		return true;
	}

	/**
	 * The private key PEM, used by Sender/WebPushCrypto to sign the VAPID JWT
	 * sent with every Web Push request.
	 */
	public function private_key_pem(): string {
		return Security::get_secret( self::SECRET_PRIVATE );
	}

	/**
	 * @param string $data Raw binary.
	 */
	private function base64url_encode( string $data ): string {
		return rtrim( strtr( base64_encode( $data ), '+/', '-_' ), '=' );
	}
}
