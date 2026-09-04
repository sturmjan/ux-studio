<?php
/**
 * Elementor Import REST controller.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\ElementorImport;

use UxStudio\Rest\Controller;
use WP_REST_Request;

defined( 'ABSPATH' ) || exit;

/**
 * Routes under uxstudio/v1 (all require manage_options via the base controller):
 *
 *   POST /elementor-import              JSON/ZIP file upload  -> new draft.
 *   POST /elementor-import/url          Fetch + convert a remote URL.
 *   POST /elementor-import/html         Convert pasted HTML.
 *   POST /elementor-import/json         Import pasted template JSON.
 *   GET  /elementor-import/export/<id>  Export a page's Elementor content.
 *
 * Every write/export path first checks that Elementor is active and returns
 * 424 Failed Dependency with an explanation when it is not.
 */
final class RestController extends Controller {

	private Module $module;

	/**
	 * @param Module $module Owning module instance.
	 */
	public function __construct( Module $module ) {
		$this->module = $module;
	}

	/**
	 * Register routes.
	 */
	public function register_routes(): void {
		$this->route( '/elementor-import', 'POST', array( $this, 'import_file' ) );
		$this->route( '/elementor-import/url', 'POST', array( $this, 'import_url' ) );
		$this->route( '/elementor-import/html', 'POST', array( $this, 'import_html' ) );
		$this->route( '/elementor-import/json', 'POST', array( $this, 'import_json' ) );
		$this->route(
			'/elementor-import/export/(?P<post_id>\d+)',
			'GET',
			array( $this, 'export' ),
			array(
				'post_id' => array(
					'validate_callback' => static fn ( $param ): bool => is_numeric( $param ),
				),
			)
		);
	}

	// ---------------------------------------------------------------------
	// Handlers.
	// ---------------------------------------------------------------------

	/**
	 * Import a JSON/ZIP upload as a new draft.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function import_file( WP_REST_Request $request ) {
		$guard = $this->require_elementor();
		if ( null !== $guard ) {
			return $guard;
		}

		$files = $request->get_file_params();
		if ( empty( $files['file'] ) ) {
			return new \WP_Error(
				'uxstudio_no_file',
				__( 'Upload a .json or .zip template file in the "file" field.', 'ux-studio' ),
				array( 'status' => 400 )
			);
		}

		$template = $this->module->read_upload( (array) $files['file'] );
		if ( is_wp_error( $template ) ) {
			return $template;
		}

		$result = $this->module->import_template( $template );
		return is_wp_error( $result ) ? $result : $this->ok( $result );
	}

	/**
	 * Import from a remote URL.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function import_url( WP_REST_Request $request ) {
		$guard = $this->require_elementor();
		if ( null !== $guard ) {
			return $guard;
		}

		$url = esc_url_raw( (string) $request->get_param( 'url' ) );
		if ( '' === $url || ! filter_var( $url, FILTER_VALIDATE_URL ) ) {
			return new \WP_Error( 'uxstudio_bad_url', __( 'A valid http(s) URL is required.', 'ux-studio' ), array( 'status' => 400 ) );
		}
		if ( ! $this->module->is_safe_import_url( $url ) ) {
			return new \WP_Error( 'uxstudio_unsafe_url', __( 'The URL points to a disallowed (internal/private) address.', 'ux-studio' ), array( 'status' => 400 ) );
		}

		$mode    = $this->sanitize_mode( (string) $request->get_param( 'mode' ) );
		$post_id = (int) $request->get_param( 'post_id' );

		if ( 'new_page' !== $mode ) {
			$target = $this->check_target( $post_id );
			if ( null !== $target ) {
				return $target;
			}
		}

		$options  = array();
		$selector = (string) $request->get_param( 'selector' );
		if ( '' !== $selector ) {
			$options['selector'] = sanitize_text_field( $selector );
		}

		$result = $this->module->import_from_url( $url, $options, $mode, $post_id );
		return is_wp_error( $result ) ? $result : $this->ok( $result );
	}

	/**
	 * Import from pasted HTML.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function import_html( WP_REST_Request $request ) {
		$guard = $this->require_elementor();
		if ( null !== $guard ) {
			return $guard;
		}

		$html = (string) $request->get_param( 'html' );
		if ( '' === trim( $html ) ) {
			return new \WP_Error( 'uxstudio_empty_html', __( 'Paste some HTML to import.', 'ux-studio' ), array( 'status' => 400 ) );
		}
		if ( strlen( $html ) > 2 * 1024 * 1024 ) {
			return new \WP_Error( 'uxstudio_html_too_large', __( 'The HTML is too large (max 2 MB).', 'ux-studio' ), array( 'status' => 413 ) );
		}

		$mode    = $this->sanitize_mode( (string) $request->get_param( 'mode' ) );
		$post_id = (int) $request->get_param( 'post_id' );
		$title   = sanitize_text_field( (string) $request->get_param( 'title' ) );

		if ( 'new_page' !== $mode ) {
			$target = $this->check_target( $post_id );
			if ( null !== $target ) {
				return $target;
			}
		}

		$result = $this->module->import_from_html( $html, $title, $mode, $post_id );
		return is_wp_error( $result ) ? $result : $this->ok( $result );
	}

	/**
	 * Import pasted template JSON (supports new_page/replace/append).
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function import_json( WP_REST_Request $request ) {
		$guard = $this->require_elementor();
		if ( null !== $guard ) {
			return $guard;
		}

		$params = $request->get_json_params();
		if ( ! is_array( $params ) ) {
			$params = array();
		}

		// Content may arrive bare or wrapped in an { import_data: {...} } envelope.
		$import = ( isset( $params['import_data'] ) && is_array( $params['import_data'] ) ) ? $params['import_data'] : $params;

		if ( empty( $import['content'] ) || ! is_array( $import['content'] ) ) {
			return new \WP_Error(
				'uxstudio_invalid_template',
				__( 'Invalid JSON. The "content" array with Elementor data is missing.', 'ux-studio' ),
				array( 'status' => 400 )
			);
		}

		$mode    = $this->sanitize_mode( (string) ( $params['mode'] ?? '' ) );
		$post_id = (int) ( $params['post_id'] ?? 0 );
		$title   = sanitize_text_field( (string) ( $import['title'] ?? '' ) );

		if ( 'new_page' !== $mode ) {
			$target = $this->check_target( $post_id );
			if ( null !== $target ) {
				return $target;
			}
		}

		$result = $this->module->store_elementor(
			$import['content'],
			$mode,
			$post_id,
			array(
				'title'         => '' !== $title ? $title : __( 'Imported template', 'ux-studio' ),
				'post_type'     => (string) ( $import['post_type'] ?? 'page' ),
				'page_settings' => is_array( $import['page_settings'] ?? null ) ? $import['page_settings'] : array(),
				'source'        => 'json',
			)
		);

		return is_wp_error( $result ) ? $result : $this->ok( $result );
	}

	/**
	 * Export a page's Elementor content as JSON.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function export( WP_REST_Request $request ) {
		$guard = $this->require_elementor();
		if ( null !== $guard ) {
			return $guard;
		}

		$post_id = (int) $request->get_param( 'post_id' );
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return new \WP_Error( 'uxstudio_forbidden', __( 'You are not allowed to export this page.', 'ux-studio' ), array( 'status' => 403 ) );
		}

		$result = $this->module->export_page( $post_id );
		return is_wp_error( $result ) ? $result : $this->ok( $result );
	}

	// ---------------------------------------------------------------------
	// Shared guards.
	// ---------------------------------------------------------------------

	/**
	 * Return a 424 error when Elementor is not active, else null.
	 *
	 * @return \WP_Error|null
	 */
	private function require_elementor() {
		if ( ! $this->module->is_elementor_active() ) {
			return new \WP_Error(
				'uxstudio_elementor_inactive',
				__( 'Elementor must be installed and active to import or export templates.', 'ux-studio' ),
				array( 'status' => 424 )
			);
		}
		return null;
	}

	/**
	 * Validate a replace/append target post: exists and is editable. Guards
	 * against IDOR even though the route already requires manage_options.
	 *
	 * @param int $post_id Target post id.
	 * @return \WP_Error|null
	 */
	private function check_target( int $post_id ) {
		if ( $post_id <= 0 ) {
			return new \WP_Error( 'uxstudio_missing_post', __( 'A target page is required for replace/append.', 'ux-studio' ), array( 'status' => 400 ) );
		}
		if ( ! get_post( $post_id ) ) {
			return new \WP_Error( 'uxstudio_no_post', __( 'The target page was not found.', 'ux-studio' ), array( 'status' => 404 ) );
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return new \WP_Error( 'uxstudio_forbidden', __( 'You are not allowed to edit this page.', 'ux-studio' ), array( 'status' => 403 ) );
		}
		return null;
	}

	/**
	 * Whitelist the import mode.
	 *
	 * @param string $mode Raw mode.
	 */
	private function sanitize_mode( string $mode ): string {
		return in_array( $mode, array( 'new_page', 'replace', 'append' ), true ) ? $mode : 'new_page';
	}
}
