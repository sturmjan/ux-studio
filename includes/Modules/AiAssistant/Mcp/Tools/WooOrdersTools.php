<?php
/**
 * MCP tools wrapping the wc/v3/orders REST route (WooCommerce).
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\AiAssistant\Mcp\Tools;

use UxStudio\Modules\AiAssistant\Mcp\Dto\RestOperationConfig;

defined( 'ABSPATH' ) || exit;

class WooOrdersTools extends RestEndpointTool {

	protected function get_operations(): array {
		return array(
			new RestOperationConfig( 'woo-orders-list', __( 'List Orders', 'ux-studio' ), __( 'List WooCommerce orders', 'ux-studio' ), 'GET', '/orders', 'wc/v3' ),
			new RestOperationConfig( 'woo-orders-get', __( 'Get Order', 'ux-studio' ), __( 'Get an order by ID', 'ux-studio' ), 'GET', '/orders/(?P<id>[\d]+)', 'wc/v3' ),
			new RestOperationConfig( 'woo-orders-create', __( 'Create Order', 'ux-studio' ), __( 'Create a new order', 'ux-studio' ), 'POST', '/orders', 'wc/v3' ),
		);
	}
}
