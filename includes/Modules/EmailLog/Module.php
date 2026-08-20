<?php
/**
 * Email Log module - record every outgoing wp_mail() send.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\EmailLog;

use UxStudio\Modules\BaseModule;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Independent from the SMTP Email module's delivery log (uxstudio_smtp_logs):
 * this module keeps its own uxstudio_email_log table so it can be enabled on
 * its own, purely as an outgoing-mail audit trail with its own retention.
 */
final class Module extends BaseModule {

	private const RETENTION_LOCK = 'uxstudio_email_log_retention_lock';

	/**
	 * Register hooks.
	 */
	public function boot(): void {
		add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );

		\UxStudio\Core\DB::ensure_module_tables(
			'email-log',
			1,
			function ( int $from ): void {
				global $wpdb;
				$charset = $wpdb->get_charset_collate();
				dbDelta(
					"CREATE TABLE {$wpdb->prefix}uxstudio_email_log (
						id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
						created_at DATETIME NOT NULL,
						to_email VARCHAR(255) NOT NULL DEFAULT '',
						subject VARCHAR(255) NOT NULL DEFAULT '',
						status VARCHAR(20) NOT NULL DEFAULT '',
						error_message TEXT NULL,
						PRIMARY KEY  (id),
						KEY created_at (created_at)
					) {$charset};"
				);
			}
		);

		add_action( 'wp_mail_succeeded', array( $this, 'log_success' ) );
		add_action( 'wp_mail_failed', array( $this, 'log_failure' ) );
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
				'key'     => 'retention_days',
				'type'    => 'number',
				'label'   => __( 'Retention (days)', 'ux-studio' ),
				'help'    => __( 'Log entries older than this are pruned automatically.', 'ux-studio' ),
				'default' => 30,
			),
		);
	}

	/**
	 * Log a successful send (hooked on wp_mail_succeeded, WP 5.9+).
	 *
	 * @param array $mail_data Same shape as the wp_mail() arguments.
	 */
	public function log_success( array $mail_data ): void {
		$this->insert_log( $mail_data['to'] ?? '', $mail_data['subject'] ?? '', 'success', '' );
		$this->maybe_prune();
	}

	/**
	 * Log a failed send (hooked on wp_mail_failed).
	 *
	 * @param WP_Error $error Error carrying the original mail_data.
	 */
	public function log_failure( WP_Error $error ): void {
		$data    = $error->get_error_data();
		$to      = is_array( $data ) ? ( $data['to'] ?? '' ) : '';
		$subject = is_array( $data ) ? ( $data['subject'] ?? '' ) : '';
		$this->insert_log( $to, $subject, 'error', $error->get_error_message() );
		$this->maybe_prune();
	}

	/**
	 * @param string|string[] $to      Recipient(s).
	 * @param string          $subject Subject.
	 * @param string          $status  success|error.
	 * @param string          $error   Error message, if any.
	 */
	private function insert_log( $to, string $subject, string $status, string $error ): void {
		global $wpdb;
		$to_string = is_array( $to ) ? implode( ', ', $to ) : (string) $to;

		$wpdb->insert(
			"{$wpdb->prefix}uxstudio_email_log",
			array(
				'created_at'    => current_time( 'mysql' ),
				'to_email'      => mb_substr( $to_string, 0, 255 ),
				'subject'       => mb_substr( $subject, 0, 255 ),
				'status'        => $status,
				'error_message' => '' === $error ? null : $error,
			),
			array( '%s', '%s', '%s', '%s', '%s' )
		);
	}

	/**
	 * Opportunistically prune entries past retention, at most once/hour
	 * (transient-gated compare-and-swap, same pattern as EmailHealth).
	 */
	private function maybe_prune(): void {
		if ( false !== get_transient( self::RETENTION_LOCK ) ) {
			return;
		}
		set_transient( self::RETENTION_LOCK, 1, HOUR_IN_SECONDS );
		$this->purge_old_entries();
	}

	/**
	 * Paginated log entries for the SPA table.
	 *
	 * @param int $limit  Max rows (default 50, capped at 200).
	 * @param int $offset Offset.
	 * @return array{items:array<int,array<string,mixed>>,total:int}
	 */
	public function get_entries( int $limit = 50, int $offset = 0 ): array {
		global $wpdb;

		$limit  = max( 1, min( 200, $limit ) );
		$offset = max( 0, $offset );

		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}uxstudio_email_log" );
		$items = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, created_at, to_email, subject, status, error_message
				FROM {$wpdb->prefix}uxstudio_email_log ORDER BY id DESC LIMIT %d OFFSET %d",
				$limit,
				$offset
			),
			ARRAY_A
		);

		return array(
			'items' => is_array( $items ) ? $items : array(),
			'total' => $total,
		);
	}

	/**
	 * Delete a single entry.
	 *
	 * @param int $id Row id.
	 */
	public function delete_entry( int $id ): bool {
		global $wpdb;
		return false !== $wpdb->delete( "{$wpdb->prefix}uxstudio_email_log", array( 'id' => $id ), array( '%d' ) );
	}

	/**
	 * Delete every entry.
	 */
	public function clear_all(): int {
		global $wpdb;
		return (int) $wpdb->query( "TRUNCATE TABLE {$wpdb->prefix}uxstudio_email_log" );
	}

	/**
	 * Delete entries older than the configured retention window.
	 */
	private function purge_old_entries(): int {
		global $wpdb;
		$days   = max( 1, (int) $this->settings->get( 'retention_days', 30 ) );
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) );

		return (int) $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->prefix}uxstudio_email_log WHERE created_at < %s",
				$cutoff
			)
		);
	}
}
