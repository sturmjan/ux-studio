<?php
/**
 * Admin-facing (nonce + capability) REST controller for the SPA.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\ContentSync;

use UxStudio\Rest\Controller;
use WP_REST_Request;

defined( 'ABSPATH' ) || exit;

/**
 * These routes are called by the logged-in admin SPA (cookie + X-WP-Nonce +
 * manage_options), NOT by remote sites. They drive the hub console: manage
 * node sites, browse local posts, push content, view the sync log. The
 * remote-facing endpoints are in NodeController / SsoController (HMAC only).
 *
 * GET    /content-sync/sites                 list nodes
 * POST   /content-sync/sites                 register a node (name, url, api_key)
 * DELETE /content-sync/sites/{id}            remove a node
 * POST   /content-sync/sites/{id}/test       ping a node
 * POST   /content-sync/sites/{id}/sso        issue an SSO login link on a node
 * GET    /content-sync/local-posts           local posts to choose from
 * POST   /content-sync/push                  push a post to selected nodes
 * GET    /content-sync/log                   recent sync log rows
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
		$this->route( '/content-sync/sites', 'GET', array( $this, 'list_sites' ) );
		$this->route(
			'/content-sync/sites',
			'POST',
			array( $this, 'create_site' ),
			array(
				'name'    => array(
					'required'          => true,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
				),
				'url'     => array(
					'required'          => true,
					'type'              => 'string',
					'sanitize_callback' => 'esc_url_raw',
				),
				'api_key' => array(
					'required'          => true,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
				),
			)
		);
		$this->route( '/content-sync/sites/(?P<id>\d+)', 'DELETE', array( $this, 'delete_site' ) );
		$this->route( '/content-sync/sites/(?P<id>\d+)/test', 'POST', array( $this, 'test_site' ) );
		$this->route( '/content-sync/sites/(?P<id>\d+)/sso', 'POST', array( $this, 'issue_sso' ) );
		$this->route( '/content-sync/local-posts', 'GET', array( $this, 'local_posts' ) );
		$this->route(
			'/content-sync/push',
			'POST',
			array( $this, 'push' ),
			array(
				'post_id'  => array(
					'required'          => true,
					'type'              => 'integer',
					'sanitize_callback' => 'absint',
				),
				'site_ids' => array(
					'required' => true,
					'type'     => 'array',
				),
			)
		);
		$this->route( '/content-sync/log', 'GET', array( $this, 'list_log' ) );
	}

	/**
	 * List registered node sites.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function list_sites( WP_REST_Request $request ) {
		return $this->ok( ( new SiteManager() )->all() );
	}

	/**
	 * Register a node site.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function create_site( WP_REST_Request $request ) {
		$name    = (string) $request->get_param( 'name' );
		$url     = (string) $request->get_param( 'url' );
		$api_key = (string) $request->get_param( 'api_key' );
		return $this->ok( ( new SiteManager() )->create( $name, $url, $api_key ) );
	}

	/**
	 * Remove a node site.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function delete_site( WP_REST_Request $request ) {
		$id = (int) $request['id'];
		return $this->ok( array( 'deleted' => ( new SiteManager() )->delete( $id ), 'id' => $id ) );
	}

	/**
	 * Ping a node site.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function test_site( WP_REST_Request $request ) {
		$id     = (int) $request['id'];
		$result = ( new SiteManager() )->test_connection( $id );
		SyncLog::record(
			array(
				'site_id' => $id,
				'action'  => 'ping',
				'status'  => ! empty( $result['success'] ) ? 'success' : 'error',
				'message' => ! empty( $result['success'] ) ? '' : (string) ( $result['error'] ?? '' ),
			)
		);
		return $this->ok( $result );
	}

	/**
	 * Issue an SSO login link on a node for the current operator.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function issue_sso( WP_REST_Request $request ) {
		return $this->ok( ( new Hub() )->issue_sso( (int) $request['id'] ) );
	}

	/**
	 * List local posts/pages available to push.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function local_posts( WP_REST_Request $request ) {
		$search    = sanitize_text_field( (string) ( $request->get_param( 'search' ) ?: '' ) );
		$post_type = sanitize_key( (string) ( $request->get_param( 'post_type' ) ?: 'post' ) );
		$allowed   = get_post_types( array( 'public' => true ) );
		unset( $allowed['attachment'] );
		if ( ! isset( $allowed[ $post_type ] ) ) {
			$post_type = 'post';
		}

		$posts = get_posts(
			array(
				'post_type'      => $post_type,
				'post_status'    => array( 'publish', 'draft', 'pending', 'private' ),
				'posts_per_page' => 50,
				'orderby'        => 'date',
				'order'          => 'DESC',
				's'              => $search,
			)
		);

		$items = array_map(
			static fn ( $p ): array => array(
				'id'        => (int) $p->ID,
				'title'     => $p->post_title,
				'status'    => $p->post_status,
				'post_type' => $p->post_type,
				'date'      => $p->post_date,
			),
			$posts
		);
		return $this->ok( $items );
	}

	/**
	 * Push a post to selected node sites.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function push( WP_REST_Request $request ) {
		$post_id  = (int) $request->get_param( 'post_id' );
		$site_ids = array_map( 'intval', (array) $request->get_param( 'site_ids' ) );
		return $this->ok( ( new Hub() )->push( $post_id, $site_ids ) );
	}

	/**
	 * Recent sync log rows.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function list_log( WP_REST_Request $request ) {
		return $this->ok( SyncLog::list( 100 ) );
	}
}
