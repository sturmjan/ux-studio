<?php
/**
 * Indexing & Sharing REST controller.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\IndexingNotice;

use UxStudio\Core\CentralApp\Gateway;
use UxStudio\Core\CentralApp\SiteIdentity;
use UxStudio\Rest\Controller;
use WP_REST_Request;

defined( 'ABSPATH' ) || exit;

/**
 * GET  uxstudio/v1/indexing-notice/status       what the settings screen needs to explain: UX1 Network app link,
 *                                               whether an own secret is stored, IndexNow (key address, other senders)
 * POST uxstudio/v1/indexing-notice/verify-keys  app_id?, app_secret?  check Facebook keys (what was typed, else the stored ones)
 *
 * Both need `manage_options`. The secret is never part of a response.
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
		$this->route( '/indexing-notice/status', 'GET', array( $this, 'status' ) );
		$this->route(
			'/indexing-notice/verify-keys',
			'POST',
			array( $this, 'verify_keys' ),
			array(
				'app_id'     => array(
					'required'          => false,
					'type'              => 'string',
					'default'           => '',
					'sanitize_callback' => static fn( $v ): string => (string) preg_replace( '/\D/', '', (string) $v ),
				),
				'app_secret' => array(
					'required' => false,
					'type'     => 'string',
					'default'  => '',
				),
			)
		);
	}

	/**
	 * GET status.
	 */
	public function status( WP_REST_Request $request ) {
		$paused = SiteIdentity::paused();
		return $this->ok(
			array(
				'central_app' => array(
					'connected' => Gateway::is_paired(),
					'paused'    => null === $paused ? null : array(
						'state' => $paused['state'],
						'url'   => $paused['url'],
					),
				),
				'own'         => array( 'has_secret' => '' !== $this->module->fb_secret() ),
				'indexnow'    => array(
					'key_url'   => $this->module->setting( 'indexnow_enabled', true ) ? IndexNow::key_url() : '',
					'others'    => IndexNow::foreign_senders(),
					'indexable' => 0 !== (int) get_option( 'blog_public', 1 ),
				),
			)
		);
	}

	/**
	 * POST verify-keys.
	 */
	public function verify_keys( WP_REST_Request $request ) {
		$app_id = (string) $request['app_id'];
		$secret = trim( (string) $request['app_secret'] );
		if ( '' === $app_id ) {
			$app_id = (string) $this->module->setting( 'social_fb_app_id', '' );
		}
		if ( '' === $secret ) {
			$secret = $this->module->fb_secret();
		}
		$result = FacebookScraper::verify( $app_id, $secret );
		return is_wp_error( $result ) ? $result : $this->ok( $result );
	}
}
