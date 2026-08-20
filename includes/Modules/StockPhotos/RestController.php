<?php
/**
 * Stock Photos REST controller.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\StockPhotos;

use UxStudio\Rest\Controller;
use WP_REST_Request;

defined( 'ABSPATH' ) || exit;

/**
 * GET  uxstudio/v1/stock-photos/providers - provider list + has_key flags
 * GET  uxstudio/v1/stock-photos/search    - proxy a provider search
 * POST uxstudio/v1/stock-photos/import    - sideload a result into the media library
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
		$this->route( '/stock-photos/providers', 'GET', array( $this, 'providers' ) );
		$this->route(
			'/stock-photos/search',
			'GET',
			array( $this, 'search' ),
			array(
				'provider' => array( 'required' => true, 'type' => 'string' ),
				'query'    => array( 'required' => true, 'type' => 'string' ),
				'page'     => array( 'required' => false, 'type' => 'integer', 'sanitize_callback' => 'absint' ),
			),
			'upload_files'
		);
		$this->route(
			'/stock-photos/import',
			'POST',
			array( $this, 'import' ),
			array(
				'provider'   => array( 'required' => true, 'type' => 'string' ),
				'image_url'  => array( 'required' => true, 'type' => 'string' ),
				'title'      => array( 'required' => false, 'type' => 'string' ),
			),
			'upload_files'
		);
	}

	/**
	 * List providers with has_key flags (never the key values themselves).
	 */
	public function providers(): mixed {
		return $this->ok( $this->module->providers() );
	}

	/**
	 * Proxy a provider search.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function search( WP_REST_Request $request ): mixed {
		$page   = max( 1, (int) $request->get_param( 'page' ) );
		$result = $this->module->search(
			(string) $request->get_param( 'provider' ),
			(string) $request->get_param( 'query' ),
			$page
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return $this->ok( $result );
	}

	/**
	 * Sideload a result into the media library (SSRF-hardened in Module::import()).
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function import( WP_REST_Request $request ): mixed {
		$result = $this->module->import(
			(string) $request->get_param( 'provider' ),
			(string) $request->get_param( 'image_url' ),
			(string) $request->get_param( 'title' )
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return $this->ok( $result );
	}
}
