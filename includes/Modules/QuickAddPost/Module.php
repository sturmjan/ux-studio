<?php
/**
 * Quick Add Post module.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\QuickAddPost;

use UxStudio\Modules\BaseModule;

defined( 'ABSPATH' ) || exit;

/**
 * Adds a "New" button to the block editor toolbar that starts a fresh post of
 * the same post type. Ported from the legacy module; the toolbar button is
 * injected with a small inline script instead of a bundled asset file.
 */
final class Module extends BaseModule {

	/**
	 * Post types that never get the button.
	 *
	 * @var string[]
	 */
	private array $excluded_post_types = array(
		'attachment',
		'elementor_library',
		'e-landing-page',
		'product',
		'sfwd-courses',
	);

	/**
	 * Register hooks.
	 */
	public function boot(): void {
		$this->excluded_post_types = apply_filters( 'ux_studio/quick_add_post/excluded_post_types', $this->excluded_post_types );

		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	/**
	 * Attach the toolbar button script in the block editor.
	 */
	public function enqueue_assets(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || 'post' !== $screen->base || ! $screen->is_block_editor() ) {
			return;
		}

		if ( ! $this->is_enabled( $screen ) ) {
			return;
		}

		$config = array(
			'newPostUrl' => admin_url( sprintf( 'post-new.php?post_type=%s', $screen->post_type ) ),
			'i18n'       => array(
				'new'      => __( 'New', 'ux-studio' ),
				'new_post' => sprintf(
					/* translators: %s: post type singular name */
					__( 'New %s', 'ux-studio' ),
					$this->get_post_type_label( $screen )
				),
			),
		);

		wp_register_script( 'uxstudio-quick-add-post', '', array( 'wp-data' ), defined( 'UXSTUDIO_VERSION' ) ? UXSTUDIO_VERSION : false, true );
		wp_enqueue_script( 'uxstudio-quick-add-post' );
		wp_add_inline_script(
			'uxstudio-quick-add-post',
			'window.uxStudioQuickAddPost = ' . wp_json_encode( $config ) . ';' . $this->get_inline_script()
		);
	}

	/**
	 * Whether the button is enabled for the current screen.
	 *
	 * @param \WP_Screen $screen Current screen.
	 */
	private function is_enabled( \WP_Screen $screen ): bool {
		if ( in_array( $screen->post_type, $this->excluded_post_types, true ) ) {
			return false;
		}

		$post_types = (array) $this->settings->get( 'post_types', array( 'post', 'page' ) );
		if ( array() === $post_types ) {
			return false;
		}

		$enabled = in_array( $screen->post_type, $post_types, true );

		return (bool) apply_filters( 'ux_studio/quick_add_post/is_enabled', $enabled, $screen );
	}

	/**
	 * Singular label of the current screen's post type.
	 *
	 * @param \WP_Screen $screen Current screen.
	 */
	private function get_post_type_label( \WP_Screen $screen ): string {
		$post_type = get_post_type_object( $screen->post_type );

		if ( $post_type && isset( $post_type->labels->singular_name ) ) {
			return $post_type->labels->singular_name;
		}

		return (string) $screen->post_type;
	}

	/**
	 * Inline script injecting the toolbar button (port of the legacy asset).
	 */
	private function get_inline_script(): string {
		return <<<'JS'
(function(){
	var config = window.uxStudioQuickAddPost;
	if (!config || !window.wp || !wp.data) { return; }
	var toolbarSelector = '.editor-document-tools';
	function insertButton(){
		var toolbar = document.querySelector(toolbarSelector);
		if (!toolbar || document.querySelector('.uxstudio-quick-add-post')) { return; }
		var left = toolbar.querySelector('.editor-document-tools__left');
		var button = document.createElement('button');
		button.className = 'components-button is-secondary uxstudio-quick-add-post';
		button.textContent = config.i18n.new;
		button.setAttribute('aria-label', config.i18n.new_post);
		button.style.marginLeft = '8px';
		button.addEventListener('click', function(){ window.location.href = config.newPostUrl; });
		if (left && left.nextSibling) { toolbar.insertBefore(button, left.nextSibling); } else { toolbar.appendChild(button); }
	}
	var unsubscribe = wp.data.subscribe(function(){
		var editor = wp.data.select('core/editor');
		var ready = editor && editor.isCleanNewPost() === false;
		var toolbar = document.querySelector(toolbarSelector);
		if (ready && toolbar) {
			unsubscribe();
			setTimeout(insertButton, 100);
		}
	});
})();
JS;
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
				'help'    => __( 'Select the post types that show the quick add button.', 'ux-studio' ),
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
		$options = array();
		foreach ( get_post_types( array( 'public' => true ), 'objects' ) as $post_type ) {
			if ( in_array( $post_type->name, $this->excluded_post_types, true ) ) {
				continue;
			}
			$options[ $post_type->name ] = $post_type->label;
		}
		return $options;
	}
}
