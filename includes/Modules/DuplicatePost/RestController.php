<?php
/**
 * Duplicate Post REST controller.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\DuplicatePost;

use UxStudio\Rest\Controller;
use WP_Error;
use WP_REST_Request;

defined( 'ABSPATH' ) || exit;

/**
 * POST uxstudio/v1/duplicate-post/duplicate/{id} - duplicate one post with the
 * elements picked in the editor modal (param `elements`, empty = defaults).
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
			'/duplicate-post/duplicate/(?P<id>\d+)',
			'POST',
			array( $this, 'duplicate' ),
			array(
				'id'       => array(
					'required'          => true,
					'type'              => 'integer',
					'sanitize_callback' => 'absint',
				),
				'elements' => array(
					'required'          => false,
					'type'              => 'array',
					'items'             => array( 'type' => 'string' ),
					'sanitize_callback' => static function ( $value ): array {
						return is_array( $value ) ? array_map( 'sanitize_key', $value ) : array();
					},
				),
			),
			'edit_posts'
		);
	}

	/**
	 * Duplicate a post.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function duplicate( WP_REST_Request $request ) {
		$post_id = (int) $request->get_param( 'id' );

		if ( ! get_post( $post_id ) ) {
			return new WP_Error( 'uxstudio_invalid_post', __( 'Invalid post ID.', 'ux-studio' ), array( 'status' => 404 ) );
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return new WP_Error( 'uxstudio_forbidden', __( 'Sorry, you are not allowed to duplicate this post.', 'ux-studio' ), array( 'status' => rest_authorization_required_code() ) );
		}

		$duplicate = $this->module->duplicate_for_request( $post_id, (array) $request->get_param( 'elements' ) );
		if ( is_wp_error( $duplicate ) ) {
			return $duplicate;
		}

		$list_url = 'post' === $duplicate->post_type
			? admin_url( 'edit.php' )
			: admin_url( 'edit.php?post_type=' . $duplicate->post_type );

		return $this->ok(
			array(
				'id'       => (int) $duplicate->ID,
				'title'    => get_the_title( $duplicate->ID ),
				'url'      => (string) get_permalink( $duplicate->ID ),
				'edit_url' => (string) get_edit_post_link( $duplicate->ID, 'url' ),
				'list_url' => $list_url,
				'status'   => $duplicate->post_status,
			)
		);
	}
}
