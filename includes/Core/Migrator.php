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
		// ux1_dashboard_tasks/ux1_dashboard_notes: copied by apply_key_remaps_v2()
		// (only when the new option does not exist yet).
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
	 * Each needs a real data-migration step, not this generic copy - see
	 * maybe_migrate_module_data() for the column-mapped ones.
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
				// Read on every request by the enabled module - autoload if small.
				add_option( $new, $value, '', Settings::should_autoload( $value ) );
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

		// 4b) Data tables whose new schema DIFFERS from legacy - column-mapped copy
		// (a blind INSERT SELECT would fail on the mismatched columns). Each runs
		// only when its target table is empty, so re-running never duplicates.
		self::migrate_activity_log( $log, $dry_run );

		// 4c) Fix up settings blobs whose inner keys were renamed in the port
		// (the framework migration copies the blob verbatim, so renamed inner
		// keys would silently lose their values).
		self::remap_pixel_tag_keys( $log, $dry_run );
		self::apply_key_remaps( $log, $dry_run );
		self::apply_key_remaps_v2( $log, $dry_run );

		// 4d) Carry over API keys the operator already filled in on the legacy
		// plugin, into the encrypted studio secret store, so they don't have to
		// be re-entered after the switch. Idempotent + never overwrites a key the
		// operator already set in UX Studio.
		self::migrate_secrets( $log, $dry_run );

		// 5) Files outside DB (snippets dir etc.) - handled by their modules
		// during their own port (see AUDIT.md section D); logged here for visibility.
		$log[] = 'note: file-based data (ux1-snippets/, mu-plugin cron-control, .htaccess blocks) migrates with its module';

		if ( ! $dry_run ) {
			update_option( self::DONE_OPTION, gmdate( 'c' ), false );
		}
		return $log;
	}

	/**
	 * Carry over API keys/secrets the operator filled in on the legacy plugin.
	 *
	 * stock-photos stored provider keys as PLAIN strings inside its settings
	 * blob; ai-assistant stored provider keys ENCRYPTED (aes-256-gcm keyed by
	 * SECURE_AUTH_KEY). Both are re-stored via Security::store_secret() (which
	 * re-encrypts with wp_salt('auth')). Only fills a studio secret that is
	 * currently empty, so re-running or a prior manual entry is never clobbered.
	 *
	 * @param string[] $log     Log lines (by reference).
	 * @param bool     $dry_run When true, only log what would happen.
	 */
	private static function migrate_secrets( array &$log, bool $dry_run ): void {
		// stock-photos: plain keys -> encrypted studio secrets.
		$stock = get_option( 'wpextended__stock-photos_settings' );
		if ( is_array( $stock ) ) {
			$map = array(
				'api_key_pexels'    => 'uxstudio_secret_stock_pexels',
				'api_key_pixabay'   => 'uxstudio_secret_stock_pixabay',
				'api_key_unsplash'  => 'uxstudio_secret_stock_unsplash',
				'api_key_flickr'    => 'uxstudio_secret_stock_flickr',
				'api_key_mapillary' => 'uxstudio_secret_stock_mapillary',
				'api_key_giphy'     => 'uxstudio_secret_stock_giphy',
			);
			foreach ( $map as $legacy_key => $secret_option ) {
				$val = isset( $stock[ $legacy_key ] ) ? (string) $stock[ $legacy_key ] : '';
				if ( '' === $val || '' !== Security::get_secret( $secret_option ) ) {
					continue;
				}
				$log[] = "secret stock-photos:{$legacy_key} -> {$secret_option}";
				if ( ! $dry_run ) {
					Security::store_secret( $secret_option, $val );
				}
			}
		}

		// ai-assistant: ux1-encrypted keys -> decrypt -> re-store studio-side.
		$ai = get_option( 'wpextended__ai-assistant_settings' );
		if ( is_array( $ai ) ) {
			$map = array(
				'claude_api_key'   => 'uxstudio_secret_ai_assistant_claude_api_key',
				'openai_api_key'   => 'uxstudio_secret_ai_assistant_openai_api_key',
				'deepseek_api_key' => 'uxstudio_secret_ai_assistant_deepseek_api_key',
			);
			foreach ( $map as $legacy_key => $secret_option ) {
				$enc = isset( $ai[ $legacy_key ] ) ? (string) $ai[ $legacy_key ] : '';
				if ( '' === $enc || '' !== Security::get_secret( $secret_option ) ) {
					continue;
				}
				$plain = self::ux1_decrypt( $enc );
				if ( '' === $plain ) {
					$log[] = "secret ai-assistant:{$legacy_key} SKIPPED (could not decrypt legacy value)";
					continue;
				}
				$log[] = "secret ai-assistant:{$legacy_key} -> {$secret_option}";
				if ( ! $dry_run ) {
					Security::store_secret( $secret_option, $plain );
				}
			}
		}
	}

	/**
	 * Decrypt a value encrypted by the legacy ai-assistant Encryption class
	 * (aes-256-gcm, key = sha256(SECURE_AUTH_KEY), payload = base64(iv|tag|cipher)).
	 * Returns '' on any failure.
	 *
	 * @param string $b64 Legacy base64 ciphertext.
	 */
	private static function ux1_decrypt( string $b64 ): string {
		if ( '' === $b64 || ! defined( 'SECURE_AUTH_KEY' ) || ! function_exists( 'openssl_decrypt' ) ) {
			return '';
		}
		$decoded = base64_decode( $b64, true );
		if ( false === $decoded ) {
			return '';
		}
		$iv_len = (int) openssl_cipher_iv_length( 'aes-256-gcm' );
		if ( strlen( $decoded ) <= $iv_len + 16 ) {
			return '';
		}
		$key    = hash( 'sha256', SECURE_AUTH_KEY, true );
		$iv     = substr( $decoded, 0, $iv_len );
		$tag    = substr( $decoded, $iv_len, 16 );
		$cipher = substr( $decoded, $iv_len + 16 );
		$out    = openssl_decrypt( $cipher, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag );
		return false === $out ? '' : $out;
	}

	/**
	 * Migrate the legacy activity-log table into the new shared table. The new
	 * schema replaced legacy user_name/object_name/ip_address columns with a
	 * single meta JSON blob, so this is a column-mapped copy - NOT a blind copy.
	 * The target (uxstudio_activity_log) is a core table created by DB::migrate_1()
	 * before Migrator::run(), so it always exists here.
	 *
	 * Idempotent: copies only when the target is empty, so re-running is a no-op.
	 *
	 * @param string[] $log     Log lines (by reference).
	 * @param bool     $dry_run When true, only log what would happen.
	 */
	private static function migrate_activity_log( array &$log, bool $dry_run ): void {
		global $wpdb;
		$old = $wpdb->prefix . 'ux1_activity_log';
		$new = $wpdb->prefix . 'uxstudio_activity_log';

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- fixed table names, one-off migration.
		if ( ! $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $old ) ) ) {
			return;
		}
		$target_rows = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$new}" );
		if ( $target_rows > 0 ) {
			$log[] = "data activity-log: skipped (target not empty: {$target_rows} rows)";
			return;
		}
		$source_rows = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$old}" );
		if ( 0 === $source_rows ) {
			return;
		}
		$log[] = "data activity-log: {$source_rows} rows {$old} -> {$new}";
		if ( ! $dry_run ) {
			$wpdb->query(
				"INSERT INTO {$new} (created_at, user_id, module, action, object_type, object_id, meta)
				 SELECT created_at, user_id, '', action, object_type, object_id,
				        JSON_OBJECT('user_name', user_name, 'object_name', object_name, 'ip_address', ip_address)
				 FROM {$old}"
			);
		}
		// phpcs:enable
	}

	/**
	 * pixel-tag-manager renamed its setting keys hyphen->underscore in the port
	 * (google-analytics -> google_analytics etc.). The framework migration copies
	 * the settings blob verbatim, so without this fixup the migrated values would
	 * sit under keys the new module never reads. Idempotent: only remaps a legacy
	 * key when its new counterpart is not already set.
	 *
	 * @param string[] $log     Log lines (by reference).
	 * @param bool     $dry_run When true, only log what would happen.
	 */
	private static function remap_pixel_tag_keys( array &$log, bool $dry_run ): void {
		$option = 'uxstudio_pixel_tag_manager';
		$value  = get_option( $option, null );
		if ( ! is_array( $value ) ) {
			return;
		}
		$map     = array(
			'google-analytics' => 'google_analytics',
			'facebook-pixel'   => 'facebook_pixel',
			'pinterest-tag'    => 'pinterest_tag',
		);
		$changed = false;
		foreach ( $map as $old => $new ) {
			if ( array_key_exists( $old, $value ) && ! array_key_exists( $new, $value ) ) {
				$value[ $new ] = $value[ $old ];
				unset( $value[ $old ] );
				$changed = true;
			}
		}
		if ( $changed ) {
			$log[] = 'settings pixel-tag-manager: remapped hyphen keys -> underscore';
			if ( ! $dry_run ) {
				update_option( $option, $value, Settings::should_autoload( $value ) );
			}
		}
	}

	/** Flag: the 2026-10-16 settings key remap/repair already ran on this site. */
	private const KEYREMAP_OPTION = 'uxstudio_keyremap_v1';

	/**
	 * One-time repair for sites that ALREADY ran the migration (DONE_OPTION is
	 * set, so run() returns early and never reaches the remaps below).
	 *
	 * Idempotent and cheap on repeat calls (one autoloaded flag lookup). It is
	 * not hooked anywhere by itself: it must be called on a normal request, e.g.
	 * from a module boot (SvgUpload does) or an early admin_init/plugins_loaded
	 * hook in a core file. Fresh migrations get the same remaps through run().
	 */
	public static function maybe_repair_settings(): void {
		if ( ! get_option( self::KEYREMAP_OPTION ) ) {
			$log = array();
			self::apply_key_remaps( $log, false );
			update_option( self::KEYREMAP_OPTION, gmdate( 'c' ), true );
		}
		// Second round (2026-10-19) for sites that already ran the first one.
		if ( ! get_option( self::KEYREMAP_V2_OPTION ) ) {
			$log = array();
			self::apply_key_remaps_v2( $log, false );
			update_option( self::KEYREMAP_V2_OPTION, gmdate( 'c' ), true );
		}
		// Remaps that need registered post types/taxonomies wait for wp_loaded.
		if ( ! get_option( self::KEYREMAP_V2_LATE_OPTION ) ) {
			add_action( 'wp_loaded', array( self::class, 'repair_after_init' ) );
		}
	}

	/** Flag: the 2026-10-19 remaps that need post types (ai-markdown) ran. */
	private const KEYREMAP_V2_LATE_OPTION = 'uxstudio_keyremap_v2_late';

	/**
	 * Late part of the v2 remap (wp_loaded): needs every public post type and
	 * taxonomy registered, which is not the case on plugins_loaded.
	 */
	public static function repair_after_init(): void {
		if ( get_option( self::KEYREMAP_V2_LATE_OPTION ) ) {
			return;
		}
		$log = array();
		self::remap_ai_markdown( $log, false );
		update_option( self::KEYREMAP_V2_LATE_OPTION, gmdate( 'c' ), true );
	}

	/**
	 * ai-markdown: legacy `pt_<type>` toggles were opt-in (only post + page on
	 * by default); UX Studio uses an opt-out `excluded_post_types` list, so a
	 * migrated site would expose every public post type in llms.txt. Rebuild
	 * the exclusion list from the legacy toggles. Also `retention_days` was
	 * renamed to `log_retention_days`.
	 *
	 * @param string[] $log     Log lines (by reference).
	 * @param bool     $dry_run When true, only log what would happen.
	 */
	private static function remap_ai_markdown( array &$log, bool $dry_run ): void {
		self::remap_option_key( 'uxstudio_ai_markdown', array( 'retention_days' => 'log_retention_days' ), 'ai-markdown', $log, $dry_run );

		$option = 'uxstudio_ai_markdown';
		$value  = get_option( $option, null );
		if ( ! is_array( $value ) || array_key_exists( 'excluded_post_types', $value ) || ! get_option( 'wpextended__ai-markdown_settings' ) ) {
			return; // Not migrated, or already configured in UX Studio.
		}
		$excluded = array();
		foreach ( get_post_types( array( 'public' => true ) ) as $type ) {
			$default = in_array( $type, array( 'post', 'page' ), true ) ? 1 : 0;
			if ( ! (int) ( $value[ 'pt_' . $type ] ?? $default ) ) {
				$excluded[] = $type;
			}
		}
		$value['excluded_post_types'] = $excluded;
		$log[]                        = 'settings ai-markdown: excluded_post_types = ' . implode( ', ', $excluded );

		// Legacy `tax_<taxonomy>` toggles (only category on by default) became one list.
		if ( ! array_key_exists( 'taxonomies', $value ) ) {
			$taxonomies = array();
			foreach ( get_taxonomies( array( 'public' => true ) ) as $tax ) {
				if ( (int) ( $value[ 'tax_' . $tax ] ?? ( 'category' === $tax ? 1 : 0 ) ) ) {
					$taxonomies[] = $tax;
				}
			}
			$value['taxonomies'] = $taxonomies;
			$log[]               = 'settings ai-markdown: taxonomies = ' . implode( ', ', $taxonomies );
		}
		// Legacy kept the bot log 90 days by default (UX Studio's default is 180).
		if ( ! array_key_exists( 'log_retention_days', $value ) ) {
			$value['log_retention_days'] = 90;
		}
		if ( ! $dry_run ) {
			update_option( $option, $value, Settings::should_autoload( $value ) );
		}
	}

	/** Flag: the 2026-10-19 remap (dashboard widgets, social login providers) ran. */
	private const KEYREMAP_V2_OPTION = 'uxstudio_keyremap_v2';

	/**
	 * Second round of renamed keys / dropped data, found by bin/parity-keys.py.
	 * Same rules as apply_key_remaps(): never overwrite a value set in UX Studio.
	 *
	 * @param string[] $log     Log lines (by reference).
	 * @param bool     $dry_run When true, only log what would happen.
	 */
	private static function apply_key_remaps_v2( array &$log, bool $dry_run ): void {
		self::remap_option_key(
			'uxstudio_dashboard_widgets',
			array(
				'disk_usage_widget' => 'widget_disk_usage',
				'widgets'           => 'hidden_widgets',
			),
			'dashboard-widgets',
			$log,
			$dry_run
		);
		self::copy_legacy_option( 'ux1_dashboard_tasks', 'uxstudio_dashboard_tasks', $log, $dry_run );
		self::copy_legacy_option( 'ux1_dashboard_notes', 'uxstudio_dashboard_notes', $log, $dry_run );
		self::remap_social_login_providers( $log, $dry_run );
		self::remap_security_csp( $log, $dry_run );
		self::remap_option_key( 'uxstudio_security_optimization', array( 'lwug_notify_email' => 'upload_guard_notify_email' ), 'security-optimization', $log, $dry_run );
		self::remap_opening_hours_schema( $log, $dry_run );
		self::remap_review_profile( $log, $dry_run );
		self::remap_review_request_place( $log, $dry_run );
	}

	/**
	 * security-optimization: legacy `enable_csp` (off|basic|strict) plus the
	 * `csp_report_only` toggle became `csp_mode` (off|report-only|enforce) and
	 * `csp_level` (basic|strict). Without this a site with CSP on lost it.
	 *
	 * @param string[] $log     Log lines (by reference).
	 * @param bool     $dry_run When true, only log what would happen.
	 */
	private static function remap_security_csp( array &$log, bool $dry_run ): void {
		$option = 'uxstudio_security_optimization';
		$value  = get_option( $option, null );
		if ( ! is_array( $value ) || ! array_key_exists( 'enable_csp', $value ) || array_key_exists( 'csp_mode', $value ) ) {
			return;
		}
		$legacy = (string) $value['enable_csp'];
		if ( 'off' === $legacy || '' === $legacy ) {
			$value['csp_mode'] = 'off';
		} else {
			$value['csp_mode']  = ! empty( $value['csp_report_only'] ) ? 'report-only' : 'enforce';
			$value['csp_level'] = 'strict' === $legacy ? 'strict' : 'basic';
		}
		unset( $value['enable_csp'], $value['csp_report_only'] );
		$log[] = 'settings security-optimization: enable_csp=' . $legacy . ' -> csp_mode=' . $value['csp_mode'];
		if ( ! $dry_run ) {
			update_option( $option, $value, Settings::should_autoload( $value ) );
		}
	}

	/**
	 * opening-hours: legacy printed the JSON-LD always (no switch); UX Studio
	 * added `schema_enabled` defaulting to off, so migrated sites lost it.
	 *
	 * @param string[] $log     Log lines (by reference).
	 * @param bool     $dry_run When true, only log what would happen.
	 */
	private static function remap_opening_hours_schema( array &$log, bool $dry_run ): void {
		$option = 'uxstudio_opening_hours';
		$value  = get_option( $option, null );
		if ( ! is_array( $value ) || array_key_exists( 'schema_enabled', $value ) || ! get_option( 'wpextended__opening-hours_settings' ) ) {
			return;
		}
		$value['schema_enabled'] = true;
		$log[]                   = 'settings opening-hours: schema_enabled = true (legacy always printed JSON-LD)';
		if ( ! $dry_run ) {
			update_option( $option, $value, Settings::should_autoload( $value ) );
		}
	}

	/**
	 * review-aggregator: the central profile id lived in a standalone legacy
	 * option; UX Studio keeps it in the module settings.
	 *
	 * @param string[] $log     Log lines (by reference).
	 * @param bool     $dry_run When true, only log what would happen.
	 */
	private static function remap_review_profile( array &$log, bool $dry_run ): void {
		$profile = absint( get_option( 'ux1_review_aggregator_central_profile_id', 0 ) );
		if ( ! $profile ) {
			return;
		}
		$option = 'uxstudio_review_aggregator';
		$value  = get_option( $option, array() );
		$value  = is_array( $value ) ? $value : array();
		if ( ! empty( $value['central_profile_id'] ) ) {
			return;
		}
		$value['central_profile_id'] = $profile;
		$log[]                       = 'settings review-aggregator: central_profile_id = ' . $profile;
		if ( ! $dry_run ) {
			update_option( $option, $value, Settings::should_autoload( $value ) );
		}
	}

	/**
	 * google-review-request: legacy stored only the Google Place ID and built
	 * the review link from it; UX Studio expects the full review URL.
	 *
	 * @param string[] $log     Log lines (by reference).
	 * @param bool     $dry_run When true, only log what would happen.
	 */
	private static function remap_review_request_place( array &$log, bool $dry_run ): void {
		$legacy = get_option( 'wpextended__google-review-request_settings', null );
		$place  = is_array( $legacy ) ? trim( (string) ( $legacy['place_id'] ?? '' ) ) : '';
		if ( '' === $place ) {
			return;
		}
		$option = 'uxstudio_google_review_request';
		$value  = get_option( $option, array() );
		$value  = is_array( $value ) ? $value : array();
		if ( ! empty( $value['google_review_url'] ) ) {
			return;
		}
		$value['google_review_url'] = 'https://search.google.com/local/writereview?placeid=' . rawurlencode( $place );
		$log[]                      = 'settings google-review-request: google_review_url from place_id';
		if ( ! $dry_run ) {
			update_option( $option, $value, Settings::should_autoload( $value ) );
		}
	}

	/**
	 * Copy a legacy standalone option when the new one does not exist yet.
	 *
	 * @param string   $old     Legacy option.
	 * @param string   $new     New option.
	 * @param string[] $log     Log lines (by reference).
	 * @param bool     $dry_run When true, only log what would happen.
	 */
	private static function copy_legacy_option( string $old, string $new, array &$log, bool $dry_run ): void {
		$value = get_option( $old, null );
		if ( null === $value || null !== get_option( $new, null ) ) {
			return;
		}
		$log[] = "option {$old} -> {$new}";
		if ( ! $dry_run ) {
			update_option( $new, $value, false );
		}
	}

	/**
	 * third-party-login: legacy had one toggle per provider (google_enabled
	 * defaulted to ON); UX Studio has a single enabled_providers list that
	 * defaults to empty, so a migrated site lost every login button.
	 *
	 * @param string[] $log     Log lines (by reference).
	 * @param bool     $dry_run When true, only log what would happen.
	 */
	private static function remap_social_login_providers( array &$log, bool $dry_run ): void {
		$option = 'uxstudio_third_party_login';
		$value  = get_option( $option, null );
		if ( ! is_array( $value ) || array_key_exists( 'enabled_providers', $value ) ) {
			return;
		}
		$legacy = array( 'google', 'facebook', 'apple', 'seznam' );
		$found  = false;
		$on     = array();
		foreach ( $legacy as $id ) {
			if ( array_key_exists( $id . '_enabled', $value ) ) {
				$found = true;
			}
			// Same default as legacy: Google on unless switched off.
			if ( (int) ( $value[ $id . '_enabled' ] ?? ( 'google' === $id ? 1 : 0 ) ) ) {
				$on[] = $id;
			}
		}
		if ( ! $found && ! get_option( 'wpextended__third-party-login_settings' ) ) {
			return; // Not a migrated site - nothing to restore.
		}
		$value['enabled_providers'] = $on;
		$log[]                      = 'settings third-party-login: enabled_providers = ' . implode( ', ', $on );
		if ( ! $dry_run ) {
			update_option( $option, $value, Settings::should_autoload( $value ) );
		}
	}

	/**
	 * Settings keys renamed in the port, plus data the first port dropped.
	 * Each step only acts when the new key/value is not already present, so it
	 * never overwrites something the operator set in UX Studio.
	 *
	 * @param string[] $log     Log lines (by reference).
	 * @param bool     $dry_run When true, only log what would happen.
	 */
	private static function apply_key_remaps( array &$log, bool $dry_run ): void {
		self::remap_svg_upload_roles( $log, $dry_run );
		self::remap_option_key( 'uxstudio_post_type_order', array( 'post-types' => 'post_types' ), 'post-type-order', $log, $dry_run );
		self::repair_instagram_feed_fields( $log, $dry_run );
		self::remap_exit_popup( $log, $dry_run );
	}

	/**
	 * exit-popup: legacy popup_title/popup_description/popup_image/
	 * activation_delay were renamed to headline/body/image/delay_seconds.
	 * The legacy media field may hold `[id]` (framework media field); the new
	 * `media` type expects a plain attachment ID.
	 *
	 * @param string[] $log     Log lines (by reference).
	 * @param bool     $dry_run When true, only log what would happen.
	 */
	private static function remap_exit_popup( array &$log, bool $dry_run ): void {
		self::remap_option_key(
			'uxstudio_exit_popup',
			array(
				'popup_title'       => 'headline',
				'popup_description' => 'body',
				'popup_image'       => 'image',
				'activation_delay'  => 'delay_seconds',
			),
			'exit-popup',
			$log,
			$dry_run
		);

		$value = get_option( 'uxstudio_exit_popup', null );
		if ( $dry_run || ! is_array( $value ) ) {
			return;
		}
		$changed = false;
		foreach ( array( 'image', 'popup_icon' ) as $key ) {
			if ( isset( $value[ $key ] ) && is_array( $value[ $key ] ) ) {
				$value[ $key ] = absint( reset( $value[ $key ] ) );
				$changed       = true;
			}
		}
		if ( $changed ) {
			update_option( 'uxstudio_exit_popup', $value, Settings::should_autoload( $value ) );
		}
	}

	/**
	 * svg-upload: legacy `user_roles` -> `allowed_roles`.
	 *
	 * Legacy semantics: empty/unset = every role that can upload files. The
	 * port treats an empty allow-list as "nobody" (safer default: administrator
	 * only), so an empty legacy value is expanded to the explicit list of all
	 * roles holding upload_files - behaviour is preserved and the new module
	 * keeps its strict semantics. Only applied to an existing settings blob
	 * that has no `allowed_roles` yet.
	 *
	 * @param string[] $log     Log lines (by reference).
	 * @param bool     $dry_run When true, only log what would happen.
	 */
	private static function remap_svg_upload_roles( array &$log, bool $dry_run ): void {
		$option = 'uxstudio_svg_upload';
		$value  = get_option( $option, null );
		if ( ! is_array( $value ) || array_key_exists( 'allowed_roles', $value ) ) {
			return;
		}

		$legacy = isset( $value['user_roles'] ) && is_array( $value['user_roles'] )
			? array_values( array_filter( array_map( 'sanitize_key', $value['user_roles'] ) ) )
			: array();

		if ( array() === $legacy ) {
			foreach ( wp_roles()->roles as $role_id => $role ) {
				if ( ! empty( $role['capabilities']['upload_files'] ) ) {
					$legacy[] = (string) $role_id;
				}
			}
		}

		$value['allowed_roles'] = $legacy;
		unset( $value['user_roles'] );

		$log[] = 'settings svg-upload: user_roles -> allowed_roles (empty = all upload roles)';
		if ( ! $dry_run ) {
			update_option( $option, $value, Settings::should_autoload( $value ) );
		}
	}

	/**
	 * Generic inner-key rename for a migrated settings blob.
	 *
	 * @param string                $option Option name.
	 * @param array<string, string> $map    Old key => new key.
	 * @param string                $label  Module id for the log.
	 * @param string[]              $log     Log lines (by reference).
	 * @param bool                  $dry_run When true, only log what would happen.
	 */
	private static function remap_option_key( string $option, array $map, string $label, array &$log, bool $dry_run ): void {
		$value = get_option( $option, null );
		if ( ! is_array( $value ) ) {
			return;
		}
		$changed = false;
		foreach ( $map as $old => $new ) {
			if ( array_key_exists( $old, $value ) && ! array_key_exists( $new, $value ) ) {
				$value[ $new ] = $value[ $old ];
				unset( $value[ $old ] );
				$changed = true;
			}
		}
		if ( $changed ) {
			$log[] = "settings {$label}: remapped keys " . implode( ', ', array_keys( $map ) );
			if ( ! $dry_run ) {
				update_option( $option, $value, Settings::should_autoload( $value ) );
			}
		}
	}

	/**
	 * instagram-feed: feeds migrated by the first port lost `show_likes` and
	 * `custom_css`. Backfill them (same keys as legacy) into the feed settings
	 * JSON from the legacy table, only for feeds that do not have them yet.
	 *
	 * @param string[] $log     Log lines (by reference).
	 * @param bool     $dry_run When true, only log what would happen.
	 */
	private static function repair_instagram_feed_fields( array &$log, bool $dry_run ): void {
		global $wpdb;
		$old = $wpdb->prefix . 'ux1_instagram_feeds';
		$new = $wpdb->prefix . 'uxstudio_instagram_feeds';

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- fixed table names, one-off repair.
		if ( ! $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $old ) ) || ! $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $new ) ) ) {
			return;
		}
		if ( ! $wpdb->get_var( "SHOW COLUMNS FROM {$old} LIKE 'custom_css'" ) || ! $wpdb->get_var( "SHOW COLUMNS FROM {$old} LIKE 'show_likes'" ) ) {
			return;
		}

		$rows = $wpdb->get_results( "SELECT n.id, n.settings, o.show_likes, o.custom_css FROM {$new} n JOIN {$old} o ON o.id = n.id" );
		foreach ( (array) $rows as $row ) {
			$settings = json_decode( (string) $row->settings, true );
			if ( ! is_array( $settings ) || array_key_exists( 'show_likes', $settings ) || array_key_exists( 'custom_css', $settings ) ) {
				continue;
			}
			$settings['show_likes'] = (int) $row->show_likes;
			$settings['custom_css'] = (string) $row->custom_css;
			$log[]                  = "instagram-feed: feed {$row->id} backfilled show_likes/custom_css";
			if ( ! $dry_run ) {
				$wpdb->update( $new, array( 'settings' => wp_json_encode( $settings ) ), array( 'id' => (int) $row->id ) );
			}
		}
		// phpcs:enable
	}

	/**
	 * Switch existing module settings options (uxstudio_<module>) to autoload.
	 * Earlier versions stored them with autoload off, costing one SELECT per
	 * enabled module on every request. Oversized blobs (>= 10 kB) stay off, as
	 * does anything that isn't a module settings row. Also covers the Cron
	 * Control mode hash, which that module compares on every boot.
	 *
	 * @param string[] $module_ids Built-in module ids.
	 */
	public static function autoload_module_settings( array $module_ids ): void {
		if ( ! function_exists( 'wp_set_option_autoload_values' ) ) {
			return;
		}
		$names = array( 'uxstudio_cron_control_hash' );
		foreach ( $module_ids as $id ) {
			$names[] = 'uxstudio_' . str_replace( '-', '_', (string) $id );
		}

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one-off migration, reads sizes without loading values.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				sprintf(
					"SELECT option_name, LENGTH(option_value) AS len FROM {$wpdb->options} WHERE option_name IN (%s)",
					implode( ',', array_fill( 0, count( $names ), '%s' ) )
				),
				$names
			)
		);

		$autoload = array();
		foreach ( (array) $rows as $row ) {
			if ( (int) $row->len < 10 * KB_IN_BYTES ) {
				$autoload[ $row->option_name ] = true;
			}
		}
		if ( $autoload ) {
			// Writes the right flag for the running WP version ('on' / 'yes').
			wp_set_option_autoload_values( $autoload );
		}
	}

	/**
	 * Data-migration dispatcher for a module whose own tables were just created
	 * (called from DB::ensure_module_tables). Each case is idempotent - it only
	 * copies when the legacy source exists and the new target is still empty, so
	 * this is safe to fire on every first-boot of a module. Legacy tables are
	 * never modified (ux1 stays as fallback until the handoff deletes it).
	 *
	 * @param string $module_id Module id (kebab-case).
	 */
	public static function maybe_migrate_module_data( string $module_id ): void {
		global $wpdb;
		switch ( $module_id ) {
			case 'push-notifications':
				// endpoint_hash is derived (legacy had none); user_agent trimmed to new width.
				self::copy_when_empty(
					$wpdb->prefix . 'ux1_push_subscribers',
					$wpdb->prefix . 'uxstudio_push_subscribers',
					'INSERT INTO %NEW% (created_at, endpoint, p256dh_key, auth_key, user_agent, endpoint_hash)
					 SELECT created_at, endpoint, p256dh, auth_key, LEFT(user_agent,255), SHA2(endpoint,256) FROM %OLD%'
				);
				self::copy_when_empty(
					$wpdb->prefix . 'ux1_push_notifications',
					$wpdb->prefix . 'uxstudio_push_notifications',
					'INSERT INTO %NEW% (created_at, title, body, sent_count)
					 SELECT created_at, LEFT(title,255), body, total_sent FROM %OLD%'
				);
				break;

			case 'service-requests':
				// subject->title, user_email->requester_email; legacy multi-file
				// attachments / budget / phone / admin_note have no new column.
				self::copy_when_empty(
					$wpdb->prefix . 'ux1_service_requests',
					$wpdb->prefix . 'uxstudio_service_requests',
					"INSERT INTO %NEW% (created_at, title, description, status, requester_email, attachment_id)
					 SELECT created_at, LEFT(subject,255), description,
					        IF(status IN ('open','in_progress','done'), status, 'open'),
					        user_email, 0 FROM %OLD%"
				);
				break;

			case 'ai-assistant':
				// Conversations were ported 1:1 (identical schema) - direct copy.
				// The product/content index tables are rebuildable from content
				// and are intentionally not migrated.
				self::copy_when_empty(
					$wpdb->prefix . 'ux1_ai_assistant_conversations',
					$wpdb->prefix . 'uxstudio_ai_assistant_conversations',
					'INSERT INTO %NEW% SELECT * FROM %OLD%'
				);
				break;

			case 'email-log':
				// legacy split error/error_reason; new schema has status + error_message.
				self::copy_when_empty(
					$wpdb->prefix . 'wpext_logs',
					$wpdb->prefix . 'uxstudio_email_log',
					"INSERT INTO %NEW% (created_at, to_email, subject, status, error_message)
					 SELECT `timestamp`, `to`, subject,
					        IF(error IS NULL OR error = '', 'sent', 'failed'), error FROM %OLD%"
				);
				break;

			case 'review-aggregator':
				// platform->source, author_name->author, review_text->text,
				// is_visible->visible, created_at->imported_at. dedup_hash is
				// computed exactly like Module::fetch() does (external_id first,
				// content signature fallback) so the next broker fetch updates
				// these rows instead of inserting duplicates. IGNORE: two legacy
				// rows can share a signature (NULL external_id + same content).
				// author_image/review_url/raw_data have no new column.
				self::copy_when_empty(
					$wpdb->prefix . 'ux1_reviews',
					$wpdb->prefix . 'uxstudio_reviews',
					"INSERT IGNORE INTO %NEW% (source, external_id, dedup_hash, author, rating, text, review_date, imported_at, visible)
					 SELECT LEFT(platform,32), LEFT(COALESCE(external_id,''),191),
					        SHA1(CONCAT(platform, '|', IF(COALESCE(external_id,'') <> '',
					            CONCAT('ext:', external_id),
					            CONCAT('sig:', author_name, '|', COALESCE(review_date,''), '|', COALESCE(review_text,''))))),
					        LEFT(author_name,255), LEAST(COALESCE(rating,0),5), review_text, review_date,
					        created_at, is_visible FROM %OLD%"
				);
				break;

			case 'instagram-feed':
				// Feeds: legacy per-column display config -> JSON `settings` blob
				// (same keys Module::normalize_config() reads). Ids are kept so
				// existing shortcode ids keep pointing at the same feed.
				// show_likes/custom_css are carried into the settings blob too.
				self::copy_when_empty(
					$wpdb->prefix . 'ux1_instagram_feeds',
					$wpdb->prefix . 'uxstudio_instagram_feeds',
					"INSERT INTO %NEW% (id, created_at, name, settings)
					 SELECT id, created_at, LEFT(name,255), JSON_OBJECT(
					        'theme', theme, 'item_limit', item_limit,
					        'cols_desktop', cols_desktop, 'cols_tablet', cols_tablet, 'cols_mobile', cols_mobile,
					        'gap_px', gap_px, 'show_caption', show_caption, 'show_follow', show_follow,
					        'caption_length', caption_length, 'link_target', link_target,
					        'media_types', media_types,
					        'include_hashtags', COALESCE(include_hashtags,''),
					        'exclude_hashtags', COALESCE(exclude_hashtags,''),
					        'show_likes', COALESCE(show_likes,0), 'custom_css', COALESCE(custom_css,''))
					 FROM %OLD%"
				);
				// Media: the new cache is one account-wide pool keyed by
				// instagram_id (legacy was per broker connection), so only the
				// newest row per instagram_id is copied. Sideloaded attachment ids
				// are kept, so the next sync reuses them instead of re-downloading.
				// The broker connection itself cannot migrate (the token never
				// lived on the site) - the operator reconnects once.
				self::copy_when_empty(
					$wpdb->prefix . 'ux1_instagram_media',
					$wpdb->prefix . 'uxstudio_instagram_media',
					"INSERT INTO %NEW% (feed_id, instagram_id, media_type, media_url, thumbnail_url, permalink, caption,
					        hashtags, attachment_id, video_attachment_id, media_timestamp, like_count, comments_count,
					        is_hidden, synced_at)
					 SELECT 0, instagram_id, media_type, LEFT(COALESCE(media_url,''),1000),
					        LEFT(COALESCE(thumbnail_url,''),1000), LEFT(COALESCE(permalink,''),1000), caption,
					        hashtags, COALESCE(attachment_id,0), COALESCE(video_attachment_id,0), media_timestamp,
					        like_count, comments_count, is_hidden, synced_at
					 FROM %OLD% WHERE id IN (SELECT MAX(id) FROM %OLD% GROUP BY instagram_id)"
				);
				break;

			case 'bot-throttle':
				// Log only: raw IP -> salted hash (same as Module::hash_ip(), no
				// raw IPs are stored any more - GDPR), timestamp->created_at, long
				// text columns trimmed to the new widths. The rate-limit buckets
				// are short-lived windows keyed differently (bot_key vs ip_hash) -
				// intentionally not migrated, they rebuild within minutes.
				$salt = $wpdb->prepare( '%s', wp_salt() );
				self::copy_when_empty(
					$wpdb->prefix . 'ux1_bot_throttle_log',
					$wpdb->prefix . 'uxstudio_bot_throttle_log',
					"INSERT INTO %NEW% (created_at, ip_hash, user_agent, action, bot_category, bot_name, tier, delay_ms,
					        url, load_score, response_status)
					 SELECT `timestamp`, SHA2(CONCAT(ip, {$salt}), 256), LEFT(user_agent,255), action, bot_category,
					        bot_name, tier, GREATEST(delay_ms,0), LEFT(url,500), load_score, GREATEST(response_status,0)
					 FROM %OLD%"
				);
				break;

			case 'exit-popup':
				// Raw ip_address -> salted ip_hash (same as RestController), so
				// rate limiting / dedup keep matching; empty IP stays empty.
				$salt = $wpdb->prepare( '%s', wp_salt() );
				self::copy_when_empty(
					$wpdb->prefix . 'ux1_exit_popup_emails',
					$wpdb->prefix . 'uxstudio_exit_popup_emails',
					"INSERT INTO %NEW% (created_at, email, page_url, ip_hash)
					 SELECT created_at, email, COALESCE(page_url,''),
					        IF(COALESCE(ip_address,'') = '', '', SHA2(CONCAT(ip_address, {$salt}), 256))
					 FROM %OLD%"
				);
				break;

			// popup-manager (ux1_popup_stats): NOT migrated - legacy is daily
			// aggregates (impressions/closes/conversions per day), the new schema
			// is raw per-event rows, and popup_id points at a different CPT. No
			// meaningful mapping. performance-optimization (ux1_performance_history):
			// NOT migrated - different model + the module was intentionally narrowed
			// (see PLAN.md 15.2). Both documented as by-design skips.
		}
	}

	/**
	 * Column-mapped copy that runs only when the source table exists and the
	 * target table exists but is empty (idempotent - never duplicates). The SQL
	 * template uses %OLD% / %NEW% placeholders for the fully-qualified table names.
	 *
	 * @param string $old            Legacy table (with prefix).
	 * @param string $new            New table (with prefix).
	 * @param string $insert_select  INSERT INTO %NEW% ... SELECT ... FROM %OLD% template.
	 */
	private static function copy_when_empty( string $old, string $new, string $insert_select ): void {
		global $wpdb;
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- fixed table names from an internal map, one-off migration.
		if ( ! $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $old ) ) ) {
			return;
		}
		if ( ! $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $new ) ) ) {
			return;
		}
		if ( (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$new}" ) > 0 ) {
			return;
		}
		$wpdb->query( str_replace( array( '%OLD%', '%NEW%' ), array( $old, $new ), $insert_select ) );
		// phpcs:enable
	}
}
