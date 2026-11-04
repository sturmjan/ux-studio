<?php
/**
 * Maps REST controller.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\Maps;

use UxStudio\Rest\Controller;
use WP_REST_Request;

defined( 'ABSPATH' ) || exit;

/**
 * GET      uxstudio/v1/maps                  - list
 * POST     uxstudio/v1/maps                  - create {title, config}
 * POST     uxstudio/v1/maps/{id}             - update {title, config}
 * DELETE   uxstudio/v1/maps/{id}             - delete
 * GET      uxstudio/v1/maps/editor-config    - providers, styles, keys for the editor preview
 * GET      uxstudio/v1/maps/geocode?q=       - address search
 * GET      uxstudio/v1/maps/location-markers - pins from Opening Hours locations
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

		$this->route( '/maps', 'GET', fn () => $this->ok( $this->module->list_maps() ) );
		$this->route( '/maps', 'POST', array( $this, 'create' ) );
		$this->route( '/maps/(?P<id>\d+)', 'POST', array( $this, 'update' ), $id );
		$this->route( '/maps/(?P<id>\d+)', 'DELETE', array( $this, 'delete' ), $id );
		$this->route( '/maps/editor-config', 'GET', array( $this, 'editor_config' ) );
		$this->route( '/maps/geocode', 'GET', array( $this, 'geocode' ), array( 'q' => array( 'required' => true, 'type' => 'string' ) ) );
		$this->route( '/maps/location-markers', 'GET', fn () => $this->ok( $this->module->location_markers() ) );
	}

	/**
	 * @param WP_REST_Request $request Request.
	 */
	public function create( WP_REST_Request $request ): mixed {
		$map = $this->module->save_map( 0, (array) $request->get_json_params() );
		return is_wp_error( $map ) ? $map : $this->ok( $map );
	}

	/**
	 * @param WP_REST_Request $request Request.
	 */
	public function update( WP_REST_Request $request ): mixed {
		$map = $this->module->save_map( (int) $request['id'], (array) $request->get_json_params() );
		return is_wp_error( $map ) ? $map : $this->ok( $map );
	}

	/**
	 * @param WP_REST_Request $request Request.
	 */
	public function delete( WP_REST_Request $request ): mixed {
		$result = $this->module->delete_map( (int) $request['id'] );
		return is_wp_error( $result ) ? $result : $this->ok( array( 'deleted' => true ) );
	}

	/**
	 * Everything the editor preview needs. The keys are browser keys that the
	 * front end exposes anyway; this route is admin-only like the rest.
	 */
	public function editor_config(): mixed {
		$keys = array();
		foreach ( Module::PROVIDERS as $provider ) {
			$keys[ $provider ] = $this->module->provider_key( $provider );
		}
		return $this->ok(
			array(
				'providers'        => $this->module->provider_options(),
				'available'        => $this->module->available_providers(),
				'default_provider' => $this->module->resolve_provider( 'default' ),
				'styles'           => Module::STYLES,
				'keys'             => $keys,
				'defaults'         => $this->module->default_config(),
				'leaflet_css'      => UXSTUDIO_URL . 'assets/vendor/leaflet/leaflet.css',
			)
		);
	}

	/**
	 * @param WP_REST_Request $request Request.
	 */
	public function geocode( WP_REST_Request $request ): mixed {
		$result = $this->module->geocode( (string) $request['q'] );
		return is_wp_error( $result ) ? $result : $this->ok( $result );
	}
}
