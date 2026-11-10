<?php
/**
 * Admin REST route of the live banner preview.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\Consent;

use UxStudio\Rest\Controller;
use WP_Error;
use WP_REST_Request;

defined( 'ABSPATH' ) || exit;

/**
 * POST uxstudio/v1/consent/preview-config  manage_options: {values, view} -> the banner config with the unsaved values applied
 *
 * It is a POST only because the unsaved texts do not fit in a query string; nothing is stored, so it uses the read-only
 * route (no share of the write rate limit, which a preview that follows typing would use up). See Module::preview_config().
 */
final class PreviewRest extends Controller {

	/** Hook callback. */
	public static function register(): void {
		( new self() )->register_routes();
	}

	/** Register the route. */
	public function register_routes(): void {
		$this->route_readonly(
			'/consent/preview-config',
			array( $this, 'handle' ),
			array(
				'values' => array(
					'type'    => 'object',
					'default' => array(),
				),
				'view'   => array(
					'type'    => 'string',
					'default' => 'main',
					'enum'    => array( 'main', 'detail', 'fab', 'ph' ),
				),
			)
		);
	}

	/**
	 * @param WP_REST_Request $request Request.
	 */
	public function handle( WP_REST_Request $request ) {
		$module = Inventory::module();
		if ( null === $module ) {
			return new WP_Error( 'uxstudio_consent_off', __( 'The Cookie Consent module is not active.', 'ux-studio' ), array( 'status' => 409 ) );
		}
		$values = $request->get_param( 'values' );
		$out    = $module->preview_config( is_array( $values ) ? $values : array(), (string) $request->get_param( 'view' ) );
		return $this->ok( $out + array( 'assets' => $module->asset_urls() ) );
	}
}
