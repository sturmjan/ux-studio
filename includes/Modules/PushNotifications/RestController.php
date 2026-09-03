<?php
/**
 * Push Notifications REST controller.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\PushNotifications;

use UxStudio\Rest\Controller;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

/**
 * GET  uxstudio/v1/push-notifications/vapid-key              - PUBLIC, public VAPID key only
 * POST uxstudio/v1/push-notifications/subscribe               - PUBLIC, rate limited
 * POST uxstudio/v1/push-notifications/event                   - PUBLIC, rate limited
 * POST uxstudio/v1/push-notifications/vapid/generate           - manage_options, (re)generate keypair
 * GET  uxstudio/v1/push-notifications/subscribers               - manage_options
 * GET  uxstudio/v1/push-notifications/notifications              - manage_options
 * POST uxstudio/v1/push-notifications/notifications              - manage_options
 * GET  uxstudio/v1/push-notifications/analytics                  - manage_options, delivery/click totals
 * POST uxstudio/v1/push-notifications/notifications/{id}/send    - manage_options, real delivery (immediate or scheduled)
 */
final class RestController extends Controller {

	private const PUBLIC_RATE_LIMIT  = 20;
	private const PUBLIC_RATE_WINDOW = 60;

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
		// Public endpoints - registered directly (bypass the capability gate),
		// each with its own IP-hash rate limit.
		register_rest_route(
			self::NS,
			'/push-notifications/vapid-key',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'vapid_key' ),
				'permission_callback' => '__return_true',
			)
		);
		register_rest_route(
			self::NS,
			'/push-notifications/subscribe',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'subscribe' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'endpoint' => array( 'required' => true, 'type' => 'string' ),
					'p256dh'   => array( 'required' => true, 'type' => 'string' ),
					'auth'     => array( 'required' => true, 'type' => 'string' ),
				),
			)
		);
		register_rest_route(
			self::NS,
			'/push-notifications/event',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'event' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'endpoint'        => array( 'required' => true, 'type' => 'string' ),
					'notification_id' => array( 'required' => false, 'type' => 'integer' ),
					'event'           => array( 'required' => true, 'type' => 'string' ),
				),
			)
		);

		$this->route( '/push-notifications/vapid/generate', 'POST', array( $this, 'generate_vapid' ) );
		$this->route( '/push-notifications/subscribers', 'GET', array( $this, 'list_subscribers' ) );
		$this->route( '/push-notifications/analytics', 'GET', array( $this, 'analytics' ) );
		$this->route( '/push-notifications/notifications', 'GET', array( $this, 'list_notifications' ) );
		$this->route(
			'/push-notifications/notifications',
			'POST',
			array( $this, 'create_notification' ),
			array(
				'title'   => array(
					'required'          => true,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
				),
				'body'    => array(
					'required' => false,
					'type'     => 'string',
				),
				'url'     => array(
					'required'          => false,
					'type'              => 'string',
					'sanitize_callback' => 'esc_url_raw',
				),
				'icon'    => array(
					'required'          => false,
					'type'              => 'string',
					'sanitize_callback' => 'esc_url_raw',
				),
				'segment' => array(
					'required' => false,
					'type'     => 'string',
				),
			)
		);
		$this->route(
			'/push-notifications/notifications/(?P<id>\d+)/send',
			'POST',
			array( $this, 'send' ),
			array(
				'scheduled_at' => array(
					'required'          => false,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
				),
			)
		);
	}

	// =====================================================================
	// Public
	// =====================================================================

	/**
	 * @param WP_REST_Request $request Request.
	 */
	public function vapid_key( WP_REST_Request $request ): WP_REST_Response {
		return $this->ok( array( 'public_key' => $this->module->vapid_public_key() ) );
	}

	/**
	 * @param WP_REST_Request $request Request.
	 */
	public function subscribe( WP_REST_Request $request ): WP_REST_Response {
		if ( $this->check_public_rate_limit() ) {
			$this->module->subscribe(
				array(
					'endpoint'   => (string) $request->get_param( 'endpoint' ),
					'p256dh'     => (string) $request->get_param( 'p256dh' ),
					'auth'       => (string) $request->get_param( 'auth' ),
					'user_agent' => (string) $request->get_header( 'user-agent' ),
				)
			);
		}
		return $this->ok( array( 'ok' => true ) );
	}

	/**
	 * @param WP_REST_Request $request Request.
	 */
	public function event( WP_REST_Request $request ): WP_REST_Response {
		if ( $this->check_public_rate_limit() ) {
			$this->module->record_event(
				(string) $request->get_param( 'endpoint' ),
				absint( $request->get_param( 'notification_id' ) ),
				sanitize_key( (string) $request->get_param( 'event' ) )
			);
		}
		return $this->ok( array( 'ok' => true ) );
	}

	/**
	 * Sliding-window rate limit for the public endpoints, keyed by a salted
	 * hash of the client IP (never the raw IP).
	 */
	private function check_public_rate_limit(): bool {
		$ip  = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : '';
		$key = 'uxstudio_pn_rl_' . md5( $ip . wp_salt() );

		$count = (int) get_transient( $key );
		if ( $count >= self::PUBLIC_RATE_LIMIT ) {
			return false;
		}
		set_transient( $key, $count + 1, self::PUBLIC_RATE_WINDOW );
		return true;
	}

	// =====================================================================
	// Admin
	// =====================================================================

	/**
	 * @param WP_REST_Request $request Request.
	 */
	public function generate_vapid( WP_REST_Request $request ) {
		if ( ! $this->module->generate_vapid_keys() ) {
			return new WP_Error( 'uxstudio_vapid_failed', __( 'Could not generate a VAPID keypair (is the openssl PHP extension with EC support available?).', 'ux-studio' ), array( 'status' => 500 ) );
		}
		return $this->ok( array( 'public_key' => $this->module->vapid_public_key() ) );
	}

	/**
	 * @param WP_REST_Request $request Request.
	 */
	public function list_subscribers( WP_REST_Request $request ) {
		return $this->ok( $this->module->list_subscribers() );
	}

	/**
	 * @param WP_REST_Request $request Request.
	 */
	public function list_notifications( WP_REST_Request $request ) {
		return $this->ok( $this->module->list_notifications() );
	}

	/**
	 * @param WP_REST_Request $request Request.
	 */
	public function analytics( WP_REST_Request $request ) {
		return $this->ok( $this->module->analytics() );
	}

	/**
	 * @param WP_REST_Request $request Request.
	 */
	public function create_notification( WP_REST_Request $request ) {
		return $this->ok(
			$this->module->create_notification(
				array(
					'title'   => (string) $request->get_param( 'title' ),
					'body'    => (string) $request->get_param( 'body' ),
					'url'     => (string) $request->get_param( 'url' ),
					'icon'    => (string) $request->get_param( 'icon' ),
					'segment' => (string) $request->get_param( 'segment' ),
				)
			)
		);
	}

	/**
	 * @param WP_REST_Request $request Request.
	 */
	public function send( WP_REST_Request $request ) {
		$id     = absint( $request->get_param( 'id' ) );
		$result = $this->module->send_notification( $id, (string) $request->get_param( 'scheduled_at' ) );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return $this->ok( $result );
	}
}
