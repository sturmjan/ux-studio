<?php
/**
 * Reusable IP burst-guard, backed by this module's own log + notifier, so
 * other modules protecting a narrow endpoint (e.g. Analytics' beacon) don't
 * need to duplicate rate-limit/ban/notify plumbing.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\BotThrottle;

use UxStudio\Core\ClientIp;
use UxStudio\Core\Settings;

defined( 'ABSPATH' ) || exit;

final class Guard {

	/**
	 * Cheap burst guard: blocks IPs hammering a caller-defined context before
	 * any heavier work happens. Once an IP trips the burst threshold it's
	 * banned outright for $ban_for - further calls short-circuit on a single
	 * read instead of touching the counting bucket at all.
	 *
	 * Respects the Bot Throttle module's own "enabled" setting even when the
	 * module itself never booted (e.g. called from another module) - if the
	 * admin turned Bot Throttle off, none of its infrastructure should act.
	 *
	 * @param string $context      Caller-defined identifier, e.g. 'analytics_hit'.
	 * @param int    $burst_max    Max attempts within the burst window.
	 * @param int    $burst_window Burst window length in seconds.
	 * @param int    $ban_for      How long to ban an IP that trips the burst limit, in seconds.
	 * @return bool True when the IP is (now or already) banned.
	 */
	public static function exceeded( string $context, int $burst_max, int $burst_window, int $ban_for ): bool {
		if ( ! (bool) ( new Settings( 'uxstudio_bot_throttle' ) )->get( 'enabled', true ) ) {
			return false;
		}

		$burst_window = max( 1, $burst_window );
		$ban_key      = self::key( $context . '_ban' );
		$key          = self::key( $context . '_burst' );

		// Storage, cheapest first: APCu (no DB), the module's bucket table (one
		// SELECT + one upsert, no option rows) when Bot Throttle is active and
		// its table therefore exists, otherwise ONE transient holding both the
		// burst counter and the ban (was two transients = four option rows).
		if ( self::has_apcu() ) {
			if ( apcu_fetch( $ban_key ) ) {
				return true;
			}
			$bucket = $key . '_' . intdiv( time(), $burst_window );
			apcu_add( $bucket, 0, $burst_window + 1 );
			if ( (int) apcu_inc( $bucket ) <= $burst_max ) {
				return false;
			}
			apcu_store( $ban_key, 1, $ban_for );
		} elseif ( self::table_available() ) {
			if ( self::table_banned( $ban_key ) ) {
				return true;
			}
			if ( self::table_count( $key, $burst_window ) <= $burst_max ) {
				return false;
			}
			self::table_ban( $ban_key, $ban_for );
		} else {
			$now  = time();
			$data = get_transient( $key );
			if ( is_array( $data ) && ! empty( $data['ban'] ) && $data['ban'] > $now ) {
				return true;
			}
			if ( ! is_array( $data ) || empty( $data['reset'] ) || $data['reset'] <= $now ) {
				$data = array(
					'count' => 0,
					'reset' => $now + $burst_window,
				);
			}
			++$data['count'];
			if ( $data['count'] <= $burst_max ) {
				set_transient( $key, $data, max( 1, $data['reset'] - $now ) );
				return false;
			}
			$data['ban'] = $now + $ban_for;
			set_transient( $key, $data, $ban_for );
		}

		Log::insert(
			array(
				'ip_hash'         => self::hash_ip(),
				'user_agent'      => (string) ( $_SERVER['HTTP_USER_AGENT'] ?? '' ), // phpcs:ignore
				'action'          => 'ban',
				'bot_category'    => 'rate-limit',
				'bot_name'        => $context,
				'tier'            => 'GREEN',
				'delay_ms'        => 0,
				'url'             => (string) ( $_SERVER['REQUEST_URI'] ?? '' ), // phpcs:ignore
				'load_score'      => 0,
				'response_status' => 429,
				'report_hash'     => self::report_hash(),
			)
		);

		/**
		 * Fires once, when an IP newly trips a burst limit (not on every
		 * subsequent request while it stays banned). Never carries the raw
		 * IP, only its salted hash (GDPR - no raw IP storage/transmission).
		 *
		 * @param string $context Caller-defined identifier.
		 * @param string $ip_hash Salted hash of the banned IP.
		 * @param int    $ban_for Ban duration in seconds.
		 */
		do_action( 'ux_studio/bot_throttle/ip_banned', $context, self::hash_ip(), $ban_for );

		return true;
	}

	private static function has_apcu(): bool {
		return function_exists( 'apcu_inc' ) && function_exists( 'apcu_enabled' ) && apcu_enabled();
	}

	/**
	 * The bucket table exists once the module booted (ensure_module_tables),
	 * i.e. whenever Bot Throttle is an enabled module (autoloaded option).
	 */
	private static function table_available(): bool {
		return in_array( 'bot-throttle', (array) get_option( 'uxstudio_active_modules', array() ), true );
	}

	private static function table(): string {
		global $wpdb;
		return "{$wpdb->prefix}uxstudio_bot_throttle_buckets";
	}

	/**
	 * A ban is a bucket row whose window_start is the ban EXPIRY (future), so
	 * the module's regular "window_start < -1 hour" pruning drops it later.
	 *
	 * @param string $ban_key Scoped key.
	 */
	private static function table_banned( string $ban_key ): bool {
		global $wpdb;
		$table = self::table();
		return (bool) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT 1 FROM {$table} WHERE ip_hash = %s AND window_start > %s LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$ban_key,
				gmdate( 'Y-m-d H:i:s' )
			)
		);
	}

	/**
	 * Increment the fixed burst window and return the new count (one query:
	 * a fresh row affects 1 row = count 1, an update hands the new count back
	 * through LAST_INSERT_ID(expr) as insert_id).
	 *
	 * @param string $key    Scoped key.
	 * @param int    $window Window length in seconds.
	 */
	private static function table_count( string $key, int $window ): int {
		global $wpdb;
		$table    = self::table();
		$now      = time();
		$affected = $wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$table} (ip_hash, window_start, request_count) VALUES (%s, %s, 1)
				ON DUPLICATE KEY UPDATE request_count = LAST_INSERT_ID(request_count + 1)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$key,
				gmdate( 'Y-m-d H:i:s', $now - ( $now % $window ) )
			)
		);
		return 1 === (int) $affected ? 1 : (int) $wpdb->insert_id;
	}

	/**
	 * @param string $ban_key Scoped key.
	 * @param int    $ban_for Ban length in seconds.
	 */
	private static function table_ban( string $ban_key, int $ban_for ): void {
		global $wpdb;
		$table = self::table();
		$wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO {$table} (ip_hash, window_start, request_count) VALUES (%s, %s, 0)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$ban_key,
				gmdate( 'Y-m-d H:i:s', time() + max( 1, $ban_for ) )
			)
		);
	}

	/**
	 * Storage key, scoped by client IP and caller context (fits the bucket
	 * table's 64-char ip_hash column).
	 */
	private static function key( string $scope ): string {
		return 'uxstudio_bt_guard_' . substr( hash( 'sha256', $scope . '|' . self::client_ip() . '|' . wp_salt() ), 0, 40 );
	}

	/**
	 * Client IP via the shared resolver (same as Module::client_ip()): proxy
	 * headers only count from Cloudflare / configured trusted proxies, so a
	 * client can't spoof its way past the ban.
	 */
	private static function client_ip(): string {
		$ip = ClientIp::get( 'auto' );
		return '' !== $ip ? $ip : '0.0.0.0';
	}

	/**
	 * Salted hash of the current client IP, safe to log/email (GDPR - no raw IP).
	 */
	private static function hash_ip(): string {
		return hash( 'sha256', self::client_ip() . wp_salt() );
	}

	/**
	 * Keyed hash of the current client IP for the central app to correlate a
	 * repeat-offender IP across every site in the fleet - unlike hash_ip()
	 * (salted with this site's own wp_salt(), so the same real IP hashes
	 * differently per site), this uses a secret shared across the whole
	 * fleet, so the SAME real IP produces the SAME hash on every site. Still
	 * never the raw IP. Must be computed here, at ban time, since the log
	 * row is the only place the raw IP is briefly available - it can't be
	 * derived later from the already-stored (differently salted) ip_hash.
	 *
	 * Empty string (never reported) when no fleet secret is configured.
	 */
	private static function report_hash(): string {
		$secret = (string) ( new Settings( 'uxstudio_bot_throttle' ) )->get( 'central_report_secret', '' );
		if ( '' === $secret ) {
			return '';
		}
		return hash_hmac( 'sha256', self::client_ip(), $secret );
	}
}
