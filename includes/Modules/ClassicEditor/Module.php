<?php
/**
 * Classic Editor module - disable the block editor per post type (ported from legacy module).
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\ClassicEditor;

use UxStudio\Modules\BaseModule;

defined( 'ABSPATH' ) || exit;

/**
 * Disables the block editor (Gutenberg) either for all post types or only for
 * the post types selected in the module settings.
 */
final class Module extends BaseModule {

	/**
	 * Post types that never get the classic editor treatment.
	 *
	 * @var string[]
	 */
	private array $excluded_post_types = array( 'attachment', 'elementor_library' );

	/**
	 * Register hooks.
	 */
	public function boot(): void {
		if ( version_compare( get_bloginfo( 'version' ), '5.0', '<' ) ) {
			add_filter( 'gutenberg_can_edit_post_type', array( $this, 'disable_gutenberg' ), 10, 2 );
		} else {
			add_filter( 'use_block_editor_for_post_type', array( $this, 'disable_gutenberg' ), 10, 2 );
		}
	}

	/**
	 * Decide whether the block editor should be used for a post type.
	 *
	 * @param bool   $use_block_editor Whether to use the block editor.
	 * @param string $post_type        Post type being checked.
	 */
	public function disable_gutenberg( $use_block_editor, $post_type ): bool {
		if ( in_array( $post_type, $this->get_excluded_post_types(), true ) ) {
			return (bool) $use_block_editor;
		}

		$post_types = (array) $this->settings->get( 'post_types', array() );

		// Empty selection = classic editor everywhere.
		$disable = array() === $post_types || in_array( $post_type, $post_types, true );

		/**
		 * Filter whether the block editor is disabled for a post type.
		 *
		 * @param bool   $disable   True to force the classic editor.
		 * @param string $post_type Post type being checked.
		 */
		$disable = (bool) apply_filters( 'ux_studio/classic_editor/disable_gutenberg', $disable, $post_type );

		return $disable ? false : (bool) $use_block_editor;
	}

	/**
	 * Post types excluded from the classic editor.
	 *
	 * @return string[]
	 */
	private function get_excluded_post_types(): array {
		/**
		 * Filter the post types the classic editor never applies to.
		 *
		 * @param string[] $excluded Excluded post type names.
		 */
		return (array) apply_filters( 'ux_studio/classic_editor/excluded_post_types', $this->excluded_post_types );
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
				'help'    => __( 'Select the post types to enable the classic editor for. Leave empty to enable it for all post types.', 'ux-studio' ),
				'options' => $this->get_post_type_options(),
				'default' => array(),
			),
		);
	}

	/**
	 * Selectable public post types.
	 *
	 * @return array<string, string>
	 */
	private function get_post_type_options(): array {
		$post_types = get_post_types(
			array(
				'public'  => true,
				'show_ui' => true,
			),
			'objects'
		);

		$options = array();

		foreach ( $post_types as $type ) {
			if ( in_array( $type->name, $this->get_excluded_post_types(), true ) ) {
				continue;
			}
			$options[ $type->name ] = $type->labels->name;
		}

		return $options;
	}
}
