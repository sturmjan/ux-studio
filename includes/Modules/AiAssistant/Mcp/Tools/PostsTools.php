<?php
/**
 * MCP tools wrapping the wp/v2/posts REST route.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\AiAssistant\Mcp\Tools;

use UxStudio\Modules\AiAssistant\Mcp\Dto\RestOperationConfig;

defined( 'ABSPATH' ) || exit;

class PostsTools extends RestEndpointTool {

	protected function get_operations(): array {
		return array(
			new RestOperationConfig( 'posts-search', __( 'Search Posts', 'ux-studio' ), __( 'Search and list posts with filters', 'ux-studio' ), 'GET', '/posts' ),
			new RestOperationConfig( 'posts-get', __( 'Get Post', 'ux-studio' ), __( 'Get a single post by ID', 'ux-studio' ), 'GET', '/posts/(?P<id>[\d]+)' ),
			new RestOperationConfig( 'posts-create', __( 'Create Post', 'ux-studio' ), __( 'Create a new post', 'ux-studio' ), 'POST', '/posts' ),
			new RestOperationConfig( 'posts-update', __( 'Update Post', 'ux-studio' ), __( 'Update an existing post', 'ux-studio' ), 'PUT', '/posts/(?P<id>[\d]+)' ),
			new RestOperationConfig( 'posts-delete', __( 'Delete Post', 'ux-studio' ), __( 'Delete a post', 'ux-studio' ), 'DELETE', '/posts/(?P<id>[\d]+)' ),
		);
	}
}
