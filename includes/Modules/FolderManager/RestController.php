<?php
/**
 * Folder Manager REST controller.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\FolderManager;

use UxStudio\Rest\Controller;
use WP_Error;
use WP_REST_Request;

defined( 'ABSPATH' ) || exit;

/**
 * GET    uxstudio/v1/folder-manager/folders            - list all folders
 * POST   uxstudio/v1/folder-manager/folders            - create a folder {name, parent?}
 * PUT    uxstudio/v1/folder-manager/folders/{id}       - rename a folder {name}
 * POST   uxstudio/v1/folder-manager/folders/{id}/move  - reparent a folder {parent}
 * DELETE uxstudio/v1/folder-manager/folders/{id}       - delete a folder
 * POST   uxstudio/v1/folder-manager/assign             - assign/clear an attachment's folder {attachment_id, folder_id}
 * POST   uxstudio/v1/folder-manager/items/move         - bulk-move attachments {attachment_ids[], folder_id}
 *
 * GET    uxstudio/v1/folder-manager/overview           - folder tree + counts for the sidebar {post_type}
 * GET    uxstudio/v1/folder-manager/post-types         - post types folders are enabled for
 *
 * Folder structure routes need `manage_categories` (the capability core uses
 * for taxonomy terms, so editors can manage folders from the media library
 * sidebar, not only administrators); moving items needs `edit_posts` and is
 * additionally checked per item with `edit_post`.
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
		$manage = 'manage_categories';
		$edit   = 'edit_posts';

		$post_type_arg = array(
			'required'          => false,
			'type'              => 'string',
			'sanitize_callback' => 'sanitize_key',
		);

		$this->route( '/folder-manager/folders', 'GET', array( $this, 'list_folders' ), array( 'post_type' => $post_type_arg ), $manage );

		$this->route( '/folder-manager/overview', 'GET', array( $this, 'overview' ), array( 'post_type' => $post_type_arg ), $edit );

		$this->route( '/folder-manager/post-types', 'GET', array( $this, 'post_types' ), array(), $manage );

		$this->route(
			'/folder-manager/folders',
			'POST',
			array( $this, 'create_folder' ),
			array(
				'name'   => array(
					'required'          => true,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
				),
				'parent' => array(
					'required'          => false,
					'type'              => 'integer',
					'default'           => 0,
					'sanitize_callback' => 'absint',
				),
				'post_type' => array(
					'required'          => false,
					'type'              => 'string',
					'default'           => 'attachment',
					'sanitize_callback' => 'sanitize_key',
				),
			),
			$manage
		);

		$this->route(
			'/folder-manager/folders/(?P<id>\d+)',
			'PUT',
			array( $this, 'rename_folder' ),
			array(
				'id'   => array(
					'required'          => true,
					'type'              => 'integer',
					'sanitize_callback' => 'absint',
				),
				'name' => array(
					'required'          => true,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
				),
			),
			$manage
		);

		$this->route(
			'/folder-manager/folders/(?P<id>\d+)/move',
			'POST',
			array( $this, 'reparent_folder' ),
			array(
				'id'     => array(
					'required'          => true,
					'type'              => 'integer',
					'sanitize_callback' => 'absint',
				),
				'parent' => array(
					'required'          => false,
					'type'              => 'integer',
					'default'           => 0,
					'sanitize_callback' => 'absint',
				),
			),
			$manage
		);

		$this->route(
			'/folder-manager/folders/(?P<id>\d+)',
			'DELETE',
			array( $this, 'delete_folder' ),
			array(
				'id' => array(
					'required'          => true,
					'type'              => 'integer',
					'sanitize_callback' => 'absint',
				),
			),
			$manage
		);

		$this->route(
			'/folder-manager/assign',
			'POST',
			array( $this, 'assign' ),
			array(
				'attachment_id' => array(
					'required'          => true,
					'type'              => 'integer',
					'sanitize_callback' => 'absint',
				),
				'folder_id'     => array(
					'required'          => true,
					'type'              => 'integer',
					'sanitize_callback' => 'absint',
				),
			),
			$edit
		);

		$this->route(
			'/folder-manager/items/move',
			'POST',
			array( $this, 'bulk_move' ),
			array(
				'attachment_ids' => array(
					'required' => true,
					'type'     => 'array',
					'items'    => array( 'type' => 'integer' ),
				),
				'folder_id'      => array(
					'required'          => true,
					'type'              => 'integer',
					'sanitize_callback' => 'absint',
				),
			),
			$edit
		);
	}

	/**
	 * List all folders.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function list_folders( WP_REST_Request $request ) {
		$post_type = (string) $request->get_param( 'post_type' );

		return $this->ok( $this->module->list_folders( '' === $post_type ? null : $post_type ) );
	}

	/**
	 * Folder tree + counts for the sidebar (one post type).
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function overview( WP_REST_Request $request ) {
		$post_type = (string) $request->get_param( 'post_type' );
		if ( '' === $post_type ) {
			$post_type = 'attachment';
		}

		if ( ! $this->module->is_enabled( $post_type ) || ! AdminIntegration::user_can_use( $post_type ) ) {
			return new WP_Error( 'uxstudio_post_type_disabled', __( 'Folders are not enabled for this post type.', 'ux-studio' ), array( 'status' => 403 ) );
		}

		return $this->ok( $this->module->overview( $post_type ) );
	}

	/**
	 * Post types folders are enabled for.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function post_types( WP_REST_Request $request ) {
		$labels = $this->module->available_post_types();
		$out    = array();
		foreach ( $this->module->enabled_post_types() as $type ) {
			$out[] = array(
				'name'  => $type,
				'label' => $labels[ $type ] ?? $type,
			);
		}

		return $this->ok( $out );
	}

	/**
	 * Create a folder.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function create_folder( WP_REST_Request $request ) {
		$name   = (string) $request->get_param( 'name' );
		$parent = (int) $request->get_param( 'parent' );
		$type   = (string) $request->get_param( 'post_type' );

		$result = $this->module->create_folder( $name, $parent, '' === $type ? 'attachment' : $type );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return $this->ok( $result );
	}

	/**
	 * Rename a folder.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function rename_folder( WP_REST_Request $request ) {
		$id   = (int) $request->get_param( 'id' );
		$name = (string) $request->get_param( 'name' );

		$result = $this->module->rename_folder( $id, $name );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return $this->ok( $result );
	}

	/**
	 * Reparent a folder (move it under another parent, or to top level).
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function reparent_folder( WP_REST_Request $request ) {
		$id     = (int) $request->get_param( 'id' );
		$parent = (int) $request->get_param( 'parent' );

		$result = $this->module->reparent_folder( $id, $parent );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return $this->ok( $result );
	}

	/**
	 * Bulk-move attachments to a folder (or clear when folder_id is 0).
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function bulk_move( WP_REST_Request $request ) {
		$attachment_ids = (array) $request->get_param( 'attachment_ids' );
		$folder_id      = (int) $request->get_param( 'folder_id' );

		$result = $this->module->bulk_move( $attachment_ids, $folder_id );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return $this->ok( $result );
	}

	/**
	 * Delete a folder.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function delete_folder( WP_REST_Request $request ) {
		$id = (int) $request->get_param( 'id' );

		$result = $this->module->delete_folder( $id );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return $this->ok( array( 'deleted' => true ) );
	}

	/**
	 * Assign or clear an attachment's folder.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function assign( WP_REST_Request $request ) {
		$attachment_id = (int) $request->get_param( 'attachment_id' );
		$folder_id     = (int) $request->get_param( 'folder_id' );

		$result = $this->module->assign( $attachment_id, $folder_id );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return $this->ok( array( 'assigned' => true ) );
	}
}
