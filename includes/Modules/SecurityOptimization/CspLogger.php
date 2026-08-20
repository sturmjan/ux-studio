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
 * Violations are aggregated by fingerprint (directive + blocked host) so a
 * repeated browser report only bumps hit_count/last_seen instead of growing
 * the table unbounded.
 */
final class CspLogger {

	private const MAX_BODY_BYTES = 8192;

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
	 * @param array  $report     Normalized report ('effective-directive'/'violated-directive', 'blocked-uri', 'document-uri', 'source-file').
	 * @param string $ip         Client IP (stored only as a sample, not for rate limiting here).
	 * @param string $user_agent Client user agent.
	 */
	public static function record( array $report, string $ip = '', string $user_agent = '' ): bool {
		global $wpdb;

		$directive    = self::clean( (string) ( $report['effective-directive'] ?? $report['violated-directive'] ?? '' ), 64 );
		$blocked_uri  = self::clean( (string) ( $report['blocked-uri'] ?? '' ), 2048 );
		$document_uri = self::clean( (string) ( $report['document-uri'] ?? '' ), 2048 );
		$source_file  = self::clean( (string) ( $report['source-file'] ?? '' ), 2048 );

		if ( '' === $directive || '' === $blocked_uri ) {
			return false;
		}

		$blocked_host = self::extract_host( $blocked_uri );
		if ( '' === $blocked_host ) {
			$blocked_host = substr( $blocked_uri, 0, 80 );
		}

		$fingerprint = md5( strtolower( $directive ) . '|' . strtolower( $blocked_host ) );
		$table       = self::table();

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared -- $table is a fixed, code-controlled identifier.
		$sql = $wpdb->prepare(
			"INSERT INTO {$table}
				(fingerprint, directive, blocked_host, blocked_uri, document_uri, source_file, sample_user_agent, sample_ip, hit_count, status, first_seen, last_seen)
			 VALUES (%s, %s, %s, %s, %s, %s, %s, %s, 1, 'open', NOW(), NOW())
			 ON DUPLICATE KEY UPDATE
				hit_count = hit_count + 1,
				last_seen = NOW(),
				blocked_uri = VALUES(blocked_uri),
				document_uri = VALUES(document_uri),
				source_file = VALUES(source_file),
				sample_user_agent = VALUES(sample_user_agent),
				sample_ip = VALUES(sample_ip)",
			$fingerprint,
			$directive,
			$blocked_host,
			$blocked_uri,
			$document_uri,
			$source_file,
			self::clean( $user_agent, 500 ),
			self::clean( $ip, 45 )
		);
		// phpcs:enable

		return false !== $wpdb->query( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * @param array{status?:string,search?:string,orderby?:string,order?:string,per_page?:int,paged?:int} $args Query args.
	 * @return array<int,object>
	 */
	public static function get_violations( array $args = array() ): array {
		global $wpdb;
		$table = self::table();

		$defaults = array(
			'status'   => '',
			'search'   => '',
			'orderby'  => 'last_seen',
			'order'    => 'DESC',
			'per_page' => 25,
			'paged'    => 1,
		);
		$args = array_merge( $defaults, $args );

		[ $where_sql, $params ] = self::build_where( $args );

		$allowed_orderby = array( 'last_seen', 'first_seen', 'hit_count', 'directive', 'blocked_host' );
		$orderby          = in_array( $args['orderby'], $allowed_orderby, true ) ? $args['orderby'] : 'last_seen';
		$order            = 'ASC' === strtoupper( (string) $args['order'] ) ? 'ASC' : 'DESC';

		$per_page = max( 1, min( 200, (int) $args['per_page'] ) );
		$offset   = max( 0, ( (int) $args['paged'] - 1 ) * $per_page );

		$sql      = "SELECT * FROM {$table} WHERE {$where_sql} ORDER BY {$orderby} {$order} LIMIT %d OFFSET %d"; // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$params[] = $per_page;
		$params[] = $offset;

		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return $rows ?: array();
	}

	/**
	 * @param array{status?:string,search?:string} $args Filter args.
	 */
	public static function get_violations_count( array $args = array() ): int {
		global $wpdb;
		$table = self::table();

		[ $where_sql, $params ] = self::build_where( $args );

		$sql = "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}"; // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		if ( ! empty( $params ) ) {
			$sql = $wpdb->prepare( $sql, $params ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}
		return (int) $wpdb->get_var( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * @param array $args Args with optional 'status' and 'search'.
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

		if ( ! empty( $args['search'] ) ) {
			$like     = '%' . $wpdb->esc_like( $args['search'] ) . '%';
			$where[]  = '(blocked_host LIKE %s OR blocked_uri LIKE %s OR directive LIKE %s)';
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
		}

		return array( implode( ' AND ', $where ), $params );
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
			'UPDATE ' . self::table() . " SET status = %s WHERE id IN ({$placeholders})", // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
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
			'DELETE FROM ' . self::table() . " WHERE id IN ({$placeholders})", // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$ids
		);
		return (int) $wpdb->query( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Extract a comparable host token from a blocked-uri value.
	 */
	private static function extract_host( string $uri ): string {
		if ( '' === $uri ) {
			return '';
		}
		if ( in_array( strtolower( $uri ), array( 'inline', 'eval', 'self', 'data', 'blob', 'unsafe-inline', 'unsafe-eval' ), true ) ) {
			return $uri;
		}
		$parsed = wp_parse_url( $uri );
		return ! empty( $parsed['host'] ) ? $parsed['host'] : '';
	}

	/**
	 * Strip control characters and clamp length.
	 */
	private static function clean( string $value, int $max_len ): string {
		$value = preg_replace( '/[\x00-\x1F\x7F]/', '', $value ) ?? '';
		return function_exists( 'mb_substr' ) ? mb_substr( $value, 0, $max_len ) : substr( $value, 0, $max_len );
	}
}
