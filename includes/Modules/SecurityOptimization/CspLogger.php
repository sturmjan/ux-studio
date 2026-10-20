<?php
/**
 * CSP violation storage: dedupe + CRUD against uxstudio_csp_violations.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\SecurityOptimization;

defined( 'ABSPATH' ) || exit;

/**
 * Ported from the legacy security-optimization module's CspViolationsLogger.
 * Violations are aggregated by fingerprint (base directive + blocked host,
 * or + source location for inline/eval) so a repeated browser report only
 * bumps hit_count/last_seen instead of growing the table unbounded.
 *
 * Privacy: URLs are stored without query string and fragment (they can
 * carry tokens or e-mail addresses), the client IP is not stored at all.
 * Times are UTC.
 */
final class CspLogger {

	private const MAX_BODY_BYTES = 16384;
	private const MAX_PAGES      = 10;

	/** Retention: resolved rows after 90 days, anything after 180 days. */
	private const KEEP_RESOLVED_DAYS = 90;
	private const KEEP_ANY_DAYS      = 180;

	/**
	 * @return string Fully qualified table name.
	 */
	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'uxstudio_csp_violations';
	}

	/**
	 * Max accepted raw report body size, in bytes.
	 */
	public static function max_body_bytes(): int {
		return self::MAX_BODY_BYTES;
	}

	/**
	 * Persist a single normalized CSP report (insert or bump hit_count).
	 *
	 * @param array  $report     Normalized report ('effective-directive'/'violated-directive', 'blocked-uri', 'document-uri', 'source-file', 'line-number', 'script-sample').
	 * @param string $policy     Policy tag of the report URL: a (audit), t (test), e (enforce).
	 * @param string $user_agent Client user agent.
	 * @return array{id:int,new:bool,level:string}|null Null when the report was not usable.
	 */
	public static function record( array $report, string $policy = 'a', string $user_agent = '' ): ?array {
		global $wpdb;

		$directive    = CspPolicy::base_directive( self::clean( (string) ( $report['effective-directive'] ?? $report['violated-directive'] ?? '' ), 64 ) );
		// violated-directive of the legacy format carries the sources too ("script-src 'self' ...").
		$directive    = (string) strtok( $directive, ' ' );
		$blocked_uri  = self::clean( self::strip_query( (string) ( $report['blocked-uri'] ?? '' ) ), 2048 );
		$document_uri = self::clean( self::strip_query( (string) ( $report['document-uri'] ?? '' ) ), 2048 );
		$source_file  = self::clean( self::strip_query( (string) ( $report['source-file'] ?? '' ) ), 2048 );
		$line         = max( 0, (int) ( $report['line-number'] ?? 0 ) );
		$sample       = self::clean( (string) ( $report['script-sample'] ?? '' ), 255 );
		$policy       = in_array( $policy, array( 'a', 't', 'e' ), true ) ? $policy : 'a';

		if ( '' === $directive || '' === $blocked_uri ) {
			return null;
		}

		$blocked_host = self::extract_host( $blocked_uri );
		if ( '' === $blocked_host ) {
			$blocked_host = substr( $blocked_uri, 0, 80 );
		}

		$fingerprint = self::fingerprint( $directive, $blocked_host, $source_file, $line );
		$table       = self::table();
		$now         = gmdate( 'Y-m-d H:i:s' );
		$page        = self::page_path( $document_uri );
		$candidate   = 'a' !== $policy;

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is a fixed, code-controlled identifier.
		$existing = $wpdb->get_row( $wpdb->prepare( "SELECT id, pages, status, risk_level FROM {$table} WHERE fingerprint = %s", $fingerprint ), ARRAY_A );

		if ( ! is_array( $existing ) ) {
			$rating   = CspRisk::rate( $blocked_uri, $directive, self::site_host(), $sample );
			$inserted = $wpdb->query(
				$wpdb->prepare(
					"INSERT IGNORE INTO {$table}
						(fingerprint, directive, blocked_host, blocked_uri, document_uri, source_file, line_number, script_sample, pages, policy_last, candidate_seen,
						 sample_user_agent, sample_ip, hit_count, status, risk_level, risk_score, risk_reasons, service, first_seen, last_seen)
					 VALUES (%s, %s, %s, %s, %s, %s, %d, %s, %s, %s, " . ( $candidate ? '%s' : 'NULL' ) . ", %s, '', 1, 'open', %s, %d, %s, %s, %s, %s)",
					array_merge(
						array( $fingerprint, $directive, $blocked_host, $blocked_uri, $document_uri, $source_file, $line, $sample, wp_json_encode( '' === $page ? array() : array( $page ) ), $policy ),
						$candidate ? array( $now ) : array(),
						array( self::clean( $user_agent, 500 ), $rating['level'], $rating['score'], wp_json_encode( $rating['reasons'] ), $rating['service'], $now, $now )
					)
				)
			);
			if ( 1 === (int) $inserted ) {
				return array(
					'id'    => (int) $wpdb->insert_id,
					'new'   => true,
					'level' => $rating['level'],
				);
			}
			// Lost a race with a parallel report of the same resource - update instead.
			$existing = $wpdb->get_row( $wpdb->prepare( "SELECT id, pages, status, risk_level FROM {$table} WHERE fingerprint = %s", $fingerprint ), ARRAY_A );
			if ( ! is_array( $existing ) ) {
				return null;
			}
		}

		$pages = json_decode( (string) $existing['pages'], true );
		$pages = is_array( $pages ) ? $pages : array();
		if ( '' !== $page && ! in_array( $page, $pages, true ) && count( $pages ) < self::MAX_PAGES ) {
			$pages[] = $page;
		}

		// An approved resource reported again by the test/enforce policy means
		// the approval does not cover it (other directive, other host) - reopen.
		$status = ( $candidate && 'resolved' === $existing['status'] ) ? 'open' : (string) $existing['status'];

		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET hit_count = hit_count + 1, last_seen = %s, blocked_uri = %s, document_uri = %s, source_file = %s,
					line_number = %d, script_sample = %s, pages = %s, policy_last = %s, sample_user_agent = %s, status = %s"
					. ( $candidate ? ', candidate_seen = %s' : '' ) .
				' WHERE id = %d',
				array_merge(
					array( $now, $blocked_uri, $document_uri, $source_file, $line, $sample, wp_json_encode( $pages ), $policy, self::clean( $user_agent, 500 ), $status ),
					$candidate ? array( $now ) : array(),
					array( (int) $existing['id'] )
				)
			)
		);
		// phpcs:enable

		return array(
			'id'    => (int) $existing['id'],
			'new'   => false,
			'level' => (string) $existing['risk_level'],
		);
	}

	/**
	 * @param array{status?:string,search?:string,level?:string,orderby?:string,order?:string,per_page?:int,paged?:int} $args Query args.
	 * @return array<int,array<string,mixed>>
	 */
	public static function get_violations( array $args = array() ): array {
		global $wpdb;
		$table = self::table();

		$defaults = array(
			'status'   => '',
			'search'   => '',
			'level'    => '',
			'orderby'  => 'risk',
			'order'    => 'DESC',
			'per_page' => 100,
			'paged'    => 1,
		);
		$args = array_merge( $defaults, $args );

		[ $where_sql, $params ] = self::build_where( $args );

		$order = 'ASC' === strtoupper( (string) $args['order'] ) ? 'ASC' : 'DESC';
		$map   = array(
			'last_seen'    => "last_seen {$order}",
			'first_seen'   => "first_seen {$order}",
			'hit_count'    => "hit_count {$order}",
			'directive'    => "directive {$order}",
			'blocked_host' => "blocked_host {$order}",
			// Risk first, then the newest - what the dashboard should show on top.
			'risk'         => "risk_score {$order}, FIELD(risk_level, 'high', 'medium', 'unknown', 'known', 'self'), last_seen DESC",
		);
		$orderby = $map[ $args['orderby'] ] ?? $map['risk'];

		$per_page = max( 1, min( 500, (int) $args['per_page'] ) );
		$offset   = max( 0, ( (int) $args['paged'] - 1 ) * $per_page );

		$sql      = "SELECT * FROM {$table} WHERE {$where_sql} ORDER BY {$orderby} LIMIT %d OFFSET %d"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$params[] = $per_page;
		$params[] = $offset;

		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return array_map( array( self::class, 'present' ), $rows ?: array() );
	}

	/**
	 * Row as returned to the admin: JSON columns decoded, numbers cast.
	 *
	 * @param array<string,mixed> $row DB row.
	 * @return array<string,mixed>
	 */
	public static function present( array $row ): array {
		$reasons = json_decode( (string) ( $row['risk_reasons'] ?? '' ), true );
		$pages   = json_decode( (string) ( $row['pages'] ?? '' ), true );

		$row['id']           = (int) $row['id'];
		$row['hit_count']    = (int) $row['hit_count'];
		$row['risk_score']   = (int) ( $row['risk_score'] ?? 0 );
		$row['line_number']  = (int) ( $row['line_number'] ?? 0 );
		$row['risk_reasons'] = is_array( $reasons ) ? array_values( $reasons ) : array();
		$row['pages']        = is_array( $pages ) ? array_values( $pages ) : array();
		$row['source']       = self::uri_to_source( (string) $row['blocked_uri'] );
		unset( $row['sample_ip'], $row['fingerprint'] );
		return $row;
	}

	/**
	 * @param array{status?:string,search?:string,level?:string} $args Filter args.
	 */
	public static function get_violations_count( array $args = array() ): int {
		global $wpdb;
		$table = self::table();

		[ $where_sql, $params ] = self::build_where( $args );

		$sql = "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( ! empty( $params ) ) {
			$sql = $wpdb->prepare( $sql, $params ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}
		return (int) $wpdb->get_var( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * @param array $args Args with optional 'status', 'search' and 'level'.
	 * @return array{0:string,1:array<int,string>}
	 */
	private static function build_where( array $args ): array {
		global $wpdb;

		$where  = array( '1=1' );
		$params = array();

		if ( ! empty( $args['status'] ) && in_array( $args['status'], array( 'open', 'resolved' ), true ) ) {
			$where[]  = 'status = %s';
			$params[] = $args['status'];
		}

		if ( ! empty( $args['level'] ) && in_array( $args['level'], array( 'high', 'medium', 'unknown', 'known', 'self' ), true ) ) {
			$where[]  = 'risk_level = %s';
			$params[] = $args['level'];
		}

		if ( ! empty( $args['search'] ) ) {
			$like     = '%' . $wpdb->esc_like( $args['search'] ) . '%';
			$where[]  = '(blocked_host LIKE %s OR blocked_uri LIKE %s OR directive LIKE %s OR service LIKE %s)';
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
		}

		return array( implode( ' AND ', $where ), $params );
	}

	/**
	 * Numbers for the dashboard widget and the CSP tab header.
	 *
	 * @return array{total:int,open:int,high:int,medium:int,unknown:int,new_7d:int,candidate_open_7d:int}
	 */
	public static function stats(): array {
		global $wpdb;
		$table = self::table();
		$week  = gmdate( 'Y-m-d H:i:s', time() - 7 * DAY_IN_SECONDS );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT COUNT(*) AS total,
					SUM(status = 'open') AS open,
					SUM(status = 'open' AND risk_level = 'high') AS high,
					SUM(status = 'open' AND risk_level = 'medium') AS medium,
					SUM(status = 'open' AND risk_level = 'unknown') AS unknown,
					SUM(first_seen >= %s) AS new_7d,
					SUM(status = 'open' AND candidate_seen >= %s) AS candidate_open_7d
				 FROM {$table}",
				$week,
				$week
			),
			ARRAY_A
		);
		// phpcs:enable

		$row = is_array( $row ) ? $row : array();
		return array(
			'total'             => (int) ( $row['total'] ?? 0 ),
			'open'              => (int) ( $row['open'] ?? 0 ),
			'high'              => (int) ( $row['high'] ?? 0 ),
			'medium'            => (int) ( $row['medium'] ?? 0 ),
			'unknown'           => (int) ( $row['unknown'] ?? 0 ),
			'new_7d'            => (int) ( $row['new_7d'] ?? 0 ),
			'candidate_open_7d' => (int) ( $row['candidate_open_7d'] ?? 0 ),
		);
	}

	/**
	 * Rows that still need a domain-age check (maintenance cron).
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function rows_needing_age_check( int $limit ): array {
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE risk_checked_at IS NULL AND risk_level IN ('unknown', 'medium', 'high') ORDER BY first_seen DESC LIMIT %d", $limit ), ARRAY_A );
		return $rows ?: array();
	}

	/**
	 * Store the domain-age refined rating of a row.
	 *
	 * @param array{level:string,score:int,reasons:array<int,string>,service:string} $rating Rating.
	 */
	public static function update_rating( int $id, array $rating, int $created ): void {
		global $wpdb;
		$wpdb->update(
			self::table(),
			array(
				'risk_level'      => $rating['level'],
				'risk_score'      => $rating['score'],
				'risk_reasons'    => wp_json_encode( $rating['reasons'] ),
				'domain_created'  => $created > 0 ? gmdate( 'Y-m-d', $created ) : null,
				'risk_checked_at' => gmdate( 'Y-m-d H:i:s' ),
			),
			array( 'id' => $id ),
			array( '%s', '%d', '%s', '%s', '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Rows first reported by the enforced policy since a moment (rollback check).
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function blocked_since( string $since_utc, int $min_hits ): array {
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE status = 'open' AND policy_last = 'e' AND candidate_seen >= %s AND hit_count >= %d AND directive IN ('script-src', 'connect-src', 'frame-src', 'worker-src') AND risk_level <> 'high'", $since_utc, $min_hits ), ARRAY_A );
		return $rows ?: array();
	}

	/**
	 * Delete old rows (daily maintenance).
	 */
	public static function purge(): int {
		global $wpdb;
		$table = self::table();
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$deleted  = (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE status = 'resolved' AND last_seen < %s", gmdate( 'Y-m-d H:i:s', time() - self::KEEP_RESOLVED_DAYS * DAY_IN_SECONDS ) ) );
		$deleted += (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE last_seen < %s", gmdate( 'Y-m-d H:i:s', time() - self::KEEP_ANY_DAYS * DAY_IN_SECONDS ) ) );
		// phpcs:enable
		return $deleted;
	}

	/**
	 * @param int[]  $ids    Violation ids.
	 * @param string $status New status ('open'|'resolved').
	 */
	public static function set_status( array $ids, string $status ): int {
		global $wpdb;
		if ( empty( $ids ) || ! in_array( $status, array( 'open', 'resolved' ), true ) ) {
			return 0;
		}
		$ids = array_filter( array_map( 'intval', $ids ) );
		if ( empty( $ids ) ) {
			return 0;
		}
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$sql          = $wpdb->prepare(
			'UPDATE ' . self::table() . " SET status = %s WHERE id IN ({$placeholders})", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			array_merge( array( $status ), $ids )
		);
		return (int) $wpdb->query( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * @param int[] $ids Violation ids to delete.
	 */
	public static function delete( array $ids ): int {
		global $wpdb;
		$ids = array_filter( array_map( 'intval', $ids ) );
		if ( empty( $ids ) ) {
			return 0;
		}
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$sql          = $wpdb->prepare(
			'DELETE FROM ' . self::table() . " WHERE id IN ({$placeholders})", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$ids
		);
		return (int) $wpdb->query( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * One violation row, or null.
	 *
	 * @return array<string,mixed>|null
	 */
	public static function get( int $id ): ?array {
		global $wpdb;
		if ( $id <= 0 ) {
			return null;
		}
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE id = %d', $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return is_array( $row ) ? $row : null;
	}

	/**
	 * Open rows that can be approved in bulk: external origin, rated known
	 * or unknown without any risk signal.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function safe_open_rows(): array {
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( "SELECT * FROM {$table} WHERE status = 'open' AND risk_level IN ('known', 'self', 'unknown') AND risk_score = 0", ARRAY_A );
		return $rows ?: array();
	}

	/**
	 * CSP source token (scheme://host) for a blocked-uri, or '' when the
	 * violation has no external origin that could be allowlisted (inline code,
	 * eval, data:/blob: URIs - those must be fixed in the code instead).
	 * Ported from legacy CspViolationsLogger::uriToSource().
	 */
	public static function uri_to_source( string $uri ): string {
		$uri = trim( $uri );
		if ( '' === $uri || in_array( strtolower( $uri ), array( 'self', 'inline', 'eval', 'data', 'blob', 'wasm-eval', 'trusted-types-sink' ), true ) ) {
			return '';
		}
		$parsed = wp_parse_url( $uri );
		$host   = is_array( $parsed ) ? strtolower( (string) ( $parsed['host'] ?? '' ) ) : '';
		if ( '' === $host || 1 !== preg_match( '/^[a-z0-9]([a-z0-9\-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9\-]*[a-z0-9])?)+$/', $host ) ) {
			return '';
		}
		$scheme = strtolower( (string) ( $parsed['scheme'] ?? 'https' ) );
		if ( ! in_array( $scheme, array( 'http', 'https', 'ws', 'wss' ), true ) ) {
			$scheme = 'https';
		}
		return $scheme . '://' . $host;
	}

	/**
	 * Host of this site (reports for other hosts are rejected, own
	 * subdomains count as own).
	 */
	public static function site_host(): string {
		return strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
	}

	/**
	 * External resources group by host; inline/eval by where the code sits,
	 * otherwise every inline script on the site would be one row.
	 */
	private static function fingerprint( string $directive, string $host, string $source_file, int $line ): string {
		$key = strtolower( $directive ) . '|' . strtolower( $host );
		if ( in_array( strtolower( $host ), array( 'inline', 'eval', 'wasm-eval' ), true ) ) {
			$key .= '|' . strtolower( $source_file ) . '|' . $line;
		}
		return md5( $key );
	}

	/**
	 * Path part of a document URI, for the list of pages a resource was seen on.
	 */
	private static function page_path( string $document_uri ): string {
		$path = wp_parse_url( $document_uri, PHP_URL_PATH );
		return is_string( $path ) ? substr( $path, 0, 200 ) : '';
	}

	/**
	 * Drop query string and fragment.
	 */
	public static function strip_query( string $uri ): string {
		$uri = trim( $uri );
		$cut = strcspn( $uri, '?#' );
		return substr( $uri, 0, $cut );
	}

	/**
	 * Extract a comparable host token from a blocked-uri value.
	 */
	private static function extract_host( string $uri ): string {
		if ( '' === $uri ) {
			return '';
		}
		if ( in_array( strtolower( $uri ), array( 'inline', 'eval', 'self', 'data', 'blob', 'unsafe-inline', 'unsafe-eval', 'wasm-eval' ), true ) ) {
			return strtolower( $uri );
		}
		$parsed = wp_parse_url( $uri );
		return ! empty( $parsed['host'] ) ? strtolower( $parsed['host'] ) : '';
	}

	/**
	 * Strip control characters and clamp length.
	 */
	private static function clean( string $value, int $max_len ): string {
		$value = preg_replace( '/[\x00-\x1F\x7F]/', '', $value ) ?? '';
		return function_exists( 'mb_substr' ) ? mb_substr( $value, 0, $max_len ) : substr( $value, 0, $max_len );
	}
}
