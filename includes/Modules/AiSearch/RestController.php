<?php
/**
 * Public REST endpoints of the AI Search module.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\AiSearch;

use UxStudio\Modules\AiAssistant\PublicGuard;
use WP_REST_Request;
use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

/**
 * GET uxstudio/v1/ai-search/suggest?q=   - dropdown HTML
 * GET uxstudio/v1/ai-search/search?q=&type=&page=&layout= - results body HTML
 *
 * Both are public (it is a site search), read-only, rate limited per client IP
 * and only ever return markup built by Renderer.
 */
final class RestController {

	private const NS = 'uxstudio/v1';

	/** Requests per minute and client IP. */
	private const RATE_LIMIT = 90;

	private Module $module;

	public function __construct( Module $module ) {
		$this->module = $module;
	}

	public function register_routes(): void {
		$args = array(
			'q' => array(
				'required'          => true,
				'type'              => 'string',
				'sanitize_callback' => array( SearchService::class, 'clean_query' ),
			),
		);

		register_rest_route(
			self::NS,
			'/ai-search/suggest',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'suggest' ),
				'permission_callback' => '__return_true',
				'args'                => $args,
			)
		);

		register_rest_route(
			self::NS,
			'/ai-search/search',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'search' ),
				'permission_callback' => '__return_true',
				'args'                => $args + array(
					'type'   => array(
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_key',
					),
					'page'   => array(
						'type'    => 'integer',
						'default' => 1,
					),
					'layout' => array(
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_key',
					),
				),
			)
		);
	}

	/**
	 * @return WP_REST_Response
	 */
	public function suggest( WP_REST_Request $request ) {
		$limited = $this->limited();
		if ( $limited ) {
			return $limited;
		}

		$q     = (string) $request['q'];
		$items = SearchService::suggest( $q, $this->module->suggest_limit() );

		return $this->respond(
			array(
				'html'  => ( new Renderer( $this->module ) )->suggestions( $items, $q ),
				'count' => count( $items ),
			)
		);
	}

	/**
	 * @return WP_REST_Response
	 */
	public function search( WP_REST_Request $request ) {
		$limited = $this->limited();
		if ( $limited ) {
			return $limited;
		}

		$q        = (string) $request['q'];
		$page     = max( 1, min( 20, (int) $request['page'] ) );
		$type     = (string) $request['type'];
		$layout   = $this->module->layout( (string) $request['layout'] );
		$renderer = new Renderer( $this->module );

		return $this->respond(
			array(
				'html' => $renderer->results_body( $q, $page, $type, $layout ),
			)
		);
	}

	private function limited(): ?WP_REST_Response {
		if ( PublicGuard::hit( 'search', self::RATE_LIMIT, MINUTE_IN_SECONDS ) ) {
			return null;
		}
		$response = new WP_REST_Response( array( 'code' => 'rate_limited' ), 429 );
		$response->header( 'Retry-After', '30' );
		return $response;
	}

	/**
	 * @param array<string, mixed> $data Payload.
	 */
	private function respond( array $data ): WP_REST_Response {
		$response = new WP_REST_Response( $data );
		$response->header( 'Cache-Control', 'private, max-age=60' );
		return $response;
	}
}
