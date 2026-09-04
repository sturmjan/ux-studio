<?php
/**
 * Sync log writer/reader for the content-sync module.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\ContentSync;

defined( 'ABSPATH' ) || exit;

/**
 * Thin helper over the shared uxstudio_content_sync_log table. Every remote
 * operation (node-side apply and hub-side push) records one row here so the
 * whole sync surface is visible in one place.
 */
final class SyncLog {

	/** Log table (without prefix). */
	private static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'uxstudio_content_sync_log';
	}

	/**
	 * Record one entry.
	 *
	 * @param array{
	 *   site_id?:int, site_name?:string, action?:string, status?:string,
	 *   object_type?:string, object_id?:int, object_title?:string, message?:string
	 * } $data Entry data.
	 */
	public static function record( array $data ): int {
		global $wpdb;
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			self::table(),
			array(
				'created_at'   => current_time( 'mysql' ),
				'site_id'      => (int) ( $data['site_id'] ?? 0 ),
				'site_name'    => mb_substr( (string) ( $data['site_name'] ?? '' ), 0, 255 ),
				'action'       => mb_substr( (string) ( $data['action'] ?? '' ), 0, 64 ),
				'status'       => mb_substr( (string) ( $data['status'] ?? '' ), 0, 20 ),
				'object_type'  => mb_substr( (string) ( $data['object_type'] ?? '' ), 0, 32 ),
				'object_id'    => (int) ( $data['object_id'] ?? 0 ),
				'object_title' => mb_substr( (string) ( $data['object_title'] ?? '' ), 0, 255 ),
				'message'      => mb_substr( (string) ( $data['message'] ?? '' ), 0, 1000 ),
				'user_id'      => get_current_user_id(),
			),
			array( '%s', '%d', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%d' )
		);
		return (int) $wpdb->insert_id;
	}

	/**
	 * Last N rows, newest first.
	 *
	 * @param int $limit Row cap (1-500).
	 * @return array<int, array<string, mixed>>
	 */
	public static function list( int $limit = 100 ): array {
		global $wpdb;
		$limit = max( 1, min( 500, $limit ) );
		$rows  = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->prepare(
				'SELECT id, created_at, site_id, site_name, action, status, object_type, object_id, object_title, message FROM ' . self::table() . ' ORDER BY id DESC LIMIT %d',
				$limit
			),
			ARRAY_A
		);
		$rows = is_array( $rows ) ? $rows : array();
		return array_map(
			static function ( array $row ): array {
				$row['id']        = (int) $row['id'];
				$row['site_id']   = (int) $row['site_id'];
				$row['object_id'] = (int) $row['object_id'];
				return $row;
			},
			$rows
		);
	}
}
