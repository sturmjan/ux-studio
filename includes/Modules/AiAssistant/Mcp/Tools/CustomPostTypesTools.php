<?php
/**
 * MCP tools wrapping the wp/v2/types and wp/v2/taxonomies REST routes.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\AiAssistant\Mcp\Tools;

use UxStudio\Modules\AiAssistant\Mcp\Dto\RestOperationConfig;

defined( 'ABSPATH' ) || exit;

class CustomPostTypesTools extends RestEndpointTool {

	protected function get_operations(): array {
		return array(
			new RestOperationConfig( 'types-list', __( 'List Post Types', 'ux-studio' ), __( 'List all registered post types', 'ux-studio' ), 'GET', '/types' ),
			new RestOperationConfig( 'types-get', __( 'Get Post Type', 'ux-studio' ), __( 'Get details about a post type', 'ux-studio' ), 'GET', '/types/(?P<type>[\w-]+)' ),
			new RestOperationConfig( 'taxonomies-list', __( 'List Taxonomies', 'ux-studio' ), __( 'List all registered taxonomies', 'ux-studio' ), 'GET', '/taxonomies' ),
			new RestOperationConfig( 'taxonomies-get', __( 'Get Taxonomy', 'ux-studio' ), __( 'Get details about a taxonomy', 'ux-studio' ), 'GET', '/taxonomies/(?P<taxonomy>[\w-]+)' ),
		);
	}
}
