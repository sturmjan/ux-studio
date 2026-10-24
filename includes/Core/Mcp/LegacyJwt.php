<?php
/**
 * Tokens issued by the AI Assistant module before the MCP server moved into the core.
 *
 * @package UxStudio
 */

namespace UxStudio\Core\Mcp;

defined( 'ABSPATH' ) || exit;

/**
 * Validation, listing and revoking of the old HS256 JWT tokens. New ones are never issued;
 * the ones that exist keep working with the `admin` scope until their (max 24 h) expiry.
 * Registry option and signature scheme are unchanged, so no token has to be re-issued.
 */
final class LegacyJwt {

	public const REGISTRY = 'uxstudio_ai_assistant_jwt_token_registry';

	/**
	 * Validate a bearer JWT. A token dies with a password change of its user.
	 *
	 * @param string $token Raw token.
	 * @return array<string, mixed>|null Payload or null.
	 */
	public static function validate( string $token ): ?array {
		$payload = self::decode( $token, self::secret() );
		if ( ! $payload || ! isset( $payload['exp'], $payload['jti'], $payload['user_id'], $payload['pwh'] ) ) {
			return null;
		}
		if ( (int) $payload['exp'] < time() ) {
			return null;
		}
		$registry = get_option( self::REGISTRY, array() );
		if ( ! isset( $registry[ (string) $payload['jti'] ] ) ) {
			return null;
		}
		$fingerprint = self::fingerprint( (int) $payload['user_id'] );
		if ( '' === $fingerprint || ! hash_equals( $fingerprint, (string) $payload['pwh'] ) ) {
			return null;
		}
		// The token grants the admin scope: the user must still be an administrator (demoted or
		// deleted users lose it at once, like with the new tokens).
		if ( ! self::user_is_admin( (int) $payload['user_id'] ) ) {
			return null;
		}
		return $payload;
	}

	/**
	 * Active (non expired) legacy tokens, for the token list.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function all(): array {
		$registry = (array) get_option( self::REGISTRY, array() );
		$now      = time();
		$out      = array();
		foreach ( $registry as $jti => $info ) {
			if ( (int) ( $info['expires_at'] ?? 0 ) < $now ) {
				continue;
			}
			$user  = get_user_by( 'id', (int) ( $info['user_id'] ?? 0 ) );
			$out[] = array(
				'id'         => (string) $jti,
				'label'      => __( 'AI Assistant token (old)', 'ux-studio' ),
				'user_id'    => (int) ( $info['user_id'] ?? 0 ),
				'user'       => $user ? $user->display_name : '',
				'scopes'     => array( Scopes::ADMIN ),
				'created_at' => gmdate( 'Y-m-d H:i:s', (int) ( $info['issued_at'] ?? 0 ) ),
				'expires_at' => gmdate( 'Y-m-d H:i:s', (int) $info['expires_at'] ),
				'expired'    => false,
				'last_used'  => null,
				'last_ip'    => '',
				'ips'        => array(),
				'legacy'     => true,
			);
		}
		return $out;
	}

	/**
	 * Is this id a legacy jti (UUID)?
	 *
	 * @param string $id Id.
	 */
	public static function is_legacy_id( string $id ): bool {
		return 1 === preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $id );
	}

	/**
	 * Revoke a legacy token.
	 *
	 * @param string $jti Token id.
	 */
	public static function revoke( string $jti ): bool {
		$registry = (array) get_option( self::REGISTRY, array() );
		if ( ! isset( $registry[ $jti ] ) ) {
			return false;
		}
		unset( $registry[ $jti ] );
		update_option( self::REGISTRY, $registry );
		return true;
	}

	/**
	 * Does the user still have manage_options?
	 *
	 * @param int $user_id User id.
	 */
	private static function user_is_admin( int $user_id ): bool {
		$user = $user_id > 0 ? get_userdata( $user_id ) : false;
		return $user && user_can( $user, 'manage_options' );
	}

	/**
	 * Keyed fingerprint of the user's password hash ('' for an unknown user).
	 *
	 * @param int $user_id User id.
	 */
	private static function fingerprint( int $user_id ): string {
		$user = $user_id > 0 ? get_userdata( $user_id ) : false;
		if ( ! $user ) {
			return '';
		}
		return substr( hash_hmac( 'sha256', 'pwh|' . $user->ID . '|' . $user->user_pass, self::secret() ), 0, 32 );
	}

	/**
	 * HMAC secret derived from a WP salt.
	 */
	private static function secret(): string {
		return wp_salt( 'secure_auth' );
	}

	/**
	 * HS256 decoder (timing safe signature comparison).
	 *
	 * @param string $token  Token.
	 * @param string $secret Secret.
	 * @return array<string, mixed>|null
	 */
	private static function decode( string $token, string $secret ): ?array {
		$parts = explode( '.', $token );
		if ( 3 !== count( $parts ) ) {
			return null;
		}
		list( $header, $body, $signature ) = $parts;
		$expected = rtrim( strtr( base64_encode( hash_hmac( 'sha256', "{$header}.{$body}", $secret, true ) ), '+/', '-_' ), '=' );
		if ( ! hash_equals( $expected, $signature ) ) {
			return null;
		}
		$payload = json_decode( (string) base64_decode( strtr( $body, '-_', '+/' ) ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		return is_array( $payload ) ? $payload : null;
	}
}
