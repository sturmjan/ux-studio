<?php
/**
 * Aggregated 404 log - one row per URL with a hit counter, so the list shows
 * what actually loses traffic (and what an AI agent should map first)
 * instead of a raw request stream. No IP addresses are stored.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\Redirects;

defined( 'ABSPATH' ) || exit;

final class NotFoundLog {

	/** Hard cap on rows; the least-hit, oldest rows are dropped above it. */
	private const MAX_ROWS = 20000;

	/**
	 * Table name.
	 */
	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'uxstudio_redirects_404';
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
			url_hash CHAR(40) NOT NULL,
			url VARCHAR(2000) NOT NULL DEFAULT '',
			hits BIGINT UNSIGNED NOT NULL DEFAULT 1,
			first_seen DATETIME NOT NULL,
			last_seen DATETIME NOT NULL,
			referrer VARCHAR(500) NOT NULL DEFAULT '',
			user_agent VARCHAR(255) NOT NULL DEFAULT '',
			PRIMARY KEY  (id),
			UNIQUE KEY url_hash (url_hash),
			KEY last_seen (last_seen),
			KEY hits (hits)
		) {$charset};";
	}

	/**
	 * Hook: record the current 404 (template_redirect, front end only).
	 */
	public static function record(): void {
		if ( ! is_404() || Engine::handled() || ! Engine::is_front_get() ) {
			return;
		}
		$request = Url::request();
		$url     = Url::key( $request['path'], $request['query'] );
		if ( Url::is_reserved( $request['path'] ) ) {
			return;
		}

		global $wpdb;
		$table = self::table();
		$now   = current_time( 'mysql' );
		// phpcs:disable WordPress.Security.ValidatedSanitizedInput
		$referrer = mb_substr( esc_url_raw( (string) wp_unslash( $_SERVER['HTTP_REFERER'] ?? '' ) ), 0, 500 );
		$agent    = mb_substr( sanitize_text_field( (string) wp_unslash( $_SERVER['HTTP_USER_AGENT'] ?? '' ) ), 0, 255 );
		// phpcs:enable
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$table} (url_hash, url, hits, first_seen, last_seen, referrer, user_agent) VALUES (%s, %s, 1, %s, %s, %s, %s)
				ON DUPLICATE KEY UPDATE hits = hits + 1, last_seen = VALUES(last_seen), referrer = IF(VALUES(referrer) = '', referrer, VALUES(referrer)), user_agent = VALUES(user_agent)",
				sha1( $url ),
				mb_substr( $url, 0, 2000 ),
				$now,
				$now,
				$referrer,
				$agent
			)
		);
	}

	/**
	 * Paged list. Each row says whether a rule already covers it.
	 *
	 * @param array<string, mixed> $args search, min_hits, unresolved_only, orderby (hits|last_seen), limit, offset.
	 * @return array{items:array<int, array<string, mixed>>, total:int}
	 */
	public static function list( array $args ): array {
		global $wpdb;
		$table  = self::table();
		$where  = array( 'hits >= %d' );
		$params = array( max( 1, (int) ( $args['min_hits'] ?? 1 ) ) );
		$search = trim( (string) ( $args['search'] ?? '' ) );
		if ( '' !== $search ) {
			$where[]  = 'url LIKE %s';
			$params[] = '%' . $wpdb->esc_like( mb_strtolower( $search, 'UTF-8' ) ) . '%';
		}
		$orderby = 'last_seen' === ( $args['orderby'] ?? '' ) ? 'last_seen' : 'hits';
		$limit   = max( 1, min( 500, (int) ( $args['limit'] ?? 50 ) ) );
		$offset  = max( 0, (int) ( $args['offset'] ?? 0 ) );
		$sql_w   = implode( ' AND ', $where );
		$unres   = rest_sanitize_boolean( $args['unresolved_only'] ?? false );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE {$sql_w}", ...$params ) );
		// With unresolved_only the page is filtered in PHP, so read ahead.
		$fetch = $unres ? min( 2000, $limit * 5 ) : $limit;
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE {$sql_w} ORDER BY {$orderby} DESC, id DESC LIMIT %d OFFSET %d", ...array_merge( $params, array( $fetch, $offset ) ) ), ARRAY_A );
		// phpcs:enable

		$items = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$parts = explode( '?', (string) $row['url'], 2 );
			$match = Repository::match( $parts[0], $parts[1] ?? '', Conditions::blank_context() );
			if ( $unres && $match ) {
				continue;
			}
			$items[] = array(
				'id'          => (int) $row['id'],
				'url'         => (string) $row['url'],
				'full_url'    => home_url( (string) $row['url'] ),
				'hits'        => (int) $row['hits'],
				'first_seen'  => (string) $row['first_seen'],
				'last_seen'   => (string) $row['last_seen'],
				'referrer'    => (string) $row['referrer'],
				'user_agent'  => (string) $row['user_agent'],
				'resolved_by' => $match ? (int) $match['rule']['id'] : null,
			);
			if ( count( $items ) >= $limit ) {
				break;
			}
		}
		return array(
			'items' => $items,
			'total' => $total,
		);
	}

	/**
	 * Delete rows by id, or everything when $ids is empty and $all is true.
	 *
	 * @param array<int, int> $ids Row ids.
	 * @param bool            $all Clear the whole log.
	 */
	public static function delete( array $ids, bool $all = false ): int {
		global $wpdb;
		$table = self::table();
		if ( $all ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			return (int) $wpdb->query( "DELETE FROM {$table}" );
		}
		$ids = array_values( array_filter( array_map( 'intval', $ids ) ) );
		if ( ! $ids ) {
			return 0;
		}
		$in = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		return (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE id IN ({$in})", ...$ids ) );
	}

	/**
	 * Daily cleanup: retention by last_seen + hard row cap.
	 *
	 * @param int $days Retention in days (0 = keep).
	 */
	public static function cleanup( int $days ): void {
		global $wpdb;
		$table = self::table();
		if ( $days > 0 ) {
			\UxStudio\Core\Retention::purge( 'uxstudio_redirects_404', $days, 'last_seen' );
		}
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
		if ( $count > self::MAX_ROWS ) {
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} ORDER BY hits ASC, last_seen ASC LIMIT %d", $count - self::MAX_ROWS ) );
		}
		// phpcs:enable
	}
}
