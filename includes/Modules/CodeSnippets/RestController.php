<?php
/**
 * Code Snippets REST controller.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\CodeSnippets;

use UxStudio\Rest\Controller;
use WP_Error;
use WP_REST_Request;

defined( 'ABSPATH' ) || exit;

/**
 * GET    uxstudio/v1/code-snippets/snippets            - list (metadata only, no code)
 * GET    uxstudio/v1/code-snippets/snippets/{id}        - single snippet (includes code)
 * POST   uxstudio/v1/code-snippets/snippets            - create
 * PUT    uxstudio/v1/code-snippets/snippets/{id}        - update
 * PUT    uxstudio/v1/code-snippets/snippets/{id}/toggle - enable/disable
 * DELETE uxstudio/v1/code-snippets/snippets/{id}        - delete
 *
 * Every route requires manage_options (base Controller default) - this module
 * executes arbitrary server-side PHP, so there is no lower-privilege tier.
 */
final class RestController extends Controller {

	private SnippetManager $snippetManager;

	public function __construct() {
		$this->snippetManager = new SnippetManager();
	}

	/**
	 * Register routes.
	 */
	public function register_routes(): void {
		$this->route( '/code-snippets/snippets', 'GET', array( $this, 'get_snippets' ) );

		$this->route( '/code-snippets/snippets/(?P<id>\d+)', 'GET', array( $this, 'get_snippet' ) );

		$this->route(
			'/code-snippets/snippets',
			'POST',
			array( $this, 'create_snippet' ),
			$this->snippet_args()
		);

		$this->route(
			'/code-snippets/snippets/(?P<id>\d+)',
			'PUT',
			array( $this, 'update_snippet' ),
			$this->snippet_args()
		);

		$this->route(
			'/code-snippets/snippets/(?P<id>\d+)/toggle',
			'PUT',
			array( $this, 'toggle_snippet' ),
			array(
				'enabled' => array(
					'required'          => true,
					'type'              => 'boolean',
					'sanitize_callback' => 'rest_sanitize_boolean',
				),
			)
		);

		$this->route( '/code-snippets/snippets/(?P<id>\d+)', 'DELETE', array( $this, 'delete_snippet' ) );
	}

	/**
	 * Shared arg schema for create/update.
	 */
	private function snippet_args(): array {
		return array(
			'name'         => array(
				'required'          => true,
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			),
			'type'         => array(
				'required'          => true,
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_key',
			),
			'code'         => array(
				'required' => true,
				'type'     => 'string',
			),
			'enabled'      => array(
				'required'          => false,
				'type'              => 'boolean',
				'sanitize_callback' => 'rest_sanitize_boolean',
			),
			'description'  => array(
				'required'          => false,
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_textarea_field',
			),
			'run_location' => array(
				'required'          => false,
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_key',
			),
			'priority'     => array(
				'required'          => false,
				'type'              => 'integer',
				'sanitize_callback' => 'absint',
			),
		);
	}

	/**
	 * List snippets (metadata only - no code, keeps the payload light).
	 */
	public function get_snippets( WP_REST_Request $request ) {
		$data = array_map(
			static fn( Snippet $snippet ) => $snippet->toApiArray(),
			$this->snippetManager->getAllSnippets()
		);
		return $this->ok( $data );
	}

	/**
	 * Single snippet, including its code (used to populate the editor).
	 */
	public function get_snippet( WP_REST_Request $request ) {
		$snippet = $this->snippetManager->getSnippet( (string) $request['id'] );
		if ( ! $snippet ) {
			return new WP_Error( 'uxstudio_not_found', __( 'Snippet not found', 'ux-studio' ), array( 'status' => 404 ) );
		}
		return $this->ok( array_merge( $snippet->toApiArray(), array( 'code' => $snippet->getCode() ) ) );
	}

	/**
	 * Create a snippet.
	 */
	public function create_snippet( WP_REST_Request $request ) {
		$result = $this->snippetManager->createSnippet( $this->payload( $request ) );
		if ( ! $result['success'] ) {
			return new WP_Error( 'uxstudio_snippet_create_failed', $result['message'], array( 'status' => 400 ) );
		}
		return $this->ok( $result['data']['snippet']->toApiArray() );
	}

	/**
	 * Update a snippet.
	 */
	public function update_snippet( WP_REST_Request $request ) {
		$result = $this->snippetManager->updateSnippet( (string) $request['id'], $this->payload( $request ) );
		if ( ! $result['success'] ) {
			return new WP_Error( 'uxstudio_snippet_update_failed', $result['message'], array( 'status' => 400 ) );
		}
		return $this->ok( $result['data']['snippet']->toApiArray() );
	}

	/**
	 * Toggle enabled state.
	 */
	public function toggle_snippet( WP_REST_Request $request ) {
		$id      = (string) $request['id'];
		$enabled = (bool) $request->get_param( 'enabled' );

		if ( ! $this->snippetManager->getSnippet( $id ) ) {
			return new WP_Error( 'uxstudio_not_found', __( 'Snippet not found', 'ux-studio' ), array( 'status' => 404 ) );
		}

		if ( ! $this->snippetManager->setSnippetEnabled( $id, $enabled ) ) {
			return new WP_Error( 'uxstudio_snippet_toggle_failed', __( 'Failed to update snippet status', 'ux-studio' ), array( 'status' => 400 ) );
		}

		$snippet = $this->snippetManager->getSnippet( $id );
		return $this->ok( $snippet ? $snippet->toApiArray() : array( 'id' => $id, 'enabled' => $enabled ) );
	}

	/**
	 * Delete a snippet.
	 */
	public function delete_snippet( WP_REST_Request $request ) {
		$id = (string) $request['id'];

		if ( ! $this->snippetManager->getSnippet( $id ) ) {
			return new WP_Error( 'uxstudio_not_found', __( 'Snippet not found', 'ux-studio' ), array( 'status' => 404 ) );
		}

		$result = $this->snippetManager->deleteSnippet( $id );
		if ( ! $result['success'] ) {
			return new WP_Error( 'uxstudio_snippet_delete_failed', $result['message'], array( 'status' => 400 ) );
		}

		return $this->ok( array( 'deleted' => true ) );
	}

	/**
	 * Build the raw payload array SnippetManager expects from a request.
	 */
	private function payload( WP_REST_Request $request ): array {
		return array(
			'name'         => (string) $request->get_param( 'name' ),
			'type'         => (string) $request->get_param( 'type' ),
			'code'         => (string) $request->get_param( 'code' ),
			'enabled'      => (bool) $request->get_param( 'enabled' ),
			'description'  => (string) $request->get_param( 'description' ),
			'run_location' => (string) $request->get_param( 'run_location' ),
			'priority'     => (int) $request->get_param( 'priority' ),
		);
	}
}
