<?php
/**
 * Media Replace module (ported from the legacy free + pro modules, merged).
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\MediaReplace;

use UxStudio\Modules\BaseModule;

defined( 'ABSPATH' ) || exit;

/**
 * Adds a "Media Replace" action to media items and a meta box that swaps an
 * attachment's underlying file (same URL) through a REST endpoint, with
 * admin-side cache-busting so the new file shows immediately.
 */
final class Module extends BaseModule {

	/**
	 * Register hooks.
	 */
	public function boot(): void {
		add_filter( 'media_row_actions', array( $this, 'add_replace_action' ), 10, 2 );
		add_action( 'add_meta_boxes_attachment', array( $this, 'add_replace_meta_box' ), 10, 1 );
		add_filter( 'attachment_fields_to_edit', array( $this, 'add_attachment_field' ), 10, 2 );

		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );

		// Cache-busting so the replaced file is not served from browser cache.
		add_filter( 'wp_calculate_image_srcset', array( $this, 'cache_bust_srcset' ) );
		add_filter( 'wp_get_attachment_image_src', array( $this, 'cache_bust_src' ) );
		add_filter( 'wp_prepare_attachment_for_js', array( $this, 'cache_bust_js' ) );
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
		return 'upload_files';
	}

	/**
	 * Add a "Media Replace" row action.
	 *
	 * @param array    $actions Row actions.
	 * @param \WP_Post $post    Attachment.
	 * @return array
	 */
	public function add_replace_action( $actions, $post ): array {
		if ( ! current_user_can( 'edit_post', $post->ID ) ) {
			return (array) $actions;
		}

		$url = add_query_arg( 'uxstudio_replace', 'load', get_edit_post_link( $post->ID ) );

		$actions['uxstudio_replace'] = sprintf(
			'<a href="%s">%s</a>',
			esc_url( $url ),
			esc_html__( 'Media Replace', 'ux-studio' )
		);

		return $actions;
	}

	/**
	 * Add the replace meta box to the attachment edit screen.
	 *
	 * @param \WP_Post $post Attachment.
	 */
	public function add_replace_meta_box( $post ): void {
		if ( ! current_user_can( 'edit_post', $post->ID ) ) {
			return;
		}

		add_meta_box(
			'uxstudio-media-replace',
			__( 'Media Replace', 'ux-studio' ),
			array( $this, 'render_meta_box' ),
			'attachment',
			'side',
			'core'
		);
	}

	/**
	 * Render the meta box content.
	 *
	 * @param \WP_Post $post Attachment.
	 */
	public function render_meta_box( $post ): void {
		printf(
			'<p>%s</p><button type="button" class="button button-secondary button-large uxstudio-media-replace-trigger" data-attachment-id="%s">%s</button>',
			esc_html__( 'Replace this media item with a new file by clicking the button below.', 'ux-studio' ),
			esc_attr( (string) $post->ID ),
			esc_html__( 'Upload New File', 'ux-studio' )
		);
	}

	/**
	 * Add a "Replace Media" button to the attachment details fields.
	 *
	 * @param array    $form_fields Form fields.
	 * @param \WP_Post $post        Attachment.
	 * @return array
	 */
	public function add_attachment_field( $form_fields, $post ): array {
		if ( ! current_user_can( 'edit_post', $post->ID ) ) {
			return (array) $form_fields;
		}

		$form_fields['uxstudio_replace_media'] = array(
			'label' => __( 'Replace Media', 'ux-studio' ),
			'input' => 'html',
			'html'  => sprintf(
				'<button type="button" class="button button-secondary uxstudio-media-replace-trigger" data-attachment-id="%s">%s</button>',
				esc_attr( (string) $post->ID ),
				esc_html__( 'Upload New File', 'ux-studio' )
			),
			'helps' => __( 'Replace this media item with a new file.', 'ux-studio' ),
		);

		return $form_fields;
	}

	/**
	 * Enqueue the media modal script on media screens.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function enqueue_assets( $hook ): void {
		if ( ! in_array( $hook, array( 'upload.php', 'post.php', 'post-new.php', 'media-upload.php' ), true ) ) {
			return;
		}

		wp_enqueue_media();

		$handle = 'uxstudio-media-replace';
		wp_enqueue_script(
			$handle,
			UXSTUDIO_URL . 'includes/Modules/MediaReplace/assets/media-replace.js',
			array( 'jquery', 'media-editor', 'media-views' ),
			defined( 'UXSTUDIO_VERSION' ) ? UXSTUDIO_VERSION : false,
			true
		);

		wp_localize_script(
			$handle,
			'uxStudioMediaReplace',
			array(
				'restUrl'  => rest_url( 'uxstudio/v1/media-replace' ),
				'nonce'    => wp_create_nonce( 'wp_rest' ),
				'autoOpen' => isset( $_GET['uxstudio_replace'] ) && 'load' === $_GET['uxstudio_replace'], // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				'i18n'     => array(
					'title'         => __( 'Select a replacement file', 'ux-studio' ),
					'button'        => __( 'Replace File', 'ux-studio' ),
					'error'         => __( 'An error occurred. Please try again.', 'ux-studio' ),
					'typeMismatch'  => __( 'The new file must be the same type as the original.', 'ux-studio' ),
				),
			)
		);
	}

	/**
	 * Whether cache-busting should run (admin, during a replace flow).
	 */
	private function is_replace_active(): bool {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return is_admin() && isset( $_GET['uxstudio_replace'] ) && in_array( $_GET['uxstudio_replace'], array( 'load', 'updated' ), true );
	}

	/**
	 * Cache-bust image srcset URLs in the admin during a replace flow.
	 *
	 * @param array $sources Srcset sources.
	 * @return array
	 */
	public function cache_bust_srcset( $sources ) {
		if ( ! $this->is_replace_active() || ! is_array( $sources ) ) {
			return $sources;
		}
		foreach ( $sources as $size => $source ) {
			$sources[ $size ]['url'] = $this->append_bust( $source['url'] );
		}
		return $sources;
	}

	/**
	 * Cache-bust a single image src in the admin during a replace flow.
	 *
	 * @param array|false $attr Image attributes ([url, w, h, ...]).
	 * @return array|false
	 */
	public function cache_bust_src( $attr ) {
		if ( ! $this->is_replace_active() || empty( $attr[0] ) ) {
			return $attr;
		}
		$attr[0] = $this->append_bust( $attr[0] );
		return $attr;
	}

	/**
	 * Cache-bust attachment URLs prepared for JS in the admin during a replace flow.
	 *
	 * @param array $response Attachment data for JS.
	 * @return array
	 */
	public function cache_bust_js( $response ) {
		if ( ! $this->is_replace_active() || ! is_array( $response ) ) {
			return $response;
		}
		if ( ! empty( $response['url'] ) ) {
			$response['url'] = $this->append_bust( $response['url'] );
		}
		if ( ! empty( $response['sizes'] ) && is_array( $response['sizes'] ) ) {
			foreach ( $response['sizes'] as $name => $size ) {
				if ( ! empty( $size['url'] ) ) {
					$response['sizes'][ $name ]['url'] = $this->append_bust( $size['url'] );
				}
			}
		}
		return $response;
	}

	/**
	 * Append a time-based cache-busting query arg to a URL.
	 *
	 * @param string $url URL.
	 */
	private function append_bust( string $url ): string {
		return $url . ( false === strpos( $url, '?' ) ? '?' : '&' ) . '_t=' . time();
	}
}
