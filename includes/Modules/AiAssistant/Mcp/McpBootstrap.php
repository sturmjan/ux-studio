<?php
/**
 * AI Assistant side of the MCP server: registers the content tools (posts, pages, media,
 * WooCommerce, SEO, Elementor...) in the WordPress Abilities API, as one ability category
 * ("ai-assistant"). The server itself (transport, tokens, scopes, UI) lives in the core,
 * `UxStudio\Core\Mcp`, and works without this module; it lists these tools only while the
 * module is on and the MCP server is enabled.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\AiAssistant\Mcp;

use UxStudio\Core\Mcp\Config;

defined( 'ABSPATH' ) || exit;

final class McpBootstrap {

	/**
	 * Hooks the category and the abilities (the Abilities API fires its own init actions).
	 */
	public static function register(): void {
		add_action( 'wp_abilities_api_init', array( self::class, 'register_abilities' ) );
		add_action( 'wp_abilities_api_categories_init', array( self::class, 'register_category' ) );
	}

	/**
	 * Registers the "ai-assistant" ability category, only while the MCP server is on.
	 */
	public static function register_category(): void {
		if ( ! Config::enabled() || ! function_exists( 'wp_register_ability_category' ) ) {
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
	 * Registers all content tools/resources, only while the MCP server is on and the
	 * Abilities API is available.
	 */
	public static function register_abilities(): void {
		if ( ! Config::enabled() || ! function_exists( 'wp_register_ability' ) ) {
			return;
		}

		( new McpAbilitiesRegistry() )->register_all();
	}
}
