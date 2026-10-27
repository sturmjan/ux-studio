<?php
/**
 * The one registry of what never travels in a settings export (and what may travel only encrypted).
 *
 * @package UxStudio
 */

namespace UxStudio\Core\Transfer;

use UxStudio\Core\Mcp\Secrets;

defined( 'ABSPATH' ) || exit;

/**
 * Export is a WHITELIST by the settings schema of each module (never a dump of the option), and this class
 * removes from that whitelist everything that belongs to ONE site:
 *
 *  - connections and identity of the site (Central App key / URL / HMAC, allowed IPs of the Central App,
 *    reCAPTCHA / Turnstile keys bound to a host name),
 *  - ids that mean something only in this database (pages, posts, users, terms),
 *  - anything that looks like a credential and is not a registered portable secret (the generic
 *    `Mcp\Secrets` key pattern decides),
 *  - state, caches, logs, schema versions and wizard markers (these are not in any schema, so they cannot
 *    get into an export; the pattern list below documents them and guards the option names).
 *
 * Portable secrets (API keys the administrator typed in) are listed in SECRET_OPTIONS: they go only into the
 * encrypted `secrets` block of the file, and only when the administrator asked for it.
 *
 * Pure: no WordPress calls, so tests/transfer-envelope.php covers it.
 */
final class NonPortable {

	/**
	 * Module id => setting keys that are never exported or imported (site specific).
	 *
	 * @var array<string, string[]>
	 */
	public const KEYS = array(
		// Central App connection: the key, address and HMAC secret identify THIS site to the hub.
		'content-sync'          => array( 'mode', 'node_api_key', 'central_app_url', 'hmac_secret', 'node_allowed_ips', 'node_default_author' ),
		'third-party-login'     => array( 'central_app_url', 'hmac_secret' ),
		'bot-throttle'          => array( 'central_report_secret' ),
		'cron-control'          => array( 'central_allowed_ips' ),
		'review-aggregator'     => array( 'central_profile_id' ),
		// Ids of users, pages and posts of this database.
		'dns-manager'           => array( 'manage_user_ids' ),
		'opening-hours'         => array( 'schema_location_ids', 'schema_pages' ),
		'ai-markdown'           => array( 'exclude_ids' ),
		'maintenance-mode'      => array( 'existing_page', 'custom_layout' ),
		// Keys of a CAPTCHA belong to one host name; copying them to another host locks the login form.
		'security-optimization' => array( 'captcha_site_key', 'captcha_secret_key', 'captcha_key_source', 'existing_page' ),
	);

	/**
	 * Portable secrets: module id => setting key => option that holds the encrypted value on the site.
	 * They are exported only inside the encrypted block and written back through the module's own save path
	 * (the module stores them with Security::store_secret).
	 *
	 * @var array<string, array<string, string>>
	 */
	public const SECRET_OPTIONS = array(
		'smtp-email'            => array(
			'password'              => 'uxstudio_secret_smtp_password',
			'brevo_api_key'         => 'uxstudio_secret_smtp_brevo_api_key',
			'gmail_client_secret'   => 'uxstudio_secret_smtp_gmail_client_secret',
			'sendgrid_api_key'      => 'uxstudio_secret_smtp_sendgrid_api_key',
			'mailgun_api_key'       => 'uxstudio_secret_smtp_mailgun_api_key',
			'postmark_server_token' => 'uxstudio_secret_smtp_postmark_server_token',
			'ses_access_key'        => 'uxstudio_secret_smtp_ses_access_key',
			'ses_secret_key'        => 'uxstudio_secret_smtp_ses_secret_key',
		),
		'stock-photos'          => array(
			'pexels_api_key'    => 'uxstudio_secret_stock_pexels',
			'pixabay_api_key'   => 'uxstudio_secret_stock_pixabay',
			'unsplash_api_key'  => 'uxstudio_secret_stock_unsplash',
			'flickr_api_key'    => 'uxstudio_secret_stock_flickr',
			'mapillary_api_key' => 'uxstudio_secret_stock_mapillary',
			'giphy_api_key'     => 'uxstudio_secret_stock_giphy',
		),
		'ai-assistant'          => array(
			'claude_api_key'   => 'uxstudio_secret_ai_assistant_claude_api_key',
			'openai_api_key'   => 'uxstudio_secret_ai_assistant_openai_api_key',
			'deepseek_api_key' => 'uxstudio_secret_ai_assistant_deepseek_api_key',
		),
		'security-optimization' => array(
			'captcha_cf_api_token' => 'uxstudio_secret_security_optimization_cf_api_token',
		),
		'opening-hours'         => array(
			'google_maps_api_key' => 'uxstudio_secret_opening_hours_google_maps',
			'mapy_cz_api_key'     => 'uxstudio_secret_opening_hours_mapy_cz',
		),
		'instagram-feed'        => array(
			'app_secret' => 'uxstudio_secret_instagram_app_secret',
		),
		'dashboard-widgets'     => array(
			'pagespeed_api_key'       => 'uxstudio_secret_dashboard_pagespeed_key',
			'ga_service_account_json' => 'uxstudio_secret_dashboard_ga_json',
		),
	);

	/**
	 * Connections that live outside the settings schema and are always reported as "set up by hand":
	 * module id => [ label, option that holds the (encrypted) token ].
	 *
	 * @var array<string, array<int, array{label:string,option:string}>>
	 */
	public const MANUAL_CONNECTIONS = array(
		'smtp-email'     => array(
			array(
				'label'  => 'Gmail connection (OAuth)',
				'option' => 'uxstudio_secret_smtp_gmail_refresh_token',
			),
		),
		'instagram-feed' => array(
			array(
				'label'  => 'Instagram connection (access token)',
				'option' => 'uxstudio_secret_instagram_token',
			),
		),
	);

	/**
	 * Keys the generic credential pattern catches by name only ("token" in a limit, "pass" in "bypass"): plain
	 * configuration, not credentials. Reviewed one by one.
	 *
	 * @var array<string, string[]>
	 */
	public const HARMLESS = array(
		'ai-assistant'     => array( 'monthly_token_limit' ),
		'content-sync'     => array( 'sso_token_ttl_seconds' ),
		'maintenance-mode' => array( 'bypass_ips' ),
		'redirects'        => array( 'pass_query' ),
	);

	/**
	 * Option names (and patterns, `*` = any run of characters) that are state, caches, logs, markers or
	 * secrets: never part of an export, whatever somebody puts into a file. Documentation of the whitelist
	 * and a guard for code that builds option names.
	 *
	 * @var string[]
	 */
	public const STATE_OPTION_PATTERNS = array(
		'uxstudio_wizard_*',
		'uxstudio_*_pending',
		'uxstudio_login_url_pending',
		'uxstudio_*snapshot*',
		'uxstudio_starter_*',
		'uxstudio_module_db_versions',
		'uxstudio_modules_meta_cache',
		'uxstudio_secret_*',
		'uxstudio_menu_lock_origin',
		'uxstudio_rl_*',
		'uxstudio_csp_*',
		'uxstudio_overlap_*',
		'uxstudio_activity_*',
		'uxstudio_*_cache',
		'uxstudio_*_log',
		'uxstudio_*_state',
		'uxstudio_*_version',
		'uxstudio_*_db_version',
		'uxstudio_mcp_*',
		'uxstudio_central_*',
		'uxstudio_node_*',
		'uxstudio_admin_notices*',
	);

	/**
	 * Is this option name state / secret / cache (never exported)?
	 *
	 * @param string $name Option name.
	 */
	public static function is_state_option( string $name ): bool {
		foreach ( self::STATE_OPTION_PATTERNS as $pattern ) {
			$regex = '/^' . str_replace( '\*', '.*', preg_quote( $pattern, '/' ) ) . '$/';
			if ( 1 === preg_match( $regex, $name ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Portable secrets of a module: setting key => option name.
	 *
	 * @param string $module Module id.
	 * @return array<string, string>
	 */
	public static function secret_fields( string $module ): array {
		return self::SECRET_OPTIONS[ $module ] ?? array();
	}

	/**
	 * Is the key a portable secret of the module?
	 *
	 * @param string $module Module id.
	 * @param string $key    Setting key.
	 */
	public static function is_secret( string $module, string $key ): bool {
		return isset( self::SECRET_OPTIONS[ $module ][ $key ] );
	}

	/**
	 * Why a schema field never travels, or '' when it may. Order: explicit list, a portable secret (its own
	 * block), a credential-looking key that is not on the harmless list.
	 *
	 * @param string               $module Module id.
	 * @param array<string, mixed> $field  Schema field.
	 */
	public static function reason( string $module, array $field ): string {
		$key = (string) ( $field['key'] ?? '' );
		if ( '' === $key ) {
			return 'invalid';
		}
		if ( in_array( $key, self::KEYS[ $module ] ?? array(), true ) ) {
			return 'site';
		}
		if ( self::is_secret( $module, $key ) ) {
			return ''; // Portable, but only as an encrypted secret (see is_secret()).
		}
		if ( Secrets::is_secret_key( $key ) && ! in_array( $key, self::HARMLESS[ $module ] ?? array(), true ) ) {
			return 'credential';
		}
		return '';
	}

	/**
	 * Does this schema field travel as an ordinary (clear text) setting?
	 *
	 * @param string               $module Module id.
	 * @param array<string, mixed> $field  Schema field.
	 */
	public static function is_portable( string $module, array $field ): bool {
		return '' === self::reason( $module, $field ) && ! self::is_secret( $module, (string) ( $field['key'] ?? '' ) );
	}

	/**
	 * Split a module's schema into the three groups.
	 *
	 * @param string                           $module Module id.
	 * @param array<int, array<string, mixed>> $schema Settings schema.
	 * @return array{portable:string[],secret:string[],manual:array<string,string>} manual: key => reason.
	 */
	public static function classify( string $module, array $schema ): array {
		$out = array(
			'portable' => array(),
			'secret'   => array(),
			'manual'   => array(),
		);
		foreach ( $schema as $field ) {
			if ( ! is_array( $field ) || empty( $field['key'] ) ) {
				continue;
			}
			if ( ! empty( $field['legacy'] ) ) {
				continue; // A key of an older format: still read from an old file, never written to a new one.
			}
			$key    = (string) $field['key'];
			$reason = self::reason( $module, $field );
			if ( '' !== $reason ) {
				$out['manual'][ $key ] = $reason;
			} elseif ( self::is_secret( $module, $key ) ) {
				$out['secret'][] = $key;
			} else {
				$out['portable'][] = $key;
			}
		}
		return $out;
	}

	/**
	 * Is a stored value a sealed secret (`uxs1:` envelope of Security::seal)? Such a value must never be
	 * written to a file, whatever key holds it.
	 *
	 * @param mixed $value Value.
	 */
	public static function is_sealed( $value ): bool {
		if ( is_string( $value ) ) {
			return 0 === strpos( $value, 'uxs1:' );
		}
		if ( is_array( $value ) ) {
			foreach ( $value as $one ) {
				if ( self::is_sealed( $one ) ) {
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * Is the value empty in the sense of "nothing to set up by hand"?
	 *
	 * @param mixed $value Value.
	 */
	public static function is_blank( $value ): bool {
		return null === $value || '' === $value || array() === $value || false === $value || 0 === $value || '0' === $value;
	}
}
