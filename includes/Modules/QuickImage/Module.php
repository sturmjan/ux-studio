<?php
/**
 * Quick Image module (ported from the legacy free + pro modules, merged).
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\QuickImage;

use UxStudio\Modules\BaseModule;

defined( 'ABSPATH' ) || exit;

/**
 * Adds a featured-image thumbnail column to the configured list tables; clicking
 * it opens the media modal and updates the featured image through a REST call.
 */
final class Module extends BaseModule {

	/**
	 * Post types that never get the quick image column.
	 *
	 * @var string[]
	 */
	private array $excluded_post_types = array( 'attachment', 'elementor_library' );

	/**
	 * Register hooks.
	 */
	public function boot(): void {
		$this->excluded_post_types = apply_filters( 'ux_studio/quick_image/excluded_post_types', $this->excluded_post_types );

		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );

		$this->register_columns();

		// WooCommerce product list: drop the native thumbnail column to avoid duplication.
		add_filter( 'manage_edit-product_columns', array( $this, 'maybe_remove_product_thumb' ), 10, 1 );
	}

	/**
	 * Register the module REST controller.
	 */
	public function register_rest_routes(): void {
		( new RestController() )->register_routes();
	}

	/**
	 * REST controller class.
	 */
	public function rest_controller(): ?string {
		return RestController::class;
	}

	/**
	 * Capability required to manage this module (settings screen).
	 */
	public function capability(): string {
		return 'edit_posts';
	}

	/**
	 * Hook the column filters for the enabled post types.
	 */
	private function register_columns(): void {
		foreach ( $this->enabled_post_types() as $type ) {
			add_filter( 'manage_' . $type . '_posts_columns', array( $this, 'add_column' ), 10, 1 );
			add_action( 'manage_' . $type . '_posts_custom_column', array( $this, 'render_column' ), 10, 2 );
		}
	}

	/**
	 * Enabled post types (from settings), minus hard exclusions.
	 *
	 * @return string[]
	 */
	private function enabled_post_types(): array {
		$types = (array) $this->settings->get( 'post_types', array() );
		return array_values( array_diff( $types, $this->excluded_post_types ) );
	}

	/**
	 * Enqueue the media modal script + styles on list tables where enabled.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function enqueue_assets( $hook ): void {
		if ( 'edit.php' !== $hook ) {
			return;
		}
		$screen = get_current_screen();
		if ( ! $screen || ! in_array( $screen->post_type, $this->enabled_post_types(), true ) ) {
			return;
		}

		wp_enqueue_media();

		$base = UXSTUDIO_URL . 'includes/Modules/QuickImage/assets/';
		$ver  = defined( 'UXSTUDIO_VERSION' ) ? UXSTUDIO_VERSION : false;

		wp_enqueue_style( 'uxstudio-quick-image', $base . 'quick-image.css', array(), $ver );
		wp_enqueue_script( 'uxstudio-quick-image', $base . 'quick-image.js', array(), $ver, true );

		wp_localize_script(
			'uxstudio-quick-image',
			'uxStudioQuickImage',
			array(
				'restUrl'     => rest_url( 'uxstudio/v1/quick-image/update' ),
				'nonce'       => wp_create_nonce( 'wp_rest' ),
				'placeholder' => $this->placeholder_uri(),
				'i18n'        => array(
					'title'  => __( 'Select featured image', 'ux-studio' ),
					'add'    => __( 'Set featured image', 'ux-studio' ),
					'remove' => __( 'Remove image', 'ux-studio' ),
					'error'  => __( 'An error occurred while updating the image.', 'ux-studio' ),
				),
			)
		);
	}

	/**
	 * Add the quick image column after the title column.
	 *
	 * @param array $columns Existing columns.
	 * @return array
	 */
	public function add_column( array $columns ): array {
		$new = array(
			'uxstudio_quick_image' => '<span class="dashicons dashicons-format-image" aria-hidden="true"></span><span class="screen-reader-text">' . esc_html__( 'Featured image', 'ux-studio' ) . '</span>',
		);

		$pos = array_search( 'title', array_keys( $columns ), true );
		if ( false === $pos ) {
			return $columns + $new;
		}
		++$pos;
		return array_slice( $columns, 0, $pos, true ) + $new + array_slice( $columns, $pos, null, true );
	}

	/**
	 * Render the quick image column cell.
	 *
	 * @param string $column  Column name.
	 * @param int    $post_id Post ID.
	 */
	public function render_column( $column, $post_id ): void {
		if ( 'uxstudio_quick_image' !== $column ) {
			return;
		}

		$thumbnail_url = $this->placeholder_uri();
		$thumbnail_id  = 0;

		if ( has_post_thumbnail( $post_id ) ) {
			$thumbnail_url = (string) get_the_post_thumbnail_url( $post_id, 'thumbnail' );
			$thumbnail_id  = (int) get_post_thumbnail_id( $post_id );
		}

		$img = sprintf(
			'<img src="%s" class="uxstudio-quick-image__image" alt="%s" width="50" height="50">',
			esc_url( $thumbnail_url ),
			esc_attr__( 'Featured image thumbnail', 'ux-studio' )
		);

		if ( current_user_can( 'edit_post', $post_id ) ) {
			$img = sprintf(
				'<button type="button" class="uxstudio-quick-image__button" data-post-id="%s" data-thumbnail-id="%s" aria-label="%s">%s</button>',
				esc_attr( (string) $post_id ),
				esc_attr( (string) $thumbnail_id ),
				esc_attr__( 'Change featured image', 'ux-studio' ),
				$img
			);
		}

		echo wp_kses(
			$img,
			array(
				'img'    => array(
					'src'    => array(),
					'class'  => array(),
					'alt'    => array(),
					'width'  => array(),
					'height' => array(),
				),
				'button' => array(
					'type'              => array(),
					'class'             => array(),
					'data-post-id'      => array(),
					'data-thumbnail-id' => array(),
					'aria-label'        => array(),
				),
			)
		);
	}

	/**
	 * Remove the native WooCommerce product thumbnail column when enabled there.
	 *
	 * @param array $columns Columns.
	 * @return array
	 */
	public function maybe_remove_product_thumb( array $columns ): array {
		if ( in_array( 'product', $this->enabled_post_types(), true ) ) {
			unset( $columns['thumb'] );
		}
		return $columns;
	}

	/**
	 * Inline SVG placeholder shown when a post has no featured image.
	 */
	private function placeholder_uri(): string {
		$svg = "<svg xmlns='http://www.w3.org/2000/svg' width='50' height='50' viewBox='0 0 24 24' fill='none' stroke='%23a7aaad' stroke-width='1.5'><rect x='3' y='3' width='18' height='18' rx='2'/><circle cx='8.5' cy='8.5' r='1.5'/><path d='M21 15l-5-5L5 21'/></svg>";
		return 'data:image/svg+xml,' . $svg;
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
				'help'    => __( 'Show the featured image column for these post types.', 'ux-studio' ),
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
