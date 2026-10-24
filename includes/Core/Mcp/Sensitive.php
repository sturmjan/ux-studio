<?php
/**
 * Settings that decide WHERE secrets and data go, or that guard the site.
 *
 * @package UxStudio
 */

namespace UxStudio\Core\Mcp;

defined( 'ABSPATH' ) || exit;

/**
 * A token with the `write` scope may change ordinary settings, but not the ones that can be turned into a
 * way to steal what is stored: change the SMTP host and the stored password is sent to the new host, change
 * the central app address and the HMAC-signed traffic goes elsewhere. Those keys (and the ones that switch
 * security off) need the `admin` scope. A few non-secret values are also hidden from `read` tokens because
 * knowing them weakens the protection they give (the secret login address, allowlists of bypass IPs).
 *
 * A field can opt in from its own schema: `'sensitive' => true` (admin to write) and `'mcp_hidden' => true`
 * (hidden from read-only callers). The lists below cover the fields that exist today; an entry ending in `*`
 * is a prefix.
 */
final class Sensitive {

	/**
	 * Module id => keys only an `admin` token may write over MCP.
	 *
	 * Outgoing destinations of stored credentials or data (mail transports and regions, central app, AI providers,
	 * notification recipients), switches of security features and bypass lists.
	 *
	 * @var array<string, string[]>
	 */
	public const ADMIN_WRITE = array(
		'smtp-email'            => array( 'mailer', 'fallback_mailer', 'host', 'port', 'encryption', 'username', 'sendgrid_region', 'mailgun_domain', 'mailgun_region', 'ses_region', 'gmail_client_id', 'postmark_message_stream' ),
		'content-sync'          => array( 'mode', 'central_app_url', 'node_allowed_ips', 'node_allow_packages', 'node_default_author', 'sso_*' ),
		'third-party-login'     => array( 'central_app_url', 'enabled_providers', 'allowed_roles', 'auto_create_enabled', 'auto_create_role' ),
		'cron-control'          => array( 'mode', 'central_allowed_ips', 'cron_whitelist_extra' ),
		'bot-throttle'          => array( 'enabled', 'whitelist_ip', 'whitelist_ua', 'verify_rdns' ),
		'vulnerability-scanner' => array( 'report_to_central' ),
		'ai-assistant'          => array( 'default_provider', 'chatbot_provider', 'mcp_enabled', 'operator_notification_email', 'contact_email' ),
		'activity-log'          => array( 'alert_email' ),
		'service-requests'      => array( 'delivery_email' ),
		'email-health'          => array( 'test_recipient' ),
		'maintenance-mode'      => array( 'bypass_ips', 'auth_type', 'access_roles', 'exclude_paths', 'allow_feeds' ),
		'security-optimization' => array(
			'ip_firewall_*',
			'country_blocklist_enabled',
			'blocked_countries',
			'canonical_redirect',
			'force_https',
			'security_headers',
			'csp_*',
			'protect_wp_config',
			'block_sensitive_files',
			'login_url_enabled',
			'login_url',
			'disabled_behavior',
			'url_redirect',
			'existing_page',
			'limit_login_attempts',
			'max_attempts',
			'lockout_time',
			'lockout_notify_email',
			'lockout_progressive',
			'lockout_max_hours',
			'notify_admin',
			'two_factor_*',
			'block_usernames',
			'blocked_usernames_extra',
			'captcha_*',
			'disable_xmlrpc',
			'restrict_rest_api',
			'block_user_enumeration',
			'disable_file_editing',
			'protect_login',
			'restrict_mime_types',
			'upload_guard_*',
		),
	);

	/**
	 * Module id => keys hidden from callers without the `admin` scope (values that are not credentials but
	 * whose knowledge weakens the protection).
	 *
	 * @var array<string, string[]>
	 */
	public const READ_HIDDEN = array(
		'security-optimization' => array( 'login_url', 'ip_firewall_allowlist', 'upload_guard_whitelist_paths', 'upload_guard_custom_patterns', 'blocked_usernames_extra' ),
		'maintenance-mode'      => array( 'bypass_ips', 'exclude_paths' ),
		'bot-throttle'          => array( 'whitelist_ip', 'whitelist_ua' ),
		'content-sync'          => array( 'node_allowed_ips' ),
		'cron-control'          => array( 'central_allowed_ips' ),
	);

	/** Marker shown instead of a hidden value. */
	public const HIDDEN = '[admin scope required]';

	/**
	 * Does `$key` match an entry (exact or `prefix*`)?
	 *
	 * @param string   $key     Setting key.
	 * @param string[] $entries List of keys / prefixes.
	 */
	private static function matches( string $key, array $entries ): bool {
		foreach ( $entries as $entry ) {
			if ( '*' === substr( $entry, -1 ) ? 0 === strpos( $key, substr( $entry, 0, -1 ) ) : $entry === $key ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Keys of a payload that only an admin token may write.
	 *
	 * @param string                           $module Module id.
	 * @param array<int, array<string, mixed>> $schema Module settings schema.
	 * @param string[]                         $keys   Keys the caller wants to set.
	 * @return string[]
	 */
	public static function admin_write_keys( string $module, array $schema, array $keys ): array {
		$flagged = array();
		foreach ( $schema as $field ) {
			if ( is_array( $field ) && ! empty( $field['sensitive'] ) && isset( $field['key'] ) ) {
				$flagged[] = (string) $field['key'];
			}
		}
		$entries = (array) ( self::ADMIN_WRITE[ $module ] ?? array() );
		return array_values(
			array_filter(
				array_map( 'strval', $keys ),
				static fn( string $key ): bool => in_array( $key, $flagged, true ) || self::matches( $key, $entries )
			)
		);
	}

	/**
	 * Is this key hidden from callers without the admin scope?
	 *
	 * @param string                           $module Module id.
	 * @param array<int, array<string, mixed>> $schema Module settings schema.
	 * @param string                           $key    Setting key.
	 */
	public static function is_read_hidden( string $module, array $schema, string $key ): bool {
		foreach ( $schema as $field ) {
			if ( is_array( $field ) && ! empty( $field['mcp_hidden'] ) && (string) ( $field['key'] ?? '' ) === $key ) {
				return true;
			}
		}
		return self::matches( $key, (array) ( self::READ_HIDDEN[ $module ] ?? array() ) );
	}

	/**
	 * Replace the hidden values of a settings payload (top level keys).
	 *
	 * @param string                           $module Module id.
	 * @param array<int, array<string, mixed>> $schema Module settings schema.
	 * @param array<string, mixed>             $values Values.
	 * @return array{values:array<string, mixed>,hidden:string[]}
	 */
	public static function hide_values( string $module, array $schema, array $values ): array {
		$hidden = array();
		foreach ( $values as $key => $value ) {
			if ( self::is_read_hidden( $module, $schema, (string) $key ) && '' !== $value && array() !== $value && null !== $value && false !== $value ) {
				$values[ $key ] = self::HIDDEN;
				$hidden[]       = (string) $key;
			}
		}
		return array(
			'values' => $values,
			'hidden' => $hidden,
		);
	}
}
