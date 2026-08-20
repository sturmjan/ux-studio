<?php
/**
 * Records AI token usage for reporting and limit enforcement.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\AiAssistant;

defined( 'ABSPATH' ) || exit;

final class UsageTracker {

	/**
	 * Log token spend for an authenticated/admin-side feature.
	 */
	public static function log( string $provider, string $model, string $feature, int $input_tokens, int $output_tokens ): void {
		global $wpdb;
		$table = $wpdb->prefix . 'uxstudio_ai_assistant_usage';

		if ( $wpdb->get_var( "SHOW TABLES LIKE '{$table}'" ) !== $table ) {
			return;
		}

		$wpdb->insert(
			$table,
			array(
				'session_id'     => '',
				'user_id'        => get_current_user_id(),
				'provider'       => $provider,
				'model'          => $model,
				'feature'        => $feature,
				'input_tokens'   => $input_tokens,
				'output_tokens'  => $output_tokens,
			),
			array( '%s', '%d', '%s', '%s', '%s', '%d', '%d' )
		);
	}

	/**
	 * Log token spend tied to a public chat session id.
	 */
	public static function log_chat( string $session_id, string $provider, string $model, int $input_tokens, int $output_tokens ): void {
		global $wpdb;
		$table = $wpdb->prefix . 'uxstudio_ai_assistant_usage';

		if ( $wpdb->get_var( "SHOW TABLES LIKE '{$table}'" ) !== $table ) {
			return;
		}

		$wpdb->insert(
			$table,
			array(
				'session_id'    => $session_id,
				'user_id'       => 0,
				'provider'      => $provider,
				'model'         => $model,
				'feature'       => 'chat',
				'input_tokens'  => $input_tokens,
				'output_tokens' => $output_tokens,
			),
			array( '%s', '%d', '%s', '%s', '%s', '%d', '%d' )
		);
	}

	/**
	 * Usage broken down by provider/model/feature for the given period.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function get_stats( int $days = 30 ): array {
		global $wpdb;
		$table = $wpdb->prefix . 'uxstudio_ai_assistant_usage';

		if ( $wpdb->get_var( "SHOW TABLES LIKE '{$table}'" ) !== $table ) {
			return array();
		}

		$since = gmdate( 'Y-m-d H:i:s', strtotime( "-{$days} days" ) );

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT provider, model, feature,
						COUNT(*) as requests,
						SUM(input_tokens) as total_input,
						SUM(output_tokens) as total_output
				 FROM {$table}
				 WHERE created_at >= %s
				 GROUP BY provider, model, feature
				 ORDER BY total_output DESC",
				$since
			),
			ARRAY_A
		) ?: array();
	}

	/**
	 * Aggregate totals for the given period.
	 *
	 * @return array{requests:int,input_tokens:int,output_tokens:int,sessions:int}
	 */
	public static function get_summary( int $days = 30 ): array {
		global $wpdb;
		$table = $wpdb->prefix . 'uxstudio_ai_assistant_usage';

		$empty = array(
			'requests'      => 0,
			'input_tokens'  => 0,
			'output_tokens' => 0,
			'sessions'      => 0,
		);

		if ( $wpdb->get_var( "SHOW TABLES LIKE '{$table}'" ) !== $table ) {
			return $empty;
		}

		$since = gmdate( 'Y-m-d H:i:s', strtotime( "-{$days} days" ) );

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT COUNT(*) as requests,
						COALESCE(SUM(input_tokens), 0) as input_tokens,
						COALESCE(SUM(output_tokens), 0) as output_tokens,
						COUNT(DISTINCT NULLIF(session_id, '')) as sessions
				 FROM {$table}
				 WHERE created_at >= %s",
				$since
			),
			ARRAY_A
		);

		return $row ?: $empty;
	}
}
