<?php
/**
 * External Permalinks module - point content to an external URL.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\ExternalPermalinks;

use UxStudio\Modules\BaseModule;

defined( 'ABSPATH' ) || exit;

/**
 * Lets selected post types link to an external URL via the standard `_links_to`
 * post meta, replacing the permalink and redirecting single views. Ported from
 * the legacy module (block editor panel + classic meta box).
 */
final class Module extends BaseModule {

	/**
	 * Meta key holding the external URL (shared "Page Links To" convention).
	 */
	private const META_KEY = '_links_to';

	/**
	 * Post types that can never use external permalinks.
	 *
	 * @var string[]
	 */
	private array $excluded_post_types = array( 'attachment', 'elementor_library', 'e-landing-page' );

	/**
	 * Register hooks.
	 */
	public function boot(): void {
		$this->register_meta();
		add_action( 'rest_api_init', array( $this, 'register_meta' ) );

		add_action( 'add_meta_boxes', array( $this, 'add_meta_box' ), 20 );
		add_action( 'save_post', array( $this, 'save_meta_box' ), 10, 2 );

		add_filter( 'page_link', array( $this, 'filter_page_link' ), 20, 2 );
		add_filter( 'post_link', array( $this, 'filter_post_link' ), 20, 2 );
		add_filter( 'post_type_link', array( $this, 'filter_post_type_link' ), 20, 2 );

		add_action( 'template_redirect', array( $this, 'maybe_redirect_to_external' ), 1 );

		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'enqueue_block_editor_assets', array( $this, 'enqueue_assets' ) );
	}

	/**
	 * Register the `_links_to` meta for selected post types (block editor).
	 */
	public function register_meta(): void {
		$post_types = $this->get_selected_post_types();
		if ( empty( $post_types ) ) {
			return;
		}

		foreach ( $post_types as $post_type ) {
			register_post_meta(
				$post_type,
				self::META_KEY,
				array(
					'show_in_rest'      => true,
					'single'            => true,
					'type'              => 'string',
					'sanitize_callback' => 'esc_url_raw',
					'auth_callback'     => static function ( $allowed, $meta_key, $post_id ) {
						return current_user_can( 'edit_post', $post_id );
					},
				)
			);
		}
	}

	/**
	 * Enqueue the block editor panel / classic meta box assets.
	 */
	public function enqueue_assets(): void {
		if ( ! is_admin() ) {
			return;
		}

		$current_post_type = get_post_type();
		if ( ! $this->is_selected_post_type( (string) $current_post_type ) ) {
			return;
		}

		$is_block_editor = $this->is_block_editor();
		$version         = defined( 'UXSTUDIO_VERSION' ) ? UXSTUDIO_VERSION : false;

		wp_enqueue_style(
			'uxstudio-external-permalinks',
			plugins_url( 'assets/css/external-permalinks.css', __FILE__ ),
			array(),
			$version
		);

		wp_enqueue_script(
			'uxstudio-external-permalinks',
			plugins_url( 'assets/js/external-permalinks.js', __FILE__ ),
			$is_block_editor ? array( 'wp-plugins', 'wp-editor', 'wp-components', 'wp-element', 'wp-data', 'wp-dom-ready' ) : array(),
			$version,
			true
		);

		wp_localize_script(
			'uxstudio-external-permalinks',
			'uxStudioExternalPermalinks',
			array(
				'is_block_editor'   => $is_block_editor,
				'post_types'        => $this->get_selected_post_types(),
				'current_post_type' => $current_post_type,
				'i18n'              => $this->get_translations(),
			)
		);
	}

	/**
	 * Add the classic editor meta box.
	 *
	 * @param string $post_type Current post type.
	 */
	public function add_meta_box( string $post_type ): void {
		if ( $this->is_block_editor() || ! $this->is_selected_post_type( $post_type ) ) {
			return;
		}

		add_meta_box(
			'uxstudio-external-permalinks',
			__( 'External Permalink', 'ux-studio' ),
			array( $this, 'render_meta_box' ),
			$post_type,
			'side',
			'high'
		);
	}

	/**
	 * Render the classic editor meta box.
	 *
	 * @param \WP_Post $post Current post.
	 */
	public function render_meta_box( \WP_Post $post ): void {
		$translations = $this->get_translations();
		$value        = get_post_meta( $post->ID, self::META_KEY, true );

		wp_nonce_field( 'uxstudio_external_permalink_' . $post->ID, 'uxstudio_external_permalink_nonce' );

		printf(
			'<div class="uxstudio-external-permalink-input">
				<label for="uxstudio_external_permalink" class="screen-reader-text">%s</label>
				<input name="uxstudio_external_permalink" id="uxstudio_external_permalink" class="large-text" type="url" value="%s" placeholder="https://" />
				<p class="description">%s</p>
			</div>',
			esc_html( $translations['urlLabel'] ),
			esc_attr( (string) $value ),
			esc_html( $translations['urlHelp'] )
		);
	}

	/**
	 * Save the classic editor meta box.
	 *
	 * @param int      $post_id Post ID.
	 * @param \WP_Post $post    Post object.
	 */
	public function save_meta_box( int $post_id, \WP_Post $post ): void {
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		$nonce = isset( $_POST['uxstudio_external_permalink_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['uxstudio_external_permalink_nonce'] ) ) : '';
		if ( '' === $nonce || ! wp_verify_nonce( $nonce, 'uxstudio_external_permalink_' . $post_id ) ) {
			return;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		if ( ! $this->is_selected_post_type( $post->post_type ) ) {
			return;
		}

		$raw   = isset( $_POST['uxstudio_external_permalink'] ) ? wp_unslash( $_POST['uxstudio_external_permalink'] ) : '';
		$value = '' !== $raw ? esc_url_raw( trim( (string) $raw ) ) : '';

		if ( '' !== $value ) {
			update_post_meta( $post_id, self::META_KEY, $value );
		} else {
			delete_post_meta( $post_id, self::META_KEY );
		}
	}

	/**
	 * Replace a post permalink with the external URL when set.
	 *
	 * @param string   $permalink Permalink.
	 * @param \WP_Post $post      Post object.
	 */
	public function filter_post_link( string $permalink, $post ): string {
		if ( ! $post instanceof \WP_Post || ! $this->is_selected_post_type( $post->post_type ) ) {
			return $permalink;
		}
		$external = get_post_meta( $post->ID, self::META_KEY, true );
		return ! empty( $external ) ? (string) $external : $permalink;
	}

	/**
	 * Replace a page permalink with the external URL when set.
	 *
	 * @param string $permalink Permalink.
	 * @param int    $post_id   Post ID.
	 */
	public function filter_page_link( string $permalink, int $post_id ): string {
		$post = get_post( $post_id );
		if ( ! $post || ! $this->is_selected_post_type( $post->post_type ) ) {
			return $permalink;
		}
		$external = get_post_meta( $post_id, self::META_KEY, true );
		return ! empty( $external ) ? (string) $external : $permalink;
	}

	/**
	 * Replace a custom post type permalink with the external URL when set.
	 *
	 * @param string   $permalink Permalink.
	 * @param \WP_Post $post      Post object.
	 */
	public function filter_post_type_link( string $permalink, $post ): string {
		if ( ! $post instanceof \WP_Post || ! $this->is_selected_post_type( $post->post_type ) ) {
			return $permalink;
		}
		$external = get_post_meta( $post->ID, self::META_KEY, true );
		return ! empty( $external ) ? (string) $external : $permalink;
	}

	/**
	 * Redirect single views to the external URL when set.
	 */
	public function maybe_redirect_to_external(): void {
		if ( is_admin() || is_preview() || is_customize_preview() || ! is_singular() ) {
			return;
		}

		if ( wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || is_feed() ) {
			return;
		}

		$post = get_queried_object();
		if ( ! $post instanceof \WP_Post || ! $this->is_selected_post_type( $post->post_type ) ) {
			return;
		}

		$external = get_post_meta( $post->ID, self::META_KEY, true );
		if ( empty( $external ) ) {
			return;
		}

		$current_url = ( is_ssl() ? 'https://' : 'http://' ) . sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ?? '' ) ) . sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ?? '' ) );
		if ( rtrim( $current_url, '/' ) === rtrim( (string) $external, '/' ) ) {
			return;
		}

		$status = (int) apply_filters( 'ux_studio/external_permalinks/redirect_status', 302, $post );
		if ( 301 !== $status && 302 !== $status ) {
			$status = 302;
		}

		wp_redirect( (string) $external, $status ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- intentional external redirect.
		exit;
	}

	/**
	 * UI translation strings passed to the editor script.
	 *
	 * @return array<string, string>
	 */
	private function get_translations(): array {
		return array(
			'panelTitle' => __( 'External Permalink', 'ux-studio' ),
			'urlLabel'   => __( 'Enter URL', 'ux-studio' ),
			'urlHelp'    => __( 'Leave empty to use the default WordPress permalink. An external permalink overrides the default slug.', 'ux-studio' ),
		);
	}

	/**
	 * Whether the current admin screen is the block editor.
	 */
	private function is_block_editor(): bool {
		if ( ! function_exists( 'get_current_screen' ) ) {
			return false;
		}
		$screen = get_current_screen();
		return $screen && method_exists( $screen, 'is_block_editor' ) && $screen->is_block_editor();
	}

	/**
	 * Whether the given post type is enabled in settings.
	 *
	 * @param string $post_type Post type name.
	 */
	private function is_selected_post_type( string $post_type ): bool {
		return in_array( $post_type, $this->get_selected_post_types(), true );
	}

	/**
	 * Selected post types from settings.
	 *
	 * @return string[]
	 */
	private function get_selected_post_types(): array {
		$post_types = $this->settings->get( 'post_types', array() );
		return is_array( $post_types ) ? array_values( $post_types ) : array();
	}

	/**
	 * Post types available for selection.
	 *
	 * @return array<string, string>
	 */
	private function get_available_post_types(): array {
		$post_types = get_post_types(
			array(
				'public'  => true,
				'show_ui' => true,
			),
			'objects'
		);

		$choices = array();
		foreach ( $post_types as $type ) {
			if ( in_array( $type->name, $this->excluded_post_types, true ) ) {
				continue;
			}
			$choices[ $type->name ] = $type->labels->name;
		}

		return $choices;
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
				'help'    => __( 'Select the post types that can use external permalinks.', 'ux-studio' ),
				'options' => $this->get_available_post_types(),
				'default' => array(),
			),
		);
	}
}
