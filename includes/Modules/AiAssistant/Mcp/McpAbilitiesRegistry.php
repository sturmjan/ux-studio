<?php
/**
 * Registers every MCP tool/resource ability for the AI Assistant module.
 * Called from McpBootstrap::register_abilities(), only when "mcp_enabled" is
 * on and the WP Abilities API is available.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\AiAssistant\Mcp;

use UxStudio\Modules\AiAssistant\Mcp\Tools;
use UxStudio\Modules\AiAssistant\Mcp\Resources;

defined( 'ABSPATH' ) || exit;

class McpAbilitiesRegistry {

	public function register_all(): void {
		$this->register_tools();
		$this->register_resources();
	}

	private function register_tools(): void {
		( new Tools\PostsTools() )->register();
		( new Tools\PagesTools() )->register();
		( new Tools\CategoriesTools() )->register();
		( new Tools\TagsTools() )->register();
		( new Tools\UsersTools() )->register();
		( new Tools\MediaTools() )->register();
		( new Tools\CustomPostTypesTools() )->register();
		( new Tools\SettingsTools() )->register();
		( new Tools\SiteInfoTools() )->register();

		if ( class_exists( 'WooCommerce' ) ) {
			( new Tools\WooProductsTools() )->register();
			( new Tools\WooOrdersTools() )->register();
		}

		if ( defined( 'ELEMENTOR_VERSION' ) ) {
			( new Tools\ElementorTools() )->register();
		}
	}

	private function register_resources(): void {
		$resources = array(
			new Resources\SiteInfoResource(),
			new Resources\SiteSettingsResource(),
			new Resources\PluginsInfoResource(),
			new Resources\ThemeInfoResource(),
			new Resources\UsersInfoResource(),
		);

		foreach ( $resources as $resource ) {
			$resource->register();
		}
	}
}
