<?php
/**
 * Safety net for the starter pack: rules written into .htaccess must not take the site down.
 *
 * @package UxStudio
 */

namespace UxStudio\Core\Onboarding;

use UxStudio\Modules\SecurityOptimization\HtaccessWriter;

defined( 'ABSPATH' ) || exit;

/**
 * A wrong .htaccess directive (a module the host does not load, `Options` that AllowOverride forbids) turns the
 * WHOLE site into HTTP 500. After the pack wrote its rules, the site asks itself for the home page and for
 * wp-login.php. When either answers 500 or more, the file goes back to what it was, and the pack tries once more
 * without the directive that is known to be the host-dependent one (directory listing).
 *
 * The decisions are pure functions (testable without a server); the glue takes the copy of the file, asks the
 * site and puts the file back.
 */
final class HtaccessGuard {

	/** Transient the Security module sets after a failed .htaccess write. */
	public const BACKOFF = 'uxstudio_security_htaccess_backoff';

	/** The one directive whose success depends on the host's AllowOverride, so it is dropped first. */
	public const RISKY_KEY = 'disable_directory_listing';

	/* ── Pure decisions ── */

	/**
	 * Did the check find a broken site? Only a real server error counts: a request that could not be made at all
	 * (code 0, a blocked loopback) is "unknown", never a reason to take the rules away.
	 *
	 * @param array<int|string, int> $codes HTTP status of each probed page (0 = no answer).
	 */
	public static function should_roll_back( array $codes ): bool {
		foreach ( $codes as $code ) {
			if ( (int) $code >= 500 ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Could the site be checked at all? Needs at least one real answer.
	 *
	 * @param array<int|string, int> $codes HTTP status of each probed page.
	 */
	public static function was_checked( array $codes ): bool {
		foreach ( $codes as $code ) {
			if ( (int) $code > 0 ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Keys for the second attempt after a server error: the same rules without the risky directive.
	 *
	 * @param string[] $keys .htaccess keys of the first attempt.
	 * @return string[]|null Keys for the retry, or null when there is nothing left to drop (give up on the group).
	 */
	public static function retry_keys( array $keys ): ?array {
		if ( ! in_array( self::RISKY_KEY, $keys, true ) ) {
			return null;
		}
		return array_values( array_diff( $keys, array( self::RISKY_KEY ) ) );
	}

	/**
	 * Is a file served as a refusal (403 or 404 means the rule works or the file is not there)?
	 *
	 * @param int $code HTTP status.
	 */
	public static function is_refused( int $code ): bool {
		return 403 === $code || 404 === $code;
	}

	/**
	 * Does the answer to a directory without an index file show the file list?
	 *
	 * @param int    $code HTTP status.
	 * @param string $body Response body.
	 */
	public static function is_listing( int $code, string $body ): bool {
		return 200 === $code && 1 === preg_match( '/<title>\s*Index of |<h1>\s*Index of /i', $body );
	}

	/**
	 * Verdict for anonymous `/?author=1` and `/wp-json/wp/v2/users`: nothing reveals a login name.
	 *
	 * @param string $author_location Location header of `/?author=1` ('' when it did not redirect).
	 * @param int    $users_code      HTTP status of the users route.
	 * @param string $users_body      Body of the users route.
	 */
	public static function enumeration_blocked( string $author_location, int $users_code, string $users_body ): bool {
		if ( 1 === preg_match( '#/author/[^/?]+#i', $author_location ) ) {
			return false;
		}
		if ( 401 === $users_code || 403 === $users_code || 404 === $users_code ) {
			return true;
		}
		$list = json_decode( $users_body, true );
		return 200 === $users_code && is_array( $list ) && array() === $list;
	}

	/* ── Glue ── */

	/**
	 * Path of the root .htaccess.
	 */
	public static function path(): string {
		if ( ! function_exists( 'get_home_path' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		return get_home_path() . '.htaccess';
	}

	/**
	 * Copy of the file as it is now (null = there is no file).
	 *
	 * @param string $path Path of the .htaccess file.
	 */
	public static function read_file( string $path ): ?string {
		if ( ! is_file( $path ) ) {
			return null;
		}
		$content = file_get_contents( $path );
		return false === $content ? null : $content;
	}

	/**
	 * Put the file back exactly as the copy says (null = it did not exist, so it is removed again).
	 *
	 * @param string      $path    Path of the .htaccess file.
	 * @param string|null $content Copy from read_file().
	 * @return bool Whether the file is as the copy says now.
	 */
	public static function restore_file( string $path, ?string $content ): bool {
		if ( null === $content ) {
			return ! is_file( $path ) || unlink( $path );
		}
		return false !== file_put_contents( $path, $content, LOCK_EX );
	}

	/**
	 * Forget what the Security module knows about its last write, so it compares with the file again.
	 */
	public static function forget_write_state(): void {
		delete_option( HtaccessWriter::HASH_OPTION );
		delete_transient( self::BACKOFF );
	}

	/**
	 * Status of the home page and of wp-login.php, asked over HTTP from the site itself (0 = no answer).
	 *
	 * @return array{home:int,login:int}
	 */
	public static function probe_site(): array {
		return array(
			'home'  => self::get( home_url( '/' ) )['code'],
			'login' => self::get( site_url( 'wp-login.php' ) )['code'],
		);
	}

	/**
	 * One loopback GET: no redirect followed, short timeout, the same certificate rule WordPress uses for itself.
	 *
	 * @param string $url URL.
	 * @return array{code:int,body:string,location:string}
	 */
	public static function get( string $url ): array {
		$response = wp_remote_get(
			add_query_arg( 'uxs_probe', (string) time(), $url ),
			array(
				'timeout'     => 8,
				'redirection' => 0,
				'cookies'     => array(),
				'sslverify'   => (bool) apply_filters( 'https_local_ssl_verify', false ),
			)
		);
		if ( is_wp_error( $response ) ) {
			return array(
				'code'     => 0,
				'body'     => '',
				'location' => '',
			);
		}
		return array(
			'code'     => (int) wp_remote_retrieve_response_code( $response ),
			'body'     => (string) wp_remote_retrieve_body( $response ),
			'location' => (string) wp_remote_retrieve_header( $response, 'location' ),
		);
	}

	/**
	 * Does the rule of each key really work? Read only. A key is "verified" when the request proves it and
	 * "unverified" when it could not be proved (not the same as "does not work").
	 *
	 * @param string[] $keys Setting keys that were switched on.
	 * @return array<string, string> key => verified | unverified
	 */
	public static function verify( array $keys ): array {
		$out = array();
		foreach ( $keys as $key ) {
			switch ( $key ) {
				case 'protect_wp_config':
					$out[ $key ] = self::file_refused( 'wp-config.php' ) ? 'verified' : 'unverified';
					break;
				case 'block_sensitive_files':
					$out[ $key ] = self::file_refused( 'readme.html' ) && self::file_refused( 'license.txt' ) ? 'verified' : 'unverified';
					break;
				case 'disable_directory_listing':
					$probe       = self::get( content_url( '/uploads/' ) );
					$out[ $key ] = self::is_refused( $probe['code'] ) ? 'verified' : 'unverified';
					break;
				case 'block_user_enumeration':
					$author      = self::get( home_url( '/?author=1' ) );
					$users       = self::get( rest_url( 'wp/v2/users' ) );
					$out[ $key ] = ( $author['code'] > 0 && $users['code'] > 0 && self::enumeration_blocked( $author['location'], $users['code'], $users['body'] ) ) ? 'verified' : 'unverified';
					break;
			}
		}
		return $out;
	}

	/**
	 * A file of the site root that exists is answered with 403 (a file that is not there proves nothing).
	 *
	 * @param string $name File name in the WordPress folder.
	 */
	private static function file_refused( string $name ): bool {
		if ( ! is_file( ABSPATH . $name ) ) {
			return false;
		}
		return 403 === self::get( home_url( '/' . $name ) )['code'];
	}
}
