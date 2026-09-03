<?php
/**
 * Web Push protocol cryptography: VAPID JWT (ES256) + payload encryption
 * (RFC 8291, aes128gcm). Pure openssl, no external library.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\PushNotifications;

use RuntimeException;

defined( 'ABSPATH' ) || exit;

/**
 * Ported from the legacy push-notifications WebPushCrypto. Adapted to the
 * studio's Vapid, which stores the VAPID private key as a PEM (not a raw D
 * value), so JWT signing and ECDH use openssl PEM handles directly.
 *
 * Implements:
 *   - VAPID JWT signing (ES256 / ECDSA-SHA256) per RFC 8292
 *   - Payload encryption per RFC 8291 (aes128gcm content encoding)
 *   - ECDH shared-secret derivation + HKDF (RFC 5869)
 */
final class WebPushCrypto {

	/**
	 * openssl.cnf hint (needed for openssl_pkey_new on Windows/XAMPP).
	 *
	 * @return array<string,string>
	 */
	private static function openssl_config(): array {
		$candidates = array(
			'D:/xampp/apache/conf/openssl.cnf',
			'D:/xampp/php/extras/ssl/openssl.cnf',
			'D:/xampp/apache/bin/openssl.cnf',
		);
		foreach ( $candidates as $path ) {
			if ( file_exists( $path ) ) {
				return array( 'config' => $path );
			}
		}
		return array();
	}

	/**
	 * Build a full Web Push request (headers + encrypted body) for one endpoint.
	 *
	 * @param string $endpoint        Subscriber push endpoint URL.
	 * @param string $subscriber_key  Raw 65-byte uncompressed p256dh key.
	 * @param string $subscriber_auth Raw 16-byte auth secret.
	 * @param string $payload         Plaintext JSON payload.
	 * @param string $vapid_private_pem VAPID private key PEM.
	 * @param string $vapid_public_raw  Raw 65-byte VAPID public key.
	 * @param string $subject         VAPID "sub" claim (mailto: or https:).
	 * @return array{url:string,headers:array<string,string>,body:string}
	 */
	public static function build_request( string $endpoint, string $subscriber_key, string $subscriber_auth, string $payload, string $vapid_private_pem, string $vapid_public_raw, string $subject ): array {
		$parsed   = wp_parse_url( $endpoint );
		$audience = ( $parsed['scheme'] ?? 'https' ) . '://' . ( $parsed['host'] ?? '' );
		if ( ! empty( $parsed['port'] ) ) {
			$audience .= ':' . $parsed['port'];
		}

		$jwt        = self::create_vapid_jwt( $audience, $subject, $vapid_private_pem );
		$ciphertext = self::encrypt_payload( $payload, $subscriber_key, $subscriber_auth );

		$headers = array(
			'Authorization'    => 'vapid t=' . $jwt . ', k=' . self::b64u( $vapid_public_raw ),
			'Content-Type'     => 'application/octet-stream',
			'Content-Encoding' => 'aes128gcm',
			'TTL'              => '86400',
			'Urgency'          => 'normal',
		);

		return array(
			'url'     => $endpoint,
			'headers' => $headers,
			'body'    => $ciphertext,
		);
	}

	/**
	 * Create an ES256-signed VAPID JWT.
	 *
	 * @param string $audience    Push service origin.
	 * @param string $subject     Contact URI.
	 * @param string $private_pem VAPID private key PEM.
	 * @param int    $expiry      Expiration timestamp (defaults to +12h).
	 */
	public static function create_vapid_jwt( string $audience, string $subject, string $private_pem, int $expiry = 0 ): string {
		if ( 0 === $expiry ) {
			$expiry = time() + 12 * HOUR_IN_SECONDS;
		}

		$header  = self::b64u( (string) wp_json_encode( array( 'typ' => 'JWT', 'alg' => 'ES256' ) ) );
		$payload = self::b64u( (string) wp_json_encode( array( 'aud' => $audience, 'exp' => $expiry, 'sub' => $subject ) ) );
		$signing = $header . '.' . $payload;

		$key = openssl_pkey_get_private( $private_pem );
		if ( false === $key ) {
			throw new RuntimeException( 'VAPID private key load failed: ' . openssl_error_string() );
		}

		$signature = '';
		if ( ! openssl_sign( $signing, $signature, $key, OPENSSL_ALGO_SHA256 ) ) {
			throw new RuntimeException( 'VAPID signing failed: ' . openssl_error_string() );
		}

		return $signing . '.' . self::b64u( self::der_to_raw( $signature ) );
	}

	/**
	 * Encrypt a payload per RFC 8291 (aes128gcm). Returns the full ciphertext
	 * (header + encrypted record) ready to be the HTTP body.
	 *
	 * @param string $payload         Plaintext JSON.
	 * @param string $subscriber_key  Raw 65-byte p256dh.
	 * @param string $subscriber_auth Raw 16-byte auth secret.
	 */
	public static function encrypt_payload( string $payload, string $subscriber_key, string $subscriber_auth ): string {
		// Ephemeral server keypair.
		$ephemeral = openssl_pkey_new(
			array_merge(
				array(
					'private_key_type' => OPENSSL_KEYTYPE_EC,
					'curve_name'       => 'prime256v1',
				),
				self::openssl_config()
			)
		);
		if ( false === $ephemeral ) {
			throw new RuntimeException( 'Ephemeral EC key generation failed: ' . openssl_error_string() );
		}
		$details = openssl_pkey_get_details( $ephemeral );
		if ( false === $details || empty( $details['ec']['x'] ) || empty( $details['ec']['y'] ) ) {
			throw new RuntimeException( 'Ephemeral EC key details unavailable.' );
		}
		$local_public = "\x04" . str_pad( (string) $details['ec']['x'], 32, "\x00", STR_PAD_LEFT ) . str_pad( (string) $details['ec']['y'], 32, "\x00", STR_PAD_LEFT );

		// ECDH shared secret with the subscriber's public key.
		$remote = openssl_pkey_get_public( self::raw_public_to_pem( $subscriber_key ) );
		if ( false === $remote ) {
			throw new RuntimeException( 'Subscriber public key load failed: ' . openssl_error_string() );
		}
		$shared = openssl_pkey_derive( $remote, $ephemeral, 256 );
		if ( false === $shared ) {
			throw new RuntimeException( 'ECDH derivation failed: ' . openssl_error_string() );
		}

		// RFC 8291 key derivation.
		$key_info = "WebPush: info\x00" . $subscriber_key . $local_public;
		$ikm      = self::hkdf( $subscriber_auth, $shared, $key_info, 32 );
		$salt     = random_bytes( 16 );
		$cek      = self::hkdf( $salt, $ikm, "Content-Encoding: aes128gcm\x00", 16 );
		$nonce    = self::hkdf( $salt, $ikm, "Content-Encoding: nonce\x00", 12 );

		// aes128gcm: append 0x02 delimiter (single, last record).
		$tag        = '';
		$ciphertext = openssl_encrypt( $payload . "\x02", 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag, '', 16 );
		if ( false === $ciphertext ) {
			throw new RuntimeException( 'AES-128-GCM encryption failed: ' . openssl_error_string() );
		}

		// aes128gcm header: salt(16) + rs(4, big-endian) + idlen(1) + keyid(65).
		$rs     = 4096;
		$header = $salt . pack( 'N', $rs ) . chr( strlen( $local_public ) ) . $local_public;

		return $header . $ciphertext . $tag;
	}

	/**
	 * HKDF (RFC 5869) extract + expand with SHA-256.
	 *
	 * @param string $salt   Salt.
	 * @param string $ikm    Input key material.
	 * @param string $info   Context info.
	 * @param int    $length Output length in bytes.
	 */
	private static function hkdf( string $salt, string $ikm, string $info, int $length ): string {
		if ( function_exists( 'hash_hkdf' ) ) {
			return hash_hkdf( 'sha256', $ikm, $length, $info, $salt );
		}
		$prk     = hash_hmac( 'sha256', $ikm, $salt, true );
		$t       = '';
		$output  = '';
		$counter = 1;
		while ( strlen( $output ) < $length ) {
			$t       = hash_hmac( 'sha256', $t . $info . chr( $counter ), $prk, true );
			$output .= $t;
			++$counter;
		}
		return substr( $output, 0, $length );
	}

	/**
	 * Wrap a raw uncompressed EC public point into a SubjectPublicKeyInfo PEM.
	 *
	 * @param string $public_key Raw 65-byte EC point.
	 */
	private static function raw_public_to_pem( string $public_key ): string {
		$oid        = "\x30\x13\x06\x07\x2a\x86\x48\xce\x3d\x02\x01\x06\x08\x2a\x86\x48\xce\x3d\x03\x01\x07";
		$bit_string = "\x03" . self::asn1_length( strlen( $public_key ) + 1 ) . "\x00" . $public_key;
		$der        = "\x30" . self::asn1_length( strlen( $oid ) + strlen( $bit_string ) ) . $oid . $bit_string;
		return "-----BEGIN PUBLIC KEY-----\n" . chunk_split( base64_encode( $der ), 64, "\n" ) . "-----END PUBLIC KEY-----\n";
	}

	/**
	 * ASN.1 DER length encoding.
	 *
	 * @param int $length Length.
	 */
	private static function asn1_length( int $length ): string {
		if ( $length < 128 ) {
			return chr( $length );
		}
		$bytes = '';
		$temp  = $length;
		while ( $temp > 0 ) {
			$bytes = chr( $temp & 0xFF ) . $bytes;
			$temp >>= 8;
		}
		return chr( 0x80 | strlen( $bytes ) ) . $bytes;
	}

	/**
	 * Convert a DER ECDSA signature (SEQUENCE{INTEGER r, INTEGER s}) to the raw
	 * fixed-width R||S (64 bytes) required by JOSE ES256.
	 *
	 * @param string $der DER signature.
	 */
	private static function der_to_raw( string $der ): string {
		$offset = 2; // Skip SEQUENCE tag + length.
		if ( "\x02" !== $der[ $offset ] ) {
			throw new RuntimeException( 'Invalid DER signature (R tag).' );
		}
		++$offset;
		$r_len   = ord( $der[ $offset ] );
		++$offset;
		$r       = substr( $der, $offset, $r_len );
		$offset += $r_len;
		if ( "\x02" !== $der[ $offset ] ) {
			throw new RuntimeException( 'Invalid DER signature (S tag).' );
		}
		++$offset;
		$s_len = ord( $der[ $offset ] );
		++$offset;
		$s     = substr( $der, $offset, $s_len );

		$r = ltrim( $r, "\x00" );
		$s = ltrim( $s, "\x00" );
		return str_pad( $r, 32, "\x00", STR_PAD_LEFT ) . str_pad( $s, 32, "\x00", STR_PAD_LEFT );
	}

	/**
	 * base64url encode (no padding).
	 *
	 * @param string $data Raw binary.
	 */
	public static function b64u( string $data ): string {
		return rtrim( strtr( base64_encode( $data ), '+/', '-_' ), '=' );
	}

	/**
	 * base64url decode.
	 *
	 * @param string $data base64url string.
	 */
	public static function b64u_decode( string $data ): string {
		$padded = str_pad( $data, strlen( $data ) + ( 4 - strlen( $data ) % 4 ) % 4, '=' );
		return (string) base64_decode( strtr( $padded, '-_', '+/' ), true );
	}
}
