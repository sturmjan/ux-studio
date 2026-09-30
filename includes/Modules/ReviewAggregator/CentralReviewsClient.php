<?php
/**
 * Client for the central app's public reviews API.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\ReviewAggregator;

use UxStudio\Core\Security;
use UxStudio\Core\Settings;
use UxStudio\Modules\ContentSync\Module as ContentSyncModule;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Talks to `?page=reviews_api` (centrani-app ReviewApiController) using the
 * auth scheme that endpoint actually implements:
 *
 *   1. The per-site `google_login_secret` is fetched once from
 *      `?page=site_config&action=google_secret`, authenticated with the
 *      hub<->node key (ContentSync node_api_key = CA sites.api_key) via the
 *      CA's legacy HmacAuth::sign(): headers X-Site-Url / X-Timestamp /
 *      X-Signature over METHOD\nURL\nTIMESTAMP\nsha256(body). It is cached
 *      encrypted (Security::store_secret) and re-fetched once on a 401.
 *   2. reviews_api calls are signed like GoogleProxySigner::sign(): HMAC-SHA256
 *      over the ksort()ed http_build_query() of site_url + ts + every optional
 *      field sent; ts/sig travel in X-Reviews-Timestamp / X-Reviews-Signature.
 *      The CA burns each signature (HmacNonce), so every request is re-signed.
 */
final class CentralReviewsClient {

	/** Cached google_login_secret (encrypted option). */
	private const SECRET_REVIEWS = 'uxstudio_secret_review_aggregator_reviews';

	private const TIMEOUT = 20;

	/**
	 * [central_app_url, node_api_key] from the Content Sync settings.
	 *
	 * @return array{0:string,1:string}
	 */
	public static function credentials(): array {
		$settings = new Settings( 'uxstudio_content_sync' );
		return array(
			untrailingslashit( (string) $settings->get( 'central_app_url', '' ) ),
			Security::get_secret( ContentSyncModule::SECRET_NODE_KEY ),
		);
	}

	/**
	 * GET a reviews_api action.
	 *
	 * @param string $action reviews|version|status|request_scrape.
	 * @param array  $params Optional signed fields (profile_id, source, external_id, limit, offset, ...).
	 * @param string $etag   If-None-Match value (reviews action), '' for none.
	 * @return array<string, mixed>|WP_Error Decoded body; array('not_modified' => true) on 304.
	 */
	public static function call( string $action, array $params = array(), string $etag = '' ) {
		$result = self::request( $action, $params, $etag, false );
		if ( is_wp_error( $result ) && 401 === (int) ( $result->get_error_data()['status'] ?? 0 ) ) {
			// The CA may have rotated the secret - refresh it once and retry.
			$result = self::request( $action, $params, $etag, true );
		}
		return $result;
	}

	/**
	 * @param string $action        Action.
	 * @param array  $params        Optional signed fields.
	 * @param string $etag          If-None-Match.
	 * @param bool   $refresh_secret Force re-fetching the reviews secret.
	 * @return array<string, mixed>|WP_Error
	 */
	private static function request( string $action, array $params, string $etag, bool $refresh_secret ) {
		list( $central_url ) = self::credentials();
		$secret              = self::reviews_secret( $refresh_secret );
		if ( is_wp_error( $secret ) ) {
			return $secret;
		}

		$signed = array( 'site_url' => home_url( '/' ) );
		foreach ( $params as $key => $value ) {
			if ( '' !== (string) $value ) {
				$signed[ (string) $key ] = (string) $value;
			}
		}
		$ts          = (string) time();
		$signed_base = $signed + array( 'ts' => $ts );
		ksort( $signed_base );
		$sig = hash_hmac( 'sha256', http_build_query( $signed_base ), $secret );

		$url     = $central_url . '/?' . http_build_query(
			array(
				'page'   => 'reviews_api',
				'action' => $action,
			) + $signed
		);
		$headers = array(
			'Accept'              => 'application/json',
			'X-Reviews-Timestamp' => $ts,
			'X-Reviews-Signature' => $sig,
		);
		if ( '' !== $etag ) {
			$headers['If-None-Match'] = '"' . $etag . '"';
		}

		$response = wp_remote_get(
			$url,
			array(
				'timeout' => self::TIMEOUT,
				'headers' => $headers,
			)
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 304 === $code ) {
			return array( 'not_modified' => true );
		}
		$decoded = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		$decoded = is_array( $decoded ) ? $decoded : array();
		if ( $code < 200 || $code >= 300 || empty( $decoded['success'] ) ) {
			return self::http_error( $code, $decoded );
		}
		return $decoded;
	}

	/**
	 * The per-site reviews secret (CA sites.google_login_secret).
	 *
	 * @param bool $refresh Ignore the cached copy.
	 * @return string|WP_Error
	 */
	private static function reviews_secret( bool $refresh ) {
		if ( ! $refresh ) {
			$cached = Security::get_secret( self::SECRET_REVIEWS );
			if ( '' !== $cached ) {
				return $cached;
			}
		}

		list( $central_url, $node_key ) = self::credentials();
		if ( '' === $central_url || '' === $node_key ) {
			return new WP_Error(
				'uxstudio_reviews_not_configured',
				__( 'Set the central app URL and the node API key in Content Sync first.', 'ux-studio' ),
				array( 'status' => 424 )
			);
		}

		$url       = $central_url . '/?' . http_build_query(
			array(
				'page'   => 'site_config',
				'action' => 'google_secret',
			)
		);
		$timestamp = time();
		$signature = hash_hmac( 'sha256', "GET\n" . $url . "\n" . $timestamp . "\n" . hash( 'sha256', '' ), $node_key );

		$response = wp_remote_get(
			$url,
			array(
				'timeout' => self::TIMEOUT,
				'headers' => array(
					'Accept'      => 'application/json',
					'X-Site-Url'  => home_url( '/' ),
					'X-Timestamp' => (string) $timestamp,
					'X-Signature' => $signature,
				),
			)
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$code    = (int) wp_remote_retrieve_response_code( $response );
		$decoded = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		$decoded = is_array( $decoded ) ? $decoded : array();
		if ( $code < 200 || $code >= 300 || empty( $decoded['success'] ) ) {
			return self::http_error( $code, $decoded );
		}

		$secret = (string) ( $decoded['google_login_secret'] ?? '' );
		if ( '' === $secret ) {
			return new WP_Error(
				'uxstudio_reviews_no_secret',
				__( 'The central app has no reviews API secret for this site yet (Google login secret not generated in the site detail).', 'ux-studio' ),
				array( 'status' => 424 )
			);
		}
		Security::store_secret( self::SECRET_REVIEWS, $secret );
		return $secret;
	}

	/**
	 * @param int   $code    HTTP status.
	 * @param array $decoded Decoded body.
	 */
	private static function http_error( int $code, array $decoded ): WP_Error {
		$message = (string) ( $decoded['error'] ?? '' );
		if ( '' === $message ) {
			/* translators: %d: HTTP status code */
			$message = sprintf( __( 'Central app returned HTTP %d.', 'ux-studio' ), $code );
		}
		return new WP_Error( 'uxstudio_reviews_http', $message, array( 'status' => $code ) );
	}
}
