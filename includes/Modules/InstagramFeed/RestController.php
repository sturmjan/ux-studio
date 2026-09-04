<?php
/**
 * Instagram Feed REST controller.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\InstagramFeed;

use UxStudio\Rest\Controller;
use WP_Error;
use WP_REST_Request;

defined( 'ABSPATH' ) || exit;

/**
 * GET  uxstudio/v1/instagram-feed/status                       - connection + module status
 * POST uxstudio/v1/instagram-feed/connect                      - build the OAuth authorize URL
 * POST uxstudio/v1/instagram-feed/disconnect                   - forget token + cached media
 * POST uxstudio/v1/instagram-feed/sync                         - pull + sideload recent media
 * GET  uxstudio/v1/instagram-feed/media                        - cached media (admin browser)
 * POST uxstudio/v1/instagram-feed/media/{id}/toggle-hidden     - hide/show a cached item
 * GET  uxstudio/v1/instagram-feed/feeds                        - list feed definitions
 * POST uxstudio/v1/instagram-feed/feeds                        - create a feed
 * POST uxstudio/v1/instagram-feed/feeds/{id}                   - update a feed
 * POST uxstudio/v1/instagram-feed/feeds/{id}/delete            - delete a feed
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
		$this->route( '/instagram-feed/status', 'GET', array( $this, 'status' ) );
		$this->route( '/instagram-feed/connect', 'POST', array( $this, 'connect' ) );
		$this->route( '/instagram-feed/disconnect', 'POST', array( $this, 'disconnect' ) );
		$this->route( '/instagram-feed/sync', 'POST', array( $this, 'sync' ) );

		$this->route(
			'/instagram-feed/media',
			'GET',
			array( $this, 'media' ),
			array(
				'include_hidden' => array(
					'required' => false,
					'type'     => 'boolean',
				),
			)
		);
		$this->route( '/instagram-feed/media/(?P<id>\d+)/toggle-hidden', 'POST', array( $this, 'toggle_hidden' ) );

		$this->route( '/instagram-feed/feeds', 'GET', array( $this, 'list_feeds' ) );
		$this->route( '/instagram-feed/feeds', 'POST', array( $this, 'create_feed' ) );
		$this->route( '/instagram-feed/feeds/(?P<id>\d+)', 'POST', array( $this, 'update_feed' ) );
		$this->route( '/instagram-feed/feeds/(?P<id>\d+)/delete', 'POST', array( $this, 'delete_feed' ) );
	}

	/**
	 * Connection + module status.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function status( WP_REST_Request $request ) {
		return $this->ok( $this->module->status() );
	}

	/**
	 * Build the OAuth authorize URL.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function connect( WP_REST_Request $request ) {
		$result = $this->module->build_auth_url();
		return $result instanceof WP_Error ? $result : $this->ok( $result );
	}

	/**
	 * Disconnect the account.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function disconnect( WP_REST_Request $request ) {
		return $this->ok( $this->module->disconnect() );
	}

	/**
	 * Trigger a media sync.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function sync( WP_REST_Request $request ) {
		$result = $this->module->sync();
		return $result instanceof WP_Error ? $result : $this->ok( $result );
	}

	/**
	 * Cached media list.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function media( WP_REST_Request $request ) {
		$include_hidden = null === $request->get_param( 'include_hidden' ) ? true : (bool) $request->get_param( 'include_hidden' );
		return $this->ok( $this->module->list_media( 60, $include_hidden ) );
	}

	/**
	 * Toggle a media item's hidden flag.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function toggle_hidden( WP_REST_Request $request ) {
		$result = $this->module->toggle_hidden( absint( $request->get_param( 'id' ) ) );
		return $result instanceof WP_Error ? $result : $this->ok( $result );
	}

	/**
	 * List feed definitions.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function list_feeds( WP_REST_Request $request ) {
		return $this->ok( $this->module->list_feeds() );
	}

	/**
	 * Create a feed.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function create_feed( WP_REST_Request $request ) {
		$body = $this->body( $request );
		return $this->ok(
			$this->module->create_feed(
				(string) ( $body['name'] ?? '' ),
				is_array( $body['config'] ?? null ) ? $body['config'] : array()
			)
		);
	}

	/**
	 * Update a feed.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function update_feed( WP_REST_Request $request ) {
		$body   = $this->body( $request );
		$result = $this->module->update_feed(
			absint( $request->get_param( 'id' ) ),
			(string) ( $body['name'] ?? '' ),
			is_array( $body['config'] ?? null ) ? $body['config'] : array()
		);
		return $result instanceof WP_Error ? $result : $this->ok( $result );
	}

	/**
	 * Delete a feed.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function delete_feed( WP_REST_Request $request ) {
		$result = $this->module->delete_feed( absint( $request->get_param( 'id' ) ) );
		return $result instanceof WP_Error ? $result : $this->ok( $result );
	}

	/**
	 * Decode the JSON body of a request as an array.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array<string, mixed>
	 */
	private function body( WP_REST_Request $request ): array {
		$params = $request->get_json_params();
		return is_array( $params ) ? $params : array();
	}
}
