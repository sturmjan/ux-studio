<?php
/**
 * Run conditions of a code snippet (PLAN.md 27, B2): where, for whom and when a snippet may produce output or run.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\CodeSnippets;

defined( 'ABSPATH' ) || exit;

/**
 * A snippet may carry up to six conditions; it runs only when ALL of them hold (AND). A condition is
 * `{ type, value, negate }`:
 *
 *  - `page`      value = list of page kinds. Every request has exactly ONE kind, decided in this order (the same
 *                order WPCode uses, so its "type of page" rules map one to one): `admin` (wp-admin), `front_page`,
 *                `blog_home` (the posts page), `singular` (a single post / page / custom post; NOT the static front
 *                page, that one is `front_page`), `author`, `archive` (every other archive), `search`, `404`.
 *  - `post_type` value = list of post types, `scope` single (default) / archive / both: the post type of the single
 *                post being viewed and / or of the post type archive.
 *  - `post`      value = list of post / page ids (the single post, or the posts page).
 *  - `login`     value `in` / `out`.
 *  - `role`      value = list of roles; a visitor who is not logged in has no role.
 *  - `device`    value `mobile` / `desktop`, decided by wp_is_mobile() (the user agent).
 *  - `schedule`  `from` and / or `to` (site time, "Y-m-d H:i"): runs only inside the window.
 *
 * `negate` turns a condition around ("not on the front page"). The conditions only ever NARROW where a snippet runs,
 * they cannot make it run anywhere its place does not already allow; they are stored in the metadata table like the
 * place, so only database access can change them. Matching is pure (a plain context array), so it is tested without
 * WordPress; {@see self::context()} builds the context of the current request.
 */
final class SnippetConditions {

	public const MAX = 6;

	public const TYPES = array( 'page', 'post_type', 'post', 'login', 'role', 'device', 'schedule' );

	public const PAGE_KINDS = array( 'front_page', 'blog_home', 'singular', 'archive', 'author', 'search', '404', 'admin' );

	public const SCOPES = array( 'single', 'archive', 'both' );

	/** Most values in one list. */
	private const MAX_VALUES = 50;

	/**
	 * Validate and canonicalise submitted conditions.
	 *
	 * @param mixed $raw List of conditions, a JSON string of that list, or empty.
	 * @return array{conditions:array<int, array<string, mixed>>, errors:array<int, string>}
	 */
	public static function normalize( $raw ): array {
		if ( is_string( $raw ) ) {
			$raw = '' === trim( $raw ) ? array() : json_decode( $raw, true );
		}
		if ( ! is_array( $raw ) || array() === $raw ) {
			return array( 'conditions' => array(), 'errors' => array() );
		}
		if ( isset( $raw['type'] ) ) {
			$raw = array( $raw ); // A single condition given as an object.
		}
		$out    = array();
		$errors = array();
		foreach ( array_values( $raw ) as $i => $item ) {
			$n = $i + 1;
			if ( $i >= self::MAX ) {
				/* translators: %d: maximum number of conditions. */
				$errors[] = sprintf( __( 'A snippet can have at most %d conditions.', 'ux-studio' ), self::MAX );
				break;
			}
			if ( ! is_array( $item ) ) {
				continue;
			}
			$one = self::normalize_one( $item, $n );
			if ( is_string( $one ) ) {
				$errors[] = $one;
				continue;
			}
			$out[] = $one;
		}
		return array( 'conditions' => $errors ? array() : $out, 'errors' => $errors );
	}

	/**
	 * One condition, or an error message.
	 *
	 * @param array<string, mixed> $item Raw condition.
	 * @param int                  $n    Position (1-based) for the message.
	 * @return array<string, mixed>|string
	 */
	private static function normalize_one( array $item, int $n ) {
		$type   = sanitize_key( (string) ( $item['type'] ?? '' ) );
		$negate = ! empty( $item['negate'] ) && 'false' !== $item['negate'];
		if ( ! in_array( $type, self::TYPES, true ) ) {
			/* translators: %d: condition number. */
			return sprintf( __( 'Condition %d has an unknown type.', 'ux-studio' ), $n );
		}
		switch ( $type ) {
			case 'login':
				$value = sanitize_key( (string) ( $item['value'] ?? '' ) );
				if ( ! in_array( $value, array( 'in', 'out' ), true ) ) {
					/* translators: %d: condition number. */
					return sprintf( __( 'Condition %d: choose logged in or logged out.', 'ux-studio' ), $n );
				}
				// "Not logged in" is "logged out": one canonical form.
				return array( 'type' => 'login', 'value' => $negate ? ( 'in' === $value ? 'out' : 'in' ) : $value, 'negate' => false );
			case 'device':
				$value = sanitize_key( (string) ( $item['value'] ?? '' ) );
				if ( ! in_array( $value, array( 'mobile', 'desktop' ), true ) ) {
					/* translators: %d: condition number. */
					return sprintf( __( 'Condition %d: choose mobile or desktop.', 'ux-studio' ), $n );
				}
				return array( 'type' => 'device', 'value' => $negate ? ( 'mobile' === $value ? 'desktop' : 'mobile' ) : $value, 'negate' => false );
			case 'schedule':
				$from = self::clean_time( $item['from'] ?? '' );
				$to   = self::clean_time( $item['to'] ?? '' );
				if ( '' === $from && '' === $to ) {
					/* translators: %d: condition number. */
					return sprintf( __( 'Condition %d: enter a start, an end, or both (YYYY-MM-DD HH:MM).', 'ux-studio' ), $n );
				}
				if ( '' !== $from && '' !== $to && $from > $to ) {
					/* translators: %d: condition number. */
					return sprintf( __( 'Condition %d: the start is after the end.', 'ux-studio' ), $n );
				}
				return array( 'type' => 'schedule', 'from' => $from, 'to' => $to, 'negate' => $negate );
			case 'page':
				$values = self::list_of( $item['value'] ?? array(), 'sanitize_key' );
				if ( ! $values || array_diff( $values, self::PAGE_KINDS ) ) {
					/* translators: %d: condition number. */
					return sprintf( __( 'Condition %d: choose at least one type of page.', 'ux-studio' ), $n );
				}
				return array( 'type' => 'page', 'value' => $values, 'negate' => $negate );
			case 'post_type':
				$values = self::list_of( $item['value'] ?? array(), 'sanitize_key' );
				if ( ! $values ) {
					/* translators: %d: condition number. */
					return sprintf( __( 'Condition %d: choose at least one post type.', 'ux-studio' ), $n );
				}
				$scope = sanitize_key( (string) ( $item['scope'] ?? 'single' ) );
				return array( 'type' => 'post_type', 'value' => $values, 'scope' => in_array( $scope, self::SCOPES, true ) ? $scope : 'single', 'negate' => $negate );
			case 'post':
				$ids = array_values( array_unique( array_filter( array_map( 'intval', self::list_of( $item['value'] ?? array(), 'trim' ) ), static fn ( int $id ): bool => $id > 0 ) ) );
				if ( ! $ids || count( $ids ) > self::MAX_VALUES ) {
					/* translators: %d: condition number. */
					return sprintf( __( 'Condition %d: enter the ids of one or more posts or pages.', 'ux-studio' ), $n );
				}
				sort( $ids );
				return array( 'type' => 'post', 'value' => $ids, 'negate' => $negate );
			default: // role.
				$values = self::list_of( $item['value'] ?? array(), 'sanitize_key' );
				if ( ! $values ) {
					/* translators: %d: condition number. */
					return sprintf( __( 'Condition %d: choose at least one role.', 'ux-studio' ), $n );
				}
				return array( 'type' => 'role', 'value' => $values, 'negate' => $negate );
		}
	}

	/**
	 * A list from an array or a comma / newline separated string: cleaned, de-duplicated, sorted, bounded.
	 *
	 * @param mixed    $value Raw.
	 * @param callable $clean Cleaner for one entry.
	 * @return array<int, string>
	 */
	private static function list_of( $value, callable $clean ): array {
		$items = is_array( $value ) ? $value : preg_split( '/[\s,;]+/', (string) $value );
		$out   = array();
		foreach ( (array) $items as $item ) {
			$one = (string) $clean( (string) $item );
			if ( '' !== $one ) {
				$out[ $one ] = $one;
			}
		}
		$out = array_values( $out );
		sort( $out, SORT_STRING );
		return array_slice( $out, 0, self::MAX_VALUES );
	}

	/**
	 * "YYYY-MM-DD HH:MM[:SS]" (also with a T) as "YYYY-MM-DD HH:MM:SS", or '' when it is not a real date.
	 *
	 * @param mixed $value Raw.
	 */
	private static function clean_time( $value ): string {
		$value = trim( (string) $value );
		if ( 1 !== preg_match( '/^(\d{4})-(\d{2})-(\d{2})(?:[ T](\d{2}):(\d{2})(?::(\d{2}))?)?$/', $value, $m ) || ! checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) ) {
			return '';
		}
		$h = (int) ( $m[4] ?? 0 );
		$i = (int) ( $m[5] ?? 0 );
		$s = (int) ( $m[6] ?? 0 );
		if ( $h > 23 || $i > 59 || $s > 59 ) {
			return '';
		}
		return sprintf( '%s-%s-%s %02d:%02d:%02d', $m[1], $m[2], $m[3], $h, $i, $s );
	}

	/**
	 * Canonical JSON for storage ('' = no conditions). Slashes are not escaped (update_post_meta-style stripping is not involved here).
	 *
	 * @param array<int, array<string, mixed>> $conditions Normalised conditions.
	 */
	public static function encode( array $conditions ): string {
		return $conditions ? (string) wp_json_encode( array_values( $conditions ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) : '';
	}

	/**
	 * Stored conditions back to a list (a corrupt value means none).
	 *
	 * @param mixed $stored Stored JSON.
	 * @return array<int, array<string, mixed>>
	 */
	public static function decode( $stored ): array {
		if ( ! is_string( $stored ) || '' === $stored ) {
			return array();
		}
		$data = json_decode( $stored, true );
		return is_array( $data ) ? self::normalize( $data )['conditions'] : array();
	}

	/**
	 * Whether evaluating needs the parsed request (page kind, post), which is known only from the `wp` action on.
	 *
	 * @param array<int, array<string, mixed>> $conditions Conditions.
	 */
	public static function needs_query( array $conditions ): bool {
		foreach ( $conditions as $condition ) {
			if ( in_array( $condition['type'] ?? '', array( 'page', 'post_type', 'post' ), true ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Whether all conditions hold for a request context. No conditions = always.
	 *
	 * @param array<int, array<string, mixed>> $conditions Normalised conditions.
	 * @param array<string, mixed>             $context    See {@see self::blank_context()}.
	 */
	public static function matches( array $conditions, array $context ): bool {
		foreach ( $conditions as $condition ) {
			if ( ! self::one_matches( $condition, $context ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * The context of a request that nothing is known about (a visitor who is not logged in, on a desktop, no page).
	 *
	 * @return array<string, mixed>
	 */
	public static function blank_context(): array {
		return array(
			'kind'         => null, // null = the request is not parsed yet; '' = a page of no known kind.
			'single_type'  => '',
			'archive_type' => '',
			'post_id'      => 0,
			'logged_in'    => false,
			'roles'        => array(),
			'mobile'       => false,
			'now'          => '1970-01-01 00:00:00',
		);
	}

	/**
	 * @param array<string, mixed> $c       One condition.
	 * @param array<string, mixed> $context Context.
	 */
	private static function one_matches( array $c, array $context ): bool {
		$negate = ! empty( $c['negate'] );
		switch ( $c['type'] ?? '' ) {
			case 'login':
				$hit = ( 'in' === $c['value'] ) === ! empty( $context['logged_in'] );
				break;
			case 'device':
				$hit = ( 'mobile' === $c['value'] ) === ! empty( $context['mobile'] );
				break;
			case 'role':
				$hit = ! empty( $context['logged_in'] ) && (bool) array_intersect( (array) $c['value'], (array) ( $context['roles'] ?? array() ) );
				break;
			case 'schedule':
				$now = (string) ( $context['now'] ?? '' );
				$hit = ( '' === ( $c['from'] ?? '' ) || $now >= $c['from'] ) && ( '' === ( $c['to'] ?? '' ) || $now <= $c['to'] );
				break;
			case 'page':
				// An unparsed request has no kind: nothing matches (and a negated condition does not either, see below).
				if ( null === ( $context['kind'] ?? null ) ) {
					return false;
				}
				$hit = in_array( $context['kind'], (array) $c['value'], true );
				break;
			case 'post_type':
				if ( null === ( $context['kind'] ?? null ) ) {
					return false;
				}
				$scope = (string) ( $c['scope'] ?? 'single' );
				$hit   = ( 'archive' !== $scope && '' !== (string) $context['single_type'] && in_array( $context['single_type'], (array) $c['value'], true ) )
					|| ( 'single' !== $scope && '' !== (string) $context['archive_type'] && in_array( $context['archive_type'], (array) $c['value'], true ) );
				break;
			case 'post':
				if ( null === ( $context['kind'] ?? null ) ) {
					return false;
				}
				$hit = (int) $context['post_id'] > 0 && in_array( (int) $context['post_id'], array_map( 'intval', (array) $c['value'] ), true );
				break;
			default:
				return false; // An unknown condition never lets a snippet run.
		}
		return $negate ? ! $hit : $hit;
	}

	/**
	 * Page kind of the request WordPress is serving (see the class description for the order).
	 */
	public static function current_kind(): string {
		if ( is_admin() ) {
			return 'admin';
		}
		if ( is_front_page() ) {
			return 'front_page';
		}
		if ( is_home() ) {
			return 'blog_home';
		}
		if ( is_singular() ) {
			return 'singular';
		}
		if ( is_author() ) {
			return 'author';
		}
		if ( is_archive() ) {
			return 'archive';
		}
		if ( is_search() ) {
			return 'search';
		}
		if ( is_404() ) {
			return '404';
		}
		return '';
	}

	/**
	 * Context of the current request. The parts that need the parsed request (page kind, post) are filled in only when
	 * the request is parsed (the `wp` action has run) or in wp-admin; before that `kind` is null and page conditions
	 * do not match. `$conditions` limits the work to what they ask for.
	 *
	 * @param array<int, array<string, mixed>> $conditions Conditions that are about to be evaluated.
	 * @return array<string, mixed>
	 */
	public static function context( array $conditions = array() ): array {
		$context = self::blank_context();
		$types   = array_column( $conditions, 'type' );
		if ( ! $conditions || array_intersect( $types, array( 'login', 'role' ) ) ) {
			$user                 = is_user_logged_in() ? wp_get_current_user() : null;
			$context['logged_in'] = (bool) $user && $user->exists();
			$context['roles']     = $user ? array_values( (array) $user->roles ) : array();
		}
		if ( ! $conditions || in_array( 'device', $types, true ) ) {
			$context['mobile'] = wp_is_mobile();
		}
		if ( ! $conditions || in_array( 'schedule', $types, true ) ) {
			$context['now'] = (string) current_time( 'mysql' );
		}
		if ( is_admin() || did_action( 'wp' ) ) {
			$context['kind'] = self::current_kind();
			if ( ! is_admin() ) {
				if ( is_singular() ) {
					$context['single_type'] = (string) get_post_type( (int) get_queried_object_id() );
				}
				if ( is_post_type_archive() ) {
					$type                    = get_query_var( 'post_type' );
					$context['archive_type'] = is_array( $type ) ? (string) reset( $type ) : (string) $type;
				}
				if ( is_singular() || ( is_home() && ! is_front_page() ) ) {
					$context['post_id'] = (int) get_queried_object_id();
				}
			}
		}
		return $context;
	}

	/**
	 * Whether a snippet's conditions hold right now.
	 *
	 * @param array<int, array<string, mixed>> $conditions Conditions of the snippet.
	 */
	public static function hold( array $conditions ): bool {
		return ! $conditions || self::matches( $conditions, self::context( $conditions ) );
	}

	/**
	 * One-line description for lists ("on the front page, logged in").
	 *
	 * @param array<int, array<string, mixed>> $conditions Conditions.
	 */
	public static function summary( array $conditions ): string {
		$kinds = array(
			'front_page' => __( 'front page', 'ux-studio' ),
			'blog_home'  => __( 'posts page', 'ux-studio' ),
			'singular'   => __( 'single post or page', 'ux-studio' ),
			'archive'    => __( 'archive', 'ux-studio' ),
			'author'     => __( 'author page', 'ux-studio' ),
			'search'     => __( 'search results', 'ux-studio' ),
			'404'        => __( '404 page', 'ux-studio' ),
			'admin'      => __( 'wp-admin', 'ux-studio' ),
		);
		$parts = array();
		foreach ( $conditions as $c ) {
			$not = ! empty( $c['negate'] ) ? __( 'not', 'ux-studio' ) . ' ' : '';
			switch ( $c['type'] ?? '' ) {
				case 'login':
					$parts[] = 'in' === $c['value'] ? __( 'logged in', 'ux-studio' ) : __( 'logged out', 'ux-studio' );
					break;
				case 'device':
					$parts[] = 'mobile' === $c['value'] ? __( 'mobile', 'ux-studio' ) : __( 'desktop', 'ux-studio' );
					break;
				case 'page':
					$parts[] = $not . implode( ' / ', array_map( static fn ( $k ): string => (string) ( $kinds[ $k ] ?? $k ), (array) $c['value'] ) );
					break;
				case 'role':
					$parts[] = $not . implode( ' / ', (array) $c['value'] );
					break;
				case 'post_type':
					$parts[] = $not . implode( ' / ', (array) $c['value'] );
					break;
				case 'post':
					$parts[] = $not . '#' . implode( ', #', (array) $c['value'] );
					break;
				case 'schedule':
					$parts[] = $not . trim( ( $c['from'] ?? '' ) . ' - ' . ( $c['to'] ?? '' ), ' -' );
					break;
			}
		}
		return implode( ', ', $parts );
	}
}
