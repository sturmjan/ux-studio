<?php
/**
 * MCP tools wrapping the wp/v2/media REST route.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\AiAssistant\Mcp\Tools;

use UxStudio\Modules\AiAssistant\Mcp\Dto\RestOperationConfig;

defined( 'ABSPATH' ) || exit;

class MediaTools extends RestEndpointTool {

	protected function get_operations(): array {
		return array(
			new RestOperationConfig( 'media-list', __( 'List Media', 'ux-studio' ), __( 'List media items', 'ux-studio' ), 'GET', '/media' ),
			new RestOperationConfig( 'media-get', __( 'Get Media', 'ux-studio' ), __( 'Get a media item by ID', 'ux-studio' ), 'GET', '/media/(?P<id>[\d]+)' ),
			new RestOperationConfig( 'media-delete', __( 'Delete Media', 'ux-studio' ), __( 'Delete a media item', 'ux-studio' ), 'DELETE', '/media/(?P<id>[\d]+)' ),
		);
	}
}
