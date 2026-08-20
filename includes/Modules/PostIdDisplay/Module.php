<?php
/**
 * Post ID Display module.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\PostIdDisplay;

use UxStudio\Modules\BaseModule;

defined( 'ABSPATH' ) || exit;

/**
 * Shows the post ID as the first row action in post/page list tables for the
 * configured post types. Ported from the legacy module.
 */
final class Module extends BaseModule {

	/**
	 * Register hooks.
	 */
	public function boot(): void {
		add_filter( 'post_row_actions', array( $this, 'add_post_id_to_row_actions' ), 10, 2 );
		add_filter( 'page_row_actions', array( $this, 'add_post_id_to_row_actions' ), 10, 2 );
	}

	/**
	 * Prepend an "ID: n" row action for enabled post types.
	 *
	 * @param array    $actions Row action links.
	 * @param \WP_Post $post    Current post.
	 * @return array
	 */
	public function add_post_id_to_row_actions( array $actions, \WP_Post $post ): array {
		$post_types = (array) $this->settings->get( 'post_types', array( 'post', 'page' ) );

		if ( ! in_array( $post->post_type, $post_types, true ) ) {
			return $actions;
		}

		if ( array_key_exists( 'id', $actions ) ) {
			return $actions;
		}

		$id_action = array(
			'id' => sprintf(
				/* translators: %d: post ID */
				esc_html__( 'ID: %d', 'ux-studio' ),
				$post->ID
			),
		);

		return array_merge( $id_action, $actions );
	}

	/**
	 * Settings schema for the generic renderer.
	 */
	public function settings_schema(): array {
		return array(
			array(
				'key'     => 'post_types',
				'type'    => 'multiselect',
				'label'   => __( 'Post types', 'ux-studio' ),
				'help'    => __( 'Select the post types to display the post ID for.', 'ux-studio' ),
				'options' => $this->get_post_type_options(),
				'default' => array( 'post', 'page' ),
			),
		);
	}

	/**
	 * Public post types available for selection.
	 *
	 * @return array<string, string>
	 */
	private function get_post_type_options(): array {
		$excluded = apply_filters(
			'ux_studio/post_id_display/excluded_post_types',
			array( 'attachment', 'revision', 'nav_menu_item' )
		);

		$options = array();
		foreach ( get_post_types( array( 'public' => true, 'show_ui' => true ), 'objects' ) as $type ) {
			if ( in_array( $type->name, $excluded, true ) ) {
				continue;
			}
			$options[ $type->name ] = $type->labels->name;
		}

		return apply_filters( 'ux_studio/post_id_display/post_types', $options );
	}
}
