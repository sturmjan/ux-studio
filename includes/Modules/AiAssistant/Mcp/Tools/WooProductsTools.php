<?php
/**
 * MCP tools wrapping the wc/v3/products REST route (WooCommerce).
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\AiAssistant\Mcp\Tools;

use UxStudio\Modules\AiAssistant\Mcp\Dto\RestOperationConfig;

defined( 'ABSPATH' ) || exit;

class WooProductsTools extends RestEndpointTool {

	protected function get_operations(): array {
		return array(
			new RestOperationConfig( 'woo-products-list', __( 'List Products', 'ux-studio' ), __( 'List WooCommerce products', 'ux-studio' ), 'GET', '/products', 'wc/v3' ),
			new RestOperationConfig( 'woo-products-get', __( 'Get Product', 'ux-studio' ), __( 'Get a product by ID', 'ux-studio' ), 'GET', '/products/(?P<id>[\d]+)', 'wc/v3' ),
			new RestOperationConfig( 'woo-products-create', __( 'Create Product', 'ux-studio' ), __( 'Create a new product', 'ux-studio' ), 'POST', '/products', 'wc/v3' ),
			new RestOperationConfig( 'woo-products-update', __( 'Update Product', 'ux-studio' ), __( 'Update a product', 'ux-studio' ), 'PUT', '/products/(?P<id>[\d]+)', 'wc/v3' ),
			new RestOperationConfig( 'woo-products-delete', __( 'Delete Product', 'ux-studio' ), __( 'Delete a product', 'ux-studio' ), 'DELETE', '/products/(?P<id>[\d]+)', 'wc/v3' ),
		);
	}
}
