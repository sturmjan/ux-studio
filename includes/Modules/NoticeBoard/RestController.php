<?php
/**
 * Notice Board REST controller.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\NoticeBoard;

use UxStudio\Rest\Controller;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

/**
 * GET    uxstudio/v1/notice-board/documents          - list documents (manage_options)
 * POST   uxstudio/v1/notice-board/documents          - create a document (manage_options)
 * DELETE uxstudio/v1/notice-board/documents/{id}     - delete a document (manage_options)
 * GET    uxstudio/v1/notice-board/categories         - list categories (manage_options)
 * POST   uxstudio/v1/notice-board/categories         - create a category (manage_options)
 * DELETE uxstudio/v1/notice-board/categories/{id}    - delete a category (manage_options)
 * GET    uxstudio/v1/notice-board/subscribers        - list subscribers (manage_options)
 * POST   uxstudio/v1/notice-board/subscribe          - PUBLIC, rate limited, double opt-in
 * GET    uxstudio/v1/notice-board/confirm/{token}    - PUBLIC, confirms a subscription
 * GET    uxstudio/v1/notice-board/unsubscribe/{token} - PUBLIC, removes a subscription
 */
final class RestController extends Controller {

	private const SUBSCRIBE_RATE_LIMIT  = 5;
	private const SUBSCRIBE_RATE_WINDOW = 300;

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
		$this->route( '/notice-board/documents', 'GET', array( $this, 'list_documents' ) );
		$this->route(
			'/notice-board/documents',
			'POST',
			array( $this, 'create_document' ),
			array(
				'title'         => array(
					'required'          => true,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
				),
				'category'      => array(
					'required' => false,
					'type'     => 'string',
				),
				'attachment_id' => array(
					'required' => false,
					'type'     => 'integer',
				),
			)
		);
		$this->route( '/notice-board/documents/(?P<id>\d+)', 'DELETE', array( $this, 'delete_document' ) );

		$this->route( '/notice-board/categories', 'GET', array( $this, 'list_categories' ) );
		$this->route(
			'/notice-board/categories',
			'POST',
			array( $this, 'create_category' ),
			array(
				'name' => array(
					'required'          => true,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
				),
			)
		);
		$this->route( '/notice-board/categories/(?P<id>\d+)', 'DELETE', array( $this, 'delete_category' ) );

		$this->route( '/notice-board/subscribers', 'GET', array( $this, 'list_subscribers' ) );

		// Public endpoints - registered directly (bypass the capability gate).
		register_rest_route(
			self::NS,
			'/notice-board/subscribe',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'subscribe' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'email' => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_email',
					),
				),
			)
		);
		register_rest_route(
			self::NS,
			'/notice-board/confirm/(?P<token>[a-zA-Z0-9]+)',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'confirm' ),
				'permission_callback' => '__return_true',
			)
		);
		register_rest_route(
			self::NS,
			'/notice-board/unsubscribe/(?P<token>[a-zA-Z0-9]+)',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'unsubscribe' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	// =====================================================================
	// Documents
	// =====================================================================

	/**
	 * @param WP_REST_Request $request Request.
	 */
	public function list_documents( WP_REST_Request $request ) {
		return $this->ok( $this->module->list_documents() );
	}

	/**
	 * @param WP_REST_Request $request Request.
	 */
	public function create_document( WP_REST_Request $request ) {
		$data = array(
			'title'         => (string) $request->get_param( 'title' ),
			'category'      => (string) $request->get_param( 'category' ),
			'attachment_id' => (int) $request->get_param( 'attachment_id' ),
		);
		return $this->ok( $this->module->create_document( $data ) );
	}

	/**
	 * @param WP_REST_Request $request Request.
	 */
	public function delete_document( WP_REST_Request $request ) {
		$id = absint( $request->get_param( 'id' ) );
		if ( ! $this->module->delete_document( $id ) ) {
			return new WP_Error( 'uxstudio_not_found', __( 'Document not found.', 'ux-studio' ), array( 'status' => 404 ) );
		}
		return $this->ok( array( 'deleted' => true ) );
	}

	// =====================================================================
	// Categories
	// =====================================================================

	/**
	 * @param WP_REST_Request $request Request.
	 */
	public function list_categories( WP_REST_Request $request ) {
		return $this->ok( $this->module->list_categories() );
	}

	/**
	 * @param WP_REST_Request $request Request.
	 */
	public function create_category( WP_REST_Request $request ) {
		$category = $this->module->create_category( (string) $request->get_param( 'name' ) );
		if ( null === $category ) {
			return new WP_Error( 'uxstudio_category_exists', __( 'A category with this name already exists.', 'ux-studio' ), array( 'status' => 400 ) );
		}
		return $this->ok( $category );
	}

	/**
	 * @param WP_REST_Request $request Request.
	 */
	public function delete_category( WP_REST_Request $request ) {
		$id = absint( $request->get_param( 'id' ) );
		if ( ! $this->module->delete_category( $id ) ) {
			return new WP_Error( 'uxstudio_not_found', __( 'Category not found.', 'ux-studio' ), array( 'status' => 404 ) );
		}
		return $this->ok( array( 'deleted' => true ) );
	}

	// =====================================================================
	// Subscribers
	// =====================================================================

	/**
	 * @param WP_REST_Request $request Request.
	 */
	public function list_subscribers( WP_REST_Request $request ) {
		return $this->ok( $this->module->list_subscriptions() );
	}

	/**
	 * Public subscribe endpoint. Always returns a generic success response
	 * regardless of whether the address was new/existing/invalid/rate-limited,
	 * so the endpoint can't be used to enumerate subscriber addresses.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function subscribe( WP_REST_Request $request ): WP_REST_Response {
		$email = (string) $request->get_param( 'email' );

		if ( $this->check_subscribe_rate_limit() ) {
			$this->module->subscribe( $email );
		}

		return $this->ok( array( 'ok' => true ) );
	}

	/**
	 * Public confirm endpoint.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function confirm( WP_REST_Request $request ): WP_REST_Response {
		$confirmed = $this->module->confirm( (string) $request->get_param( 'token' ) );
		return $this->ok( array( 'confirmed' => $confirmed ) );
	}

	/**
	 * Public unsubscribe endpoint.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function unsubscribe( WP_REST_Request $request ): WP_REST_Response {
		$unsubscribed = $this->module->unsubscribe( (string) $request->get_param( 'token' ) );
		return $this->ok( array( 'unsubscribed' => $unsubscribed ) );
	}

	/**
	 * Sliding-window rate limit for the public subscribe endpoint, keyed by a
	 * salted hash of the client IP (never the raw IP).
	 */
	private function check_subscribe_rate_limit(): bool {
		$ip  = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : '';
		$key = 'uxstudio_nb_rl_' . md5( $ip . wp_salt() );

		$count = (int) get_transient( $key );
		if ( $count >= self::SUBSCRIBE_RATE_LIMIT ) {
			return false;
		}
		set_transient( $key, $count + 1, self::SUBSCRIBE_RATE_WINDOW );
		return true;
	}
}
