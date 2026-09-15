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
 *   POST uxstudio/v1/ai-assistant/content/generate-social
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
			'/ai-assistant/content/bulk-seo/status',
			'GET',
			array( $this, 'bulk_seo_status' ),
			array(
				'post_type' => array( 'required' => false, 'type' => 'string', 'default' => 'post' ),
			)
		);

		$this->route(
			'/ai-assistant/content/bulk-seo/run',
			'POST',
			array( $this, 'bulk_seo_run' ),
			array(
				'post_type' => array( 'required' => false, 'type' => 'string', 'default' => 'post' ),
				'limit'     => array( 'required' => false, 'type' => 'integer', 'default' => 5 ),
			)
		);

		$this->route(
			'/ai-assistant/content/tool',
			'POST',
			array( $this, 'tool' ),
			array(
				'tool'           => array( 'required' => true, 'type' => 'string' ),
				'topic'          => array( 'required' => false, 'type' => 'string' ),
				'source_content' => array( 'required' => false, 'type' => 'string' ),
				'tone'           => array( 'required' => false, 'type' => 'string' ),
			)
		);

		$this->route(
			'/ai-assistant/content/tools',
			'GET',
			array( $this, 'tools_catalog' )
		);

		$this->route(
			'/ai-assistant/content/bulk-alt/status',
			'GET',
			array( $this, 'bulk_alt_status' )
		);

		$this->route(
			'/ai-assistant/content/bulk-alt/run',
			'POST',
			array( $this, 'bulk_alt_run' ),
			array(
				'limit' => array( 'required' => false, 'type' => 'integer', 'default' => 5 ),
			)
		);

		$this->route(
			'/ai-assistant/content/generate-social',
			'POST',
			array( $this, 'generate_social' ),
			array(
				'content'   => array( 'required' => true, 'type' => 'string' ),
				'platforms' => array( 'required' => true, 'type' => 'array', 'items' => array( 'type' => 'string' ) ),
				'post_id'   => array( 'required' => false, 'type' => 'integer' ),
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

	/**
	 * Generic content tool endpoint - one route for the ~40 RankMath-Content-AI
	 * style tools registered in PromptLibrary, selected by the `tool` param.
	 */
	public function tool( WP_REST_Request $request ) {
		$limit_error = UsageLimiter::check();
		if ( null !== $limit_error ) {
			return new WP_Error( 'uxstudio_usage_limited', $limit_error, array( 'status' => 429 ) );
		}

		$tool_key = sanitize_key( (string) $request->get_param( 'tool' ) );

		try {
			$generator = new ContentGenerator();
			$result    = $generator->generate_from_prompt(
				$tool_key,
				array(
					'topic'          => (string) $request->get_param( 'topic' ),
					'source_content' => (string) $request->get_param( 'source_content' ),
				),
				array( 'tone' => (string) $request->get_param( 'tone' ) )
			);

			return $this->ok( $result );
		} catch ( \Throwable $e ) {
			return new WP_Error( 'uxstudio_tool_failed', $e->getMessage(), array( 'status' => 400 ) );
		}
	}

	/**
	 * Tool catalog (key/label/vars) for building the picker UI.
	 */
	public function tools_catalog( WP_REST_Request $request ) {
		$catalog = array();
		foreach ( PromptLibrary::all() as $key => $tool ) {
			$catalog[] = array(
				'key'      => $key,
				'label'    => $tool['label'],
				'vars'     => $tool['vars'],
				'required' => $tool['required'],
			);
		}
		return $this->ok( $catalog );
	}

	/**
	 * How many published posts of the given type still have no SEO title -
	 * "Rank Math bulk SEO generation", applied across everything already on
	 * this site instead of one post at a time.
	 */
	public function bulk_seo_status( WP_REST_Request $request ) {
		$post_type = sanitize_key( (string) $request->get_param( 'post_type' ) );
		if ( ! post_type_exists( $post_type ) ) {
			$post_type = 'post';
		}

		return $this->ok(
			array(
				'post_type'      => $post_type,
				'missing_count'  => SeoManager::count_missing_seo( $post_type ),
				'seo_plugin'     => SeoManager::detect_seo_plugin(),
			)
		);
	}

	/**
	 * Generates + saves SEO meta for up to `limit` posts still missing one.
	 * Bounded per request (default 5, max 20) so a large backlog is worked
	 * through in repeated clicks rather than one long-running request that
	 * risks a PHP timeout (the mistake AiMarkdown::regenerate_all() made).
	 */
	public function bulk_seo_run( WP_REST_Request $request ) {
		$post_type = sanitize_key( (string) $request->get_param( 'post_type' ) );
		if ( ! post_type_exists( $post_type ) ) {
			$post_type = 'post';
		}
		$limit = max( 1, min( 20, (int) $request->get_param( 'limit' ) ) );

		$post_ids  = SeoManager::find_missing_seo_post_ids( $post_type, $limit );
		$generator = new ContentGenerator();
		$processed = array();

		foreach ( $post_ids as $post_id ) {
			$limit_error = UsageLimiter::check();
			if ( null !== $limit_error ) {
				break;
			}

			$post = get_post( $post_id );
			if ( ! $post ) {
				continue;
			}

			try {
				$source = wp_strip_all_tags( $post->post_title . "\n\n" . $post->post_content );
				$result = $generator->generate_seo_meta( '' !== trim( $source ) ? $source : $post->post_title );
				SeoManager::save_meta( $post_id, $result );

				$processed[] = array(
					'post_id'         => $post_id,
					'title'           => $post->post_title,
					'seo_title'       => $result['seo_title'] ?? '',
					'seo_description' => $result['seo_description'] ?? '',
				);
			} catch ( \Throwable $e ) {
				$processed[] = array(
					'post_id' => $post_id,
					'title'   => $post->post_title,
					'error'   => $e->getMessage(),
				);
			}
		}

		return $this->ok(
			array(
				'processed'     => $processed,
				'remaining'     => SeoManager::count_missing_seo( $post_type ),
				'seo_plugin'    => SeoManager::detect_seo_plugin(),
			)
		);
	}

	/**
	 * How many image attachments still have no ALT text.
	 */
	public function bulk_alt_status( WP_REST_Request $request ) {
		return $this->ok(
			array(
				'missing_count' => (int) ( new \WP_Query( $this->missing_alt_query_args( 1, 1, true ) ) )->found_posts,
			)
		);
	}

	/**
	 * Generates + saves ALT text (from context, see ContentGenerator::generate_alt_text())
	 * for up to `limit` images still missing one. Same bounded-per-request
	 * shape as bulk_seo_run() to avoid a PHP timeout on a large media library.
	 */
	public function bulk_alt_run( WP_REST_Request $request ) {
		$limit = max( 1, min( 20, (int) $request->get_param( 'limit' ) ) );

		$query     = new \WP_Query( $this->missing_alt_query_args( $limit, 1, false ) );
		$generator = new ContentGenerator();
		$processed = array();

		foreach ( $query->posts as $attachment_id ) {
			$limit_error = UsageLimiter::check();
			if ( null !== $limit_error ) {
				break;
			}

			$attachment = get_post( $attachment_id );
			if ( ! $attachment ) {
				continue;
			}

			$post_title = '';
			if ( $attachment->post_parent > 0 ) {
				$parent = get_post( $attachment->post_parent );
				if ( $parent ) {
					$post_title = $parent->post_title;
				}
			}

			try {
				$result = $generator->generate_alt_text(
					array(
						'title'      => $attachment->post_title,
						'caption'    => $attachment->post_excerpt,
						'filename'   => basename( (string) get_attached_file( $attachment_id ) ),
						'post_title' => $post_title,
					)
				);
				$alt_text = sanitize_text_field( (string) ( $result['alt_text'] ?? '' ) );
				update_post_meta( $attachment_id, '_wp_attachment_image_alt', $alt_text );

				$processed[] = array(
					'attachment_id' => $attachment_id,
					'title'         => $attachment->post_title,
					'alt_text'      => $alt_text,
				);
			} catch ( \Throwable $e ) {
				$processed[] = array(
					'attachment_id' => $attachment_id,
					'title'         => $attachment->post_title,
					'error'         => $e->getMessage(),
				);
			}
		}

		return $this->ok(
			array(
				'processed' => $processed,
				'remaining' => (int) ( new \WP_Query( $this->missing_alt_query_args( 1, 1, true ) ) )->found_posts,
			)
		);
	}

	/**
	 * WP_Query args for image attachments with an empty/missing ALT text.
	 *
	 * @return array<string, mixed>
	 */
	private function missing_alt_query_args( int $per_page, int $page, bool $count_only ): array {
		$args = array(
			'post_type'      => 'attachment',
			'post_mime_type' => 'image',
			'post_status'    => 'inherit',
			'posts_per_page' => $per_page,
			'paged'          => $page,
			'orderby'        => 'date',
			'order'          => 'ASC',
			'fields'         => 'ids',
			'no_found_rows'  => ! $count_only,
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
			'meta_query'     => array(
				'relation' => 'OR',
				array( 'key' => '_wp_attachment_image_alt', 'compare' => 'NOT EXISTS' ),
				array( 'key' => '_wp_attachment_image_alt', 'value' => '', 'compare' => '=' ),
			),
		);
		return $args;
	}

	public function generate_social( WP_REST_Request $request ) {
		$limit_error = UsageLimiter::check();
		if ( null !== $limit_error ) {
			return new WP_Error( 'uxstudio_usage_limited', $limit_error, array( 'status' => 429 ) );
		}

		$content = (string) $request->get_param( 'content' );
		if ( '' === trim( $content ) ) {
			return new WP_Error( 'uxstudio_empty_content', __( 'The content to base captions on is empty.', 'ux-studio' ), array( 'status' => 400 ) );
		}

		try {
			$generator  = new ContentGenerator();
			$platforms  = array_map( 'sanitize_key', (array) $request->get_param( 'platforms' ) );
			$result     = $generator->generate_social_captions( $content, $platforms );

			return $this->ok( $result );
		} catch ( \Throwable $e ) {
			return new WP_Error( 'uxstudio_generate_social_failed', $e->getMessage(), array( 'status' => 400 ) );
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
