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
		'bot-throttle'          => array( 'enabled', 'whitelist_ip', 'whitelist_ua', 'verify_rdns', 'requests_per_minute', 'blacklist_ua' ),
		'vulnerability-scanner' => array( 'report_to_central' ),
		'ai-assistant'          => array( 'default_provider', 'chatbot_provider', 'mcp_enabled', 'operator_notification_email', 'contact_email', 'custom_system_prompt', 'chatbot_system_prompt', 'internal_rag_enabled', 'internal_guide_enabled', 'index_post_types', 'chatbot_enabled' ),
		'activity-log'          => array( 'alert_email', 'alert_*', 'retention_days' ),
		'service-requests'      => array( 'delivery_email' ),
		'email-health'          => array( 'test_recipient' ),
		'maintenance-mode'      => array( 'mode', 'bypass_ips', 'auth_type', 'access_roles', 'exclude_paths', 'allow_feeds', 'custom_layout', 'custom_css', 'existing_page' ),
		// The whole module guards the site (login lock, firewall, CSP, headers, lockout): every key is admin-write.
		'security-optimization' => array( '*' ),
		'disable-auto-updates'  => array( '*' ),
		'pixel-tag-manager'     => array( 'google_analytics', 'facebook_pixel', 'pinterest_tag' ),
		'dns-manager'           => array( 'manage_user_ids' ),
		'svg-upload'            => array( 'allowed_roles' ),
		'download-files'        => array( 'require_login_default' ),
		'google-review-request' => array( 'google_review_url', 'email_body', 'email_subject' ),
		'admin-customiser'      => array( 'login_custom_css', 'login_message', 'admin_custom_css', 'menu_lock_enabled' ),
		'image-optimizer'       => array( 'htaccess_delivery' ),
		'notice-board'          => array( 'from_email' ),
	);

	/**
	 * Module id => keys hidden from callers without the `admin` scope (values that are not credentials but
	 * whose knowledge weakens the protection).
	 *
	 * @var array<string, string[]>
	 */
	public const READ_HIDDEN = array(
		'smtp-email'            => array( 'username', 'gmail_client_id' ),
		'security-optimization' => array( 'login_url', 'ip_firewall_allowlist', 'upload_guard_whitelist_paths', 'upload_guard_custom_patterns', 'blocked_usernames_extra' ),
		'maintenance-mode'      => array( 'bypass_ips', 'exclude_paths' ),
		'bot-throttle'          => array( 'whitelist_ip', 'whitelist_ua' ),
		'content-sync'          => array( 'node_allowed_ips' ),
		'cron-control'          => array( 'central_allowed_ips' ),
	);

	/**
	 * Modules whose on/off state is itself a security decision: enabling or disabling them over MCP needs the
	 * `admin` scope (a write token could otherwise switch the login lock, logging or throttling off).
	 *
	 * @var string[]
	 */
	public const SECURITY_MODULES = array(
		'security-optimization',
		'activity-log',
		'bot-throttle',
		'smtp-email',
		'cron-control',
		'code-snippets',
		'file-manager',
		'user-switching',
		'admin-customiser',
		'maintenance-mode',
		'disable-auto-updates',
		'dns-manager',
		'svg-upload',
		'ai-assistant',
		'pixel-tag-manager',
	);

	/** Does toggling this module need the admin scope? */
	public static function is_security_module( string $module ): bool {
		return in_array( $module, self::SECURITY_MODULES, true );
	}

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
