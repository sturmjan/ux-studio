<?php
/**
 * Dashboard Widgets module - PageSpeed score, recent activity and quick
 * tasks/notes, presented entirely through the module's own SPA screen.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\DashboardWidgets;

use UxStudio\Core\Security;
use UxStudio\Modules\BaseModule;

defined( 'ABSPATH' ) || exit;

/**
 * Ported/redesigned from the legacy dashboard-widgets module. The rich views
 * (PageSpeed score, recent activity, quick tasks/notes) live in the SPA under
 * this module's own page; on top of that it manages the REAL wp-admin
 * dashboard by hiding selected (or all) core/plugin dashboard widgets via
 * wp_dashboard_setup.
 *
 * Secrets (Google PageSpeed API key, GA service-account JSON) are never
 * stored in the regular uxstudio_dashboard-widgets settings option; they go
 * through Security::store_secret() in dedicated uxstudio_secret_* options
 * and are never echoed back via REST.
 */
final class Module extends BaseModule {

	private const SECRET_PAGESPEED = 'uxstudio_secret_dashboard_pagespeed_key';
	private const SECRET_GA        = 'uxstudio_secret_dashboard_ga_json';

	private const OPTION_TASKS = 'uxstudio_dashboard_tasks';
	private const OPTION_NOTES = 'uxstudio_dashboard_notes';

	private const CACHE_WIDGETS = 'uxstudio_dashboard_widgets_cache';

	/**
	 * Register hooks.
	 */
	public function boot(): void {
		add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );

		// Manage the real wp-admin dashboard: snapshot the registered widgets
		// late (so add-ons have registered), then remove the ones the admin
		// hid. Priority ordering matters - cache before we start removing.
		add_action( 'wp_dashboard_setup', array( $this, 'cache_dashboard_widgets' ), 998 );
		add_action( 'wp_dashboard_setup', array( $this, 'apply_hidden_widgets' ), 999 );
	}

	/* ------------------------------------------------------------------ *
	 * Real wp-admin dashboard widget management
	 * ------------------------------------------------------------------ */

	/**
	 * Snapshot the currently registered dashboard meta boxes (id => title) into
	 * a transient so the settings screen can list them even outside the
	 * dashboard request where $wp_meta_boxes isn't populated.
	 */
	public function cache_dashboard_widgets(): void {
		global $wp_meta_boxes;
		if ( ! isset( $wp_meta_boxes['dashboard'] ) || ! is_array( $wp_meta_boxes['dashboard'] ) ) {
			return;
		}

		$normalized = array();
		foreach ( $wp_meta_boxes['dashboard'] as $priorities ) {
			if ( ! is_array( $priorities ) ) {
				continue;
			}
			foreach ( $priorities as $boxes ) {
				if ( ! is_array( $boxes ) ) {
					continue;
				}
				foreach ( $boxes as $box_id => $box ) {
					$title = is_array( $box ) && isset( $box['title'] ) ? sanitize_text_field( wp_strip_all_tags( (string) $box['title'] ) ) : '';
					$normalized[ (string) $box_id ] = $title;
				}
			}
		}
		set_transient( self::CACHE_WIDGETS, $normalized, DAY_IN_SECONDS );
	}

	/**
	 * Remove the dashboard widgets the admin hid (or all of them, plus the
	 * welcome panel, when "disable all" is on).
	 */
	public function apply_hidden_widgets(): void {
		global $wp_meta_boxes;

		if ( (bool) $this->settings->get( 'disable_all_widgets', false ) ) {
			remove_action( 'welcome_panel', 'wp_welcome_panel' );
			$wp_meta_boxes['dashboard'] = array();
			return;
		}

		$hidden = (array) $this->settings->get( 'hidden_widgets', array() );
		if ( empty( $hidden ) ) {
			return;
		}

		if ( in_array( 'dashboard_welcome', $hidden, true ) ) {
			remove_action( 'welcome_panel', 'wp_welcome_panel' );
		}
		if ( ! isset( $wp_meta_boxes['dashboard'] ) || ! is_array( $wp_meta_boxes['dashboard'] ) ) {
			return;
		}

		foreach ( $hidden as $widget_id ) {
			foreach ( array_keys( $wp_meta_boxes['dashboard'] ) as $context ) {
				foreach ( array_keys( (array) $wp_meta_boxes['dashboard'][ $context ] ) as $priority ) {
					unset( $wp_meta_boxes['dashboard'][ $context ][ $priority ][ $widget_id ] );
				}
			}
		}
	}

	/**
	 * Known dashboard widgets as id => title (core defaults merged over the
	 * cached snapshot of whatever is actually registered on this site).
	 *
	 * @return array<string,string>
	 */
	public function available_widgets(): array {
		$defaults = array(
			'dashboard_welcome'     => __( 'Welcome', 'ux-studio' ),
			'dashboard_site_health' => __( 'Site Health Status', 'ux-studio' ),
			'dashboard_right_now'   => __( 'At a Glance', 'ux-studio' ),
			'dashboard_activity'    => __( 'Activity', 'ux-studio' ),
			'dashboard_quick_press' => __( 'Quick Draft', 'ux-studio' ),
			'dashboard_primary'     => __( 'WordPress Events and News', 'ux-studio' ),
		);

		$cached = get_transient( self::CACHE_WIDGETS );
		if ( is_array( $cached ) ) {
			foreach ( $cached as $id => $title ) {
				$defaults[ (string) $id ] = '' !== (string) $title ? (string) $title : (string) $id;
			}
		}
		return $defaults;
	}

	/**
	 * Register the module REST controller.
	 */
	public function register_rest_routes(): void {
		( new RestController( $this ) )->register_routes();
	}

	/**
	 * REST controller class.
	 */
	public function rest_controller(): ?string {
		return RestController::class;
	}

	/**
	 * Settings schema for the generic renderer / embedded Settings tab.
	 */
	public function settings_schema(): array {
		return array(
			array(
				'key'     => 'disable_all_widgets',
				'type'    => 'toggle',
				'label'   => __( 'Hide all dashboard widgets', 'ux-studio' ),
				'help'    => __( 'Removes every wp-admin dashboard widget and the welcome panel for a clean dashboard.', 'ux-studio' ),
				'default' => false,
			),
			array(
				'key'     => 'hidden_widgets',
				'type'    => 'multiselect',
				'label'   => __( 'Hide specific dashboard widgets', 'ux-studio' ),
				'help'    => __( 'The list reflects widgets registered on this site (visit Dashboard once to refresh it).', 'ux-studio' ),
				'options' => $this->available_widgets(),
				'default' => array(),
			),
			array(
				'key'     => 'pagespeed_api_key',
				'type'    => 'text',
				'label'   => __( 'Google PageSpeed API key', 'ux-studio' ),
				'help'    => __( 'Stored encrypted. Leave blank to keep the current key.', 'ux-studio' ),
				'default' => '',
			),
			array(
				'key'     => 'ga_service_account_json',
				'type'    => 'textarea',
				'label'   => __( 'Google Analytics service-account JSON', 'ux-studio' ),
				'help'    => __( 'Paste the full service-account JSON. Stored encrypted. Leave blank to keep the current value.', 'ux-studio' ),
				'default' => '',
			),
		);
	}

	/**
	 * Intercept the two secret fields before they reach the plain settings
	 * option; everything else goes through the normal schema-based save.
	 *
	 * @param array $input Raw input.
	 */
	public function save_settings( array $input ): array {
		if ( array_key_exists( 'pagespeed_api_key', $input ) && '' !== (string) $input['pagespeed_api_key'] ) {
			Security::store_secret( self::SECRET_PAGESPEED, (string) $input['pagespeed_api_key'] );
		}
		unset( $input['pagespeed_api_key'] );

		if ( array_key_exists( 'ga_service_account_json', $input ) && '' !== (string) $input['ga_service_account_json'] ) {
			Security::store_secret( self::SECRET_GA, (string) $input['ga_service_account_json'] );
		}
		unset( $input['ga_service_account_json'] );

		return parent::save_settings( $input );
	}

	/**
	 * Never leak the secrets back to the client; expose only whether they're set.
	 */
	public function settings_values(): array {
		$values                             = parent::settings_values();
		$values['pagespeed_api_key']        = '';
		$values['ga_service_account_json']  = '';
		$values['has_pagespeed_key']        = '' !== Security::get_secret( self::SECRET_PAGESPEED );
		$values['has_ga_key']               = '' !== Security::get_secret( self::SECRET_GA );
		return $values;
	}

	/**
	 * Current tasks list.
	 *
	 * @return array<int, array{id:string,text:string,done:bool,created_at:string}>
	 */
	public function get_tasks(): array {
		$tasks = get_option( self::OPTION_TASKS, array() );
		return is_array( $tasks ) ? $tasks : array();
	}

	/**
	 * Current notes text.
	 */
	public function get_notes(): string {
		return (string) get_option( self::OPTION_NOTES, '' );
	}

	/**
	 * Sanitize and persist the full desired task list.
	 *
	 * @param array $tasks Raw task list from the client.
	 * @return array<int, array{id:string,text:string,done:bool,created_at:string}>
	 */
	public function save_tasks( array $tasks ): array {
		$clean = array();

		foreach ( $tasks as $task ) {
			if ( ! is_array( $task ) ) {
				continue;
			}

			$text = isset( $task['text'] ) ? sanitize_text_field( (string) $task['text'] ) : '';
			$text = mb_substr( $text, 0, 500 );
			if ( '' === $text ) {
				continue;
			}

			$id = isset( $task['id'] ) ? sanitize_text_field( (string) $task['id'] ) : '';
			if ( '' === $id ) {
				$id = wp_generate_uuid4();
			}

			$created_at = isset( $task['created_at'] ) ? sanitize_text_field( (string) $task['created_at'] ) : '';
			if ( '' === $created_at ) {
				$created_at = current_time( 'mysql' );
			}

			$clean[] = array(
				'id'         => $id,
				'text'       => $text,
				'done'       => ! empty( $task['done'] ),
				'created_at' => $created_at,
			);
		}

		update_option( self::OPTION_TASKS, $clean, false );

		return $clean;
	}

	/**
	 * Persist the notes text.
	 *
	 * @param string $notes Raw notes text.
	 */
	public function save_notes( string $notes ): string {
		$notes = sanitize_textarea_field( $notes );
		update_option( self::OPTION_NOTES, $notes, false );
		return $notes;
	}

	/**
	 * PageSpeed Insights performance score for a URL, cached for one hour.
	 *
	 * @param string $url URL to test; defaults to the site home URL.
	 * @return array<string, mixed>
	 */
	public function get_pagespeed( string $url = '' ): array {
		$api_key = Security::get_secret( self::SECRET_PAGESPEED );
		if ( '' === $api_key ) {
			return array( 'configured' => false );
		}

		if ( '' === $url ) {
			$url = home_url( '/' );
		}

		$cache_key = 'uxstudio_dashboard_pagespeed_' . md5( $url );
		$cached    = get_transient( $cache_key );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$response = wp_remote_get(
			'https://www.googleapis.com/pagespeedonline/v5/runPagespeed?' . http_build_query(
				array(
					'url'      => $url,
					'key'      => $api_key,
					'category' => 'performance',
				)
			),
			array( 'timeout' => 20 )
		);

		if ( is_wp_error( $response ) ) {
			return array(
				'configured' => true,
				'success'    => false,
				'error'      => $response->get_error_message(),
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 300 ) {
			return array(
				'configured' => true,
				'success'    => false,
				'error'      => sprintf(
					/* translators: %d: HTTP status code */
					__( 'PageSpeed API returned HTTP %d.', 'ux-studio' ),
					$code
				),
			);
		}

		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $body ) ) {
			return array(
				'configured' => true,
				'success'    => false,
				'error'      => __( 'Unexpected PageSpeed API response.', 'ux-studio' ),
			);
		}

		$score_raw = $body['lighthouseResult']['categories']['performance']['score'] ?? null;
		$score     = is_numeric( $score_raw ) ? (int) round( (float) $score_raw * 100 ) : 0;

		$audits = $body['lighthouseResult']['audits'] ?? array();
		$metrics = array(
			'first_contentful_paint' => $audits['first-contentful-paint']['displayValue'] ?? null,
			'largest_contentful_paint' => $audits['largest-contentful-paint']['displayValue'] ?? null,
		);

		$result = array(
			'configured' => true,
			'success'    => true,
			'score'      => $score,
			'url'        => $url,
			'metrics'    => $metrics,
		);

		set_transient( $cache_key, $result, HOUR_IN_SECONDS );

		return $result;
	}

	/**
	 * Recent rows from the shared activity log table.
	 *
	 * @param int $limit Max rows.
	 * @return array<int, array<string, mixed>>
	 */
	public function get_recent_activity( int $limit = 10 ): array {
		global $wpdb;

		$limit = max( 1, min( 50, $limit ) );

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT created_at, module, action, object_type, object_id FROM {$wpdb->prefix}uxstudio_activity_log ORDER BY id DESC LIMIT %d",
				$limit
			),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}
}
