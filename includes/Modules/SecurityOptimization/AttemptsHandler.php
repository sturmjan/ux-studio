<?php
/**
 * Login attempt rate limiting (brute-force protection).
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\SecurityOptimization;

use UxStudio\Core\ClientIp;
use WP_Error;
use WP_User;

defined( 'ABSPATH' ) || exit;

/**
 * Ported from the legacy AttemptsHandler. Tables are fixed by the migration
 * map: uxstudio_login_failed (lock records) and uxstudio_login_attempt
 * (rolling per-IP attempt counters).
 */
final class AttemptsHandler {

	private Module $module;

	/** @var string */
	private $login_failed;

	/** @var string */
	private $login_attempt;

	/** Lockout decided at the start of the authenticate chain (see enforce_lockout()). */
	private ?WP_Error $lockout_error = null;

	public function __construct( Module $module ) {
		global $wpdb;
		$this->module        = $module;
		$this->login_failed  = $wpdb->prefix . 'uxstudio_login_failed';
		$this->login_attempt = $wpdb->prefix . 'uxstudio_login_attempt';
	}

	private function max_attempts(): int {
		return max( 1, (int) $this->module->setting( 'max_attempts', 3 ) );
	}

	private function lockout_time(): int {
		return max( 1, (int) $this->module->setting( 'lockout_time', 30 ) );
	}

	/**
	 * The one clock for both tables: rows are written AND compared with the
	 * site-local time from PHP, never MySQL's NOW() (the DB server's time zone,
	 * which usually differs from WordPress's and shifted every lockout window).
	 * Local rather than UTC so the admin list keeps showing the same times.
	 */
	private function now(): string {
		return current_time( 'mysql' );
	}

	/* ═══════════════════════════════════════════════════
	   Authentication hooks
	   ═══════════════════════════════════════════════════ */

	/**
	 * Lockout check - runs on `authenticate` at priority 5, i.e. BEFORE core
	 * verifies the password (priority 20), and blocks regardless of whether
	 * the credentials are correct. Otherwise a locked-out attacker could keep
	 * guessing and a correct guess would still get through (a different
	 * response for the correct password would also be a password oracle).
	 *
	 * Locks are per IP only. A per-username lock that also rejects the correct
	 * password would let anyone lock the real admin out just by guessing
	 * against their username.
	 *
	 * Core's wp_authenticate_username_password() ignores an incoming WP_Error
	 * (it only short-circuits on WP_User), so the error is remembered here and
	 * re-asserted by enforce_lockout() at the very end of the chain.
	 *
	 * @param WP_User|WP_Error|null $user     Incoming authenticate() value.
	 * @param string                $username Attempted username.
	 * @param string                $password Attempted password (unused).
	 * @return WP_User|WP_Error|null
	 */
	public function check_attempted_login( $user, $username, $password ) {
		$this->lockout_error = null;

		$username = (string) $username;
		if ( '' === $username && '' === (string) $password ) {
			return $user; // Plain login page load, not an attempt.
		}

		$ip    = $this->get_client_ip();
		$error = null;

		if ( $this->is_ip_blocked( $ip ) ) {
			$error = new WP_Error(
				'uxstudio_ip_blocked',
				sprintf(
					/* translators: %d: minutes */
					__( 'Too many failed login attempts from this IP. Please try again in %d minutes.', 'ux-studio' ),
					$this->minutes_to_report( $ip )
				)
			);
		} elseif ( '' !== $ip && $this->get_current_attempt_count( $ip ) >= $this->max_attempts() ) {
			$error = new WP_Error(
				'uxstudio_attempts_exceeded',
				sprintf(
					/* translators: %d: minutes */
					__( 'Too many failed login attempts. Please try again in %d minutes.', 'ux-studio' ),
					$this->lockout_time()
				)
			);
		}

		if ( null === $error ) {
			return $user;
		}

		$this->lockout_error = $error;
		return $error;
	}

	/**
	 * Last word on `authenticate` (PHP_INT_MAX): re-assert the lockout decided
	 * by check_attempted_login(), whatever the filters in between returned.
	 *
	 * @param WP_User|WP_Error|null $user Incoming authenticate() value.
	 * @return WP_User|WP_Error|null
	 */
	public function enforce_lockout( $user ) {
		return null !== $this->lockout_error ? $this->lockout_error : $user;
	}

	/**
	 * Counters are reset only after a real, completed login (`wp_login`),
	 * never from inside the authenticate chain.
	 *
	 * @param string $user_login Logged-in username.
	 */
	public function handle_successful_login( $user_login ): void {
		$this->reset_attempts();
	}

	/**
	 * @param string $username Attempted username.
	 */
	public function handle_failed_login( $username ): void {
		$username    = (string) $username;
		$ip          = $this->get_client_ip();
		$ip_attempts = $this->increment_ip_attempts( $ip );

		if ( $ip_attempts >= $this->max_attempts() ) {
			if ( $this->is_ip_blocked( $ip ) ) {
				return; // Retry during an active lockout - don't stack lock rows / emails.
			}
			$minutes = $this->block_ip( $ip, $username );
			if ( $this->module->setting( 'notify_admin', false ) ) {
				$this->notify_admin( $ip, $username, $minutes );
			}
		}
	}

	/**
	 * `block_username_attempts`: a failed login with a reserved username
	 * (Module::is_blocked_username) locks the IP out at once, no counting.
	 * Hooked on `wp_login_failed` at priority 5, before handle_failed_login().
	 *
	 * Unlike legacy, a CORRECT password for an existing account with such a
	 * name is not turned into a failure here - that locked the site's real
	 * "admin" out; the admin notice about such accounts covers that case.
	 *
	 * @param string        $username Attempted username.
	 * @param WP_Error|null $error    Login error shown on the form (WP 5.4+).
	 */
	public function handle_blocked_username_login( $username, $error = null ): void {
		$username = (string) $username;
		if ( ! $this->module->is_blocked_username( $username ) ) {
			return;
		}

		$ip = $this->get_client_ip();
		if ( '' === $ip || $this->is_ip_blocked( $ip ) ) {
			return;
		}

		$minutes = $this->block_ip( $ip, $username );
		if ( $this->module->setting( 'notify_admin', false ) ) {
			$this->notify_admin( $ip, $username, $minutes );
		}

		if ( $error instanceof WP_Error ) {
			$error->add(
				'uxstudio_ip_blocked',
				sprintf(
					/* translators: %d: minutes */
					__( 'Too many failed login attempts from this IP. Please try again in %d minutes.', 'ux-studio' ),
					$minutes
				)
			);
		}
	}

	/* ═══════════════════════════════════════════════════
	   DB reads/writes
	   ═══════════════════════════════════════════════════ */

	private function increment_ip_attempts( string $ip ): int {
		global $wpdb;

		$existing = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$this->login_attempt} WHERE ip = %s ORDER BY date DESC LIMIT 1", $ip )
		);

		if ( $existing ) {
			$count = (int) $existing->attempt + 1;
			$wpdb->update(
				$this->login_attempt,
				array(
					'attempt' => $count,
					'date'    => $this->now(),
				),
				array( 'id' => $existing->id ),
				array( '%d', '%s' ),
				array( '%d' )
			);
			return $count;
		}

		$wpdb->insert(
			$this->login_attempt,
			array(
				'attempt' => 1,
				'ip'      => $ip,
				'date'    => $this->now(),
			),
			array( '%d', '%s', '%s' )
		);
		return 1;
	}

	/**
	 * Lock an IP and return the length of the lock in minutes (longer after repeated locks when "progressive" is on).
	 */
	private function block_ip( string $ip, string $username ): int {
		global $wpdb;
		$minutes = $this->next_lockout_minutes( $ip );
		$wpdb->insert(
			$this->login_failed,
			array(
				'ip'        => $ip,
				'username'  => $username,
				'country'   => null,
				'status'    => 1,
				'locktime'  => $minutes,
				'locklimit' => $this->max_attempts(),
				'date'      => $this->now(),
			),
			array( '%s', '%s', '%s', '%d', '%d', '%d', '%s' )
		);
		return $minutes;
	}

	/**
	 * Length of the next lock of an IP: the base time, or - with "progressive" on - the base time multiplied
	 * once for every lock of that IP within the last 24 hours, capped at the configured maximum.
	 */
	private function next_lockout_minutes( string $ip ): int {
		$base = $this->lockout_time();
		if ( ! $this->module->setting( 'lockout_progressive', false ) ) {
			return $base;
		}
		return self::progressive_minutes(
			$base,
			$this->recent_lockouts( $ip ),
			(float) $this->module->setting( 'lockout_multiplier', 2 ),
			max( 1, (int) $this->module->setting( 'lockout_max_hours', 24 ) ) * 60
		);
	}

	/**
	 * The progressive rule, pure: base * multiplier^previous, never below the base, never above the cap.
	 *
	 * @param int   $base       Base lockout in minutes.
	 * @param int   $previous   Locks of the same IP within the last 24 hours.
	 * @param float $multiplier Factor per repeated lock (1 = no growth).
	 * @param int   $max        Longest lock in minutes.
	 */
	public static function progressive_minutes( int $base, int $previous, float $multiplier, int $max ): int {
		$base = max( 1, $base );
		$cap  = max( $base, $max );
		$raw  = $base * ( max( 1.0, $multiplier ) ** max( 0, $previous ) );
		// A float that overflowed to INF (or just exceeds the cap) must land on the cap, not wrap around.
		return is_finite( $raw ) && $raw < $cap ? max( $base, (int) round( $raw ) ) : $cap;
	}

	/**
	 * Locks of one IP in the last 24 hours (an administrator's "unlock" resets the status, so those do not count).
	 */
	private function recent_lockouts( string $ip ): int {
		global $wpdb;
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$this->login_failed} WHERE ip = %s AND status = 1 AND date > DATE_SUB(%s, INTERVAL 24 HOUR)",
				$ip,
				$this->now()
			)
		);
	}

	/**
	 * Minutes shown in "try again in N minutes": the base time as always, or the real rest of the lock when
	 * the lock length can vary.
	 */
	private function minutes_to_report( string $ip ): int {
		if ( ! $this->module->setting( 'lockout_progressive', false ) ) {
			return $this->lockout_time();
		}
		global $wpdb;
		$left = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT CEIL(TIMESTAMPDIFF(SECOND, %s, DATE_ADD(date, INTERVAL locktime MINUTE)) / 60) FROM {$this->login_failed}
				 WHERE ip = %s AND status = 1 AND date > DATE_SUB(%s, INTERVAL locktime MINUTE)
				 ORDER BY date DESC LIMIT 1",
				$this->now(),
				$ip,
				$this->now()
			)
		);
		return null === $left ? $this->lockout_time() : max( 1, (int) $left );
	}

	public function is_ip_blocked( string $ip ): bool {
		if ( '' === $ip ) {
			return false;
		}
		global $wpdb;
		$blocked = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$this->login_failed}
				 WHERE ip = %s AND status = 1 AND date > DATE_SUB(%s, INTERVAL locktime MINUTE)",
				$ip,
				$this->now()
			)
		);
		return (bool) $blocked;
	}

	/**
	 * Clear the failure counter of the IP that just logged in. Lock rows of
	 * OTHER IPs that guessed against the same username stay in force - the
	 * real user signing in must not lift an attacker's ban.
	 */
	private function reset_attempts(): void {
		global $wpdb;
		$wpdb->delete( $this->login_attempt, array( 'ip' => $this->get_client_ip() ), array( '%s' ) );
	}

	public function get_current_attempt_count( string $ip ): int {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT attempt FROM {$this->login_attempt}
				 WHERE ip = %s AND date > DATE_SUB(%s, INTERVAL %d MINUTE)
				 ORDER BY date DESC LIMIT 1",
				$ip,
				$this->now(),
				$this->lockout_time()
			)
		);
		return $row ? (int) $row->attempt : 0;
	}

	/**
	 * Client IP - REMOTE_ADDR by default (unspoofable); forwarded headers are
	 * only trusted in the configured IP firewall proxy mode AND only when they
	 * come from that proxy itself (Core\ClientIp), never a bare client header.
	 */
	public function get_client_ip(): string {
		return ClientIp::get( $this->module->proxy_mode() );
	}

	/**
	 * Recipient of the lockout e-mail: the address from the settings, or the site administrator.
	 */
	public function notification_email(): string {
		$custom = sanitize_email( (string) $this->module->setting( 'lockout_notify_email', '' ) );
		return '' !== $custom && is_email( $custom ) ? $custom : (string) get_option( 'admin_email' );
	}

	private function notify_admin( string $ip, string $username, int $minutes ): void {
		$site_name = get_bloginfo( 'name' );

		$subject = sprintf(
			/* translators: %s: site name */
			__( '[%s] IP Address Blocked', 'ux-studio' ),
			$site_name
		);

		$lines = array(
			__( 'An IP address has been blocked due to too many failed login attempts:', 'ux-studio' ),
			/* translators: %s: IP address */
			'- ' . sprintf( __( 'IP Address: %s', 'ux-studio' ), $ip ),
			/* translators: %s: username */
			'- ' . sprintf( __( 'Username: %s', 'ux-studio' ), $username ),
			/* translators: %d: minutes */
			'- ' . sprintf( __( 'Block Duration: %d minutes', 'ux-studio' ), $minutes ),
		);
		if ( $this->module->setting( 'lockout_progressive', false ) && $minutes > $this->lockout_time() ) {
			$lines[] = '- ' . __( 'This IP was locked repeatedly within 24 hours, so the lock is longer than usual.', 'ux-studio' );
		}
		$lines[] = '';
		$lines[] = __( 'You can unblock this IP address from the plugin admin screen.', 'ux-studio' );

		wp_mail( $this->notification_email(), $subject, implode( "\r\n", $lines ) );
	}

	/**
	 * Purge stale attempt counters. Hourly cron (Module::ATTEMPTS_CLEANUP_HOOK),
	 * not on every request.
	 */
	public function cleanup_expired_attempts(): void {
		global $wpdb;
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$this->login_attempt} WHERE date < DATE_SUB(%s, INTERVAL %d MINUTE)",
				$this->now(),
				$this->lockout_time()
			)
		);
	}

	/* ═══════════════════════════════════════════════════
	   Admin/REST facing
	   ═══════════════════════════════════════════════════ */

	/**
	 * @param array $args status?, search?, page, per_page.
	 */
	public function get_blocked_accounts( array $args = array() ): array {
		global $wpdb;

		$page     = max( 1, (int) ( $args['page'] ?? 1 ) );
		$per_page = max( 1, min( 100, (int) ( $args['per_page'] ?? 10 ) ) );
		$offset   = ( $page - 1 ) * $per_page;

		$where  = array( '1=1' );
		$params = array();

		if ( isset( $args['status'] ) && '' !== $args['status'] ) {
			$where[]  = 'status = %d';
			$params[] = (int) $args['status'];
		}
		if ( ! empty( $args['search'] ) ) {
			$like     = '%' . $wpdb->esc_like( (string) $args['search'] ) . '%';
			$where[]  = '(username LIKE %s OR ip LIKE %s)';
			$params[] = $like;
			$params[] = $like;
		}

		$where_sql = implode( ' AND ', $where );
		$total     = (int) $wpdb->get_var(
			$params
				? $wpdb->prepare( "SELECT COUNT(*) FROM {$this->login_failed} WHERE {$where_sql}", $params )
				: "SELECT COUNT(*) FROM {$this->login_failed} WHERE {$where_sql}"
		);

		$list_params = array_merge( $params, array( $per_page, $offset ) );
		$items       = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$this->login_failed} WHERE {$where_sql} ORDER BY date DESC LIMIT %d OFFSET %d",
				$list_params
			),
			ARRAY_A
		);

		return array(
			'items'       => $items ?: array(),
			'total'       => $total,
			'page'        => $page,
			'per_page'    => $per_page,
			'total_pages' => (int) ceil( $total / $per_page ),
		);
	}

	/**
	 * Unlock (clear) one blocked account row by id.
	 */
	public function unblock_account( int $id ): bool {
		global $wpdb;
		$account = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->login_failed} WHERE id = %d", $id ) );
		if ( ! $account ) {
			return false;
		}

		$result = $wpdb->update( $this->login_failed, array( 'status' => 0 ), array( 'id' => $id ), array( '%d' ), array( '%d' ) );
		if ( false !== $result ) {
			$wpdb->delete( $this->login_attempt, array( 'ip' => $account->ip ), array( '%s' ) );
		}
		return false !== $result;
	}

	/**
	 * Clear every recorded attempt/lock (used by "Clear all" action).
	 */
	public function clear_all_attempts(): void {
		global $wpdb;
		$wpdb->query( "DELETE FROM {$this->login_attempt}" );
		$wpdb->query( "DELETE FROM {$this->login_failed}" );
	}
}
