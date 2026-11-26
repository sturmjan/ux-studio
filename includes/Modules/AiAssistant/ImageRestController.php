<?php
/**
 * REST endpoints of the Content Creator "Image" card.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\AiAssistant;

use UxStudio\Rest\Controller;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

/**
 * The route capability is upload_files (the result lands in the Media Library); the handlers additionally require
 * edit_posts, as the rest of the content UI does. Generating costs money, so it spends the write rate-limit budget;
 * the estimate does not.
 *
 *   GET  uxstudio/v1/ai-assistant/image/config    - provider, key status, form options
 *   POST uxstudio/v1/ai-assistant/image/estimate  - cost estimate, no API call
 *   POST uxstudio/v1/ai-assistant/image/generate  - generate and save to the Media Library
 */
final class ImageRestController extends Controller {

	/** @var array<string, array<string, mixed>> */
	private const INPUT_ARGS = array(
		'prompt'       => array( 'required' => true, 'type' => 'string' ),
		'style'        => array( 'required' => false, 'type' => 'string' ),
		'aspect_ratio' => array( 'required' => false, 'type' => 'string' ),
		'resolution'   => array( 'required' => false, 'type' => 'string' ),
		'quality'      => array( 'required' => false, 'type' => 'string' ),
		'tier'         => array( 'required' => false, 'type' => 'string' ),
	);

	public function register_routes(): void {
		$this->route( '/ai-assistant/image/config', 'GET', array( $this, 'config' ), array(), 'upload_files' );
		$this->route_readonly( '/ai-assistant/image/estimate', array( $this, 'estimate' ), self::INPUT_ARGS, 'upload_files' );
		$this->route(
			'/ai-assistant/image/generate',
			'POST',
			array( $this, 'generate' ),
			self::INPUT_ARGS + array( 'post_id' => array( 'required' => false, 'type' => 'integer', 'sanitize_callback' => 'absint' ) ),
			'upload_files'
		);
	}

	/**
	 * @return WP_Error|null Error when the user may not use the content UI.
	 */
	private function guard(): ?WP_Error {
		if ( ! current_user_can( 'edit_posts' ) ) {
			return new WP_Error( 'uxstudio_forbidden', __( 'You are not allowed to do this.', 'ux-studio' ), array( 'status' => 403 ) );
		}
		return null;
	}

	/** @return array<string, string> */
	private function input( WP_REST_Request $request ): array {
		$input = array();
		foreach ( array_keys( self::INPUT_ARGS ) as $key ) {
			$value = $request->get_param( $key );
			if ( is_scalar( $value ) ) {
				$input[ $key ] = 'prompt' === $key ? sanitize_textarea_field( (string) $value ) : sanitize_text_field( (string) $value );
			}
		}
		return $input;
	}

	public function config() {
		$error = $this->guard();
		if ( $error ) {
			return $error;
		}

		$provider_id = ImageService::provider_id();
		$service     = new ImageService( ImageService::build_provider( $provider_id, true ) );
		$config      = $service->config();

		return $this->ok(
			array(
				'provider'     => $config['provider'],
				'label'        => $config['label'],
				'has_key'      => ImageService::has_key( $provider_id ),
				'capabilities' => $config['capabilities'],
			)
		);
	}

	public function estimate( WP_REST_Request $request ) {
		$error = $this->guard();
		if ( $error ) {
			return $error;
		}

		try {
			$service = new ImageService( ImageService::build_provider( null, true ) );
			return $this->ok( $service->estimate( $this->input( $request ) ) );
		} catch ( \RuntimeException $e ) {
			return $this->failure( $e );
		}
	}

	public function generate( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$error = $this->guard();
		if ( $error ) {
			return $error;
		}

		$parent = absint( $request->get_param( 'post_id' ) );
		if ( $parent > 0 && ! current_user_can( 'edit_post', $parent ) ) {
			$parent = 0;
		}

		try {
			return $this->ok( ( new ImageService() )->generate( $this->input( $request ), $parent ) );
		} catch ( \RuntimeException $e ) {
			ErrorLogger::log(
				array(
					'error_type'    => ErrorLogger::TYPE_PROVIDER,
					'error_message' => $e->getMessage(),
					'context'       => array(
						'scope'   => 'image',
						'user_id' => get_current_user_id(),
					),
				)
			);
			return $this->failure( $e );
		}
	}

	private function failure( \RuntimeException $e ): WP_Error {
		$status = $e->getCode();
		return new WP_Error( 'uxstudio_image_failed', $e->getMessage(), array( 'status' => $status >= 400 && $status < 600 ? $status : 500 ) );
	}
}
