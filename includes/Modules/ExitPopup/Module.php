<?php
/**
 * Exit Popup module - shows an email capture popup on exit-intent.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\ExitPopup;

use UxStudio\Core\ActivityLog;
use UxStudio\Core\DB;
use UxStudio\Modules\BaseModule;

defined( 'ABSPATH' ) || exit;

/**
 * Simplified port of the legacy exit-popup module: a single settings screen
 * (headline, body, delay, per-post-type targeting, show-once-per-session),
 * a public REST endpoint for the email subscription and a CSV export of the
 * captured emails. No raw IPs are ever stored (GDPR) - only a salted hash.
 */
final class Module extends BaseModule {

	/**
	 * Register hooks.
	 */
	public function boot(): void {
		DB::ensure_module_tables(
			'exit-popup',
			1,
			function ( int $from ): void {
				global $wpdb;
				$charset = $wpdb->get_charset_collate();
				dbDelta(
					"CREATE TABLE {$wpdb->prefix}uxstudio_exit_popup_emails (
						id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
						created_at DATETIME NOT NULL,
						email VARCHAR(255) NOT NULL DEFAULT '',
						page_url VARCHAR(500) NOT NULL DEFAULT '',
						ip_hash VARCHAR(64) NOT NULL DEFAULT '',
						PRIMARY KEY  (id),
						KEY created_at (created_at)
					) {$charset};"
				);
			}
		);

		if ( ! is_admin() ) {
			add_action( 'wp_enqueue_scripts', array( $this, 'maybe_enqueue_assets' ) );
		}

		add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );
		add_action( 'admin_post_uxstudio_exit_popup_export', array( $this, 'export_csv' ) );
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
		$post_type_options = array();
		foreach ( get_post_types( array( 'public' => true ), 'objects' ) as $post_type ) {
			$post_type_options[ $post_type->name ] = $post_type->label;
		}

		return array(
			array(
				'key'     => 'enabled_post_types',
				'type'    => 'multiselect',
				'label'   => __( 'Show on', 'ux-studio' ),
				'help'    => __( 'Post types on which the exit popup can appear.', 'ux-studio' ),
				'options' => $post_type_options,
				'default' => array( 'post', 'page' ),
			),
			array(
				'key'     => 'headline',
				'type'    => 'text',
				'label'   => __( 'Headline', 'ux-studio' ),
				'default' => __( "Wait, don't go!", 'ux-studio' ),
			),
			array(
				'key'     => 'body',
				'type'    => 'richtext',
				'label'   => __( 'Body', 'ux-studio' ),
				'default' => '',
			),
			array(
				'key'     => 'delay_seconds',
				'type'    => 'number',
				'label'   => __( 'Delay before enabling exit-intent detection (seconds)', 'ux-studio' ),
				'default' => 2,
			),
			array(
				'key'     => 'show_once_per_session',
				'type'    => 'toggle',
				'label'   => __( 'Show only once per session', 'ux-studio' ),
				'default' => true,
			),
		);
	}

	/**
	 * Conditionally enqueue the exit-intent popup script on singular views of
	 * an enabled post type.
	 */
	public function maybe_enqueue_assets(): void {
		$enabled_post_types = (array) $this->settings->get( 'enabled_post_types', array( 'post', 'page' ) );
		if ( empty( $enabled_post_types ) || ! is_singular( $enabled_post_types ) ) {
			return;
		}

		$version = defined( 'UXSTUDIO_VERSION' ) ? UXSTUDIO_VERSION : false;

		wp_enqueue_script(
			'uxstudio-exit-popup',
			plugins_url( 'assets/exit-popup.js', __FILE__ ),
			array(),
			$version,
			true
		);

		wp_localize_script(
			'uxstudio-exit-popup',
			'uxStudioExitPopup',
			array(
				'headline'            => (string) $this->settings->get( 'headline', __( "Wait, don't go!", 'ux-studio' ) ),
				'body'                => (string) $this->settings->get( 'body', '' ),
				'delaySeconds'        => (int) $this->settings->get( 'delay_seconds', 2 ),
				'showOncePerSession'  => (bool) $this->settings->get( 'show_once_per_session', true ),
				'restUrl'             => esc_url_raw( rest_url( 'uxstudio/v1/exit-popup/subscribe' ) ),
				'restNonce'           => wp_create_nonce( 'wp_rest' ),
				'emailLabel'          => __( 'Email address', 'ux-studio' ),
				'submitLabel'         => __( 'Subscribe', 'ux-studio' ),
				'closeLabel'          => __( 'Close', 'ux-studio' ),
				'successMessage'      => __( 'Thanks! Check your inbox soon.', 'ux-studio' ),
				'errorMessage'        => __( 'Something went wrong, please try again.', 'ux-studio' ),
			)
		);
	}

	/**
	 * Last 200 subscriber rows, newest first.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function get_subscribers(): array {
		global $wpdb;
		$rows = $wpdb->get_results(
			"SELECT id, created_at, email, page_url FROM {$wpdb->prefix}uxstudio_exit_popup_emails ORDER BY id DESC LIMIT 200",
			ARRAY_A
		);
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Stream a CSV export of all captured subscribers (admin-post handler).
	 */
	public function export_csv(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'ux-studio' ) );
		}

		check_admin_referer( 'uxstudio_exit_popup_export' );

		global $wpdb;
		$rows = $wpdb->get_results(
			"SELECT email, page_url, created_at FROM {$wpdb->prefix}uxstudio_exit_popup_emails ORDER BY id DESC",
			ARRAY_A
		);
		$rows = is_array( $rows ) ? $rows : array();

		ActivityLog::log( 'exit-popup', 'export', 'exit_popup_emails', 0, array( 'rows' => count( $rows ) ) );

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="exit-popup-subscribers.csv"' );

		$out = fopen( 'php://output', 'w' );
		fputcsv( $out, array( 'email', 'page_url', 'created_at' ) );
		foreach ( $rows as $row ) {
			fputcsv(
				$out,
				array(
					(string) $row['email'],
					(string) $row['page_url'],
					(string) $row['created_at'],
				)
			);
		}
		fclose( $out );
		exit;
	}
}
