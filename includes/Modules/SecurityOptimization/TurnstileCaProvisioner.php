<?php
/**
 * Auto-provisions Cloudflare Turnstile keys via the central app's broker
 * endpoint (`?page=turnstile_provision`), for sites already paired with it
 * through Content Sync.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\SecurityOptimization;

use UxStudio\Core\Security;
use UxStudio\Core\Settings;
use UxStudio\Modules\ContentSync\HmacAuth;
use UxStudio\Modules\ContentSync\Module as ContentSyncModule;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Deliberately does NOT reuse ServiceRequests\CentralClient - that class is
 * scoped to `?page=ticket_api` only. Rather than widen a working, tested
 * channel for an unrelated use case, this repeats its ~20-line signing
 * pattern (same hub<->node credentials, same HmacAuth::sign()) against a
 * different page. See CentralClient.php for the canonical version of this
 * pattern.
 */
final class TurnstileCaProvisioner {

	private const TIMEOUT = 20;

	/**
	 * @return array{site_key:string,secret_key:string}|WP_Error
	 */
	public static function provision( Module $module ) {
		list( $central_url, $secret ) = self::credentials();

		if ( '' === $central_url || '' === $secret ) {
			return new WP_Error(
				'uxstudio_turnstile_ca_not_configured',
				__( 'The central app URL or the node API key is missing in Content Sync settings.', 'ux-studio' )
			);
		}

		$query = array(
			'page'     => 'turnstile_provision',
			'action'   => 'register',
			'site_url' => home_url( '/' ),
		);
		$url = untrailingslashit( $central_url ) . '/?' . http_build_query( $query );
		$raw = '';

		$timestamp = time();
		$nonce     = bin2hex( random_bytes( 8 ) );
		$signature = HmacAuth::sign( 'POST', $url, $raw, $timestamp, $nonce, $secret );

		$response = wp_remote_post(
			$url,
			array(
				'method'  => 'POST',
				'timeout' => self::TIMEOUT,
				'headers' => array(
					'Content-Type'              => 'application/json',
					HmacAuth::HEADER_SIGNATURE  => $signature,
					HmacAuth::HEADER_TIMESTAMP  => (string) $timestamp,
					HmacAuth::HEADER_NONCE      => $nonce,
				),
				'body'    => $raw,
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code    = (int) wp_remote_retrieve_response_code( $response );
		$decoded = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		$decoded = is_array( $decoded ) ? $decoded : array();

		if ( $code < 200 || $code >= 300 || empty( $decoded['success'] ) ) {
			$message = (string) ( $decoded['error'] ?? '' );
			if ( '' === $message ) {
				/* translators: %d: HTTP status code */
				$message = sprintf( __( 'Central app returned HTTP %d.', 'ux-studio' ), $code );
			}
			return new WP_Error( 'uxstudio_turnstile_ca_http', $message, array( 'status' => $code ) );
		}

		$site_key   = (string) ( $decoded['site_key'] ?? '' );
		$secret_key = (string) ( $decoded['secret_key'] ?? '' );
		if ( '' === $site_key || '' === $secret_key ) {
			return new WP_Error( 'uxstudio_turnstile_ca_incomplete', __( 'Central app response is missing site_key/secret_key.', 'ux-studio' ) );
		}

		return array(
			'site_key'   => $site_key,
			'secret_key' => $secret_key,
		);
	}

	/**
	 * Central app URL + shared node key - same source as CentralClient, this
	 * module never stores its own copy.
	 *
	 * @return array{0:string,1:string}
	 */
	private static function credentials(): array {
		$content_sync = new Settings( 'uxstudio_content_sync' );

		return array(
			(string) $content_sync->get( 'central_app_url', '' ),
			Security::get_secret( ContentSyncModule::SECRET_NODE_KEY ),
		);
	}
}
