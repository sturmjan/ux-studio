<?php
/**
 * MCP tools wrapping the wp/v2/users REST route.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\AiAssistant\Mcp\Tools;

use UxStudio\Modules\AiAssistant\Mcp\Dto\RestOperationConfig;

defined( 'ABSPATH' ) || exit;

class UsersTools extends RestEndpointTool {

	protected function get_operations(): array {
		return array(
			new RestOperationConfig( 'users-list', __( 'List Users', 'ux-studio' ), __( 'List all users', 'ux-studio' ), 'GET', '/users' ),
			new RestOperationConfig( 'users-get', __( 'Get User', 'ux-studio' ), __( 'Get a user by ID', 'ux-studio' ), 'GET', '/users/(?P<id>[\d]+)' ),
			new RestOperationConfig( 'users-create', __( 'Create User', 'ux-studio' ), __( 'Create a new user', 'ux-studio' ), 'POST', '/users' ),
			new RestOperationConfig( 'users-update', __( 'Update User', 'ux-studio' ), __( 'Update a user', 'ux-studio' ), 'PUT', '/users/(?P<id>[\d]+)' ),
			new RestOperationConfig( 'users-delete', __( 'Delete User', 'ux-studio' ), __( 'Delete a user', 'ux-studio' ), 'DELETE', '/users/(?P<id>[\d]+)' ),
		);
	}
}
