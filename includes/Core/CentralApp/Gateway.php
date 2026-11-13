<?php
/**
 * Signed calls from the plugin to UX1 Network app, in one place (PLAN.md 39, 40).
 *
 * @package UxStudio
 */

namespace UxStudio\Core\CentralApp;

use UxStudio\Core\ActivityLog;
use UxStudio\Core\Security;
use UxStudio\Core\Settings;
use UxStudio\Modules\ContentSync\HmacAuth;
use UxStudio\Modules\ContentSync\Module as ContentSyncModule;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Same channel as ServiceRequests\CentralClient and SecurityOptimization\TurnstileCaProvisioner: UX1 Network app
 * URL and the shared `node_api_key` from the Content Sync settings, HmacAuth::sign() over
 * METHOD \n URL \n TIMESTAMP \n NONCE \n sha256(body). What this class adds, and what the two older clients call
 * too (`guard()`, `headers()`, `inspect()`):
 *
 *  - the stable site id in the header `X-UxStudio-Site-Id` and in the body field `site_id`,
 *  - the paused state: a copy of a site, or a site that was moved away, makes NO call,
 *  - the domain watch: when home_url() no longer matches the address UX1 Network app knows, `site_moved` goes
 *    first (DomainWatch),
 *  - the central reading of the answers 409 `url_mismatch`, 410 `superseded` and 423 `clone`.
 */
final class Gateway {

	private const TIMEOUT = 20;

	/**
	 * UX1 Network app URL + shared node key.
	 *
	 * @return array{0:string,1:string}
	 */
	public static function credentials(): array {
		$content_sync = new Settings( 'uxstudio_content_sync' );

		return array(
			(string) $content_sync->get( 'central_app_url', '' ),
			Security::get_secret( ContentSyncModule::SECRET_NODE_KEY ),
		);
	}

	/**
	 * Are the URL and the key both set (the site is paired)? A paused link still counts as paired.
	 */
	public static function is_paired(): bool {
		list( $url, $secret ) = self::credentials();
		return '' !== trim( $url ) && '' !== $secret;
	}

	/**
	 * Paired and not paused: the calls can be made.
	 */
	public static function is_active(): bool {
		return self::is_paired() && ! SiteIdentity::is_paused();
	}

	/**
	 * The error every call returns while the link is paused.
	 */
	public static function paused_error(): WP_Error {
		return new WP_Error(
			'uxstudio_ca_paused',
			__( 'The connection to UX1 Network app is paused, because this site is a copy or was moved to another address.', 'ux-studio' ),
			array( 'status' => 424 )
		);
	}

	/**
	 * Run before every call: a paused link stops it, a changed address is reported first.
	 *
	 * @return WP_Error|null Error when the call must not be made.
	 */
	public static function guard(): ?WP_Error {
		if ( SiteIdentity::is_paused() ) {
			return self::paused_error();
		}
		if ( self::is_paired() ) {
			DomainWatch::check( false );
			if ( SiteIdentity::is_paused() ) {
				return self::paused_error();
			}
		}
		return null;
	}

	/**
	 * Headers of a signed call: the site id (the signature headers are added by the caller).
	 *
	 * @return array<string, string>
	 */
	public static function headers(): array {
		return SiteIdentity::headers();
	}

	/**
	 * UX1 Network app address of a page with the query string built once (the signed text has to be byte identical
	 * to what UX1 Network app sees in REQUEST_URI).
	 *
	 * @param string               $central_url UX1 Network app base address.
	 * @param string               $page        `?page=` value.
	 * @param array<string, mixed> $query       Further query parameters.
	 */
	public static function url( string $central_url, string $page, array $query = array() ): string {
		return untrailingslashit( $central_url ) . '/?' . http_build_query( array_merge( array( 'page' => $page ), $query ) );
	}

	/**
	 * Guarded, signed JSON POST to a page of UX1 Network app.
	 *
	 * @param string               $page  `?page=` value (also the HMAC scope on UX1 Network app side).
	 * @param array<string, mixed> $body  Body (the site id is added).
	 * @param array<string, mixed> $query Further query parameters.
	 * @return array{code:int,body:array<string,mixed>}|WP_Error
	 */
	public static function post( string $page, array $body, array $query = array() ) {
		$blocked = self::guard();
		if ( null !== $blocked ) {
			return $blocked;
		}
		return self::request( $page, $body, $query, true );
	}

	/**
	 * Signed JSON POST without the guard (the domain watch uses it, it must not call itself).
	 *
	 * @param string               $page    `?page=` value.
	 * @param array<string, mixed> $body    Body (the site id is added).
	 * @param array<string, mixed> $query   Further query parameters.
	 * @param bool                 $inspect Let the answers 409 / 410 / 423 change the state of the link.
	 * @return array{code:int,body:array<string,mixed>}|WP_Error
	 */
	public static function request( string $page, array $body, array $query = array(), bool $inspect = true ) {
		list( $central_url, $secret ) = self::credentials();
		if ( '' === $central_url || '' === $secret ) {
			return new WP_Error(
				'uxstudio_ca_not_configured',
				__( 'UX1 Network app URL or the node API key is missing in Content Sync settings.', 'ux-studio' ),
				array( 'status' => 424 )
			);
		}

		$url       = self::url( $central_url, $page, $query );
		$raw       = (string) wp_json_encode( SiteIdentity::with_site_id( $body ) );
		$timestamp = time();
		$nonce     = bin2hex( random_bytes( 8 ) );

		$response = wp_remote_post(
			$url,
			array(
				'method'  => 'POST',
				'timeout' => self::TIMEOUT,
				'headers' => array_merge(
					array(
						'Content-Type'             => 'application/json',
						HmacAuth::HEADER_SIGNATURE => HmacAuth::sign( 'POST', $url, $raw, $timestamp, $nonce, $secret ),
						HmacAuth::HEADER_TIMESTAMP => (string) $timestamp,
						HmacAuth::HEADER_NONCE     => $nonce,
					),
					self::headers()
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
		if ( $inspect ) {
			self::inspect( $code, $decoded );
		}
		return array(
			'code' => $code,
			'body' => $decoded,
		);
	}

	/**
	 * Read an answer of UX1 Network app for what it says about the link: a copy (423), a site that was moved away
	 * (410) pauses it, a different address on file (409) sends `site_moved`.
	 *
	 * @param int                  $code    HTTP status.
	 * @param array<string, mixed> $decoded Decoded JSON body.
	 */
	public static function inspect( int $code, array $decoded ): void {
		$verdict = SiteIdentity::classify_response( $code, $decoded );
		switch ( $verdict['kind'] ) {
			case SiteIdentity::STATE_CLONE:
			case SiteIdentity::STATE_SUPERSEDED:
				SiteIdentity::pause( $verdict['kind'], $verdict['url'] );
				ActivityLog::log( 'content-sync', 'ca_link_paused', 'setting', 0, array( 'state' => $verdict['kind'], 'url' => $verdict['url'] ) );
				break;
			case 'url_mismatch':
				DomainWatch::report( '' !== $verdict['url'] ? $verdict['url'] : SiteIdentity::known_url(), home_url( '/' ), false );
				break;
		}
	}

	/**
	 * "Connect as a new site": a new site id and a new key, the pause is lifted, the address on file is forgotten.
	 * The normal connect flow follows (the wizard).
	 *
	 * @param ContentSyncModule $content_sync Content Sync module (the key lives in its settings).
	 */
	public static function connect_as_new( ContentSyncModule $content_sync ): void {
		SiteIdentity::regenerate();
		$content_sync->save_settings( array( 'node_api_key' => HmacAuth::generate_key() ) );
		SiteIdentity::resume();
		delete_option( SiteIdentity::KNOWN_URL_OPTION );
		ActivityLog::log( 'content-sync', 'ca_connect_as_new', 'setting', 0 );
	}
}
