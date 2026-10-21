<?php
/**
 * Source: WP Mail Logging, Email Log.
 *
 * @package UxStudio
 */

namespace UxStudio\Core\Migration\Sources;

use UxStudio\Core\Migration\AbstractSource;
use UxStudio\Core\Migration\Mapping;
use UxStudio\Modules\EmailLog\Module as EmailLogModule;

defined( 'ABSPATH' ) || exit;

/**
 * Target module `email-log`: setting `retention_days` and, when the admin ticks "also move the history", the
 * logged e-mails themselves, copied into `uxstudio_email_log` in batches of 500.
 *
 *  - WP Mail Logging: table `{prefix}wpml_mails` (timestamp, receiver, subject, message, headers, attachments, error)
 *    and option `wpml_settings` (`log-rotation-delete-time` + `-days` = delete after N days).
 *  - Email Log: table `{prefix}email_log` (to_email, subject, message, headers, attachments, sent_date, result, error_message);
 *    the free plugin has no settings that map.
 *
 * HARMLESS: a log is only written to and read by the admin, so it is copied directly also when the module is on.
 * Idempotent: a mail that is already in the UX Studio log (same time, recipient and subject) is not copied again.
 * Mails older than the retention period are not copied (the module would delete them at once).
 */
final class EmailLogSource extends AbstractSource {

	private const MODULE = 'email-log';

	protected const BATCH = 500;

	protected function changes_behavior(): bool {
		return false;
	}

	protected function option_names(): array {
		return 'wp-mail-logging' === $this->slug ? array( 'wpml_settings' ) : array();
	}

	/**
	 * Foreign table name (with prefix).
	 */
	private function table(): string {
		global $wpdb;
		return $wpdb->prefix . ( 'wp-mail-logging' === $this->slug ? 'wpml_mails' : 'email_log' );
	}

	/**
	 * Whether a table exists.
	 *
	 * @param string $table Full table name.
	 */
	private function table_exists( string $table ): bool {
		global $wpdb;
		return $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ); // phpcs:ignore WordPress.DB
	}

	/**
	 * Primary key column of the foreign table.
	 */
	private function pk(): string {
		return 'wp-mail-logging' === $this->slug ? 'mail_id' : 'id';
	}

	/**
	 * Mails in the foreign log.
	 */
	private function count_rows(): int {
		global $wpdb;
		$table = $this->table();
		return $this->table_exists( $table ) ? (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ) : 0; // phpcs:ignore WordPress.DB
	}

	protected function has_data(): bool {
		return $this->count_rows() > 0 || parent::has_data();
	}

	public function options(): array {
		return array(
			array(
				'key'     => 'history',
				'label'   => __( 'Also move the history', 'ux-studio' ),
				'help'    => __( 'Copies the e-mails already logged by the old plugin. Mails older than the retention period are skipped, because the module would delete them at once.', 'ux-studio' ),
				'default' => false,
			),
		);
	}

	/**
	 * Retention in days the module will have after the migration.
	 */
	private function retention_days(): int {
		$stored = (array) get_option( 'uxstudio_email_log', array() );
		$days   = (int) ( $stored['retention_days'] ?? 30 );
		if ( 'wp-mail-logging' === $this->slug ) {
			$s = $this->opt( 'wpml_settings' );
			if ( is_array( $s ) && ! empty( $s['log-rotation-delete-time'] ) && (int) ( $s['log-rotation-delete-time-days'] ?? 0 ) > 0 ) {
				$days = (int) $s['log-rotation-delete-time-days'];
			}
		}
		return max( 1, $days );
	}

	protected function map( bool $active ): Mapping {
		$m = new Mapping();
		if ( 'wp-mail-logging' === $this->slug ) {
			$s = $this->opt( 'wpml_settings' );
			if ( is_array( $s ) ) {
				if ( ! empty( $s['log-rotation-delete-time'] ) && (int) ( $s['log-rotation-delete-time-days'] ?? 0 ) > 0 ) {
					$m->set( self::MODULE, 'retention_days', (int) $s['log-rotation-delete-time-days'], __( 'Retention (days)', 'ux-studio' ), 'wpml_settings:log-rotation-delete-time-days' );
				} else {
					$m->lossy( __( 'The plugin kept the log without deleting old entries. The module deletes entries after its retention period (30 days by default); change it in the module settings.', 'ux-studio' ) );
				}
				if ( ! empty( $s['log-rotation-limit-amout'] ) ) {
					$m->lossy( __( 'Keeping only the latest N entries has no equivalent; the module deletes entries by age only.', 'ux-studio' ) );
				}
			}
		}
		if ( $this->count_rows() > 0 ) {
			$m->note( __( 'The logged e-mails can be copied too: tick "Also move the history" for this plugin.', 'ux-studio' ) );
		} elseif ( $m->is_empty() ) {
			$m->note( __( 'The plugin has no settings or logged e-mails, there is nothing to transfer.', 'ux-studio' ) );
		}
		if ( 'email-log' === $this->slug ) {
			$m->lossy( __( 'The free plugin has no settings that map to the module. IP address and attachment content of the logged mails are not copied.', 'ux-studio' ) );
		}
		return $m;
	}

	protected function data_kind(): string {
		return 'email';
	}

	protected function data_label(): string {
		return __( 'Logged e-mails', 'ux-studio' );
	}

	protected function data_total( array $options ): int {
		return ! empty( $options['history'] ) ? $this->count_rows() : 0;
	}

	protected function data_plan( array $options ): array {
		return array(
			array(
				'key'   => 'email',
				'label' => __( 'Logged e-mails in the old plugin (copied only if you tick "Also move the history")', 'ux-studio' ),
				'count' => $this->count_rows(),
			),
		);
	}

	protected function read_batch( $position, int $limit, array $options ): array {
		global $wpdb;
		$table = $this->table();
		$pk    = $this->pk();
		$after = (int) $position;
		if ( ! $this->table_exists( $table ) ) {
			return array(
				'items' => array(),
				'next'  => $after,
				'done'  => true,
			);
		}
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE {$pk} > %d ORDER BY {$pk} ASC LIMIT %d", $after, $limit ), ARRAY_A ); // phpcs:ignore WordPress.DB
		$rows = is_array( $rows ) ? $rows : array();
		$out  = array();
		$last = $after;
		foreach ( $rows as $row ) {
			$last  = (int) $row[ $pk ];
			$out[] = $this->normalize( $row );
		}
		return array(
			'items' => $out,
			'next'  => $last,
			'done'  => count( $rows ) < $limit,
		);
	}

	/**
	 * One foreign row as an item.
	 *
	 * @param array<string, mixed> $row Row.
	 * @return array<string, mixed>
	 */
	private function normalize( array $row ): array {
		if ( 'wp-mail-logging' === $this->slug ) {
			$error = trim( (string) ( $row['error'] ?? '' ) );
			$att   = trim( (string) ( $row['attachments'] ?? '' ) );
			return array(
				'key'         => 'wpml:' . (int) $row['mail_id'],
				'label'       => (string) $row['subject'],
				'created_at'  => (string) $row['timestamp'],
				'to_email'    => (string) $row['receiver'],
				'subject'     => (string) $row['subject'],
				'status'      => '' === $error ? 'success' : 'error',
				'error'       => $error,
				'message'     => (string) ( $row['message'] ?? '' ),
				'headers'     => (string) ( $row['headers'] ?? '' ),
				'attachments' => '' === $att || '0' === $att ? array() : array_map( 'trim', explode( ',', $att ) ),
			);
		}
		$att = trim( (string) ( $row['attachments'] ?? '' ) );
		return array(
			'key'         => 'el:' . (int) $row['id'],
			'label'       => (string) $row['subject'],
			'created_at'  => (string) $row['sent_date'],
			'to_email'    => (string) $row['to_email'],
			'subject'     => (string) $row['subject'],
			'status'      => isset( $row['result'] ) && '0' === (string) $row['result'] ? 'error' : 'success',
			'error'       => (string) ( $row['error_message'] ?? '' ),
			'message'     => (string) ( $row['message'] ?? '' ),
			'headers'     => (string) ( $row['headers'] ?? '' ),
			'attachments' => '' === $att ? array() : array_map( 'basename', array_map( 'trim', explode( "\n", str_replace( ',', "\n", $att ) ) ) ),
		);
	}

	public function target_tables(): array {
		return array( 'uxstudio_email_log' );
	}

	protected function prepare_data(): void {
		EmailLogModule::ensure_tables();
	}

	/**
	 * Id of the UX Studio log row for the same mail, or 0.
	 *
	 * @param array<string, mixed> $item Item.
	 */
	private function existing( array $item ): int {
		global $wpdb;
		$table = $wpdb->prefix . 'uxstudio_email_log';
		if ( ! $this->table_exists( $table ) ) {
			return 0;
		}
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE created_at = %s AND to_email = %s AND subject = %s LIMIT 1", mb_substr( (string) $item['created_at'], 0, 19 ), mb_substr( (string) $item['to_email'], 0, 255 ), mb_substr( (string) $item['subject'], 0, 255 ) ) ); // phpcs:ignore WordPress.DB
	}

	/**
	 * Is the mail too old for the module's retention?
	 *
	 * @param array<string, mixed> $item Item.
	 */
	private function too_old( array $item ): bool {
		$ts = strtotime( (string) $item['created_at'] );
		return $ts && $ts < time() - $this->retention_days() * DAY_IN_SECONDS;
	}

	protected function preview_item( array $item, array $ctx ): array {
		if ( $this->too_old( $item ) ) {
			return array( 'status' => 'skipped' );
		}
		return array( 'status' => $this->existing( $item ) ? 'unchanged' : 'created' );
	}

	protected function apply_item( array $item, array $ctx ): array {
		global $wpdb;
		if ( $this->too_old( $item ) ) {
			return array( 'status' => 'skipped' );
		}
		if ( $this->existing( $item ) ) {
			return array( 'status' => 'unchanged' );
		}
		$ok = $wpdb->insert(
			$wpdb->prefix . 'uxstudio_email_log',
			array(
				'created_at'    => mb_substr( (string) $item['created_at'], 0, 19 ),
				'to_email'      => mb_substr( (string) $item['to_email'], 0, 255 ),
				'subject'       => mb_substr( (string) $item['subject'], 0, 255 ),
				'status'        => (string) $item['status'],
				'error_message' => '' === $item['error'] ? null : (string) $item['error'],
				'source'        => 'migration: ' . $this->slug,
				'message'       => (string) $item['message'],
				'headers'       => (string) $item['headers'],
				'attachments'   => wp_json_encode( array_values( $item['attachments'] ) ),
			)
		);
		return false === $ok
			? array(
				'status'  => 'error',
				'message' => __( 'A logged e-mail could not be copied.', 'ux-studio' ),
			)
			: array( 'status' => 'created' );
	}

	protected function verify_item( array $item ): array {
		if ( $this->too_old( $item ) || $this->existing( $item ) ) {
			return array(
				'ok'     => true,
				'detail' => '',
			);
		}
		return array(
			'ok'     => false,
			'detail' => __( 'The e-mail is not in the UX Studio log.', 'ux-studio' ),
		);
	}
}
