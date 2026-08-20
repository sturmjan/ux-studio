<?php
/**
 * Google Review Request REST controller.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\GoogleReviewRequest;

use UxStudio\Rest\Controller;
use WP_REST_Request;

defined( 'ABSPATH' ) || exit;

/**
 * POST uxstudio/v1/google-review-request/send  - send a review request email
 * GET  uxstudio/v1/google-review-request/stats - paginated delivery stats
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
		$this->route(
			'/google-review-request/send',
			'POST',
			array( $this, 'send' ),
			array(
				'email'    => array(
					'required'          => true,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_email',
					'validate_callback' => static fn( $value ) => is_email( $value ),
				),
				'order_id' => array(
					'required'          => false,
					'type'              => 'integer',
					'sanitize_callback' => 'absint',
				),
			)
		);

		$this->route(
			'/google-review-request/stats',
			'GET',
			array( $this, 'stats' ),
			array(
				'limit'  => array(
					'required'          => false,
					'type'              => 'integer',
					'sanitize_callback' => 'absint',
				),
				'offset' => array(
					'required'          => false,
					'type'              => 'integer',
					'sanitize_callback' => 'absint',
				),
			)
		);
	}

	/**
	 * Send a review request email.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function send( WP_REST_Request $request ) {
		$email    = (string) $request->get_param( 'email' );
		$order_id = $request->get_param( 'order_id' );
		$order_id = null === $order_id ? null : (int) $order_id;

		return $this->ok( $this->module->send_request( $email, $order_id ) );
	}

	/**
	 * Paginated delivery stats.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function stats( WP_REST_Request $request ) {
		$limit  = null !== $request->get_param( 'limit' ) ? (int) $request->get_param( 'limit' ) : 50;
		$offset = null !== $request->get_param( 'offset' ) ? (int) $request->get_param( 'offset' ) : 0;

		return $this->ok( $this->module->get_stats( $limit, $offset ) );
	}
}
