<?php
/**
 * Thin HTTP client for the Instagram API (Instagram Login / graph.instagram.com).
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\InstagramFeed;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Wraps the OAuth token lifecycle and the media edge of the Instagram Graph
 * API. This module talks to Meta directly (it is NOT routed through the
 * Content Sync broker): the long-lived access token is stored encrypted on
 * this site via UxStudio\Core\Security and passed here per call.
 *
 * Endpoints (Instagram API with Instagram Login, current as of 2025):
 *  - authorize      : https://www.instagram.com/oauth/authorize
 *  - code -> token  : POST https://api.instagram.com/oauth/access_token
 *  - long-lived     : GET  https://graph.instagram.com/access_token
 *  - refresh        : GET  https://graph.instagram.com/refresh_access_token
 *  - profile        : GET  https://graph.instagram.com/me
 *  - media          : GET  https://graph.instagram.com/me/media
 *
 * Every method is defensive: a missing/invalid token or a Meta error surfaces
 * as a WP_Error, never a fatal.
 */
final class InstagramClient {

	private const AUTHORIZE_URL = 'https://www.instagram.com/oauth/authorize';
	private const TOKEN_URL     = 'https://api.instagram.com/oauth/access_token';
	private const GRAPH_URL     = 'https://graph.instagram.com';

	/** Scopes requested during authorization (Instagram Login). */
	public const SCOPE = 'instagram_business_basic';

	/** Fields requested per media item (safe subset that works for all account types). */
	private const MEDIA_FIELDS = 'id,caption,media_type,media_url,thumbnail_url,permalink,timestamp,username,children{media_url,media_type,thumbnail_url}';

	private const TIMEOUT = 20;

	/**
	 * Build the browser authorization URL the admin is redirected to.
	 *
	 * @param string $app_id       Instagram app client id.
	 * @param string $redirect_uri Registered OAuth redirect URI.
	 * @param string $state        Opaque CSRF/one-shot state token.
	 */
	public function authorize_url( string $app_id, string $redirect_uri, string $state ): string {
		return self::AUTHORIZE_URL . '?' . http_build_query(
			array(
				'client_id'     => $app_id,
				'redirect_uri'  => $redirect_uri,
				'scope'         => self::SCOPE,
				'response_type' => 'code',
				'state'         => $state,
			)
		);
	}

	/**
	 * Exchange an authorization code for a short-lived access token.
	 *
	 * @param string $app_id       Client id.
	 * @param string $app_secret   Client secret.
	 * @param string $redirect_uri Redirect URI used in the authorize step.
	 * @param string $code         Authorization code from the callback.
	 * @return array{access_token:string,user_id:string}|WP_Error
	 */
	public function exchange_code( string $app_id, string $app_secret, string $redirect_uri, string $code ) {
		$response = wp_remote_post(
			self::TOKEN_URL,
			array(
				'timeout' => self::TIMEOUT,
				'body'    => array(
					'client_id'     => $app_id,
					'client_secret' => $app_secret,
					'grant_type'    => 'authorization_code',
					'redirect_uri'  => $redirect_uri,
					'code'          => $code,
				),
			)
		);

		$data = $this->decode( $response );
		if ( $data instanceof WP_Error ) {
			return $data;
		}
		if ( empty( $data['access_token'] ) ) {
			return new WP_Error( 'uxstudio_ig_no_token', __( 'Instagram did not return an access token.', 'ux-studio' ), array( 'status' => 502 ) );
		}

		return array(
			'access_token' => (string) $data['access_token'],
			'user_id'      => (string) ( $data['user_id'] ?? '' ),
		);
	}

	/**
	 * Exchange a short-lived token for a long-lived (~60 day) token.
	 *
	 * @param string $app_secret   Client secret.
	 * @param string $short_token  Short-lived access token.
	 * @return array{access_token:string,expires_in:int}|WP_Error
	 */
	public function exchange_long_lived( string $app_secret, string $short_token ) {
		$data = $this->get(
			self::GRAPH_URL . '/access_token',
			array(
				'grant_type'   => 'ig_exchange_token',
				'client_secret' => $app_secret,
				'access_token' => $short_token,
			)
		);
		if ( $data instanceof WP_Error ) {
			return $data;
		}
		if ( empty( $data['access_token'] ) ) {
			return new WP_Error( 'uxstudio_ig_no_long_token', __( 'Could not obtain a long-lived Instagram token.', 'ux-studio' ), array( 'status' => 502 ) );
		}

		return array(
			'access_token' => (string) $data['access_token'],
			'expires_in'   => (int) ( $data['expires_in'] ?? 0 ),
		);
	}

	/**
	 * Refresh a long-lived token (valid while it is at least 24h old and not
	 * expired). Returns a fresh token + new expiry.
	 *
	 * @param string $long_token Current long-lived token.
	 * @return array{access_token:string,expires_in:int}|WP_Error
	 */
	public function refresh( string $long_token ) {
		$data = $this->get(
			self::GRAPH_URL . '/refresh_access_token',
			array(
				'grant_type'   => 'ig_refresh_token',
				'access_token' => $long_token,
			)
		);
		if ( $data instanceof WP_Error ) {
			return $data;
		}
		if ( empty( $data['access_token'] ) ) {
			return new WP_Error( 'uxstudio_ig_refresh_failed', __( 'Instagram token refresh failed.', 'ux-studio' ), array( 'status' => 502 ) );
		}

		return array(
			'access_token' => (string) $data['access_token'],
			'expires_in'   => (int) ( $data['expires_in'] ?? 0 ),
		);
	}

	/**
	 * Fetch the connected account's profile.
	 *
	 * @param string $token Access token.
	 * @return array{id:string,username:string,account_type:string}|WP_Error
	 */
	public function fetch_profile( string $token ) {
		$data = $this->get(
			self::GRAPH_URL . '/me',
			array(
				'fields'       => 'id,username,account_type',
				'access_token' => $token,
			)
		);
		if ( $data instanceof WP_Error ) {
			return $data;
		}

		return array(
			'id'           => (string) ( $data['id'] ?? '' ),
			'username'     => (string) ( $data['username'] ?? '' ),
			'account_type' => (string) ( $data['account_type'] ?? '' ),
		);
	}

	/**
	 * Fetch recent media for the connected account (single page).
	 *
	 * @param string $token Access token.
	 * @param int    $limit Max items (1-100).
	 * @return array<int, array<string, mixed>>|WP_Error
	 */
	public function fetch_media( string $token, int $limit ) {
		$limit = max( 1, min( 100, $limit ) );
		$data  = $this->get(
			self::GRAPH_URL . '/me/media',
			array(
				'fields'       => self::MEDIA_FIELDS,
				'access_token' => $token,
				'limit'        => $limit,
			)
		);
		if ( $data instanceof WP_Error ) {
			return $data;
		}

		$items = $data['data'] ?? array();
		return is_array( $items ) ? $items : array();
	}

	/**
	 * GET a Graph endpoint and return the decoded body.
	 *
	 * @param string $url    Endpoint URL.
	 * @param array  $params Query params.
	 * @return array<string, mixed>|WP_Error
	 */
	private function get( string $url, array $params ) {
		$response = wp_remote_get(
			$url . '?' . http_build_query( $params ),
			array( 'timeout' => self::TIMEOUT )
		);
		return $this->decode( $response );
	}

	/**
	 * Turn a wp_remote_* response into an associative array or a WP_Error that
	 * carries the Meta-provided error message when present.
	 *
	 * @param array|WP_Error $response Raw response.
	 * @return array<string, mixed>|WP_Error
	 */
	private function decode( $response ) {
		if ( $response instanceof WP_Error ) {
			return new WP_Error( 'uxstudio_ig_http', $response->get_error_message(), array( 'status' => 502 ) );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = (string) wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );

		if ( ! is_array( $data ) ) {
			return new WP_Error( 'uxstudio_ig_bad_response', __( 'Unexpected response from Instagram.', 'ux-studio' ), array( 'status' => 502 ) );
		}

		if ( isset( $data['error'] ) || $code >= 400 ) {
			$message = '';
			if ( is_array( $data['error'] ?? null ) ) {
				$message = (string) ( $data['error']['message'] ?? '' );
			} elseif ( isset( $data['error_message'] ) ) {
				$message = (string) $data['error_message'];
			}
			if ( '' === $message ) {
				$message = sprintf( /* translators: %d: HTTP status code */ __( 'Instagram API error (HTTP %d).', 'ux-studio' ), $code );
			}
			return new WP_Error( 'uxstudio_ig_api', $message, array( 'status' => 502 ) );
		}

		return $data;
	}
}
