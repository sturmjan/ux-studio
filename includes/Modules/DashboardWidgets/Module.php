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
 * Ported/redesigned from the legacy dashboard-widgets module. There is no
 * real wp-admin dashboard widget registration here - the UI lives entirely
 * in the SPA under this module's own page.
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

	/**
	 * Register hooks.
	 */
	public function boot(): void {
		add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );
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
