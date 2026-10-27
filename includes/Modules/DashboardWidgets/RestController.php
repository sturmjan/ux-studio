<?php
/**
 * Dashboard Widgets REST controller.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\DashboardWidgets;

use UxStudio\Modules\AdminCustomiser\MenuOrganizer;
use UxStudio\Rest\Controller;
use WP_REST_Request;

defined( 'ABSPATH' ) || exit;

/**
 * GET  uxstudio/v1/dashboard-widgets/tasks     - current tasks + notes
 * POST uxstudio/v1/dashboard-widgets/tasks     - persist full tasks list + notes
 * GET  uxstudio/v1/dashboard-widgets/pagespeed - PageSpeed Insights performance score
 * GET  uxstudio/v1/dashboard-widgets/activity  - recent rows from the shared activity log
 * POST uxstudio/v1/dashboard-widgets/analytics/refresh - fetch GA4 sessions (runReport) and return widget HTML
 * POST uxstudio/v1/dashboard-widgets/layout/save - store the current user's dashboard layout as the forced default
 * GET  uxstudio/v1/dashboard-widgets/rules     - every known widget with its rule, the roles, the time of the last scan
 * POST uxstudio/v1/dashboard-widgets/rules     - set rules: `items` [{id, hidden?, roles?}], a bulk `action` (hide | show | roles | reset)
 *                                                on `ids`, `replace` (the whole map back, for "undo"), `dismiss_notice`
 * POST uxstudio/v1/dashboard-widgets/refresh   - ask for the next Dashboard view to rewrite the snapshot (the SPA then loads index.php)
 * DELETE uxstudio/v1/dashboard-widgets/snapshot/{id} - forget a widget that is no longer registered, together with its rule
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
		$this->route( '/dashboard-widgets/tasks', 'GET', array( $this, 'get_tasks' ) );
		$this->route(
			'/dashboard-widgets/tasks',
			'POST',
			array( $this, 'save_tasks' ),
			array(
				'tasks' => array(
					'required' => false,
					'type'     => 'array',
				),
				'notes' => array(
					'required'          => false,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_textarea_field',
				),
			)
		);
		$this->route(
			'/dashboard-widgets/pagespeed',
			'GET',
			array( $this, 'pagespeed' ),
			array(
				'url' => array(
					'required'          => false,
					'type'              => 'string',
					'sanitize_callback' => 'esc_url_raw',
				),
			)
		);
		$this->route( '/dashboard-widgets/pagespeed/widget', 'POST', array( $this, 'pagespeed_widget' ) );
		$this->route( '/dashboard-widgets/analytics/refresh', 'POST', array( $this, 'analytics_refresh' ) );
		$this->route( '/dashboard-widgets/layout/save', 'POST', array( $this, 'layout_save' ) );
		$this->route( '/dashboard-widgets/rules', 'GET', array( $this, 'get_rules' ) );
		$this->route(
			'/dashboard-widgets/rules',
			'POST',
			array( $this, 'save_rules' ),
			array(
				'items'  => array(
					'required' => false,
					'type'     => 'array',
					'maxItems' => WidgetRules::MAX_ENTRIES,
					'items'    => array(
						'type'                 => 'object',
						'additionalProperties' => false,
						'properties'           => array(
							'id'     => array(
								'type'      => 'string',
								'maxLength' => WidgetRules::MAX_ID,
								'pattern'   => '^[A-Za-z0-9_:.-]+$',
							),
							'hidden' => array( 'type' => 'boolean' ),
							'roles'  => array(
								'type'     => 'array',
								'maxItems' => WidgetRules::MAX_ROLES,
								'items'    => array(
									'type'      => 'string',
									'maxLength' => 64,
								),
							),
						),
						'required'             => array( 'id' ),
					),
				),
				'action' => array(
					'required' => false,
					'type'     => 'string',
					'enum'     => array_merge( WidgetRules::ACTIONS, array( 'replace', 'dismiss_notice' ) ),
				),
				'ids'    => array(
					'required' => false,
					'type'     => 'array',
					'maxItems' => WidgetRules::MAX_ENTRIES,
					'items'    => array(
						'type'      => 'string',
						'maxLength' => WidgetRules::MAX_ID,
					),
				),
				'roles'  => array(
					'required' => false,
					'type'     => 'array',
					'maxItems' => WidgetRules::MAX_ROLES,
					'items'    => array(
						'type'      => 'string',
						'maxLength' => 64,
					),
				),
				'rules'  => array(
					'required' => false,
					'type'     => array( 'object', 'array' ),
				),
			)
		);
		$this->route( '/dashboard-widgets/refresh', 'POST', array( $this, 'refresh' ) );
		$this->route( '/dashboard-widgets/snapshot/(?P<id>[A-Za-z0-9_:.-]{1,120})', 'DELETE', array( $this, 'forget' ) );
		$this->route(
			'/dashboard-widgets/activity',
			'GET',
			array( $this, 'activity' ),
			array(
				'limit' => array(
					'required'          => false,
					'type'              => 'integer',
					'sanitize_callback' => 'absint',
				),
			)
		);
	}

	/**
	 * Return the current tasks list and notes text.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function get_tasks( WP_REST_Request $request ) {
		return $this->ok(
			array(
				'tasks' => $this->module->get_tasks(),
				'notes' => $this->module->get_notes(),
			)
		);
	}

	/**
	 * Persist the full desired tasks list and notes text.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function save_tasks( WP_REST_Request $request ) {
		// Each part is saved only when sent, so the dashboard notes widget
		// (notes only) never wipes the tasks list and vice versa.
		$tasks = $request->get_param( 'tasks' );
		$saved_tasks = $request->has_param( 'tasks' )
			? $this->module->save_tasks( is_array( $tasks ) ? $tasks : array() )
			: $this->module->get_tasks();
		$saved_notes = $request->has_param( 'notes' )
			? $this->module->save_notes( (string) $request->get_param( 'notes' ) )
			: $this->module->get_notes();

		return $this->ok(
			array(
				'tasks' => $saved_tasks,
				'notes' => $saved_notes,
			)
		);
	}

	/**
	 * Return the PageSpeed Insights performance score for a URL.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function pagespeed( WP_REST_Request $request ) {
		$url = (string) $request->get_param( 'url' );
		return $this->ok( $this->module->get_pagespeed( $url ) );
	}

	/**
	 * Measure the home URL (fresh, bypassing the 1 h cache) and return the
	 * wp-admin dashboard widget markup.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function pagespeed_widget( WP_REST_Request $request ) {
		delete_transient( 'uxstudio_dashboard_pagespeed_' . md5( home_url( '/' ) ) );
		return $this->ok( array( 'html' => ExtraWidgets::pagespeed_html( $this->module->get_pagespeed() ) ) );
	}

	/**
	 * Return recent rows from the shared activity log.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function activity( WP_REST_Request $request ) {
		$limit = (int) $request->get_param( 'limit' );
		if ( $limit <= 0 ) {
			$limit = 10;
		}
		return $this->ok( $this->module->get_recent_activity( $limit ) );
	}

	/**
	 * Fetch fresh GA4 data and return the rendered widget HTML.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function analytics_refresh( WP_REST_Request $request ) {
		$result = $this->module->analytics()->refresh();
		if ( empty( $result['ok'] ) ) {
			return new \WP_Error( 'uxstudio_ga_failed', (string) ( $result['error'] ?? 'GA4 error' ), array( 'status' => 502 ) );
		}
		return $this->ok( array( 'html' => $result['html'] ?? '' ) );
	}

	/**
	 * Save the current user's dashboard arrangement as the forced layout.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function layout_save( WP_REST_Request $request ) {
		$result = $this->module->layout()->save_current();
		if ( empty( $result['ok'] ) ) {
			return new \WP_Error( 'uxstudio_layout_missing', (string) ( $result['error'] ?? '' ), array( 'status' => 400 ) );
		}
		return $this->ok( array( 'summary' => $result['summary'] ?? '' ) );
	}

	/* ------------------------------------------------------------------ *
	 * Widget rules
	 * ------------------------------------------------------------------ */

	/**
	 * Everything the table needs.
	 *
	 * @return array<string, mixed>
	 */
	private function rules_payload(): array {
		$rules    = $this->module->rules();
		$snapshot = WidgetSnapshot::read();
		$scan_at  = (int) $snapshot['scan_at'];
		$rows     = array();
		foreach ( $snapshot['widgets'] as $id => $widget ) {
			$rows[ (string) $id ] = array(
				'id'          => (string) $id,
				'title'       => (string) ( $widget['title'] ?? $id ),
				'context'     => (string) ( $widget['context'] ?? '' ),
				'priority'    => (string) ( $widget['priority'] ?? '' ),
				'source'      => (string) ( $widget['source'] ?? 'unknown' ),
				'source_name' => (string) ( $widget['source_name'] ?? '' ),
				'guess'       => ! empty( $widget['guess'] ),
				'first_seen'  => (int) ( $widget['first_seen'] ?? 0 ),
				'last_seen'   => (int) ( $widget['last_seen'] ?? 0 ),
				'stale'       => WidgetRules::is_stale( (array) $widget, $scan_at ),
			);
		}
		// A rule of a widget the snapshot does not know (e.g. imported from another site) still has to be visible.
		foreach ( array_keys( $rules ) as $id ) {
			if ( ! isset( $rows[ (string) $id ] ) ) {
				$rows[ (string) $id ] = array(
					'id'          => (string) $id,
					'title'       => (string) $id,
					'context'     => '',
					'priority'    => '',
					'source'      => 'unknown',
					'source_name' => '',
					'guess'       => false,
					'first_seen'  => 0,
					'last_seen'   => 0,
					'stale'       => true,
				);
			}
		}
		$roles = array();
		foreach ( MenuOrganizer::role_list() as $slug => $name ) {
			$roles[] = array(
				'id'   => (string) $slug,
				'name' => (string) $name,
			);
		}
		return array(
			'rules'    => (object) $rules,
			'widgets'  => array_values( $rows ),
			'roles'    => $roles,
			'scan_at'  => $scan_at,
			'hide_all' => (bool) $this->module->settings_value( 'disable_all_widgets', false ),
			'notice'   => (bool) $this->module->settings_value( 'rules_notice', false ),
			'index'    => admin_url( 'index.php' ),
		);
	}

	/**
	 * GET rules.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function get_rules( WP_REST_Request $request ) {
		return $this->ok( $this->rules_payload() );
	}

	/**
	 * POST rules: set items and / or run one bulk action.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function save_rules( WP_REST_Request $request ) {
		$valid  = array_map( 'strval', array_keys( MenuOrganizer::role_list() ) );
		$action = (string) $request->get_param( 'action' );
		$rules  = $this->module->rules();

		if ( 'dismiss_notice' === $action ) {
			$this->module->write_option( array( 'rules_notice' => false ) );
			return $this->ok( $this->rules_payload() );
		}
		if ( 'replace' === $action ) {
			$raw = $request->get_param( 'rules' );
			if ( ! is_array( $raw ) ) {
				return new \WP_Error( 'uxstudio_rules_missing', __( 'The rules to restore are missing.', 'ux-studio' ), array( 'status' => 400 ) );
			}
			$rules = WidgetRules::sanitize( $raw, $valid );
		} else {
			$items = $request->get_param( 'items' );
			if ( is_array( $items ) && $items ) {
				$rules = WidgetRules::set_items( $rules, $items, $valid );
			}
			if ( '' !== $action ) {
				$ids = $request->get_param( 'ids' );
				if ( ! is_array( $ids ) || ! $ids ) {
					return new \WP_Error( 'uxstudio_rules_ids', __( 'Choose at least one widget.', 'ux-studio' ), array( 'status' => 400 ) );
				}
				$roles = $request->get_param( 'roles' );
				$rules = WidgetRules::apply_bulk( $rules, $action, $ids, is_array( $roles ) ? $roles : array(), $valid );
			}
		}
		if ( count( $rules ) > WidgetRules::MAX_ENTRIES ) {
			return new \WP_Error( 'uxstudio_rules_too_many', __( 'There are too many widget rules.', 'ux-studio' ), array( 'status' => 400 ) );
		}
		$this->module->save_rules( $rules );
		return $this->ok( $this->rules_payload() );
	}

	/**
	 * POST refresh: the SPA loads the Dashboard right after this (same origin, with the cookies of the administrator),
	 * and that view rewrites the snapshot.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function refresh( WP_REST_Request $request ) {
		WidgetSnapshot::request_refresh();
		return $this->ok( array( 'url' => admin_url( 'index.php' ) ) );
	}

	/**
	 * DELETE snapshot/{id}: forget a widget that is no longer registered, and its rule.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function forget( WP_REST_Request $request ) {
		$id       = WidgetRules::clean_id( (string) $request->get_param( 'id' ) );
		$snapshot = WidgetSnapshot::read();
		if ( '' === $id ) {
			return new \WP_Error( 'uxstudio_widget_id', __( 'Unknown widget.', 'ux-studio' ), array( 'status' => 400 ) );
		}
		$entry = $snapshot['widgets'][ $id ] ?? null;
		if ( is_array( $entry ) && ! WidgetRules::is_stale( $entry, (int) $snapshot['scan_at'] ) ) {
			return new \WP_Error( 'uxstudio_widget_live', __( 'This widget is still registered, so it would come back. Hide it instead.', 'ux-studio' ), array( 'status' => 409 ) );
		}
		if ( null === $entry && ! isset( $this->module->rules()[ $id ] ) ) {
			return new \WP_Error( 'uxstudio_widget_id', __( 'Unknown widget.', 'ux-studio' ), array( 'status' => 404 ) );
		}
		WidgetSnapshot::forget( $id );
		$rules = $this->module->rules();
		unset( $rules[ $id ] );
		$this->module->save_rules( $rules );
		return $this->ok( $this->rules_payload() );
	}
}
