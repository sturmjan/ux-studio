<?php
/**
 * Folder Manager REST controller.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\FolderManager;

use UxStudio\Rest\Controller;
use WP_REST_Request;

defined( 'ABSPATH' ) || exit;

/**
 * GET    uxstudio/v1/folder-manager/folders          - list all folders
 * POST   uxstudio/v1/folder-manager/folders          - create a folder {name, parent?}
 * DELETE uxstudio/v1/folder-manager/folders/{id}     - delete a folder
 * POST   uxstudio/v1/folder-manager/assign           - assign/clear an attachment's folder {attachment_id, folder_id}
 *
 * All routes require the module capability (manage_options).
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
		$capability = $this->module->capability();

		$this->route( '/folder-manager/folders', 'GET', array( $this, 'list_folders' ), array(), $capability );

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
			),
			$capability
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
			$capability
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
			$capability
		);
	}

	/**
	 * List all folders.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function list_folders( WP_REST_Request $request ) {
		return $this->ok( $this->module->list_folders() );
	}

	/**
	 * Create a folder.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function create_folder( WP_REST_Request $request ) {
		$name   = (string) $request->get_param( 'name' );
		$parent = (int) $request->get_param( 'parent' );

		$result = $this->module->create_folder( $name, $parent );
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
