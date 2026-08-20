<?php
/**
 * MCP tools wrapping the wp/v2/settings REST route.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\AiAssistant\Mcp\Tools;

use UxStudio\Modules\AiAssistant\Mcp\Dto\RestOperationConfig;

defined( 'ABSPATH' ) || exit;

class SettingsTools extends RestEndpointTool {

	protected function get_operations(): array {
		return array(
			new RestOperationConfig( 'settings-get', __( 'Get Settings', 'ux-studio' ), __( 'Get all WordPress settings', 'ux-studio' ), 'GET', '/settings' ),
			new RestOperationConfig( 'settings-update', __( 'Update Settings', 'ux-studio' ), __( 'Update WordPress settings', 'ux-studio' ), 'PUT', '/settings' ),
		);
	}
}
