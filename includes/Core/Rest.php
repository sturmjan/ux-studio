<?php
/**
 * Core REST routes: module registry + generic module settings.
 *
 * @package UxStudio
 */

namespace UxStudio\Core;

use UxStudio\Core\Migration\ActivationGuard;
use UxStudio\Core\Migration\Overlaps;
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
				'confirm_overlap' => array(
					'required'          => false,
					'type'              => 'boolean',
					'default'           => false,
					'sanitize_callback' => 'rest_sanitize_boolean',
				),
			)
		);
		$this->route( '/overlaps', 'GET', array( $this, 'get_overlaps' ) );
		$this->route( '/overlaps/ignore', 'POST', array( $this, 'ignore_overlap' ), $this->overlap_key_args() );
		$this->route( '/overlaps/unignore', 'POST', array( $this, 'unignore_overlap' ), $this->overlap_key_args() );
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
			if ( ! empty( $meta['virtual'] ) && ! Modules::is_group( $meta ) ) {
				continue; // Empty group (all its features gone) - nothing to show.
			}
			$children = Modules::is_group( $meta ) ? array_values( (array) $meta['children'] ) : array();
			$out[]    = array(
				'id'          => $id,
				'name'        => $this->translate_meta( $meta['name'] ?? $id ),
				'description' => $this->translate_meta( $meta['description'] ?? '' ),
				'group'       => $meta['group'] ?? 'general',
				'icon'        => $meta['icon'] ?? 'puzzle',
				'settings'    => ! empty( $meta['settings'] ),
				// A group counts as enabled when any of its features is.
				'enabled'     => $children ? (bool) array_intersect( $children, $enabled ) : in_array( $id, $enabled, true ),
				'parent'      => isset( $meta['parent'] ) ? (string) $meta['parent'] : null,
				'children'    => $children,
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
		if ( (bool) $request['enabled'] ) {
			$error = $this->overlap_guard( $id, (bool) $request['confirm_overlap'] );
			if ( $error ) {
				return $error;
			}
		}
		$ok = $this->modules->set_enabled( $id, (bool) $request['enabled'] );
		if ( ! $ok ) {
			return new \WP_Error( 'uxstudio_unknown_module', __( 'Unknown module.', 'ux-studio' ), array( 'status' => 404 ) );
		}
		return $this->ok( array( 'id' => $id, 'enabled' => (bool) $request['enabled'] ) );
	}

	/**
	 * Plugin-overlap guard for enabling a module (or every feature of a group).
	 * Only modules that are currently off are checked - re-enabling an enabled one
	 * is a no-op. See ActivationGuard for the `uxstudio_overlap_guard_enabled` filter.
	 *
	 * @param string $id        Module or group id.
	 * @param bool   $confirmed The user confirmed a partial overlap.
	 * @return \WP_Error|null
	 */
	private function overlap_guard( string $id, bool $confirmed ) {
		$meta = $this->modules->all()[ $id ] ?? null;
		if ( ! $meta ) {
			return null; // Unknown module: set_enabled() answers 404.
		}
		$ids = Modules::is_group( $meta ) ? array_values( (array) $meta['children'] ) : array( $id );
		$ids = array_values( array_diff( $ids, $this->modules->enabled_ids() ) );
		if ( ! $ids ) {
			return null;
		}
		$decision = ActivationGuard::decide( $ids, $confirmed );
		ActivationGuard::log_decision( $ids, $decision );
		return ActivationGuard::error_for( $decision );
	}

	/**
	 * Shared args of the ignore/unignore routes.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function overlap_key_args(): array {
		return array(
			'keys' => array(
				'required'          => true,
				'type'              => 'array',
				'items'             => array( 'type' => 'string' ),
				'sanitize_callback' => static fn( $v ): array => array_map( 'sanitize_text_field', (array) $v ),
			),
		);
	}

	/**
	 * Add module labels for the UI.
	 *
	 * @param array<int, array<string, mixed>> $rows Overlap rows.
	 * @return array<int, array<string, mixed>>
	 */
	private function label_overlaps( array $rows ): array {
		foreach ( $rows as &$row ) {
			$row['module_name'] = ActivationGuard::module_name( (string) $row['module'] );
		}
		return $rows;
	}

	/**
	 * GET /overlaps - current plugin overlaps, grouped (full / customiser / partial / ignored).
	 */
	public function get_overlaps( WP_REST_Request $request ): WP_REST_Response {
		$grouped = Overlaps::grouped();
		return $this->ok(
			array(
				'full'       => $this->label_overlaps( $grouped['full'] ),
				'customiser' => $this->label_overlaps( $grouped['customiser'] ),
				'partial'    => $this->label_overlaps( $grouped['partial'] ),
				'ignored'    => $this->label_overlaps( $grouped['ignored'] ),
			)
		);
	}

	/**
	 * POST /overlaps/ignore - hide pairs from the panel and the admin notice.
	 */
	public function ignore_overlap( WP_REST_Request $request ): WP_REST_Response {
		$added = Overlaps::ignore( (array) $request['keys'] );
		if ( $added ) {
			ActivityLog::log( 'core', 'overlap_ignored', 'module', 0, array( 'pairs' => $added ) );
		}
		return $this->ok( array( 'ignored' => $added ) );
	}

	/**
	 * POST /overlaps/unignore - show pairs again.
	 */
	public function unignore_overlap( WP_REST_Request $request ): WP_REST_Response {
		$removed = Overlaps::unignore( (array) $request['keys'] );
		if ( $removed ) {
			ActivityLog::log( 'core', 'overlap_unignored', 'module', 0, array( 'pairs' => $removed ) );
		}
		return $this->ok( array( 'restored' => $removed ) );
	}
}
