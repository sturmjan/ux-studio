<?php
/**
 * Storage of the admin notice history (table uxstudio_admin_notices).
 *
 * One row per notice of the site (not per user): the same notice seen by two administrators is one row, the
 * user who closed it is recorded in dismissed_by. Texts can contain names and e-mail addresses, so the table is
 * read only by administrators (REST, manage_options), never exported with the settings and never printed on the
 * front end.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\AdminCustomiser\NoticeHistory;

use UxStudio\Core\DB;

defined( 'ABSPATH' ) || exit;

final class Store {

	public const MODULE     = 'admin-customiser';
	public const DB_VERSION = 1;

	/** Transient that throttles the opportunistic maintenance (sweep + purge). */
	private const MAINTENANCE_TRANSIENT = 'uxstudio_admin_notices_maint';

	/** Request-local flag: the table is known to exist. */
	private static bool $ready = false;

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'uxstudio_admin_notices';
	}

	/**
	 * Create the table when missing (cheap on repeat calls). Returns whether the table can be used.
	 */
	public static function ensure(): bool {
		if ( self::$ready ) {
			return true;
		}
		DB::ensure_module_tables( self::MODULE, self::DB_VERSION, array( self::class, 'migrate' ) );
		global $wpdb;
		$table = self::table();
		self::$ready = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) === $table; // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( ! self::$ready && DB::module_schema_version( self::MODULE ) >= self::DB_VERSION && false === get_transient( 'uxstudio_admin_notices_repair' ) ) {
			// The version says "created" but the table is gone (dropped by hand, restored backup): create it again.
			set_transient( 'uxstudio_admin_notices_repair', 1, 15 * MINUTE_IN_SECONDS );
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
			self::migrate( 0 );
			self::$ready = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) === $table; // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}
		return self::$ready;
	}

	/** Forget the cached "ready" flag (tests drop the table and expect it back). */
	public static function reset_ready(): void {
		self::$ready = false;
	}

	/**
	 * dbDelta step of ensure_module_tables().
	 *
	 * @param int $from Installed version of the module schema.
	 */
	public static function migrate( int $from ): void {
		unset( $from );
		global $wpdb;
		$table   = self::table();
		$charset = $wpdb->get_charset_collate();
		dbDelta(
			"CREATE TABLE {$table} (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				notice_key CHAR(40) NOT NULL,
				client_key VARCHAR(255) NOT NULL DEFAULT '',
				type VARCHAR(10) NOT NULL DEFAULT 'info',
				title VARCHAR(190) NOT NULL DEFAULT '',
				text TEXT NOT NULL,
				source_slug VARCHAR(100) NOT NULL DEFAULT '',
				source_name VARCHAR(190) NOT NULL DEFAULT '',
				source_how VARCHAR(10) NOT NULL DEFAULT '',
				screen VARCHAR(100) NOT NULL DEFAULT '',
				dismissible TINYINT(1) NOT NULL DEFAULT 0,
				first_seen DATETIME NOT NULL,
				last_seen DATETIME NOT NULL,
				seen_count INT UNSIGNED NOT NULL DEFAULT 1,
				reappeared_count INT UNSIGNED NOT NULL DEFAULT 0,
				state VARCHAR(10) NOT NULL DEFAULT 'active',
				dismissed_at DATETIME NULL DEFAULT NULL,
				dismissed_by BIGINT UNSIGNED NOT NULL DEFAULT 0,
				dismiss_method VARCHAR(10) NOT NULL DEFAULT '',
				first_seen_user BIGINT UNSIGNED NOT NULL DEFAULT 0,
				PRIMARY KEY  (id),
				UNIQUE KEY notice_key (notice_key),
				KEY state_last_seen (state, last_seen),
				KEY last_seen (last_seen)
			) {$charset};"
		);
	}

	/** Drop the table and forget its schema version (a full data cleanup; the table comes back lazily). */
	public static function drop(): void {
		global $wpdb;
		$wpdb->query( 'DROP TABLE IF EXISTS ' . self::table() ); // phpcs:ignore WordPress.DB
		self::$ready = false;
		$versions = get_option( DB::MODULE_VERSIONS_OPTION, array() );
		$slug     = str_replace( '-', '_', self::MODULE );
		if ( is_array( $versions ) && isset( $versions[ $slug ] ) ) {
			unset( $versions[ $slug ] );
			update_option( DB::MODULE_VERSIONS_OPTION, $versions, true );
		}
	}

	/**
	 * Active plugins as directory slug => name (for the source guess).
	 *
	 * @return array<string, string>
	 */
	public static function active_plugins(): array {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$active = (array) get_option( 'active_plugins', array() );
		if ( is_multisite() ) {
			$active = array_merge( $active, array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) );
		}
		$out = array();
		foreach ( get_plugins() as $file => $data ) {
			if ( ! in_array( $file, $active, true ) ) {
				continue;
			}
			$slug         = false !== strpos( $file, '/' ) ? substr( $file, 0, (int) strpos( $file, '/' ) ) : (string) preg_replace( '/\.php$/', '', $file );
			$out[ $slug ] = (string) ( $data['Name'] ?? $slug );
		}
		return $out;
	}

	/** Current time in the site zone, the way the other UX Studio tables store it. */
	private static function now(): string {
		return current_time( 'mysql' );
	}

	/**
	 * Record the notices one page load showed in the panel. Each notice counts once per call (page load).
	 *
	 * @param array<int, array<string, mixed>> $items   Output of Rules::sanitize_item().
	 * @param int                              $user_id Administrator who loaded the page.
	 * @return int Number of distinct notices recorded.
	 */
	public static function log( array $items, int $user_id ): int {
		if ( ! self::ensure() ) {
			return 0;
		}
		$plugins = self::active_plugins();
		$seen    = array();
		foreach ( array_slice( $items, 0, Rules::BATCH_MAX ) as $item ) {
			$key = Rules::item_key( $item );
			if ( isset( $seen[ $key ] ) ) {
				continue;
			}
			$seen[ $key ] = true;
			self::upsert( $key, $item, $plugins, $user_id, false );
		}
		self::maintenance();
		return count( $seen );
	}

	/**
	 * Insert a notice or update the existing row of the same key.
	 *
	 * @param string               $key     Notice key.
	 * @param array<string, mixed> $item    Sanitised item.
	 * @param array<string,string> $plugins Active plugins.
	 * @param int                  $user_id User.
	 * @param bool                 $dismiss Whether this call is a dismissal (does not count as a page view).
	 * @return int Row id.
	 */
	private static function upsert( string $key, array $item, array $plugins, int $user_id, bool $dismiss ): int {
		global $wpdb;
		$table  = self::table();
		$now    = self::now();
		$source = Rules::guess_source( $item, $plugins );
		$row    = $wpdb->get_row( $wpdb->prepare( "SELECT id, state, reappeared_count FROM {$table} WHERE notice_key = %s", $key ), ARRAY_A ); // phpcs:ignore WordPress.DB

		if ( null === $row ) {
			$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$table,
				array(
					'notice_key'       => $key,
					'client_key'       => (string) $item['ckey'],
					'type'             => (string) $item['type'],
					'title'            => (string) $item['title'],
					'text'             => (string) $item['text'],
					'source_slug'      => $source['slug'],
					'source_name'      => $source['name'],
					'source_how'       => $source['how'],
					'screen'           => (string) $item['screen'],
					'dismissible'      => ! empty( $item['dismissible'] ) ? 1 : 0,
					'first_seen'       => $now,
					'last_seen'        => $now,
					'seen_count'       => $dismiss ? 0 : 1,
					'reappeared_count' => 0,
					'state'            => 'active',
					'first_seen_user'  => $user_id,
				)
			);
			if ( $wpdb->insert_id ) {
				return (int) $wpdb->insert_id;
			}
			// Lost a race with a parallel request: take the row that won.
			$row = $wpdb->get_row( $wpdb->prepare( "SELECT id, state, reappeared_count FROM {$table} WHERE notice_key = %s", $key ), ARRAY_A ); // phpcs:ignore WordPress.DB
			if ( null === $row ) {
				return 0;
			}
		}

		$id = (int) $row['id'];
		if ( $dismiss ) {
			// Keep the text fresh, but a dismissal is not another page view.
			$wpdb->update( $table, array( 'text' => (string) $item['text'], 'client_key' => (string) $item['ckey'] ), array( 'id' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			return $id;
		}
		$next = Rules::on_seen( (string) $row['state'], (int) $row['reappeared_count'] );
		$set  = array(
			'last_seen'        => $now,
			'text'             => (string) $item['text'],
			'title'            => (string) $item['title'],
			'client_key'       => (string) $item['ckey'],
			'screen'           => (string) $item['screen'],
			'dismissible'      => ! empty( $item['dismissible'] ) ? 1 : 0,
			'state'            => $next['state'],
			'reappeared_count' => $next['reappeared'],
		);
		if ( '' !== $source['slug'] ) {
			$set['source_slug'] = $source['slug'];
			$set['source_name'] = $source['name'];
			$set['source_how']  = $source['how'];
		}
		if ( 'active' !== (string) $row['state'] ) {
			$set['dismissed_at']   = null;
			$set['dismissed_by']   = 0;
			$set['dismiss_method'] = '';
		}
		$wpdb->update( $table, $set, array( 'id' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET seen_count = seen_count + 1 WHERE id = %d", $id ) ); // phpcs:ignore WordPress.DB
		return $id;
	}

	/**
	 * The user closed a notice. The row is created when the log request did not arrive yet.
	 *
	 * @param array<string, mixed> $item    Sanitised item.
	 * @param string               $method  `panel` or `plugin`.
	 * @param int                  $user_id User.
	 */
	public static function dismiss( array $item, string $method, int $user_id ): int {
		if ( ! self::ensure() ) {
			return 0;
		}
		global $wpdb;
		$key = Rules::item_key( $item );
		$id  = self::upsert( $key, $item, self::active_plugins(), $user_id, true );
		if ( $id <= 0 ) {
			return 0;
		}
		$next = Rules::on_dismiss( $method );
		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			self::table(),
			array(
				'state'          => $next['state'],
				'dismissed_at'   => self::now(),
				'dismissed_by'   => $user_id,
				'dismiss_method' => $next['method'],
			),
			array( 'id' => $id )
		);
		return $id;
	}

	/**
	 * Client keys of notices the panel hides on every page (dismissed with the panel's own cross).
	 *
	 * @return string[]
	 */
	public static function hidden_client_keys(): array {
		if ( ! self::ensure() ) {
			return array();
		}
		global $wpdb;
		$table = self::table();
		$keys  = $wpdb->get_col( "SELECT client_key FROM {$table} WHERE state = 'dismissed' AND dismiss_method = 'panel' AND client_key <> '' ORDER BY dismissed_at DESC LIMIT 100" ); // phpcs:ignore WordPress.DB
		return array_values( array_map( 'strval', (array) $keys ) );
	}

	/**
	 * Show a notice hidden by the panel again.
	 *
	 * @return string `ok`, `not_found` or `not_restorable`.
	 */
	public static function reshow( int $id ): string {
		if ( ! self::ensure() ) {
			return 'not_found';
		}
		global $wpdb;
		$table = self::table();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT state, dismiss_method FROM {$table} WHERE id = %d", $id ), ARRAY_A ); // phpcs:ignore WordPress.DB
		if ( null === $row ) {
			return 'not_found';
		}
		if ( ! Rules::can_reshow( (string) $row['state'], (string) $row['dismiss_method'] ) ) {
			return 'not_restorable';
		}
		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$table,
			array(
				'state'          => 'active',
				'dismissed_at'   => null,
				'dismissed_by'   => 0,
				'dismiss_method' => '',
			),
			array( 'id' => $id )
		);
		return 'ok';
	}

	/**
	 * WHERE clause (prepared) of the list filters.
	 *
	 * @param array<string, mixed> $f Output of Rules::sanitize_filters().
	 */
	private static function where( array $f ): string {
		global $wpdb;
		$parts = array();
		if ( '' !== $f['state'] ) {
			$parts[] = $wpdb->prepare( 'state = %s', $f['state'] );
		}
		if ( '' !== $f['type'] ) {
			$parts[] = $wpdb->prepare( 'type = %s', $f['type'] );
		}
		if ( '' !== $f['source'] ) {
			$parts[] = Rules::UNKNOWN_SOURCE === $f['source'] ? "source_slug = ''" : $wpdb->prepare( 'source_slug = %s', $f['source'] );
		}
		$seconds = Rules::PERIODS[ $f['period'] ] ?? 0;
		if ( $seconds > 0 ) {
			$parts[] = $wpdb->prepare( 'last_seen >= %s', wp_date( 'Y-m-d H:i:s', time() - $seconds ) );
		}
		if ( '' !== $f['q'] ) {
			$like    = '%' . $wpdb->esc_like( $f['q'] ) . '%';
			$parts[] = $wpdb->prepare( '(title LIKE %s OR text LIKE %s)', $like, $like );
		}
		return $parts ? ' WHERE ' . implode( ' AND ', $parts ) : '';
	}

	/**
	 * One page of the list.
	 *
	 * @param array<string, mixed> $filters Raw filters.
	 * @return array{items: array<int, array<string, mixed>>, total: int, filters: array<string, mixed>}
	 */
	public static function query( array $filters ): array {
		$f = Rules::sanitize_filters( $filters );
		if ( ! self::ensure() ) {
			return array(
				'items'   => array(),
				'total'   => 0,
				'filters' => $f,
			);
		}
		global $wpdb;
		self::maintenance();
		$table = self::table();
		$where = self::where( $f );
		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}{$where}" ); // phpcs:ignore WordPress.DB
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table}{$where} ORDER BY last_seen DESC, id DESC LIMIT %d OFFSET %d", $f['per_page'], ( $f['page'] - 1 ) * $f['per_page'] ), ARRAY_A ); // phpcs:ignore WordPress.DB
		return array(
			'items'   => self::present( (array) $rows ),
			'total'   => $total,
			'filters' => $f,
		);
	}

	/**
	 * Rows for the SPA: typed fields, the name of the user who closed the notice, the "can be shown again" flag.
	 *
	 * @param array<int, array<string, mixed>> $rows Database rows.
	 * @return array<int, array<string, mixed>>
	 */
	private static function present( array $rows ): array {
		$names = array();
		$out   = array();
		foreach ( $rows as $row ) {
			$uid = (int) $row['dismissed_by'];
			if ( $uid > 0 && ! isset( $names[ $uid ] ) ) {
				$user          = get_userdata( $uid );
				$names[ $uid ] = $user ? (string) $user->display_name : '';
			}
			$out[] = array(
				'id'               => (int) $row['id'],
				'type'             => (string) $row['type'],
				'title'            => (string) $row['title'],
				'text'             => (string) $row['text'],
				'source_slug'      => (string) $row['source_slug'],
				'source_name'      => (string) $row['source_name'],
				'screen'           => (string) $row['screen'],
				'dismissible'      => (bool) $row['dismissible'],
				'first_seen'       => (string) $row['first_seen'],
				'last_seen'        => (string) $row['last_seen'],
				'seen_count'       => (int) $row['seen_count'],
				'reappeared_count' => (int) $row['reappeared_count'],
				'state'            => (string) $row['state'],
				'dismissed_at'     => $row['dismissed_at'] ? (string) $row['dismissed_at'] : null,
				'dismissed_by'     => $uid,
				'dismissed_by_name' => $uid > 0 ? ( $names[ $uid ] ?? '' ) : '',
				'dismiss_method'   => (string) $row['dismiss_method'],
				'can_reshow'       => Rules::can_reshow( (string) $row['state'], (string) $row['dismiss_method'] ),
			);
		}
		return $out;
	}

	/**
	 * Counters for the page header and the source filter.
	 *
	 * @return array{states: array<string,int>, types: array<string,int>, sources: array<int, array{slug:string,name:string,count:int}>, total: int}
	 */
	public static function stats(): array {
		$out = array(
			'states'  => array_fill_keys( Rules::STATES, 0 ),
			'types'   => array_fill_keys( Rules::TYPES, 0 ),
			'sources' => array(),
			'total'   => 0,
		);
		if ( ! self::ensure() ) {
			return $out;
		}
		global $wpdb;
		$table = self::table();
		foreach ( (array) $wpdb->get_results( "SELECT state, COUNT(*) AS c FROM {$table} GROUP BY state", ARRAY_A ) as $r ) { // phpcs:ignore WordPress.DB
			if ( isset( $out['states'][ $r['state'] ] ) ) {
				$out['states'][ $r['state'] ] = (int) $r['c'];
			}
			$out['total'] += (int) $r['c'];
		}
		foreach ( (array) $wpdb->get_results( "SELECT type, COUNT(*) AS c FROM {$table} GROUP BY type", ARRAY_A ) as $r ) { // phpcs:ignore WordPress.DB
			if ( isset( $out['types'][ $r['type'] ] ) ) {
				$out['types'][ $r['type'] ] = (int) $r['c'];
			}
		}
		foreach ( (array) $wpdb->get_results( "SELECT source_slug, MAX(source_name) AS n, COUNT(*) AS c FROM {$table} GROUP BY source_slug ORDER BY c DESC, source_slug ASC LIMIT 100", ARRAY_A ) as $r ) { // phpcs:ignore WordPress.DB
			$out['sources'][] = array(
				'slug'  => (string) $r['source_slug'],
				'name'  => (string) $r['n'],
				'count' => (int) $r['c'],
			);
		}
		return $out;
	}

	public static function delete( int $id ): bool {
		return 1 === self::delete_many( array( $id ) );
	}

	/**
	 * @param int[] $ids Row ids.
	 * @return int Rows deleted.
	 */
	public static function delete_many( array $ids ): int {
		$ids = array_values( array_unique( array_filter( array_map( 'intval', $ids ), static fn ( int $i ): bool => $i > 0 ) ) );
		if ( ! $ids || ! self::ensure() ) {
			return 0;
		}
		global $wpdb;
		$table = self::table();
		$total = 0;
		foreach ( array_chunk( $ids, 200 ) as $chunk ) {
			$marks  = implode( ',', array_fill( 0, count( $chunk ), '%d' ) );
			$total += (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE id IN ({$marks})", $chunk ) ); // phpcs:ignore WordPress.DB
		}
		return $total;
	}

	/** Delete the whole history. */
	public static function delete_all(): int {
		if ( ! self::ensure() ) {
			return 0;
		}
		global $wpdb;
		return (int) $wpdb->query( 'DELETE FROM ' . self::table() ); // phpcs:ignore WordPress.DB
	}

	/**
	 * Rows for the CSV export (same filters as the list, no paging, capped).
	 *
	 * @param array<string, mixed> $filters Raw filters.
	 * @return array<int, array<string, mixed>>
	 */
	public static function export_rows( array $filters ): array {
		if ( ! self::ensure() ) {
			return array();
		}
		global $wpdb;
		$table = self::table();
		$where = self::where( Rules::sanitize_filters( $filters ) );
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table}{$where} ORDER BY last_seen DESC, id DESC LIMIT %d", Rules::ROW_CAP * 2 ), ARRAY_A ); // phpcs:ignore WordPress.DB
		return self::present( (array) $rows );
	}

	/**
	 * Mark notices that were not seen for GONE_DAYS as gone and delete rows past the retention and the cap.
	 * Opportunistic: runs at most every six hours, from a log request or a list request.
	 *
	 * @param bool $force Ignore the throttle (tests).
	 * @return array{gone:int,purged:int}
	 */
	public static function maintenance( bool $force = false ): array {
		$result = array(
			'gone'   => 0,
			'purged' => 0,
		);
		if ( ! $force && false !== get_transient( self::MAINTENANCE_TRANSIENT ) ) {
			return $result;
		}
		if ( ! self::ensure() ) {
			return $result;
		}
		set_transient( self::MAINTENANCE_TRANSIENT, 1, 6 * HOUR_IN_SECONDS );
		global $wpdb;
		$table = self::table();

		/**
		 * Days a notice may be missing before it is marked as gone.
		 *
		 * @param int $days Default 7.
		 */
		$gone_days = max( 1, (int) apply_filters( 'uxstudio_admin_notices_gone_days', Rules::GONE_DAYS ) );
		/**
		 * Days since the last sighting after which a row is deleted.
		 *
		 * @param int $days Default 90.
		 */
		$retention = max( 1, (int) apply_filters( 'uxstudio_admin_notices_retention_days', Rules::RETENTION_DAYS ) );

		$rows = (array) $wpdb->get_results( "SELECT id, state, last_seen FROM {$table} ORDER BY last_seen DESC LIMIT 5000", ARRAY_A ); // phpcs:ignore WordPress.DB
		$gone = Rules::gone_ids( $rows, wp_date( 'Y-m-d H:i:s', Rules::gone_cutoff( time(), $gone_days ) ) );
		foreach ( array_chunk( $gone, 200 ) as $chunk ) {
			$marks                = implode( ',', array_fill( 0, count( $chunk ), '%d' ) );
			$result['gone'] += (int) $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET state = 'gone' WHERE state = 'active' AND id IN ({$marks})", $chunk ) ); // phpcs:ignore WordPress.DB
		}
		$result['purged'] = self::delete_many( Rules::purge_ids( $rows, wp_date( 'Y-m-d H:i:s', time() - $retention * DAY_IN_SECONDS ), Rules::ROW_CAP ) );
		return $result;
	}
}
