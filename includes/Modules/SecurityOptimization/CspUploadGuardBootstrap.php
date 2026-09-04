<?php
/**
 * Bootstrap for the CSP violation reporting + Upload Guard sub-features.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\SecurityOptimization;

use UxStudio\Core\DB;

defined( 'ABSPATH' ) || exit;

/**
 * This is a self-contained bootstrap point, deliberately separate from the
 * Security Optimization module's own Module.php (owned by another part of
 * this port covering login/IP-ban/htaccess protections). Call
 * `\UxStudio\Modules\SecurityOptimization\CspUploadGuardBootstrap::register()`
 * once from that Module.php's boot() (or from rest_api_init directly for
 * the REST half) - no arguments needed, everything here is self-configuring.
 *
 * Uses its OWN schema-version namespace ('security-optimization-csp-upload')
 * with DB::ensure_module_tables() so it never collides with the sibling
 * module's own `ensure_module_tables( 'security-optimization', ... )` call.
 */
final class CspUploadGuardBootstrap {

	private const DB_MODULE_ID = 'security-optimization-csp-upload';
	private const DB_VERSION   = 1;

	private static bool $registered = false;

	/**
	 * Register REST routes, upload/scan hooks, and cron. Safe to call more
	 * than once (subsequent calls are no-ops).
	 *
	 * Tables and the two REST controllers are always registered (CSP violation
	 * reporting must keep working regardless). The Upload Guard scanner hooks
	 * and its nightly cron are only wired when $upload_guard_enabled is true;
	 * when disabled, any previously scheduled cron event is cleared.
	 *
	 * @param bool $upload_guard_enabled Whether the malware scanner is active.
	 */
	public static function register( bool $upload_guard_enabled = true ): void {
		if ( self::$registered ) {
			return;
		}
		self::$registered = true;

		self::ensure_tables();

		add_action( 'rest_api_init', array( self::class, 'register_rest_routes' ) );

		if ( ! $upload_guard_enabled ) {
			self::on_deactivate();
			return;
		}

		$scanner = new UploadGuardScanner();

		add_filter( 'wp_handle_upload', array( $scanner, 'handle_upload' ), 10, 2 );
		add_action( 'add_attachment', array( $scanner, 'handle_add_attachment' ) );

		add_action( UploadGuardScanner::QUEUE_BATCH_EVENT, array( $scanner, 'run_queue_batch' ) );
		add_action( UploadGuardScanner::FULL_SCAN_EVENT, array( $scanner, 'run_full_scan_batch' ) );
		add_action( UploadGuardScanner::DAILY_CRON_HOOK, array( $scanner, 'run_daily_cron' ) );

		self::ensure_cron_scheduled();
	}

	/**
	 * Register the two REST controllers under uxstudio/v1.
	 */
	public static function register_rest_routes(): void {
		( new CspRestController() )->register_routes();
		( new UploadGuardRestController() )->register_routes();
	}

	/**
	 * Create/upgrade this sub-feature's three tables: CSP violations,
	 * security findings, and the shared file-hash cache.
	 */
	private static function ensure_tables(): void {
		DB::ensure_module_tables(
			self::DB_MODULE_ID,
			self::DB_VERSION,
			static function ( int $from ): void {
				global $wpdb;
				$charset = $wpdb->get_charset_collate();

				dbDelta(
					"CREATE TABLE {$wpdb->prefix}uxstudio_csp_violations (
						id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
						fingerprint char(32) NOT NULL,
						directive varchar(64) NOT NULL DEFAULT '',
						blocked_host varchar(255) NOT NULL DEFAULT '',
						blocked_uri text NOT NULL,
						document_uri text DEFAULT '',
						source_file text DEFAULT '',
						sample_user_agent varchar(500) DEFAULT '',
						sample_ip varchar(45) DEFAULT '',
						hit_count int(11) UNSIGNED NOT NULL DEFAULT 1,
						status varchar(20) NOT NULL DEFAULT 'open',
						first_seen datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
						last_seen datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
						PRIMARY KEY  (id),
						UNIQUE KEY uniq_fp (fingerprint),
						KEY status (status),
						KEY last_seen (last_seen)
					) {$charset};"
				);

				dbDelta(
					"CREATE TABLE {$wpdb->prefix}uxstudio_security_findings (
						id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
						created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
						file_path text NOT NULL,
						finding_type varchar(32) NOT NULL DEFAULT '',
						severity varchar(20) NOT NULL DEFAULT '',
						status varchar(20) NOT NULL DEFAULT 'queued',
						hash varchar(64) NOT NULL DEFAULT '',
						details longtext NULL,
						scanned_at datetime NULL,
						PRIMARY KEY  (id),
						KEY status (status),
						KEY severity (severity),
						KEY created_at (created_at)
					) {$charset};"
				);

				dbDelta(
					"CREATE TABLE {$wpdb->prefix}uxstudio_security_file_hashes (
						id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
						file_path varchar(500) NOT NULL DEFAULT '',
						hash varchar(64) NOT NULL DEFAULT '',
						approved tinyint(1) NOT NULL DEFAULT 0,
						updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
						PRIMARY KEY  (id),
						UNIQUE KEY uniq_path (file_path)
					) {$charset};"
				);
			}
		);
	}

	/**
	 * Schedule the nightly cron event if it is not already scheduled.
	 */
	private static function ensure_cron_scheduled(): void {
		if ( ! wp_next_scheduled( UploadGuardScanner::DAILY_CRON_HOOK ) ) {
			wp_schedule_event( strtotime( 'tomorrow 03:00' ), 'daily', UploadGuardScanner::DAILY_CRON_HOOK );
		}
	}

	/**
	 * Unschedule cron events. Does NOT delete any data. Call this from the
	 * plugin's own deactivation hook if desired.
	 */
	public static function on_deactivate(): void {
		foreach ( array( UploadGuardScanner::DAILY_CRON_HOOK, UploadGuardScanner::QUEUE_BATCH_EVENT, UploadGuardScanner::FULL_SCAN_EVENT ) as $hook ) {
			$timestamp = wp_next_scheduled( $hook );
			if ( $timestamp ) {
				wp_unschedule_event( $timestamp, $hook );
			}
		}
	}
}
