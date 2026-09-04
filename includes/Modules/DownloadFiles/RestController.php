<?php
/**
 * Download Files REST controller.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\DownloadFiles;

use UxStudio\Rest\Controller;
use WP_Error;
use WP_REST_Request;

defined( 'ABSPATH' ) || exit;

/**
 * GET    uxstudio/v1/download-files/items          - list files (manage_options)
 * POST   uxstudio/v1/download-files/items          - create a file entry (manage_options)
 * PATCH  uxstudio/v1/download-files/items/{id}     - update a file entry (manage_options)
 * DELETE uxstudio/v1/download-files/items/{id}     - delete a file entry (manage_options)
 * GET    uxstudio/v1/download-files/categories     - distinct category labels (manage_options)
 * GET    uxstudio/v1/download-files/serve/{id}     - public, token-gated download
 *
 * The serve route is deliberately NOT registered through Controller::route():
 * that helper always gates on current_user_can($capability), but a download
 * link must be usable by anonymous visitors (the token itself IS the auth).
 * It is registered directly with register_rest_route() and a permission_callback
 * of '__return_true'; all real authorization (token + require_login check)
 * happens inside Module::serve().
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
		$this->route( '/download-files/items', 'GET', array( $this, 'list_items' ) );

		$this->route(
			'/download-files/items',
			'POST',
			array( $this, 'create_item' ),
			array(
				'title'         => array(
					'required'          => true,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
				),
				'attachment_id' => array(
					'required'          => true,
					'type'              => 'integer',
					'sanitize_callback' => 'absint',
				),
				'require_login' => array(
					'required' => false,
					'type'     => 'boolean',
				),
				'category'      => array(
					'required'          => false,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
				),
				'post_id'       => array(
					'required'          => false,
					'type'              => 'integer',
					'sanitize_callback' => 'absint',
				),
			)
		);

		$this->route(
			'/download-files/items/(?P<id>\d+)',
			'PATCH',
			array( $this, 'update_item' ),
			array(
				'title'         => array(
					'required'          => false,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
				),
				'require_login' => array(
					'required' => false,
					'type'     => 'boolean',
				),
				'category'      => array(
					'required'          => false,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
				),
				'post_id'       => array(
					'required'          => false,
					'type'              => 'integer',
					'sanitize_callback' => 'absint',
				),
			)
		);

		$this->route(
			'/download-files/items/(?P<id>\d+)',
			'DELETE',
			array( $this, 'delete_item' )
		);

		$this->route( '/download-files/categories', 'GET', array( $this, 'list_categories' ) );

		// Deliberate deviation from $this->route(): see class docblock above.
		register_rest_route(
			self::NS,
			'/download-files/serve/(?P<id>\d+)',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'serve' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'id'    => array(
						'required'          => true,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					),
					'token' => array(
						'required'          => false,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);
	}

	/**
	 * List all files.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function list_items( WP_REST_Request $request ) {
		return $this->ok( $this->module->list_files() );
	}

	/**
	 * Create a new file entry from an existing media library attachment.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function create_item( WP_REST_Request $request ) {
		$attachment_id = absint( $request->get_param( 'attachment_id' ) );

		if ( 'attachment' !== get_post_type( $attachment_id ) ) {
			return new WP_Error( 'uxstudio_invalid_attachment', __( 'Please select a valid media library file.', 'ux-studio' ), array( 'status' => 400 ) );
		}

		$data = array(
			'title'         => (string) $request->get_param( 'title' ),
			'attachment_id' => $attachment_id,
		);

		if ( null !== $request->get_param( 'require_login' ) ) {
			$data['require_login'] = (bool) $request->get_param( 'require_login' );
		}
		if ( null !== $request->get_param( 'category' ) ) {
			$data['category'] = (string) $request->get_param( 'category' );
		}
		if ( null !== $request->get_param( 'post_id' ) ) {
			$data['post_id'] = absint( $request->get_param( 'post_id' ) );
		}

		return $this->ok( $this->module->create_file( $data ) );
	}

	/**
	 * Update an existing file entry (title / require_login / category / post_id).
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function update_item( WP_REST_Request $request ) {
		$id = absint( $request->get_param( 'id' ) );

		$data = array();
		if ( null !== $request->get_param( 'title' ) ) {
			$data['title'] = (string) $request->get_param( 'title' );
		}
		if ( null !== $request->get_param( 'require_login' ) ) {
			$data['require_login'] = (bool) $request->get_param( 'require_login' );
		}
		if ( null !== $request->get_param( 'category' ) ) {
			$data['category'] = (string) $request->get_param( 'category' );
		}
		if ( null !== $request->get_param( 'post_id' ) ) {
			$data['post_id'] = absint( $request->get_param( 'post_id' ) );
		}

		$result = $this->module->update_file( $id, $data );

		if ( $result instanceof WP_Error ) {
			return $result;
		}

		return $this->ok( $result );
	}

	/**
	 * List distinct category labels in use.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function list_categories( WP_REST_Request $request ) {
		return $this->ok( $this->module->list_categories() );
	}

	/**
	 * Delete a file entry.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function delete_item( WP_REST_Request $request ) {
		$id = absint( $request->get_param( 'id' ) );

		if ( ! $this->module->delete_file( $id ) ) {
			return new WP_Error( 'uxstudio_not_found', __( 'File not found.', 'ux-studio' ), array( 'status' => 404 ) );
		}

		return $this->ok( array( 'deleted' => true ) );
	}

	/**
	 * Public, token-gated file download. Streams the file directly and exits
	 * on success (bypassing the normal REST response envelope); returns a
	 * WP_Error only on failure.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function serve( WP_REST_Request $request ) {
		$id    = absint( $request->get_param( 'id' ) );
		$token = (string) $request->get_param( 'token' );

		$result = $this->module->serve( $id, $token );

		// Module::serve() exits directly on success; it only returns when
		// something went wrong.
		return $result instanceof WP_Error ? $result : new WP_Error( 'uxstudio_download_failed', __( 'Unable to serve the file.', 'ux-studio' ), array( 'status' => 500 ) );
	}
}
