<?php
/**
 * Cron Control module - inspect and manage WP-Cron, control its run mode,
 * and audit scheduled events for suspicious hooks.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\CronControl;

use UxStudio\Core\ActivityLog;
use UxStudio\Core\Settings;
use UxStudio\Modules\BaseModule;

defined( 'ABSPATH' ) || exit;

/**
 * Two responsibilities:
 *
 * 1. Event management (unchanged from the first cut): read/inspect/run/delete
 *    the events already in WP-Cron via `_get_cron_array()` /
 *    `wp_unschedule_event()` / `do_action_ref_array()`.
 *
 * 2. WP-Cron run-mode control (ported from the legacy cron-control module):
 *    a `mode` setting decides whether WP-Cron runs normally, is fully disabled,
 *    is restricted to local requests, is left for an external hosting cron, or
 *    is driven by the central app. Disabling is implemented the safe way the
 *    legacy module used - an mu-plugin that defines DISABLE_WP_CRON - and an
 *    optional .htaccess block controls who may reach wp-cron.php. Neither
 *    wp-config.php nor any core file is ever edited, and a non-writable
 *    filesystem is handled gracefully (the site keeps working, the SPA reports
 *    that the constant could not be written).
 *
 * 3. A cron watcher (see Watcher.php) that grades scheduled hooks against a
 *    whitelist and can optionally auto-remove suspicious ones.
 */
final class Module extends BaseModule {

	/**
	 * Seconds past a scheduled timestamp before we consider WP-Cron "late".
	 */
	private const LATE_THRESHOLD = 300;

	/** Marker used to fence our block in the site .htaccess. */
	private const HTACCESS_MARKER = 'UX Studio Cron Control';

	/** mu-plugin filename that defines DISABLE_WP_CRON. */
	private const MU_PLUGIN_FILE = 'ux-studio-cron-control.php';

	/** Option caching the last synced (mode|home_url) hash. */
	private const HASH_OPTION = 'uxstudio_cron_control_hash';

	/** Daily watcher event hook. */
	private const WATCH_HOOK = 'uxstudio_cron_watch_daily';

	/** Valid run modes. */
	private const MODES = array( 'none', 'block_all', 'local_only', 'external', 'central_app' );

	/**
	 * Register hooks. Runs only for the enabled module (plugins_loaded).
	 */
	public function boot(): void {
		add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );

		// Keep the filesystem (mu-plugin + .htaccess) in sync with the mode.
		$this->sync_filesystem();

		// Daily watcher.
		add_action( self::WATCH_HOOK, array( $this, 'run_watch' ) );
		$this->ensure_watch_scheduled();

		// Warn in the admin bar when WP-Cron is fully blocked.
		add_action( 'admin_bar_menu', array( $this, 'admin_bar_warning' ), 100 );
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

	/* ------------------------------------------------------------------ *
	 * Settings
	 * ------------------------------------------------------------------ */

	/**
	 * Settings schema for the embedded "Settings" tab / generic renderer.
	 */
	public function settings_schema(): array {
		$mode_options = array(
			'none'        => __( 'Do not intervene - WP-Cron runs normally', 'ux-studio' ),
			'block_all'   => __( 'Disable completely (even locally)', 'ux-studio' ),
			'local_only'  => __( 'Disable publicly, allow local requests only', 'ux-studio' ),
			'external'    => __( 'Disable WP-Cron and trigger it from a hosting cron', 'ux-studio' ),
			'central_app' => __( 'Drive WP-Cron from the central app', 'ux-studio' ),
		);

		return array(
			array(
				'key'     => 'mode',
				'type'    => 'select',
				'label'   => __( 'WP-Cron mode', 'ux-studio' ),
				'help'    => __( 'WordPress runs scheduled tasks (wp-cron) on every front-end page load, which is unreliable and adds overhead. Change that behaviour here. Disabling is done via an mu-plugin that defines DISABLE_WP_CRON - wp-config.php is never edited.', 'ux-studio' ),
				'default' => 'none',
				'options' => $mode_options,
			),
			array(
				'key'     => 'central_allowed_ips',
				'type'    => 'textarea',
				'label'   => __( 'Central app IP allowlist (central mode)', 'ux-studio' ),
				'help'    => __( 'Only used in "central app" mode. One IP or CIDR per line - these are allowed to reach wp-cron.php in addition to local requests. Leave blank to allow local requests only.', 'ux-studio' ),
				'default' => '',
			),
			array(
				'key'     => 'cron_watch_enabled',
				'type'    => 'toggle',
				'label'   => __( 'Watch scheduled tasks', 'ux-studio' ),
				'help'    => __( 'Runs a nightly check of all scheduled cron tasks and flags unknown/suspicious hooks (typical of malware that schedules its own hidden hook).', 'ux-studio' ),
				'default' => true,
			),
			array(
				'key'     => 'cron_autoremove',
				'type'    => 'toggle',
				'label'   => __( 'Automatically remove suspicious tasks', 'ux-studio' ),
				'help'    => __( 'CAUTION: active protection. Tasks graded as suspicious (random name, callback in uploads, etc.) are automatically unscheduled. Leave off until you have reviewed the findings.', 'ux-studio' ),
				'default' => false,
			),
			array(
				'key'     => 'cron_whitelist_extra',
				'type'    => 'textarea',
				'label'   => __( 'Custom hook whitelist', 'ux-studio' ),
				'help'    => __( 'Cron hook names that must never be reported as unknown - one per line. Supports a trailing "*" wildcard, e.g. "woocommerce_*".', 'ux-studio' ),
				'default' => '',
			),
		);
	}

	/**
	 * Persist settings, then re-sync the filesystem and watcher schedule so the
	 * mode change takes effect immediately.
	 *
	 * @param array $input Raw input.
	 */
	public function save_settings( array $input ): array {
		$values = parent::save_settings( $input );

		// parent::save_settings() has refreshed $this->settings' cached values,
		// so the mode/whitelist reads below reflect the just-saved input.
		$this->sync_filesystem( true );
		$this->ensure_watch_scheduled();

		return $values;
	}

	/* ------------------------------------------------------------------ *
	 * Status
	 * ------------------------------------------------------------------ */

	/**
	 * Overall WP-Cron status snapshot (mode + constants + next event + writability).
	 *
	 * @return array<string, mixed>
	 */
	public function get_status(): array {
		$cron = $this->cron_array();

		$next_scheduled = null;
		$total_events   = 0;

		foreach ( $cron as $timestamp => $hooks ) {
			if ( ! is_int( $timestamp ) ) {
				continue;
			}
			if ( null === $next_scheduled ) {
				$next_scheduled = $timestamp;
			}
			foreach ( $hooks as $args_signatures ) {
				if ( is_array( $args_signatures ) ) {
					$total_events += count( $args_signatures );
				}
			}
		}

		$late = null !== $next_scheduled && ( time() - $next_scheduled ) > self::LATE_THRESHOLD;
		$mode = $this->get_mode();

		return array(
			'mode'                => $mode,
			'central_app_active'  => $this->central_app_active(),
			'disable_wp_cron'     => defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON,
			'alternate_wp_cron'   => defined( 'ALTERNATE_WP_CRON' ) && ALTERNATE_WP_CRON,
			'next_scheduled'      => $next_scheduled,
			'total_events'        => $total_events,
			'late'                => $late,
			'mu_plugin_active'    => file_exists( $this->mu_plugin_path() ),
			'mu_writable'         => $this->mu_dir_writable(),
			'htaccess_writable'   => $this->htaccess_writable(),
			'cron_url'            => site_url( 'wp-cron.php' ) . '?doing_wp_cron',
			// Convenience command for the "external"/hosting cron mode.
			'external_cron_cmd'   => sprintf( '*/5 * * * * wget -q -O - "%s" >/dev/null 2>&1', site_url( 'wp-cron.php' ) . '?doing_wp_cron' ),
		);
	}

	/* ------------------------------------------------------------------ *
	 * Events (inspect / run / delete)
	 * ------------------------------------------------------------------ */

	/**
	 * Flat list of scheduled events, sorted ascending by timestamp.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function get_events(): array {
		$cron      = $this->cron_array();
		$schedules = wp_get_schedules();
		$events    = array();

		foreach ( $cron as $timestamp => $hooks ) {
			if ( ! is_int( $timestamp ) ) {
				continue;
			}
			foreach ( $hooks as $hook => $args_signatures ) {
				if ( ! is_array( $args_signatures ) ) {
					continue;
				}
				foreach ( $args_signatures as $entry ) {
					$schedule = isset( $entry['schedule'] ) && $entry['schedule'] ? (string) $entry['schedule'] : null;
					$interval = null;
					if ( null !== $schedule && isset( $schedules[ $schedule ]['interval'] ) ) {
						$interval = (int) $schedules[ $schedule ]['interval'];
					}

					$events[] = array(
						'hook'           => (string) $hook,
						'timestamp'      => (int) $timestamp,
						'next_run_human' => $this->human_time( (int) $timestamp ),
						'schedule'       => $schedule,
						'interval'       => $interval,
						'args'           => isset( $entry['args'] ) && is_array( $entry['args'] ) ? array_values( $entry['args'] ) : array(),
					);
				}
			}
		}

		usort(
			$events,
			static function ( array $a, array $b ): int {
				return $a['timestamp'] <=> $b['timestamp'];
			}
		);

		return $events;
	}

	/**
	 * List the registered cron schedules (display name + interval).
	 *
	 * @return array<int, array{name:string,display:string,interval:int}>
	 */
	public function get_schedules(): array {
		$out = array();
		foreach ( wp_get_schedules() as $name => $schedule ) {
			$out[] = array(
				'name'     => (string) $name,
				'display'  => (string) ( $schedule['display'] ?? $name ),
				'interval' => (int) ( $schedule['interval'] ?? 0 ),
			);
		}
		usort(
			$out,
			static function ( array $a, array $b ): int {
				return $a['interval'] <=> $b['interval'];
			}
		);
		return $out;
	}

	/**
	 * Run a scheduled event immediately, synchronously, in this request.
	 *
	 * @param string $hook      Hook name.
	 * @param int    $timestamp Scheduled timestamp.
	 * @param array  $args      Event args.
	 * @return array{success:bool,message:string}
	 */
	public function run_event( string $hook, int $timestamp, array $args ): array {
		if ( ! $this->event_exists( $hook, $timestamp, $args ) ) {
			return array(
				'success' => false,
				'message' => __( 'That scheduled event could not be found.', 'ux-studio' ),
			);
		}

		do_action_ref_array( $hook, $args );

		ActivityLog::log( 'cron-control', 'run_event', 'cron_hook', 0, array( 'hook' => $hook ) );

		return array(
			'success' => true,
			'message' => __( 'Event executed.', 'ux-studio' ),
		);
	}

	/**
	 * Delete (unschedule) an event.
	 *
	 * @param string $hook      Hook name.
	 * @param int    $timestamp Scheduled timestamp.
	 * @param array  $args      Event args.
	 * @return array{success:bool,message:string}
	 */
	public function delete_event( string $hook, int $timestamp, array $args ): array {
		if ( ! $this->event_exists( $hook, $timestamp, $args ) ) {
			return array(
				'success' => false,
				'message' => __( 'That scheduled event could not be found.', 'ux-studio' ),
			);
		}

		$result = wp_unschedule_event( $timestamp, $hook, $args );

		if ( is_wp_error( $result ) || false === $result ) {
			return array(
				'success' => false,
				'message' => is_wp_error( $result ) ? $result->get_error_message() : __( 'Failed to delete the event.', 'ux-studio' ),
			);
		}

		ActivityLog::log( 'cron-control', 'delete_event', 'cron_hook', 0, array( 'hook' => $hook ) );

		return array(
			'success' => true,
			'message' => __( 'Event deleted.', 'ux-studio' ),
		);
	}

	/* ------------------------------------------------------------------ *
	 * Watcher
	 * ------------------------------------------------------------------ */

	/**
	 * Scheduled callback: run the watcher (no-op if watching is disabled).
	 */
	public function run_watch(): array {
		if ( ! (bool) $this->settings->get( 'cron_watch_enabled', true ) ) {
			return array();
		}
		return ( new Watcher( $this->settings ) )->run();
	}

	/**
	 * Force-run the watcher now (used by the "Run check now" REST endpoint).
	 */
	public function run_watch_now(): array {
		return ( new Watcher( $this->settings ) )->run();
	}

	/**
	 * Last cached watcher result, or null.
	 *
	 * @return array<string, mixed>|null
	 */
	public function watch_result(): ?array {
		return Watcher::last_result();
	}

	/**
	 * Schedule (or unschedule) the daily watcher based on the toggle.
	 */
	private function ensure_watch_scheduled(): void {
		$enabled = (bool) $this->settings->get( 'cron_watch_enabled', true );

		if ( ! $enabled ) {
			$ts = wp_next_scheduled( self::WATCH_HOOK );
			if ( $ts ) {
				wp_unschedule_event( $ts, self::WATCH_HOOK );
			}
			return;
		}

		if ( ! wp_next_scheduled( self::WATCH_HOOK ) ) {
			$next = strtotime( 'today 03:20' );
			if ( false === $next || $next <= time() ) {
				$next = strtotime( 'tomorrow 03:20' );
			}
			wp_schedule_event( (int) $next, 'daily', self::WATCH_HOOK );
		}
	}

	/* ------------------------------------------------------------------ *
	 * Run mode + filesystem sync (mu-plugin + .htaccess)
	 * ------------------------------------------------------------------ */

	/**
	 * Effective, validated run mode. central_app downgrades to none when the
	 * central app is not configured.
	 */
	private function get_mode(): string {
		$mode = (string) $this->settings->get( 'mode', 'none' );
		if ( ! in_array( $mode, self::MODES, true ) ) {
			return 'none';
		}
		if ( 'central_app' === $mode && ! $this->central_app_active() ) {
			return 'none';
		}
		return $mode;
	}

	/**
	 * Whether the central app broker (content-sync) is configured with a URL.
	 */
	private function central_app_active(): bool {
		$content_sync = new Settings( 'uxstudio_content_sync' );
		return '' !== (string) $content_sync->get( 'central_app_url', '' );
	}

	/**
	 * Bring the mu-plugin and .htaccess block in line with the current mode.
	 * Cheap on repeat calls (a single option compare) unless $force is set.
	 *
	 * @param bool $force Re-write even if the mode hash is unchanged.
	 */
	private function sync_filesystem( bool $force = false ): void {
		$mode = $this->get_mode();
		$hash = md5( $mode . '|' . home_url() . '|' . (string) $this->settings->get( 'central_allowed_ips', '' ) );

		if ( ! $force && get_option( self::HASH_OPTION, '' ) === $hash ) {
			return;
		}

		$this->write_mu_plugin( $mode );
		$this->write_htaccess( $mode );

		update_option( self::HASH_OPTION, $hash, false );
	}

	/**
	 * Absolute path to our mu-plugin.
	 */
	private function mu_plugin_path(): string {
		return trailingslashit( WPMU_PLUGIN_DIR ) . self::MU_PLUGIN_FILE;
	}

	/**
	 * Whether the mu-plugins directory can be written (created if missing).
	 */
	private function mu_dir_writable(): bool {
		$dir = WPMU_PLUGIN_DIR;
		if ( is_dir( $dir ) ) {
			return is_writable( $dir );
		}
		$parent = dirname( $dir );
		return is_dir( $parent ) && is_writable( $parent );
	}

	/**
	 * Write or remove the mu-plugin that defines DISABLE_WP_CRON. Never fatal:
	 * if the directory is not writable we simply leave things as they are.
	 *
	 * @param string $mode Effective run mode.
	 */
	private function write_mu_plugin( string $mode ): void {
		$mu_dir  = WPMU_PLUGIN_DIR;
		$mu_file = $this->mu_plugin_path();

		$needs_disable = in_array( $mode, array( 'block_all', 'local_only', 'external', 'central_app' ), true );

		if ( ! $needs_disable ) {
			if ( file_exists( $mu_file ) && is_writable( $mu_file ) ) {
				wp_delete_file( $mu_file );
			}
			return;
		}

		if ( ! is_dir( $mu_dir ) ) {
			wp_mkdir_p( $mu_dir );
		}
		if ( ! is_dir( $mu_dir ) || ! is_writable( $mu_dir ) ) {
			return;
		}

		$content = "<?php\n"
			. "/**\n"
			. " * Plugin Name: UX Studio Cron Control\n"
			. " * Description: Disables WP-Cron - managed by the Cron Control module in UX Studio.\n"
			. " * Author: UX Studio\n"
			. " * Version: 1.0\n"
			. " */\n\n"
			. "if ( ! defined( 'DISABLE_WP_CRON' ) ) {\n"
			. "\tdefine( 'DISABLE_WP_CRON', true );\n"
			. "}\n";

		$this->put_file( $mu_file, $content );
	}

	/**
	 * Write (or clear) the .htaccess block controlling wp-cron.php access.
	 *
	 * @param string $mode Effective run mode.
	 */
	private function write_htaccess( string $mode ): void {
		if ( ! function_exists( 'insert_with_markers' ) ) {
			require_once ABSPATH . 'wp-admin/includes/misc.php';
		}
		if ( ! function_exists( 'get_home_path' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		$htaccess = get_home_path() . '.htaccess';

		if ( ! is_writable( $htaccess ) && ! is_writable( dirname( $htaccess ) ) ) {
			return;
		}

		insert_with_markers( $htaccess, self::HTACCESS_MARKER, $this->htaccess_rules( $mode ) );
	}

	/**
	 * Whether the site .htaccess (or its directory) is writable.
	 */
	private function htaccess_writable(): bool {
		if ( ! function_exists( 'get_home_path' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		$htaccess = get_home_path() . '.htaccess';
		return is_writable( $htaccess ) || is_writable( dirname( $htaccess ) );
	}

	/**
	 * The .htaccess rules for a mode. Note: .htaccess is Apache-only; on nginx
	 * these are ignored and the operator must configure access there.
	 *
	 * @param string $mode Effective run mode.
	 * @return array<int, string>
	 */
	private function htaccess_rules( string $mode ): array {
		switch ( $mode ) {
			case 'block_all':
				return array(
					'<Files wp-cron.php>',
					'  Require all denied',
					'</Files>',
				);

			case 'local_only':
				return array(
					'<Files wp-cron.php>',
					'  Require local',
					'</Files>',
				);

			case 'central_app':
				$rules = array( '<Files wp-cron.php>', '  Require local' );
				foreach ( $this->central_allowed_ips() as $ip ) {
					$rules[] = '  Require ip ' . $ip;
				}
				$rules[] = '</Files>';
				return $rules;

			case 'external':
			case 'none':
			default:
				return array();
		}
	}

	/**
	 * Parsed, validated IP/CIDR allowlist for central_app mode.
	 *
	 * @return array<int, string>
	 */
	private function central_allowed_ips(): array {
		$raw = (string) $this->settings->get( 'central_allowed_ips', '' );
		if ( '' === $raw ) {
			return array();
		}
		$ips = preg_split( '/[\r\n,]+/', $raw ) ?: array();
		$ips = array_map( 'trim', $ips );
		return array_values(
			array_filter(
				$ips,
				static function ( string $ip ): bool {
					return '' !== $ip && ( false !== filter_var( $ip, FILTER_VALIDATE_IP ) || strpos( $ip, '/' ) !== false );
				}
			)
		);
	}

	/**
	 * Write a file via the WP_Filesystem API (respects FS_METHOD / credentials),
	 * falling back to a direct write when the API is unavailable.
	 *
	 * @param string $path    Absolute target path.
	 * @param string $content File contents.
	 */
	private function put_file( string $path, string $content ): void {
		global $wp_filesystem;
		if ( ! function_exists( 'WP_Filesystem' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		if ( WP_Filesystem() && $wp_filesystem ) {
			$wp_filesystem->put_contents( $path, $content, FS_CHMOD_FILE );
			return;
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- WP_Filesystem unavailable; direct write is the only option.
		file_put_contents( $path, $content );
	}

	/* ------------------------------------------------------------------ *
	 * Admin bar warning
	 * ------------------------------------------------------------------ */

	/**
	 * Add a red admin-bar warning when WP-Cron is fully blocked.
	 *
	 * @param \WP_Admin_Bar $wp_admin_bar Admin bar.
	 */
	public function admin_bar_warning( \WP_Admin_Bar $wp_admin_bar ): void {
		if ( ! is_admin_bar_showing() || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( 'block_all' !== $this->get_mode() ) {
			return;
		}

		$wp_admin_bar->add_node(
			array(
				'id'     => 'ux-studio-cron-warning',
				'parent' => 'top-secondary',
				'title'  => '⚠ ' . esc_html__( 'WP-Cron disabled', 'ux-studio' ),
				'href'   => admin_url( 'admin.php?page=ux-studio#/module?id=cron-control' ),
				'meta'   => array(
					'title' => __( 'WP-Cron is fully disabled - scheduled tasks will not run. Click to review.', 'ux-studio' ),
				),
			)
		);
	}

	/* ------------------------------------------------------------------ *
	 * Helpers
	 * ------------------------------------------------------------------ */

	/**
	 * Raw WP-Cron array, guarded in case the private core helper is unavailable.
	 *
	 * @return array<int|string, mixed>
	 */
	private function cron_array(): array {
		if ( ! function_exists( '_get_cron_array' ) ) {
			return array();
		}
		$cron = _get_cron_array();
		return is_array( $cron ) ? $cron : array();
	}

	/**
	 * Whether the given (hook, timestamp, args) triple is still scheduled.
	 *
	 * @param string $hook      Hook name.
	 * @param int    $timestamp Scheduled timestamp.
	 * @param array  $args      Event args.
	 */
	private function event_exists( string $hook, int $timestamp, array $args ): bool {
		$cron = $this->cron_array();
		if ( ! isset( $cron[ $timestamp ][ $hook ] ) ) {
			return false;
		}

		$key = md5( serialize( $args ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize

		return isset( $cron[ $timestamp ][ $hook ][ $key ] );
	}

	/**
	 * Human-readable relative time for a timestamp.
	 *
	 * @param int $timestamp Unix timestamp.
	 */
	private function human_time( int $timestamp ): string {
		$now = time();
		if ( $timestamp >= $now ) {
			/* translators: %s: human-readable time difference */
			return sprintf( __( 'in %s', 'ux-studio' ), human_time_diff( $now, $timestamp ) );
		}
		/* translators: %s: human-readable time difference */
		return sprintf( __( '%s ago', 'ux-studio' ), human_time_diff( $timestamp, $now ) );
	}
}
