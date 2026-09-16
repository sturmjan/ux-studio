<?php
/**
 * Analytics module — self-hosted, cookieless visitor statistics.
 *
 * How it works:
 *  - The frontend sends an async "beacon" (fetch keepalive) after the page has
 *    rendered, so page load is never delayed. It posts to a REST endpoint that
 *    writes into a raw `analytics_hits` buffer table, which only holds a
 *    couple of days' worth of rows.
 *  - A daily cron job (rollup()) merges older days into small daily
 *    aggregates (`analytics_daily`, `_daily_pages`, `_daily_refs`,
 *    `_daily_dims`) and deletes the raw rows, so the buffer never grows
 *    unbounded and queries stay fast (no full-table scans). rollup() is also
 *    called on-demand from summary() so it self-heals even if WP-Cron never
 *    fires.
 *  - Visitors are counted via a daily-rotating hash (IP+UA+day+salt) — the
 *    raw IP is never stored.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\Analytics;

use UxStudio\Core\DB;
use UxStudio\Modules\BaseModule;
use UxStudio\Modules\BotThrottle\Guard as BotThrottleGuard;
use UxStudio\Rest\Controller;
use WP_REST_Request;
use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

final class Module extends BaseModule {

	/** Schema version for this module's own tables. */
	private const DB_VERSION = 1;

	private const ROLLUP_CRON_HOOK = 'ux_studio/analytics_rollup';
	private const GEOIP_CRON_HOOK  = 'ux_studio/analytics_geoip_refresh';

	/** Dimension breakdowns: key => raw table column. */
	private const DIMS = array(
		'device'  => 'device',
		'browser' => 'browser',
		'os'      => 'os',
		'lang'    => 'lang',
		'country' => 'country',
	);

	/** Allowed period keys for the summary switcher. */
	private const PERIODS = array( 'today', 'yesterday', '7d', '14d', '30d', 'month' );

	public function boot(): void {
		add_filter( 'cron_schedules', array( $this, 'register_cron_schedule' ) ); // phpcs:ignore WordPress.WP.CronInterval

		DB::ensure_module_tables(
			'analytics',
			self::DB_VERSION,
			function ( int $from ): void {
				global $wpdb;
				$charset = $wpdb->get_charset_collate();
				$prefix  = $wpdb->prefix . 'uxstudio_analytics_';

				dbDelta(
					"CREATE TABLE {$prefix}hits (
						id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
						path VARCHAR(255) NOT NULL,
						referer_host VARCHAR(120) NULL,
						visitor_hash CHAR(64) NULL,
						device VARCHAR(10) NULL,
						browser VARCHAR(24) NULL,
						os VARCHAR(16) NULL,
						lang VARCHAR(8) NULL,
						country CHAR(2) NULL,
						day DATE NOT NULL,
						ts DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
						PRIMARY KEY  (id),
						KEY day (day),
						KEY path (path)
					) {$charset};"
				);

				dbDelta(
					"CREATE TABLE {$prefix}daily (
						day DATE NOT NULL,
						views INT UNSIGNED NOT NULL DEFAULT 0,
						visitors INT UNSIGNED NOT NULL DEFAULT 0,
						PRIMARY KEY  (day)
					) {$charset};"
				);

				dbDelta(
					"CREATE TABLE {$prefix}daily_pages (
						day DATE NOT NULL,
						path VARCHAR(190) NOT NULL,
						views INT UNSIGNED NOT NULL DEFAULT 0,
						visitors INT UNSIGNED NOT NULL DEFAULT 0,
						PRIMARY KEY  (day, path)
					) {$charset};"
				);

				dbDelta(
					"CREATE TABLE {$prefix}daily_refs (
						day DATE NOT NULL,
						referer_host VARCHAR(120) NOT NULL,
						views INT UNSIGNED NOT NULL DEFAULT 0,
						visitors INT UNSIGNED NOT NULL DEFAULT 0,
						PRIMARY KEY  (day, referer_host)
					) {$charset};"
				);

				dbDelta(
					"CREATE TABLE {$prefix}daily_dims (
						day DATE NOT NULL,
						dim VARCHAR(12) NOT NULL,
						val VARCHAR(48) NOT NULL,
						views INT UNSIGNED NOT NULL DEFAULT 0,
						visitors INT UNSIGNED NOT NULL DEFAULT 0,
						PRIMARY KEY  (day, dim, val)
					) {$charset};"
				);
			}
		);

		add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );
		add_action( 'wp_footer', array( $this, 'beacon' ) );
		add_filter( 'uxstudio_rest_public_routes', array( $this, 'allow_public_hit_route' ) );

		add_action( self::ROLLUP_CRON_HOOK, array( $this, 'rollup' ) );
		if ( ! wp_next_scheduled( self::ROLLUP_CRON_HOOK ) ) {
			wp_schedule_event( time(), 'daily', self::ROLLUP_CRON_HOOK );
		}

		add_action( self::GEOIP_CRON_HOOK, array( $this, 'geoip_refresh' ) );
		if ( ! wp_next_scheduled( self::GEOIP_CRON_HOOK ) ) {
			wp_schedule_event( time(), 'uxstudio_analytics_weekly', self::GEOIP_CRON_HOOK );
		}
	}

	/**
	 * WP core only ships hourly/twicedaily/daily; add a weekly interval for
	 * the GeoIP refresh (its handler itself checks staleness, so a weekly
	 * poll is cheap even when nothing needs downloading).
	 *
	 * @param array $schedules Existing cron schedules.
	 */
	public function register_cron_schedule( array $schedules ): array {
		$schedules['uxstudio_analytics_weekly'] = array(
			'interval' => WEEK_IN_SECONDS,
			'display'  => __( 'Once Weekly', 'ux-studio' ),
		);
		return $schedules;
	}

	/**
	 * Keep the beacon reachable when Security Optimization restricts the REST
	 * API to logged-in users: visitors sending a hit are anonymous by
	 * definition. The route already has its own rate limit, not session auth.
	 *
	 * @param mixed $routes Route prefixes already registered as public.
	 */
	public function allow_public_hit_route( $routes ): array {
		$routes   = is_array( $routes ) ? $routes : array();
		$routes[] = '/uxstudio/v1/analytics/hit';
		return $routes;
	}

	/**
	 * Register the module REST controller.
	 */
	public function register_rest_routes(): void {
		( new RestController( $this ) )->register_routes();
	}

	/**
	 * REST controller class.
	 */
	public function rest_controller(): ?string {
		return RestController::class;
	}

	/** Cron: refresh the GeoIP database when enabled and missing/stale (>30 days). */
	public function geoip_refresh(): void {
		if ( ! GeoIp::is_enabled() ) {
			return;
		}
		$updated = strtotime( (string) ( GeoIp::status()['updated'] ?? '' ) );
		if ( ! GeoIp::is_ready() || ! $updated || ( time() - $updated ) > 30 * DAY_IN_SECONDS ) {
			GeoIp::update();
		}
	}

	private function table( string $name = 'hits' ): string {
		global $wpdb;
		return $wpdb->prefix . 'uxstudio_analytics_' . $name;
	}

	/** GeoIP status (admin-only). */
	public function geoip_get(): array {
		$s = GeoIp::status();
		return array(
			'enabled' => GeoIp::is_enabled(),
			'ready'   => GeoIp::is_ready(),
			'rows'    => (int) ( $s['rows'] ?? 0 ),
			'updated' => (string) ( $s['updated'] ?? '' ),
		);
	}

	/**
	 * Enable/download or disable the GeoIP database.
	 *
	 * @return array{ok:bool,message:string}
	 */
	public function geoip_set( string $action ): array {
		if ( 'disable' === $action ) {
			GeoIp::set_enabled( false );
			return array( 'ok' => true, 'message' => '' );
		}

		list( $ok, $msg ) = GeoIp::update();
		return array( 'ok' => $ok, 'message' => $msg );
	}

	/** Record a (cookieless, public) page view into the buffer. */
	public function hit( WP_REST_Request $req ): WP_REST_Response {
		global $wpdb;

		// Cheapest check first, and shared with the rest of the site: Bot
		// Throttle's Guard rejects an already-banned IP on a single transient
		// read, before any DB write or GeoIP lookup runs, and owns the
		// ban log + throttled admin email. A real bot hammering this endpoint
		// costs almost nothing once caught.
		//
		// The hourly cap below is a generous fallback specific to this endpoint,
		// not bot defense - it's keyed by IP, and a single IP can be a whole
		// office or a mobile carrier's CGNAT pool of real visitors, so it stays
		// high enough to not quietly under-count their stats.
		if (
			BotThrottleGuard::exceeded( 'analytics_hit', 20, 10, 15 * MINUTE_IN_SECONDS )
			|| RateLimit::exceeded( 'hit', 600 )
		) {
			return new WP_REST_Response( array( 'ok' => true ), 200 ); // Silently drop.
		}

		$path = (string) $req->get_param( 'path' );
		$path = '/' . ltrim( sanitize_text_field( wp_parse_url( $path, PHP_URL_PATH ) ?: $path ), '/' );
		$path = substr( $path, 0, 255 );

		$ref  = (string) $req->get_param( 'ref' );
		$host = $ref ? sanitize_text_field( (string) wp_parse_url( $ref, PHP_URL_HOST ) ) : '';

		// Other dimensions are derived server-side from headers (raw UA/IP are
		// never stored).
		$ua      = isset( $_SERVER['HTTP_USER_AGENT'] ) ? (string) $_SERVER['HTTP_USER_AGENT'] : ''; // phpcs:ignore
		$al      = isset( $_SERVER['HTTP_ACCEPT_LANGUAGE'] ) ? (string) $_SERVER['HTTP_ACCEPT_LANGUAGE'] : ''; // phpcs:ignore
		$country = GeoIp::lookup( ClientIp::get() );

		$wpdb->insert(
			$this->table(),
			array(
				'path'         => $path,
				'referer_host' => $host ?: null,
				'visitor_hash' => $this->visitor_hash(),
				'device'       => self::ua_device( $ua ),
				'browser'      => self::ua_browser( $ua ),
				'os'           => self::ua_os( $ua ),
				'lang'         => self::lang_primary( $al ),
				'country'      => '' !== $country ? $country : null,
				'day'          => current_time( 'Y-m-d' ),
				'ts'           => current_time( 'mysql', true ),
			)
		);
		return new WP_REST_Response( array( 'ok' => true ), 200 );
	}

	/** Device type from the User-Agent. */
	private static function ua_device( string $ua ): string {
		if ( '' === $ua ) {
			return 'unknown';
		}
		if ( preg_match( '/iPad|Tablet|PlayBook|Silk|Kindle/i', $ua ) ) {
			return 'tablet';
		}
		if ( preg_match( '/Mobi|iPhone|iPod|Android.*Mobile|Windows Phone|Opera Mini/i', $ua ) ) {
			return 'mobile';
		}
		return 'desktop';
	}

	/** Browser from the User-Agent (comparison order matters). */
	private static function ua_browser( string $ua ): string {
		$map = array(
			'Edg'            => 'Edge',
			'OPR'            => 'Opera',
			'Opera'          => 'Opera',
			'SamsungBrowser' => 'Samsung Internet',
			'YaBrowser'      => 'Yandex',
			'Firefox'        => 'Firefox',
			'Chrome'         => 'Chrome',
			'CriOS'          => 'Chrome',
			'Safari'         => 'Safari',
			'MSIE'           => 'Internet Explorer',
			'Trident'        => 'Internet Explorer',
		);
		foreach ( $map as $needle => $name ) {
			if ( false !== stripos( $ua, $needle ) ) {
				return $name;
			}
		}
		return 'other';
	}

	/** Operating system from the User-Agent. */
	private static function ua_os( string $ua ): string {
		if ( preg_match( '/Windows/i', $ua ) ) {
			return 'Windows';
		}
		if ( preg_match( '/Android/i', $ua ) ) {
			return 'Android';
		}
		if ( preg_match( '/iPhone|iPad|iPod|iOS/i', $ua ) ) {
			return 'iOS';
		}
		if ( preg_match( '/Mac OS X|Macintosh/i', $ua ) ) {
			return 'macOS';
		}
		if ( preg_match( '/Linux/i', $ua ) ) {
			return 'Linux';
		}
		return 'other';
	}

	/** Primary language from the Accept-Language header (e.g. "cs-CZ,cs;q=0.9" -> "cs"). */
	private static function lang_primary( string $al ): string {
		if ( '' === $al ) {
			return 'unknown';
		}
		$first = strtolower( trim( explode( ',', $al )[0] ) );
		$first = explode( ';', $first )[0];
		$first = explode( '-', $first )[0];
		$first = preg_replace( '/[^a-z]/', '', $first );
		return ( '' === $first ) ? 'unknown' : substr( $first, 0, 8 );
	}

	/** Daily-rotating, anonymous visitor fingerprint (no raw IP stored). */
	private function visitor_hash(): string {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : ''; // phpcs:ignore
		$ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? (string) $_SERVER['HTTP_USER_AGENT'] : ''; // phpcs:ignore
		return hash( 'sha256', $ip . '|' . $ua . '|' . current_time( 'Y-m-d' ) . '|' . wp_salt() );
	}

	/**
	 * Batch aggregation: merges every day older than today from the raw buffer
	 * into the daily aggregates and deletes the raw rows. Idempotent
	 * (ON DUPLICATE KEY) and self-healing — also called from summary() so it
	 * works even without a functioning WP-Cron.
	 */
	public function rollup(): void {
		global $wpdb;

		$raw    = $this->table( 'hits' );
		$daily  = $this->table( 'daily' );
		$dpages = $this->table( 'daily_pages' );
		$drefs  = $this->table( 'daily_refs' );
		$dims   = $this->table( 'daily_dims' );
		$today  = current_time( 'Y-m-d' );

		$days = $wpdb->get_col(
			$wpdb->prepare( "SELECT DISTINCT day FROM {$raw} WHERE day < %s ORDER BY day LIMIT 90", $today ) // phpcs:ignore
		);
		if ( ! $days ) {
			return;
		}

		foreach ( $days as $day ) {
			$wpdb->query(
				$wpdb->prepare(
					"INSERT INTO {$daily} (day, views, visitors)
					 SELECT %s, COUNT(*), COUNT(DISTINCT visitor_hash) FROM {$raw} WHERE day = %s
					 ON DUPLICATE KEY UPDATE views = VALUES(views), visitors = VALUES(visitors)", // phpcs:ignore
					$day,
					$day
				)
			);

			$wpdb->query(
				$wpdb->prepare(
					"INSERT INTO {$dpages} (day, path, views, visitors)
					 SELECT %s, LEFT(path,190), COUNT(*), COUNT(DISTINCT visitor_hash) FROM {$raw} WHERE day = %s GROUP BY LEFT(path,190)
					 ON DUPLICATE KEY UPDATE views = VALUES(views), visitors = VALUES(visitors)", // phpcs:ignore
					$day,
					$day
				)
			);

			$wpdb->query(
				$wpdb->prepare(
					"INSERT INTO {$drefs} (day, referer_host, views, visitors)
					 SELECT %s, referer_host, COUNT(*), COUNT(DISTINCT visitor_hash) FROM {$raw}
					 WHERE day = %s AND referer_host IS NOT NULL AND referer_host <> '' GROUP BY referer_host
					 ON DUPLICATE KEY UPDATE views = VALUES(views), visitors = VALUES(visitors)", // phpcs:ignore
					$day,
					$day
				)
			);

			foreach ( self::DIMS as $dim => $col ) {
				$wpdb->query(
					$wpdb->prepare(
						"INSERT INTO {$dims} (day, dim, val, views, visitors)
						 SELECT %s, %s, COALESCE(NULLIF({$col}, ''), '—'), COUNT(*), COUNT(DISTINCT visitor_hash)
						 FROM {$raw} WHERE day = %s GROUP BY COALESCE(NULLIF({$col}, ''), '—')
						 ON DUPLICATE KEY UPDATE views = VALUES(views), visitors = VALUES(visitors)", // phpcs:ignore
						$day,
						$dim,
						$day
					)
				);
			}

			$wpdb->query( $wpdb->prepare( "DELETE FROM {$raw} WHERE day = %s", $day ) ); // phpcs:ignore
		}
	}

	/**
	 * Visitor summary for the admin (cards + chart + rankings). Fast: history
	 * comes from the daily aggregates, today comes from the buffer.
	 */
	public function summary( WP_REST_Request $req ): array {
		global $wpdb;

		// Merge whatever is still sitting in the buffer from older days first
		// (self-heal, works even without cron).
		$this->rollup();

		$today  = current_time( 'Y-m-d' );
		$period = (string) $req->get_param( 'period' );
		$period = in_array( $period, self::PERIODS, true ) ? $period : '7d';

		list( $from, $to ) = $this->period_range( $period, $today );

		// Previous period of equal length, for the % delta.
		$len       = (int) round( ( strtotime( $to ) - strtotime( $from ) ) / DAY_IN_SECONDS ) + 1;
		$prev_to   = gmdate( 'Y-m-d', strtotime( $from . ' -1 day' ) );
		$prev_from = gmdate( 'Y-m-d', strtotime( $from . ' -' . $len . ' days' ) );

		$daily = $this->daily_series( $from, $to, $today );

		$views = array_sum( array_column( $daily, 'views' ) );
		$uniq  = array_sum( array_column( $daily, 'uniq' ) );

		$prev       = $this->daily_series( $prev_from, $prev_to, $today );
		$prev_views = array_sum( array_column( $prev, 'views' ) );
		$prev_uniq  = array_sum( array_column( $prev, 'uniq' ) );

		return array(
			'range'      => array(
				'from'   => $from,
				'to'     => $to,
				'period' => $period,
				'days'   => $len,
			),
			'totals'     => array( 'views' => $views, 'visitors' => $uniq ),
			'previous'   => array( 'views' => $prev_views, 'visitors' => $prev_uniq ),
			'realtime'   => $this->realtime(),
			'daily'      => $daily,
			'top_pages'  => $this->top_dimension( $this->table( 'daily_pages' ), 'path', $from, $to, $today, 15 ),
			'referers'   => $this->top_dimension( $this->table( 'daily_refs' ), 'referer_host', $from, $to, $today, 15, true ),
			'breakdowns' => $this->breakdowns( $from, $to, $today ),
		);
	}

	/** Converts a period key into a [from, to] range (Y-m-d, inclusive). */
	private function period_range( string $period, string $today ): array {
		switch ( $period ) {
			case 'today':
				return array( $today, $today );
			case 'yesterday':
				$y = gmdate( 'Y-m-d', strtotime( $today . ' -1 day' ) );
				return array( $y, $y );
			case '14d':
				return array( gmdate( 'Y-m-d', strtotime( $today . ' -13 days' ) ), $today );
			case '30d':
				return array( gmdate( 'Y-m-d', strtotime( $today . ' -29 days' ) ), $today );
			case 'month':
				return array( gmdate( 'Y-m-01', strtotime( $today ) ), $today );
			case '7d':
			default:
				return array( gmdate( 'Y-m-d', strtotime( $today . ' -6 days' ) ), $today );
		}
	}

	/** Views over the last hour (from today's buffer). */
	private function realtime(): int {
		global $wpdb;
		$since = gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS );
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$this->table('hits')} WHERE ts >= %s", $since ) ); // phpcs:ignore
	}

	/**
	 * Daily series [{day, views, uniq}] across the range, zero-filled.
	 * History from the daily aggregate, today from the buffer.
	 */
	private function daily_series( string $from, string $to, string $today ): array {
		global $wpdb;
		$raw   = $this->table( 'hits' );
		$daily = $this->table( 'daily' );

		$map     = array();
		$hist_to = ( $to >= $today ) ? gmdate( 'Y-m-d', strtotime( $today . ' -1 day' ) ) : $to;
		if ( $from <= $hist_to ) {
			$rows = $wpdb->get_results(
				$wpdb->prepare( "SELECT day, views, visitors FROM {$daily} WHERE day >= %s AND day <= %s", $from, $hist_to ) // phpcs:ignore
			);
			foreach ( $rows as $r ) {
				$map[ $r->day ] = array( 'views' => (int) $r->views, 'uniq' => (int) $r->visitors );
			}
		}
		if ( $to >= $today && $from <= $today ) {
			$map[ $today ] = array(
				'views' => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$raw} WHERE day = %s", $today ) ), // phpcs:ignore
				'uniq'  => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(DISTINCT visitor_hash) FROM {$raw} WHERE day = %s", $today ) ), // phpcs:ignore
			);
		}

		$series = array();
		for ( $d = $from; $d <= $to; $d = gmdate( 'Y-m-d', strtotime( $d . ' +1 day' ) ) ) {
			$series[] = array(
				'day'   => $d,
				'views' => $map[ $d ]['views'] ?? 0,
				'uniq'  => $map[ $d ]['uniq'] ?? 0,
			);
		}
		return $series;
	}

	/**
	 * Ranking for a dimension (pages/referers) with visitors and views across
	 * the range. History from the aggregate + today from the buffer.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function top_dimension( string $agg_table, string $col, string $from, string $to, string $today, int $limit, bool $skip_empty = false ): array {
		global $wpdb;
		$raw = $this->table( 'hits' );

		$acc     = array(); // key => [views, visitors]
		$hist_to = ( $to >= $today ) ? gmdate( 'Y-m-d', strtotime( $today . ' -1 day' ) ) : $to;

		if ( $from <= $hist_to ) {
			$rows = $wpdb->get_results(
				$wpdb->prepare( "SELECT {$col} AS k, SUM(views) AS v, SUM(visitors) AS u FROM {$agg_table} WHERE day >= %s AND day <= %s GROUP BY {$col}", $from, $hist_to ) // phpcs:ignore
			);
			foreach ( $rows as $r ) {
				$acc[ (string) $r->k ] = array( (int) $r->v, (int) $r->u );
			}
		}

		if ( $to >= $today && $from <= $today ) {
			$sel = ( 'path' === $col ) ? 'LEFT(path,190)' : $col;
			$sql = "SELECT {$sel} AS k, COUNT(*) AS v, COUNT(DISTINCT visitor_hash) AS u FROM {$raw} WHERE day = %s";
			if ( 'referer_host' === $col ) {
				$sql .= " AND referer_host IS NOT NULL AND referer_host <> ''";
			}
			$sql  .= " GROUP BY {$sel}";
			$trows = $wpdb->get_results( $wpdb->prepare( $sql, $today ) ); // phpcs:ignore
			foreach ( $trows as $r ) {
				$k         = (string) $r->k;
				$acc[ $k ] = array(
					( $acc[ $k ][0] ?? 0 ) + (int) $r->v,
					( $acc[ $k ][1] ?? 0 ) + (int) $r->u,
				);
			}
		}

		uasort( $acc, static fn( $a, $b ) => $b[0] <=> $a[0] );

		$out = array();
		foreach ( $acc as $k => $vu ) {
			if ( $skip_empty && '' === $k ) {
				continue;
			}
			$out[] = array( $col => $k, 'views' => $vu[0], 'visitors' => $vu[1] );
			if ( count( $out ) >= $limit ) {
				break;
			}
		}
		return $out;
	}

	/**
	 * Breakdowns for every dimension (device/browser/os/lang/country) across
	 * the range.
	 *
	 * @return array<string,array<int,array<string,mixed>>>
	 */
	private function breakdowns( string $from, string $to, string $today ): array {
		$out = array();
		foreach ( self::DIMS as $dim => $col ) {
			$rows = $this->dim_breakdown( $dim, $col, $from, $to, $today, 8 );
			if ( 'country' === $dim ) {
				$rows = array_values(
					array_filter( $rows, static fn( $r ) => '—' !== $r['val'] )
				);
			}
			$out[ $dim ] = $rows;
		}
		return $out;
	}

	/**
	 * Ranking for a single dimension (dims aggregate + today's buffer), sorted
	 * by visitors.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function dim_breakdown( string $dim, string $col, string $from, string $to, string $today, int $limit ): array {
		global $wpdb;
		$raw  = $this->table( 'hits' );
		$dims = $this->table( 'daily_dims' );

		$acc     = array(); // val => [views, visitors]
		$hist_to = ( $to >= $today ) ? gmdate( 'Y-m-d', strtotime( $today . ' -1 day' ) ) : $to;

		if ( $from <= $hist_to ) {
			$rows = $wpdb->get_results(
				$wpdb->prepare( "SELECT val, SUM(views) v, SUM(visitors) u FROM {$dims} WHERE dim = %s AND day >= %s AND day <= %s GROUP BY val", $dim, $from, $hist_to ) // phpcs:ignore
			);
			foreach ( $rows as $r ) {
				$acc[ (string) $r->val ] = array( (int) $r->v, (int) $r->u );
			}
		}

		if ( $to >= $today && $from <= $today ) {
			$trows = $wpdb->get_results(
				$wpdb->prepare( "SELECT COALESCE(NULLIF({$col}, ''), '—') val, COUNT(*) v, COUNT(DISTINCT visitor_hash) u FROM {$raw} WHERE day = %s GROUP BY COALESCE(NULLIF({$col}, ''), '—')", $today ) // phpcs:ignore
			);
			foreach ( $trows as $r ) {
				$k         = (string) $r->val;
				$acc[ $k ] = array(
					( $acc[ $k ][0] ?? 0 ) + (int) $r->v,
					( $acc[ $k ][1] ?? 0 ) + (int) $r->u,
				);
			}
		}

		uasort( $acc, static fn( $a, $b ) => $b[1] <=> $a[1] );

		$out = array();
		foreach ( $acc as $val => $vu ) {
			$out[] = array( 'val' => $val, 'views' => $vu[0], 'visitors' => $vu[1] );
			if ( count( $out ) >= $limit ) {
				break;
			}
		}
		return $out;
	}

	/** Cookieless frontend beacon (no consent required). */
	public function beacon(): void {
		if ( is_admin() ) {
			return;
		}
		$url = esc_url( rest_url( Controller::NS . '/analytics/hit' ) );
		?>
<script>
( function () {
	try {
		// Guard against F5-spam: don't count the same page twice within 10s
		// (the server also rate-limits, and a unique visitor only counts once/day).
		var path = location.pathname;
		var key = 'uxstudio_a_' + path;
		var now = Date.now();
		try {
			var last = +( sessionStorage.getItem( key ) || 0 );
			if ( now - last < 10000 ) {
				return;
			}
			sessionStorage.setItem( key, String( now ) );
		} catch ( e ) {}

		fetch( <?php echo wp_json_encode( $url ); ?>, {
			method: 'POST',
			headers: { 'Content-Type': 'application/json' },
			body: JSON.stringify( { path: path, ref: document.referrer } ),
			keepalive: true,
		} ).catch( function () {} );
	} catch ( e ) {}
} )();
</script>
		<?php
	}
}
