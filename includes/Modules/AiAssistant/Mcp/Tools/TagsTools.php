<?php
/**
 * MCP tools wrapping the wp/v2/tags REST route.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\AiAssistant\Mcp\Tools;

use UxStudio\Modules\AiAssistant\Mcp\Dto\RestOperationConfig;

defined( 'ABSPATH' ) || exit;

class TagsTools extends RestEndpointTool {

	protected function get_operations(): array {
		return array(
			new RestOperationConfig( 'tags-list', __( 'List Tags', 'ux-studio' ), __( 'List all tags', 'ux-studio' ), 'GET', '/tags' ),
			new RestOperationConfig( 'tags-get', __( 'Get Tag', 'ux-studio' ), __( 'Get a tag by ID', 'ux-studio' ), 'GET', '/tags/(?P<id>[\d]+)' ),
			new RestOperationConfig( 'tags-create', __( 'Create Tag', 'ux-studio' ), __( 'Create a new tag', 'ux-studio' ), 'POST', '/tags' ),
			new RestOperationConfig( 'tags-update', __( 'Update Tag', 'ux-studio' ), __( 'Update a tag', 'ux-studio' ), 'PUT', '/tags/(?P<id>[\d]+)' ),
			new RestOperationConfig( 'tags-delete', __( 'Delete Tag', 'ux-studio' ), __( 'Delete a tag', 'ux-studio' ), 'DELETE', '/tags/(?P<id>[\d]+)' ),
		);
	}
}
