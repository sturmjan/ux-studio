<?php
/**
 * Node-side REST endpoints: receive & apply operations pushed by a hub.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\ContentSync;

use UxStudio\Core\ActivityLog;
use UxStudio\Core\Security;
use WP_Error;
use WP_Post;
use WP_Query;
use WP_REST_Request;
use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

/**
 * These routes are authenticated ONLY by HMAC (shared node_api_key), not by a
 * WP cookie/nonce, because the caller is another site (the hub), not a logged
 * in browser. Every route's permission_callback runs HmacAuth::verify_request.
 * All routes live under uxstudio/v1/content-sync/node.
 */
final class NodeController {

	private const NS   = 'uxstudio/v1';
	private const BASE = '/content-sync/node';

	/**
	 * Register node routes.
	 */
	public function register_routes(): void {
		$verify = array( $this, 'verify' );

		register_rest_route( self::NS, self::BASE . '/ping', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'ping' ),
			'permission_callback' => $verify,
		) );

		register_rest_route( self::NS, self::BASE . '/posts', array(
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_posts' ),
				'permission_callback' => $verify,
			),
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'create_post' ),
				'permission_callback' => $verify,
			),
		) );

		register_rest_route( self::NS, self::BASE . '/posts/(?P<id>\d+)', array(
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_post' ),
				'permission_callback' => $verify,
			),
			array(
				'methods'             => 'PUT',
				'callback'            => array( $this, 'update_post' ),
				'permission_callback' => $verify,
			),
			array(
				'methods'             => 'DELETE',
				'callback'            => array( $this, 'delete_post' ),
				'permission_callback' => $verify,
			),
		) );

		register_rest_route( self::NS, self::BASE . '/categories', array(
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_categories' ),
				'permission_callback' => $verify,
			),
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'create_category' ),
				'permission_callback' => $verify,
			),
		) );

		register_rest_route( self::NS, self::BASE . '/categories/(?P<id>\d+)', array(
			array(
				'methods'             => 'PUT',
				'callback'            => array( $this, 'update_category' ),
				'permission_callback' => $verify,
			),
			array(
				'methods'             => 'DELETE',
				'callback'            => array( $this, 'delete_category' ),
				'permission_callback' => $verify,
			),
		) );

		register_rest_route( self::NS, self::BASE . '/taxonomies/(?P<taxonomy>[a-zA-Z0-9_-]+)/terms', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'get_terms' ),
			'permission_callback' => $verify,
		) );

		register_rest_route( self::NS, self::BASE . '/media/upload', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'upload_media' ),
			'permission_callback' => $verify,
		) );

		register_rest_route( self::NS, self::BASE . '/media/(?P<id>\d+)', array(
			'methods'             => 'DELETE',
			'callback'            => array( $this, 'delete_media' ),
			'permission_callback' => $verify,
		) );

		register_rest_route( self::NS, self::BASE . '/acf/field-groups', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'get_acf_field_groups' ),
			'permission_callback' => $verify,
		) );
	}

	/**
	 * HMAC permission callback shared by every node route (and SsoController).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return true|WP_Error
	 */
	public function verify( WP_REST_Request $request ) {
		$secret = Security::get_secret( Module::SECRET_NODE_KEY );
		return HmacAuth::verify_request( $request, $secret );
	}

	/**
	 * Capability announcement + author list for the hub.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function ping( WP_REST_Request $request ): WP_REST_Response {
		$authors = get_users(
			array(
				'role__in' => array( 'administrator', 'editor', 'author' ),
				'fields'   => array( 'ID', 'display_name', 'user_login' ),
				'orderby'  => 'display_name',
			)
		);

		$post_types = array();
		foreach ( get_post_types( array( 'public' => true ), 'objects' ) as $pt ) {
			if ( 'attachment' === $pt->name ) {
				continue;
			}
			$post_types[] = array(
				'name'         => $pt->name,
				'label'        => $pt->label,
				'hierarchical' => (bool) $pt->hierarchical,
			);
		}

		return new WP_REST_Response(
			array(
				'ok'         => true,
				'site'       => home_url( '/' ),
				'acf_active' => AcfBridge::is_active(),
				'post_types' => $post_types,
				'authors'    => array_map(
					static fn ( $u ): array => array(
						'id'    => (int) $u->ID,
						'name'  => $u->display_name,
						'login' => $u->user_login,
					),
					$authors
				),
			),
			200
		);
	}

	/**
	 * List posts.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function get_posts( WP_REST_Request $request ): WP_REST_Response {
		$post_type = sanitize_key( (string) ( $request->get_param( 'post_type' ) ?: 'post' ) );
		$allowed   = get_post_types( array( 'public' => true ) );
		unset( $allowed['attachment'] );
		if ( ! isset( $allowed[ $post_type ] ) ) {
			$post_type = 'post';
		}

		$args = array(
			'post_type'      => $post_type,
			'post_status'    => array( 'publish', 'draft', 'pending' ),
			'posts_per_page' => min( 100, max( 1, (int) ( $request->get_param( 'per_page' ) ?: 20 ) ) ),
			'paged'          => max( 1, (int) ( $request->get_param( 'page' ) ?: 1 ) ),
			'orderby'        => 'date',
			'order'          => 'DESC',
		);
		$search = $request->get_param( 'search' );
		if ( $search ) {
			$args['s'] = sanitize_text_field( (string) $search );
		}

		$query = new WP_Query( $args );
		$items = array();
		foreach ( $query->posts as $p ) {
			$items[] = array(
				'id'         => $p->ID,
				'title'      => $p->post_title,
				'status'     => $p->post_status,
				'post_type'  => $p->post_type,
				'date'       => $p->post_date,
				'modified'   => $p->post_modified,
			);
		}

		return new WP_REST_Response(
			array(
				'items'       => $items,
				'total'       => (int) $query->found_posts,
				'total_pages' => (int) $query->max_num_pages,
				'page'        => $args['paged'],
			),
			200
		);
	}

	/**
	 * Fetch one post detail.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function get_post( WP_REST_Request $request ): WP_REST_Response {
		$post = get_post( (int) $request['id'] );
		if ( ! $this->is_editable_post( $post ) ) {
			return $this->not_found( __( 'Post not found.', 'ux-studio' ) );
		}
		return new WP_REST_Response( $this->format_post( $post ), 200 );
	}

	/**
	 * Create (or upsert-by-hub-origin) a post.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function create_post( WP_REST_Request $request ): WP_REST_Response {
		$data = (array) $request->get_json_params();

		// Idempotency: if this hub post was already synced here, update it.
		$hub_id = sanitize_text_field( (string) ( $data['hub_post_id'] ?? '' ) );
		if ( '' !== $hub_id ) {
			$existing = $this->find_by_hub_id( $hub_id );
			if ( $existing > 0 ) {
				$request->set_param( 'id', $existing );
				return $this->update_post( $request );
			}
		}

		$post_type = sanitize_key( (string) ( $data['post_type'] ?? 'post' ) );
		$allowed   = get_post_types( array( 'public' => true ) );
		unset( $allowed['attachment'] );
		if ( ! isset( $allowed[ $post_type ] ) ) {
			$post_type = 'post';
		}

		$author = (int) ( $data['author_id'] ?? 0 );
		if ( $author <= 0 ) {
			$author = (int) Module::setting( 'node_default_author', 0 );
		}
		if ( $author <= 0 ) {
			$author = 1;
		}

		$post_data = array(
			'post_type'    => $post_type,
			'post_title'   => sanitize_text_field( (string) ( $data['title'] ?? '' ) ),
			'post_content' => wp_kses_post( (string) ( $data['content'] ?? '' ) ),
			'post_status'  => $this->safe_status( (string) ( $data['status'] ?? 'draft' ) ),
			'post_excerpt' => sanitize_textarea_field( (string) ( $data['excerpt'] ?? '' ) ),
			'post_author'  => $author,
		);
		if ( isset( $data['post_parent'] ) ) {
			$post_data['post_parent'] = (int) $data['post_parent'];
		}

		$post_id = wp_insert_post( $post_data, true );
		if ( is_wp_error( $post_id ) ) {
			return new WP_REST_Response( array( 'message' => $post_id->get_error_message() ), 500 );
		}
		$post_id = (int) $post_id;

		$this->apply_terms_and_meta( $post_id, $data );
		if ( '' !== $hub_id ) {
			update_post_meta( $post_id, '_uxstudio_cs_hub_id', $hub_id );
		}

		ActivityLog::log( 'content-sync', 'node_create_post', 'post', $post_id );

		return new WP_REST_Response( $this->format_post( get_post( $post_id ) ), 201 );
	}

	/**
	 * Update a post.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function update_post( WP_REST_Request $request ): WP_REST_Response {
		$post_id = (int) $request['id'];
		$post    = get_post( $post_id );
		if ( ! $this->is_editable_post( $post ) ) {
			return $this->not_found( __( 'Post not found.', 'ux-studio' ) );
		}

		$data      = (array) $request->get_json_params();
		$post_data = array( 'ID' => $post_id );
		if ( isset( $data['title'] ) ) {
			$post_data['post_title'] = sanitize_text_field( (string) $data['title'] );
		}
		if ( isset( $data['content'] ) ) {
			$post_data['post_content'] = wp_kses_post( (string) $data['content'] );
		}
		if ( isset( $data['status'] ) ) {
			$post_data['post_status'] = $this->safe_status( (string) $data['status'] );
		}
		if ( isset( $data['excerpt'] ) ) {
			$post_data['post_excerpt'] = sanitize_textarea_field( (string) $data['excerpt'] );
		}
		if ( isset( $data['post_parent'] ) ) {
			$post_data['post_parent'] = (int) $data['post_parent'];
		}

		$result = wp_update_post( $post_data, true );
		if ( is_wp_error( $result ) ) {
			return new WP_REST_Response( array( 'message' => $result->get_error_message() ), 500 );
		}

		$this->apply_terms_and_meta( $post_id, $data );

		ActivityLog::log( 'content-sync', 'node_update_post', 'post', $post_id );

		return new WP_REST_Response( $this->format_post( get_post( $post_id ) ), 200 );
	}

	/**
	 * Delete a post.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function delete_post( WP_REST_Request $request ): WP_REST_Response {
		$post_id = (int) $request['id'];
		$post    = get_post( $post_id );
		if ( ! $this->is_editable_post( $post ) ) {
			return $this->not_found( __( 'Post not found.', 'ux-studio' ) );
		}

		$force = (bool) $request->get_param( 'force' );
		if ( ! wp_delete_post( $post_id, $force ) ) {
			return new WP_REST_Response( array( 'message' => __( 'Could not delete the post.', 'ux-studio' ) ), 500 );
		}

		ActivityLog::log( 'content-sync', 'node_delete_post', 'post', $post_id );

		return new WP_REST_Response( array( 'deleted' => true, 'id' => $post_id ), 200 );
	}

	/**
	 * List categories.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function get_categories( WP_REST_Request $request ): WP_REST_Response {
		return new WP_REST_Response( $this->format_terms( 'category' ), 200 );
	}

	/**
	 * Create a category.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function create_category( WP_REST_Request $request ): WP_REST_Response {
		$data = (array) $request->get_json_params();
		$name = sanitize_text_field( (string) ( $data['name'] ?? '' ) );
		if ( '' === $name ) {
			return new WP_REST_Response( array( 'message' => __( 'Category name is required.', 'ux-studio' ) ), 400 );
		}

		$args = array( 'slug' => sanitize_title( (string) ( $data['slug'] ?? $name ) ) );
		if ( ! empty( $data['parent'] ) ) {
			$args['parent'] = (int) $data['parent'];
		}

		$result = wp_insert_term( $name, 'category', $args );
		if ( is_wp_error( $result ) ) {
			// An existing term with the same slug is a benign, idempotent outcome.
			$existing = get_term_by( 'slug', $args['slug'], 'category' );
			if ( $existing ) {
				return new WP_REST_Response( $this->term_shape( $existing ), 200 );
			}
			return new WP_REST_Response( array( 'message' => $result->get_error_message() ), 400 );
		}

		return new WP_REST_Response( $this->term_shape( get_term( $result['term_id'], 'category' ) ), 201 );
	}

	/**
	 * Update a category.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function update_category( WP_REST_Request $request ): WP_REST_Response {
		$term_id = (int) $request['id'];
		$term    = get_term( $term_id, 'category' );
		if ( ! $term || is_wp_error( $term ) ) {
			return $this->not_found( __( 'Category not found.', 'ux-studio' ) );
		}

		$data = (array) $request->get_json_params();
		$args = array();
		if ( isset( $data['name'] ) ) {
			$args['name'] = sanitize_text_field( (string) $data['name'] );
		}
		if ( isset( $data['slug'] ) ) {
			$args['slug'] = sanitize_title( (string) $data['slug'] );
		}
		if ( isset( $data['parent'] ) ) {
			$args['parent'] = (int) $data['parent'];
		}

		$result = wp_update_term( $term_id, 'category', $args );
		if ( is_wp_error( $result ) ) {
			return new WP_REST_Response( array( 'message' => $result->get_error_message() ), 400 );
		}

		return new WP_REST_Response( $this->term_shape( get_term( $term_id, 'category' ) ), 200 );
	}

	/**
	 * Delete a category.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function delete_category( WP_REST_Request $request ): WP_REST_Response {
		$term_id = (int) $request['id'];
		$term    = get_term( $term_id, 'category' );
		if ( ! $term || is_wp_error( $term ) ) {
			return $this->not_found( __( 'Category not found.', 'ux-studio' ) );
		}
		if ( $term_id === (int) get_option( 'default_category' ) ) {
			return new WP_REST_Response( array( 'message' => __( 'Cannot delete the default category.', 'ux-studio' ) ), 400 );
		}

		$result = wp_delete_term( $term_id, 'category' );
		if ( is_wp_error( $result ) ) {
			return new WP_REST_Response( array( 'message' => $result->get_error_message() ), 500 );
		}

		return new WP_REST_Response( array( 'deleted' => true, 'id' => $term_id ), 200 );
	}

	/**
	 * List terms of a taxonomy.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function get_terms( WP_REST_Request $request ): WP_REST_Response {
		$taxonomy = sanitize_key( (string) $request['taxonomy'] );
		if ( ! taxonomy_exists( $taxonomy ) ) {
			return $this->not_found( __( 'Taxonomy not found.', 'ux-studio' ) );
		}
		return new WP_REST_Response( $this->format_terms( $taxonomy ), 200 );
	}

	/**
	 * Sideload an uploaded file into the media library.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function upload_media( WP_REST_Request $request ): WP_REST_Response {
		$files = $request->get_file_params();
		if ( empty( $files['file'] ) ) {
			return new WP_REST_Response( array( 'message' => __( 'No file was sent.', 'ux-studio' ) ), 400 );
		}
		$file = $files['file'];

		$finfo     = new \finfo( FILEINFO_MIME_TYPE );
		$real_mime = $finfo->file( $file['tmp_name'] );
		if ( ! in_array( $real_mime, get_allowed_mime_types(), true ) ) {
			return new WP_REST_Response( array( 'message' => __( 'Disallowed file type.', 'ux-studio' ) ), 400 );
		}
		if ( (int) $file['size'] > 10 * 1024 * 1024 ) {
			return new WP_REST_Response( array( 'message' => __( 'File too large (max 10 MB).', 'ux-studio' ) ), 400 );
		}

		require_once ABSPATH . 'wp-admin/includes/image.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';

		$attachment_id = media_handle_sideload(
			array(
				'name'     => sanitize_file_name( $file['name'] ),
				'type'     => $real_mime,
				'tmp_name' => $file['tmp_name'],
				'error'    => $file['error'],
				'size'     => $file['size'],
			),
			0
		);
		if ( is_wp_error( $attachment_id ) ) {
			return new WP_REST_Response( array( 'message' => $attachment_id->get_error_message() ), 500 );
		}

		ActivityLog::log( 'content-sync', 'node_upload_media', 'attachment', (int) $attachment_id );

		return new WP_REST_Response(
			array(
				'attachment_id' => (int) $attachment_id,
				'url'           => wp_get_attachment_url( (int) $attachment_id ),
				'filename'      => basename( (string) get_attached_file( (int) $attachment_id ) ),
			),
			201
		);
	}

	/**
	 * Delete an attachment.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function delete_media( WP_REST_Request $request ): WP_REST_Response {
		$attachment_id = (int) $request['id'];
		$attachment    = get_post( $attachment_id );
		if ( ! $attachment || 'attachment' !== $attachment->post_type ) {
			return $this->not_found( __( 'Media not found.', 'ux-studio' ) );
		}
		if ( ! wp_delete_attachment( $attachment_id, true ) ) {
			return new WP_REST_Response( array( 'message' => __( 'Could not delete the media.', 'ux-studio' ) ), 500 );
		}
		return new WP_REST_Response( array( 'deleted' => true, 'id' => $attachment_id ), 200 );
	}

	/**
	 * Expose active ACF field groups for a post type.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function get_acf_field_groups( WP_REST_Request $request ): WP_REST_Response {
		if ( ! function_exists( 'acf_get_field_groups' ) || ! function_exists( 'acf_get_fields' ) ) {
			return new WP_REST_Response( array(), 200 );
		}
		$post_type = sanitize_key( (string) ( $request->get_param( 'post_type' ) ?: 'post' ) );
		$groups    = acf_get_field_groups( array( 'post_type' => $post_type ) );
		$out       = array();
		foreach ( (array) $groups as $group ) {
			if ( empty( $group['active'] ) ) {
				continue;
			}
			$fields = acf_get_fields( $group['key'] );
			$out[]  = array(
				'key'    => $group['key'],
				'title'  => $group['title'],
				'fields' => $this->format_acf_fields( is_array( $fields ) ? $fields : array() ),
			);
		}
		return new WP_REST_Response( $out, 200 );
	}

	/**
	 * Apply categories, custom taxonomies, featured image and ACF to a post.
	 *
	 * @param int   $post_id Post id.
	 * @param array $data    Incoming payload.
	 */
	private function apply_terms_and_meta( int $post_id, array $data ): void {
		if ( isset( $data['categories'] ) ) {
			wp_set_post_categories( $post_id, array_map( 'intval', (array) $data['categories'] ) );
		}
		if ( ! empty( $data['taxonomies'] ) && is_array( $data['taxonomies'] ) ) {
			foreach ( $data['taxonomies'] as $taxonomy => $term_ids ) {
				$tax = sanitize_key( (string) $taxonomy );
				if ( taxonomy_exists( $tax ) ) {
					wp_set_object_terms( $post_id, array_map( 'intval', (array) $term_ids ), $tax );
				}
			}
		}
		if ( array_key_exists( 'featured_image_id', $data ) ) {
			$thumb = (int) $data['featured_image_id'];
			if ( $thumb > 0 ) {
				set_post_thumbnail( $post_id, $thumb );
			} else {
				delete_post_thumbnail( $post_id );
			}
		}
		if ( ! empty( $data['acf'] ) && is_array( $data['acf'] ) ) {
			AcfBridge::apply( $post_id, $data['acf'] );
		}
	}

	/**
	 * Find a local post previously synced from a given hub origin id.
	 *
	 * @param string $hub_id Hub origin id.
	 */
	private function find_by_hub_id( string $hub_id ): int {
		$found = get_posts(
			array(
				'post_type'      => 'any',
				'post_status'    => 'any',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				'meta_query'     => array(
					array(
						'key'   => '_uxstudio_cs_hub_id',
						'value' => $hub_id,
					),
				),
			)
		);
		return ! empty( $found ) ? (int) $found[0] : 0;
	}

	/**
	 * Format a post for detail responses.
	 *
	 * @param WP_Post $post Post.
	 * @return array<string, mixed>
	 */
	private function format_post( WP_Post $post ): array {
		$thumb = get_post_thumbnail_id( $post->ID );
		$data  = array(
			'id'                 => $post->ID,
			'title'              => $post->post_title,
			'content'            => $post->post_content,
			'excerpt'            => $post->post_excerpt,
			'status'             => $post->post_status,
			'post_type'          => $post->post_type,
			'date'               => $post->post_date,
			'modified'           => $post->post_modified,
			'link'               => get_permalink( $post->ID ),
			'category_ids'       => wp_get_post_categories( $post->ID ),
			'author_id'          => (int) $post->post_author,
			'featured_image_id'  => $thumb ? (int) $thumb : null,
			'featured_image_url' => $thumb ? wp_get_attachment_url( $thumb ) : null,
			'post_parent'        => (int) $post->post_parent,
		);
		if ( function_exists( 'get_fields' ) ) {
			$data['acf'] = AcfBridge::read( $post->ID );
		}
		return $data;
	}

	/**
	 * Format all terms of a taxonomy.
	 *
	 * @param string $taxonomy Taxonomy.
	 * @return array<int, array<string, mixed>>
	 */
	private function format_terms( string $taxonomy ): array {
		$terms = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'hide_empty' => false,
				'orderby'    => 'name',
				'order'      => 'ASC',
			)
		);
		if ( is_wp_error( $terms ) ) {
			return array();
		}
		return array_map( array( $this, 'term_shape' ), $terms );
	}

	/**
	 * Shape a term object.
	 *
	 * @param \WP_Term $term Term.
	 * @return array<string, mixed>
	 */
	private function term_shape( $term ): array {
		return array(
			'id'     => (int) $term->term_id,
			'name'   => $term->name,
			'slug'   => $term->slug,
			'parent' => (int) $term->parent,
			'count'  => (int) $term->count,
		);
	}

	/**
	 * Format ACF field definitions (recursive for group/repeater).
	 *
	 * @param array $fields Field defs.
	 * @return array<int, array<string, mixed>>
	 */
	private function format_acf_fields( array $fields ): array {
		$out = array();
		foreach ( $fields as $field ) {
			$item = array(
				'key'   => $field['key'] ?? '',
				'name'  => $field['name'] ?? '',
				'label' => $field['label'] ?? '',
				'type'  => $field['type'] ?? 'text',
			);
			if ( ! empty( $field['choices'] ) ) {
				$item['choices'] = $field['choices'];
			}
			if ( isset( $field['required'] ) ) {
				$item['required'] = (bool) $field['required'];
			}
			if ( ! empty( $field['sub_fields'] ) ) {
				$item['sub_fields'] = $this->format_acf_fields( $field['sub_fields'] );
			}
			$out[] = $item;
		}
		return $out;
	}

	/**
	 * Clamp an incoming status to a known-safe set.
	 *
	 * @param string $status Requested status.
	 */
	private function safe_status( string $status ): string {
		$allowed = array( 'publish', 'draft', 'pending', 'private', 'future' );
		return in_array( $status, $allowed, true ) ? $status : 'draft';
	}

	/**
	 * Whether a post exists and is a normal editable post (not a revision/attachment).
	 *
	 * @param WP_Post|null $post Post.
	 */
	private function is_editable_post( ?WP_Post $post ): bool {
		return $post instanceof WP_Post && 'revision' !== $post->post_type && 'attachment' !== $post->post_type;
	}

	/**
	 * 404 response.
	 *
	 * @param string $message Message.
	 */
	private function not_found( string $message ): WP_REST_Response {
		return new WP_REST_Response( array( 'message' => $message ), 404 );
	}
}
