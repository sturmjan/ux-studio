<?php
/**
 * Connects the site to the Central App from the onboarding wizard (plugin side of the one-click connect).
 *
 * @package UxStudio
 */

namespace UxStudio\Core\Onboarding;

use UxStudio\Core\ActivityLog;
use UxStudio\Core\Modules;
use UxStudio\Core\Security;
use UxStudio\Modules\BaseModule;
use UxStudio\Modules\ContentSync\HmacAuth;
use UxStudio\Modules\ContentSync\Module as ContentSync;
use UxStudio\Modules\ContentSync\NodeController;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * The protocol (verified against the Central App, controllers/ConnectController.php, 2026-10-25):
 *
 *   The browser POSTs to  {central_app_url}/?page=connect_site  the fields
 *     site_url, site_name, api_key (at least 32 characters), timestamp (+-600 s), site_type (wordpress),
 *     return_url (same origin as site_url, otherwise the Central App drops it), signature
 *   signature = hash_hmac( 'sha256', site_url . '|' . site_name . '|' . timestamp . '|' . site_type, api_key )
 *   (site_type is part of the signed text whenever it is sent; sent as 'wordpress' here).
 *
 * The signature proves nothing by itself (the key travels in the same POST), so the Central App makes a live
 * challenge: it calls the site's signed content-sync ping, first with the legacy protocol, then with `uxstudio`.
 * That is why prepare() switches content-sync to node mode and stores the key BEFORE the browser leaves.
 * On the way back the Central App appends `ux1_connected=1` (or `ux1_connect_cancelled=1`) to return_url.
 *
 * The pure helpers (url check, signature, origin check, form fields) need no WordPress and are covered by
 * tests/onboarding-central-app.php.
 */
final class CentralApp {

	/** Production address of the Central App. */
	public const DEFAULT_URL = 'https://app.ux1.cz/';

	/** Path (and query) of the connect page on the Central App. */
	public const CONNECT_PAGE = '?page=connect_site';

	public const SITE_TYPE = 'wordpress';

	/** Option: unix time the connection was last prepared. */
	public const PREPARED_OPTION = 'uxstudio_ca_prepared';

	/** Where the wizard lands again after the Central App (admin screen + hash route). */
	public const RETURN_PATH = 'admin.php?page=ux-studio#/wizard?mode=new&step=ca&ca=done';

	private Modules $modules;

	/**
	 * @param Modules $modules Module registry.
	 */
	public function __construct( Modules $modules ) {
		$this->modules = $modules;
	}

	/* ── Pure helpers ── */

	/**
	 * A Central App address the administrator typed, checked and normalised: https, or plain http only for a
	 * machine of the developer (localhost, 127.0.0.1, [::1]); no credentials, query or fragment in it.
	 * Empty input means the production address.
	 *
	 * @param string $url Raw address.
	 * @return string|null `scheme://host[:port]/path/` or null when it is not acceptable.
	 */
	public static function normalize_base_url( string $url ): ?string {
		$url = trim( $url );
		if ( '' === $url ) {
			$url = self::DEFAULT_URL;
		}
		if ( strlen( $url ) > 200 || preg_match( '/[\x00-\x20\x7f\\\\]/', $url ) ) {
			return null;
		}
		$parts = parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			return null;
		}
		if ( isset( $parts['user'] ) || isset( $parts['pass'] ) || isset( $parts['query'] ) || isset( $parts['fragment'] ) ) {
			return null;
		}
		$scheme = strtolower( (string) $parts['scheme'] );
		$host   = strtolower( (string) $parts['host'] );
		if ( 'https' !== $scheme && ! ( 'http' === $scheme && self::is_loopback_host( $host ) ) ) {
			return null;
		}
		if ( ! preg_match( '/^(\[[0-9a-f:]+\]|[a-z0-9]([a-z0-9.-]*[a-z0-9])?)$/', $host ) ) {
			return null;
		}
		$path = (string) ( $parts['path'] ?? '' );
		if ( '' !== $path && ! preg_match( '#^/[A-Za-z0-9._~/%-]*$#', $path ) ) {
			return null;
		}
		if ( false !== strpos( $path, '..' ) ) {
			return null;
		}
		$port = isset( $parts['port'] ) ? ':' . (int) $parts['port'] : '';
		return $scheme . '://' . $host . $port . rtrim( $path, '/' ) . '/';
	}

	/**
	 * Hosts that mean "this very machine".
	 *
	 * @param string $host Lower case host.
	 */
	public static function is_loopback_host( string $host ): bool {
		return in_array( $host, array( 'localhost', '127.0.0.1', '[::1]' ), true );
	}

	/**
	 * Signature of the connect request.
	 *
	 * @param string $site_url  Site address as sent.
	 * @param string $site_name Site name as sent.
	 * @param int    $timestamp Unix time as sent.
	 * @param string $site_type `wordpress` (an empty type is left out of the signed text).
	 * @param string $api_key   Shared key.
	 */
	public static function signature( string $site_url, string $site_name, int $timestamp, string $site_type, string $api_key ): string {
		$payload = $site_url . '|' . $site_name . '|' . $timestamp;
		if ( '' !== $site_type ) {
			$payload .= '|' . $site_type;
		}
		return hash_hmac( 'sha256', $payload, $api_key );
	}

	/**
	 * The Central App only follows a return_url on the same origin as site_url.
	 *
	 * @param string $a Address.
	 * @param string $b Address.
	 */
	public static function same_origin( string $a, string $b ): bool {
		$pa = parse_url( $a );
		$pb = parse_url( $b );
		if ( ! is_array( $pa ) || ! is_array( $pb ) || empty( $pa['host'] ) || empty( $pb['host'] ) ) {
			return false;
		}
		$scheme = static fn( array $u ): string => strtolower( (string) ( $u['scheme'] ?? '' ) );
		$port   = static fn( array $u ): int => (int) ( $u['port'] ?? ( 'https' === strtolower( (string) ( $u['scheme'] ?? '' ) ) ? 443 : 80 ) );
		if ( ! in_array( $scheme( $pa ), array( 'http', 'https' ), true ) ) {
			return false;
		}
		return $scheme( $pa ) === $scheme( $pb ) && strtolower( (string) $pa['host'] ) === strtolower( (string) $pb['host'] ) && $port( $pa ) === $port( $pb );
	}

	/**
	 * The fields of the POST form and the address it goes to.
	 *
	 * @param string $base_url   Normalised Central App address.
	 * @param string $site_url   Site address (no trailing slash).
	 * @param string $site_name  Site name.
	 * @param string $api_key    Shared key.
	 * @param string $return_url Where the Central App sends the browser back (may be empty).
	 * @param int    $timestamp  Unix time.
	 * @return array{action:string,fields:array<string,string>}
	 */
	public static function connect_form( string $base_url, string $site_url, string $site_name, string $api_key, string $return_url, int $timestamp ): array {
		return array(
			'action' => $base_url . self::CONNECT_PAGE,
			'fields' => array(
				'site_url'   => $site_url,
				'site_name'  => $site_name,
				'api_key'    => $api_key,
				'timestamp'  => (string) $timestamp,
				'site_type'  => self::SITE_TYPE,
				'return_url' => $return_url,
				'signature'  => self::signature( $site_url, $site_name, $timestamp, self::SITE_TYPE, $api_key ),
			),
		);
	}

	/* ── WordPress side ── */

	/**
	 * Get the site ready for the Central App and build the form the browser posts.
	 *
	 * @param string $url Central App address typed by the administrator (empty = production).
	 * @return array<string, mixed>|WP_Error
	 */
	public function prepare( string $url ) {
		$base = self::normalize_base_url( $url );
		if ( null === $base ) {
			return new WP_Error( 'uxstudio_ca_bad_url', __( 'The address of the central app must start with https:// (http:// only works for localhost).', 'ux-studio' ), array( 'status' => 400 ) );
		}
		$module = $this->modules->instance( 'content-sync' );
		if ( ! $module instanceof ContentSync ) {
			return new WP_Error( 'uxstudio_ca_no_module', __( 'The Content Sync module is not available.', 'ux-studio' ), array( 'status' => 404 ) );
		}

		// A hub that already manages other sites must not silently become a node.
		$values = $module->settings_values();
		if ( 'node' !== ( $values['mode'] ?? 'hub' ) && $this->hub_has_sites() ) {
			return new WP_Error( 'uxstudio_ca_hub_in_use', __( 'This site is set up as a hub and manages other sites. It cannot be connected to the central app as a managed site without breaking that.', 'ux-studio' ), array( 'status' => 409 ) );
		}

		// 1) Snapshot first, so "Undo everything" puts the content-sync settings and key back.
		$applier = new Applier( $this->modules );
		$applier->snapshot_for( array( 'content-sync' ), array( ContentSync::SECRET_NODE_KEY, self::PREPARED_OPTION, NodeController::LAST_PING_OPTION ) );

		// 2) The module on, through the activation guard.
		$state = $applier->enable( 'content-sync', false );
		if ( ! in_array( $state['status'], array( 'enabled', 'already' ), true ) ) {
			return new WP_Error(
				'uxstudio_ca_module_blocked',
				$state['plugins']
					? sprintf(
						/* translators: %s: plugin names. */
						__( 'The Content Sync module cannot be turned on because it overlaps with %s.', 'ux-studio' ),
						implode( ', ', $state['plugins'] )
					)
					: __( 'The Content Sync module could not be turned on.', 'ux-studio' ),
				array( 'status' => 409 )
			);
		}

		// 3) Node mode, the address, the safe defaults; an existing key is kept.
		$key = Security::get_secret( ContentSync::SECRET_NODE_KEY );
		if ( strlen( $key ) < 32 ) {
			$key = HmacAuth::generate_key();
		}
		$module->save_settings(
			array(
				'mode'                => 'node',
				'central_app_url'     => $base,
				'node_api_key'        => $key,
				'sso_allow_admin'     => false,
				'node_allow_packages' => false,
			)
		);
		update_option( self::PREPARED_OPTION, time(), false );

		// 4) Does the site answer the signed ping the Central App is about to send? (A request of its own, so the node routes exist.)
		$ping = $this->self_check( $key );

		$site_url  = untrailingslashit( home_url() );
		$site_name = wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES );
		$return    = admin_url( self::RETURN_PATH );
		$warnings  = array();
		if ( ! self::same_origin( $return, $site_url ) ) {
			// The Central App would drop it anyway; the visitor comes back by hand.
			$return     = '';
			$warnings[] = 'return_url_origin';
		}
		$form = self::connect_form( $base, $site_url, $site_name, $key, $return, time() );

		// The key is not logged anywhere; the log only says that a connection was prepared and where to.
		ActivityLog::log( 'content-sync', 'ca_connect_prepared', 'setting', 0, array( 'central_app' => $base, 'ping' => $ping['ok'] ? 'ok' : 'failed' ) );

		return array(
			'action'   => $form['action'],
			'fields'   => $form['fields'],
			'ping'     => $ping,
			'warnings' => $warnings,
			'local'    => self::is_local_site( $site_url ),
		);
	}

	/**
	 * Status for the wizard: is the node ready, did the Central App call, may the CAPTCHA come from it.
	 *
	 * @return array<string, mixed>
	 */
	public function status(): array {
		$module   = $this->modules->instance( 'content-sync' );
		$values   = $module instanceof ContentSync ? $module->settings_values() : array();
		$enabled  = in_array( 'content-sync', $this->modules->enabled_ids(), true );
		$has_key  = strlen( Security::get_secret( ContentSync::SECRET_NODE_KEY ) ) >= 32;
		$prepared = (int) get_option( self::PREPARED_OPTION, 0 );
		$last     = (int) get_option( NodeController::LAST_PING_OPTION, 0 );

		$security = $this->modules->instance( 'security-optimization' );
		$sec      = $security ? (array) $security->settings_values() : array();
		$sec_on   = in_array( 'security-optimization', $this->modules->enabled_ids(), true );

		return array(
			'ready'           => $enabled && $has_key && 'node' === ( $values['mode'] ?? '' ) && '' !== (string) ( $values['central_app_url'] ?? '' ),
			'central_app_url' => (string) ( $values['central_app_url'] ?? '' ),
			'default_url'     => self::DEFAULT_URL,
			'prepared'        => $prepared,
			'last_contact'    => $last,
			'contact_after'   => $prepared > 0 && $last >= $prepared,
			'local'           => self::is_local_site( home_url() ),
			'captcha'         => array(
				'security_enabled' => $sec_on,
				'enabled'          => ! empty( $sec['captcha_enabled'] ) && 'ca' === ( $sec['captcha_key_source'] ?? '' ),
				'has_keys'         => '' !== (string) ( $sec['captcha_site_key'] ?? '' ),
			),
		);
	}

	/**
	 * Switch the CAPTCHA with keys from the Central App on or off (the Security save path provisions the keys).
	 *
	 * @param bool $enable On or off.
	 * @return array<string, mixed>|WP_Error
	 */
	public function set_captcha( bool $enable ) {
		$security = $this->modules->instance( 'security-optimization' );
		if ( ! $security instanceof BaseModule || ! in_array( 'security-optimization', $this->modules->enabled_ids(), true ) ) {
			return new WP_Error( 'uxstudio_ca_captcha_no_security', __( 'The Security module is off, so there is no CAPTCHA to turn on.', 'ux-studio' ), array( 'status' => 409 ) );
		}
		$status = $this->status();
		if ( $enable && ! $status['ready'] ) {
			return new WP_Error( 'uxstudio_ca_captcha_not_connected', __( 'Connect the site to the central app first.', 'ux-studio' ), array( 'status' => 409 ) );
		}
		( new Applier( $this->modules ) )->snapshot_for(
			array( 'security-optimization' ),
			array( 'uxstudio_secret_security_optimization_captcha_secret_key', 'uxstudio_security_optimization_cf_registered_domain' )
		);
		$security->save_settings(
			$enable
				? array(
					'captcha_enabled'    => true,
					'captcha_provider'   => 'turnstile',
					'captcha_key_source' => 'ca',
				)
				: array( 'captcha_enabled' => false )
		);
		return $this->status();
	}

	/**
	 * A signed ping to this very site, exactly the address the Central App calls.
	 *
	 * @param string $key Shared key.
	 * @return array{ok:bool,code:int,error:string}
	 */
	private function self_check( string $key ): array {
		$url       = untrailingslashit( home_url() ) . '/wp-json/uxstudio/v1/content-sync/node/ping';
		$timestamp = time();
		$nonce     = wp_generate_password( 24, false );
		$response  = wp_remote_get(
			$url,
			array(
				'timeout'     => 10,
				'redirection' => 0,
				// Same rule WordPress applies to its own loopback requests (a local test site has a self-signed certificate).
				'sslverify'   => (bool) apply_filters( 'https_local_ssl_verify', false ),
				'headers'     => array(
					HmacAuth::HEADER_SIGNATURE => HmacAuth::sign( 'GET', $url, '', $timestamp, $nonce, $key ),
					HmacAuth::HEADER_TIMESTAMP => (string) $timestamp,
					HmacAuth::HEADER_NONCE     => $nonce,
					HmacAuth::HEADER_VERSION   => '1',
					'X-UXS-Selfcheck'          => '1',
				),
			)
		);
		if ( is_wp_error( $response ) ) {
			return array(
				'ok'    => false,
				'code'  => 0,
				'error' => $response->get_error_message(),
			);
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		return array(
			'ok'    => 200 === $code,
			'code'  => $code,
			'error' => 200 === $code ? '' : 'HTTP ' . $code,
		);
	}

	/**
	 * Does the hub list sites it manages?
	 */
	private function hub_has_sites(): bool {
		global $wpdb;
		$table = $wpdb->prefix . 'uxstudio_content_sync_sites';
		if ( $table !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) ) {
			return false;
		}
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ) > 0; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- fixed table name.
	}

	/**
	 * A site on this machine (the Central App on the internet cannot reach it).
	 *
	 * @param string $site_url Site address.
	 */
	public static function is_local_site( string $site_url ): bool {
		$host = strtolower( (string) parse_url( $site_url, PHP_URL_HOST ) );
		foreach ( array( '.localhost', '.local', '.test' ) as $suffix ) {
			if ( substr( $host, -strlen( $suffix ) ) === $suffix ) {
				return true;
			}
		}
		return self::is_loopback_host( $host ) || '::1' === $host;
	}
}
