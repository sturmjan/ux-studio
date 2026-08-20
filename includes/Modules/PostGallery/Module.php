<?php
/**
 * Post Gallery module - image gallery per post with grid + lightbox.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\PostGallery;

use UxStudio\Modules\BaseModule;

defined( 'ABSPATH' ) || exit;

/**
 * Adds a gallery meta box to selected post types, renders a responsive grid on
 * the frontend and opens images in a lightbox. Ported from the legacy module.
 *
 * New data is stored under _uxstudio_gallery_* meta keys; reads fall back to the
 * legacy _ux1_gallery_* keys (migrated later). The shortcode is uxstudio_gallery
 * with a ux1_galerie alias for existing content.
 */
final class Module extends BaseModule {

	private const META_IMAGES = '_uxstudio_gallery_images';
	private const META_SHOW    = '_uxstudio_gallery_show';

	private const LEGACY_META_IMAGES = '_ux1_gallery_images';
	private const LEGACY_META_SHOW    = '_ux1_gallery_show';

	private const SHORTCODE        = 'uxstudio_gallery';
	private const SHORTCODE_LEGACY = 'ux1_galerie';

	private const NONCE_ACTION = 'uxstudio_gallery_save';
	private const NONCE_NAME   = 'uxstudio_gallery_nonce';

	/**
	 * Register hooks.
	 */
	public function boot(): void {
		add_action( 'add_meta_boxes', array( $this, 'register_meta_box' ) );
		add_action( 'save_post', array( $this, 'save_meta_box' ), 10, 2 );

		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_frontend_assets' ) );

		add_filter( 'the_content', array( $this, 'append_to_content' ), 999 );
		add_shortcode( self::SHORTCODE, array( $this, 'render_shortcode' ) );
		add_shortcode( self::SHORTCODE_LEGACY, array( $this, 'render_shortcode' ) );
	}

	// ---------------------------------------------------------------------
	//  Meta box
	// ---------------------------------------------------------------------

	/**
	 * Register the gallery meta box for enabled post types.
	 */
	public function register_meta_box(): void {
		foreach ( $this->get_enabled_post_types() as $post_type ) {
			add_meta_box(
				'uxstudio-post-gallery',
				__( 'Gallery', 'ux-studio' ),
				array( $this, 'render_meta_box' ),
				$post_type,
				'side',
				'low'
			);
		}
	}

	/**
	 * Render the meta box.
	 *
	 * @param \WP_Post $post Current post.
	 */
	public function render_meta_box( \WP_Post $post ): void {
		$images = $this->get_gallery_images( $post->ID );
		$show   = $this->get_gallery_show_raw( $post->ID );
		if ( '' === $show ) {
			$show = $this->settings->get( 'auto_append', true ) ? '1' : '0';
		}

		wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME );
		?>
		<div class="uxstudio-gallery-metabox">
			<div class="uxstudio-gallery-metabox__grid" id="uxstudio-gallery-grid">
				<?php
				foreach ( $images as $id ) :
					$thumb = wp_get_attachment_image_url( $id, 'thumbnail' );
					if ( ! $thumb ) {
						continue;
					}
					?>
					<div class="uxstudio-gallery-metabox__item" data-id="<?php echo esc_attr( $id ); ?>" draggable="true">
						<img src="<?php echo esc_url( $thumb ); ?>" alt="">
						<button type="button" class="uxstudio-gallery-metabox__remove" title="<?php esc_attr_e( 'Remove', 'ux-studio' ); ?>">&times;</button>
					</div>
				<?php endforeach; ?>
			</div>

			<button type="button" class="button uxstudio-gallery-metabox__add" id="uxstudio-gallery-add">
				<?php esc_html_e( 'Add images', 'ux-studio' ); ?>
			</button>

			<input type="hidden" name="uxstudio_gallery_images" id="uxstudio-gallery-ids" value="<?php echo esc_attr( implode( ',', $images ) ); ?>">

			<div class="uxstudio-gallery-metabox__options">
				<label>
					<input type="checkbox" name="uxstudio_gallery_show" value="1" <?php checked( $show, '1' ); ?>>
					<?php esc_html_e( 'Show gallery in the post', 'ux-studio' ); ?>
				</label>
			</div>

			<p class="description uxstudio-gallery-metabox__shortcode">
				<?php
				printf(
					/* translators: %s: shortcode example. */
					esc_html__( 'Or insert the shortcode %s', 'ux-studio' ),
					'<code>[' . esc_html( self::SHORTCODE ) . ']</code>'
				);
				?>
			</p>
		</div>
		<?php
	}

	/**
	 * Persist the meta box values.
	 *
	 * @param int      $post_id Post ID.
	 * @param \WP_Post $post    Post object.
	 */
	public function save_meta_box( int $post_id, \WP_Post $post ): void {
		$nonce = isset( $_POST[ self::NONCE_NAME ] ) ? sanitize_text_field( wp_unslash( $_POST[ self::NONCE_NAME ] ) ) : '';
		if ( '' === $nonce || ! wp_verify_nonce( $nonce, self::NONCE_ACTION ) ) {
			return;
		}

		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		if ( wp_is_post_revision( $post_id ) ) {
			return;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		if ( ! in_array( $post->post_type, $this->get_enabled_post_types(), true ) ) {
			return;
		}

		$ids_raw = isset( $_POST['uxstudio_gallery_images'] ) ? sanitize_text_field( wp_unslash( $_POST['uxstudio_gallery_images'] ) ) : '';
		$ids     = array();
		if ( '' !== $ids_raw ) {
			$ids = array_map( 'intval', array_filter( explode( ',', $ids_raw ) ) );
		}
		update_post_meta( $post_id, self::META_IMAGES, $ids );

		$show = isset( $_POST['uxstudio_gallery_show'] ) ? '1' : '0';
		update_post_meta( $post_id, self::META_SHOW, $show );
	}

	// ---------------------------------------------------------------------
	//  Frontend rendering
	// ---------------------------------------------------------------------

	/**
	 * Append the gallery to the content when enabled.
	 *
	 * @param string $content Post content.
	 */
	public function append_to_content( string $content ): string {
		if ( ! is_singular() ) {
			return $content;
		}

		if ( has_shortcode( $content, self::SHORTCODE ) || has_shortcode( $content, self::SHORTCODE_LEGACY ) ) {
			return $content;
		}

		$post = get_post();
		if ( ! $post ) {
			return $content;
		}

		if ( ! in_array( $post->post_type, $this->get_enabled_post_types(), true ) ) {
			return $content;
		}

		if ( '0' === $this->get_gallery_show_raw( $post->ID ) ) {
			return $content;
		}

		$html = $this->render_gallery( $post->ID );
		if ( '' === $html ) {
			return $content;
		}

		return $content . $html;
	}

	/**
	 * Shortcode handler.
	 *
	 * @param array|string $atts Shortcode attributes.
	 */
	public function render_shortcode( $atts ): string {
		$atts = shortcode_atts(
			array(
				'id'      => 0,
				'columns' => '',
				'size'    => '',
			),
			$atts,
			self::SHORTCODE
		);

		$post_id = (int) $atts['id'];
		if ( ! $post_id ) {
			$post_id = (int) get_the_ID();
		}
		if ( ! $post_id ) {
			return '';
		}

		$overrides = array();
		if ( '' !== $atts['columns'] ) {
			$overrides['columns'] = (int) $atts['columns'];
		}
		if ( '' !== $atts['size'] ) {
			$overrides['thumb_size'] = sanitize_key( $atts['size'] );
		}

		return $this->render_gallery( $post_id, $overrides );
	}

	/**
	 * Build the gallery markup.
	 *
	 * @param int   $post_id   Post ID.
	 * @param array $overrides Optional columns/thumb_size overrides.
	 */
	private function render_gallery( int $post_id, array $overrides = array() ): string {
		$images = $this->get_gallery_images( $post_id );
		if ( empty( $images ) ) {
			return '';
		}

		$columns       = isset( $overrides['columns'] ) ? (int) $overrides['columns'] : (int) $this->settings->get( 'columns', 3 );
		$thumb_size    = isset( $overrides['thumb_size'] ) ? $overrides['thumb_size'] : (string) $this->settings->get( 'thumb_size', 'medium' );
		$lightbox      = (bool) $this->settings->get( 'enable_lightbox', true );
		$lightbox_size = (string) $this->settings->get( 'lightbox_size', 'large' );

		$columns = max( 1, min( 6, $columns ) );

		$html = '<div class="uxstudio-gallery" style="--uxstudio-gallery-cols:' . $columns . '"' . ( $lightbox ? ' data-lightbox="1"' : '' ) . '>';

		foreach ( $images as $attachment_id ) {
			$thumb_url = wp_get_attachment_image_url( $attachment_id, $thumb_size );
			if ( ! $thumb_url ) {
				continue;
			}

			$full_url = $lightbox ? wp_get_attachment_image_url( $attachment_id, $lightbox_size ) : '';
			$alt      = get_post_meta( $attachment_id, '_wp_attachment_image_alt', true );
			$img_attr = wp_get_attachment_image_src( $attachment_id, $thumb_size );

			$img_tag = sprintf(
				'<img src="%s" alt="%s"%s%s loading="lazy">',
				esc_url( $thumb_url ),
				esc_attr( (string) $alt ),
				$img_attr ? ' width="' . esc_attr( $img_attr[1] ) . '"' : '',
				$img_attr ? ' height="' . esc_attr( $img_attr[2] ) . '"' : ''
			);

			if ( $lightbox && $full_url ) {
				$html .= '<div class="uxstudio-gallery__item">';
				$html .= '<a href="' . esc_url( $full_url ) . '" data-uxstudio-lightbox data-elementor-open-lightbox="no">';
				$html .= $img_tag;
				$html .= '</a></div>';
			} else {
				$html .= '<div class="uxstudio-gallery__item">' . $img_tag . '</div>';
			}
		}

		$html .= '</div>';

		return $html;
	}

	// ---------------------------------------------------------------------
	//  Assets
	// ---------------------------------------------------------------------

	/**
	 * Enqueue the meta box media picker assets.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function enqueue_admin_assets( string $hook ): void {
		if ( ! in_array( $hook, array( 'post.php', 'post-new.php' ), true ) ) {
			return;
		}

		$screen = get_current_screen();
		if ( ! $screen || ! in_array( $screen->post_type, $this->get_enabled_post_types(), true ) ) {
			return;
		}

		wp_enqueue_media();

		$version = defined( 'UXSTUDIO_VERSION' ) ? UXSTUDIO_VERSION : false;

		wp_enqueue_style(
			'uxstudio-post-gallery-admin',
			plugins_url( 'assets/css/post-gallery-admin.css', __FILE__ ),
			array(),
			$version
		);

		wp_enqueue_script(
			'uxstudio-post-gallery-admin',
			plugins_url( 'assets/js/post-gallery-admin.js', __FILE__ ),
			array(),
			$version,
			true
		);
	}

	/**
	 * Enqueue the frontend grid/lightbox assets when the post has a gallery.
	 */
	public function enqueue_frontend_assets(): void {
		if ( ! is_singular() ) {
			return;
		}

		$post = get_post();
		if ( ! $post || ! in_array( $post->post_type, $this->get_enabled_post_types(), true ) ) {
			return;
		}

		if ( empty( $this->get_gallery_images( $post->ID ) ) ) {
			return;
		}

		$version = defined( 'UXSTUDIO_VERSION' ) ? UXSTUDIO_VERSION : false;

		wp_enqueue_style(
			'uxstudio-post-gallery',
			plugins_url( 'assets/css/post-gallery.css', __FILE__ ),
			array(),
			$version
		);

		if ( $this->settings->get( 'enable_lightbox', true ) ) {
			wp_enqueue_script(
				'uxstudio-post-gallery',
				plugins_url( 'assets/js/post-gallery.js', __FILE__ ),
				array(),
				$version,
				true
			);
		}
	}

	// ---------------------------------------------------------------------
	//  Helpers
	// ---------------------------------------------------------------------

	/**
	 * Gallery image IDs (new key, falling back to the legacy key).
	 *
	 * @param int $post_id Post ID.
	 * @return int[]
	 */
	private function get_gallery_images( int $post_id ): array {
		$images = get_post_meta( $post_id, self::META_IMAGES, true );
		if ( ! is_array( $images ) || empty( $images ) ) {
			$images = get_post_meta( $post_id, self::LEGACY_META_IMAGES, true );
		}
		return is_array( $images ) ? array_map( 'intval', $images ) : array();
	}

	/**
	 * Raw "show gallery" flag ('1', '0' or '' when unset), new key with legacy
	 * fallback.
	 *
	 * @param int $post_id Post ID.
	 */
	private function get_gallery_show_raw( int $post_id ): string {
		$show = get_post_meta( $post_id, self::META_SHOW, true );
		if ( '' === $show ) {
			$show = get_post_meta( $post_id, self::LEGACY_META_SHOW, true );
		}
		return (string) $show;
	}

	/**
	 * Enabled post types from settings.
	 *
	 * @return string[]
	 */
	private function get_enabled_post_types(): array {
		$setting = $this->settings->get( 'post_types', array( 'post', 'page' ) );
		if ( ! is_array( $setting ) || empty( $setting ) ) {
			return array( 'post', 'page' );
		}
		return array_values( $setting );
	}

	/**
	 * Public post types available for selection.
	 *
	 * @return array<string, string>
	 */
	private function get_available_post_types(): array {
		$choices = array();
		foreach ( get_post_types( array( 'public' => true ), 'objects' ) as $type ) {
			if ( 'attachment' === $type->name ) {
				continue;
			}
			$choices[ $type->name ] = $type->label;
		}
		return $choices;
	}

	/**
	 * Settings schema for the generic renderer.
	 */
	public function settings_schema(): array {
		return array(
			array(
				'key'     => 'columns',
				'type'    => 'select',
				'label'   => __( 'Columns', 'ux-studio' ),
				'help'    => __( 'Number of columns in the gallery grid.', 'ux-studio' ),
				'options' => array(
					'2' => '2',
					'3' => '3',
					'4' => '4',
					'5' => '5',
					'6' => '6',
				),
				'default' => '3',
			),
			array(
				'key'     => 'thumb_size',
				'type'    => 'select',
				'label'   => __( 'Thumbnail size', 'ux-studio' ),
				'help'    => __( 'Image size used in the gallery grid.', 'ux-studio' ),
				'options' => array(
					'thumbnail'    => __( 'Thumbnail', 'ux-studio' ) . ' (150x150)',
					'medium'       => __( 'Medium', 'ux-studio' ) . ' (300x300)',
					'medium_large' => __( 'Medium large', 'ux-studio' ) . ' (768x0)',
					'large'        => __( 'Large', 'ux-studio' ) . ' (1024x1024)',
				),
				'default' => 'medium',
			),
			array(
				'key'     => 'enable_lightbox',
				'type'    => 'toggle',
				'label'   => __( 'Open in lightbox', 'ux-studio' ),
				'help'    => __( 'Clicking an image opens it in an enlarged lightbox.', 'ux-studio' ),
				'default' => true,
			),
			array(
				'key'     => 'lightbox_size',
				'type'    => 'select',
				'label'   => __( 'Lightbox size', 'ux-studio' ),
				'help'    => __( 'Image size shown in the lightbox.', 'ux-studio' ),
				'options' => array(
					'large' => __( 'Large', 'ux-studio' ) . ' (1024x1024)',
					'full'  => __( 'Full size', 'ux-studio' ),
				),
				'default' => 'large',
			),
			array(
				'key'     => 'auto_append',
				'type'    => 'toggle',
				'label'   => __( 'Auto append to the end of the post', 'ux-studio' ),
				'help'    => __( 'The gallery is shown automatically at the end of the content. Can be overridden per post.', 'ux-studio' ),
				'default' => true,
			),
			array(
				'key'     => 'post_types',
				'type'    => 'multiselect',
				'label'   => __( 'Post types', 'ux-studio' ),
				'help'    => __( 'Select which post types can use the gallery.', 'ux-studio' ),
				'options' => $this->get_available_post_types(),
				'default' => array( 'post', 'page' ),
			),
		);
	}
}
