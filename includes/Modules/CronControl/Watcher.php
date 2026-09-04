<?php
/**
 * Cron watcher - audits scheduled WP-Cron events against a whitelist.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\CronControl;

use UxStudio\Core\ActivityLog;
use UxStudio\Core\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Ported (redesigned) from the legacy cron-control CronWatcher. Walks
 * `_get_cron_array()` and grades every hook against a whitelist (WP core hooks +
 * hooks currently registered via add_action() + the operator's own whitelist).
 * Anything not on the whitelist is "unknown"; heuristics additionally flag
 * "suspicious" events (random/obfuscated hook names, callbacks living outside
 * plugins/themes/mu-plugins or inside uploads, runtime-created callbacks).
 *
 * The last result is cached in the uxstudio_cron_watch_result option so the SPA
 * can render it without re-scanning on every page load.
 */
final class Watcher {

	public const RESULT_OPTION = 'uxstudio_cron_watch_result';

	/**
	 * Known WP core cron hooks (and hooks from common core subsystems).
	 */
	private const CORE_HOOKS = array(
		'wp_version_check',
		'wp_update_plugins',
		'wp_update_themes',
		'wp_scheduled_delete',
		'wp_scheduled_auto_draft_delete',
		'delete_expired_transients',
		'recovery_mode_clean_expired_keys',
		'wp_https_detection',
		'wp_update_user_counts',
		'wp_privacy_delete_old_export_files',
		'do_pings',
		'publish_future_post',
		'importer_scheduled_cleanup',
		'wp_site_health_scheduled_check',
		'update_network_counts',
		'wp_delete_temp_updater_backups',
		'wp_attachment_delete_temp_files',
		// ActionScheduler (WooCommerce and others).
		'action_scheduler_run_queue',
	);

	/** @var array<int, string> Extra whitelist hook patterns (from settings). */
	private array $extra_whitelist;

	/** @var bool Automatically unschedule suspicious events. */
	private bool $auto_remove;

	/**
	 * @param Settings $settings Module settings instance.
	 */
	public function __construct( Settings $settings ) {
		$extra_raw             = (string) $settings->get( 'cron_whitelist_extra', '' );
		$this->extra_whitelist = array_values(
			array_filter(
				array_map( 'trim', preg_split( '/[\r\n,]+/', $extra_raw ) ?: array() )
			)
		);

		$this->auto_remove = (bool) $settings->get( 'cron_autoremove', false );
	}

	/**
	 * Run the scan, cache the result and return it.
	 *
	 * @return array<string, mixed>
	 */
	public function run(): array {
		$cron = function_exists( '_get_cron_array' ) ? _get_cron_array() : array();
		if ( ! is_array( $cron ) ) {
			$cron = array();
		}

		$registered = $this->registered_hooks();

		$total      = 0;
		$unknown    = array();
		$suspicious = array();
		$removed    = array();

		foreach ( $cron as $timestamp => $hooks ) {
			if ( ! is_int( $timestamp ) || ! is_array( $hooks ) ) {
				continue;
			}

			foreach ( $hooks as $hook => $events ) {
				if ( 'version' === $hook || ! is_array( $events ) ) {
					continue;
				}

				foreach ( $events as $event ) {
					++$total;

					$is_core        = in_array( $hook, self::CORE_HOOKS, true );
					$is_whitelisted = $this->matches_whitelist( (string) $hook );
					$is_registered  = isset( $registered[ $hook ] );

					// Known event = core, whitelisted, or has a registered callback.
					if ( $is_core || $is_whitelisted || $is_registered ) {
						$hint = $is_registered ? $this->describe_callbacks( (string) $hook ) : null;
						if ( $hint && ! empty( $hint['suspicious'] ) ) {
							$entry        = $this->build_entry( (string) $hook, (int) $timestamp, (array) $event, $hint, __( 'callback from a suspicious location', 'ux-studio' ) );
							$suspicious[] = $entry;
							if ( $this->auto_remove ) {
								$removed[] = $this->unschedule( (string) $hook, (int) $timestamp, (array) $event );
							}
						}
						continue;
					}

					// Unknown event (orphan hook with no registered callback).
					$reason = $this->suspicion_reason( (string) $hook );
					$entry  = $this->build_entry( (string) $hook, (int) $timestamp, (array) $event, null, $reason ?? __( 'unknown hook with no registered callback', 'ux-studio' ) );

					$unknown[] = $entry;

					if ( null !== $reason ) {
						$suspicious[] = $entry;
						if ( $this->auto_remove ) {
							$removed[] = $this->unschedule( (string) $hook, (int) $timestamp, (array) $event );
						}
					}
				}
			}
		}

		$removed = array_values( array_filter( $removed ) );

		$result = array(
			'scanned_at'   => current_time( 'mysql' ),
			'timestamp'    => time(),
			'total_events' => $total,
			'unknown'      => array_values( $unknown ),
			'suspicious'   => array_values( $suspicious ),
			'removed'      => $removed,
			'autoremove'   => $this->auto_remove,
		);

		update_option( self::RESULT_OPTION, $result, false );

		ActivityLog::log(
			'cron-control',
			'watch',
			'cron_scan',
			0,
			array(
				'total'      => $total,
				'unknown'    => count( $unknown ),
				'suspicious' => count( $suspicious ),
				'removed'    => count( $removed ),
			)
		);

		return $result;
	}

	/**
	 * Map of hooks that currently have at least one registered callback.
	 *
	 * @return array<string, bool>
	 */
	private function registered_hooks(): array {
		global $wp_filter;
		$map = array();

		if ( is_array( $wp_filter ) ) {
			foreach ( $wp_filter as $hook => $obj ) {
				if ( $obj instanceof \WP_Hook && ! empty( $obj->callbacks ) ) {
					$map[ $hook ] = true;
				} elseif ( is_array( $obj ) && ! empty( $obj ) ) {
					$map[ $hook ] = true;
				}
			}
		}

		return $map;
	}

	/**
	 * Describe a hook's callbacks (their files) and whether any is suspicious.
	 *
	 * @param string $hook Hook name.
	 * @return array{files: array<int, string>, suspicious: bool}|null
	 */
	private function describe_callbacks( string $hook ): ?array {
		global $wp_filter;
		if ( ! isset( $wp_filter[ $hook ] ) || ! ( $wp_filter[ $hook ] instanceof \WP_Hook ) ) {
			return null;
		}

		$files      = array();
		$suspicious = false;

		foreach ( $wp_filter[ $hook ]->callbacks as $callbacks ) {
			foreach ( $callbacks as $cb ) {
				$fn   = $cb['function'] ?? null;
				$file = $this->resolve_callback_file( $fn );
				if ( null === $file ) {
					continue;
				}
				$files[] = $this->rel_path( $file );
				if ( $this->is_suspicious_path( $file ) ) {
					$suspicious = true;
				}
			}
		}

		return array(
			'files'      => array_values( array_unique( $files ) ),
			'suspicious' => $suspicious,
		);
	}

	/**
	 * Resolve the file a callback is defined in (via reflection).
	 *
	 * @param mixed $fn Callback.
	 */
	private function resolve_callback_file( $fn ): ?string {
		try {
			if ( is_string( $fn ) && function_exists( $fn ) ) {
				$ref = new \ReflectionFunction( $fn );
				return $ref->getFileName() ?: null;
			}
			if ( $fn instanceof \Closure ) {
				$ref = new \ReflectionFunction( $fn );
				return $ref->getFileName() ?: null;
			}
			if ( is_array( $fn ) && count( $fn ) === 2 ) {
				$ref = new \ReflectionMethod( $fn[0], $fn[1] );
				return $ref->getFileName() ?: null;
			}
			if ( is_object( $fn ) && method_exists( $fn, '__invoke' ) ) {
				$ref = new \ReflectionMethod( $fn, '__invoke' );
				return $ref->getFileName() ?: null;
			}
		} catch ( \Throwable $e ) {
			// Runtime-created (eval'd) callback has no file - suspicious in itself.
			return null;
		}
		return null;
	}

	/**
	 * A path is suspicious if it lives outside plugins/themes/mu-plugins, or
	 * directly inside the uploads directory.
	 *
	 * @param string $file Absolute file path.
	 */
	private function is_suspicious_path( string $file ): bool {
		$file = wp_normalize_path( $file );

		$uploads     = wp_get_upload_dir();
		$upload_base = wp_normalize_path( $uploads['basedir'] ?? ( WP_CONTENT_DIR . '/uploads' ) );
		if ( $upload_base && strpos( $file, $upload_base ) === 0 ) {
			return true;
		}

		$content_dir = wp_normalize_path( WP_CONTENT_DIR );
		// Core files outside wp-content are not this module's concern.
		if ( strpos( $file, $content_dir ) !== 0 ) {
			return false;
		}

		$allowed = array(
			wp_normalize_path( WP_PLUGIN_DIR ),
			wp_normalize_path( get_theme_root() ),
			wp_normalize_path( WPMU_PLUGIN_DIR ),
		);
		foreach ( $allowed as $base ) {
			if ( $base && strpos( $file, $base ) === 0 ) {
				return false;
			}
		}

		// Inside wp-content but outside plugins/themes/mu-plugins.
		return true;
	}

	/**
	 * Reason a hook name looks suspicious, or null if it's merely unknown.
	 *
	 * @param string $hook Hook name.
	 */
	private function suspicion_reason( string $hook ): ?string {
		// Random hex string (typical of malware).
		if ( preg_match( '/^[a-f0-9]{16,}$/i', $hook ) ) {
			return __( 'hook name looks like a random hex string', 'ux-studio' );
		}
		// Unusually long "glued" string with no separators.
		if ( strlen( $hook ) >= 24 && ! preg_match( '/[_\-\/]/', $hook ) ) {
			return __( 'unusually long hook name with no separators', 'ux-studio' );
		}
		// Base64-like.
		if ( preg_match( '/^[A-Za-z0-9+\/]{20,}={0,2}$/', $hook ) && ! preg_match( '/_/', $hook ) ) {
			return __( 'hook name looks like base64', 'ux-studio' );
		}
		return null;
	}

	/**
	 * Build one result entry.
	 *
	 * @param string     $hook      Hook name.
	 * @param int        $timestamp Scheduled timestamp.
	 * @param array      $event     Raw event signature.
	 * @param array|null $hint      Callback hint (files/suspicious) or null.
	 * @param string     $note      Human note.
	 * @return array<string, mixed>
	 */
	private function build_entry( string $hook, int $timestamp, array $event, ?array $hint, string $note ): array {
		return array(
			'hook'          => $hook,
			'next_run'      => gmdate( 'Y-m-d H:i:s', $timestamp ) . ' UTC',
			'next_run_ts'   => $timestamp,
			'schedule'      => isset( $event['schedule'] ) && $event['schedule'] ? (string) $event['schedule'] : 'one-off',
			'callback_hint' => $hint['files'] ?? array(),
			'note'          => $note,
		);
	}

	/**
	 * Unschedule one event; return a short description of what was removed.
	 *
	 * @param string $hook      Hook name.
	 * @param int    $timestamp Scheduled timestamp.
	 * @param array  $event     Raw event signature.
	 */
	private function unschedule( string $hook, int $timestamp, array $event ): ?string {
		$args = isset( $event['args'] ) && is_array( $event['args'] ) ? $event['args'] : array();
		$ok   = wp_unschedule_event( $timestamp, $hook, $args );
		if ( false === $ok || is_wp_error( $ok ) ) {
			return null;
		}
		return sprintf( '%s @ %s UTC', $hook, gmdate( 'Y-m-d H:i:s', $timestamp ) );
	}

	/**
	 * Whether the hook matches the operator's extra whitelist (supports a
	 * trailing "*" wildcard prefix, e.g. "woocommerce_*").
	 *
	 * @param string $hook Hook name.
	 */
	private function matches_whitelist( string $hook ): bool {
		foreach ( $this->extra_whitelist as $pattern ) {
			if ( '' === $pattern ) {
				continue;
			}
			if ( substr( $pattern, -1 ) === '*' ) {
				if ( strpos( $hook, rtrim( $pattern, '*' ) ) === 0 ) {
					return true;
				}
			} elseif ( $hook === $pattern ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Path relative to ABSPATH (or basename when outside it).
	 *
	 * @param string $file Absolute file path.
	 */
	private function rel_path( string $file ): string {
		$file = wp_normalize_path( $file );
		$root = wp_normalize_path( ABSPATH );
		if ( $root && strpos( $file, $root ) === 0 ) {
			return ltrim( substr( $file, strlen( $root ) ), '/' );
		}
		return basename( $file );
	}

	/**
	 * Last cached scan result, or null.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function last_result(): ?array {
		$result = get_option( self::RESULT_OPTION, null );
		return is_array( $result ) ? $result : null;
	}
}
