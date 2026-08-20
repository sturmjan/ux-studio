<?php
/**
 * AI Markdown REST controller.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\AiMarkdown;

use UxStudio\Rest\Controller;
use WP_Error;
use WP_REST_Request;

defined( 'ABSPATH' ) || exit;

/**
 * GET  uxstudio/v1/ai-markdown/cache/{post_id} - cached markdown for one post
 * POST uxstudio/v1/ai-markdown/regenerate/{post_id}
 * POST uxstudio/v1/ai-markdown/regenerate-all
 * GET  uxstudio/v1/ai-markdown/stats
 * GET  uxstudio/v1/ai-markdown/list
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
		$this->route(
			'/ai-markdown/cache/(?P<post_id>\d+)',
			'GET',
			array( $this, 'cache' ),
			array( 'post_id' => array( 'required' => true, 'type' => 'integer' ) )
		);
		$this->route(
			'/ai-markdown/regenerate/(?P<post_id>\d+)',
			'POST',
			array( $this, 'regenerate' ),
			array( 'post_id' => array( 'required' => true, 'type' => 'integer' ) )
		);
		$this->route( '/ai-markdown/regenerate-all', 'POST', array( $this, 'regenerate_all' ) );
		$this->route( '/ai-markdown/stats', 'GET', array( $this, 'stats' ) );
		$this->route( '/ai-markdown/list', 'GET', array( $this, 'list_cache' ) );
	}

	/**
	 * Cached markdown for one post.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function cache( WP_REST_Request $request ): mixed {
		$result = $this->module->get_cached( (int) $request->get_param( 'post_id' ) );
		if ( null === $result ) {
			return new WP_Error( 'uxstudio_am_not_found', __( 'Post not found.', 'ux-studio' ), array( 'status' => 404 ) );
		}
		return $this->ok( $result );
	}

	/**
	 * Force-regenerate one post.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function regenerate( WP_REST_Request $request ): mixed {
		$result = $this->module->regenerate( (int) $request->get_param( 'post_id' ) );
		if ( null === $result ) {
			return new WP_Error( 'uxstudio_am_not_found', __( 'Post not found.', 'ux-studio' ), array( 'status' => 404 ) );
		}
		return $this->ok( $result );
	}

	/**
	 * Regenerate every eligible post.
	 */
	public function regenerate_all(): mixed {
		return $this->ok( array( 'regenerated' => $this->module->regenerate_all() ) );
	}

	/**
	 * Cache/log stats.
	 */
	public function stats(): mixed {
		return $this->ok( $this->module->get_stats() );
	}

	/**
	 * Recently cached posts.
	 */
	public function list_cache(): mixed {
		return $this->ok( $this->module->list_cache() );
	}
}
