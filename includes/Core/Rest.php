<?php
/**
 * Core REST routes: module registry + generic module settings.
 *
 * @package UxStudio
 */

namespace UxStudio\Core;

use UxStudio\Plugin;
use UxStudio\Rest\Controller;
use WP_REST_Request;
use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

/**
 * uxstudio/v1/modules            GET  - list modules (meta + enabled state)
 * uxstudio/v1/modules/{id}       POST - enable/disable
 * uxstudio/v1/settings/{id}      GET/POST - module settings via schema
 * Module-specific controllers are registered by the modules themselves.
 */
final class Rest extends Controller {

	private Modules $modules;

	public function __construct( Modules $modules ) {
		$this->modules = $modules;
	}

	/**
	 * Hook route registration + let modules register their controllers.
	 */
	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Register core routes.
	 */
	public function register_routes(): void {
		$this->route( '/modules', 'GET', array( $this, 'list_modules' ) );
		// Must be registered BEFORE the /modules/{id} route below: "deactivate-all"
		// also matches the [a-z0-9-]+ id pattern, and WP dispatches to the first
		// matching route in registration order.
		$this->route( '/modules/deactivate-all', 'POST', array( $this, 'deactivate_all' ) );
		$this->route(
			'/modules/(?P<id>[a-z0-9-]+)',
			'POST',
			array( $this, 'toggle_module' ),
			array(
				'enabled' => array(
					'required'          => true,
					'type'              => 'boolean',
					'sanitize_callback' => 'rest_sanitize_boolean',
				),
			)
		);
		$this->route( '/settings/(?P<id>[a-z0-9-]+)', 'GET', array( $this, 'get_settings' ) );
		$this->route( '/settings/(?P<id>[a-z0-9-]+)', 'POST', array( $this, 'save_settings' ) );
	}

	/**
	 * GET /settings/{id} — schema + current values for the generic renderer.
	 */
	public function get_settings( WP_REST_Request $request ) {
		$module = $this->modules->instance( (string) $request['id'] );
		if ( ! $module ) {
			return new \WP_Error( 'uxstudio_unknown_module', __( 'Unknown module.', 'ux-studio' ), array( 'status' => 404 ) );
		}
		if ( ! current_user_can( $module->capability() ) ) {
			return new \WP_Error( 'uxstudio_forbidden', __( 'You are not allowed to manage this module.', 'ux-studio' ), array( 'status' => 403 ) );
		}
		$meta = $this->modules->all()[ (string) $request['id'] ] ?? array();
		return $this->ok(
			array(
				'name'   => $this->translate_meta( $meta['name'] ?? (string) $request['id'] ),
				'schema' => $module->settings_schema(),
				'values' => $module->settings_values(),
			)
		);
	}

	/**
	 * POST /settings/{id} — validate against schema and persist.
	 */
	public function save_settings( WP_REST_Request $request ) {
		$module = $this->modules->instance( (string) $request['id'] );
		if ( ! $module ) {
			return new \WP_Error( 'uxstudio_unknown_module', __( 'Unknown module.', 'ux-studio' ), array( 'status' => 404 ) );
		}
		if ( ! current_user_can( $module->capability() ) ) {
			return new \WP_Error( 'uxstudio_forbidden', __( 'You are not allowed to manage this module.', 'ux-studio' ), array( 'status' => 403 ) );
		}
		$values = $request->get_json_params();
		return $this->ok( array( 'values' => $module->save_settings( is_array( $values ) ? $values : array() ) ) );
	}

	/**
	 * Translate a module meta string (name/description). These live in each
	 * module's meta.json (data, not code), so make-pot cannot extract them;
	 * the English value is used as the gettext msgid and the cs_CZ .po carries
	 * the translation. Empty strings pass through untouched (translate('') is
	 * unsafe - it would return the .po header block).
	 *
	 * @param string $text English source string from meta.json.
	 */
	private function translate_meta( string $text ): string {
		if ( '' === $text ) {
			return '';
		}
		// phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText -- dynamic by design; msgids are registered manually in languages/ux-studio-meta.php.
		return translate( $text, 'ux-studio' );
	}

	/**
	 * GET /modules — id, localized name/description, group, enabled.
	 */
	public function list_modules( WP_REST_Request $request ): WP_REST_Response {
		$enabled = $this->modules->enabled_ids();
		$out     = array();
		foreach ( $this->modules->all() as $id => $meta ) {
			$out[] = array(
				'id'          => $id,
				'name'        => $this->translate_meta( $meta['name'] ?? $id ),
				'description' => $this->translate_meta( $meta['description'] ?? '' ),
				'group'       => $meta['group'] ?? 'general',
				'icon'        => $meta['icon'] ?? 'puzzle',
				'settings'    => ! empty( $meta['settings'] ),
				'enabled'     => in_array( $id, $enabled, true ),
			);
		}
		return $this->ok( $out );
	}

	/**
	 * POST /modules/deactivate-all — disable every module at once.
	 */
	public function deactivate_all( WP_REST_Request $request ): WP_REST_Response {
		$count = $this->modules->deactivate_all();
		return $this->ok( array( 'deactivated' => $count ) );
	}

	/**
	 * POST /modules/{id} — persist enabled state.
	 */
	public function toggle_module( WP_REST_Request $request ) {
		$id = (string) $request['id'];
		$ok = $this->modules->set_enabled( $id, (bool) $request['enabled'] );
		if ( ! $ok ) {
			return new \WP_Error( 'uxstudio_unknown_module', __( 'Unknown module.', 'ux-studio' ), array( 'status' => 404 ) );
		}
		return $this->ok( array( 'id' => $id, 'enabled' => (bool) $request['enabled'] ) );
	}
}
