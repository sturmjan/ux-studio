<?php
/**
 * Local copy of the DNS change log (display only, no IP).
 *
 * The source of truth is the central app. This table is a fallback for when
 * the central app does not answer and feeds the dashboard widget.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\DnsManager;

defined( 'ABSPATH' ) || exit;

final class LocalLog {

	public static function table(): string {
		global $wpdb;
		return "{$wpdb->prefix}uxstudio_dns_log";
	}

	public static function install(): void {
		\UxStudio\Core\DB::ensure_module_tables(
			'dns-manager',
			1,
			function (): void {
				global $wpdb;
				$charset = $wpdb->get_charset_collate();
				dbDelta(
					"CREATE TABLE {$wpdb->prefix}uxstudio_dns_log (
						id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
						created_at DATETIME NOT NULL,
						batch_id VARCHAR(32) NOT NULL DEFAULT '',
						wp_user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
						wp_user_login VARCHAR(100) NOT NULL DEFAULT '',
						wp_user_name VARCHAR(190) NOT NULL DEFAULT '',
						wp_user_email VARCHAR(255) NOT NULL DEFAULT '',
						action VARCHAR(16) NOT NULL DEFAULT '',
						record_id VARCHAR(64) NOT NULL DEFAULT '',
						record_type VARCHAR(10) NOT NULL DEFAULT '',
						record_name VARCHAR(253) NOT NULL DEFAULT '',
						before_json LONGTEXT NULL,
						after_json LONGTEXT NULL,
						status VARCHAR(10) NOT NULL DEFAULT 'success',
						error VARCHAR(500) NOT NULL DEFAULT '',
						PRIMARY KEY  (id),
						KEY created_at (created_at)
					) {$charset};"
				);
			}
		);
	}

	/**
	 * Store the outcome of a broker write.
	 *
	 * @param string $action  create|update|delete|revert|acknowledged.
	 * @param array  $results CA `results` entries.
	 * @param string $batch   CA batch id.
	 */
	public static function record( string $action, array $results, string $batch ): void {
		global $wpdb;
		$user = wp_get_current_user();
		foreach ( $results as $res ) {
			if ( ! is_array( $res ) || 'success' !== ( $res['status'] ?? '' ) ) {
				continue;
			}
			$after  = is_array( $res['record'] ?? null ) ? $res['record'] : null;
			$before = is_array( $res['before'] ?? null ) ? $res['before'] : null;
			$ref    = $after ?? $before ?? array();
			$wpdb->insert(
				self::table(),
				array(
					'created_at'    => gmdate( 'Y-m-d H:i:s' ),
					'batch_id'      => substr( $batch, 0, 32 ),
					'wp_user_id'    => $user->ID,
					'wp_user_login' => $user->user_login,
					'wp_user_name'  => $user->display_name,
					'wp_user_email' => $user->user_email,
					'action'        => $action,
					'record_id'     => (string) ( $ref['id'] ?? '' ),
					'record_type'   => (string) ( $ref['type'] ?? '' ),
					'record_name'   => (string) ( $ref['name'] ?? '' ),
					'before_json'   => $before ? wp_json_encode( $before ) : null,
					'after_json'    => $after ? wp_json_encode( $after ) : null,
					'status'        => 'success',
				)
			);
		}
	}

	/**
	 * @param int $limit Max rows.
	 * @return array<int, array<string, mixed>>
	 */
	public static function recent( int $limit ): array {
		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare( 'SELECT * FROM ' . self::table() . ' ORDER BY id DESC LIMIT %d', $limit ), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			ARRAY_A
		);
		return is_array( $rows ) ? array_map( array( self::class, 'shape' ), $rows ) : array();
	}

	/**
	 * @param array<string, mixed> $row DB row.
	 * @return array<string, mixed>
	 */
	private static function shape( array $row ): array {
		$row['before'] = $row['before_json'] ? json_decode( (string) $row['before_json'], true ) : null;
		$row['after']  = $row['after_json'] ? json_decode( (string) $row['after_json'], true ) : null;
		unset( $row['before_json'], $row['after_json'] );
		return $row;
	}
}
