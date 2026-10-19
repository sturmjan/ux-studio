<?php
/**
 * AI Markdown bot-access log queries.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\AiMarkdown;

defined( 'ABSPATH' ) || exit;

/**
 * Read/delete side of the uxstudio_ai_markdown_log table for the SPA Log tab
 * (port of the legacy ListTable + BotDetector::getStats()). created_at is
 * stored in UTC and handed to the UI in site-local time.
 */
final class AccessLog {

	/** Max rows per page. */
	private const MAX_LIMIT = 200;

	private static function table(): string {
		global $wpdb;
		return "{$wpdb->prefix}uxstudio_ai_markdown_log";
	}

	/**
	 * Filtered, paginated entries.
	 *
	 * @param array{search?:string,bot?:string,type?:string,limit?:int,offset?:int} $args Filters.
	 * @return array{items:array<int,array<string,mixed>>,total:int}
	 */
	public static function list( array $args ): array {
		global $wpdb;
		$table  = self::table();
		$where  = array( '1=1' );
		$params = array();

		$bot = sanitize_text_field( (string) ( $args['bot'] ?? '' ) );
		if ( '' !== $bot ) {
			$where[]  = 'bot_name = %s';
			$params[] = $bot;
		}
		$type = sanitize_key( (string) ( $args['type'] ?? '' ) );
		if ( '' !== $type ) {
			$where[]  = 'content_type = %s';
			$params[] = $type;
		}
		$search = sanitize_text_field( (string) ( $args['search'] ?? '' ) );
		if ( '' !== $search ) {
			$like     = '%' . $wpdb->esc_like( $search ) . '%';
			$where[]  = '(bot_name LIKE %s OR url LIKE %s OR ip_address LIKE %s OR user_agent LIKE %s)';
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
		}

		$where_sql = implode( ' AND ', $where );
		$limit     = min( self::MAX_LIMIT, max( 1, (int) ( $args['limit'] ?? 30 ) ) );
		$offset    = max( 0, (int) ( $args['offset'] ?? 0 ) );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $where_sql holds only %s placeholders.
		$count_sql = "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}";
		$total     = (int) ( $params ? $wpdb->get_var( $wpdb->prepare( $count_sql, ...$params ) ) : $wpdb->get_var( $count_sql ) );

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, created_at, post_id, bot_name, url, content_type, ip_address, user_agent
				 FROM {$table} WHERE {$where_sql} ORDER BY created_at DESC, id DESC LIMIT %d OFFSET %d",
				...array_merge( $params, array( $limit, $offset ) )
			),
			ARRAY_A
		);
		// phpcs:enable

		$items = array();
		foreach ( (array) $rows as $row ) {
			$post_id = (int) $row['post_id'];
			$url     = (string) $row['url'];
			if ( '' === $url && $post_id ) {
				// Rows logged before schema v2 have no URL - rebuild it from the post.
				$permalink = get_permalink( $post_id );
				$url       = $permalink ? add_query_arg( 'format', 'markdown', $permalink ) : '';
			}
			$items[] = array(
				'id'           => (int) $row['id'],
				'created_at'   => get_date_from_gmt( (string) $row['created_at'] ),
				'post_id'      => $post_id,
				'bot_name'     => (string) $row['bot_name'],
				'url'          => $url,
				'content_type' => (string) $row['content_type'],
				'ip_address'   => (string) $row['ip_address'],
				'user_agent'   => (string) $row['user_agent'],
			);
		}

		return array( 'items' => $items, 'total' => $total );
	}

	/**
	 * Totals, today's count (site-local day) and per-bot counts.
	 *
	 * @return array{total:int,today:int,bots:array<int,array{bot_name:string,count:int}>}
	 */
	public static function stats(): array {
		global $wpdb;
		$table       = self::table();
		$today_start = get_gmt_from_date( current_time( 'Y-m-d' ) . ' 00:00:00' );

		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$today = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE created_at >= %s", $today_start ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows  = $wpdb->get_results( "SELECT bot_name, COUNT(*) AS cnt FROM {$table} GROUP BY bot_name ORDER BY cnt DESC", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$bots = array();
		foreach ( (array) $rows as $row ) {
			$bots[] = array(
				'bot_name' => (string) $row['bot_name'],
				'count'    => (int) $row['cnt'],
			);
		}

		return array( 'total' => $total, 'today' => $today, 'bots' => $bots );
	}

	/**
	 * Delete entries by id.
	 *
	 * @param array<int, mixed> $ids Row ids.
	 * @return int Rows deleted.
	 */
	public static function delete( array $ids ): int {
		$ids = array_values( array_unique( array_filter( array_map( 'absint', $ids ) ) ) );
		if ( empty( $ids ) ) {
			return 0;
		}
		global $wpdb;
		$table        = self::table();
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		return (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE id IN ({$placeholders})", ...$ids ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Schema v1 -> v2: v1 logged every visitor with only the raw user agent.
	 * Tag the rows that match a known bot (same first-match order as
	 * BotDetector::detect()), derive the content type, and drop the rest -
	 * the log is a bot-access log, as in the legacy plugin.
	 */
	public static function backfill_v1(): void {
		global $wpdb;
		$table = self::table();
		foreach ( BotDetector::BOTS as $pattern => $name ) {
			$wpdb->query(
				$wpdb->prepare(
					"UPDATE {$table} SET bot_name = %s WHERE bot_name = '' AND user_agent LIKE %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$name,
					'%' . $wpdb->esc_like( $pattern ) . '%'
				)
			);
		}
		$wpdb->query( "DELETE FROM {$table} WHERE bot_name = ''" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "UPDATE {$table} SET content_type = 'llms_txt' WHERE content_type = '' AND post_id IS NULL" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "UPDATE {$table} l INNER JOIN {$wpdb->posts} p ON p.ID = l.post_id SET l.content_type = LEFT(p.post_type, 20) WHERE l.content_type = ''" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}
}
