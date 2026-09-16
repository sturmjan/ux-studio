<?php
/**
 * Reusable IP burst-guard, backed by this module's own log + notifier, so
 * other modules protecting a narrow endpoint (e.g. Analytics' beacon) don't
 * need to duplicate rate-limit/ban/notify plumbing.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\BotThrottle;

use UxStudio\Core\Settings;

defined( 'ABSPATH' ) || exit;

final class Guard {

	/**
	 * Cheap burst guard: blocks IPs hammering a caller-defined context before
	 * any heavier work happens. Once an IP trips the burst threshold it's
	 * banned outright for $ban_for - further calls short-circuit on a single
	 * transient read instead of touching the counting bucket at all.
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

		$ban_key = self::key( $context . '_ban' );
		if ( get_transient( $ban_key ) ) {
			return true;
		}

		$key  = self::key( $context . '_burst' );
		$now  = time();
		$data = get_transient( $key );

		if ( ! is_array( $data ) || empty( $data['reset'] ) || $data['reset'] <= $now ) {
			$data = array(
				'count' => 0,
				'reset' => $now + $burst_window,
			);
		}

		++$data['count'];

		if ( $data['count'] > $burst_max ) {
			set_transient( $ban_key, true, $ban_for );

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

		set_transient( $key, $data, max( 1, $data['reset'] - $now ) );
		return false;
	}

	/**
	 * Transient key, scoped by client IP and caller context.
	 */
	private static function key( string $scope ): string {
		return 'uxstudio_bt_guard_' . substr( hash( 'sha256', $scope . '|' . self::client_ip() . '|' . wp_salt() ), 0, 40 );
	}

	/**
	 * Client IP. REMOTE_ADDR only, matching Module::client_ip() - honoring
	 * proxy headers without a trusted-proxy allowlist would let a client
	 * spoof its way past the ban.
	 */
	private static function client_ip(): string {
		$ip = (string) ( $_SERVER['REMOTE_ADDR'] ?? '' ); // phpcs:ignore
		return filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '0.0.0.0';
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
