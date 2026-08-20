<?php
/**
 * Post Type Switcher REST controller.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\PostTypeSwitcher;

use UxStudio\Rest\Controller;
use WP_REST_Request;

defined( 'ABSPATH' ) || exit;

/**
 * POST uxstudio/v1/post-type-switcher/switch - switch one post's type
 * POST uxstudio/v1/post-type-switcher/bulk   - switch many posts at once
 *
 * The route permission only guarantees the actor can edit posts; per-post
 * ownership and the target type's publish capability are enforced inside
 * Module::switch_post for every ID.
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
			'/post-type-switcher/switch',
			'POST',
			array( $this, 'switch_one' ),
			array(
				'post_id'  => array(
					'required'          => true,
					'type'              => 'integer',
					'sanitize_callback' => 'absint',
				),
				'new_type' => array(
					'required'          => true,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_key',
				),
			),
			'edit_posts'
		);

		$this->route(
			'/post-type-switcher/bulk',
			'POST',
			array( $this, 'switch_bulk' ),
			array(
				'post_ids' => array(
					'required' => true,
					'type'     => 'array',
				),
				'new_type' => array(
					'required'          => true,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_key',
				),
			),
			'edit_posts'
		);
	}

	/**
	 * Switch a single post.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function switch_one( WP_REST_Request $request ) {
		$post_id  = (int) $request->get_param( 'post_id' );
		$new_type = (string) $request->get_param( 'new_type' );

		$result = $this->module->switch_post( $post_id, $new_type );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return $this->ok(
			array(
				'post_id'  => $post_id,
				'new_type' => $new_type,
				'edit_url' => get_edit_post_link( $post_id, 'raw' ),
			)
		);
	}

	/**
	 * Switch many posts, collecting per-post results.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function switch_bulk( WP_REST_Request $request ) {
		$post_ids = array_map( 'absint', (array) $request->get_param( 'post_ids' ) );
		$new_type = (string) $request->get_param( 'new_type' );

		$switched = array();
		$failed   = array();
		foreach ( array_filter( $post_ids ) as $post_id ) {
			$result = $this->module->switch_post( $post_id, $new_type );
			if ( is_wp_error( $result ) ) {
				$failed[] = array(
					'post_id' => $post_id,
					'message' => $result->get_error_message(),
				);
			} else {
				$switched[] = $post_id;
			}
		}

		return $this->ok(
			array(
				'new_type' => $new_type,
				'switched' => $switched,
				'failed'   => $failed,
			)
		);
	}
}
