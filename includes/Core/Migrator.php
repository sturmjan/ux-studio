<?php
/**
 * One-time import of settings and data from the legacy ux1 plugin.
 *
 * Source of truth: AUDIT.md "Migrační mapa dat". Booking data
 * (reservation-calendar) is intentionally NOT migrated.
 *
 * @package UxStudio
 */

namespace UxStudio\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Idempotent importer, safe to call repeatedly; runs once unless dry-run.
 */
final class Migrator {

	private const DONE_OPTION = 'uxstudio_migrated_from_ux1';

	/**
	 * Module ids whose framework settings (wpextended__{id}_settings) are
	 * migrated to uxstudio_{id}_settings. reservation-calendar excluded.
	 *
	 * @var string[]
	 */
	private const MODULE_IDS = array(
		'activity-log', 'admin-columns', 'admin-customiser', 'ai-assistant',
		'ai-markdown', 'auto-image-upload', 'auto-unpublish', 'bot-throttle',
		'classic-editor', 'classic-widgets', 'claude-panel', 'clean-profiles',
		'code-snippets', 'content-sync', 'cron-control', 'dashboard-widgets',
		'debug-mode', 'disable-auto-updates', 'disable-video-uploads',
		'download-files', 'duplicate-menu', 'duplicate-post', 'elementor-import',
		'email-health', 'email-log', 'exit-popup', 'export-posts', 'export-users',
		'external-permalinks', 'file-manager', 'folder-manager',
		'google-review-request', 'guide', 'hide-admin-bar', 'image-optimizer',
		'indexing-notice', 'instagram-feed', 'link-manager', 'maintenance-mode',
		'media-replace', 'media-trash', 'menu-visibility', 'notice-board',
		'opening-hours', 'page-load', 'performance-optimization',
		'pixel-tag-manager', 'popup-manager', 'post-gallery', 'post-id-display',
		'post-type-order', 'post-type-switcher', 'push-notifications',
		'quick-add-post', 'quick-image', 'redirect-404-to-homepage',
		'review-aggregator', 'rollback-manager', 'security-optimization',
		'service-requests', 'smtp-email', 'stock-photos', 'svg-upload',
		'third-party-login', 'top-bar', 'user-last-login', 'user-switching',
		'vulnerability-scanner',
	);

	/**
	 * Non-framework legacy option key => new key. `%` = wildcard (LIKE match).
	 *
	 * @var array<string, string>
	 */
	private const OPTION_MAP = array(
		// db-version / value options for modules already ported with their own
		// DB::ensure_module_tables schema or a redesigned option shape are
		// intentionally absent here - the legacy shape doesn't map to the new
		// one: activity-log, email-log, page-load, ai-markdown, bot-throttle,
		// exit-popup, google-review-request, popup-manager, smtp-email,
		// notice-board (own dbv scheme), push-notifications (VAPID keys are
		// now auto-generated into a different public option + encrypted
		// secret, not migratable from the legacy blob), review-aggregator
		// (now syncs via the content-sync broker, not a direct API - the
		// legacy import_token/last_sync/central_etag fields have no new
		// equivalent; central_profile_id lives in the module's settings
		// schema instead of a standalone option).
		'wpextended__admin-columns_defaults'     => 'uxstudio_admin_columns_defaults',
		'wpext_search_module_info'               => 'uxstudio_search_module_info',
		// ux1_ai_assistant_blog_pilot_db_version removed: blog-pilot tables now
		// live under ai-assistant's own DB::ensure_module_tables() versioning
		// (uxstudio_dbv_ai_assistant), the legacy per-feature db-version option
		// has no equivalent to migrate into.
		'ux1_ai_assistant_keys_migrated'         => 'uxstudio_ai_assistant_keys_migrated',
		// ux1_email_health_result removed: EmailHealth::OPTION_RESULT owns
		// 'uxstudio_email_health_result' directly (its own result shape).
		'ux1_query_monitor_settings'             => 'uxstudio_query_monitor_settings',
		// ux1_security_htaccess_hash removed: SecurityOptimization's
		// HtaccessWriter::HASH_OPTION owns 'uxstudio_security_htaccess_hash' and
		// tracks the hash of the .htaccess block IT writes - seeding a legacy
		// hash value would just be stale/meaningless for the new writer.
		'ux1_csp_violations_db_version'          => 'uxstudio_csp_violations_db_version',
		// ux1_vulnerability_scan_result removed: VulnerabilityScanner::OPTION_RESULT
		// owns 'uxstudio_vulnerability_scan_result' in its own result shape.
		'ux1_vulnerability_notice_dismissed'     => 'uxstudio_vulnerability_notice_dismissed',
		'ux1_dashboard_forced_layout'            => 'uxstudio_dashboard_forced_layout',
		'ux1_dashboard_forced_closed'            => 'uxstudio_dashboard_forced_closed',
		// ux1_dashboard_tasks/ux1_dashboard_notes removed: DashboardWidgets::
		// OPTION_TASKS/OPTION_NOTES own 'uxstudio_dashboard_tasks'/'_notes'.
		'ux1_cron_control_hash'                  => 'uxstudio_cron_control_hash',
		'ux1_cron_control_remote_result'         => 'uxstudio_cron_control_remote_result',
		'ux1_cron_watch_result'                  => 'uxstudio_cron_watch_result',
		// claude_panel_settings/audit/attempts intentionally absent: the
		// ai-panel module is a literal lift-and-shift (see
		// Modules/AiPanel/legacy/) that keeps reading/writing those exact
		// unprefixed option names unchanged - renaming them here would just
		// create an unused duplicate while the real data stays where it is.
		'ux1_io_bulk_progress'                   => 'uxstudio_io_bulk_progress',
	);

	/**
	 * Prefix-wildcard option families (LIKE 'prefix%'). Empty: the ported
	 * instagram-feed module now stores per-feed sync time as a table column
	 * (uxstudio_instagram_media.synced_at), not per-feed options - the legacy
	 * 'ux1_instagram_last_sync_*' family has no new equivalent to copy into.
	 *
	 * @var array<string, string> old prefix => new prefix.
	 */
	private const OPTION_PREFIX_MAP = array();

	/**
	 * Legacy table (without wp prefix) => new table (without wp prefix). This
	 * is a blind "CREATE LIKE + INSERT SELECT" copy - only safe for a legacy
	 * table whose group-C module has NOT been reimplemented yet (the new name
	 * doesn't exist, so the copy effectively becomes the initial table).
	 *
	 * IMPORTANT: once a module is ported with its own dbDelta schema (see
	 * Module::boot() -> DB::ensure_module_tables()), its blind-copy entry MUST
	 * be removed from this map - otherwise this migrator would DROP the
	 * dbDelta-created table and replace it with the legacy schema under the
	 * same name. Ported so far (entries intentionally absent below):
	 * activity-log (reads the shared uxstudio_activity_log table owned by
	 * Core\DB, legacy ux1_activity_log needs a dedicated column-mapping
	 * migration - not a blind copy), email-log, smtp-email (wpext_logs),
	 * bot-throttle, exit-popup, google-review-request, page-load, popup-manager,
	 * ai-markdown, content-sync, instagram-feed, review-aggregator,
	 * service-requests, notice-board, push-notifications, performance-optimization.
	 * Each needs a real data-migration step in F4, not this generic copy.
	 *
	 * Booking tables intentionally absent.
	 *
	 * @var array<string, string>
	 */
	private const TABLE_MAP = array(
		// ai-assistant owns all 14 of its tables via its own
		// DB::ensure_module_tables() schema (Module::migrate()) - a blind copy
		// here would silently overwrite that schema with the legacy one, so
		// those entries were removed. A real data-migration step belongs in F4.
		'ux1_query_log'                       => 'uxstudio_query_log',
		// push-segments (segmentation) was NOT ported with push-notifications -
		// legacy data is kept here as a blind-copy placeholder until it is.
		'ux1_push_segments'                   => 'uxstudio_push_segments',
		// ux1_ip_bans/ux1_ip_ban_ranges/ux1_csp_violations/wpext_login_attempt/
		// wpext_login_failed removed: security-optimization owns all 5 of these
		// tables via its own dbDelta schema (Module::migrate() +
		// CspUploadGuardBootstrap) - a blind copy here would DROP and overwrite
		// them with the legacy schema. This was missed when that module landed;
		// found and fixed during the F4 QA pass. A real data-migration step
		// belongs in F4.
	);

	/**
	 * Run the migration once (safe to call on every activation).
	 *
	 * @param bool $dry_run When true, only log what would happen.
	 * @return string[] Log lines.
	 */
	public static function run( bool $dry_run = false ): array {
		$log = array();
		if ( get_option( self::DONE_OPTION ) && ! $dry_run ) {
			return array( 'already-migrated' );
		}

		// 1) Framework settings per module.
		foreach ( self::MODULE_IDS as $id ) {
			$old   = 'wpextended__' . $id . '_settings';
			$value = get_option( $old, null );
			if ( null === $value ) {
				continue;
			}
			$new   = 'uxstudio_' . str_replace( '-', '_', $id );
			$log[] = "option {$old} -> {$new}";
			if ( ! $dry_run ) {
				add_option( $new, $value, '', false );
			}
		}

		// 2) Standalone option keys.
		foreach ( self::OPTION_MAP as $old => $new ) {
			$value = get_option( $old, null );
			if ( null === $value ) {
				continue;
			}
			$log[] = "option {$old} -> {$new}";
			if ( ! $dry_run ) {
				add_option( $new, $value, '', false );
			}
		}

		// 3) Wildcard option families.
		global $wpdb;
		foreach ( self::OPTION_PREFIX_MAP as $old_prefix => $new_prefix ) {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s",
					$wpdb->esc_like( $old_prefix ) . '%'
				)
			);
			foreach ( $rows as $row ) {
				$new   = $new_prefix . substr( $row->option_name, strlen( $old_prefix ) );
				$log[] = "option {$row->option_name} -> {$new}";
				if ( ! $dry_run ) {
					add_option( $new, maybe_unserialize( $row->option_value ), '', false );
				}
			}
		}

		// 4) Tables (copy, never move - the legacy plugin stays as fallback).
		foreach ( self::TABLE_MAP as $old => $new ) {
			$old_table = $wpdb->prefix . $old;
			$new_table = $wpdb->prefix . $new;
			$exists    = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $old_table ) );
			if ( ! $exists ) {
				continue;
			}
			$log[] = "table {$old_table} -> {$new_table}";
			if ( ! $dry_run ) {
				// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names from a fixed map.
				$wpdb->query( "DROP TABLE IF EXISTS {$new_table}" );
				$wpdb->query( "CREATE TABLE {$new_table} LIKE {$old_table}" );
				$wpdb->query( "INSERT INTO {$new_table} SELECT * FROM {$old_table}" );
				// phpcs:enable
			}
		}

		// 5) Files outside DB (snippets dir etc.) - handled by their modules
		// during their own port (see AUDIT.md section D); logged here for visibility.
		$log[] = 'note: file-based data (ux1-snippets/, mu-plugin cron-control, .htaccess blocks) migrates with its module';

		if ( ! $dry_run ) {
			update_option( self::DONE_OPTION, gmdate( 'c' ), false );
		}
		return $log;
	}
}
