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
 * GET  uxstudio/v1/ai-markdown/log?search=&bot=&type=&limit=&offset= - bot-access log {items,total}
 * DELETE uxstudio/v1/ai-markdown/log {ids:[]}
 * GET  uxstudio/v1/ai-markdown/log/stats - total, today, per-bot counts
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
		$this->route(
			'/ai-markdown/log',
			'GET',
			array( $this, 'log_list' ),
			array(
				'search' => array( 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ),
				'bot'    => array( 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ),
				'type'   => array( 'type' => 'string', 'sanitize_callback' => 'sanitize_key' ),
				'limit'  => array( 'type' => 'integer', 'default' => 30, 'minimum' => 1, 'maximum' => 200 ),
				'offset' => array( 'type' => 'integer', 'default' => 0, 'minimum' => 0 ),
			)
		);
		$this->route(
			'/ai-markdown/log',
			'DELETE',
			array( $this, 'log_delete' ),
			array(
				'ids' => array(
					'required' => true,
					'type'     => 'array',
					'items'    => array( 'type' => 'integer' ),
				),
			)
		);
		$this->route( '/ai-markdown/log/stats', 'GET', array( $this, 'log_stats' ) );
	}

	/**
	 * Bot-access log entries (search/bot/type filters, paginated).
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function log_list( WP_REST_Request $request ): mixed {
		$result = AccessLog::list(
			array(
				'search' => (string) $request->get_param( 'search' ),
				'bot'    => (string) $request->get_param( 'bot' ),
				'type'   => (string) $request->get_param( 'type' ),
				'limit'  => (int) $request->get_param( 'limit' ),
				'offset' => (int) $request->get_param( 'offset' ),
			)
		);
		return $this->ok( $result );
	}

	/**
	 * Delete log entries by id.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function log_delete( WP_REST_Request $request ): mixed {
		return $this->ok( array( 'deleted' => AccessLog::delete( (array) $request->get_param( 'ids' ) ) ) );
	}

	/**
	 * Total/today counts and per-bot counts.
	 */
	public function log_stats(): mixed {
		return $this->ok( AccessLog::stats() );
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
