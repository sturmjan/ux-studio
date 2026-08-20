<?php
/**
 * Shared activity/audit log writer.
 *
 * @package UxStudio
 */

namespace UxStudio\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Thin insert-only helper around the shared uxstudio_activity_log table
 * (created in DB::migrate_1, always present regardless of enabled modules).
 * Other modules call ActivityLog::log() to record notable actions.
 */
final class ActivityLog {

	/**
	 * Insert one activity log entry.
	 *
	 * @param string $module      Module id (kebab-case) recording the entry.
	 * @param string $action      Short action key, e.g. 'login', 'delete'.
	 * @param string $object_type Optional object type, e.g. 'user', 'post'.
	 * @param int    $object_id   Optional object id.
	 * @param array  $meta        Optional extra context, stored as JSON.
	 */
	public static function log( string $module, string $action, string $object_type = '', int $object_id = 0, array $meta = array() ): void {
		global $wpdb;

		$wpdb->insert(
			"{$wpdb->prefix}uxstudio_activity_log",
			array(
				'created_at'  => current_time( 'mysql' ),
				'user_id'     => get_current_user_id(),
				'module'      => $module,
				'action'      => $action,
				'object_type' => $object_type,
				'object_id'   => $object_id,
				'meta'        => empty( $meta ) ? null : wp_json_encode( $meta ),
			),
			array( '%s', '%d', '%s', '%s', '%s', '%d', '%s' )
		);
	}
}
