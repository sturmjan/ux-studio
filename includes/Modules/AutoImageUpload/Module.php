<?php
/**
 * Auto Image Upload module (ported from the legacy module).
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\AutoImageUpload;

use UxStudio\Modules\BaseModule;

defined( 'ABSPATH' ) || exit;

/**
 * Sideloads external images pasted into the editor into the media library, with
 * an optional server-side safety net on save and a REST-driven bulk fix tool.
 */
final class Module extends BaseModule {

	/** @var Processor|null Lazy processor instance. */
	private ?Processor $processor = null;

	/**
	 * Register hooks.
	 */
	public function boot(): void {
		add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_editor_assets' ) );
		add_action( 'enqueue_block_editor_assets', array( $this, 'enqueue_block_editor_assets' ) );

		// Server-side safety net: rewrite external images on save.
		if ( $this->settings->get( 'server_filter', true ) ) {
			add_filter( 'wp_insert_post_data', array( $this, 'filter_post_on_save' ), 20, 2 );
		}
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

	/* ---------------------------------------------------------------------
	 * Asset enqueueing
	 * ------------------------------------------------------------------- */

	/**
	 * Enqueue the editor script on classic/editor screens.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function enqueue_editor_assets( $hook ): void {
		if ( ! in_array( $hook, array( 'post.php', 'post-new.php', 'widgets.php', 'customize.php' ), true ) ) {
			return;
		}
		$this->enqueue_script();
	}

	/**
	 * Enqueue the editor script in the block editor.
	 */
	public function enqueue_block_editor_assets(): void {
		$this->enqueue_script();
	}

	/**
	 * Register + localize the editor script.
	 */
	private function enqueue_script(): void {
		if ( ! current_user_can( 'upload_files' ) ) {
			return;
		}

		$handle = 'uxstudio-auto-image-upload';
		wp_enqueue_script(
			$handle,
			UXSTUDIO_URL . 'includes/Modules/AutoImageUpload/assets/auto-image-upload.js',
			array( 'wp-hooks', 'wp-data' ),
			defined( 'UXSTUDIO_VERSION' ) ? UXSTUDIO_VERSION : false,
			true
		);

		wp_localize_script(
			$handle,
			'uxStudioAutoImageUpload',
			array(
				'restUrl'     => rest_url( 'uxstudio/v1/auto-image-upload/sideload' ),
				'nonce'       => wp_create_nonce( 'wp_rest' ),
				'siteOrigin'  => wp_parse_url( home_url(), PHP_URL_SCHEME ) . '://' . wp_parse_url( home_url(), PHP_URL_HOST ),
				'showNotices' => (bool) $this->settings->get( 'show_notices', true ),
				'i18n'        => array(
					'uploading' => __( 'Uploading images to the server...', 'ux-studio' ),
					'uploaded'  => __( 'Images were added to the media library.', 'ux-studio' ),
					'failed'    => __( 'Some images could not be downloaded.', 'ux-studio' ),
				),
			)
		);
	}

	/* ---------------------------------------------------------------------
	 * Server-side safety net (runs on every post save)
	 * ------------------------------------------------------------------- */

	/**
	 * Rewrite external images in post content on save.
	 *
	 * @param array $data    Slashed post data.
	 * @param array $postarr Raw post data.
	 * @return array
	 */
	public function filter_post_on_save( $data, $postarr ) {
		if ( ! isset( $data['post_content'] ) || '' === $data['post_content'] ) {
			return $data;
		}

		$post_type = $data['post_type'] ?? '';
		if ( in_array( $post_type, array( 'revision', 'attachment', 'nav_menu_item', 'customize_changeset', 'oembed_cache', 'wp_block' ), true ) ) {
			return $data;
		}

		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return $data;
		}

		if ( false === stripos( $data['post_content'], '<img' ) ) {
			return $data;
		}

		$post_id    = isset( $postarr['ID'] ) ? (int) $postarr['ID'] : 0;
		$max_images = (int) $this->settings->get( 'max_images_per_save', 10 );
		if ( $max_images <= 0 ) {
			$max_images = 10;
		}

		// Content is stored slashed at this hook - unslash before processing.
		$content   = wp_unslash( $data['post_content'] );
		$rewritten = $this->processor()->process_content( $content, $post_id, $max_images );

		if ( $rewritten !== $content ) {
			$data['post_content'] = wp_slash( $rewritten );
		}

		return $data;
	}

	/* ---------------------------------------------------------------------
	 * Bulk fix (REST-driven batches, no ajax)
	 * ------------------------------------------------------------------- */

	/**
	 * Process one batch of posts starting at the given offset.
	 *
	 * @param int $offset Query offset.
	 * @return array Progress payload.
	 */
	public function process_bulk_batch( int $offset ): array {
		$batch_size = 5; // Small batch - sideloading is slow.

		$post_types = get_post_types( array( 'public' => true ), 'names' );
		unset( $post_types['attachment'] );

		$query = new \WP_Query(
			array(
				'post_type'      => array_values( $post_types ),
				'post_status'    => array( 'publish', 'draft', 'private', 'future', 'pending' ),
				'posts_per_page' => $batch_size,
				'offset'         => $offset,
				'orderby'        => 'ID',
				'order'          => 'ASC',
				'fields'         => 'ids',
				'no_found_rows'  => false,
			)
		);

		$processor    = $this->processor();
		$updated      = 0;
		$updated_ids  = array();

		foreach ( $query->posts as $post_id ) {
			$post = get_post( $post_id );
			if ( ! $post || false === stripos( $post->post_content, '<img' ) ) {
				continue;
			}

			$new_content = $processor->process_content( $post->post_content, (int) $post_id, 20 );
			if ( $new_content !== $post->post_content ) {
				// Avoid recursing into our own save filter.
				remove_filter( 'wp_insert_post_data', array( $this, 'filter_post_on_save' ), 20 );
				wp_update_post(
					array(
						'ID'           => $post_id,
						'post_content' => wp_slash( $new_content ),
					)
				);
				add_filter( 'wp_insert_post_data', array( $this, 'filter_post_on_save' ), 20, 2 );
				++$updated;
				$updated_ids[] = (int) $post_id;
			}
		}

		$total = (int) $query->found_posts;
		$done  = $offset + count( $query->posts );

		return array(
			'processed'   => count( $query->posts ),
			'updated'     => $updated,
			'updated_ids' => $updated_ids,
			'total'       => $total,
			'done'        => $done,
			'next_offset' => $done,
			'is_complete' => $done >= $total || count( $query->posts ) < $batch_size,
		);
	}

	/* ---------------------------------------------------------------------
	 * Processor accessor
	 * ------------------------------------------------------------------- */

	/**
	 * Shared processor instance (configured from settings).
	 */
	public function processor(): Processor {
		if ( null === $this->processor ) {
			$max_bytes       = (int) $this->settings->get( 'max_file_size_mb', 10 ) * 1024 * 1024;
			$this->processor = new Processor( $max_bytes );
		}
		return $this->processor;
	}

	/* ---------------------------------------------------------------------
	 * Settings
	 * ------------------------------------------------------------------- */

	/**
	 * Settings schema for the generic renderer.
	 */
	public function settings_schema(): array {
		return array(
			array(
				'key'     => 'max_file_size_mb',
				'type'    => 'number',
				'label'   => __( 'Maximum image size (MB)', 'ux-studio' ),
				'help'    => __( 'Images larger than this will not be downloaded.', 'ux-studio' ),
				'default' => 10,
			),
			array(
				'key'     => 'show_notices',
				'type'    => 'toggle',
				'label'   => __( 'Show editor notices', 'ux-studio' ),
				'help'    => __( 'Show a message in the editor when pasted external images are downloaded.', 'ux-studio' ),
				'default' => true,
			),
			array(
				'key'     => 'server_filter',
				'type'    => 'toggle',
				'label'   => __( 'Server-side safety net on save', 'ux-studio' ),
				'help'    => __( 'On save, automatically replace external images (works with Elementor, ACF, direct edits, etc.). Recommended.', 'ux-studio' ),
				'default' => true,
			),
			array(
				'key'     => 'max_images_per_save',
				'type'    => 'number',
				'label'   => __( 'Maximum images per save', 'ux-studio' ),
				'help'    => __( 'How many images to download at most during a single post save. Prevents slow saves on bulk imports.', 'ux-studio' ),
				'default' => 10,
			),
		);
	}
}
