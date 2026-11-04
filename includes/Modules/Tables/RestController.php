<?php
/**
 * Tables REST controller.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\Tables;

use UxStudio\Rest\Controller;
use WP_Error;
use WP_REST_Request;

defined( 'ABSPATH' ) || exit;

/**
 * GET      uxstudio/v1/tables                  - list (without cells)
 * GET      uxstudio/v1/tables/editor-config    - defaults and limits
 * GET      uxstudio/v1/tables/{id}             - one table with cells
 * POST     uxstudio/v1/tables                  - create {title, description, cells, options}
 * POST     uxstudio/v1/tables/{id}             - update
 * POST     uxstudio/v1/tables/{id}/duplicate   - copy
 * DELETE   uxstudio/v1/tables/{id}             - delete
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
		$id = array( 'id' => array( 'required' => true, 'type' => 'integer' ) );

		$this->route( '/tables', 'GET', fn () => $this->ok( $this->module->list_tables() ) );
		$this->route( '/tables', 'POST', array( $this, 'create' ) );
		$this->route( '/tables/editor-config', 'GET', array( $this, 'editor_config' ) );
		$this->route( '/tables/(?P<id>\d+)', 'GET', array( $this, 'read' ), $id );
		$this->route( '/tables/(?P<id>\d+)', 'POST', array( $this, 'update' ), $id );
		$this->route( '/tables/(?P<id>\d+)/duplicate', 'POST', array( $this, 'duplicate' ), $id );
		$this->route( '/tables/(?P<id>\d+)', 'DELETE', array( $this, 'delete' ), $id );
	}

	/**
	 * Defaults for a new table and the size limits.
	 */
	public function editor_config(): mixed {
		return $this->ok(
			array(
				'defaults'   => $this->module->default_options(),
				'max_rows'   => Module::MAX_ROWS,
				'max_cols'   => Module::MAX_COLS,
				'responsive' => $this->module->responsive_options(),
			)
		);
	}

	/**
	 * @param WP_REST_Request $request Request.
	 */
	public function read( WP_REST_Request $request ): mixed {
		$table = $this->module->get_table( (int) $request['id'] );
		return null === $table ? new WP_Error( 'uxstudio_table_not_found', __( 'Table not found.', 'ux-studio' ), array( 'status' => 404 ) ) : $this->ok( $table );
	}

	/**
	 * @param WP_REST_Request $request Request.
	 */
	public function create( WP_REST_Request $request ): mixed {
		$table = $this->module->save_table( 0, (array) $request->get_json_params() );
		return is_wp_error( $table ) ? $table : $this->ok( $table );
	}

	/**
	 * @param WP_REST_Request $request Request.
	 */
	public function update( WP_REST_Request $request ): mixed {
		$table = $this->module->save_table( (int) $request['id'], (array) $request->get_json_params() );
		return is_wp_error( $table ) ? $table : $this->ok( $table );
	}

	/**
	 * @param WP_REST_Request $request Request.
	 */
	public function duplicate( WP_REST_Request $request ): mixed {
		$table = $this->module->duplicate_table( (int) $request['id'] );
		return is_wp_error( $table ) ? $table : $this->ok( $table );
	}

	/**
	 * @param WP_REST_Request $request Request.
	 */
	public function delete( WP_REST_Request $request ): mixed {
		$result = $this->module->delete_table( (int) $request['id'] );
		return is_wp_error( $result ) ? $result : $this->ok( array( 'deleted' => true ) );
	}
}
