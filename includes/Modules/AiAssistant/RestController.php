<?php
/**
 * AI Assistant REST controller - foundation routes only. Later waves add
 * their own dedicated controllers (chat, knowledge base, handoff, blog
 * pilot, MCP) registered from Module::boot().
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\AiAssistant;

use UxStudio\Rest\Controller;
use WP_REST_Request;

defined( 'ABSPATH' ) || exit;

/**
 * GET  uxstudio/v1/ai-assistant/providers        - provider list + has_key flags
 * POST uxstudio/v1/ai-assistant/test-connection   - test a provider/model combination
 * GET  uxstudio/v1/ai-assistant/usage             - usage summary/stats + limit status
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
		$this->route( '/ai-assistant/providers', 'GET', array( $this, 'providers' ) );

		$this->route(
			'/ai-assistant/test-connection',
			'POST',
			array( $this, 'test_connection' ),
			array(
				'provider' => array( 'required' => true, 'type' => 'string' ),
				'model'    => array( 'required' => true, 'type' => 'string' ),
			)
		);

		$this->route(
			'/ai-assistant/usage',
			'GET',
			array( $this, 'usage' ),
			array(
				'days' => array( 'required' => false, 'type' => 'integer', 'sanitize_callback' => 'absint' ),
			)
		);
	}

	/**
	 * List providers with their models and has_key flags (never the key values themselves).
	 */
	public function providers(): mixed {
		$providers = ProviderFactory::get_all_providers();
		foreach ( $providers as $id => &$provider ) {
			$provider['has_key'] = ProviderFactory::has_api_key( $id );
		}
		unset( $provider );

		return $this->ok( $providers );
	}

	/**
	 * Test a provider/model combination using its currently stored API key.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function test_connection( WP_REST_Request $request ): mixed {
		$provider_id = (string) $request->get_param( 'provider' );
		$model       = (string) $request->get_param( 'model' );

		try {
			$result = ProviderFactory::create( $provider_id )->test_connection( $model );
		} catch ( \Throwable $e ) {
			$result = array(
				'success' => false,
				'error'   => $e->getMessage(),
			);
		}

		return $this->ok( $result );
	}

	/**
	 * Usage summary + per provider/model/feature breakdown + limit status.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function usage( WP_REST_Request $request ): mixed {
		$days = (int) $request->get_param( 'days' );
		$days = $days > 0 ? $days : 30;

		return $this->ok(
			array(
				'summary'      => UsageTracker::get_summary( $days ),
				'stats'        => UsageTracker::get_stats( $days ),
				'limit_status' => UsageLimiter::get_status(),
			)
		);
	}
}
