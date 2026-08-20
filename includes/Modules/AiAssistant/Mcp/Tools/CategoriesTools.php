<?php
/**
 * MCP tools wrapping the wp/v2/categories REST route.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\AiAssistant\Mcp\Tools;

use UxStudio\Modules\AiAssistant\Mcp\Dto\RestOperationConfig;

defined( 'ABSPATH' ) || exit;

class CategoriesTools extends RestEndpointTool {

	protected function get_operations(): array {
		return array(
			new RestOperationConfig( 'categories-list', __( 'List Categories', 'ux-studio' ), __( 'List all categories', 'ux-studio' ), 'GET', '/categories' ),
			new RestOperationConfig( 'categories-get', __( 'Get Category', 'ux-studio' ), __( 'Get a category by ID', 'ux-studio' ), 'GET', '/categories/(?P<id>[\d]+)' ),
			new RestOperationConfig( 'categories-create', __( 'Create Category', 'ux-studio' ), __( 'Create a new category', 'ux-studio' ), 'POST', '/categories' ),
			new RestOperationConfig( 'categories-update', __( 'Update Category', 'ux-studio' ), __( 'Update a category', 'ux-studio' ), 'PUT', '/categories/(?P<id>[\d]+)' ),
			new RestOperationConfig( 'categories-delete', __( 'Delete Category', 'ux-studio' ), __( 'Delete a category', 'ux-studio' ), 'DELETE', '/categories/(?P<id>[\d]+)' ),
		);
	}
}
