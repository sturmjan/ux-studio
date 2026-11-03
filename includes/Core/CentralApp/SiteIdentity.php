<?php
/**
 * Stable identity of the site towards the Central App (PLAN.md 40.2) and the state of that link.
 *
 * @package UxStudio
 */

namespace UxStudio\Core\CentralApp;

defined( 'ABSPATH' ) || exit;

/**
 * `uxstudio_site_id` is a UUID v4 made once (lazily, the first time something needs it) and kept in the database,
 * so it travels with the database to a new domain. It is NOT a secret: it only tells the Central App WHICH site
 * calls; the signature with `node_api_key` is what proves it. Every signed call to the Central App carries it in
 * the header `X-UxStudio-Site-Id` and in the body field `site_id`.
 *
 * Also here: the address the Central App last knew (`uxstudio_ca_known_url`, the domain watch compares it with
 * home_url()) and the paused state of a copy or a moved site (`uxstudio_ca_clone`). The pure helpers (uuid,
 * address comparison, the move decision, the response classification) need no WordPress and are covered by
 * tests/central-app-identity.php.
 */
final class SiteIdentity {

	/** Option: the site id (UUID v4), no autoload. */
	public const ID_OPTION = 'uxstudio_site_id';

	/** Option: address the Central App knows the site under (no autoload). */
	public const KNOWN_URL_OPTION = 'uxstudio_ca_known_url';

	/** Option: array{state:string,url:string,since:int} when the link is paused, no autoload. */
	public const CLONE_OPTION = 'uxstudio_ca_clone';

	/** Request header that carries the site id. */
	public const HEADER = 'X-UxStudio-Site-Id';

	public const STATE_CLONE      = 'clone';
	public const STATE_SUPERSEDED = 'superseded';

	/* ── Pure helpers ── */

	/**
	 * Is this a UUID version 4 (lower case, with dashes)?
	 *
	 * @param string $value Candidate.
	 */
	public static function is_uuid4( string $value ): bool {
		return 1 === preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $value );
	}

	/**
	 * A fresh UUID v4.
	 */
	public static function generate_uuid4(): string {
		$bytes    = random_bytes( 16 );
		$bytes[6] = chr( ( ord( $bytes[6] ) & 0x0f ) | 0x40 );
		$bytes[8] = chr( ( ord( $bytes[8] ) & 0x3f ) | 0x80 );
		$hex      = bin2hex( $bytes );
		return substr( $hex, 0, 8 ) . '-' . substr( $hex, 8, 4 ) . '-' . substr( $hex, 12, 4 ) . '-' . substr( $hex, 16, 4 ) . '-' . substr( $hex, 20, 12 );
	}

	/**
	 * An address as the comparison sees it: lower case scheme and host, no default port, no trailing slash.
	 *
	 * @param string $url Address.
	 */
	public static function normalize_url( string $url ): string {
		$parts = parse_url( trim( $url ) );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
			return strtolower( rtrim( trim( $url ), '/' ) );
		}
		$scheme = strtolower( (string) ( $parts['scheme'] ?? 'http' ) );
		$port   = isset( $parts['port'] ) ? (int) $parts['port'] : 0;
		if ( ( 'https' === $scheme && 443 === $port ) || ( 'http' === $scheme && 80 === $port ) ) {
			$port = 0;
		}
		return $scheme . '://' . strtolower( (string) $parts['host'] ) . ( $port ? ':' . $port : '' ) . rtrim( (string) ( $parts['path'] ?? '' ), '/' );
	}

	/**
	 * What the domain watch does for the stored and the current address.
	 *
	 *  - `init`  nothing is stored yet: remember the current address, tell nobody,
	 *  - `none`  the same address, or the link is paused (a copy never reports a move),
	 *  - `send`  the address changed: send `site_moved`.
	 *
	 * @param string     $known   Stored address ('' = none).
	 * @param string     $current home_url( '/' ).
	 * @param array|null $paused  Paused state or null.
	 */
	public static function move_decision( string $known, string $current, ?array $paused ): string {
		if ( null !== $paused ) {
			return 'none';
		}
		if ( '' === trim( $known ) ) {
			return 'init';
		}
		return self::normalize_url( $known ) === self::normalize_url( $current ) ? 'none' : 'send';
	}

	/**
	 * What a Central App answer means for the link, whatever the call was.
	 *
	 * @param int                  $code    HTTP status.
	 * @param array<string, mixed> $decoded Decoded JSON body.
	 * @return array{kind:string,url:string} kind: `none` | `url_mismatch` | `superseded` | `clone`.
	 */
	public static function classify_response( int $code, array $decoded ): array {
		$kind = (string) ( $decoded['code'] ?? '' );
		if ( 409 === $code && 'url_mismatch' === $kind ) {
			return array( 'kind' => 'url_mismatch', 'url' => (string) ( $decoded['known_url'] ?? '' ) );
		}
		if ( 410 === $code && 'superseded' === $kind ) {
			return array( 'kind' => self::STATE_SUPERSEDED, 'url' => (string) ( $decoded['new_url'] ?? '' ) );
		}
		if ( 423 === $code && 'clone' === $kind ) {
			return array( 'kind' => self::STATE_CLONE, 'url' => (string) ( $decoded['original_url'] ?? '' ) );
		}
		return array( 'kind' => 'none', 'url' => '' );
	}

	/**
	 * What the answer to `site_moved` means.
	 *
	 * @param int                  $code    HTTP status.
	 * @param array<string, mixed> $decoded Decoded JSON body.
	 * @return array{kind:string,url:string} kind: `moved` | `pending` | `clone` | `error`.
	 */
	public static function classify_move_response( int $code, array $decoded ): array {
		$status = (string) ( $decoded['status'] ?? '' );
		if ( 200 === $code && 'moved' === $status ) {
			return array( 'kind' => 'moved', 'url' => (string) ( $decoded['url'] ?? '' ) );
		}
		if ( 202 === $code && 'pending_decision' === $status ) {
			return array( 'kind' => 'pending', 'url' => '' );
		}
		if ( 200 === $code && 'clone' === $status ) {
			return array( 'kind' => 'clone', 'url' => (string) ( $decoded['original_url'] ?? '' ) );
		}
		return array( 'kind' => 'error', 'url' => '' );
	}

	/* ── WordPress side ── */

	/**
	 * The site id; made on first use.
	 */
	public static function site_id(): string {
		$id = (string) get_option( self::ID_OPTION, '' );
		if ( self::is_uuid4( $id ) ) {
			return $id;
		}
		return self::regenerate();
	}

	/**
	 * A new site id (only "Connect as a new site" does this).
	 */
	public static function regenerate(): string {
		$id = self::generate_uuid4();
		update_option( self::ID_OPTION, $id, false );
		return $id;
	}

	/**
	 * Headers every signed call to the Central App adds.
	 *
	 * @return array<string, string>
	 */
	public static function headers(): array {
		return array( self::HEADER => self::site_id() );
	}

	/**
	 * Body of a signed call with the site id added.
	 *
	 * @param array<string, mixed> $body Body.
	 * @return array<string, mixed>
	 */
	public static function with_site_id( array $body ): array {
		$body['site_id'] = self::site_id();
		return $body;
	}

	/**
	 * Address the Central App knows the site under ('' = not recorded yet).
	 */
	public static function known_url(): string {
		return (string) get_option( self::KNOWN_URL_OPTION, '' );
	}

	/**
	 * Remember the address the Central App knows the site under.
	 *
	 * @param string $url Address.
	 */
	public static function set_known_url( string $url ): void {
		update_option( self::KNOWN_URL_OPTION, $url, false );
	}

	/**
	 * The paused state, or null when the link is live.
	 *
	 * @return array{state:string,url:string,since:int}|null
	 */
	public static function paused(): ?array {
		$value = get_option( self::CLONE_OPTION, null );
		if ( ! is_array( $value ) || ! in_array( $value['state'] ?? '', array( self::STATE_CLONE, self::STATE_SUPERSEDED ), true ) ) {
			return null;
		}
		return array(
			'state' => (string) $value['state'],
			'url'   => (string) ( $value['url'] ?? '' ),
			'since' => (int) ( $value['since'] ?? 0 ),
		);
	}

	/**
	 * Is the link to the Central App paused (this site is a copy, or was moved away)?
	 */
	public static function is_paused(): bool {
		return null !== self::paused();
	}

	/**
	 * Pause the link.
	 *
	 * @param string $state `clone` or `superseded`.
	 * @param string $url   The original address (clone) or the new one (superseded).
	 */
	public static function pause( string $state, string $url ): void {
		if ( ! in_array( $state, array( self::STATE_CLONE, self::STATE_SUPERSEDED ), true ) ) {
			return;
		}
		update_option(
			self::CLONE_OPTION,
			array(
				'state' => $state,
				'url'   => esc_url_raw( $url ),
				'since' => time(),
			),
			false
		);
	}

	/**
	 * Lift the pause.
	 */
	public static function resume(): void {
		delete_option( self::CLONE_OPTION );
	}
}
