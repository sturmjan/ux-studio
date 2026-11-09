<?php
/**
 * Consent records: the proof of consent (GDPR art. 7(1)).
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\Consent;

use UxStudio\Core\Csv;
use UxStudio\Core\DB;
use UxStudio\Core\Retention;
use UxStudio\Core\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * PLAN.md section 42, phase F5. One row per decision of a visitor: an anonymous random id made by the browser (it
 * also sits in the `uxs_consent` cookie, so the same visitor's later changes share it), the time, the version of the
 * policy, the categories (bits 1 preferences, 2 statistics, 4 marketing, like the cookie), the kind of action and the
 * path of the page. Nothing else: no IP address, no user agent, no query string.
 *
 * The pure parts (clean(), normalize_id(), retention_days(), csv_row()) need no WordPress and are tested on their own.
 */
final class Records {

	/** Table name without the prefix. */
	public const TABLE = 'uxstudio_consent_log';

	/** Schema version of the table (DB::ensure_module_tables). */
	public const DB_VERSION = 1;

	/** Daily purge event. */
	public const CRON_HOOK = 'uxstudio_consent_log_purge';

	/** Kinds of action a record can have. */
	public const ACTIONS = array( 'accept_all', 'reject_all', 'custom', 'withdraw' );

	/** Default retention: 3 years. */
	public const DEFAULT_DAYS = 1095;

	/** Shortest and longest retention the setting can have. */
	public const MIN_DAYS = 30;
	public const MAX_DAYS = 3650;

	/** Rows per page of an export. */
	private const EXPORT_BATCH = 1000;

	/**
	 * Hooks of the module: table, purge cron, public and admin routes, export, dashboard widget.
	 */
	public static function boot(): void {
		DB::ensure_module_tables( 'consent', self::DB_VERSION, array( self::class, 'migrate' ) );

		add_action( 'rest_api_init', array( RecordsRest::class, 'register' ) );
		add_action( 'admin_post_uxstudio_consent_export', array( RecordsRest::class, 'export_csv' ) );
		add_action( self::CRON_HOOK, array( self::class, 'purge' ) );
		Retention::ensure_scheduled( self::CRON_HOOK );

		if ( is_admin() ) {
			RecordsWidget::register();
		}
	}

	/** Remove the purge event (module switched off). The data stays. */
	public static function unschedule(): void {
		Retention::unschedule( self::CRON_HOOK );
	}

	/** Full table name. */
	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . self::TABLE;
	}

	/**
	 * Create the table. Called by DB::ensure_module_tables() (and by tests).
	 *
	 * @param int $from Installed schema version.
	 */
	public static function migrate( int $from = 0 ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$table   = self::table();
		$charset = $wpdb->get_charset_collate();
		dbDelta(
			"CREATE TABLE {$table} (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				consent_id CHAR(32) NOT NULL,
				created_at DATETIME NOT NULL,
				policy_version SMALLINT UNSIGNED NOT NULL DEFAULT 1,
				categories TINYINT UNSIGNED NOT NULL DEFAULT 0,
				action VARCHAR(12) NOT NULL DEFAULT '',
				page_path VARCHAR(255) NULL,
				PRIMARY KEY  (id),
				KEY consent_id (consent_id),
				KEY created_at (created_at)
			) {$charset};"
		);
	}

	/* ---------- settings ---------- */

	/** Are decisions recorded? (Setting `log_enabled`, on by default.) */
	public static function enabled(): bool {
		return (bool) ( new Settings( 'uxstudio_consent' ) )->get( 'log_enabled', true );
	}

	/** Retention in days from the stored setting. */
	public static function configured_days(): int {
		return self::retention_days( ( new Settings( 'uxstudio_consent' ) )->get( 'log_retention_days', self::DEFAULT_DAYS ) );
	}

	/**
	 * Clamp a stored retention value. Not numeric or 0 means the default, never "keep forever".
	 *
	 * @param mixed $raw Stored value.
	 */
	public static function retention_days( $raw ): int {
		$days = is_numeric( $raw ) ? (int) $raw : 0;
		if ( $days <= 0 ) {
			return self::DEFAULT_DAYS;
		}
		return max( self::MIN_DAYS, min( self::MAX_DAYS, $days ) );
	}

	/** Daily cron: delete records older than the retention. */
	public static function purge(): int {
		return Retention::purge( self::TABLE, self::configured_days(), 'created_at', true );
	}

	/* ---------- validation (pure) ---------- */

	/**
	 * Normalise a consent id typed by a person (upper case, dashes, spaces) to the stored form, or '' when it is not one.
	 *
	 * @param string $raw Text.
	 */
	public static function normalize_id( string $raw ): string {
		$id = strtolower( (string) preg_replace( '/[\s-]+/', '', $raw ) );
		return 1 === preg_match( '/^[a-f0-9]{32}$/', $id ) ? $id : '';
	}

	/**
	 * Path of a page without host, query string and fragment, or null when it is not a usable path.
	 *
	 * @param mixed $raw Value sent by the browser.
	 */
	public static function clean_path( $raw ): ?string {
		if ( ! is_string( $raw ) || '' === $raw || strlen( $raw ) > 2000 || 0 !== strpos( $raw, '/' ) || 0 === strpos( $raw, '//' ) ) {
			return null;
		}
		$path = (string) strtok( (string) strtok( $raw, '?' ), '#' );
		// Only characters of a URL path; anything else (control characters, spaces, brackets) drops the whole value.
		if ( '' === $path || 1 !== preg_match( '#^/[A-Za-z0-9\-._~%/!$&\'()*+,;=:@]*$#', $path ) ) {
			return null;
		}
		return strlen( $path ) > 255 ? substr( $path, 0, 255 ) : $path;
	}

	/**
	 * Validate the parameters of one decision. Strict: any wrong field rejects the whole record.
	 *
	 * @param array<string, mixed> $in id, v (policy version), c (category bits), a (action), p (path, optional).
	 * @return array{consent_id:string,policy_version:int,categories:int,action:string,page_path:?string}|null Clean row or null.
	 */
	public static function clean( array $in ): ?array {
		$id = $in['id'] ?? null;
		if ( ! is_string( $id ) || 1 !== preg_match( '/^[a-f0-9]{32}$/', $id ) ) {
			return null;
		}
		$v = $in['v'] ?? null;
		$c = $in['c'] ?? null;
		if ( ! self::is_int_in( $v, 1, 9999 ) || ! self::is_int_in( $c, 0, 7 ) ) {
			return null;
		}
		$action = $in['a'] ?? null;
		if ( ! is_string( $action ) || ! in_array( $action, self::ACTIONS, true ) ) {
			return null;
		}
		$bits = (int) $c;
		// "Accept all" and "reject all" are fixed by definition; a record that contradicts its action is junk.
		if ( ( 'accept_all' === $action && 7 !== $bits ) || ( 'reject_all' === $action && 0 !== $bits ) ) {
			return null;
		}
		return array(
			'consent_id'     => $id,
			'policy_version' => (int) $v,
			'categories'     => $bits,
			'action'         => $action,
			'page_path'      => self::clean_path( $in['p'] ?? null ),
		);
	}

	/**
	 * Is the value an integer (or a string of digits) inside the range?
	 *
	 * @param mixed $value Value.
	 * @param int   $min   Lowest.
	 * @param int   $max   Highest.
	 */
	private static function is_int_in( $value, int $min, int $max ): bool {
		if ( is_string( $value ) && 1 === preg_match( '/^\d{1,5}$/', $value ) ) {
			$value = (int) $value;
		}
		return is_int( $value ) && $value >= $min && $value <= $max;
	}

	/* ---------- storage ---------- */

	/**
	 * Store a clean record. The same decision sent twice within five minutes (a retry, a double click) is stored once.
	 *
	 * @param array{consent_id:string,policy_version:int,categories:int,action:string,page_path:?string} $row Clean row.
	 * @return bool True when a row was written.
	 */
	public static function insert( array $row ): bool {
		global $wpdb;
		$table = self::table();
		$last  = $wpdb->get_row(
			$wpdb->prepare( "SELECT policy_version, categories, action, created_at FROM {$table} WHERE consent_id = %s ORDER BY id DESC LIMIT 1", $row['consent_id'] ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);
		if ( is_array( $last )
			&& (int) $last['policy_version'] === $row['policy_version']
			&& (int) $last['categories'] === $row['categories']
			&& (string) $last['action'] === $row['action']
			&& strtotime( (string) $last['created_at'] . ' UTC' ) > time() - 300
		) {
			return false;
		}
		return false !== $wpdb->insert(
			$table,
			array(
				'consent_id'     => $row['consent_id'],
				'created_at'     => gmdate( 'Y-m-d H:i:s' ),
				'policy_version' => $row['policy_version'],
				'categories'     => $row['categories'],
				'action'         => $row['action'],
				'page_path'      => $row['page_path'],
			),
			array( '%s', '%s', '%d', '%d', '%s', '%s' )
		);
	}

	/** Does the table exist? (A module that never booted has none.) */
	public static function table_exists(): bool {
		global $wpdb;
		$table = self::table();
		return $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
	}

	/**
	 * Counts for the overview: records per action and per category in the last N days.
	 *
	 * @param int $days Period length.
	 * @return array<string, mixed>
	 */
	public static function summary( int $days ): array {
		global $wpdb;
		$days  = max( 1, min( 3650, $days ) );
		$out   = array(
			'days'       => $days,
			'total'      => 0,
			'visitors'   => 0,
			'actions'    => array_fill_keys( self::ACTIONS, 0 ),
			'categories' => array(
				'preferences' => 0,
				'statistics'  => 0,
				'marketing'   => 0,
			),
			'all_time'   => 0,
			'oldest'     => null,
			'retention'  => self::configured_days(),
			'enabled'    => self::enabled(),
		);
		if ( ! self::table_exists() ) {
			return $out;
		}
		$table = self::table();
		$since = gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );

		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT action, COUNT(*) AS n FROM {$table} WHERE created_at >= %s GROUP BY action", $since ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);
		foreach ( (array) $rows as $r ) {
			if ( isset( $out['actions'][ $r['action'] ] ) ) {
				$out['actions'][ $r['action'] ] = (int) $r['n'];
				$out['total']                  += (int) $r['n'];
			}
		}
		$cat = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT COUNT(DISTINCT consent_id) AS visitors, SUM((categories & 1) > 0) AS p, SUM((categories & 2) > 0) AS s, SUM((categories & 4) > 0) AS m FROM {$table} WHERE created_at >= %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$since
			),
			ARRAY_A
		);
		if ( is_array( $cat ) ) {
			$out['visitors']                  = (int) $cat['visitors'];
			$out['categories']['preferences'] = (int) $cat['p'];
			$out['categories']['statistics']  = (int) $cat['s'];
			$out['categories']['marketing']   = (int) $cat['m'];
		}
		$out['all_time'] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$oldest          = $wpdb->get_var( "SELECT MIN(created_at) FROM {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$out['oldest']   = is_string( $oldest ) ? $oldest : null;
		return $out;
	}

	/**
	 * Every record of one consent id, oldest first.
	 *
	 * @param string $consent_id Normalised id.
	 * @return array<int, array<string, mixed>>
	 */
	public static function find( string $consent_id ): array {
		global $wpdb;
		if ( '' === $consent_id || ! self::table_exists() ) {
			return array();
		}
		$table = self::table();
		$rows  = $wpdb->get_results(
			$wpdb->prepare( "SELECT id, consent_id, created_at, policy_version, categories, action, page_path FROM {$table} WHERE consent_id = %s ORDER BY id ASC LIMIT 200", $consent_id ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);
		return array_map( array( self::class, 'shape' ), is_array( $rows ) ? $rows : array() );
	}

	/**
	 * A row as the admin screen and the export show it.
	 *
	 * @param array<string, mixed> $r Database row.
	 * @return array<string, mixed>
	 */
	public static function shape( array $r ): array {
		$bits = (int) ( $r['categories'] ?? 0 );
		return array(
			'id'             => (int) ( $r['id'] ?? 0 ),
			'consent_id'     => (string) ( $r['consent_id'] ?? '' ),
			'created_at'     => (string) ( $r['created_at'] ?? '' ),
			'policy_version' => (int) ( $r['policy_version'] ?? 0 ),
			'preferences'    => (bool) ( $bits & 1 ),
			'statistics'     => (bool) ( $bits & 2 ),
			'marketing'      => (bool) ( $bits & 4 ),
			'action'         => (string) ( $r['action'] ?? '' ),
			'page_path'      => (string) ( $r['page_path'] ?? '' ),
		);
	}

	/* ---------- export ---------- */

	/**
	 * Header of the CSV.
	 *
	 * @return string[]
	 */
	public static function csv_header(): array {
		return array(
			__( 'Record', 'ux-studio' ),
			__( 'Consent ID', 'ux-studio' ),
			__( 'Time (UTC)', 'ux-studio' ),
			__( 'Policy version', 'ux-studio' ),
			__( 'Preferences', 'ux-studio' ),
			__( 'Statistics', 'ux-studio' ),
			__( 'Marketing', 'ux-studio' ),
			__( 'Action', 'ux-studio' ),
			__( 'Page', 'ux-studio' ),
		);
	}

	/**
	 * One CSV line (formula-injection safe: the path comes from a public request).
	 *
	 * @param array<string, mixed> $r Database row.
	 * @return string[]
	 */
	public static function csv_row( array $r ): array {
		$s = self::shape( $r );
		return Csv::safe_row(
			array(
				(string) $s['id'],
				$s['consent_id'],
				$s['created_at'],
				(string) $s['policy_version'],
				$s['preferences'] ? 'yes' : 'no',
				$s['statistics'] ? 'yes' : 'no',
				$s['marketing'] ? 'yes' : 'no',
				$s['action'],
				$s['page_path'],
			)
		);
	}

	/**
	 * Write the records as CSV to a stream, in pages of rows so a large table never sits in memory.
	 *
	 * @param resource $out  Open stream.
	 * @param string   $from Lowest date (Y-m-d) or ''.
	 * @param string   $to   Highest date (Y-m-d) or ''.
	 * @param string   $id   Normalised consent id or ''.
	 * @return int Rows written.
	 */
	public static function stream_csv( $out, string $from = '', string $to = '', string $id = '' ): int {
		global $wpdb;
		fputcsv( $out, self::csv_header() ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fputcsv
		if ( ! self::table_exists() ) {
			return 0;
		}
		$table = self::table();
		$where = array( 'id > %d' );
		$args  = array( 0 );
		if ( 1 === preg_match( '/^\d{4}-\d{2}-\d{2}$/', $from ) ) {
			$where[] = 'created_at >= %s';
			$args[]  = $from . ' 00:00:00';
		}
		if ( 1 === preg_match( '/^\d{4}-\d{2}-\d{2}$/', $to ) ) {
			$where[] = 'created_at <= %s';
			$args[]  = $to . ' 23:59:59';
		}
		if ( '' !== $id ) {
			$where[] = 'consent_id = %s';
			$args[]  = $id;
		}
		$count = 0;
		$last  = 0;
		do {
			$args[0] = $last;
			$rows    = $wpdb->get_results(
				$wpdb->prepare( "SELECT id, consent_id, created_at, policy_version, categories, action, page_path FROM {$table} WHERE " . implode( ' AND ', $where ) . ' ORDER BY id ASC LIMIT ' . self::EXPORT_BATCH, $args ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
				ARRAY_A
			);
			$rows    = is_array( $rows ) ? $rows : array();
			foreach ( $rows as $r ) {
				fputcsv( $out, self::csv_row( $r ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fputcsv
				$last = (int) $r['id'];
				++$count;
			}
		} while ( count( $rows ) === self::EXPORT_BATCH );
		return $count;
	}
}
