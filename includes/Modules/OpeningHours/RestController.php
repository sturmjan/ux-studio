<?php
/**
 * Opening Hours REST controller.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\OpeningHours;

use UxStudio\Rest\Controller;
use WP_Error;
use WP_REST_Request;

defined( 'ABSPATH' ) || exit;

/**
 * GET/POST     uxstudio/v1/opening-hours/locations
 * GET/POST/DEL uxstudio/v1/opening-hours/locations/{id}
 * GET          uxstudio/v1/opening-hours/locations/{id}/status
 * GET          uxstudio/v1/opening-hours/geocode
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
		$this->route( '/opening-hours/locations', 'GET', array( $this, 'list_locations' ) );
		$this->route(
			'/opening-hours/locations',
			'POST',
			array( $this, 'create_location' )
		);
		$this->route(
			'/opening-hours/locations/(?P<id>\d+)',
			'POST',
			array( $this, 'update_location' ),
			array( 'id' => array( 'required' => true, 'type' => 'integer' ) )
		);
		$this->route(
			'/opening-hours/locations/(?P<id>\d+)',
			'DELETE',
			array( $this, 'delete_location' ),
			array( 'id' => array( 'required' => true, 'type' => 'integer' ) )
		);
		// Status is intentionally public (used by frontend widgets) and
		// read-only (no secrets), so it bypasses $this->route()'s capability
		// gate the same way PopupManager's public /track endpoint does.
		register_rest_route(
			self::NS,
			'/opening-hours/locations/(?P<id>\d+)/status',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'status' ),
				'permission_callback' => '__return_true',
				'args'                => array( 'id' => array( 'required' => true, 'type' => 'integer' ) ),
			)
		);
		$this->route(
			'/opening-hours/geocode',
			'GET',
			array( $this, 'geocode' ),
			array( 'address' => array( 'required' => true, 'type' => 'string' ) )
		);
	}

	/**
	 * List all locations.
	 */
	public function list_locations(): mixed {
		return $this->ok( $this->module->list_locations() );
	}

	/**
	 * Create a location.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function create_location( WP_REST_Request $request ): mixed {
		$data = (array) $request->get_json_params();
		$loc  = $this->module->create_location( $data );
		if ( is_wp_error( $loc ) ) {
			return $loc;
		}
		return $this->ok( $loc );
	}

	/**
	 * Update a location.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function update_location( WP_REST_Request $request ): mixed {
		$id   = (int) $request->get_param( 'id' );
		$data = (array) $request->get_json_params();
		$loc  = $this->module->update_location( $id, $data );
		if ( is_wp_error( $loc ) ) {
			return $loc;
		}
		return $this->ok( $loc );
	}

	/**
	 * Delete a location.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function delete_location( WP_REST_Request $request ): mixed {
		$id = (int) $request->get_param( 'id' );
		if ( ! $this->module->delete_location( $id ) ) {
			return new WP_Error( 'uxstudio_oh_not_found', __( 'Location not found.', 'ux-studio' ), array( 'status' => 404 ) );
		}
		return $this->ok( array( 'deleted' => true ) );
	}

	/**
	 * Live open/closed status for one location. Public on purpose (frontend
	 * widgets read this without authentication) - read-only, no secrets.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function status( WP_REST_Request $request ): mixed {
		$id     = (int) $request->get_param( 'id' );
		$status = $this->module->compute_status( $id );
		if ( is_wp_error( $status ) ) {
			return $status;
		}
		return $this->ok( $status );
	}

	/**
	 * Geocode an address via the configured map provider (424 if none set).
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function geocode( WP_REST_Request $request ): mixed {
		$result = $this->module->geocode( (string) $request->get_param( 'address' ) );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return $this->ok( $result );
	}
}
