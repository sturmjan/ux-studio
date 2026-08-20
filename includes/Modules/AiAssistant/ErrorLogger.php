<?php
/**
 * Logs why the AI chat could not fulfil a request.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\AiAssistant;

defined( 'ABSPATH' ) || exit;

/**
 * Records provider failures, validation errors, rate limiting and
 * configuration problems so admins can debug the AI Assistant module.
 */
final class ErrorLogger {

	public const TYPE_PROVIDER   = 'provider';
	public const TYPE_VALIDATION = 'validation';
	public const TYPE_RATELIMIT  = 'ratelimit';
	public const TYPE_CONFIG     = 'config';
	public const TYPE_UNKNOWN    = 'unknown';

	/**
	 * @param array<string, mixed> $data Error details.
	 */
	public static function log( array $data ): void {
		global $wpdb;
		$table = $wpdb->prefix . 'uxstudio_ai_assistant_error_log';

		$row = array(
			'session_id'       => substr( (string) ( $data['session_id'] ?? '' ), 0, 64 ),
			'provider'         => substr( (string) ( $data['provider'] ?? '' ), 0, 50 ),
			'model'            => substr( (string) ( $data['model'] ?? '' ), 0, 100 ),
			'error_type'       => substr( (string) ( $data['error_type'] ?? self::TYPE_UNKNOWN ), 0, 50 ),
			'error_message'    => (string) ( $data['error_message'] ?? '' ),
			'http_status'      => isset( $data['http_status'] ) ? (int) $data['http_status'] : null,
			'response_excerpt' => isset( $data['response_excerpt'] )
				? substr( (string) $data['response_excerpt'], 0, 2000 )
				: null,
			'user_message'     => isset( $data['user_message'] )
				? substr( (string) $data['user_message'], 0, 2000 )
				: null,
			'page_url'         => substr( (string) ( $data['page_url'] ?? '' ), 0, 500 ),
			'visitor_ip'       => substr( (string) ( $data['visitor_ip'] ?? self::get_visitor_ip() ), 0, 45 ),
			'context'          => ! empty( $data['context'] ) ? wp_json_encode( $data['context'] ) : null,
			'created_at'       => current_time( 'mysql' ),
		);

		$wpdb->insert( $table, $row );

		// Mirror to the PHP error log for easier debugging.
		error_log( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			sprintf(
				'AI Assistant [%s] %s%s: %s',
				$row['error_type'],
				$row['provider'] ? $row['provider'] . ' ' : '',
				$row['http_status'] ? '(HTTP ' . $row['http_status'] . ')' : '',
				$row['error_message']
			)
		);
	}

	/**
	 * @param array<string, string> $filters Optional filters: type, provider, search.
	 * @return array<int, object>
	 */
	public static function get_entries( int $page = 1, int $per_page = 30, array $filters = array() ): array {
		global $wpdb;
		$table  = $wpdb->prefix . 'uxstudio_ai_assistant_error_log';
		$offset = max( 0, ( $page - 1 ) * $per_page );

		$where  = '1=1';
		$params = array();

		if ( ! empty( $filters['type'] ) ) {
			$where   .= ' AND error_type = %s';
			$params[] = $filters['type'];
		}
		if ( ! empty( $filters['provider'] ) ) {
			$where   .= ' AND provider = %s';
			$params[] = $filters['provider'];
		}
		if ( ! empty( $filters['search'] ) ) {
			$where   .= ' AND (error_message LIKE %s OR user_message LIKE %s)';
			$like     = '%' . $wpdb->esc_like( $filters['search'] ) . '%';
			$params[] = $like;
			$params[] = $like;
		}

		$params[] = $per_page;
		$params[] = $offset;

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE {$where} ORDER BY created_at DESC LIMIT %d OFFSET %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				...$params
			)
		);
	}

	/**
	 * @param array<string, string> $filters Optional filters: type, provider, search.
	 */
	public static function get_count( array $filters = array() ): int {
		global $wpdb;
		$table = $wpdb->prefix . 'uxstudio_ai_assistant_error_log';

		$where  = '1=1';
		$params = array();

		if ( ! empty( $filters['type'] ) ) {
			$where   .= ' AND error_type = %s';
			$params[] = $filters['type'];
		}
		if ( ! empty( $filters['provider'] ) ) {
			$where   .= ' AND provider = %s';
			$params[] = $filters['provider'];
		}
		if ( ! empty( $filters['search'] ) ) {
			$where   .= ' AND (error_message LIKE %s OR user_message LIKE %s)';
			$like     = '%' . $wpdb->esc_like( $filters['search'] ) . '%';
			$params[] = $like;
			$params[] = $like;
		}

		if ( ! empty( $params ) ) {
			return (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$table} WHERE {$where}", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					...$params
				)
			);
		}
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE {$where}" );
	}

	public static function clear(): int {
		global $wpdb;
		$table = $wpdb->prefix . 'uxstudio_ai_assistant_error_log';
		return (int) $wpdb->query( "DELETE FROM {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	public static function delete_older_than_days( int $days ): int {
		global $wpdb;
		if ( $days <= 0 ) {
			return 0;
		}
		$table = $wpdb->prefix . 'uxstudio_ai_assistant_error_log';
		return (int) $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$table} WHERE created_at < DATE_SUB(NOW(), INTERVAL %d DAY)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$days
			)
		);
	}

	/**
	 * Extracts HTTP status from an exception message when it follows the
	 * "Provider API: HTTP xxx: body" pattern used by the provider adapters.
	 *
	 * @return array{http_status:?int,excerpt:?string}
	 */
	public static function parse_exception_detail( string $message ): array {
		$result = array(
			'http_status' => null,
			'excerpt'     => null,
		);
		if ( preg_match( '/HTTP\s+(\d{3})/', $message, $m ) ) {
			$result['http_status'] = (int) $m[1];
		}
		return $result;
	}

	private static function get_visitor_ip(): string {
		$headers = array( 'HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR' );
		foreach ( $headers as $header ) {
			if ( ! empty( $_SERVER[ $header ] ) ) {
				$ip = sanitize_text_field( wp_unslash( $_SERVER[ $header ] ) );
				if ( str_contains( $ip, ',' ) ) {
					$ip = trim( explode( ',', $ip )[0] );
				}
				if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
					return $ip;
				}
			}
		}
		return '';
	}
}
