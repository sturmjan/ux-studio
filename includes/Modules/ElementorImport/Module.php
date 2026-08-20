<?php
/**
 * Elementor Import module.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\ElementorImport;

use UxStudio\Modules\BaseModule;

defined( 'ABSPATH' ) || exit;

/**
 * Imports an Elementor template from a JSON or ZIP upload as a new draft page.
 * Ported from the legacy module (free + pro merged). The module always boots;
 * when Elementor is not active the REST endpoint replies 424 Failed Dependency
 * instead of silently disabling itself.
 */
final class Module extends BaseModule {

	/**
	 * Register hooks.
	 */
	public function boot(): void {
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
	 * Whether Elementor is active.
	 */
	public function is_elementor_active(): bool {
		return defined( 'ELEMENTOR_VERSION' );
	}

	/**
	 * Import a validated template payload as a new Elementor draft page.
	 *
	 * @param array $template Decoded template data (must contain a 'content' array).
	 * @return array|\WP_Error Result payload or error.
	 */
	public function import_template( array $template ) {
		if ( empty( $template['content'] ) || ! is_array( $template['content'] ) ) {
			return new \WP_Error(
				'uxstudio_invalid_template',
				__( 'Invalid template file. The "content" array with Elementor data is missing.', 'ux-studio' ),
				array( 'status' => 400 )
			);
		}

		$title     = sanitize_text_field( (string) ( $template['title'] ?? __( 'Imported template', 'ux-studio' ) ) );
		$post_type = in_array( $template['post_type'] ?? 'page', array( 'page', 'post' ), true ) ? $template['post_type'] : 'page';

		$post_id = wp_insert_post(
			array(
				'post_title'   => $title,
				'post_content' => '',
				'post_type'    => $post_type,
				'post_status'  => 'draft',
				'post_author'  => get_current_user_id(),
			),
			true
		);

		if ( is_wp_error( $post_id ) ) {
			return new \WP_Error( 'uxstudio_insert_failed', $post_id->get_error_message(), array( 'status' => 500 ) );
		}

		$data = $this->regenerate_element_ids( $template['content'] );

		update_post_meta( $post_id, '_elementor_edit_mode', 'builder' );
		update_post_meta( $post_id, '_elementor_template_type', 'wp-' . $post_type );
		update_post_meta( $post_id, '_elementor_data', wp_slash( (string) wp_json_encode( $data ) ) );

		if ( ! empty( $template['page_settings'] ) && is_array( $template['page_settings'] ) ) {
			update_post_meta( $post_id, '_elementor_page_settings', $template['page_settings'] );
		}

		delete_post_meta( $post_id, '_elementor_css' );
		delete_post_meta( $post_id, '_elementor_element_cache' );

		if ( class_exists( '\Elementor\Plugin' ) && \Elementor\Plugin::$instance && isset( \Elementor\Plugin::$instance->files_manager ) ) {
			\Elementor\Plugin::$instance->files_manager->clear_cache();
		}

		return array(
			'post_id'         => $post_id,
			'title'           => get_the_title( $post_id ),
			'widgets_created' => $this->count_widgets( $data ),
			'edit_url'        => admin_url( "post.php?post={$post_id}&action=elementor" ),
			'url'             => get_permalink( $post_id ),
		);
	}

	/**
	 * Read and decode a template from an uploaded .json or .zip file.
	 *
	 * @param array $file Entry from $request->get_file_params().
	 * @return array|\WP_Error Decoded template or error.
	 */
	public function read_upload( array $file ) {
		if ( empty( $file['tmp_name'] ) || ! is_uploaded_file( $file['tmp_name'] ) ) {
			return new \WP_Error( 'uxstudio_no_file', __( 'No file was uploaded.', 'ux-studio' ), array( 'status' => 400 ) );
		}

		$name = isset( $file['name'] ) ? sanitize_file_name( (string) $file['name'] ) : '';
		$ext  = strtolower( (string) pathinfo( $name, PATHINFO_EXTENSION ) );

		if ( 'json' === $ext ) {
			$raw = file_get_contents( $file['tmp_name'] );
			return $this->decode_json( (string) $raw );
		}

		if ( 'zip' === $ext ) {
			return $this->read_zip( $file['tmp_name'] );
		}

		return new \WP_Error(
			'uxstudio_invalid_file_type',
			__( 'Only .json and .zip files are allowed.', 'ux-studio' ),
			array( 'status' => 400 )
		);
	}

	/**
	 * Extract the first JSON template from a ZIP archive.
	 *
	 * @param string $path Absolute path to the uploaded ZIP.
	 * @return array|\WP_Error
	 */
	private function read_zip( string $path ) {
		if ( ! class_exists( '\ZipArchive' ) ) {
			return new \WP_Error( 'uxstudio_no_zip', __( 'ZIP support is not available on this server.', 'ux-studio' ), array( 'status' => 500 ) );
		}

		$zip = new \ZipArchive();
		if ( true !== $zip->open( $path ) ) {
			return new \WP_Error( 'uxstudio_bad_zip', __( 'The ZIP archive could not be opened.', 'ux-studio' ), array( 'status' => 400 ) );
		}

		$json = '';
		for ( $i = 0; $i < $zip->numFiles; $i++ ) {
			$entry = (string) $zip->getNameIndex( $i );
			if ( 'json' === strtolower( (string) pathinfo( $entry, PATHINFO_EXTENSION ) ) ) {
				$json = (string) $zip->getFromIndex( $i );
				break;
			}
		}
		$zip->close();

		if ( '' === $json ) {
			return new \WP_Error( 'uxstudio_zip_no_json', __( 'The ZIP archive does not contain a JSON template.', 'ux-studio' ), array( 'status' => 400 ) );
		}

		return $this->decode_json( $json );
	}

	/**
	 * Decode a raw JSON string into a template array.
	 *
	 * @param string $raw Raw JSON.
	 * @return array|\WP_Error
	 */
	private function decode_json( string $raw ) {
		$data = json_decode( $raw, true );
		if ( ! is_array( $data ) ) {
			return new \WP_Error( 'uxstudio_bad_json', __( 'The file does not contain valid JSON.', 'ux-studio' ), array( 'status' => 400 ) );
		}
		return $data;
	}

	/**
	 * Regenerate element IDs recursively to avoid collisions.
	 *
	 * @param array $elements Elementor elements.
	 * @return array
	 */
	private function regenerate_element_ids( array $elements ): array {
		foreach ( $elements as &$element ) {
			if ( ! is_array( $element ) ) {
				continue;
			}
			$element['id'] = dechex( random_int( 0x10000000, 0x7FFFFFFF ) );
			if ( ! empty( $element['elements'] ) && is_array( $element['elements'] ) ) {
				$element['elements'] = $this->regenerate_element_ids( $element['elements'] );
			}
		}
		unset( $element );
		return $elements;
	}

	/**
	 * Count widget elements recursively.
	 *
	 * @param array $elements Elementor elements.
	 */
	private function count_widgets( array $elements ): int {
		$count = 0;
		foreach ( $elements as $element ) {
			if ( ! is_array( $element ) ) {
				continue;
			}
			if ( 'widget' === ( $element['elType'] ?? '' ) ) {
				$count++;
			}
			if ( ! empty( $element['elements'] ) && is_array( $element['elements'] ) ) {
				$count += $this->count_widgets( $element['elements'] );
			}
		}
		return $count;
	}
}
