<?php
/**
 * Content Sync module - hub <-> node content synchronization + operator SSO.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\ContentSync;

use UxStudio\Core\DB;
use UxStudio\Core\Security;
use UxStudio\Core\Settings;
use UxStudio\Modules\BaseModule;

defined( 'ABSPATH' ) || exit;

/**
 * A content-sync install runs in one of two modes:
 *
 *  - hub  : the console site. Registers connected node sites (SiteManager),
 *           and pushes posts (+ terms, featured media, ACF) to them over
 *           signed SyncClient calls (Hub). Admin-only, driven from the SPA.
 *  - node : a managed site. Exposes HMAC-authenticated REST endpoints
 *           (NodeController / SsoController) that receive and apply the hub's
 *           operations, plus a browser SSO redeemer (SsoRedeemer).
 *
 * Two independent HMAC channels exist and must not be confused:
 *  - central-app broker (central_app_url + hmac_secret, via Core\Broker) -
 *    unchanged, still consumed by instagram-feed / review-aggregator.
 *  - hub<->node channel (this module) - signed with the node's node_api_key
 *    via HmacAuth. The hub keeps a copy of each node's key as a per-site
 *    encrypted secret (SiteManager).
 *
 * Secrets (hmac_secret, node_api_key, per-site keys) go through
 * Security::store_secret() and are NEVER echoed back - only has_* booleans.
 */
final class Module extends BaseModule {

	/** Central-app broker secret (unchanged contract). */
	public const SECRET_HMAC = 'uxstudio_secret_content_sync_hmac';

	/** This node's shared HMAC key for the hub<->node channel. */
	public const SECRET_NODE_KEY = 'uxstudio_secret_content_sync_node_key';

	/** Module DB schema version. */
	private const DB_VERSION = 2;

	/**
	 * Register hooks.
	 */
	public function boot(): void {
		$this->ensure_tables();

		add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );

		if ( 'node' === (string) $this->settings->get( 'mode', 'hub' ) ) {
			( new SsoRedeemer() )->register();
		}
	}

	/**
	 * Create/upgrade this module's tables.
	 */
	private function ensure_tables(): void {
		DB::ensure_module_tables(
			'content-sync',
			self::DB_VERSION,
			static function ( int $from ): void {
				global $wpdb;
				$charset = $wpdb->get_charset_collate();

				dbDelta(
					"CREATE TABLE {$wpdb->prefix}uxstudio_content_sync_sites (
						id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
						created_at DATETIME NOT NULL,
						name VARCHAR(255) NOT NULL DEFAULT '',
						url VARCHAR(500) NOT NULL DEFAULT '',
						status VARCHAR(20) NOT NULL DEFAULT 'unchecked',
						acf_active TINYINT(1) NOT NULL DEFAULT 0,
						last_ping DATETIME NULL,
						last_sync DATETIME NULL,
						PRIMARY KEY  (id)
					) {$charset};"
				);

				dbDelta(
					"CREATE TABLE {$wpdb->prefix}uxstudio_content_sync_log (
						id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
						created_at DATETIME NOT NULL,
						site_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
						site_name VARCHAR(255) NOT NULL DEFAULT '',
						action VARCHAR(64) NOT NULL DEFAULT '',
						status VARCHAR(20) NOT NULL DEFAULT '',
						object_type VARCHAR(32) NOT NULL DEFAULT '',
						object_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
						object_title VARCHAR(255) NOT NULL DEFAULT '',
						message VARCHAR(1000) NOT NULL DEFAULT '',
						user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
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
						operator_email VARCHAR(190) NOT NULL DEFAULT '',
						operator_id_central BIGINT UNSIGNED NOT NULL DEFAULT 0,
						central_role VARCHAR(40) NOT NULL DEFAULT '',
						target_wp_role VARCHAR(40) NOT NULL DEFAULT '',
						user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
						action VARCHAR(40) NOT NULL DEFAULT '',
						return_to VARCHAR(500) NOT NULL DEFAULT '',
						ua_hash VARCHAR(64) NULL,
						ip VARCHAR(45) NULL,
						issued_at DATETIME NULL,
						expires_at DATETIME NOT NULL,
						consumed_at DATETIME NULL,
						revoked_at DATETIME NULL,
						fail_count INT UNSIGNED NOT NULL DEFAULT 0,
						PRIMARY KEY  (id),
						KEY token_hash (token_hash),
						KEY user_id (user_id),
						KEY ip (ip)
					) {$charset};"
				);
			}
		);
	}

	/**
	 * Register REST controllers. The admin console controller is always
	 * available (capability-gated); the HMAC node/SSO controllers only exist in
	 * node mode.
	 */
	public function register_rest_routes(): void {
		( new RestController( $this ) )->register_routes();

		if ( 'node' === (string) $this->settings->get( 'mode', 'hub' ) ) {
			( new NodeController() )->register_routes();
			( new SsoController() )->register_routes();
		}
	}

	/**
	 * REST controller class (admin console).
	 */
	public function rest_controller(): ?string {
		return RestController::class;
	}

	/**
	 * Read a content-sync setting from anywhere (used by SSO helpers).
	 *
	 * @param string $key     Setting key.
	 * @param mixed  $default Fallback.
	 * @return mixed
	 */
	public static function setting( string $key, $default = null ) {
		return ( new Settings( 'uxstudio_content_sync' ) )->get( $key, $default );
	}

	/**
	 * Settings schema for the embedded Settings tab.
	 *
	 * central_app_url and hmac_secret are the pre-existing central-app broker
	 * contract (consumed by instagram-feed / review-aggregator) - do not rename.
	 */
	public function settings_schema(): array {
		$roles = array(
			'editor'      => __( 'Editor', 'ux-studio' ),
			'author'      => __( 'Author', 'ux-studio' ),
			'contributor' => __( 'Contributor', 'ux-studio' ),
		);

		return array(
			array(
				'key'     => 'mode',
				'type'    => 'select',
				'label'   => __( 'Role of this site', 'ux-studio' ),
				'help'    => __( 'Hub pushes content out and manages other sites. Node receives content and exposes the signed endpoints.', 'ux-studio' ),
				'options' => array(
					'hub'  => __( 'Hub (console)', 'ux-studio' ),
					'node' => __( 'Node (managed site)', 'ux-studio' ),
				),
				'default' => 'hub',
			),
			array(
				'key'     => 'node_api_key',
				'type'    => 'text',
				'label'   => __( 'Node API key (this site)', 'ux-studio' ),
				'help'    => __( 'Shared HMAC secret this node uses to authenticate the hub. Give the same value to the hub when registering this site. Stored encrypted; leave blank to keep the current key.', 'ux-studio' ),
				'default' => '',
			),
			array(
				'key'     => 'node_default_author',
				'type'    => 'number',
				'label'   => __( 'Default author (node)', 'ux-studio' ),
				'help'    => __( 'User ID assigned to posts created by the hub when the payload has no author.', 'ux-studio' ),
				'default' => 0,
			),
			array(
				'key'     => 'sso_mapping_strategy',
				'type'    => 'select',
				'label'   => __( 'SSO user mapping', 'ux-studio' ),
				'help'    => __( 'auto: map by email, provision if missing. strict: only pre-mapped/known users. disabled: SSO off.', 'ux-studio' ),
				'options' => array(
					'auto'     => __( 'Auto (map by email, provision if missing)', 'ux-studio' ),
					'strict'   => __( 'Strict (existing users only)', 'ux-studio' ),
					'disabled' => __( 'Disabled', 'ux-studio' ),
				),
				'default' => 'auto',
			),
			array(
				'key'     => 'sso_allow_provision',
				'type'    => 'toggle',
				'label'   => __( 'Allow SSO user provisioning', 'ux-studio' ),
				'help'    => __( 'Create a WP user when an operator has none. Never creates administrators.', 'ux-studio' ),
				'default' => true,
			),
			array(
				'key'     => 'sso_provision_wp_role',
				'type'    => 'select',
				'label'   => __( 'Provisioned role', 'ux-studio' ),
				'help'    => __( 'Role given to auto-provisioned SSO users. Administrator is intentionally not selectable.', 'ux-studio' ),
				'options' => $roles,
				'default' => 'editor',
			),
			array(
				'key'     => 'sso_token_ttl_seconds',
				'type'    => 'number',
				'label'   => __( 'SSO token lifetime (seconds)', 'ux-studio' ),
				'help'    => __( 'Clamped to 15-300 seconds.', 'ux-studio' ),
				'default' => 60,
			),
			array(
				'key'     => 'sso_require_ua_match',
				'type'    => 'toggle',
				'label'   => __( 'Require user-agent match', 'ux-studio' ),
				'default' => true,
			),
			array(
				'key'     => 'sso_require_ip_match',
				'type'    => 'toggle',
				'label'   => __( 'Require IP match', 'ux-studio' ),
				'default' => false,
			),
			array(
				'key'     => 'sso_max_failed_redemptions',
				'type'    => 'number',
				'label'   => __( 'Max failed redemptions per IP / hour', 'ux-studio' ),
				'default' => 5,
			),
			array(
				'key'     => 'sso_max_issue_per_operator_hour',
				'type'    => 'number',
				'label'   => __( 'Max token issues per operator / hour', 'ux-studio' ),
				'default' => 30,
			),
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
				'label'   => __( 'Central app HMAC secret', 'ux-studio' ),
				'help'    => __( 'Shared secret used to sign/verify every request to and from the central app. Stored encrypted. Leave blank to keep the current secret.', 'ux-studio' ),
				'default' => '',
			),
		);
	}

	/**
	 * Intercept secret fields before they reach the plain settings option.
	 *
	 * @param array $input Raw input.
	 */
	public function save_settings( array $input ): array {
		if ( array_key_exists( 'hmac_secret', $input ) && '' !== (string) $input['hmac_secret'] ) {
			Security::store_secret( self::SECRET_HMAC, (string) $input['hmac_secret'] );
		}
		unset( $input['hmac_secret'] );

		if ( array_key_exists( 'node_api_key', $input ) && '' !== (string) $input['node_api_key'] ) {
			Security::store_secret( self::SECRET_NODE_KEY, (string) $input['node_api_key'] );
		}
		unset( $input['node_api_key'] );

		return parent::save_settings( $input );
	}

	/**
	 * Never leak secrets; expose only booleans for whether they are set.
	 */
	public function settings_values(): array {
		$values                       = parent::settings_values();
		$values['hmac_secret']        = '';
		$values['node_api_key']       = '';
		$values['has_hmac_secret']    = '' !== Security::get_secret( self::SECRET_HMAC );
		$values['has_node_api_key']   = '' !== Security::get_secret( self::SECRET_NODE_KEY );
		return $values;
	}

	/**
	 * Record a broker sync attempt (kept for instagram-feed / review-aggregator
	 * which call this after a Broker::call()).
	 *
	 * @param string $action  Short action key.
	 * @param string $status  'success'|'error'.
	 * @param int    $site_id Optional related site row id.
	 */
	public static function log_sync( string $action, string $status, int $site_id = 0 ): void {
		SyncLog::record(
			array(
				'site_id' => $site_id,
				'action'  => $action,
				'status'  => $status,
			)
		);
	}
}
