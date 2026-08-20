<?php
/**
 * Popup Manager REST controller.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\PopupManager;

use UxStudio\Rest\Controller;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

/**
 * GET    uxstudio/v1/popup-manager/popups       - list all popups
 * POST   uxstudio/v1/popup-manager/popups       - create a popup
 * POST   uxstudio/v1/popup-manager/popups/{id}  - update a popup
 * DELETE uxstudio/v1/popup-manager/popups/{id}  - delete a popup
 * GET    uxstudio/v1/popup-manager/stats/{id}   - aggregated stats for one popup
 * POST   uxstudio/v1/popup-manager/track        - PUBLIC, records a shown/closed/clicked event
 */
final class RestController extends Controller {

	/**
	 * Max track events accepted per IP-hash per rate limit window.
	 */
	private const TRACK_RATE_LIMIT  = 30;
	private const TRACK_RATE_WINDOW = 60;

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
		$this->route( '/popup-manager/popups', 'GET', array( $this, 'list_popups' ) );
		$this->route( '/popup-manager/popups', 'POST', array( $this, 'create_popup' ) );
		$this->route(
			'/popup-manager/popups/(?P<id>\d+)',
			'POST',
			array( $this, 'update_popup' ),
			array(
				'id' => array(
					'required' => true,
					'type'     => 'integer',
				),
			)
		);
		$this->route(
			'/popup-manager/popups/(?P<id>\d+)',
			'DELETE',
			array( $this, 'delete_popup' ),
			array(
				'id' => array(
					'required' => true,
					'type'     => 'integer',
				),
			)
		);
		$this->route(
			'/popup-manager/stats/(?P<id>\d+)',
			'GET',
			array( $this, 'stats' ),
			array(
				'id' => array(
					'required' => true,
					'type'     => 'integer',
				),
			)
		);

		// Public tracking endpoint - bypasses the capability gate on purpose,
		// so it is registered directly instead of through $this->route().
		register_rest_route(
			self::NS,
			'/popup-manager/track',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'track' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'popup_id' => array(
						'required' => true,
						'type'     => 'integer',
					),
					'event'    => array(
						'required' => true,
						'type'     => 'string',
					),
				),
			)
		);
	}

	/**
	 * List all popups.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function list_popups( WP_REST_Request $request ) {
		return $this->ok( $this->module->list_popups() );
	}

	/**
	 * Create a popup.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function create_popup( WP_REST_Request $request ) {
		$data   = (array) $request->get_json_params();
		$popup  = $this->module->create_popup( $data );
		if ( null === $popup ) {
			return new WP_Error( 'uxstudio_popup_create_failed', __( 'Could not create the popup.', 'ux-studio' ), array( 'status' => 400 ) );
		}
		return $this->ok( $popup );
	}

	/**
	 * Update a popup.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function update_popup( WP_REST_Request $request ) {
		$id    = (int) $request->get_param( 'id' );
		$data  = (array) $request->get_json_params();
		$popup = $this->module->update_popup( $id, $data );
		if ( null === $popup ) {
			return new WP_Error( 'uxstudio_popup_not_found', __( 'Popup not found.', 'ux-studio' ), array( 'status' => 404 ) );
		}
		return $this->ok( $popup );
	}

	/**
	 * Delete a popup.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function delete_popup( WP_REST_Request $request ) {
		$id      = (int) $request->get_param( 'id' );
		$deleted = $this->module->delete_popup( $id );
		if ( ! $deleted ) {
			return new WP_Error( 'uxstudio_popup_not_found', __( 'Popup not found.', 'ux-studio' ), array( 'status' => 404 ) );
		}
		return $this->ok( array( 'deleted' => true ) );
	}

	/**
	 * Aggregated stats for one popup.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function stats( WP_REST_Request $request ) {
		$id = (int) $request->get_param( 'id' );
		if ( null === $this->module->get_popup( $id ) ) {
			return new WP_Error( 'uxstudio_popup_not_found', __( 'Popup not found.', 'ux-studio' ), array( 'status' => 404 ) );
		}
		return $this->ok( $this->module->get_stats( $id ) );
	}

	/**
	 * Public tracking endpoint. Validates popup_id/event strictly and applies
	 * its own per-IP-hash rate limit (independent from the write-endpoint
	 * limiter in Controller::route(), which this endpoint bypasses since it
	 * has no logged-in user). The raw IP is never stored - only a salted
	 * hash is used as the transient key. Once the limiter trips, the insert
	 * is silently skipped but a normal ok() response is still returned so
	 * abusers can't detect the rate limit state.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function track( WP_REST_Request $request ): WP_REST_Response {
		$popup_id = absint( $request->get_param( 'popup_id' ) );
		$event    = sanitize_key( (string) $request->get_param( 'event' ) );

		$valid_event = in_array( $event, array( 'shown', 'closed', 'clicked' ), true );
		$post        = $popup_id ? get_post( $popup_id ) : null;
		$valid_popup = $post && Module::POST_TYPE === $post->post_type;

		if ( $valid_event && $valid_popup && $this->check_track_rate_limit() ) {
			$this->module->track_event( $popup_id, $event );
		}

		return $this->ok( array( 'ok' => true ) );
	}

	/**
	 * Sliding-window rate limit for the public track endpoint, keyed by a
	 * salted hash of the client IP (never the raw IP) so the table can't be
	 * grown unbounded by a flood of requests.
	 */
	private function check_track_rate_limit(): bool {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : '';
		$key = 'uxstudio_pm_rl_' . md5( $ip . wp_salt() );

		$count = (int) get_transient( $key );
		if ( $count >= self::TRACK_RATE_LIMIT ) {
			return false;
		}
		set_transient( $key, $count + 1, self::TRACK_RATE_WINDOW );
		return true;
	}
}
