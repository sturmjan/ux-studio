<?php
/**
 * Review Aggregator REST controller.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\ReviewAggregator;

use UxStudio\Rest\Controller;
use WP_Error;
use WP_REST_Request;

defined( 'ABSPATH' ) || exit;

/**
 * GET  uxstudio/v1/review-aggregator/reviews                    - list reviews (filterable)
 * POST uxstudio/v1/review-aggregator/fetch                      - pull fresh reviews through the content-sync broker
 * POST uxstudio/v1/review-aggregator/reviews/{id}/toggle-visibility
 * GET  uxstudio/v1/review-aggregator/stats
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
			'/review-aggregator/reviews',
			'GET',
			array( $this, 'list_reviews' ),
			array(
				'source'     => array(
					'required'          => false,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
				),
				'visible'    => array(
					'required' => false,
					'type'     => 'boolean',
				),
				'min_rating' => array(
					'required'          => false,
					'type'              => 'integer',
					'sanitize_callback' => 'absint',
				),
			)
		);

		$this->route( '/review-aggregator/fetch', 'POST', array( $this, 'fetch' ) );

		$this->route(
			'/review-aggregator/reviews/(?P<id>\d+)/toggle-visibility',
			'POST',
			array( $this, 'toggle_visibility' )
		);

		$this->route( '/review-aggregator/stats', 'GET', array( $this, 'stats' ) );
	}

	/**
	 * List reviews, optionally filtered by source/visible/min_rating.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function list_reviews( WP_REST_Request $request ) {
		$filters = array();

		if ( null !== $request->get_param( 'source' ) && '' !== $request->get_param( 'source' ) ) {
			$filters['source'] = (string) $request->get_param( 'source' );
		}
		if ( null !== $request->get_param( 'visible' ) ) {
			$filters['visible'] = (bool) $request->get_param( 'visible' );
		}
		if ( null !== $request->get_param( 'min_rating' ) ) {
			$filters['min_rating'] = (int) $request->get_param( 'min_rating' );
		}

		return $this->ok( $this->module->list_reviews( $filters ) );
	}

	/**
	 * Trigger a broker fetch.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function fetch( WP_REST_Request $request ) {
		$result = $this->module->fetch();
		return is_wp_error( $result ) ? $result : $this->ok( $result );
	}

	/**
	 * Toggle a review's visibility.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function toggle_visibility( WP_REST_Request $request ) {
		$id     = absint( $request->get_param( 'id' ) );
		$result = $this->module->toggle_visibility( $id );
		return $result instanceof WP_Error ? $result : $this->ok( $result );
	}

	/**
	 * Aggregate stats.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function stats( WP_REST_Request $request ) {
		return $this->ok( $this->module->stats() );
	}
}
