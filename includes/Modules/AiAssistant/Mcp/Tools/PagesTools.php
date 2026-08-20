<?php
/**
 * MCP tools wrapping the wp/v2/pages REST route.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\AiAssistant\Mcp\Tools;

use UxStudio\Modules\AiAssistant\Mcp\Dto\RestOperationConfig;

defined( 'ABSPATH' ) || exit;

class PagesTools extends RestEndpointTool {

	protected function get_operations(): array {
		return array(
			new RestOperationConfig( 'pages-search', __( 'Search Pages', 'ux-studio' ), __( 'Search and list pages with filters', 'ux-studio' ), 'GET', '/pages' ),
			new RestOperationConfig( 'pages-get', __( 'Get Page', 'ux-studio' ), __( 'Get a single page by ID', 'ux-studio' ), 'GET', '/pages/(?P<id>[\d]+)' ),
			new RestOperationConfig( 'pages-create', __( 'Create Page', 'ux-studio' ), __( 'Create a new page', 'ux-studio' ), 'POST', '/pages' ),
			new RestOperationConfig( 'pages-update', __( 'Update Page', 'ux-studio' ), __( 'Update an existing page', 'ux-studio' ), 'PUT', '/pages/(?P<id>[\d]+)' ),
			new RestOperationConfig( 'pages-delete', __( 'Delete Page', 'ux-studio' ), __( 'Delete a page', 'ux-studio' ), 'DELETE', '/pages/(?P<id>[\d]+)' ),
		);
	}
}
