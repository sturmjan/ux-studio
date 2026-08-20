<?php
/**
 * Quick Image REST controller.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\QuickImage;

use UxStudio\Rest\Controller;
use WP_Error;
use WP_REST_Request;

defined( 'ABSPATH' ) || exit;

/**
 * POST uxstudio/v1/quick-image/update - set/remove a post's featured image.
 */
final class RestController extends Controller {

	/**
	 * Register routes.
	 */
	public function register_routes(): void {
		$this->route(
			'/quick-image/update',
			'POST',
			array( $this, 'update' ),
			array(
				'post_id'       => array(
					'required'          => true,
					'type'              => 'integer',
					'sanitize_callback' => 'absint',
					'validate_callback' => static fn ( $param ): bool => is_numeric( $param ) && (int) $param > 0,
				),
				'attachment_id' => array(
					'required'          => false,
					'type'              => 'integer',
					'default'           => 0,
					'sanitize_callback' => 'absint',
					'validate_callback' => static fn ( $param ): bool => '' === $param || null === $param || is_numeric( $param ),
				),
			),
			// Broad gate; per-post capability is checked in the handler.
			'edit_posts'
		);
	}

	/**
	 * Update the featured image for a post.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function update( WP_REST_Request $request ) {
		$post_id       = (int) $request->get_param( 'post_id' );
		$attachment_id = (int) $request->get_param( 'attachment_id' );

		$post = get_post( $post_id );
		if ( ! $post ) {
			return new WP_Error( 'uxstudio_not_found', __( 'Invalid post ID.', 'ux-studio' ), array( 'status' => 404 ) );
		}

		// Per-item authorization on the specific post.
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return new WP_Error( 'uxstudio_forbidden', __( 'You are not allowed to edit this post.', 'ux-studio' ), array( 'status' => 403 ) );
		}

		if ( 0 === $attachment_id ) {
			delete_post_thumbnail( $post_id );
			return $this->ok( array( 'message' => __( 'Featured image removed.', 'ux-studio' ) ) );
		}

		$attachment = get_post( $attachment_id );
		if ( ! $attachment || 'attachment' !== $attachment->post_type ) {
			return new WP_Error( 'uxstudio_invalid_attachment', __( 'Invalid attachment.', 'ux-studio' ), array( 'status' => 400 ) );
		}

		if ( ! set_post_thumbnail( $post_id, $attachment_id ) ) {
			return new WP_Error( 'uxstudio_update_failed', __( 'Failed to update the featured image.', 'ux-studio' ), array( 'status' => 500 ) );
		}

		return $this->ok(
			array(
				'message' => __( 'Featured image updated.', 'ux-studio' ),
				'url'     => (string) get_the_post_thumbnail_url( $post_id, 'thumbnail' ),
			)
		);
	}
}
