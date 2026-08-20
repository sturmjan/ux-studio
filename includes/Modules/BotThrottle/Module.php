<?php
/**
 * Bot Throttle module - detect bots by User-Agent pattern and rate-limit
 * anonymous frontend requests.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\BotThrottle;

use UxStudio\Modules\BaseModule;

defined( 'ABSPATH' ) || exit;

/**
 * Ported/redesigned from the legacy bot-throttle module as a group-C module
 * with its own SPA screen. The legacy module's mu-plugin bootstrap, its own
 * REST namespace, Microcache and LoadSampler are intentionally NOT ported -
 * out of scope for this rewrite. This module keeps only: a UA-pattern block
 * list, a simple per-IP fixed-window rate limiter backed by its own bucket
 * table, and a log of blocked hits.
 */
final class Module extends BaseModule {

	/**
	 * Register hooks.
	 */
	public function boot(): void {
		\UxStudio\Core\DB::ensure_module_tables(
			'bot-throttle',
			1,
			function ( int $from ): void {
				global $wpdb;
				$charset = $wpdb->get_charset_collate();
				dbDelta(
					"CREATE TABLE {$wpdb->prefix}uxstudio_bot_throttle_buckets (
						id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
						ip_hash VARCHAR(64) NOT NULL,
						window_start DATETIME NOT NULL,
						request_count INT UNSIGNED NOT NULL DEFAULT 0,
						PRIMARY KEY  (id),
						UNIQUE KEY ip_window (ip_hash, window_start)
					) {$charset};
					CREATE TABLE {$wpdb->prefix}uxstudio_bot_throttle_log (
						id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
						created_at DATETIME NOT NULL,
						ip_hash VARCHAR(64) NOT NULL,
						user_agent VARCHAR(255) NOT NULL DEFAULT '',
						action VARCHAR(20) NOT NULL DEFAULT '',
						PRIMARY KEY  (id),
						KEY created_at (created_at)
					) {$charset};"
				);
			}
		);

		add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );

		if ( ! (bool) $this->settings->get( 'enabled', true ) ) {
			return;
		}

		// template_redirect only fires for normal frontend page/theme requests -
		// it naturally excludes REST API calls, wp-cron and wp-admin, so no extra
		// REST_REQUEST/wp_doing_cron() gating is needed here.
		add_action( 'template_redirect', array( $this, 'maybe_block' ) );
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

	/**
	 * Settings schema for the generic renderer / embedded Settings tab.
	 */
	public function settings_schema(): array {
		return array(
			array(
				'key'     => 'enabled',
				'type'    => 'toggle',
				'label'   => __( 'Enable bot throttling', 'ux-studio' ),
				'default' => true,
			),
			array(
				'key'     => 'requests_per_minute',
				'type'    => 'number',
				'label'   => __( 'Requests per minute per IP', 'ux-studio' ),
				'default' => 60,
			),
			array(
				'key'     => 'blocked_user_agents',
				'type'    => 'textarea',
				'label'   => __( 'Blocked User-Agent patterns (one per line)', 'ux-studio' ),
				'help'    => __( 'Case-insensitive substring or regex match against the request\'s User-Agent header.', 'ux-studio' ),
				'default' => "AhrefsBot\nSemrushBot\nMJ12bot\nDotBot\nPetalBot\nBLEXBot\nMegaIndex\nSerpstatBot\nrogerbot\nDataForSeoBot",
			),
			array(
				'key'     => 'block_response_code',
				'type'    => 'select',
				'label'   => __( 'Block response code', 'ux-studio' ),
				'options' => array(
					'403' => __( '403 Forbidden', 'ux-studio' ),
					'429' => __( '429 Too Many Requests', 'ux-studio' ),
				),
				'default' => '403',
			),
		);
	}

	/**
	 * Frontend gate hooked on template_redirect. Blocks requests that match a
	 * blocked User-Agent pattern or exceed the per-IP rate limit; logs and
	 * terminates the request when blocking, otherwise returns normally.
	 */
	public function maybe_block(): void {
		// Admins working on the site are never throttled (safety valve so an
		// editor previewing pages doesn't get locked out by their own traffic).
		if ( current_user_can( 'manage_options' ) ) {
			return;
		}

		// REMOTE_ADDR only - trusting X-Forwarded-For (or similar proxy headers)
		// requires a trusted reverse-proxy allowlist this module does not assume,
		// and honoring it here would let a client spoof its way past the rate
		// limiter by forging the header on every request.
		$ip = (string) ( $_SERVER['REMOTE_ADDR'] ?? '' );
		$ua = (string) ( $_SERVER['HTTP_USER_AGENT'] ?? '' );

		$blocked = $this->matches_blocked_user_agent( $ua );
		if ( ! $blocked ) {
			$limit   = (int) $this->settings->get( 'requests_per_minute', 60 );
			$blocked = $limit > 0 && $this->hit_rate_limit( $ip, $limit );
		}

		if ( ! $blocked ) {
			// Allowed requests are deliberately not logged - only blocked hits are
			// recorded, otherwise the log table would be flooded on any real
			// traffic. The 'action' column stays generic (values 'blocked'/'allowed')
			// in case a future sampled-allowed insert is ever added.
			return;
		}

		$ip_hash = $this->hash_ip( $ip );
		$this->insert_log( $ip_hash, $ua, 'blocked' );

		$code = (int) $this->settings->get( 'block_response_code', '403' );
		if ( 429 === $code ) {
			status_header( 429 );
			echo 'Too many requests.';
		} else {
			status_header( 403 );
			echo 'Forbidden.';
		}
		exit;
	}

	/**
	 * Match the User-Agent against the configured blocked-pattern list.
	 *
	 * @param string $ua Request User-Agent header.
	 */
	private function matches_blocked_user_agent( string $ua ): bool {
		if ( '' === $ua ) {
			return false;
		}

		$patterns = (string) $this->settings->get( 'blocked_user_agents', '' );
		foreach ( preg_split( '/\r\n|\r|\n/', $patterns ) as $pattern ) {
			$pattern = trim( $pattern );
			if ( '' === $pattern ) {
				continue;
			}
			if ( false !== stripos( $ua, $pattern ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Atomically increments the fixed 1-minute-window bucket for this IP and
	 * reports whether the request should be blocked (count now over the limit).
	 * Opportunistically prunes stale bucket rows (1% chance per call) so the
	 * table doesn't grow unbounded without needing a scheduled cron job.
	 *
	 * @param string $ip    Raw client IP (never stored - only its hash).
	 * @param int    $limit requests_per_minute setting.
	 */
	private function hit_rate_limit( string $ip, int $limit ): bool {
		global $wpdb;

		$ip_hash      = $this->hash_ip( $ip );
		$window_start = gmdate( 'Y-m-d H:i:00' );
		$table        = "{$wpdb->prefix}uxstudio_bot_throttle_buckets";

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $table is a fixed internal identifier, values are parameterized.
		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$table} (ip_hash, window_start, request_count) VALUES (%s, %s, 1)
				ON DUPLICATE KEY UPDATE request_count = request_count + 1",
				$ip_hash,
				$window_start
			)
		);

		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT request_count FROM {$table} WHERE ip_hash = %s AND window_start = %s",
				$ip_hash,
				$window_start
			)
		);

		if ( wp_rand( 1, 100 ) === 1 ) {
			$wpdb->query(
				$wpdb->prepare(
					"DELETE FROM {$table} WHERE window_start < %s",
					gmdate( 'Y-m-d H:i:s', strtotime( '-1 hour' ) )
				)
			);
		}

		return $count > $limit;
	}

	/**
	 * GDPR: never store the raw IP anywhere - only its salted hash.
	 *
	 * @param string $ip Raw client IP.
	 */
	private function hash_ip( string $ip ): string {
		return hash( 'sha256', $ip . wp_salt() );
	}

	/**
	 * @param string $ip_hash    Salted IP hash (never the raw IP).
	 * @param string $user_agent Request User-Agent header.
	 * @param string $action     'blocked' or 'allowed'.
	 */
	private function insert_log( string $ip_hash, string $user_agent, string $action ): void {
		global $wpdb;

		$wpdb->insert(
			"{$wpdb->prefix}uxstudio_bot_throttle_log",
			array(
				'created_at' => current_time( 'mysql' ),
				'ip_hash'    => $ip_hash,
				'user_agent' => mb_substr( $user_agent, 0, 255 ),
				'action'     => $action,
			),
			array( '%s', '%s', '%s', '%s' )
		);
	}

	/**
	 * Last 100 log rows, newest first.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function get_log(): array {
		global $wpdb;
		$rows = $wpdb->get_results(
			"SELECT id, created_at, ip_hash, user_agent, action FROM {$wpdb->prefix}uxstudio_bot_throttle_log ORDER BY id DESC LIMIT 100",
			ARRAY_A
		);
		return is_array( $rows ) ? $rows : array();
	}
}
