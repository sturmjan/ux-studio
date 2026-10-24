<?php
/**
 * Regular expressions written by an administrator and evaluated on visitor input.
 *
 * @package UxStudio
 */

namespace UxStudio\Core;

defined( 'ABSPATH' ) || exit;

/**
 * A pattern like `(a+)+$` takes exponential time on a crafted address, referrer, user agent or cookie
 * (ReDoS): a single request could pin a PHP worker. Everything that runs an admin-written pattern against
 * request data goes through here:
 *
 *  - the subject is cut to MAX_SUBJECT bytes (at a character boundary),
 *  - `pcre.backtrack_limit` is lowered for the call (and restored after it), so a runaway match stops
 *    quickly with an error,
 *  - an error counts as "no match" - the rule simply does not apply.
 *
 * `probe()` is used when a pattern is saved: it runs the pattern against a few adversarial strings under
 * a low limit and refuses patterns that hit the limit. That is a heuristic (it cannot prove a pattern safe),
 * the runtime limits are what actually protect the site.
 */
final class SafeRegex {

	/** Longest subject a pattern is run against, in bytes. */
	public const MAX_SUBJECT = 1024;

	/** Backtrack limit while matching request data (the PHP default is 1 000 000). */
	public const MATCH_LIMIT = 20000;

	/** Backtrack limit of the save-time probe. */
	public const PROBE_LIMIT = 10000;

	/**
	 * Cut a subject to the maximum length without splitting a multi-byte character.
	 *
	 * @param string $subject Subject.
	 */
	public static function clip( string $subject ): string {
		if ( strlen( $subject ) <= self::MAX_SUBJECT ) {
			return $subject;
		}
		return function_exists( 'mb_strcut' ) ? mb_strcut( $subject, 0, self::MAX_SUBJECT, 'UTF-8' ) : substr( $subject, 0, self::MAX_SUBJECT );
	}

	/**
	 * Does the (delimited) pattern match the subject? Errors, a runaway match or a bad pattern: false.
	 *
	 * @param string                   $regex   Pattern with delimiters and flags.
	 * @param string                   $subject Request data.
	 * @param array<int|string, mixed> $matches Captured groups (out).
	 */
	public static function match( string $regex, string $subject, ?array &$matches = null ): bool {
		$matches = array();
		$result  = self::run( $regex, self::clip( $subject ), self::MATCH_LIMIT, $matches );
		return 1 === $result;
	}

	/**
	 * `preg_match` under a temporary backtrack limit.
	 *
	 * @param string                   $regex   Pattern with delimiters.
	 * @param string                   $subject Subject.
	 * @param int                      $limit   Backtrack limit.
	 * @param array<int|string, mixed> $matches Captured groups (out).
	 * @return int|false preg_match result.
	 */
	private static function run( string $regex, string $subject, int $limit, array &$matches ) {
		$previous = ini_get( 'pcre.backtrack_limit' );
		$lowered  = false;
		if ( false !== $previous && (int) $previous > $limit ) {
			$lowered = false !== ini_set( 'pcre.backtrack_limit', (string) $limit ); // phpcs:ignore WordPress.PHP.IniSet.Risky
		}
		try {
			$result = @preg_match( $regex, $subject, $matches ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a bad pattern must not break the site.
		} finally {
			if ( $lowered ) {
				ini_set( 'pcre.backtrack_limit', (string) $previous ); // phpcs:ignore WordPress.PHP.IniSet.Risky
			}
		}
		return $result;
	}

	/**
	 * Quick catastrophic-backtracking probe for a pattern about to be saved.
	 *
	 * @param string $regex Pattern with delimiters and flags.
	 * @return bool True when the pattern compiled and survived the probe strings; false when it is invalid or hit the limit.
	 */
	public static function probe( string $regex ): bool {
		$none = array();
		if ( false === self::run( $regex, '', self::PROBE_LIMIT, $none ) ) {
			return false; // Does not compile.
		}
		$subjects = array(
			str_repeat( 'a', 40 ) . '!',
			str_repeat( '0', 40 ) . '!',
			str_repeat( 'ab', 24 ) . '!',
			str_repeat( 'a/', 24 ) . '!',
			'/' . str_repeat( 'a-', 24 ) . '?x=' . str_repeat( 'b', 24 ) . '!',
			str_repeat( ' ', 40 ) . '!',
			str_repeat( 'x.', 24 ) . '!',
		);
		// Strings made of each literal character of the pattern, so `(x+x+)+y` is fed x's, not only a's.
		$literals = array_slice( array_unique( str_split( (string) preg_replace( '/[^A-Za-z0-9]/', '', $regex ) ) ), 0, 8 );
		foreach ( $literals as $char ) {
			// The other literals follow the '!' so PCRE's "required character" shortcut does not skip the match.
			$subjects[] = str_repeat( (string) $char, 40 ) . '!' . implode( '', $literals );
		}
		foreach ( $subjects as $subject ) {
			$matches = array();
			if ( false === self::run( $regex, $subject, self::PROBE_LIMIT, $matches ) && PREG_NO_ERROR !== preg_last_error() ) {
				return false;
			}
		}
		return true;
	}
}
