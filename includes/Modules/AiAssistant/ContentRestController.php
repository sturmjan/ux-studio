<?php
/**
 * REST endpoints for the Content Creator tab: AI content/WooCommerce/SEO
 * generation, draft creation & publishing, and the HTML/URL -> Elementor
 * importer.
 *
 * Ported from the legacy ux1-wordpress-customizer AI Assistant module
 * (rest/BackendController.php - generate/createDraft/publish/generateWoo/
 * generateSeo/elementorImportHtml/elementorImportUrl methods only; the rest
 * of that file - internal chat, settings/usage/providers - belongs to
 * other waves and is intentionally not ported here).
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\AiAssistant;

use UxStudio\Rest\Controller;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

/**
 * All routes require manage_options (via Controller::route()):
 *   POST uxstudio/v1/ai-assistant/content/generate
 *   POST uxstudio/v1/ai-assistant/content/create-draft
 *   POST uxstudio/v1/ai-assistant/content/publish/{id}
 *   POST uxstudio/v1/ai-assistant/content/generate-woo
 *   POST uxstudio/v1/ai-assistant/content/generate-seo
 *   POST uxstudio/v1/ai-assistant/content/elementor-import-html
 *   POST uxstudio/v1/ai-assistant/content/elementor-import-url
 */
final class ContentRestController extends Controller {

	public function register_routes(): void {
		$this->route(
			'/ai-assistant/content/generate',
			'POST',
			array( $this, 'generate' ),
			array(
				'post_type'     => array( 'required' => false, 'type' => 'string' ),
				'tone'          => array( 'required' => false, 'type' => 'string' ),
				'length'        => array( 'required' => false, 'type' => 'string' ),
				'description'   => array( 'required' => true, 'type' => 'string' ),
				'focus_keyword' => array( 'required' => false, 'type' => 'string' ),
			)
		);

		$this->route(
			'/ai-assistant/content/create-draft',
			'POST',
			array( $this, 'create_draft' )
		);

		$this->route(
			'/ai-assistant/content/publish/(?P<id>\d+)',
			'POST',
			array( $this, 'publish' ),
			array(
				'id' => array( 'required' => true, 'type' => 'integer' ),
			)
		);

		$this->route(
			'/ai-assistant/content/generate-woo',
			'POST',
			array( $this, 'generate_woo' ),
			array(
				'tone'        => array( 'required' => false, 'type' => 'string' ),
				'length'      => array( 'required' => false, 'type' => 'string' ),
				'description' => array( 'required' => true, 'type' => 'string' ),
			)
		);

		$this->route(
			'/ai-assistant/content/generate-seo',
			'POST',
			array( $this, 'generate_seo' ),
			array(
				'content' => array( 'required' => true, 'type' => 'string' ),
				'post_id' => array( 'required' => false, 'type' => 'integer' ),
			)
		);

		$this->route(
			'/ai-assistant/content/elementor-import-html',
			'POST',
			array( $this, 'elementor_import_html' ),
			array(
				'html_content' => array( 'required' => true, 'type' => 'string' ),
				'post_id'      => array( 'required' => false, 'type' => 'integer' ),
				'title'        => array( 'required' => false, 'type' => 'string' ),
				'status'       => array( 'required' => false, 'type' => 'string' ),
				'post_type'    => array( 'required' => false, 'type' => 'string' ),
			)
		);

		$this->route(
			'/ai-assistant/content/elementor-import-url',
			'POST',
			array( $this, 'elementor_import_url' ),
			array(
				'url'       => array( 'required' => true, 'type' => 'string' ),
				'selector'  => array( 'required' => false, 'type' => 'string' ),
				'post_id'   => array( 'required' => false, 'type' => 'integer' ),
				'title'     => array( 'required' => false, 'type' => 'string' ),
				'status'    => array( 'required' => false, 'type' => 'string' ),
				'post_type' => array( 'required' => false, 'type' => 'string' ),
			)
		);
	}

	// ─── Content generation ──────────────────────────────────────────

	public function generate( WP_REST_Request $request ) {
		$limit_error = UsageLimiter::check();
		if ( null !== $limit_error ) {
			return new WP_Error( 'uxstudio_usage_limited', $limit_error, array( 'status' => 429 ) );
		}

		try {
			$generator = new ContentGenerator();
			$result    = $generator->generate(
				array(
					'post_type'     => $request->get_param( 'post_type' ),
					'tone'          => $request->get_param( 'tone' ),
					'length'        => $request->get_param( 'length' ),
					'description'   => $request->get_param( 'description' ),
					'focus_keyword' => $request->get_param( 'focus_keyword' ),
				)
			);

			return $this->ok( $result );
		} catch ( \Throwable $e ) {
			return new WP_Error( 'uxstudio_generate_failed', $e->getMessage(), array( 'status' => 400 ) );
		}
	}

	public function create_draft( WP_REST_Request $request ) {
		try {
			$generated = (array) $request->get_json_params();
			$post_type = sanitize_text_field( (string) ( $generated['post_type'] ?? 'post' ) );

			$post_id = ContentHelper::create_draft( $generated, $post_type );

			return $this->ok(
				array(
					'post_id'  => $post_id,
					'edit_url' => get_edit_post_link( $post_id, 'raw' ),
				)
			);
		} catch ( \Throwable $e ) {
			return new WP_Error( 'uxstudio_draft_failed', $e->getMessage(), array( 'status' => 400 ) );
		}
	}

	public function publish( WP_REST_Request $request ) {
		$post_id = absint( $request->get_param( 'id' ) );
		$success = ContentHelper::publish_post( $post_id );

		if ( ! $success ) {
			return new WP_Error( 'uxstudio_publish_failed', __( 'Publishing failed.', 'ux-studio' ), array( 'status' => 400 ) );
		}

		return $this->ok(
			array(
				'post_id' => $post_id,
				'url'     => get_permalink( $post_id ),
			)
		);
	}

	public function generate_woo( WP_REST_Request $request ) {
		$limit_error = UsageLimiter::check();
		if ( null !== $limit_error ) {
			return new WP_Error( 'uxstudio_usage_limited', $limit_error, array( 'status' => 429 ) );
		}

		try {
			$generator = new ContentGenerator();
			$result    = $generator->generate_woo_product(
				array(
					'tone'        => $request->get_param( 'tone' ),
					'length'      => $request->get_param( 'length' ),
					'description' => $request->get_param( 'description' ),
				)
			);

			return $this->ok( $result );
		} catch ( \Throwable $e ) {
			return new WP_Error( 'uxstudio_generate_woo_failed', $e->getMessage(), array( 'status' => 400 ) );
		}
	}

	public function generate_seo( WP_REST_Request $request ) {
		$limit_error = UsageLimiter::check();
		if ( null !== $limit_error ) {
			return new WP_Error( 'uxstudio_usage_limited', $limit_error, array( 'status' => 429 ) );
		}

		$content = (string) $request->get_param( 'content' );
		if ( '' === trim( $content ) ) {
			return new WP_Error( 'uxstudio_empty_content', __( 'The content to analyse for SEO is empty.', 'ux-studio' ), array( 'status' => 400 ) );
		}

		try {
			$generator = new ContentGenerator();
			$result    = $generator->generate_seo_meta( $content );

			$post_id = absint( $request->get_param( 'post_id' ) );
			if ( $post_id > 0 ) {
				SeoManager::save_meta( $post_id, $result );
				$result['seo_plugin_detected'] = SeoManager::detect_seo_plugin();
			}

			return $this->ok( $result );
		} catch ( \Throwable $e ) {
			return new WP_Error( 'uxstudio_generate_seo_failed', $e->getMessage(), array( 'status' => 400 ) );
		}
	}

	// ─── Elementor import ────────────────────────────────────────────

	public function elementor_import_html( WP_REST_Request $request ) {
		if ( ! defined( 'ELEMENTOR_VERSION' ) ) {
			return new WP_Error( 'uxstudio_elementor_inactive', __( 'Elementor is not active.', 'ux-studio' ), array( 'status' => 400 ) );
		}

		$html_content = (string) $request->get_param( 'html_content' );
		if ( '' === trim( $html_content ) ) {
			return new WP_Error( 'uxstudio_missing_html', __( 'The html_content parameter is required.', 'ux-studio' ), array( 'status' => 400 ) );
		}

		$elements = HtmlToElementor::convert( $html_content );
		if ( empty( $elements ) ) {
			return new WP_Error( 'uxstudio_conversion_failed', __( 'Could not convert the HTML into Elementor widgets.', 'ux-studio' ), array( 'status' => 400 ) );
		}

		return $this->save_elementor_import(
			$elements,
			$request,
			sanitize_text_field( (string) ( $request->get_param( 'title' ) ?: __( 'Elementor page', 'ux-studio' ) ) )
		);
	}

	public function elementor_import_url( WP_REST_Request $request ) {
		if ( ! defined( 'ELEMENTOR_VERSION' ) ) {
			return new WP_Error( 'uxstudio_elementor_inactive', __( 'Elementor is not active.', 'ux-studio' ), array( 'status' => 400 ) );
		}

		$url = (string) $request->get_param( 'url' );

		// SSRF guard: reject anything that isn't a valid, publicly-routable
		// http(s) URL (blocks localhost/private/reserved IP ranges).
		$safe_url = wp_http_validate_url( $url );
		if ( false === $safe_url ) {
			return new WP_Error( 'uxstudio_invalid_url', __( 'The url parameter must be a valid, publicly reachable URL.', 'ux-studio' ), array( 'status' => 400 ) );
		}

		$options = array();
		$selector = (string) $request->get_param( 'selector' );
		if ( '' !== $selector ) {
			$options['selector'] = sanitize_text_field( $selector );
		}

		try {
			$result = HtmlToElementor::convert_from_url( $safe_url, $options );
		} catch ( \Throwable $e ) {
			return new WP_Error( 'uxstudio_url_import_failed', $e->getMessage(), array( 'status' => 400 ) );
		}

		if ( empty( $result['elements'] ) ) {
			return new WP_Error( 'uxstudio_extract_failed', __( 'Could not extract content from the page.', 'ux-studio' ), array( 'status' => 400 ) );
		}

		$title = sanitize_text_field( (string) ( $request->get_param( 'title' ) ?: $result['title'] ?: __( 'Import from URL', 'ux-studio' ) ) );

		return $this->save_elementor_import(
			$result['elements'],
			$request,
			$title,
			array(
				'source_url'        => $safe_url,
				'meta_description' => (string) ( $result['meta_description'] ?? '' ),
			)
		);
	}

	/**
	 * Shared "write Elementor data to a post" step for both import endpoints.
	 *
	 * @param array<int, array<string, mixed>> $elements
	 * @param array{source_url?:string,meta_description?:string}    $extra
	 */
	private function save_elementor_import( array $elements, WP_REST_Request $request, string $title, array $extra = array() ): WP_REST_Response {
		$status    = in_array( $request->get_param( 'status' ), array( 'draft', 'publish', 'pending' ), true ) ? $request->get_param( 'status' ) : 'draft';
		$post_type = in_array( $request->get_param( 'post_type' ), array( 'page', 'post' ), true ) ? $request->get_param( 'post_type' ) : 'page';
		$post_id   = absint( $request->get_param( 'post_id' ) );

		if ( $post_id > 0 ) {
			$post = get_post( $post_id );
			if ( ! $post ) {
				return new WP_Error( 'uxstudio_post_not_found', __( 'Page not found.', 'ux-studio' ), array( 'status' => 404 ) );
			}
		} else {
			$post_id = wp_insert_post(
				array(
					'post_title'   => $title,
					'post_content' => '',
					'post_type'    => $post_type,
					'post_status'  => $status,
					'post_author'  => get_current_user_id(),
				),
				true
			);

			if ( is_wp_error( $post_id ) ) {
				return new WP_Error( 'uxstudio_post_create_failed', $post_id->get_error_message(), array( 'status' => 500 ) );
			}
		}

		update_post_meta( $post_id, '_elementor_edit_mode', 'builder' );
		if ( ! get_post_meta( $post_id, '_elementor_template_type', true ) ) {
			$type = ( 'page' === $post_type || 'page' === get_post_type( $post_id ) ) ? 'wp-page' : 'wp-post';
			update_post_meta( $post_id, '_elementor_template_type', $type );
		}

		$old_data = get_post_meta( $post_id, '_elementor_data', true );
		if ( $old_data ) {
			update_post_meta( $post_id, '_elementor_data_backup', $old_data );
		}

		$json = wp_json_encode( $elements );
		update_post_meta( $post_id, '_elementor_data', wp_slash( (string) $json ) );
		delete_post_meta( $post_id, '_elementor_css' );
		delete_post_meta( $post_id, '_elementor_element_cache' );

		if ( class_exists( '\\Elementor\\Plugin' ) && \Elementor\Plugin::$instance && isset( \Elementor\Plugin::$instance->files_manager ) ) {
			\Elementor\Plugin::$instance->files_manager->clear_cache();
		}

		$response = array(
			'post_id'         => $post_id,
			'title'           => get_the_title( $post_id ),
			'widgets_created' => HtmlToElementor::count_widgets( $elements ),
			'edit_url'        => admin_url( "post.php?post={$post_id}&action=elementor" ),
			'url'             => get_permalink( $post_id ),
		);

		return $this->ok( array_merge( $response, $extra ) );
	}
}
