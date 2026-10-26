<?php
/**
 * Registry of UX Studio's own inline scripts, allowed in a strict CSP by hash.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\SecurityOptimization;

defined( 'ABSPATH' ) || exit;

/**
 * Why hashes and not nonces (PLAN.md 20.14): a nonce has to be new on every response, so a page cache that stores
 * the HTML would serve a stale nonce and the browser would block the script. A hash depends only on the script text,
 * which is the same in every response - cache safe.
 *
 * The header goes out BEFORE the page body, so the hash of a script has to be known without rendering it. A module
 * therefore registers a PROVIDER (a callable) instead of a body printed later: it returns the exact texts it will print
 * - for all of its scripts that can appear, not only those on the current page. The printing code and the provider
 * use the same function for the text, so the hash always matches the byte stream.
 *
 * A provider returns a list of items, each either a body string or an array with `body` (or a precomputed `hash`)
 * and an optional `label` shown in the CSP admin screen.
 *
 * Hashes are only put into the policy when it has no 'unsafe-inline' (see CspPolicy::with_inline_hashes()): browsers
 * ignore 'unsafe-inline' as soon as a hash is present, so adding one to a lenient policy would block every other
 * inline script of the site.
 */
final class CspInlineScripts {

	/** Upper bound of hashes in one header (a header has a size limit and a bloated list is a smell). */
	public const MAX = 200;

	/** @var array<string, array{label:string, provider:callable}> */
	private static array $providers = array();

	/**
	 * @param string   $id       Stable id of the provider (a second registration with the same id replaces the first).
	 * @param string   $label    What the scripts are, for the admin screen.
	 * @param callable $provider Returns list<string|array{body?:string,hash?:string,label?:string}>.
	 */
	public static function register( string $id, string $label, callable $provider ): void {
		self::$providers[ $id ] = array(
			'label'    => $label,
			'provider' => $provider,
		);
	}

	/** Test helper. */
	public static function reset(): void {
		self::$providers = array();
	}

	/**
	 * CSP hash token of a script body: `sha256-<base64 of the SHA-256 of the exact bytes>` (without quotes).
	 * The body is the text between <script> and </script>, whitespace included.
	 */
	public static function hash( string $body ): string {
		return 'sha256-' . base64_encode( hash( 'sha256', $body, true ) );
	}

	/**
	 * All registered scripts, deduplicated by hash.
	 *
	 * @return array{entries:list<array{hash:string,label:string,provider:string}>,total:int,capped:bool}
	 */
	public static function collect(): array {
		$entries = array();
		foreach ( self::$providers as $id => $meta ) {
			try {
				$items = (array) ( $meta['provider'] )();
			} catch ( \Throwable $e ) {
				continue; // A broken provider must not take the policy down.
			}
			foreach ( $items as $item ) {
				$body  = is_array( $item ) ? (string) ( $item['body'] ?? '' ) : (string) $item;
				$hash  = is_array( $item ) && ! empty( $item['hash'] ) ? (string) $item['hash'] : ( '' !== $body ? self::hash( $body ) : '' );
				$label = is_array( $item ) && ! empty( $item['label'] ) ? (string) $item['label'] : $meta['label'];
				if ( 1 !== preg_match( '#^sha256-[A-Za-z0-9+/]{43}=$#', $hash ) ) {
					continue;
				}
				$entries[ $hash ] = array(
					'hash'     => $hash,
					'label'    => $label,
					'provider' => (string) $id,
				);
			}
		}
		$total = count( $entries );
		return array(
			'entries' => array_values( array_slice( $entries, 0, self::MAX ) ),
			'total'   => $total,
			'capped'  => $total > self::MAX,
		);
	}

	/**
	 * Quoted CSP source expressions, ready for script-src.
	 *
	 * @return list<string>
	 */
	public static function sources(): array {
		return array_map(
			static fn( array $e ): string => "'" . $e['hash'] . "'",
			self::collect()['entries']
		);
	}
}
