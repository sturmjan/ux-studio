<?php
/**
 * Content Sync REST controller.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\ContentSync;

use UxStudio\Rest\Controller;
use WP_REST_Request;

defined( 'ABSPATH' ) || exit;

/**
 * GET  uxstudio/v1/content-sync/sites - list registered sites
 * POST uxstudio/v1/content-sync/sites - register a new site
 * GET  uxstudio/v1/content-sync/log   - last 100 sync log rows
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
				'name' => array(
					'required'          => true,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
				),
				'url'  => array(
					'required'          => true,
					'type'              => 'string',
					'sanitize_callback' => 'esc_url_raw',
				),
			)
		);
		$this->route( '/content-sync/log', 'GET', array( $this, 'list_log' ) );
	}

	/**
	 * List all registered sites.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function list_sites( WP_REST_Request $request ) {
		return $this->ok( $this->module->list_sites() );
	}

	/**
	 * Register a new site.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function create_site( WP_REST_Request $request ) {
		$name = (string) $request->get_param( 'name' );
		$url  = (string) $request->get_param( 'url' );
		return $this->ok( $this->module->create_site( $name, $url ) );
	}

	/**
	 * List the last 100 sync log rows.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function list_log( WP_REST_Request $request ) {
		return $this->ok( $this->module->list_log() );
	}
}
