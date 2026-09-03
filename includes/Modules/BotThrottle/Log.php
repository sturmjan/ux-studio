<?php
/**
 * Read/write access to the bot-throttle hit log.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\BotThrottle;

defined( 'ABSPATH' ) || exit;

/**
 * Thin data-access layer over {$prefix}uxstudio_bot_throttle_log. The IP is
 * stored only as a salted hash (GDPR) - never the raw address.
 */
final class Log {

	/**
	 * Fully-qualified log table name.
	 */
	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'uxstudio_bot_throttle_log';
	}

	/**
	 * Insert one log row.
	 *
	 * @param array $entry created_at/ip_hash/user_agent/action/bot_category/bot_name/tier/delay_ms/url/load_score/response_status.
	 */
	public static function insert( array $entry ): void {
		global $wpdb;
		$wpdb->insert(
			self::table(),
			array(
				'created_at'      => current_time( 'mysql' ),
				'ip_hash'         => substr( (string) ( $entry['ip_hash'] ?? '' ), 0, 64 ),
				'user_agent'      => mb_substr( (string) ( $entry['user_agent'] ?? '' ), 0, 255 ),
				'action'          => substr( (string) ( $entry['action'] ?? 'pass' ), 0, 20 ),
				'bot_category'    => substr( (string) ( $entry['bot_category'] ?? '' ), 0, 50 ),
				'bot_name'        => substr( (string) ( $entry['bot_name'] ?? '' ), 0, 100 ),
				'tier'            => substr( (string) ( $entry['tier'] ?? 'GREEN' ), 0, 10 ),
				'delay_ms'        => (int) ( $entry['delay_ms'] ?? 0 ),
				'url'             => mb_substr( (string) ( $entry['url'] ?? '' ), 0, 500 ),
				'load_score'      => (float) ( $entry['load_score'] ?? 0 ),
				'response_status' => (int) ( $entry['response_status'] ?? 200 ),
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%f', '%d' )
		);
	}

	/**
	 * Most recent rows (newest first), optionally filtered.
	 *
	 * @param int   $limit   Max rows (1-500).
	 * @param array $filters category/action/bot_name.
	 * @return array<int,array<string,mixed>>
	 */
	public static function recent( int $limit = 100, array $filters = array() ): array {
		global $wpdb;
		$table = self::table();
		$limit = max( 1, min( 500, $limit ) );

		$where = array( '1=1' );
		$args  = array();
		if ( ! empty( $filters['category'] ) ) {
			$where[] = 'bot_category = %s';
			$args[]  = (string) $filters['category'];
		}
		if ( ! empty( $filters['action'] ) ) {
			$where[] = 'action = %s';
			$args[]  = (string) $filters['action'];
		}
		if ( ! empty( $filters['bot_name'] ) ) {
			$where[] = 'bot_name LIKE %s';
			$args[]  = '%' . $wpdb->esc_like( (string) $filters['bot_name'] ) . '%';
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table fixed, $where uses placeholders.
		$sql    = "SELECT * FROM {$table} WHERE " . implode( ' AND ', $where ) . ' ORDER BY id DESC LIMIT %d';
		$args[] = $limit;

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, ...$args ), ARRAY_A );
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Aggregate stats over the last N hours.
	 *
	 * @param int $hours Window size in hours (1-720).
	 * @return array<string,mixed>
	 */
	public static function stats( int $hours = 24 ): array {
		global $wpdb;
		$table = self::table();
		$hours = max( 1, min( 720, $hours ) );
		$since = gmdate( 'Y-m-d H:i:s', time() - $hours * HOUR_IN_SECONDS );

		$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE created_at >= %s", $since ) );

		$by_action = $wpdb->get_results(
			$wpdb->prepare( "SELECT action, COUNT(*) AS c, AVG(delay_ms) AS avg_delay FROM {$table} WHERE created_at >= %s GROUP BY action", $since ),
			ARRAY_A
		) ?: array();

		$top_bots = $wpdb->get_results(
			$wpdb->prepare( "SELECT bot_name, bot_category, COUNT(*) AS c, AVG(delay_ms) AS avg_delay FROM {$table} WHERE created_at >= %s GROUP BY bot_name, bot_category ORDER BY c DESC LIMIT 10", $since ),
			ARRAY_A
		) ?: array();

		$blocked = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE created_at >= %s AND action = 'block'", $since ) );
		$delayed = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE created_at >= %s AND delay_ms > 0", $since ) );

		return array(
			'total'     => $total,
			'blocked'   => $blocked,
			'delayed'   => $delayed,
			'by_action' => $by_action,
			'top_bots'  => $top_bots,
			'since'     => $since,
			'hours'     => $hours,
		);
	}

	/**
	 * Delete rows older than the retention window. Returns rows removed.
	 *
	 * @param int $retention_days Retention in days.
	 */
	public static function cleanup( int $retention_days = 14 ): int {
		global $wpdb;
		$table  = self::table();
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - $retention_days * DAY_IN_SECONDS );
		return (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE created_at < %s", $cutoff ) );
	}

	/**
	 * Truncate the whole log.
	 */
	public static function clear(): int {
		global $wpdb;
		return (int) $wpdb->query( 'TRUNCATE TABLE ' . self::table() );
	}
}
