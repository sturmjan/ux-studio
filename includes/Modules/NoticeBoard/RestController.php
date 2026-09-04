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
 * Admin (manage_options) routes:
 *   GET    uxstudio/v1/notice-board/notices             - list notices
 *   POST   uxstudio/v1/notice-board/notices             - create a notice
 *   GET    uxstudio/v1/notice-board/notices/{id}        - one notice
 *   PUT    uxstudio/v1/notice-board/notices/{id}        - update a notice
 *   DELETE uxstudio/v1/notice-board/notices/{id}        - delete a notice
 *   GET    uxstudio/v1/notice-board/categories          - list categories
 *   POST   uxstudio/v1/notice-board/categories          - create a category
 *   PUT    uxstudio/v1/notice-board/categories/{id}     - update a category
 *   DELETE uxstudio/v1/notice-board/categories/{id}     - delete a category
 *   GET    uxstudio/v1/notice-board/subscribers         - list subscribers
 *   DELETE uxstudio/v1/notice-board/subscribers/{id}    - delete a subscriber
 *
 * Public (token/rate-limited) routes:
 *   POST   uxstudio/v1/notice-board/subscribe           - double opt-in subscribe
 *   GET    uxstudio/v1/notice-board/confirm/{token}     - confirm a subscription
 *   GET    uxstudio/v1/notice-board/unsubscribe/{token} - remove a subscription
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
		// Notices.
		$this->route( '/notice-board/notices', 'GET', array( $this, 'list_notices' ) );
		$this->route(
			'/notice-board/notices',
			'POST',
			array( $this, 'create_notice' ),
			array(
				'title'        => array(
					'required'          => true,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
				),
				'body'         => array( 'required' => false ),
				'category'     => array( 'required' => false ),
				'reference'    => array( 'required' => false ),
				'attachments'  => array( 'required' => false ),
				'publish_date' => array( 'required' => false ),
				'expiry_date'  => array( 'required' => false ),
				'is_archived'  => array( 'required' => false ),
			)
		);
		$this->route( '/notice-board/notices/(?P<id>\d+)', 'GET', array( $this, 'get_notice' ) );
		$this->route( '/notice-board/notices/(?P<id>\d+)', 'PUT, PATCH', array( $this, 'update_notice' ) );
		$this->route( '/notice-board/notices/(?P<id>\d+)', 'DELETE', array( $this, 'delete_notice' ) );

		// Categories.
		$this->route( '/notice-board/categories', 'GET', array( $this, 'list_categories' ) );
		$this->route(
			'/notice-board/categories',
			'POST',
			array( $this, 'create_category' ),
			array(
				'name'       => array(
					'required'          => true,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
				),
				'sort_order' => array( 'required' => false ),
			)
		);
		$this->route( '/notice-board/categories/(?P<id>\d+)', 'PUT, PATCH', array( $this, 'update_category' ) );
		$this->route( '/notice-board/categories/(?P<id>\d+)', 'DELETE', array( $this, 'delete_category' ) );

		// Subscribers.
		$this->route( '/notice-board/subscribers', 'GET', array( $this, 'list_subscribers' ) );
		$this->route( '/notice-board/subscribers/(?P<id>\d+)', 'DELETE', array( $this, 'delete_subscriber' ) );

		// Public endpoints - registered directly (bypass the capability gate).
		register_rest_route(
			self::NS,
			'/notice-board/subscribe',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'subscribe' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'email'      => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_email',
					),
					'categories' => array( 'required' => false ),
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

	/* =============================== Notices =============================== */

	/**
	 * @param WP_REST_Request $request Request.
	 */
	public function list_notices( WP_REST_Request $request ) {
		$include_archived = '0' !== (string) $request->get_param( 'include_archived' );
		return $this->ok( $this->module->list_notices( $include_archived ) );
	}

	/**
	 * @param WP_REST_Request $request Request.
	 */
	public function get_notice( WP_REST_Request $request ) {
		$notice = $this->module->get_notice( absint( $request->get_param( 'id' ) ) );
		if ( null === $notice ) {
			return new WP_Error( 'uxstudio_not_found', __( 'Notice not found.', 'ux-studio' ), array( 'status' => 404 ) );
		}
		return $this->ok( $notice );
	}

	/**
	 * @param WP_REST_Request $request Request.
	 */
	public function create_notice( WP_REST_Request $request ) {
		$result = $this->module->create_notice( $this->notice_input( $request ) );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return $this->ok( $result );
	}

	/**
	 * @param WP_REST_Request $request Request.
	 */
	public function update_notice( WP_REST_Request $request ) {
		$result = $this->module->update_notice( absint( $request->get_param( 'id' ) ), $this->notice_input( $request ) );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return $this->ok( $result );
	}

	/**
	 * @param WP_REST_Request $request Request.
	 */
	public function delete_notice( WP_REST_Request $request ) {
		if ( ! $this->module->delete_notice( absint( $request->get_param( 'id' ) ) ) ) {
			return new WP_Error( 'uxstudio_not_found', __( 'Notice not found.', 'ux-studio' ), array( 'status' => 404 ) );
		}
		return $this->ok( array( 'deleted' => true ) );
	}

	/**
	 * Collect only the notice fields that were actually sent, so PUT stays a
	 * partial update.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array<string, mixed>
	 */
	private function notice_input( WP_REST_Request $request ): array {
		$keys = array( 'title', 'body', 'category', 'reference', 'attachments', 'publish_date', 'expiry_date', 'is_archived' );
		$data = array();
		$json = $request->get_json_params();
		$json = is_array( $json ) ? $json : array();
		foreach ( $keys as $key ) {
			if ( null !== $request->get_param( $key ) || array_key_exists( $key, $json ) ) {
				$data[ $key ] = $request->get_param( $key );
			}
		}
		return $data;
	}

	/* ============================= Categories ============================= */

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
		$category = $this->module->create_category(
			(string) $request->get_param( 'name' ),
			(int) $request->get_param( 'sort_order' )
		);
		if ( null === $category ) {
			return new WP_Error( 'uxstudio_category_exists', __( 'A category with this name already exists.', 'ux-studio' ), array( 'status' => 400 ) );
		}
		return $this->ok( $category );
	}

	/**
	 * @param WP_REST_Request $request Request.
	 */
	public function update_category( WP_REST_Request $request ) {
		$data = array();
		if ( null !== $request->get_param( 'name' ) ) {
			$data['name'] = $request->get_param( 'name' );
		}
		if ( null !== $request->get_param( 'sort_order' ) ) {
			$data['sort_order'] = $request->get_param( 'sort_order' );
		}
		$category = $this->module->update_category( absint( $request->get_param( 'id' ) ), $data );
		if ( null === $category ) {
			return new WP_Error( 'uxstudio_not_found', __( 'Category not found.', 'ux-studio' ), array( 'status' => 404 ) );
		}
		return $this->ok( $category );
	}

	/**
	 * @param WP_REST_Request $request Request.
	 */
	public function delete_category( WP_REST_Request $request ) {
		if ( ! $this->module->delete_category( absint( $request->get_param( 'id' ) ) ) ) {
			return new WP_Error( 'uxstudio_not_found', __( 'Category not found.', 'ux-studio' ), array( 'status' => 404 ) );
		}
		return $this->ok( array( 'deleted' => true ) );
	}

	/* ============================ Subscribers ============================ */

	/**
	 * @param WP_REST_Request $request Request.
	 */
	public function list_subscribers( WP_REST_Request $request ) {
		return $this->ok( $this->module->list_subscriptions() );
	}

	/**
	 * @param WP_REST_Request $request Request.
	 */
	public function delete_subscriber( WP_REST_Request $request ) {
		if ( ! $this->module->delete_subscription( absint( $request->get_param( 'id' ) ) ) ) {
			return new WP_Error( 'uxstudio_not_found', __( 'Subscriber not found.', 'ux-studio' ), array( 'status' => 404 ) );
		}
		return $this->ok( array( 'deleted' => true ) );
	}

	/* ============================== Public =============================== */

	/**
	 * Public subscribe endpoint. Always returns a generic success response so
	 * it can't be used to enumerate subscriber addresses.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function subscribe( WP_REST_Request $request ): WP_REST_Response {
		$email      = (string) $request->get_param( 'email' );
		$categories = $request->get_param( 'categories' );
		$categories = is_array( $categories ) ? $categories : array();

		if ( $this->check_subscribe_rate_limit() ) {
			$this->module->subscribe( $email, $categories );
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
		return $this->ok(
			array(
				'confirmed' => $confirmed,
				'message'   => $confirmed
					? __( 'Your subscription is confirmed.', 'ux-studio' )
					: __( 'This confirmation link is invalid or has expired.', 'ux-studio' ),
			)
		);
	}

	/**
	 * Public unsubscribe endpoint.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function unsubscribe( WP_REST_Request $request ): WP_REST_Response {
		$unsubscribed = $this->module->unsubscribe( (string) $request->get_param( 'token' ) );
		return $this->ok(
			array(
				'unsubscribed' => $unsubscribed,
				'message'      => $unsubscribed
					? __( 'You have been unsubscribed.', 'ux-studio' )
					: __( 'This unsubscribe link is invalid or has expired.', 'ux-studio' ),
			)
		);
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
