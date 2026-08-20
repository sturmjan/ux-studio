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
