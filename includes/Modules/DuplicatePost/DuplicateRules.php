<?php
/**
 * Pure rules of the Duplicate Post module (no WordPress calls, covered by tests/content-b3.php).
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\DuplicatePost;

defined( 'ABSPATH' ) || exit;

/**
 * Lists of excluded fields, wildcard matching, the status of a copy and the role check.
 */
final class DuplicateRules {

	/** Statuses a copy may keep from the original. Everything else (future, trash, inherit ...) becomes a draft. */
	private const KEEPABLE_STATUSES = array( 'publish', 'draft', 'pending', 'private' );

	/**
	 * Split a list typed by a person: one entry per line or separated by commas, `#` starts a comment.
	 *
	 * @param string $text Raw text.
	 * @return string[] Unique entries in the order given.
	 */
	public static function parse_list( string $text ): array {
		$out = array();
		foreach ( preg_split( '/[\r\n,]+/', $text ) ?: array() as $entry ) {
			$hash = strpos( $entry, '#' );
			if ( false !== $hash ) {
				$entry = substr( $entry, 0, $hash );
			}
			$entry = trim( $entry );
			if ( '' !== $entry && ! in_array( $entry, $out, true ) ) {
				$out[] = $entry;
			}
		}
		return $out;
	}

	/**
	 * Whether a meta key matches one of the patterns: an exact key or a pattern with `*` (any characters),
	 * for example `_edit_*` or `*_cache`. Case sensitive, like meta keys in the database.
	 *
	 * @param string   $key      Meta key.
	 * @param string[] $patterns Exact keys and wildcard patterns.
	 */
	public static function matches( string $key, array $patterns ): bool {
		foreach ( $patterns as $pattern ) {
			$pattern = (string) $pattern;
			if ( '' === $pattern ) {
				continue;
			}
			if ( false === strpos( $pattern, '*' ) ) {
				if ( $key === $pattern ) {
					return true;
				}
				continue;
			}
			$regex = '/^' . str_replace( '\*', '.*', preg_quote( $pattern, '/' ) ) . '$/s';
			if ( \UxStudio\Core\SafeRegex::match( $regex, $key ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Status of the copy.
	 *
	 * @param string $original  Status of the original post.
	 * @param string $fixed     The configured fixed status.
	 * @param bool   $keep      Keep the status of the original.
	 * @return string
	 */
	public static function copy_status( string $original, string $fixed, bool $keep ): string {
		if ( ! $keep ) {
			return $fixed;
		}
		// A scheduled post would be published at once (its date is not copied), so the copy waits as a draft.
		return in_array( $original, self::KEEPABLE_STATUSES, true ) ? $original : 'draft';
	}

	/**
	 * Whether a person with these roles may duplicate. No role selected = everybody who may edit the post.
	 *
	 * @param string[] $user_roles    Roles of the user.
	 * @param string[] $allowed_roles Roles selected in the settings.
	 * @param bool     $is_admin      Administrators (manage_options) always may.
	 */
	public static function role_allowed( array $user_roles, array $allowed_roles, bool $is_admin ): bool {
		if ( $is_admin || array() === $allowed_roles ) {
			return true;
		}
		return array() !== array_intersect( $user_roles, $allowed_roles );
	}
}
