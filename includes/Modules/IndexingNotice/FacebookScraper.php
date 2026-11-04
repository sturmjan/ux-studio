<?php
/**
 * Asks Facebook to fetch a page again (own keys mode, PLAN.md 39.2, 39.4).
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\IndexingNotice;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * `POST https://graph.facebook.com/` with `id=<url>`, `scrape=true` and an app access token `<app id>|<app secret>`
 * (an app token is enough, App Review is not needed). The token goes in the POST body, not in the address, so it
 * never lands in a server or proxy log. Facebook and Messenger take the new preview from that; the same crawler is
 * not documented for Instagram, Threads and WhatsApp, so nothing is promised there.
 *
 * The app secret is only a parameter here: the caller reads it from Security::get_secret() and nothing in this
 * class stores, logs or returns it. The pure parts (request, answer reading) are tested without WordPress.
 */
final class FacebookScraper {

	public const GRAPH   = 'https://graph.facebook.com/';
	private const TIMEOUT = 15;

	/** Graph API error codes that mean "too many calls, wait" (application / user / page request limit, throttling). */
	private const RATE_CODES = array( 4, 17, 32, 613 );

	/**
	 * Body of the scrape request.
	 *
	 * @param string $url    Address to refresh.
	 * @param string $app_id Facebook app id.
	 * @param string $secret Facebook app secret.
	 * @return array<string, string>
	 */
	public static function scrape_body( string $url, string $app_id, string $secret ): array {
		return array(
			'id'           => $url,
			'scrape'       => 'true',
			'access_token' => $app_id . '|' . $secret,
		);
	}

	/**
	 * Address of the key check: the app itself, read with its own token.
	 *
	 * @param string $app_id Facebook app id.
	 * @param string $secret Facebook app secret.
	 */
	public static function verify_url( string $app_id, string $secret ): string {
		return self::GRAPH . rawurlencode( $app_id ) . '?' . http_build_query( array( 'access_token' => $app_id . '|' . $secret ) );
	}

	/**
	 * An app id is a number; anything else cannot be an id.
	 *
	 * @param string $app_id Candidate.
	 */
	public static function is_app_id( string $app_id ): bool {
		return 1 === preg_match( '/^[0-9]{5,25}$/', $app_id );
	}

	/**
	 * Read the answer to a scrape.
	 *
	 * @param string               $url     Address that was refreshed.
	 * @param int                  $http    HTTP status.
	 * @param array<string, mixed> $decoded Decoded JSON.
	 * @return array{url:string,ok:bool,rate:bool,og_title?:string,og_image?:string,error?:string}
	 */
	public static function read_scrape( string $url, int $http, array $decoded ): array {
		if ( isset( $decoded['error'] ) && is_array( $decoded['error'] ) ) {
			$code = (int) ( $decoded['error']['code'] ?? 0 );
			return array(
				'url'   => $url,
				'ok'    => false,
				'rate'  => in_array( $code, self::RATE_CODES, true ),
				'error' => self::clean_error( (string) ( $decoded['error']['message'] ?? '' ), $code ),
			);
		}
		if ( $http < 200 || $http >= 300 ) {
			return array(
				'url'   => $url,
				'ok'    => false,
				'rate'  => 429 === $http,
				'error' => 'HTTP ' . $http,
			);
		}
		$image = '';
		if ( isset( $decoded['image'] ) && is_array( $decoded['image'] ) ) {
			$first = reset( $decoded['image'] );
			$image = is_array( $first ) ? (string) ( $first['url'] ?? '' ) : '';
		}
		return array(
			'url'      => $url,
			'ok'       => true,
			'rate'     => false,
			'og_title' => (string) ( $decoded['title'] ?? '' ),
			'og_image' => $image,
		);
	}

	/**
	 * An error text without anything that looks like a token, short enough for the log.
	 *
	 * @param string $message Message of the Graph API.
	 * @param int    $code    Error code.
	 */
	public static function clean_error( string $message, int $code ): string {
		$message = (string) preg_replace( '/[0-9]{5,25}\|[A-Za-z0-9]{10,}/', '[token]', $message );
		$message = trim( mb_substr( $message, 0, 200 ) );
		return $code > 0 ? sprintf( '%s (%d)', '' !== $message ? $message : 'Graph API error', $code ) : $message;
	}

	/**
	 * Refresh one address.
	 *
	 * @param string $url    Address.
	 * @param string $app_id Facebook app id.
	 * @param string $secret Facebook app secret.
	 * @return array{url:string,ok:bool,rate:bool,og_title?:string,og_image?:string,error?:string}
	 */
	public static function scrape( string $url, string $app_id, string $secret ): array {
		$response = wp_remote_post(
			self::GRAPH,
			array(
				'timeout' => self::TIMEOUT,
				'body'    => self::scrape_body( $url, $app_id, $secret ),
			)
		);
		if ( is_wp_error( $response ) ) {
			return array(
				'url'   => $url,
				'ok'    => false,
				'rate'  => false,
				'error' => $response->get_error_message(),
			);
		}
		$decoded = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		return self::read_scrape( $url, (int) wp_remote_retrieve_response_code( $response ), is_array( $decoded ) ? $decoded : array() );
	}

	/**
	 * Are the keys valid? One read of the app itself.
	 *
	 * @param string $app_id Facebook app id.
	 * @param string $secret Facebook app secret.
	 * @return array{ok:bool,message:string,app_name:string}|WP_Error
	 */
	public static function verify( string $app_id, string $secret ) {
		if ( ! self::is_app_id( $app_id ) || '' === $secret ) {
			return array(
				'ok'       => false,
				'message'  => __( 'Enter the app ID (digits only) and the app secret.', 'ux-studio' ),
				'app_name' => '',
			);
		}
		$response = wp_remote_get( self::verify_url( $app_id, $secret ), array( 'timeout' => self::TIMEOUT ) );
		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'uxstudio_fb_unreachable', __( 'Facebook could not be reached.', 'ux-studio' ), array( 'status' => 502 ) );
		}
		$http    = (int) wp_remote_retrieve_response_code( $response );
		$decoded = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		$decoded = is_array( $decoded ) ? $decoded : array();
		if ( 200 === $http && (string) ( $decoded['id'] ?? '' ) === $app_id ) {
			return array(
				'ok'       => true,
				'message'  => __( 'The keys are valid.', 'ux-studio' ),
				'app_name' => sanitize_text_field( (string) ( $decoded['name'] ?? '' ) ),
			);
		}
		$code = (int) ( $decoded['error']['code'] ?? 0 );
		return array(
			'ok'       => false,
			'message'  => sprintf(
				/* translators: %s: error text from Facebook. */
				__( 'Facebook refused the keys: %s', 'ux-studio' ),
				self::clean_error( (string) ( $decoded['error']['message'] ?? '' ), $code ) ?: 'HTTP ' . $http
			),
			'app_name' => '',
		);
	}
}
