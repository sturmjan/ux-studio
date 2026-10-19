<?php
/**
 * Signed client for the central app's cron config endpoint
 * (`?page=site_config&action=set_cron`).
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\CronControl;

use UxStudio\Core\Security;
use UxStudio\Modules\ContentSync\Module as ContentSyncModule;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Tells the central app to start/stop calling this site's wp-cron.php.
 *
 * Contract (centrani-app SiteConfigController::setCron(), unchanged from the
 * legacy cron-control module):
 *   POST {central_app_url}/?page=site_config&action=set_cron
 *   JSON body {enabled: bool, interval_minutes: int, use_wp_default: bool}
 *   Headers X-Site-Url / X-Timestamp / X-Signature, where the signature is the
 *   CA's legacy HmacAuth::sign(): HMAC-SHA256 over
 *   METHOD \n URL \n TIMESTAMP \n sha256(body) - NO nonce - keyed with the
 *   hub<->node key (Content Sync node_api_key = CA sites.api_key).
 *   200 {success:true, cron_enabled, cron_interval_minutes, cron_use_wp_default}
 *   4xx {success:false, error}
 *
 * Same credentials and scheme as ReviewAggregator\CentralReviewsClient's
 * `site_config&action=google_secret` call.
 */
final class CentralCronClient {

	private const TIMEOUT = 15;

	/**
	 * Push the cron config for this site to the central app.
	 *
	 * @param bool $enabled          Whether the central app should run wp-cron.php.
	 * @param int  $interval_minutes How often (minutes).
	 * @return array<string, mixed>|WP_Error Decoded CA response, or an error.
	 */
	public static function set_cron( bool $enabled, int $interval_minutes ) {
		$central_url = untrailingslashit( (string) ContentSyncModule::setting( 'central_app_url', '' ) );
		$secret      = Security::get_secret( ContentSyncModule::SECRET_NODE_KEY );

		if ( '' === $central_url || '' === $secret ) {
			return new WP_Error(
				'uxstudio_cron_not_configured',
				__( 'The central app URL or the node API key is missing in Content Sync settings.', 'ux-studio' ),
				array( 'status' => 424 )
			);
		}

		$request = self::build_request( $central_url, $secret, $enabled, $interval_minutes, time() );

		$response = wp_remote_post(
			$request['url'],
			array(
				'timeout' => self::TIMEOUT,
				'headers' => $request['headers'],
				'body'    => $request['body'],
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
			return new WP_Error( 'uxstudio_cron_ca_error', $message, array( 'status' => $code ) );
		}

		return $decoded;
	}

	/**
	 * Build the signed request (URL, body, headers). Separate from the HTTP call
	 * so the signature can be verified offline against the CA's algorithm.
	 *
	 * @param string $central_url      CA base URL (no trailing slash).
	 * @param string $secret           Shared node_api_key.
	 * @param bool   $enabled          Run wp-cron from the CA.
	 * @param int    $interval_minutes Interval in minutes.
	 * @param int    $timestamp        Unix timestamp.
	 * @return array{url:string, body:string, headers:array<string,string>}
	 */
	public static function build_request( string $central_url, string $secret, bool $enabled, int $interval_minutes, int $timestamp ): array {
		$url  = $central_url . '/?' . http_build_query(
			array(
				'page'   => 'site_config',
				'action' => 'set_cron',
			)
		);
		$body = (string) wp_json_encode(
			array(
				'enabled'          => $enabled,
				'interval_minutes' => max( 1, $interval_minutes ),
				'use_wp_default'   => true,
			)
		);

		$signature = hash_hmac( 'sha256', "POST\n" . $url . "\n" . $timestamp . "\n" . hash( 'sha256', $body ), $secret );

		return array(
			'url'     => $url,
			'body'    => $body,
			'headers' => array(
				'Content-Type' => 'application/json',
				'Accept'       => 'application/json',
				'X-Site-Url'   => untrailingslashit( home_url() ),
				'X-Timestamp'  => (string) $timestamp,
				'X-Signature'  => $signature,
			),
		);
	}
}
