<?php
/**
 * Instagram Feed REST controller.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\InstagramFeed;

use UxStudio\Rest\Controller;
use WP_REST_Request;

defined( 'ABSPATH' ) || exit;

/**
 * GET  uxstudio/v1/instagram-feed/media - cached media, newest first
 * POST uxstudio/v1/instagram-feed/sync  - pull fresh media through the content-sync broker
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
		$this->route( '/instagram-feed/media', 'GET', array( $this, 'media' ) );
		$this->route( '/instagram-feed/sync', 'POST', array( $this, 'sync' ) );
	}

	/**
	 * Cached media.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function media( WP_REST_Request $request ) {
		return $this->ok( $this->module->list_media() );
	}

	/**
	 * Trigger a broker sync.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function sync( WP_REST_Request $request ) {
		$result = $this->module->sync();
		return is_wp_error( $result ) ? $result : $this->ok( $result );
	}
}
