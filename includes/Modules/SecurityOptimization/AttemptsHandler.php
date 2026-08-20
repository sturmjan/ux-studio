<?php
/**
 * Login attempt rate limiting (brute-force protection).
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\SecurityOptimization;

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

	/* ═══════════════════════════════════════════════════
	   Authentication hooks
	   ═══════════════════════════════════════════════════ */

	/**
	 * @param WP_User|WP_Error|null $user     Incoming authenticate() value.
	 * @param string                $username Attempted username.
	 * @param string                $password Attempted password (unused).
	 * @return WP_User|WP_Error|null
	 */
	public function check_attempted_login( $user, $username, $password ) {
		if ( $user instanceof WP_User ) {
			$this->reset_attempts( $username );
			return $user;
		}

		$ip = $this->get_client_ip();

		if ( $this->is_ip_blocked( $ip ) ) {
			return new WP_Error(
				'uxstudio_ip_blocked',
				sprintf(
					/* translators: %d: minutes */
					__( 'Too many failed login attempts from this IP. Please try again in %d minutes.', 'ux-studio' ),
					$this->lockout_time()
				)
			);
		}

		if ( $this->is_username_blocked( $username ) ) {
			return new WP_Error(
				'uxstudio_username_blocked',
				sprintf(
					/* translators: %d: minutes */
					__( 'Too many failed login attempts for this username. Please try again in %d minutes.', 'ux-studio' ),
					$this->lockout_time()
				)
			);
		}

		if ( $this->get_current_attempt_count( $ip ) >= $this->max_attempts() ) {
			return new WP_Error(
				'uxstudio_attempts_exceeded',
				sprintf(
					/* translators: %d: minutes */
					__( 'Too many failed login attempts. Please try again in %d minutes.', 'ux-studio' ),
					$this->lockout_time()
				)
			);
		}

		return $user;
	}

	/**
	 * @param string $username Attempted username.
	 */
	public function handle_failed_login( $username ): void {
		$ip             = $this->get_client_ip();
		$ip_attempts    = $this->increment_ip_attempts( $ip );
		$username_count = $this->get_current_username_attempts( $username );

		if ( $ip_attempts >= $this->max_attempts() || $username_count >= $this->max_attempts() ) {
			$this->block_ip( $ip, $username );
			if ( $this->module->setting( 'notify_admin', false ) ) {
				$this->notify_admin( $ip, $username );
			}
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
					'date'    => current_time( 'mysql' ),
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
				'date'    => current_time( 'mysql' ),
			),
			array( '%d', '%s', '%s' )
		);
		return 1;
	}

	private function get_current_username_attempts( string $username ): int {
		global $wpdb;
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$this->login_failed}
				 WHERE username = %s AND status = 1 AND date > DATE_SUB(NOW(), INTERVAL locktime MINUTE)",
				$username
			)
		);
	}

	private function block_ip( string $ip, string $username ): void {
		global $wpdb;
		$wpdb->insert(
			$this->login_failed,
			array(
				'ip'        => $ip,
				'username'  => $username,
				'country'   => null,
				'status'    => 1,
				'locktime'  => $this->lockout_time(),
				'locklimit' => $this->max_attempts(),
				'date'      => current_time( 'mysql' ),
			),
			array( '%s', '%s', '%s', '%d', '%d', '%d', '%s' )
		);
	}

	public function is_ip_blocked( string $ip ): bool {
		if ( '' === $ip ) {
			return false;
		}
		global $wpdb;
		$blocked = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$this->login_failed}
				 WHERE ip = %s AND status = 1 AND date > DATE_SUB(NOW(), INTERVAL locktime MINUTE)",
				$ip
			)
		);
		return (bool) $blocked;
	}

	private function is_username_blocked( string $username ): bool {
		global $wpdb;
		$blocked = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$this->login_failed}
				 WHERE username = %s AND status = 1 AND date > DATE_SUB(NOW(), INTERVAL locktime MINUTE)",
				$username
			)
		);
		return (bool) $blocked;
	}

	private function reset_attempts( string $username ): void {
		global $wpdb;
		$ip = $this->get_client_ip();

		$wpdb->delete( $this->login_attempt, array( 'ip' => $ip ), array( '%s' ) );
		$wpdb->update( $this->login_failed, array( 'status' => 0 ), array( 'username' => $username ), array( '%d' ), array( '%s' ) );
	}

	public function get_current_attempt_count( string $ip ): int {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT attempt FROM {$this->login_attempt}
				 WHERE ip = %s AND date > DATE_SUB(NOW(), INTERVAL %d MINUTE)
				 ORDER BY date DESC LIMIT 1",
				$ip,
				$this->lockout_time()
			)
		);
		return $row ? (int) $row->attempt : 0;
	}

	/**
	 * Client IP - REMOTE_ADDR by default (unspoofable); forwarded headers are
	 * only trusted when the IP firewall proxy mode explicitly says so, via
	 * the shared filter also used by IpBanStore's proxy-mode setting.
	 */
	public function get_client_ip(): string {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';

		$proxy_mode = (string) $this->module->setting( 'ip_firewall_proxy', 'none' );
		if ( 'cloudflare' === $proxy_mode && ! empty( $_SERVER['HTTP_CF_CONNECTING_IP'] ) ) {
			$candidate = sanitize_text_field( wp_unslash( $_SERVER['HTTP_CF_CONNECTING_IP'] ) );
			if ( filter_var( $candidate, FILTER_VALIDATE_IP ) ) {
				$ip = $candidate;
			}
		} elseif ( 'xff' === $proxy_mode && ! empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
			$parts     = explode( ',', sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) );
			$candidate = trim( $parts[0] );
			if ( filter_var( $candidate, FILTER_VALIDATE_IP ) ) {
				$ip = $candidate;
			}
		}

		return filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '';
	}

	private function notify_admin( string $ip, string $username ): void {
		$admin_email = get_option( 'admin_email' );
		$site_name   = get_bloginfo( 'name' );

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
			'- ' . sprintf( __( 'Block Duration: %d minutes', 'ux-studio' ), $this->lockout_time() ),
			'',
			__( 'You can unblock this IP address from the plugin admin screen.', 'ux-studio' ),
		);

		wp_mail( $admin_email, $subject, implode( "\r\n", $lines ) );
	}

	public function cleanup_expired_attempts(): void {
		global $wpdb;
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$this->login_attempt} WHERE date < DATE_SUB(NOW(), INTERVAL %d MINUTE)",
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
