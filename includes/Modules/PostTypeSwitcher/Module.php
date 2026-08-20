<?php
/**
 * Post Type Switcher module.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\PostTypeSwitcher;

use UxStudio\Modules\BaseModule;

defined( 'ABSPATH' ) || exit;

/**
 * Switches the post type of a post/page. Ported from the legacy module (free +
 * pro merged): a lightweight editor metabox and Quick Edit control drive the
 * change through save_post, while the SPA and bulk operations go through the
 * REST endpoints. Every path enforces edit_post + the target type's
 * publish_posts capability.
 */
final class Module extends BaseModule {

	private const NONCE = 'uxstudio_post_type_switcher';

	/**
	 * Register hooks.
	 */
	public function boot(): void {
		add_action( 'add_meta_boxes', array( $this, 'add_meta_box' ) );
		add_action( 'quick_edit_custom_box', array( $this, 'quick_edit_field' ), 10, 2 );
		add_action( 'save_post', array( $this, 'save_post' ) );
		add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );
	}

	/**
	 * Register the module REST controller.
	 */
	public function register_rest_routes(): void {
		( new RestController( $this ) )->register_routes();
	}

	/**
	 * REST controller class.
	 */
	public function rest_controller(): ?string {
		return RestController::class;
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
				'help'    => __( 'Enable the post type switcher for these post types.', 'ux-studio' ),
				'options' => $this->switchable_options(),
				'default' => array( 'post', 'page' ),
			),
		);
	}

	/**
	 * Post types the switcher is enabled for.
	 *
	 * @return string[]
	 */
	public function enabled_post_types(): array {
		$types = (array) $this->settings->get( 'post_types', array( 'post', 'page' ) );
		return array_values( array_filter( array_map( 'strval', $types ) ) );
	}

	/**
	 * Whether a post type participates in switching.
	 *
	 * @param string $post_type Post type name.
	 */
	public function is_enabled( string $post_type ): bool {
		return in_array( $post_type, $this->enabled_post_types(), true );
	}

	/**
	 * Post types that can be switched to (excludes attachments/builder types).
	 *
	 * @return \WP_Post_Type[]
	 */
	public function switchable_types(): array {
		$excluded = apply_filters(
			'ux_studio/post_type_switcher/excluded_post_types',
			array( 'attachment', 'elementor_library', 'e-landing-page' )
		);

		$types = get_post_types(
			array(
				'public'  => true,
				'show_ui' => true,
			),
			'objects'
		);

		foreach ( (array) $excluded as $name ) {
			unset( $types[ $name ] );
		}

		return $types;
	}

	/**
	 * Switch a post to a new post type after full capability validation.
	 *
	 * @param int    $post_id  Post ID.
	 * @param string $new_type Target post type.
	 * @return true|\WP_Error
	 */
	public function switch_post( int $post_id, string $new_type ) {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return new \WP_Error( 'uxstudio_invalid_post', __( 'Invalid post ID.', 'ux-studio' ), array( 'status' => 404 ) );
		}

		if ( ! $this->is_enabled( $post->post_type ) ) {
			return new \WP_Error( 'uxstudio_type_not_enabled', __( 'The post type switcher is not enabled for this post type.', 'ux-studio' ), array( 'status' => 400 ) );
		}

		$target = get_post_type_object( $new_type );
		if ( ! $target || 'attachment' === $new_type ) {
			return new \WP_Error( 'uxstudio_invalid_post_type', __( 'Invalid target post type.', 'ux-studio' ), array( 'status' => 400 ) );
		}

		if ( ! current_user_can( 'edit_post', $post_id ) || ! current_user_can( $target->cap->publish_posts ) ) {
			return new \WP_Error( 'uxstudio_forbidden', __( 'Sorry, you are not allowed to switch this post type.', 'ux-studio' ), array( 'status' => 403 ) );
		}

		if ( $post->post_type === $new_type ) {
			return true;
		}

		if ( ! set_post_type( $post_id, $new_type ) ) {
			return new \WP_Error( 'uxstudio_switch_failed', __( 'Failed to update post type.', 'ux-studio' ), array( 'status' => 500 ) );
		}

		return true;
	}

	/**
	 * Add the classic-editor metabox for enabled post types.
	 *
	 * @param string $post_type Current post type.
	 */
	public function add_meta_box( string $post_type ): void {
		if ( ! $this->is_enabled( $post_type ) ) {
			return;
		}

		add_meta_box(
			'uxstudio-post-type-switcher',
			__( 'Post Type Switcher', 'ux-studio' ),
			array( $this, 'render_meta_box' ),
			$post_type,
			'side',
			'default'
		);
	}

	/**
	 * Render the metabox select.
	 *
	 * @param \WP_Post $post Current post.
	 */
	public function render_meta_box( \WP_Post $post ): void {
		$options = $this->target_options( $post->post_type );
		if ( empty( $options ) ) {
			echo '<p>' . esc_html__( 'No alternative post types available.', 'ux-studio' ) . '</p>';
			return;
		}

		wp_nonce_field( self::NONCE, self::NONCE . '_nonce' );

		echo '<select name="uxstudio_post_type" class="widefat">';
		printf( '<option value="">%s</option>', esc_html__( '— No change —', 'ux-studio' ) );
		foreach ( $options as $name => $label ) {
			printf( '<option value="%s">%s</option>', esc_attr( $name ), esc_html( $label ) );
		}
		echo '</select>';
	}

	/**
	 * Render the Quick Edit control.
	 *
	 * @param string $column_name Column being rendered.
	 * @param string $post_type   Current post type.
	 */
	public function quick_edit_field( string $column_name, string $post_type ): void {
		static $printed = false;
		if ( $printed || 'title' !== $column_name || ! $this->is_enabled( $post_type ) ) {
			return;
		}

		$options = $this->target_options( $post_type );
		if ( empty( $options ) ) {
			return;
		}
		$printed = true;

		wp_nonce_field( self::NONCE, self::NONCE . '_nonce' );
		?>
		<fieldset class="inline-edit-col-right">
			<div class="inline-edit-col">
				<label>
					<span class="title"><?php esc_html_e( 'Post Type', 'ux-studio' ); ?></span>
					<select name="uxstudio_post_type">
						<option value=""><?php esc_html_e( '— No change —', 'ux-studio' ); ?></option>
						<?php foreach ( $options as $name => $label ) : ?>
							<option value="<?php echo esc_attr( $name ); ?>"><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
				</label>
			</div>
		</fieldset>
		<?php
	}

	/**
	 * Handle the metabox / Quick Edit submission.
	 *
	 * @param int $post_id Post ID.
	 */
	public function save_post( int $post_id ): void {
		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || ! isset( $_POST['uxstudio_post_type'] ) ) {
			return;
		}

		$nonce = isset( $_POST[ self::NONCE . '_nonce' ] ) ? sanitize_key( wp_unslash( $_POST[ self::NONCE . '_nonce' ] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, self::NONCE ) ) {
			return;
		}

		$new_type = sanitize_key( wp_unslash( $_POST['uxstudio_post_type'] ) );
		if ( '' === $new_type ) {
			return;
		}

		// switch_post performs the full capability + validation checks.
		$this->switch_post( $post_id, $new_type );
	}

	/**
	 * Target post types for a given source type (excludes itself + caps).
	 *
	 * @param string $current Current post type.
	 * @return array<string, string> name => singular label.
	 */
	private function target_options( string $current ): array {
		$options = array();
		foreach ( $this->switchable_types() as $name => $type ) {
			if ( $name === $current || ! current_user_can( $type->cap->publish_posts ) ) {
				continue;
			}
			$options[ $name ] = $type->labels->singular_name;
		}
		return $options;
	}

	/**
	 * Options for the settings multiselect (name => singular label).
	 *
	 * @return array<string, string>
	 */
	private function switchable_options(): array {
		$options = array();
		foreach ( $this->switchable_types() as $name => $type ) {
			$options[ $name ] = $type->labels->singular_name;
		}
		return $options;
	}
}
