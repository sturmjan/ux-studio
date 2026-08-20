<?php
/**
 * Content Sync module - central broker connection to the central app.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\ContentSync;

use UxStudio\Core\Security;
use UxStudio\Modules\BaseModule;

defined( 'ABSPATH' ) || exit;

/**
 * Ported/redesigned from the legacy content-sync module (free+pro merged) as
 * a group-C module with its own SPA screen. This module is the central
 * broker: it is the ONLY place the central app's URL and shared HMAC secret
 * are stored. instagram-feed and review-aggregator have no credentials of
 * their own - they read this module's settings (central_app_url via
 * Settings::get(), hmac_secret via Security::get_secret()) and sign every
 * outbound/inbound broker call through UxStudio\Core\Broker.
 *
 * The HMAC secret is never stored in the plain uxstudio_content-sync
 * settings option; it goes through Security::store_secret() and is never
 * echoed back via REST (only a boolean "has_hmac_secret" is exposed).
 */
final class Module extends BaseModule {

	public const SECRET_HMAC = 'uxstudio_secret_content_sync_hmac';

	/**
	 * Register hooks.
	 */
	public function boot(): void {
		add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );

		\UxStudio\Core\DB::ensure_module_tables(
			'content-sync',
			1,
			function ( int $from ): void {
				global $wpdb;
				$charset = $wpdb->get_charset_collate();
				dbDelta(
					"CREATE TABLE {$wpdb->prefix}uxstudio_content_sync_sites (
						id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
						created_at DATETIME NOT NULL,
						name VARCHAR(255) NOT NULL DEFAULT '',
						url VARCHAR(500) NOT NULL DEFAULT '',
						last_sync DATETIME NULL,
						PRIMARY KEY  (id)
					) {$charset};"
				);
				dbDelta(
					"CREATE TABLE {$wpdb->prefix}uxstudio_content_sync_log (
						id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
						created_at DATETIME NOT NULL,
						site_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
						action VARCHAR(64) NOT NULL DEFAULT '',
						status VARCHAR(20) NOT NULL DEFAULT '',
						PRIMARY KEY  (id),
						KEY created_at (created_at),
						KEY site_id (site_id)
					) {$charset};"
				);
				dbDelta(
					"CREATE TABLE {$wpdb->prefix}uxstudio_central_sso_tokens (
						id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
						created_at DATETIME NOT NULL,
						token_hash VARCHAR(255) NOT NULL DEFAULT '',
						user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
						expires_at DATETIME NOT NULL,
						PRIMARY KEY  (id),
						KEY token_hash (token_hash),
						KEY user_id (user_id)
					) {$charset};"
				);
			}
		);
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
	 *
	 * central_app_url and hmac_secret are the shared contract consumed by
	 * instagram-feed and review-aggregator - do not rename these keys.
	 */
	public function settings_schema(): array {
		return array(
			array(
				'key'     => 'central_app_url',
				'type'    => 'text',
				'label'   => __( 'Central app URL', 'ux-studio' ),
				'help'    => __( 'Base URL of the central application acting as the broker for connected sites.', 'ux-studio' ),
				'default' => '',
			),
			array(
				'key'     => 'hmac_secret',
				'type'    => 'text',
				'label'   => __( 'HMAC secret', 'ux-studio' ),
				'help'    => __( 'Shared secret used to sign/verify every request to and from the central app. Stored encrypted. Leave blank to keep the current secret.', 'ux-studio' ),
				'default' => '',
			),
		);
	}

	/**
	 * Intercept the hmac_secret field before it reaches the plain settings
	 * option; everything else goes through the normal schema-based save.
	 *
	 * @param array $input Raw input.
	 */
	public function save_settings( array $input ): array {
		if ( array_key_exists( 'hmac_secret', $input ) && '' !== (string) $input['hmac_secret'] ) {
			Security::store_secret( self::SECRET_HMAC, (string) $input['hmac_secret'] );
		}
		unset( $input['hmac_secret'] );

		return parent::save_settings( $input );
	}

	/**
	 * Never leak the secret back to the client; expose only whether it's set.
	 */
	public function settings_values(): array {
		$values                    = parent::settings_values();
		$values['hmac_secret']     = '';
		$values['has_hmac_secret'] = '' !== Security::get_secret( self::SECRET_HMAC );
		return $values;
	}

	/**
	 * All registered sites, newest first.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function list_sites(): array {
		global $wpdb;
		$rows = $wpdb->get_results(
			"SELECT id, created_at, name, url, last_sync FROM {$wpdb->prefix}uxstudio_content_sync_sites ORDER BY id DESC",
			ARRAY_A
		);
		$rows = is_array( $rows ) ? $rows : array();
		return array_map(
			static function ( array $row ): array {
				$row['id'] = (int) $row['id'];
				return $row;
			},
			$rows
		);
	}

	/**
	 * Register a new site known to the broker.
	 *
	 * @param string $name Site label.
	 * @param string $url  Site URL.
	 * @return array<string, mixed>
	 */
	public function create_site( string $name, string $url ): array {
		global $wpdb;

		$wpdb->insert(
			"{$wpdb->prefix}uxstudio_content_sync_sites",
			array(
				'created_at' => current_time( 'mysql' ),
				'name'       => mb_substr( $name, 0, 255 ),
				'url'        => mb_substr( $url, 0, 500 ),
				'last_sync'  => null,
			),
			array( '%s', '%s', '%s', '%s' )
		);

		$id = (int) $wpdb->insert_id;

		\UxStudio\Core\ActivityLog::log( 'content-sync', 'site_added', 'site', $id );

		$rows = $this->list_sites();
		foreach ( $rows as $row ) {
			if ( $row['id'] === $id ) {
				return $row;
			}
		}
		return array();
	}

	/**
	 * Last 100 sync log rows, newest first.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function list_log(): array {
		global $wpdb;
		$rows = $wpdb->get_results(
			"SELECT id, created_at, site_id, action, status FROM {$wpdb->prefix}uxstudio_content_sync_log ORDER BY id DESC LIMIT 100",
			ARRAY_A
		);
		$rows = is_array( $rows ) ? $rows : array();
		return array_map(
			static function ( array $row ): array {
				$row['id']      = (int) $row['id'];
				$row['site_id'] = (int) $row['site_id'];
				return $row;
			},
			$rows
		);
	}

	/**
	 * Record a broker sync attempt. Called by this module's own site CRUD
	 * as well as by consumer modules (instagram-feed, review-aggregator)
	 * after a Broker::call() so every sync attempt is visible in one place.
	 *
	 * @param string $action  Short action key, e.g. 'instagram-feed:sync'.
	 * @param string $status  'success'|'error'.
	 * @param int    $site_id Optional related site row id.
	 */
	public static function log_sync( string $action, string $status, int $site_id = 0 ): void {
		global $wpdb;
		$wpdb->insert(
			"{$wpdb->prefix}uxstudio_content_sync_log",
			array(
				'created_at' => current_time( 'mysql' ),
				'site_id'    => $site_id,
				'action'     => mb_substr( $action, 0, 64 ),
				'status'     => mb_substr( $status, 0, 20 ),
			),
			array( '%s', '%d', '%s', '%s' )
		);
	}
}
