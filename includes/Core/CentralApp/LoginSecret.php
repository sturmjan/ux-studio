<?php
/**
 * The per-site login secret of UX1 Network app, fetched from it instead of typed in by hand.
 *
 * @package UxStudio
 */

namespace UxStudio\Core\CentralApp;

use UxStudio\Core\Security;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * UX1 Network app creates `sites.google_login_secret` for every site the moment it is registered. The
 * same secret signs the third-party login handshake (both directions) and the reviews / PageSpeed /
 * Places proxies, so there is one source of truth: UX1 Network app. A copy typed into the site is wrong
 * as soon as somebody presses "regenerate" there.
 *
 * Reading it:
 *
 *   GET {central_app_url}/?page=site_config&action=google_secret
 *
 * signed with the shared `node_api_key` (the key the onboarding wizard stored when it paired the site):
 * headers X-Site-Url / X-Timestamp / X-Signature over METHOD \n URL \n TIMESTAMP \n sha256(body). This is
 * the same call CentralReviewsClient makes; here it is shared so a module does not need its own copy.
 *
 * The answer is cached encrypted (Security::store_secret). Callers that cannot recover from a stale copy
 * (a handshake the browser carries to UX1 Network app and back) ask for a fresh one.
 */
final class LoginSecret {

	/** Cached secret (encrypted option, never echoed back). */
	public const CACHE_OPTION = 'uxstudio_secret_ca_login';

	private const TIMEOUT = 20;

	/**
	 * Cached copy only, no request. '' when none has been fetched yet.
	 */
	public static function cached(): string {
		return Security::get_secret( self::CACHE_OPTION );
	}

	/**
	 * The secret, from the cache or from UX1 Network app.
	 *
	 * @param bool $refresh Ignore the cached copy and ask UX1 Network app again.
	 * @return string|WP_Error
	 */
	public static function get( bool $refresh = false ) {
		if ( ! $refresh ) {
			$cached = self::cached();
			if ( '' !== $cached ) {
				return $cached;
			}
		}
		return self::fetch();
	}

	/**
	 * Ask UX1 Network app and cache the answer.
	 *
	 * @return string|WP_Error
	 */
	public static function fetch() {
		$blocked = Gateway::guard();
		if ( null !== $blocked ) {
			return $blocked;
		}

		list( $central_url, $node_key ) = Gateway::credentials();
		if ( '' === trim( $central_url ) || '' === $node_key ) {
			return new WP_Error(
				'uxstudio_login_secret_not_paired',
				__( 'The site is not connected to UX1 Network app yet (URL or node API key is missing in Content Sync).', 'ux-studio' ),
				array( 'status' => 424 )
			);
		}

		$url       = Gateway::url( $central_url, 'site_config', array( 'action' => 'google_secret' ) );
		$timestamp = time();
		$signature = hash_hmac( 'sha256', "GET\n" . $url . "\n" . $timestamp . "\n" . hash( 'sha256', '' ), $node_key );

		$response = wp_remote_get(
			$url,
			array(
				'timeout' => self::TIMEOUT,
				'headers' => array_merge(
					array(
						'Accept'      => 'application/json',
						'X-Site-Url'  => home_url( '/' ),
						'X-Timestamp' => (string) $timestamp,
						'X-Signature' => $signature,
					),
					Gateway::headers()
				),
			)
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code    = (int) wp_remote_retrieve_response_code( $response );
		$decoded = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		$decoded = is_array( $decoded ) ? $decoded : array();
		Gateway::inspect( $code, $decoded );

		if ( $code < 200 || $code >= 300 || empty( $decoded['success'] ) ) {
			$message = (string) ( $decoded['error'] ?? '' );
			if ( '' === $message ) {
				/* translators: %d: HTTP status code */
				$message = sprintf( __( 'UX1 Network app returned HTTP %d.', 'ux-studio' ), $code );
			}
			return new WP_Error( 'uxstudio_login_secret_http', $message, array( 'status' => $code ) );
		}

		$secret = (string) ( $decoded['google_login_secret'] ?? '' );
		if ( '' === $secret ) {
			return new WP_Error(
				'uxstudio_login_secret_missing',
				__( 'UX1 Network app has no login secret for this site yet. Open the site detail there, section "Third-party login", and enable a provider.', 'ux-studio' ),
				array( 'status' => 424 )
			);
		}

		Security::store_secret( self::CACHE_OPTION, $secret );
		return $secret;
	}
}
