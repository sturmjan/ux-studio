<?php
/**
 * Admin-facing (nonce + capability) REST controller for the SPA.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\ContentSync;

use UxStudio\Rest\Controller;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

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
 *
 * Remote content management (proxied to the node over the signed channel):
 * GET    /content-sync/sites/{id}/posts              list posts (page, per_page, status, search, category, post_type)
 * GET    /content-sync/sites/{id}/posts/{post_id}    post detail
 * POST   /content-sync/sites/{id}/posts              create post
 * PUT    /content-sync/sites/{id}/posts/{post_id}    update post
 * DELETE /content-sync/sites/{id}/posts/{post_id}    delete post (?force=1 skips trash)
 * GET    /content-sync/sites/{id}/categories         list categories
 * POST   /content-sync/sites/{id}/categories         create category
 * GET    /content-sync/sites/{id}/acf/field-groups   ACF field groups (cached 1 h)
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

		$this->route( '/content-sync/sites/(?P<id>\d+)/posts', 'GET', array( $this, 'remote_posts' ) );
		$this->route( '/content-sync/sites/(?P<id>\d+)/posts', 'POST', array( $this, 'remote_create_post' ) );
		$this->route( '/content-sync/sites/(?P<id>\d+)/posts/(?P<post_id>\d+)', 'GET', array( $this, 'remote_post' ) );
		$this->route( '/content-sync/sites/(?P<id>\d+)/posts/(?P<post_id>\d+)', 'PUT', array( $this, 'remote_update_post' ) );
		$this->route( '/content-sync/sites/(?P<id>\d+)/posts/(?P<post_id>\d+)', 'DELETE', array( $this, 'remote_delete_post' ) );
		$this->route( '/content-sync/sites/(?P<id>\d+)/categories', 'GET', array( $this, 'remote_categories' ) );
		$this->route( '/content-sync/sites/(?P<id>\d+)/categories', 'POST', array( $this, 'remote_create_category' ) );
		$this->route( '/content-sync/sites/(?P<id>\d+)/acf/field-groups', 'GET', array( $this, 'remote_acf_groups' ) );
	}

	/**
	 * List posts on a node.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function remote_posts( WP_REST_Request $request ) {
		$params = array();
		foreach ( array( 'page', 'per_page' ) as $key ) {
			if ( null !== $request->get_param( $key ) ) {
				$params[ $key ] = max( 1, (int) $request->get_param( $key ) );
			}
		}
		foreach ( array( 'status', 'search', 'post_type' ) as $key ) {
			$value = sanitize_text_field( (string) $request->get_param( $key ) );
			if ( '' !== $value ) {
				$params[ $key ] = $value;
			}
		}
		if ( (int) $request->get_param( 'category' ) > 0 ) {
			$params['category'] = (int) $request->get_param( 'category' );
		}

		return $this->proxy( (int) $request['id'], 'GET', '/posts', $params );
	}

	/**
	 * One post on a node.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function remote_post( WP_REST_Request $request ) {
		return $this->proxy( (int) $request['id'], 'GET', '/posts/' . (int) $request['post_id'] );
	}

	/**
	 * Create a post on a node.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function remote_create_post( WP_REST_Request $request ) {
		$data = $this->post_payload( $request );
		return $this->proxy( (int) $request['id'], 'POST', '/posts', array(), $data, 'create_post', (string) ( $data['title'] ?? '' ) );
	}

	/**
	 * Update a post on a node.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function remote_update_post( WP_REST_Request $request ) {
		$post_id = (int) $request['post_id'];
		$data    = $this->post_payload( $request );
		return $this->proxy( (int) $request['id'], 'PUT', '/posts/' . $post_id, array(), $data, 'update_post', (string) ( $data['title'] ?? '' ), $post_id );
	}

	/**
	 * Delete a post on a node.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function remote_delete_post( WP_REST_Request $request ) {
		$post_id  = (int) $request['post_id'];
		$endpoint = '/posts/' . $post_id . ( $request->get_param( 'force' ) ? '?force=true' : '' );
		return $this->proxy( (int) $request['id'], 'DELETE', $endpoint, array(), array(), 'delete_post', '', $post_id );
	}

	/**
	 * Categories of a node.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function remote_categories( WP_REST_Request $request ) {
		return $this->proxy( (int) $request['id'], 'GET', '/categories' );
	}

	/**
	 * Create a category on a node.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function remote_create_category( WP_REST_Request $request ) {
		$body = (array) $request->get_json_params();
		$data = array( 'name' => sanitize_text_field( (string) ( $body['name'] ?? '' ) ) );
		if ( ! empty( $body['slug'] ) ) {
			$data['slug'] = sanitize_title( (string) $body['slug'] );
		}
		if ( ! empty( $body['parent'] ) ) {
			$data['parent'] = absint( $body['parent'] );
		}
		return $this->proxy( (int) $request['id'], 'POST', '/categories', array(), $data, 'create_category', $data['name'] );
	}

	/**
	 * ACF field groups of a node (cached for an hour per node).
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function remote_acf_groups( WP_REST_Request $request ) {
		$site_id   = (int) $request['id'];
		$post_type = sanitize_key( (string) $request->get_param( 'post_type' ) );
		$cache_key = 'uxstudio_cs_acf_' . $site_id . '_' . $post_type;
		$cached    = get_transient( $cache_key );
		if ( false !== $cached ) {
			return $this->ok( $cached );
		}

		$params   = '' !== $post_type ? array( 'post_type' => $post_type ) : array();
		$response = $this->proxy( $site_id, 'GET', '/acf/field-groups', $params );
		if ( $response instanceof WP_REST_Response ) {
			$payload = $response->get_data();
			set_transient( $cache_key, $payload['data'] ?? array(), HOUR_IN_SECONDS );
		}
		return $response;
	}

	/**
	 * Keep only the post fields the node understands; the node re-sanitises
	 * everything, this just stops arbitrary keys travelling over the channel.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array<string, mixed>
	 */
	private function post_payload( WP_REST_Request $request ): array {
		$body = (array) $request->get_json_params();
		$out  = array();

		foreach ( array( 'title', 'slug', 'post_type', 'status', 'meta_title', 'meta_desc', 'focus_keyword' ) as $key ) {
			if ( isset( $body[ $key ] ) ) {
				$out[ $key ] = sanitize_text_field( (string) $body[ $key ] );
			}
		}
		if ( isset( $body['excerpt'] ) ) {
			$out['excerpt'] = sanitize_textarea_field( (string) $body['excerpt'] );
		}
		if ( isset( $body['content'] ) ) {
			$out['content'] = wp_kses_post( (string) $body['content'] );
		}
		foreach ( array( 'post_parent', 'featured_image_id', 'author_id' ) as $key ) {
			if ( isset( $body[ $key ] ) ) {
				$out[ $key ] = absint( $body[ $key ] );
			}
		}
		if ( isset( $body['categories'] ) ) {
			$out['categories'] = array_map( 'absint', (array) $body['categories'] );
		}
		if ( isset( $body['taxonomies'] ) && is_array( $body['taxonomies'] ) ) {
			$out['taxonomies'] = array();
			foreach ( $body['taxonomies'] as $taxonomy => $term_ids ) {
				$out['taxonomies'][ sanitize_key( (string) $taxonomy ) ] = array_map( 'absint', (array) $term_ids );
			}
		}
		if ( isset( $body['acf'] ) && is_array( $body['acf'] ) ) {
			$out['acf'] = $body['acf']; // Applied through AcfBridge on the node (field-type aware).
		}

		return $out;
	}

	/**
	 * Run one signed request against a node, log writes and wrap the answer.
	 *
	 * @param int                  $site_id Node id.
	 * @param string               $method  GET|POST|PUT|DELETE.
	 * @param string               $path    Endpoint under the node base.
	 * @param array<string, mixed> $params  Query params (GET).
	 * @param array<string, mixed> $body    JSON body (POST/PUT).
	 * @param string               $action  Log action for writes ('' = not logged).
	 * @param string               $title   Object title for the log.
	 * @param int                  $obj_id  Object id for the log.
	 * @return WP_REST_Response|WP_Error
	 */
	private function proxy( int $site_id, string $method, string $path, array $params = array(), array $body = array(), string $action = '', string $title = '', int $obj_id = 0 ) {
		$sites  = new SiteManager();
		$client = $sites->client( $site_id );
		if ( ! $client ) {
			return new WP_Error( 'uxstudio_cs_no_site', __( 'Site not found or missing API key.', 'ux-studio' ), array( 'status' => 404 ) );
		}

		switch ( $method ) {
			case 'POST':
				$result = $client->post( $path, $body );
				break;
			case 'PUT':
				$result = $client->put( $path, $body );
				break;
			case 'DELETE':
				$result = $client->delete( $path );
				break;
			default:
				$result = $client->get( $path, $params );
		}

		if ( '' !== $action ) {
			if ( 0 === $obj_id && isset( $result['data']['id'] ) ) {
				$obj_id = (int) $result['data']['id'];
			}
			$ok = ! empty( $result['success'] );
			SyncLog::record(
				array(
					'site_id'      => $site_id,
					'site_name'    => (string) ( $sites->get( $site_id )['name'] ?? '' ),
					'action'       => $action,
					'status'       => $ok ? 'success' : 'error',
					'object_type'  => 'create_category' === $action ? 'category' : 'post',
					'object_id'    => $obj_id,
					'object_title' => $title,
					'message'      => $ok ? '' : (string) ( $result['error'] ?? '' ),
				)
			);
			if ( $ok && 'create_category' !== $action ) {
				$sites->update( $site_id, array( 'last_sync' => current_time( 'mysql' ) ) );
			}
		}

		if ( empty( $result['success'] ) ) {
			$code = (int) ( $result['code'] ?? 0 );
			return new WP_Error(
				'uxstudio_cs_remote_error',
				(string) ( $result['error'] ?? __( 'Remote request failed.', 'ux-studio' ) ),
				array( 'status' => ( $code >= 400 && $code < 500 ) ? $code : 502 )
			);
		}

		return $this->ok( $result['data'] ?? array() );
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
