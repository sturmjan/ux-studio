<?php
/**
 * JWT (HS256) issuance/validation for external MCP clients (Claude Desktop,
 * Cursor, Windsurf, ...). Tokens are self-contained (no external dependency,
 * same minimal encoder/decoder as the legacy module) and tracked in a WP
 * option registry so they can be listed/revoked from the admin UI.
 *
 * Token management routes are always registered (even with MCP disabled) so
 * admins can prepare tokens ahead of enabling MCP; validate_token() is meant
 * to be called by REST/MCP authentication middleware, not by ability
 * permission_callbacks (those stay manage_options, same as legacy).
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\AiAssistant\Mcp;

use UxStudio\Rest\Controller;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

/**
 * Admin (manage_options, via Controller::route()):
 *   POST uxstudio/v1/ai-assistant/jwt/token   - issue a new token
 *   POST uxstudio/v1/ai-assistant/jwt/revoke  - revoke a token by jti
 *   GET  uxstudio/v1/ai-assistant/jwt/tokens  - list active (non-expired) tokens
 */
final class JwtAuth extends Controller {

	private const TOKEN_REGISTRY_OPTION = 'uxstudio_ai_assistant_jwt_token_registry';
	private const ALGO                  = 'HS256';
	private const MIN_EXPIRES_IN        = 3600;
	private const MAX_EXPIRES_IN        = 86400;

	public function register_routes(): void {
		$this->route(
			'/ai-assistant/jwt/token',
			'POST',
			array( $this, 'generate_token' ),
			array(
				'expires_in' => array(
					'required'          => false,
					'type'              => 'integer',
					'default'           => self::MIN_EXPIRES_IN,
					'sanitize_callback' => 'absint',
				),
			)
		);

		$this->route(
			'/ai-assistant/jwt/revoke',
			'POST',
			array( $this, 'revoke_token' ),
			array(
				'jti' => array(
					'required'          => true,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
				),
			)
		);

		$this->route(
			'/ai-assistant/jwt/tokens',
			'GET',
			array( $this, 'list_tokens' )
		);
	}

	public function generate_token( WP_REST_Request $request ): WP_REST_Response {
		$expires_in = (int) $request->get_param( 'expires_in' );
		$expires_in = max( self::MIN_EXPIRES_IN, min( self::MAX_EXPIRES_IN, $expires_in ?: self::MIN_EXPIRES_IN ) );

		$now = time();
		$jti = wp_generate_uuid4();

		$payload = array(
			'iss'     => get_bloginfo( 'url' ),
			'iat'     => $now,
			'exp'     => $now + $expires_in,
			'user_id' => get_current_user_id(),
			'jti'     => $jti,
		);

		$token = $this->encode( $payload, $this->get_secret() );

		$registry          = get_option( self::TOKEN_REGISTRY_OPTION, array() );
		$registry[ $jti ]  = array(
			'user_id'    => get_current_user_id(),
			'issued_at'  => $now,
			'expires_at' => $now + $expires_in,
		);
		update_option( self::TOKEN_REGISTRY_OPTION, $registry );

		return $this->ok(
			array(
				'token'      => $token,
				'jti'        => $jti,
				'expires_at' => gmdate( 'Y-m-d H:i:s', $now + $expires_in ),
			)
		);
	}

	public function revoke_token( WP_REST_Request $request ) {
		$jti      = (string) $request->get_param( 'jti' );
		$registry = get_option( self::TOKEN_REGISTRY_OPTION, array() );

		if ( ! isset( $registry[ $jti ] ) ) {
			return new WP_Error( 'uxstudio_jwt_not_found', __( 'Token not found.', 'ux-studio' ), array( 'status' => 404 ) );
		}

		unset( $registry[ $jti ] );
		update_option( self::TOKEN_REGISTRY_OPTION, $registry );

		return $this->ok( array( 'revoked' => true ) );
	}

	public function list_tokens(): WP_REST_Response {
		$registry = get_option( self::TOKEN_REGISTRY_OPTION, array() );
		$now      = time();
		$tokens   = array();

		foreach ( $registry as $jti => $info ) {
			if ( $info['expires_at'] < $now ) {
				unset( $registry[ $jti ] );
				continue;
			}

			$user     = get_user_by( 'id', $info['user_id'] );
			$tokens[] = array(
				'jti'        => $jti,
				'user'       => $user ? $user->display_name : __( 'Unknown', 'ux-studio' ),
				'issued_at'  => gmdate( 'Y-m-d H:i:s', $info['issued_at'] ),
				'expires_at' => gmdate( 'Y-m-d H:i:s', $info['expires_at'] ),
			);
		}

		// Persist the cleaned-up registry (expired tokens dropped above).
		update_option( self::TOKEN_REGISTRY_OPTION, $registry );

		return $this->ok( $tokens );
	}

	/**
	 * Validates a JWT bearer token for MCP/REST authentication middleware.
	 * Not used by ability permission_callbacks (those check manage_options
	 * directly, same as legacy).
	 */
	public function validate_token( string $token ): ?array {
		$payload = $this->decode( $token, $this->get_secret() );

		if ( ! $payload ) {
			return null;
		}

		if ( $payload['exp'] < time() ) {
			return null;
		}

		$registry = get_option( self::TOKEN_REGISTRY_OPTION, array() );
		if ( ! isset( $registry[ $payload['jti'] ] ) ) {
			return null;
		}

		return $payload;
	}

	/**
	 * HMAC secret derived from a WP salt (never a raw constant), consistent
	 * with UxStudio\Core\Security's use of wp_salt() for key material.
	 */
	private function get_secret(): string {
		return wp_salt( 'secure_auth' );
	}

	/**
	 * Minimal HS256 JWT encoder - no external dependency.
	 */
	private function encode( array $payload, string $secret ): string {
		$header    = $this->base64_url_encode( (string) wp_json_encode( array( 'typ' => 'JWT', 'alg' => self::ALGO ) ) );
		$body      = $this->base64_url_encode( (string) wp_json_encode( $payload ) );
		$signature = $this->base64_url_encode( hash_hmac( 'sha256', "{$header}.{$body}", $secret, true ) );

		return "{$header}.{$body}.{$signature}";
	}

	/**
	 * Minimal HS256 JWT decoder; verifies the signature with hash_equals()
	 * (timing-safe comparison).
	 */
	private function decode( string $token, string $secret ): ?array {
		$parts = explode( '.', $token );
		if ( 3 !== count( $parts ) ) {
			return null;
		}

		list( $header, $body, $signature ) = $parts;

		$expected_signature = $this->base64_url_encode( hash_hmac( 'sha256', "{$header}.{$body}", $secret, true ) );

		if ( ! hash_equals( $expected_signature, $signature ) ) {
			return null;
		}

		$payload = json_decode( $this->base64_url_decode( $body ), true );
		return is_array( $payload ) ? $payload : null;
	}

	private function base64_url_encode( string $data ): string {
		return rtrim( strtr( base64_encode( $data ), '+/', '-_' ), '=' );
	}

	private function base64_url_decode( string $data ): string {
		return (string) base64_decode( strtr( $data, '-_', '+/' ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
	}
}
