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
	 * The current public key (base64url, uncompressed EC point), generating
	 * a fresh keypair on first access if none exists yet.
	 */
	public function public_key(): string {
		$public = (string) get_option( self::OPTION_PUBLIC, '' );
		if ( '' === $public || '' === Security::get_secret( self::SECRET_PRIVATE ) ) {
			$this->generate();
			$public = (string) get_option( self::OPTION_PUBLIC, '' );
		}
		return $public;
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
			array(
				'private_key_type' => OPENSSL_KEYTYPE_EC,
				'curve_name'       => 'prime256v1',
			)
		);
		if ( false === $resource ) {
			return false;
		}

		$exported = openssl_pkey_export( $resource, $private_pem );
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
	 * The private key PEM, for building a Web Push VAPID JWT (real sending is
	 * a documented TODO in this module - see Module::send_notification()).
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
