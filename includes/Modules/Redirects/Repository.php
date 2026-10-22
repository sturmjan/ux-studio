<?php
/**
 * Redirect rule store. Every write path (admin UI, CSV/Redirection import,
 * slug monitor, AI abilities) goes through save(), which enforces the SEO
 * rules: no self-redirects, no loops, chains flattened to a single hop,
 * same-host targets stored relative and bound to the post when possible.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\Redirects;

defined( 'ABSPATH' ) || exit;

final class Repository {

	public const CODES = array( 301, 302, 307, 308, 410 );

	public const MATCH_TYPES = array( 'exact', 'regex' );

	public const ORIGINS = array( 'manual', 'ai', 'import', 'auto' );

	/** Max hops followed when flattening a chain. */
	private const MAX_HOPS = 10;

	/** Autoloaded snapshot read by the engine on every front-end request. */
	private const STATE_OPTION = 'uxstudio_redirects_state';

	/** Max items in one bulk call (REST / ability). */
	public const BULK_MAX = 500;

	/**
	 * Ids passed over by the last match() because their conditions did not hold.
	 *
	 * @var int[]
	 */
	private static array $skipped = array();

	/**
	 * Rules table name.
	 */
	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'uxstudio_redirects';
	}

	/**
	 * Table schema (dbDelta).
	 */
	public static function schema(): string {
		global $wpdb;
		$charset = $wpdb->get_charset_collate();
		$table   = self::table();
		return "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			source VARCHAR(2000) NOT NULL DEFAULT '',
			source_key VARCHAR(2000) NOT NULL DEFAULT '',
			source_hash CHAR(40) NOT NULL,
			conditions TEXT NULL,
			cond_key CHAR(40) NOT NULL DEFAULT '',
			match_type VARCHAR(10) NOT NULL DEFAULT 'exact',
			target VARCHAR(2000) NOT NULL DEFAULT '',
			target_post_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			target_hash CHAR(40) NOT NULL DEFAULT '',
			code SMALLINT UNSIGNED NOT NULL DEFAULT 301,
			enabled TINYINT(1) NOT NULL DEFAULT 1,
			hits BIGINT UNSIGNED NOT NULL DEFAULT 0,
			last_hit DATETIME NULL,
			origin VARCHAR(20) NOT NULL DEFAULT 'manual',
			note VARCHAR(255) NOT NULL DEFAULT '',
			position INT NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY source_cond (source_hash,cond_key),
			KEY source_hash (source_hash),
			KEY target_hash (target_hash),
			KEY target_post_id (target_post_id),
			KEY match_enabled (match_type, enabled)
		) {$charset};";
	}

	/*
	 * ------------------------------------------------------------------
	 * Matching (front end + test tool)
	 * ------------------------------------------------------------------
	 */

	/**
	 * Engine snapshot: count of enabled exact rules + the enabled regex rules
	 * (few, so kept in an autoloaded option instead of a query per request).
	 *
	 * @return array{exact:int, regex:array<int, array<string, mixed>>}
	 */
	public static function state(): array {
		$state = get_option( self::STATE_OPTION, null );
		if ( ! is_array( $state ) || ! isset( $state['exact'], $state['regex'] ) ) {
			$state = self::refresh_state();
		}
		return self::with_preview_rules( $state );
	}

	/**
	 * Ids of rules the migration created switched off that a safe-preview request treats as on.
	 *
	 * @return int[]
	 */
	private static function preview_ids(): array {
		return array_values( array_filter( array_map( 'intval', \UxStudio\Core\Migration\Preview::ids( 'redirect' ) ) ) );
	}

	/**
	 * In a safe-preview request the staged (still switched off) rules count as on.
	 *
	 * @param array{exact:int, regex:array<int, array<string, mixed>>} $state Engine snapshot.
	 * @return array{exact:int, regex:array<int, array<string, mixed>>}
	 */
	private static function with_preview_rules( array $state ): array {
		$ids = self::preview_ids();
		if ( ! $ids ) {
			return $state;
		}
		global $wpdb;
		$table = self::table();
		$in    = implode( ',', $ids );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own table, ids are integers.
		$state['exact'] += (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE enabled = 0 AND match_type = 'exact' AND id IN ({$in})" );
		$extra           = $wpdb->get_results( "SELECT * FROM {$table} WHERE enabled = 0 AND match_type = 'regex' AND id IN ({$in})", ARRAY_A );
		// phpcs:enable
		if ( is_array( $extra ) && $extra ) {
			$state['regex'] = array_merge( $state['regex'], $extra );
			usort( $state['regex'], static fn( array $a, array $b ): int => array( (int) $a['position'], (int) $a['id'] ) <=> array( (int) $b['position'], (int) $b['id'] ) );
		}
		return $state;
	}

	/**
	 * Rebuild the engine snapshot. Called after every write.
	 *
	 * @return array{exact:int, regex:array<int, array<string, mixed>>}
	 */
	public static function refresh_state(): array {
		global $wpdb;
		$table = self::table();
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own table, no user input.
		$exact = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE enabled = 1 AND match_type = 'exact'" );
		$regex = $wpdb->get_results( "SELECT * FROM {$table} WHERE enabled = 1 AND match_type = 'regex' ORDER BY position ASC, id ASC", ARRAY_A );
		// phpcs:enable
		$state = array(
			'exact' => $exact,
			'regex' => is_array( $regex ) ? $regex : array(),
		);
		update_option( self::STATE_OPTION, $state, true );
		return $state;
	}

	/**
	 * Drop the snapshot (module disabled).
	 */
	public static function clear_state(): void {
		delete_option( self::STATE_OPTION );
	}

	/**
	 * Find the rule for a normalised path + query. Exact rules win over
	 * regex; an exact rule WITH a query beats the same path without one.
	 *
	 * @param string $path  Normalised path.
	 * @param string $query Normalised query.
	 * @return array{rule:array<string, mixed>, target:string}|null Rule row + resolved absolute target ('' for 410).
	 */
	public static function match( string $path, string $query, ?array $context = null ): ?array {
		$state         = self::state();
		self::$skipped = array();

		if ( $state['exact'] > 0 ) {
			$rule = self::first_matching( self::find_exact_rules( $path, $query, true ), $context );
			if ( $rule ) {
				return array( 'rule' => $rule, 'target' => self::resolve_target( $rule ) );
			}
		}

		$subject = Url::key( $path, $query );
		foreach ( $state['regex'] as $rule ) {
			$matches = array();
			if ( 1 === @preg_match( self::delimit( (string) $rule['source_key'] ), $subject, $matches ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- pattern validated on save; a bad one must not break the site.
				if ( ! self::conditions_hold( $rule, $context ) ) {
					self::$skipped[] = (int) $rule['id'];
					continue;
				}
				return array( 'rule' => $rule, 'target' => self::resolve_target( $rule, $matches ) );
			}
		}
		return null;
	}

	/**
	 * Ids of the rules the last match() passed over because their conditions did not hold (the URL itself matched).
	 *
	 * @return int[]
	 */
	public static function last_skipped(): array {
		return self::$skipped;
	}

	/**
	 * First rule of an ordered list whose conditions hold. Conditions are only read for rules whose URL has
	 * already matched, and the request context is built only when such a rule is met.
	 *
	 * @param array<int, array<string, mixed>> $rules   Rules of one URL, in order.
	 * @param array<string, mixed>|null        $context Visitor (null = the current request).
	 * @return array<string, mixed>|null
	 */
	private static function first_matching( array $rules, ?array $context ): ?array {
		foreach ( $rules as $rule ) {
			if ( self::conditions_hold( $rule, $context ) ) {
				return $rule;
			}
			self::$skipped[] = (int) $rule['id'];
		}
		return null;
	}

	/**
	 * Whether the visitor meets the rule's conditions (no conditions = always).
	 *
	 * @param array<string, mixed>      $rule    Rule row.
	 * @param array<string, mixed>|null $context Visitor (null = the current request).
	 */
	private static function conditions_hold( array $rule, ?array $context ): bool {
		$stored = $rule['conditions'] ?? '';
		if ( ! is_string( $stored ) || '' === $stored ) {
			return true;
		}
		$conditions = Conditions::decode( $stored );
		return ! $conditions || Conditions::matches( $conditions, $context ?? Conditions::request_context() );
	}

	/**
	 * First exact rule for a path + query, ignoring conditions (existence checks, chain follow-up).
	 *
	 * @param string $path         Normalised path.
	 * @param string $query        Normalised query.
	 * @param bool   $enabled_only Ignore disabled rules.
	 * @return array<string, mixed>|null
	 */
	public static function find_exact( string $path, string $query, bool $enabled_only ): ?array {
		$rules = self::find_exact_rules( $path, $query, $enabled_only );
		return $rules ? $rules[0] : null;
	}

	/**
	 * Every exact rule for a path + query in the order the engine tries them: a rule on the path WITH the query
	 * beats one on the bare path, then lower position first, then older first. One indexed query.
	 *
	 * @param string $path         Normalised path.
	 * @param string $query        Normalised query.
	 * @param bool   $enabled_only Ignore disabled rules.
	 * @return array<int, array<string, mixed>>
	 */
	public static function find_exact_rules( string $path, string $query, bool $enabled_only ): array {
		global $wpdb;
		$hashes = array( Url::hash( 'exact', $path ) );
		if ( '' !== $query ) {
			$hashes[] = Url::hash( 'exact', Url::key( $path, $query ) );
		}
		$table = self::table();
		$in    = implode( ',', array_fill( 0, count( $hashes ), '%s' ) );
		$where = ' AND enabled = 1';
		if ( ! $enabled_only ) {
			$where = '';
		} elseif ( self::preview_ids() ) {
			$where = ' AND (enabled = 1 OR id IN (' . implode( ',', self::preview_ids() ) . '))';
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE source_hash IN ({$in}){$where} ORDER BY CHAR_LENGTH(source_key) DESC, (cond_key = '') ASC, position ASC, id ASC LIMIT 50", ...$hashes ), ARRAY_A );
		return is_array( $rows ) ? array_values( $rows ) : array();
	}

	/**
	 * Absolute URL a rule sends the visitor to ('' for 410). Post-bound
	 * rules follow the post's current permalink, so a later slug change
	 * never creates a chain - but only while that permalink is a real one.
	 * Engine may run before a custom post type is registered; then the
	 * permalink would be the plain "/?p=ID" and the visitor would be sent
	 * through a second 301, so the stored (pretty) target is used instead.
	 *
	 * @param array<string, mixed> $rule    Rule row.
	 * @param array<int, string>   $matches Regex captures for $1..$9.
	 */
	public static function resolve_target( array $rule, array $matches = array() ): string {
		if ( 410 === (int) $rule['code'] ) {
			return '';
		}
		$post_id = (int) $rule['target_post_id'];
		$target  = (string) $rule['target'];
		if ( $post_id > 0 && 'publish' === get_post_status( $post_id ) ) {
			$permalink = self::pretty_permalink( $post_id );
			if ( '' !== $permalink ) {
				// Rules saved before anchors were kept out of post binding
				// still carry their "#fragment" in the stored target.
				$fragment = (string) wp_parse_url( $target, PHP_URL_FRAGMENT );
				return '' !== $fragment && ! str_contains( $permalink, '#' ) ? $permalink . '#' . $fragment : $permalink;
			}
		}

		if ( $matches && str_contains( $target, '$' ) ) {
			$target = (string) preg_replace_callback(
				'~\$(\d)~',
				static fn ( array $m ): string => (string) ( $matches[ (int) $m[1] ] ?? '' ),
				$target
			);
			// A capture must never turn a relative target into "//evil.tld".
			if ( str_starts_with( (string) $rule['target'], '/' ) ) {
				$target = '/' . ltrim( $target, '/' );
			}
		}
		return Url::absolute( $target );
	}

	/**
	 * Permalink of a post, or '' when it is not final yet: the post type is
	 * not registered (yet) or WordPress falls back to the plain "?p=ID" form.
	 *
	 * @param int $post_id Post id.
	 */
	private static function pretty_permalink( int $post_id ): string {
		$post = get_post( $post_id );
		if ( ! $post || ! post_type_exists( $post->post_type ) ) {
			return '';
		}
		$permalink = get_permalink( $post );
		if ( ! is_string( $permalink ) || '' === $permalink || str_contains( $permalink, '?p=' ) || str_contains( $permalink, '&p=' ) ) {
			return '';
		}
		return $permalink;
	}

	/**
	 * Wrap a stored regex in delimiters with case-insensitive UTF-8 flags.
	 *
	 * @param string $pattern Pattern as entered (no delimiters).
	 */
	public static function delimit( string $pattern ): string {
		return '@' . str_replace( '@', '\@', $pattern ) . '@iu';
	}

	/**
	 * Count a hit (cheap single UPDATE).
	 *
	 * @param int $id Rule id.
	 */
	public static function record_hit( int $id ): void {
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET hits = hits + 1, last_hit = %s WHERE id = %d", current_time( 'mysql' ), $id ) );
	}

	/*
	 * ------------------------------------------------------------------
	 * Reads
	 * ------------------------------------------------------------------
	 */

	/**
	 * One rule row.
	 *
	 * @param int $id Rule id.
	 * @return array<string, mixed>|null
	 */
	public static function get( int $id ): ?array {
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A );
		return is_array( $row ) ? $row : null;
	}

	/**
	 * Paged, filtered list of rules (formatted).
	 *
	 * @param array<string, mixed> $args search, code, origin, match_type, enabled, orderby, order, limit, offset.
	 * @return array{items:array<int, array<string, mixed>>, total:int}
	 */
	public static function list( array $args ): array {
		global $wpdb;
		$table  = self::table();
		$where  = array( '1=1' );
		$params = array();

		$search = trim( (string) ( $args['search'] ?? '' ) );
		if ( '' !== $search ) {
			$like     = '%' . $wpdb->esc_like( $search ) . '%';
			$where[]  = '(source LIKE %s OR target LIKE %s OR note LIKE %s)';
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
		}
		if ( ! empty( $args['code'] ) && in_array( (int) $args['code'], self::CODES, true ) ) {
			$where[]  = 'code = %d';
			$params[] = (int) $args['code'];
		}
		if ( ! empty( $args['origin'] ) && in_array( $args['origin'], self::ORIGINS, true ) ) {
			$where[]  = 'origin = %s';
			$params[] = (string) $args['origin'];
		}
		if ( ! empty( $args['match_type'] ) && in_array( $args['match_type'], self::MATCH_TYPES, true ) ) {
			$where[]  = 'match_type = %s';
			$params[] = (string) $args['match_type'];
		}
		if ( isset( $args['enabled'] ) && '' !== $args['enabled'] && null !== $args['enabled'] ) {
			$where[]  = 'enabled = %d';
			$params[] = rest_sanitize_boolean( $args['enabled'] ) ? 1 : 0;
		}
		if ( isset( $args['conditional'] ) && '' !== $args['conditional'] && null !== $args['conditional'] ) {
			$where[] = rest_sanitize_boolean( $args['conditional'] ) ? "(conditions IS NOT NULL AND conditions <> '')" : "(conditions IS NULL OR conditions = '')";
		}

		$orderby = in_array( $args['orderby'] ?? '', array( 'id', 'source', 'hits', 'last_hit', 'created_at', 'updated_at', 'code' ), true ) ? $args['orderby'] : 'id';
		$order   = 'asc' === strtolower( (string) ( $args['order'] ?? '' ) ) ? 'ASC' : 'DESC';
		$limit   = max( 1, min( self::BULK_MAX, (int) ( $args['limit'] ?? 50 ) ) );
		$offset  = max( 0, (int) ( $args['offset'] ?? 0 ) );
		$sql_w   = implode( ' AND ', $where );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		$count_sql = "SELECT COUNT(*) FROM {$table} WHERE {$sql_w}";
		$total     = (int) $wpdb->get_var( $params ? $wpdb->prepare( $count_sql, ...$params ) : $count_sql );
		$rows      = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE {$sql_w} ORDER BY {$orderby} {$order}, id DESC LIMIT %d OFFSET %d", ...array_merge( $params, array( $limit, $offset ) ) ),
			ARRAY_A
		);
		// phpcs:enable

		return array(
			'items' => array_map( array( self::class, 'format' ), is_array( $rows ) ? $rows : array() ),
			'total' => $total,
		);
	}

	/**
	 * Public shape of a rule (REST, abilities, export).
	 *
	 * @param array<string, mixed> $row Rule row.
	 * @return array<string, mixed>
	 */
	public static function format( array $row ): array {
		$post_id = (int) $row['target_post_id'];
		return array(
			'id'                => (int) $row['id'],
			'source'            => (string) $row['source'],
			'match_type'        => (string) $row['match_type'],
			'target'            => (string) $row['target'],
			'target_url'        => 'regex' === $row['match_type'] ? Url::absolute( (string) $row['target'] ) : self::resolve_target( $row ),
			'target_post_id'    => $post_id,
			'target_post_title' => $post_id > 0 ? get_the_title( $post_id ) : '',
			'code'              => (int) $row['code'],
			'conditions'        => Conditions::decode( $row['conditions'] ?? '' ),
			'position'          => (int) ( $row['position'] ?? 0 ),
			'enabled'           => (bool) (int) $row['enabled'],
			'hits'              => (int) $row['hits'],
			'last_hit'          => $row['last_hit'],
			'origin'            => (string) $row['origin'],
			'note'              => (string) $row['note'],
			'created_at'        => (string) $row['created_at'],
			'updated_at'        => (string) $row['updated_at'],
		);
	}

	/*
	 * ------------------------------------------------------------------
	 * Writes
	 * ------------------------------------------------------------------
	 */

	/**
	 * Validate + create or update one rule.
	 *
	 * Input: source, match_type (exact|regex), target OR target_post_id,
	 * code (301|302|307|308|410), enabled, note, origin, id (update by id).
	 *
	 * @param array<string, mixed> $input       Rule input.
	 * @param string               $on_conflict update|skip|error when the source already has a rule.
	 * @param bool                 $dry_run     Validate only.
	 * @return array{status:string, id:int|null, source:string, target_url:string, errors:array<int, array{code:string,message:string}>, warnings:array<int, array{code:string,message:string}>}
	 */
	public static function save( array $input, string $on_conflict = 'update', bool $dry_run = false ): array {
		$errors   = array();
		$warnings = array();
		$id       = (int) ( $input['id'] ?? 0 );
		$existing = $id > 0 ? self::get( $id ) : null;

		if ( $id > 0 && ! $existing ) {
			return self::result( 'error', null, '', '', array( self::issue( 'not_found', __( 'Rule not found.', 'ux-studio' ) ) ), array() );
		}

		// Partial update by id: missing fields keep their current values.
		if ( $existing ) {
			$input += array(
				'source'         => $existing['source'],
				'match_type'     => $existing['match_type'],
				'code'           => $existing['code'],
				'enabled'        => $existing['enabled'],
				'note'           => $existing['note'],
				'origin'         => $existing['origin'],
				'conditions'     => Conditions::decode( $existing['conditions'] ?? '' ),
			);
			if ( ! array_key_exists( 'target', $input ) && ! array_key_exists( 'target_post_id', $input ) ) {
				$input['target']         = $existing['target'];
				$input['target_post_id'] = $existing['target_post_id'];
			}
		}

		$match_type = (string) ( $input['match_type'] ?? 'exact' );
		$code       = (int) ( $input['code'] ?? 301 );
		$source     = trim( (string) ( $input['source'] ?? '' ) );
		$origin     = in_array( $input['origin'] ?? '', self::ORIGINS, true ) ? (string) $input['origin'] : 'manual';

		if ( ! in_array( $match_type, self::MATCH_TYPES, true ) ) {
			$errors[] = self::issue( 'invalid_match_type', __( 'match_type must be "exact" or "regex".', 'ux-studio' ) );
		}
		if ( ! in_array( $code, self::CODES, true ) ) {
			$errors[] = self::issue( 'invalid_code', __( 'code must be one of 301, 302, 307, 308, 410.', 'ux-studio' ) );
		}

		// --- Conditions -------------------------------------------------
		$normalized = Conditions::normalize( $input['conditions'] ?? array() );
		$conditions = $normalized['conditions'];
		$cond_key   = Conditions::key( $conditions );
		$errors     = array_merge( $errors, $normalized['errors'] );

		// --- Source -----------------------------------------------------
		$source_key  = '';
		$source_path = '';
		if ( '' === $source ) {
			$errors[] = self::issue( 'source_required', __( 'Source is required.', 'ux-studio' ) );
		} elseif ( 'regex' === $match_type ) {
			if ( false === @preg_match( self::delimit( $source ), '' ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- validating user regex.
				$errors[] = self::issue( 'invalid_regex', __( 'Source is not a valid regular expression.', 'ux-studio' ) );
			}
			$source_key = $source;
		} else {
			$parsed      = Url::parse_source( $source );
			$source_path = $parsed['path'];
			$source_key  = Url::key( $parsed['path'], $parsed['query'] );
			if ( '' === $source_path ) {
				$errors[] = self::issue( 'invalid_source', __( 'Source is not a valid URL or path.', 'ux-studio' ) );
			} elseif ( '/' === $source_key ) {
				$errors[] = self::issue( 'source_is_home', __( 'The homepage itself cannot be redirected.', 'ux-studio' ) );
			} elseif ( Url::is_reserved( $source_path ) ) {
				$errors[] = self::issue( 'source_reserved', __( 'WordPress system paths (wp-admin, wp-login.php, wp-json…) cannot be redirected.', 'ux-studio' ) );
			}
		}
		$source_hash = Url::hash( $match_type, $source_key );

		// --- Target -----------------------------------------------------
		$target  = trim( (string) ( $input['target'] ?? '' ) );
		$post_id = (int) ( $input['target_post_id'] ?? 0 );

		if ( 410 === $code ) {
			$target  = '';
			$post_id = 0;
		} elseif ( $post_id > 0 ) {
			$post = get_post( $post_id );
			if ( ! $post || 'publish' !== $post->post_status || ! is_post_type_viewable( $post->post_type ) ) {
				$errors[] = self::issue( 'target_post_invalid', __( 'target_post_id must be a published, publicly viewable post.', 'ux-studio' ) );
			} else {
				$target = Url::to_relative( (string) get_permalink( $post ) );
			}
		} elseif ( '' === $target ) {
			$errors[] = self::issue( 'target_required', __( 'Target (URL or target_post_id) is required unless code is 410.', 'ux-studio' ) );
		} elseif ( str_starts_with( $target, '//' ) ) {
			$errors[] = self::issue( 'invalid_target', __( 'Protocol-relative targets (//host) are not allowed, use https://.', 'ux-studio' ) );
		} elseif ( ! str_starts_with( $target, '/' ) ) {
			$scheme = strtolower( (string) wp_parse_url( $target, PHP_URL_SCHEME ) );
			if ( ! in_array( $scheme, array( 'http', 'https' ), true ) || ! wp_parse_url( $target, PHP_URL_HOST ) ) {
				$errors[] = self::issue( 'invalid_target', __( 'Target must be a site path starting with "/" or an http(s) URL.', 'ux-studio' ) );
			} elseif ( Url::is_internal( $target ) ) {
				$target = Url::to_relative( $target );
			}
		}

		// Same-host target pointing at a post: bind to the post so the rule
		// follows future slug changes (one hop forever). A target with an
		// anchor or query ("/#kontakt", "/shop/?x=1") stays a plain text
		// target - the post permalink would drop that part.
		$is_template = 'regex' === $match_type && str_contains( $target, '$' );
		if ( ! $errors && 0 === $post_id && '' !== $target && ! str_contains( $target, '#' ) && ! str_contains( $target, '?' ) && Url::is_internal( $target ) && ! $is_template ) {
			$found = url_to_postid( Url::absolute( $target ) );
			if ( $found > 0 && 'publish' === get_post_status( $found ) && Url::target_key( (string) get_permalink( $found ) ) === Url::target_key( $target ) ) {
				$post_id = $found;
			}
		}

		// --- Chains & loops (exact internal targets) -------------------------
		if ( ! $errors && '' !== $target && ! $is_template && Url::is_internal( $target ) ) {
			$visited = array( $source_key => true );
			for ( $hop = 0; $hop < self::MAX_HOPS; $hop++ ) {
				$tkey = Url::target_key( $target );
				// The rule would match its own target: a query-less source
				// matches any query, a regex may match the target path.
				$self_match = 'regex' === $match_type
					? 1 === @preg_match( self::delimit( $source_key ), $tkey ) // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
					: ( ! str_contains( $source_key, '?' ) && explode( '?', $tkey, 2 )[0] === $source_key );
				if ( isset( $visited[ $tkey ] ) || $self_match ) {
					$errors[] = self::issue( 'loop', __( 'This redirect would create a loop (the target leads back to the source).', 'ux-studio' ) );
					break;
				}
				$visited[ $tkey ] = true;
				$parts            = explode( '?', $tkey, 2 );
				$nexts            = array_values(
					array_filter(
						self::find_exact_rules( $parts[0], $parts[1] ?? '', true ),
						static fn ( array $r ): bool => ! $existing || (int) $r['id'] !== (int) $existing['id']
					)
				);
				if ( ! $nexts ) {
					break;
				}
				// The target has several rules or one with conditions: which one applies depends on the visitor,
				// so the chain cannot be flattened. A way back to this source is only a warning here (a pair like
				// /members -> /login for guests and /login -> /members for members is legitimate).
				if ( count( $nexts ) > 1 || '' !== (string) ( $nexts[0]['cond_key'] ?? '' ) ) {
					foreach ( $nexts as $candidate ) {
						$back = self::resolve_target( $candidate );
						if ( '' !== $back && Url::target_key( $back ) === $source_key ) {
							$warnings[] = self::issue( 'conditional_loop', __( 'The target has conditional rules and one of them leads back to this source. Make sure their conditions exclude each other, otherwise visitors loop.', 'ux-studio' ) );
							break;
						}
					}
					$warnings[] = self::issue( 'target_conditional', __( 'The target itself has conditional redirects, so the chain is not flattened.', 'ux-studio' ) );
					break;
				}
				$next = $nexts[0];
				if ( 410 === (int) $next['code'] ) {
					$warnings[] = self::issue( 'target_is_gone', __( 'The target itself is marked 410 Gone - pick a live page or use 410 for this source too.', 'ux-studio' ) );
					break;
				}
				$from     = $target;
				$resolved = self::resolve_target( $next );
				$target   = Url::is_internal( $resolved ) ? Url::to_relative( $resolved ) : $resolved;
				$post_id  = (int) $next['target_post_id'];
				/* translators: 1: original target, 2: final target */
				$warnings[] = self::issue( 'chain_flattened', sprintf( __( 'Target %1$s is itself redirected - pointed straight to %2$s to avoid a redirect chain.', 'ux-studio' ), $from, $target ) );
				if ( ! Url::is_internal( $target ) ) {
					break;
				}
			}
		}

		// --- Warnings (SEO hints, do not block) --------------------------
		if ( ! $errors ) {
			$tkey = Url::target_key( $target );
			if ( '/' === $tkey && in_array( $code, array( 301, 308 ), true ) ) {
				$warnings[] = self::issue( 'homepage_target', __( 'Redirecting to the homepage is treated as a soft 404 by Google. Prefer the closest matching page, or 410 when the content is gone.', 'ux-studio' ) );
			}
			if ( in_array( $code, array( 302, 307 ), true ) ) {
				$warnings[] = self::issue( 'temporary_code', __( 'Temporary redirect: search engines keep the old URL indexed and do not pass its ranking. Use 301/308 for permanent moves.', 'ux-studio' ) );
			}
			if ( 0 === $post_id && '' !== $tkey && '/' !== $tkey && ! $is_template ) {
				$warnings[] = self::issue( 'target_unverified', __( 'Target is not a known post or page - verify it answers 200 (test-url with live=true).', 'ux-studio' ) );
			}
			if ( 'exact' === $match_type && '' !== $source_path && url_to_postid( home_url( $source_path ) ) > 0 ) {
				$warnings[] = self::issue( 'source_has_content', __( 'The source URL currently shows live content - the redirect will hide it.', 'ux-studio' ) );
			}
		}

		// --- Duplicate source -------------------------------------------
		if ( ! $errors ) {
			$dupe = self::find_by_hash( $source_hash, $cond_key );
			if ( $dupe && ( ! $existing || (int) $dupe['id'] !== (int) $existing['id'] ) ) {
				if ( $existing || 'error' === $on_conflict ) {
					/* translators: %d: rule id */
					$errors[] = self::issue( 'duplicate', sprintf( __( 'Another rule (#%d) already handles this source with the same conditions.', 'ux-studio' ), (int) $dupe['id'] ) );
				} elseif ( 'skip' === $on_conflict ) {
					return self::result( 'skipped', (int) $dupe['id'], $source, self::resolve_target( $dupe ), array(), array( self::issue( 'duplicate', __( 'Source already has a rule with the same conditions - skipped.', 'ux-studio' ) ) ) );
				} else {
					$existing = $dupe;
				}
			}
		}

		$target_url = 410 === $code ? '' : ( $post_id > 0 ? (string) get_permalink( $post_id ) : Url::absolute( $target ) );

		if ( $errors ) {
			return self::result( 'error', $existing ? (int) $existing['id'] : null, $source, $target_url, $errors, $warnings );
		}

		$tkey = Url::target_key( $target );
		$data = array(
			'source'         => $source,
			'source_key'     => $source_key,
			'source_hash'    => $source_hash,
			'conditions'     => Conditions::encode( $conditions ),
			'cond_key'       => $cond_key,
			'match_type'     => $match_type,
			'target'         => $target,
			'target_post_id' => $post_id,
			'target_hash'    => '' !== $tkey && ! $is_template ? Url::hash( 'target', $tkey ) : '',
			'code'           => $code,
			'enabled'        => array_key_exists( 'enabled', $input ) ? ( rest_sanitize_boolean( $input['enabled'] ) ? 1 : 0 ) : 1,
			'origin'         => $existing ? (string) $existing['origin'] : $origin,
			'note'           => mb_substr( sanitize_text_field( (string) ( $input['note'] ?? '' ) ), 0, 255 ),
			'position'       => (int) ( $input['position'] ?? ( $existing['position'] ?? 0 ) ),
		);

		if ( $existing && self::unchanged( $existing, $data ) ) {
			return self::result( 'unchanged', (int) $existing['id'], $source, $target_url, array(), $warnings );
		}
		if ( $dry_run ) {
			return self::result( $existing ? 'would_update' : 'would_create', $existing ? (int) $existing['id'] : null, $source, $target_url, array(), $warnings );
		}

		global $wpdb;
		$now                = current_time( 'mysql' );
		$data['updated_at'] = $now;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery
		if ( $existing ) {
			$wpdb->update( self::table(), $data, array( 'id' => (int) $existing['id'] ) );
			$rule_id = (int) $existing['id'];
			$status  = 'updated';
		} else {
			$data['created_at'] = $now;
			$wpdb->insert( self::table(), $data );
			$rule_id = (int) $wpdb->insert_id;
			$status  = 'created';
		}
		// phpcs:enable

		// Only a lone, unconditional rule is "the" way out of its source, so only then can older rules that point
		// at this source skip it.
		if ( 'exact' === $match_type && '' === $cond_key && 1 === self::count_by_hash( $source_hash ) ) {
			$repointed = self::repoint_upstream( $rule_id, $source_key, $data );
			if ( $repointed > 0 ) {
				/* translators: %d: number of rules */
				$warnings[] = self::issue( 'upstream_repointed', sprintf( _n( '%d older rule pointed at this source - repointed to the new target (no chain).', '%d older rules pointed at this source - repointed to the new target (no chain).', $repointed, 'ux-studio' ), $repointed ) );
			}
		}

		self::refresh_state();
		return self::result( $status, $rule_id, $source, $target_url, array(), $warnings );
	}

	/**
	 * Save many rules. Errors on one item never stop the others.
	 *
	 * @param array<int, array<string, mixed>> $items       Rule inputs.
	 * @param string                           $on_conflict update|skip|error.
	 * @param bool                             $dry_run     Validate only.
	 * @param string                           $origin      Default origin for new rules.
	 * @return array{summary:array<string, int>, results:array<int, array<string, mixed>>}
	 */
	public static function bulk( array $items, string $on_conflict, bool $dry_run, string $origin ): array {
		$results = array();
		$summary = array();
		foreach ( array_slice( $items, 0, self::BULK_MAX ) as $index => $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$item['origin'] = $item['origin'] ?? $origin;
			$result         = self::save( $item, $on_conflict, $dry_run );
			$result['index'] = $index;
			$results[]      = $result;
			$summary[ $result['status'] ] = ( $summary[ $result['status'] ] ?? 0 ) + 1;
		}
		return array(
			'summary' => $summary,
			'results' => $results,
		);
	}

	/**
	 * Delete rules by id.
	 *
	 * @param array<int, int> $ids Rule ids.
	 */
	public static function delete( array $ids ): int {
		global $wpdb;
		$ids = array_values( array_filter( array_map( 'intval', $ids ) ) );
		if ( ! $ids ) {
			return 0;
		}
		$table = self::table();
		$in    = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		$deleted = (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE id IN ({$in})", ...$ids ) );
		self::refresh_state();
		return $deleted;
	}

	/**
	 * Delete exact rules whose source is a given path (content now lives there).
	 *
	 * @param string $path Normalised path.
	 */
	public static function delete_by_source_path( string $path ): int {
		$ids = array();
		foreach ( self::find_exact_rules( $path, '', false ) as $rule ) {
			if ( Url::key( $path ) === $rule['source_key'] ) {
				$ids[] = (int) $rule['id'];
			}
		}
		return $ids ? self::delete( $ids ) : 0;
	}

	/**
	 * Rule by source hash + condition set.
	 *
	 * @param string $hash     Source hash.
	 * @param string $cond_key Condition key ('' = no conditions).
	 * @return array<string, mixed>|null
	 */
	private static function find_by_hash( string $hash, string $cond_key = '' ): ?array {
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE source_hash = %s AND cond_key = %s", $hash, $cond_key ), ARRAY_A );
		return is_array( $row ) ? $row : null;
	}

	/**
	 * Number of rules (any state) that share a source.
	 *
	 * @param string $hash Source hash.
	 */
	private static function count_by_hash( string $hash ): int {
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE source_hash = %s", $hash ) );
	}

	/**
	 * Rules that pointed at $source_key now point at this rule's target, so
	 * A->B followed by B->C ends as A->C + B->C (never a 2-hop chain). A 410
	 * source is left alone - the caller gets a warning from audit().
	 *
	 * @param int                  $rule_id    Rule just saved.
	 * @param string               $source_key Its source key.
	 * @param array<string, mixed> $data       Its stored data.
	 * @return int Rules repointed.
	 */
	private static function repoint_upstream( int $rule_id, string $source_key, array $data ): int {
		if ( 410 === (int) $data['code'] ) {
			return 0;
		}
		global $wpdb;
		$table = self::table();
		// Never repoint the rule whose source IS the new target (would loop).
		$target_key = Url::target_key( (string) $data['target'] );
		$self_hash  = '' !== $target_key ? Url::hash( 'exact', $target_key ) : '';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET target = %s, target_post_id = %d, target_hash = %s, updated_at = %s WHERE target_hash = %s AND id <> %d AND source_hash <> %s",
				$data['target'],
				$data['target_post_id'],
				$data['target_hash'],
				current_time( 'mysql' ),
				Url::hash( 'target', $source_key ),
				$rule_id,
				$self_hash
			)
		);
	}

	/**
	 * Whether stored row already equals the new data.
	 *
	 * @param array<string, mixed> $row  Stored row.
	 * @param array<string, mixed> $data New data.
	 */
	private static function unchanged( array $row, array $data ): bool {
		foreach ( $data as $key => $value ) {
			if ( (string) ( $row[ $key ] ?? '' ) !== (string) $value ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Issue entry.
	 *
	 * @param string $code    Machine code.
	 * @param string $message Human message.
	 * @return array{code:string, message:string}
	 */
	private static function issue( string $code, string $message ): array {
		return array(
			'code'    => $code,
			'message' => $message,
		);
	}

	/**
	 * Save result envelope.
	 *
	 * @param string                                          $status     created|updated|unchanged|skipped|would_create|would_update|error.
	 * @param int|null                                        $id         Rule id.
	 * @param string                                          $source     Source as given.
	 * @param string                                          $target_url Absolute target.
	 * @param array<int, array{code:string, message:string}> $errors     Errors.
	 * @param array<int, array{code:string, message:string}> $warnings   Warnings.
	 * @return array{status:string, id:int|null, source:string, target_url:string, errors:array<int, array{code:string,message:string}>, warnings:array<int, array{code:string,message:string}>}
	 */
	private static function result( string $status, ?int $id, string $source, string $target_url, array $errors, array $warnings ): array {
		return array(
			'status'     => $status,
			'id'         => $id,
			'source'     => $source,
			'target_url' => $target_url,
			'errors'     => $errors,
			'warnings'   => $warnings,
		);
	}
}
