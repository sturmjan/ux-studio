<?php
/**
 * CSV import/export and a one-click import from the Redirection plugin's
 * tables (this module replaces it). Every row goes through
 * Repository::save(), so imported rules get the same chain/loop checks.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\Redirects;

use UxStudio\Core\Csv;

defined( 'ABSPATH' ) || exit;

final class Importer {

	/** Max rows per import call. */
	public const MAX_ROWS = 5000;

	/**
	 * Import CSV text. Columns: source, target, code, note (header optional,
	 * comma or semicolon separated). Empty target + code 410 = gone.
	 *
	 * @param string $csv         CSV text.
	 * @param string $on_conflict update|skip|error.
	 * @param bool   $dry_run     Validate only.
	 * @return array{summary:array<string, int>, results:array<int, array<string, mixed>>}
	 */
	public static function csv( string $csv, string $on_conflict, bool $dry_run ): array {
		$csv   = (string) preg_replace( '/^\xEF\xBB\xBF/', '', $csv );
		$lines = preg_split( '/\r\n|\r|\n/', trim( $csv ) ) ?: array();
		$delim = isset( $lines[0] ) && substr_count( $lines[0], ';' ) > substr_count( $lines[0], ',' ) ? ';' : ',';

		$columns = array( 'source', 'target', 'code', 'note' );
		$items   = array();
		foreach ( $lines as $n => $line ) {
			if ( '' === trim( $line ) ) {
				continue;
			}
			$cells = array_map( 'trim', str_getcsv( $line, $delim, '"', '' ) );
			if ( 0 === $n && in_array( strtolower( $cells[0] ?? '' ), array( 'source', 'url', 'from', 'old' ), true ) ) {
				$columns = array_map(
					static fn ( string $c ): string => array( 'url' => 'source', 'from' => 'source', 'old' => 'source', 'to' => 'target', 'new' => 'target', 'status' => 'code' )[ strtolower( $c ) ] ?? strtolower( $c ),
					$cells
				);
				continue;
			}
			$item = array();
			foreach ( $columns as $i => $column ) {
				if ( isset( $cells[ $i ] ) ) {
					// Undo the formula guard our own export adds.
					$item[ $column ] = str_starts_with( $cells[ $i ], "'" ) ? substr( $cells[ $i ], 1 ) : $cells[ $i ];
				}
			}
			if ( empty( $item['code'] ) ) {
				$item['code'] = '' === ( $item['target'] ?? '' ) ? 410 : 301;
			}
			$items[] = $item;
		}
		return self::run( $items, $on_conflict, $dry_run );
	}

	/**
	 * Whether the Redirection plugin's table exists.
	 */
	public static function redirection_available(): bool {
		global $wpdb;
		$table = $wpdb->prefix . 'redirection_items';
		return $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Import URL redirects (and 410s) from the Redirection plugin. Rules with
	 * login/referrer/IP/cookie conditions are skipped - they have no
	 * equivalent here and are not SEO redirects.
	 *
	 * @param string $on_conflict update|skip|error.
	 * @param bool   $dry_run     Validate only.
	 * @return array{summary:array<string, int>, results:array<int, array<string, mixed>>}
	 */
	public static function redirection( string $on_conflict, bool $dry_run ): array {
		if ( ! self::redirection_available() ) {
			return array( 'summary' => array(), 'results' => array() );
		}
		global $wpdb;
		$table = $wpdb->prefix . 'redirection_items';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE match_type = 'url' ORDER BY position ASC, id ASC LIMIT %d", self::MAX_ROWS ), ARRAY_A );

		$items = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$code = (int) $row['action_code'];
			if ( 'url' === $row['action_type'] ) {
				$target = (string) $row['action_data'];
			} elseif ( 'error' === $row['action_type'] && 410 === $code ) {
				$target = '';
			} else {
				continue;
			}
			$items[] = array(
				'source'     => (string) $row['url'],
				'match_type' => (int) $row['regex'] ? 'regex' : 'exact',
				'target'     => $target,
				'code'       => in_array( $code, Repository::CODES, true ) ? $code : 301,
				'enabled'    => 'enabled' === $row['status'],
				'note'       => (string) ( $row['title'] ?? '' ),
				'position'   => (int) $row['position'],
			);
		}
		return self::run( $items, $on_conflict, $dry_run );
	}

	/**
	 * All rules as CSV (same columns the importer reads).
	 */
	public static function export_csv(): string {
		global $wpdb;
		$table = Repository::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY id ASC", ARRAY_A );

		$out = fopen( 'php://temp', 'r+' );
		fputcsv( $out, array( 'source', 'target', 'code', 'note', 'match_type', 'enabled', 'hits' ), ',', '"', '' );
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$target = 410 === (int) $row['code'] ? '' : ( 'regex' === $row['match_type'] ? (string) $row['target'] : Repository::resolve_target( $row ) );
			fputcsv( $out, Csv::safe_row( array( $row['source'], $target, $row['code'], $row['note'], $row['match_type'], $row['enabled'], $row['hits'] ) ), ',', '"', '' );
		}
		rewind( $out );
		$csv = (string) stream_get_contents( $out );
		fclose( $out );
		return $csv;
	}

	/**
	 * Save items one by one, collect results.
	 *
	 * @param array<int, array<string, mixed>> $items       Rule inputs.
	 * @param string                           $on_conflict update|skip|error.
	 * @param bool                             $dry_run     Validate only.
	 * @return array{summary:array<string, int>, results:array<int, array<string, mixed>>}
	 */
	private static function run( array $items, string $on_conflict, bool $dry_run ): array {
		$summary = array();
		$results = array();
		foreach ( array_slice( $items, 0, self::MAX_ROWS ) as $index => $item ) {
			$item['origin'] = 'import';
			$result         = Repository::save( $item, $on_conflict, $dry_run );
			$summary[ $result['status'] ] = ( $summary[ $result['status'] ] ?? 0 ) + 1;
			// Keep the response small: only rows worth looking at.
			if ( $result['errors'] || $result['warnings'] ) {
				$result['index'] = $index;
				$results[]       = $result;
			}
		}
		return array(
			'summary' => $summary,
			'results' => $results,
		);
	}
}
