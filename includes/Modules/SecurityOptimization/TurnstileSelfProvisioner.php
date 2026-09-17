<?php
/**
 * Auto-provisions Cloudflare Turnstile keys directly against Cloudflare's
 * API, using an API token the site owner pastes in (captcha_cf_api_token) -
 * for sites that are NOT paired with the central app.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\SecurityOptimization;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Mirrors centrani-app's core/CloudflareTurnstileClient.php, but manages a
 * widget scoped to THIS site's own domain only, under the site owner's own
 * Cloudflare account - no shared widget, no central app involved.
 *
 * Cloudflare never returns `secret` outside widget creation/rotation - GET
 * and PATCH responses omit it - so the secret is stored locally (encrypted)
 * on first creation and reused afterwards, same as on the CA side.
 */
final class TurnstileSelfProvisioner {

	private const API_BASE   = 'https://api.cloudflare.com/client/v4';
	private const WIDGET_OPT = 'uxstudio_security_optimization_cf_widget_id';
	private const TIMEOUT    = 20;

	/**
	 * @return array{site_key:string,secret_key:string}|WP_Error
	 */
	public static function provision( Module $module ) {
		$token      = $module->captcha_cf_api_token();
		$account_id = (string) $module->setting( 'captcha_cf_account_id', '' );

		if ( '' === $token || '' === $account_id ) {
			return new WP_Error( 'uxstudio_turnstile_self_not_configured', __( 'Cloudflare API token or account ID is missing.', 'ux-studio' ) );
		}

		$domain = wp_parse_url( home_url( '/' ), PHP_URL_HOST );
		if ( ! is_string( $domain ) || '' === $domain ) {
			return new WP_Error( 'uxstudio_turnstile_self_domain', __( 'Could not determine this site\'s domain.', 'ux-studio' ) );
		}

		$widget_id = (string) get_option( self::WIDGET_OPT, '' );

		if ( '' === $widget_id ) {
			return self::create_widget( $account_id, $token, $domain );
		}

		return self::ensure_domain( $account_id, $token, $widget_id, $domain, $module );
	}

	/**
	 * @return array{site_key:string,secret_key:string}|WP_Error
	 */
	private static function create_widget( string $account_id, string $token, string $domain ) {
		$response = self::request(
			'POST',
			"/accounts/{$account_id}/challenges/widgets",
			$token,
			array(
				'name'    => 'ux-studio (auto-provisioned)',
				'mode'    => 'managed',
				'domains' => array( $domain ),
			)
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$site_key   = (string) ( $response['result']['sitekey'] ?? '' );
		$secret_key = (string) ( $response['result']['secret'] ?? '' );
		// Turnstile has no separate widget id - GET/PUT/DELETE address the
		// widget by its sitekey (confirmed against the live API 17.9.2026;
		// the create response never contains an "id" field).
		$widget_id  = $site_key;

		if ( '' === $site_key || '' === $secret_key ) {
			return new WP_Error( 'uxstudio_turnstile_self_incomplete', __( 'Cloudflare did not return a complete widget (sitekey/secret).', 'ux-studio' ) );
		}

		update_option( self::WIDGET_OPT, $widget_id );

		return array(
			'site_key'   => $site_key,
			'secret_key' => $secret_key,
		);
	}

	/**
	 * @return array{site_key:string,secret_key:string}|WP_Error
	 */
	private static function ensure_domain( string $account_id, string $token, string $widget_id, string $domain, Module $module ) {
		$current = self::request( 'GET', "/accounts/{$account_id}/challenges/widgets/{$widget_id}", $token );
		if ( is_wp_error( $current ) ) {
			return $current;
		}

		$domains = array_map( 'strval', (array) ( $current['result']['domains'] ?? array() ) );
		if ( ! in_array( $domain, $domains, true ) ) {
			$domains[] = $domain;
			// PATCH is rejected for API-token auth on this endpoint ("Method
			// not allowed for this authentication scheme", HTTP 405, found
			// 17.9.2026) - PUT works but needs the full resource resent, not
			// just the changed field.
			$updated = self::request(
				'PUT',
				"/accounts/{$account_id}/challenges/widgets/{$widget_id}",
				$token,
				array(
					'name'    => (string) ( $current['result']['name'] ?? 'ux-studio (auto-provisioned)' ),
					'mode'    => (string) ( $current['result']['mode'] ?? 'managed' ),
					'domains' => $domains,
				)
			);
			if ( is_wp_error( $updated ) ) {
				return $updated;
			}
		}

		$site_key   = (string) $module->setting( 'captcha_site_key', '' );
		$secret_key = $module->captcha_secret_key();
		if ( '' === $site_key || '' === $secret_key ) {
			// Local key material got lost even though the widget still exists -
			// re-create it so a fresh secret is available (rotates the old one,
			// but that's the only way to recover it - Cloudflare never re-shows it).
			return self::create_widget( $account_id, $token, $domain );
		}

		return array(
			'site_key'   => $site_key,
			'secret_key' => $secret_key,
		);
	}

	/**
	 * @param array|null $body
	 * @return array|WP_Error
	 */
	private static function request( string $method, string $path, string $token, ?array $body = null ) {
		$args = array(
			'method'  => $method,
			'timeout' => self::TIMEOUT,
			'headers' => array(
				'Authorization' => 'Bearer ' . $token,
				'Content-Type'  => 'application/json',
			),
		);
		if ( null !== $body ) {
			$args['body'] = wp_json_encode( $body );
		}

		$response = wp_remote_request( self::API_BASE . $path, $args );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code    = (int) wp_remote_retrieve_response_code( $response );
		$decoded = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		$decoded = is_array( $decoded ) ? $decoded : array();

		if ( $code < 200 || $code >= 300 || empty( $decoded['success'] ) ) {
			$errors = is_array( $decoded['errors'] ?? null ) ? wp_json_encode( $decoded['errors'] ) : '';
			return new WP_Error(
				'uxstudio_turnstile_self_cf_http',
				/* translators: 1: HTTP status code, 2: Cloudflare error payload */
				sprintf( __( 'Cloudflare API returned HTTP %1$d: %2$s', 'ux-studio' ), $code, $errors ),
				array( 'status' => $code )
			);
		}

		return $decoded;
	}
}
