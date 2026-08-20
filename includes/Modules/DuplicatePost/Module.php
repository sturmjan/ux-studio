<?php
/**
 * Duplicate Post module.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\DuplicatePost;

use UxStudio\Modules\BaseModule;

defined( 'ABSPATH' ) || exit;

/**
 * One-click post duplication from the post list row actions. Ported from the
 * legacy module (free + pro merged); the duplicate action runs through an
 * admin-post handler with a nonce instead of a REST/JS modal.
 */
final class Module extends BaseModule {

	private const ACTION = 'uxstudio_duplicate_post';

	/**
	 * Post types that can never be duplicated.
	 *
	 * @var string[]
	 */
	private array $excluded_post_types = array(
		'attachment',
		'elementor_library',
		'e-landing-page',
		'sfwd-courses',
	);

	/**
	 * Meta keys never copied to the duplicate.
	 *
	 * @var string[]
	 */
	private array $excluded_meta = array(
		'_edit_lock',
		'_edit_last',
		'_elementor_css',         // Elementor CSS cache.
		'_elementor_page_assets', // Elementor assets cache.
	);

	/**
	 * Register hooks.
	 */
	public function boot(): void {
		$this->excluded_post_types = apply_filters( 'ux_studio/duplicate_post/excluded_post_types', $this->excluded_post_types );
		$this->excluded_meta       = apply_filters( 'ux_studio/duplicate_post/excluded_meta', $this->excluded_meta );

		add_filter( 'post_row_actions', array( $this, 'add_duplicate_link' ), 10, 2 );
		add_filter( 'page_row_actions', array( $this, 'add_duplicate_link' ), 10, 2 );
		add_action( 'admin_post_' . self::ACTION, array( $this, 'handle_duplicate_request' ) );
	}

	/**
	 * Add a "Duplicate" row action for allowed post types.
	 *
	 * @param array    $actions Row action links.
	 * @param \WP_Post $post    Current post.
	 * @return array
	 */
	public function add_duplicate_link( array $actions, \WP_Post $post ): array {
		if ( ! $this->is_allowed_post_type( $post->post_type ) || ! current_user_can( 'edit_post', $post->ID ) ) {
			return $actions;
		}

		$url = wp_nonce_url(
			add_query_arg(
				array(
					'action' => self::ACTION,
					'post'   => $post->ID,
				),
				admin_url( 'admin-post.php' )
			),
			self::ACTION . '_' . $post->ID
		);

		$actions['duplicate'] = sprintf(
			'<a href="%s" aria-label="%s">%s</a>',
			esc_url( $url ),
			esc_attr(
				sprintf(
					/* translators: %s: post title */
					__( 'Duplicate %s', 'ux-studio' ),
					get_the_title( $post->ID )
				)
			),
			esc_html__( 'Duplicate', 'ux-studio' )
		);

		return $actions;
	}

	/**
	 * Handle the duplicate action (admin-post.php).
	 */
	public function handle_duplicate_request(): void {
		$post_id = isset( $_GET['post'] ) ? absint( $_GET['post'] ) : 0;

		if ( ! $post_id || ! wp_verify_nonce( sanitize_key( $_GET['_wpnonce'] ?? '' ), self::ACTION . '_' . $post_id ) ) {
			wp_die( esc_html__( 'Security check failed.', 'ux-studio' ), '', array( 'response' => 403 ) );
		}

		if ( ! current_user_can( 'edit_posts' ) || ! current_user_can( 'edit_post', $post_id ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to duplicate this post.', 'ux-studio' ), '', array( 'response' => 403 ) );
		}

		$post = get_post( $post_id );
		if ( ! $post || ! $this->is_allowed_post_type( $post->post_type ) ) {
			wp_die( esc_html__( 'Invalid post ID.', 'ux-studio' ), '', array( 'response' => 404 ) );
		}

		$duplicator = new Duplicator( $this->excluded_meta );
		$duplicate  = $duplicator->duplicate(
			$post_id,
			$this->get_default_elements(),
			$this->get_post_status( $post_id ),
			$this->get_post_title( $post_id )
		);

		if ( is_wp_error( $duplicate ) ) {
			wp_die( esc_html( $duplicate->get_error_message() ), '', array( 'response' => 500 ) );
		}

		if ( $this->settings->get( 'post_redirect', true ) ) {
			$redirect = get_edit_post_link( $duplicate->ID, 'url' );
		} else {
			$redirect = 'post' === $duplicate->post_type
				? admin_url( 'edit.php' )
				: admin_url( 'edit.php?post_type=' . $duplicate->post_type );
		}

		wp_safe_redirect( $redirect ?: admin_url( 'edit.php' ) );
		exit;
	}

	/**
	 * Whether the post type may be duplicated (setting + hard exclusions).
	 *
	 * @param string $post_type Post type name.
	 */
	private function is_allowed_post_type( string $post_type ): bool {
		if ( in_array( $post_type, $this->excluded_post_types, true ) ) {
			return false;
		}

		$post_types = (array) $this->settings->get( 'post_types', array( 'post', 'page' ) );
		$enabled    = in_array( $post_type, $post_types, true );

		return (bool) apply_filters( 'ux_studio/duplicate_post/is_enabled', $enabled, $post_type );
	}

	/**
	 * Title for the duplicated post (optional custom format).
	 *
	 * @param int $post_id Source post ID.
	 */
	private function get_post_title( int $post_id ): string {
		$title = get_the_title( $post_id );

		if ( $this->settings->get( 'custom_title', false ) ) {
			$format = (string) $this->settings->get( 'custom_title_format', '' );
			if ( '' === $format ) {
				$format = __( '[Duplicate] {post_title}', 'ux-studio' );
			}
			$title = str_replace( '{post_title}', $title, $format );
		}

		return (string) apply_filters( 'ux_studio/duplicate_post/post_title', $title, $post_id );
	}

	/**
	 * Status for the duplicated post.
	 *
	 * @param int $post_id Source post ID.
	 */
	private function get_post_status( int $post_id ): string {
		$status = (string) $this->settings->get( 'post_status', 'draft' );

		return (string) apply_filters( 'ux_studio/duplicate_post/post_status', $status, $post_id );
	}

	/**
	 * All elements a duplication can copy.
	 *
	 * @return array<string, string>
	 */
	private function get_available_elements(): array {
		$elements = array(
			'post_content'   => __( 'Post Content', 'ux-studio' ),
			'post_excerpt'   => __( 'Post Excerpt', 'ux-studio' ),
			'featured_image' => __( 'Featured Image', 'ux-studio' ),
			'taxonomies'     => __( 'Taxonomies', 'ux-studio' ),
			'meta_fields'    => __( 'Custom Fields', 'ux-studio' ),
			'menu_order'     => __( 'Menu Order', 'ux-studio' ),
			'post_parent'    => __( 'Post Parent', 'ux-studio' ),
		);

		if ( class_exists( 'WooCommerce' ) ) {
			$elements['product_gallery'] = __( 'Product Gallery', 'ux-studio' );
		}

		return apply_filters( 'ux_studio/duplicate_post/available_elements', $elements );
	}

	/**
	 * Elements copied by a duplication (from settings, default all).
	 *
	 * @return string[]
	 */
	private function get_default_elements(): array {
		$available = array_keys( $this->get_available_elements() );
		$saved     = $this->settings->get( 'duplicate_elements', null );

		$defaults = is_array( $saved ) ? array_values( array_intersect( $saved, $available ) ) : $available;

		return (array) apply_filters( 'ux_studio/duplicate_post/default_elements', $defaults );
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
				'help'    => __( 'Select the post types that can be duplicated.', 'ux-studio' ),
				'options' => $this->get_post_type_options(),
				'default' => array( 'post', 'page' ),
			),
			array(
				'key'     => 'post_redirect',
				'type'    => 'toggle',
				'label'   => __( 'Redirect to post', 'ux-studio' ),
				'help'    => __( 'Redirect to the post edit screen after duplication.', 'ux-studio' ),
				'default' => true,
			),
			array(
				'key'     => 'post_status',
				'type'    => 'select',
				'label'   => __( 'Post status', 'ux-studio' ),
				'help'    => __( 'Select the status of the duplicated post.', 'ux-studio' ),
				'options' => get_post_statuses(),
				'default' => 'draft',
			),
			array(
				'key'     => 'duplicate_elements',
				'type'    => 'multiselect',
				'label'   => __( 'Elements to duplicate', 'ux-studio' ),
				'help'    => __( 'Select which elements are copied when duplicating a post.', 'ux-studio' ),
				'options' => $this->get_available_elements(),
				'default' => array_keys( $this->get_available_elements() ),
			),
			array(
				'key'     => 'custom_title',
				'type'    => 'toggle',
				'label'   => __( 'Customise post title', 'ux-studio' ),
				'help'    => __( 'Enable this to use a custom format for the duplicated post title.', 'ux-studio' ),
				'default' => false,
			),
			array(
				'key'     => 'custom_title_format',
				'type'    => 'text',
				'label'   => __( 'Post title format', 'ux-studio' ),
				'help'    => __( 'Use {post_title} to insert the original post title.', 'ux-studio' ),
				'default' => __( '[Duplicate] {post_title}', 'ux-studio' ),
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
