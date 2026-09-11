<?php
/**
 * Wires up the MCP server: JWT token management REST routes (always
 * available, so tokens can be prepared ahead of time) and, gated by the
 * "mcp_enabled" setting, the WP Abilities API category + tools/resources
 * that expose this site to external MCP clients (Claude Desktop, Cursor,
 * Windsurf, ...). Called explicitly from Module::boot() once this wave is
 * integrated (not auto-registered here, so it can land independently).
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\AiAssistant\Mcp;

use UxStudio\Core\Settings;

defined( 'ABSPATH' ) || exit;

final class McpBootstrap {

	/**
	 * Registers the JWT REST routes and the MCP abilities/category hooks.
	 */
	public static function register(): void {
		add_action( 'rest_api_init', array( self::class, 'register_jwt_routes' ) );

		add_action( 'wp_abilities_api_init', array( self::class, 'register_abilities' ) );
		add_action( 'wp_abilities_api_categories_init', array( self::class, 'register_category' ) );

		// Makes the JWT tokens issued/revoked above actually mean something:
		// without this, generate_token()/revoke_token() only maintain a
		// registry that nothing ever consults, and every ability's
		// current_user_can('manage_options') check can only ever be satisfied
		// by an already-logged-in cookie session or an Application Password -
		// never by the bearer token the UI hands out. Priority 20 so it runs
		// after WP core's own cookie/application-password resolution and only
		// acts when nothing else has already authenticated the request.
		add_filter( 'determine_current_user', array( self::class, 'authenticate_bearer_token' ), 20 );
	}

	/**
	 * `determine_current_user` filter: if the request carries a valid
	 * `Authorization: Bearer <jwt>` header issued via JwtAuth::generate_token(),
	 * authenticate as the WP user that token was issued for (the admin who
	 * generated it, from the token payload's `user_id` - never a wildcard/
	 * service account). Leaves `$user_id` untouched otherwise, including when
	 * it is already set by an earlier authentication method.
	 *
	 * @param int|false $user_id Result so far from earlier `determine_current_user` filters.
	 * @return int|false
	 */
	public static function authenticate_bearer_token( $user_id ) {
		if ( $user_id ) {
			return $user_id;
		}

		$header = self::bearer_token_from_request();
		if ( '' === $header ) {
			return $user_id;
		}

		$payload = ( new JwtAuth() )->validate_token( $header );
		if ( null === $payload || empty( $payload['user_id'] ) ) {
			return $user_id;
		}

		$target_user_id = (int) $payload['user_id'];
		if ( ! get_userdata( $target_user_id ) ) {
			return $user_id;
		}

		return $target_user_id;
	}

	/**
	 * Extracts the raw token from an `Authorization: Bearer <token>` header,
	 * accounting for the `REDIRECT_` variant some SAPIs (mod_php/mod_fcgid)
	 * use, the same fallback WP core's Application Passwords support uses.
	 */
	private static function bearer_token_from_request(): string {
		$auth = '';
		if ( ! empty( $_SERVER['HTTP_AUTHORIZATION'] ) ) {
			$auth = (string) $_SERVER['HTTP_AUTHORIZATION']; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash
		} elseif ( ! empty( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ) ) {
			$auth = (string) $_SERVER['REDIRECT_HTTP_AUTHORIZATION']; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash
		}

		if ( '' === $auth || 0 !== stripos( $auth, 'Bearer ' ) ) {
			return '';
		}

		return trim( substr( $auth, 7 ) );
	}

	public static function register_jwt_routes(): void {
		( new JwtAuth() )->register_routes();
	}

	/**
	 * Registers the "ai-assistant" ability category, only while MCP is
	 * enabled - mirrors register_abilities()'s gating so the category never
	 * appears without any abilities behind it.
	 */
	public static function register_category(): void {
		if ( ! self::mcp_enabled() || ! function_exists( 'wp_register_ability_category' ) ) {
			return;
		}

		wp_register_ability_category(
			'ai-assistant',
			array(
				'label'       => __( 'AI Assistant', 'ux-studio' ),
				'description' => __( 'WordPress management tools provided by AI Assistant', 'ux-studio' ),
			)
		);
	}

	/**
	 * Registers all MCP tools/resources, only while MCP is enabled and the
	 * WP Abilities API is available.
	 */
	public static function register_abilities(): void {
		if ( ! self::mcp_enabled() || ! function_exists( 'wp_register_ability' ) ) {
			return;
		}

		( new McpAbilitiesRegistry() )->register_all();
	}

	private static function mcp_enabled(): bool {
		return (bool) ( new Settings( 'uxstudio_ai_assistant' ) )->get( 'mcp_enabled', false );
	}
}
