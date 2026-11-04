<?php
/**
 * IndexNow: tells Bing, Seznam, Yandex, Naver and Yep that a page changed (PLAN.md 39.7).
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\IndexingNotice;

use UxStudio\Core\Migration\Overlaps;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * The site calls the shared IndexNow endpoint directly (no Central App). The key is 32 random hex characters made
 * the first time the feature is on and kept in an option without autoload. It is NOT a secret: the protocol needs
 * it to be readable at `/<key>.txt`, which this class serves from `template_redirect` (no file in the site root,
 * so it works without write access and survives a domain move, the key stays valid for the new host).
 *
 * Answers: 200 / 202 accepted, 403 the key does not match, 422 an address does not belong to the host, 429 too
 * many requests (the queue tries again later). Pure helpers are tested in tests/indexing-queue.php.
 */
final class IndexNow {

	public const ENDPOINT   = 'https://api.indexnow.org/indexnow';
	public const KEY_OPTION = 'uxstudio_indexnow_key';
	private const TIMEOUT   = 15;

	/** Most addresses in one request (the protocol allows 10 000, a site save never gets near). */
	public const MAX_URLS = 100;

	/**
	 * A fresh key.
	 */
	public static function generate_key(): string {
		return bin2hex( random_bytes( 16 ) );
	}

	/**
	 * Is this one of our keys (32 lower case hex characters)?
	 *
	 * @param string $key Candidate.
	 */
	public static function is_key( string $key ): bool {
		return 1 === preg_match( '/^[0-9a-f]{32}$/', $key );
	}

	/**
	 * Body of the IndexNow request.
	 *
	 * @param string   $host         Host name of the site.
	 * @param string   $key          Key.
	 * @param string   $key_location Address where the key can be read.
	 * @param string[] $urls         Addresses that changed.
	 * @return array{host:string,key:string,keyLocation:string,urlList:string[]}
	 */
	public static function payload( string $host, string $key, string $key_location, array $urls ): array {
		return array(
			'host'        => $host,
			'key'         => $key,
			'keyLocation' => $key_location,
			'urlList'     => array_slice( self::urls_of_host( $host, $urls ), 0, self::MAX_URLS ),
		);
	}

	/**
	 * Only addresses of the host (IndexNow answers 422 for a foreign one), without duplicates.
	 *
	 * @param string   $host Host name.
	 * @param string[] $urls Addresses.
	 * @return string[]
	 */
	public static function urls_of_host( string $host, array $urls ): array {
		$out = array();
		foreach ( $urls as $url ) {
			$url = (string) $url;
			if ( 0 === stripos( $url, 'http' ) && 0 === strcasecmp( (string) parse_url( $url, PHP_URL_HOST ), $host ) ) {
				$out[ $url ] = $url;
			}
		}
		return array_values( $out );
	}

	/**
	 * What an HTTP status means for the queue.
	 *
	 * @param int $code HTTP status.
	 * @return string ok | bad_key | bad_url | rate | error
	 */
	public static function interpret( int $code ): string {
		if ( 200 === $code || 202 === $code ) {
			return 'ok';
		}
		if ( 403 === $code ) {
			return 'bad_key';
		}
		if ( 422 === $code ) {
			return 'bad_url';
		}
		if ( 429 === $code ) {
			return 'rate';
		}
		return 'error';
	}

	/**
	 * The key when this request asks for `/<key>.txt`: path without the folder of the site, `''` when it does not.
	 *
	 * @param string $request_path Path of the request.
	 * @param string $home_path    Path of the site address ('' or '/folder').
	 * @return string The key from the path (not checked against the stored one), or ''.
	 */
	public static function key_from_path( string $request_path, string $home_path ): string {
		$home = rtrim( $home_path, '/' );
		if ( '' !== $home && 0 === strpos( $request_path, $home . '/' ) ) {
			$request_path = substr( $request_path, strlen( $home ) );
		}
		return 1 === preg_match( '#^/([0-9a-f]{32})\.txt$#', $request_path, $m ) ? $m[1] : '';
	}

	/**
	 * Names of the plugins that already send IndexNow (Rank Math, Yoast, the IndexNow plugin), so a page is not
	 * announced twice. Same data the overlap catalog uses.
	 *
	 * @return string[]
	 */
	public static function foreign_senders(): array {
		$names = Overlaps::blocked_settings( 'indexing-notice' )['indexnow_enabled'] ?? array();
		return array_values( array_unique( array_map( 'strval', $names ) ) );
	}

	/**
	 * The key, made on first use.
	 */
	public static function key(): string {
		$key = (string) get_option( self::KEY_OPTION, '' );
		if ( self::is_key( $key ) ) {
			return $key;
		}
		$key = self::generate_key();
		update_option( self::KEY_OPTION, $key, false );
		return $key;
	}

	/**
	 * Where the key is read.
	 */
	public static function key_url(): string {
		return home_url( '/' . self::key() . '.txt' );
	}

	/**
	 * Send addresses to IndexNow.
	 *
	 * @param string[] $urls Addresses (of this site).
	 * @return array{status:string,http:int}|WP_Error status: ok | bad_key | bad_url | rate | error
	 */
	public static function submit( array $urls ) {
		$host = (string) parse_url( home_url(), PHP_URL_HOST );
		$key  = self::key();
		$body = self::payload( $host, $key, self::key_url(), $urls );
		if ( ! $body['urlList'] ) {
			return new WP_Error( 'uxstudio_indexnow_empty', __( 'There is no address of this site to send.', 'ux-studio' ) );
		}
		$response = wp_remote_post(
			self::ENDPOINT,
			array(
				'timeout' => self::TIMEOUT,
				'headers' => array( 'Content-Type' => 'application/json; charset=utf-8' ),
				'body'    => wp_json_encode( $body ),
			)
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$http = (int) wp_remote_retrieve_response_code( $response );
		return array(
			'status' => self::interpret( $http ),
			'http'   => $http,
		);
	}

	/**
	 * template_redirect: answer `/<key>.txt` with the key. Cheap for every other request (a path test first,
	 * the option is read only when the path has the right shape).
	 *
	 * @param bool $enabled Whether the feature is on.
	 */
	public static function serve( bool $enabled ): void {
		if ( ! $enabled || empty( $_SERVER['REQUEST_URI'] ) ) {
			return;
		}
		$path = (string) parse_url( wp_unslash( (string) $_SERVER['REQUEST_URI'] ), PHP_URL_PATH ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- only matched against a fixed pattern.
		$asked = self::key_from_path( $path, (string) parse_url( home_url(), PHP_URL_PATH ) );
		if ( '' === $asked || ! hash_equals( (string) get_option( self::KEY_OPTION, '' ), $asked ) ) {
			return;
		}
		status_header( 200 );
		header( 'Content-Type: text/plain; charset=utf-8' );
		header( 'Cache-Control: public, max-age=3600' );
		header( 'X-Robots-Tag: noindex' );
		echo $asked; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- 32 hex characters, validated above.
		exit;
	}
}
