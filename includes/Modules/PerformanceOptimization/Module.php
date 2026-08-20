<?php
/**
 * Performance Optimization module - read-only health checks + a small
 * whitelist of safe cleanup actions.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\PerformanceOptimization;

use UxStudio\Core\ActivityLog;
use UxStudio\Core\DB;
use UxStudio\Modules\BaseModule;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Ported/redesigned from the legacy performance-optimization module with a
 * DELIBERATELY NARROWED scope (documented, see project report):
 *
 * The legacy module could dump the entire database and expose phpinfo() to
 * the browser - both are serious information-disclosure risks and are NOT
 * ported. This module only ever runs read-only SELECT COUNT()-style checks
 * and a whitelist of three safe, standard-WP-function cleanup actions. There
 * is no DB export endpoint, no phpinfo endpoint and no SQL query monitor/log.
 */
final class Module extends BaseModule {

	private const FIXES = array( 'revisions', 'expired_transients', 'spam_comments' );

	/**
	 * Register hooks.
	 */
	public function boot(): void {
		add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );

		DB::ensure_module_tables(
			'performance-optimization',
			1,
			function ( int $from ): void {
				global $wpdb;
				$charset = $wpdb->get_charset_collate();
				dbDelta(
					"CREATE TABLE {$wpdb->prefix}uxstudio_performance_history (
						id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
						created_at DATETIME NOT NULL,
						score INT NOT NULL DEFAULT 0,
						details LONGTEXT NULL,
						PRIMARY KEY  (id),
						KEY created_at (created_at)
					) {$charset};"
				);
			}
		);
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

	// =====================================================================
	// Analyze
	// =====================================================================

	/**
	 * Run the read-only analysis, store a history row and return the result.
	 *
	 * @return array{score:int,metrics:array<string,int>}
	 */
	public function analyze(): array {
		global $wpdb;

		$revisions = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'revision'"
		);
		$spam_comments = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->comments} WHERE comment_approved = 'spam'"
		);
		$auto_drafts = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status = 'auto-draft'"
		);
		$transient_like = $wpdb->esc_like( '_transient_' ) . '%';
		$transients_count = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE %s", $transient_like )
		);
		$transients_bytes = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COALESCE(SUM(LENGTH(option_value)), 0) FROM {$wpdb->options} WHERE option_name LIKE %s", $transient_like )
		);

		$metrics = array(
			'revisions'         => $revisions,
			'spam_comments'     => $spam_comments,
			'auto_drafts'       => $auto_drafts,
			'transients_count'  => $transients_count,
			'transients_bytes'  => $transients_bytes,
		);

		$score = $this->score_metrics( $metrics );

		$this->save_history( $score, $metrics );

		return array(
			'score'   => $score,
			'metrics' => $metrics,
		);
	}

	/**
	 * Simple weighted-penalty scoring, clamped to 0-100.
	 *
	 * @param array<string,int> $metrics Raw metrics from analyze().
	 */
	private function score_metrics( array $metrics ): int {
		$score = 100;
		$score -= min( 30, (int) floor( $metrics['revisions'] / 10 ) );
		$score -= min( 20, (int) floor( $metrics['spam_comments'] / 5 ) );
		$score -= min( 15, (int) floor( $metrics['auto_drafts'] / 5 ) );
		$score -= min( 20, (int) floor( $metrics['transients_count'] / 25 ) );
		return max( 0, min( 100, $score ) );
	}

	/**
	 * @param int                $score   Computed score.
	 * @param array<string, int> $metrics Raw metrics.
	 */
	private function save_history( int $score, array $metrics ): void {
		global $wpdb;
		$wpdb->insert(
			"{$wpdb->prefix}uxstudio_performance_history",
			array(
				'created_at' => current_time( 'mysql' ),
				'score'      => $score,
				'details'    => wp_json_encode( $metrics ),
			),
			array( '%s', '%d', '%s' )
		);
	}

	// =====================================================================
	// Fix (whitelisted)
	// =====================================================================

	/**
	 * Run one whitelisted cleanup action.
	 *
	 * @param string $fix_id One of self::FIXES.
	 * @return array{fix_id:string,affected:int}|WP_Error
	 */
	public function fix( string $fix_id ) {
		if ( ! in_array( $fix_id, self::FIXES, true ) ) {
			return new WP_Error( 'uxstudio_unknown_fix', __( 'Unknown or unsupported fix.', 'ux-studio' ), array( 'status' => 400 ) );
		}

		$affected = 0;
		switch ( $fix_id ) {
			case 'revisions':
				$affected = $this->fix_revisions();
				break;
			case 'expired_transients':
				$affected = $this->fix_expired_transients();
				break;
			case 'spam_comments':
				$affected = $this->fix_spam_comments();
				break;
		}

		ActivityLog::log( 'performance-optimization', 'fix', $fix_id, 0, array( 'affected' => $affected ) );

		return array( 'fix_id' => $fix_id, 'affected' => $affected );
	}

	/** Delete all post revisions via the standard WP function. */
	private function fix_revisions(): int {
		$ids = get_posts(
			array(
				'post_type'      => 'revision',
				'post_status'    => 'inherit',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
			)
		);
		$count = 0;
		foreach ( $ids as $id ) {
			if ( wp_delete_post_revision( (int) $id ) ) {
				++$count;
			}
		}
		return $count;
	}

	/** Delete expired transients via the standard WP helper (or a scoped fallback query). */
	private function fix_expired_transients(): int {
		global $wpdb;

		$timeout_like = $wpdb->esc_like( '_transient_timeout_' ) . '%';

		if ( function_exists( 'delete_expired_transients' ) ) {
			$before = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE %s", $timeout_like ) );
			delete_expired_transients( true );
			$after = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE %s", $timeout_like ) );
			return max( 0, $before - $after );
		}

		$time    = time();
		$timeout = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s AND option_value < %d",
				$wpdb->esc_like( '_transient_timeout_' ) . '%',
				$time
			)
		);

		$count = 0;
		foreach ( (array) $timeout as $timeout_name ) {
			$key = substr( (string) $timeout_name, strlen( '_transient_timeout_' ) );
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s", '_transient_timeout_' . $key ) );
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s", '_transient_' . $key ) );
			++$count;
		}
		return $count;
	}

	/** Permanently delete spam comments via the standard WP function. */
	private function fix_spam_comments(): int {
		$comments = get_comments(
			array(
				'status' => 'spam',
				'fields' => 'ids',
			)
		);
		$count = 0;
		foreach ( $comments as $id ) {
			if ( wp_delete_comment( (int) $id, true ) ) {
				++$count;
			}
		}
		return $count;
	}

	// =====================================================================
	// History
	// =====================================================================

	/**
	 * Last 50 history rows, newest first.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function get_history(): array {
		global $wpdb;
		$rows = $wpdb->get_results(
			"SELECT id, created_at, score, details FROM {$wpdb->prefix}uxstudio_performance_history ORDER BY id DESC LIMIT 50",
			ARRAY_A
		);
		$rows = is_array( $rows ) ? $rows : array();
		return array_map(
			static function ( array $row ): array {
				$decoded = json_decode( (string) $row['details'], true );
				return array(
					'id'         => (int) $row['id'],
					'created_at' => $row['created_at'],
					'score'      => (int) $row['score'],
					'metrics'    => is_array( $decoded ) ? $decoded : array(),
				);
			},
			$rows
		);
	}
}
