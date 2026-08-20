<?php
/**
 * Post Type Order REST controller.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\PostTypeOrder;

use UxStudio\Rest\Controller;
use WP_Error;
use WP_REST_Request;

defined( 'ABSPATH' ) || exit;

/**
 * POST uxstudio/v1/post-order/reorder - persist a new menu_order for a set of posts.
 */
final class RestController extends Controller {

	private Module $module;

	/**
	 * @param Module $module Owning module instance.
	 */
	public function __construct( Module $module ) {
		$this->module = $module;
	}

	/**
	 * Register routes.
	 */
	public function register_routes(): void {
		$this->route(
			'/post-order/reorder',
			'POST',
			array( $this, 'reorder' ),
			array(
				'items' => array(
					'required'          => true,
					'type'              => 'array',
					'validate_callback' => array( $this, 'validate_items' ),
				),
			),
			'edit_others_posts'
		);
	}

	/**
	 * Validate the items payload.
	 *
	 * @param mixed $items Items.
	 * @return bool|WP_Error
	 */
	public function validate_items( $items ) {
		if ( ! is_array( $items ) || array() === $items ) {
			return new WP_Error( 'uxstudio_invalid_items', __( 'Items must be a non-empty array.', 'ux-studio' ) );
		}
		foreach ( $items as $item ) {
			if ( ! isset( $item['id'], $item['order'] ) ) {
				return new WP_Error( 'uxstudio_invalid_item', __( 'Each item must have an id and order.', 'ux-studio' ) );
			}
			if ( ! is_numeric( $item['id'] ) || ! is_numeric( $item['order'] ) ) {
				return new WP_Error( 'uxstudio_invalid_item_values', __( 'ID and order must be numeric.', 'ux-studio' ) );
			}
		}
		return true;
	}

	/**
	 * Handle the reorder request.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function reorder( WP_REST_Request $request ) {
		$items = (array) $request->get_param( 'items' );

		$result = $this->module->reorder_items( $items );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return $this->ok( $result );
	}
}
