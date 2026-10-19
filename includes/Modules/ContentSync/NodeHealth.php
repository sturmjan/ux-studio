<?php
/**
 * Node-side health/monitoring endpoints polled by the Central App.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\ContentSync;

use UxStudio\Modules\Guide\Module as GuideModule;
use UxStudio\Modules\ServiceRequests\Module as ServiceRequestsModule;
use UxStudio\Modules\VulnerabilityScanner\Module as VulnerabilityScannerModule;
use UxStudio\Plugin;
use WP_REST_Request;
use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

/**
 * Read-only reporting endpoints ported from the legacy NodeController
 * (`/updates`, `/php-errors`, `/pagespeed`, `/email-health`, `/security-monitor`,
 * `/vulnerabilities`, `/guide`, `/service-requests`). Response shapes are kept
 * identical to the legacy plugin because the Central App parses them as-is;
 * where the Studio stores the underlying data differently (security findings,
 * vulnerability scan, service requests) the data is mapped back to the legacy
 * shape here.
 *
 * Every route is registered by NodeController with the HMAC permission
 * callback; nothing in this class is reachable without it.
 */
final class NodeHealth {

	/** Legacy transient the Central App's PageSpeed sync used to fill. */
	private const PAGESPEED_TRANSIENT = 'ux1_pagespeed_data';

	/** Legacy guide option (pre-Studio installs). */
	private const LEGACY_GUIDE_OPTION = 'wpextended__guide_settings';

	/**
	 * Pending/updated core, plugin and theme updates (forces a fresh check).
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function updates( WP_REST_Request $request ): WP_REST_Response {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		require_once ABSPATH . 'wp-admin/includes/update.php';

		wp_update_plugins();
		wp_update_themes();
		wp_version_check();

		$result = array(
			'core'    => null,
			'plugins' => array(),
			'themes'  => array(),
			'counts'  => array(
				'core'    => 0,
				'plugins' => 0,
				'themes'  => 0,
				'total'   => 0,
			),
		);

		$core_updates = get_site_transient( 'update_core' );
		if ( is_object( $core_updates ) && ! empty( $core_updates->updates ) ) {
			foreach ( $core_updates->updates as $update ) {
				if ( isset( $update->response ) && 'upgrade' === $update->response ) {
					$result['core']           = array(
						'current'   => get_bloginfo( 'version' ),
						'new'       => $update->version,
						'available' => true,
					);
					$result['counts']['core'] = 1;
					break;
				}
			}
		}

		$plugin_updates = get_site_transient( 'update_plugins' );
		if ( is_object( $plugin_updates ) && ! empty( $plugin_updates->response ) ) {
			$all_plugins = get_plugins();
			foreach ( $plugin_updates->response as $file => $info ) {
				$result['plugins'][] = array(
					'name'    => isset( $all_plugins[ $file ] ) ? $all_plugins[ $file ]['Name'] : $file,
					'slug'    => $info->slug ?? dirname( (string) $file ),
					'current' => isset( $all_plugins[ $file ] ) ? $all_plugins[ $file ]['Version'] : '?',
					'new'     => $info->new_version ?? '?',
				);
			}
			$result['counts']['plugins'] = count( $plugin_updates->response );
		}

		$theme_updates = get_site_transient( 'update_themes' );
		if ( is_object( $theme_updates ) && ! empty( $theme_updates->response ) ) {
			foreach ( $theme_updates->response as $slug => $info ) {
				$theme              = wp_get_theme( (string) $slug );
				$result['themes'][] = array(
					'name'    => $theme->exists() ? $theme->get( 'Name' ) : $slug,
					'slug'    => $slug,
					'current' => $theme->exists() ? $theme->get( 'Version' ) : '?',
					'new'     => $info['new_version'] ?? '?',
				);
			}
			$result['counts']['themes'] = count( $theme_updates->response );
		}

		$result['counts']['total'] = $result['counts']['core'] + $result['counts']['plugins'] + $result['counts']['themes'];

		return new WP_REST_Response( $result, 200 );
	}

	/**
	 * Tail of the PHP/WP debug log, parsed into structured entries.
	 *
	 * @param WP_REST_Request $request Request (`limit`, 10-200).
	 */
	public function php_errors( WP_REST_Request $request ): WP_REST_Response {
		$limit = min( 200, max( 10, (int) ( $request->get_param( 'limit' ) ?: 50 ) ) );

		$log_path = '';
		if ( defined( 'WP_DEBUG_LOG' ) && is_string( WP_DEBUG_LOG ) ) {
			$log_path = WP_DEBUG_LOG;
		} elseif ( defined( 'WP_DEBUG_LOG' ) && true === WP_DEBUG_LOG ) {
			$log_path = WP_CONTENT_DIR . '/debug.log';
		}
		if ( '' === $log_path ) {
			$log_path = (string) ini_get( 'error_log' );
		}

		if ( '' === $log_path || ! file_exists( $log_path ) || ! is_readable( $log_path ) ) {
			return new WP_REST_Response(
				array(
					'errors'      => array(),
					'total_count' => 0,
					'log_size'    => '0 B',
					'log_path'    => '' !== $log_path ? basename( $log_path ) : 'not configured',
					'available'   => false,
				),
				200
			);
		}

		$log_size = (int) filesize( $log_path );

		// Read only the last 512 KB of the file.
		$max_read = 512 * 1024;
		$fp       = fopen( $log_path, 'r' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( ! $fp ) {
			return new WP_REST_Response(
				array(
					'errors'      => array(),
					'total_count' => 0,
					'log_size'    => $this->format_bytes( $log_size ),
					'available'   => false,
				),
				200
			);
		}

		if ( $log_size > $max_read ) {
			fseek( $fp, -$max_read, SEEK_END );
			fgets( $fp ); // Skip the partial first line.
		}

		$lines = array();
		while ( ( $line = fgets( $fp ) ) !== false ) { // phpcs:ignore WordPress.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition
			$line = trim( $line );
			if ( '' !== $line ) {
				$lines[] = $line;
			}
		}
		fclose( $fp ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

		// PHP log format: [DD-Mon-YYYY HH:MM:SS UTC] TYPE: message in file on line N.
		$errors  = array();
		$pattern = '/^\[([^\]]+)\]\s+(PHP\s+)?(Fatal\s+error|Parse\s+error|Warning|Notice|Deprecated|Strict\s+Standards|Recoverable\s+fatal\s+error):\s*(.+?)(?:\s+in\s+(.+?)\s+on\s+line\s+(\d+))?$/i';

		foreach ( array_reverse( $lines ) as $line ) {
			if ( count( $errors ) >= $limit ) {
				break;
			}
			if ( preg_match( $pattern, $line, $m ) ) {
				$errors[] = array(
					'time'    => $m[1],
					'type'    => trim( $m[3] ),
					'message' => trim( $m[4] ),
					'file'    => isset( $m[5] ) && '' !== $m[5] ? basename( $m[5] ) : null,
					'line'    => isset( $m[6] ) && '' !== $m[6] ? (int) $m[6] : null,
				);
			}
		}

		$total       = 0;
		$type_counts = array(
			'fatal'      => 0,
			'warning'    => 0,
			'notice'     => 0,
			'deprecated' => 0,
			'other'      => 0,
		);
		foreach ( $lines as $l ) {
			if ( preg_match( '/^\[.+\]\s+(PHP\s+)?(Fatal\s+error|Parse\s+error|Warning|Notice|Deprecated|Strict\s+Standards|Recoverable\s+fatal\s+error)/i', $l, $tc ) ) {
				++$total;
				$t = strtolower( trim( $tc[2] ) );
				if ( str_contains( $t, 'fatal' ) || str_contains( $t, 'parse' ) ) {
					++$type_counts['fatal'];
				} elseif ( 'warning' === $t ) {
					++$type_counts['warning'];
				} elseif ( 'notice' === $t || str_contains( $t, 'strict' ) ) {
					++$type_counts['notice'];
				} elseif ( 'deprecated' === $t ) {
					++$type_counts['deprecated'];
				} else {
					++$type_counts['other'];
				}
			} elseif ( preg_match( '/^\[.+\]\s+/', $l ) ) {
				++$total;
				++$type_counts['other'];
			}
		}

		return new WP_REST_Response(
			array(
				'errors'      => $errors,
				'total_count' => $total,
				'type_counts' => $type_counts,
				'log_size'    => $this->format_bytes( $log_size ),
				'log_path'    => basename( $log_path ),
				'available'   => true,
			),
			200
		);
	}

	/**
	 * Cached PageSpeed measurement (the Central App measures and pushes it to
	 * the legacy `ux1_pagespeed_data` transient; the Studio's dashboard widget
	 * keeps its own cache, so on a pure-Studio site this is `available:false`).
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function pagespeed( WP_REST_Request $request ): WP_REST_Response {
		$data = get_transient( self::PAGESPEED_TRANSIENT );

		if ( empty( $data ) || ! is_array( $data ) ) {
			return new WP_REST_Response(
				array(
					'available' => false,
					'data'      => null,
					'age_hours' => null,
				),
				200
			);
		}

		$age_hours = null;
		if ( ! empty( $data['measured'] ) ) {
			$age_hours = round( ( time() - (int) $data['measured'] ) / 3600, 1 );
		}

		return new WP_REST_Response(
			array(
				'available' => true,
				'data'      => $data,
				'age_hours' => $age_hours,
			),
			200
		);
	}

	/**
	 * Result of the last outgoing-mail test (Email Health module).
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function email_health( WP_REST_Request $request ): WP_REST_Response {
		$result = get_option( 'uxstudio_email_health_result', null );

		if ( empty( $result ) || ! is_array( $result ) ) {
			return new WP_REST_Response(
				array(
					'available' => false,
					'success'   => null,
					'error'     => null,
					'method'    => null,
					'tested_at' => null,
					'age_hours' => null,
				),
				200
			);
		}

		$age_hours = null;
		if ( ! empty( $result['timestamp'] ) ) {
			$age_hours = round( ( time() - (int) $result['timestamp'] ) / 3600, 1 );
		}

		return new WP_REST_Response(
			array(
				'available'   => true,
				'success'     => ! empty( $result['success'] ),
				'error'       => ! empty( $result['error'] ) ? $result['error'] : null,
				'method'      => ! empty( $result['method'] ) ? $result['method'] : null,
				'tested_at'   => $result['tested_at'] ?? null,
				'age_hours'   => $age_hours,
				// The mail-tester.com scraper was intentionally dropped in the Studio.
				'mail_tester' => null,
			),
			200
		);
	}

	/**
	 * Aggregated security report: cron watcher, file-injection scan, key-file
	 * integrity, new users / admin security and hardening posture.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function security_monitor( WP_REST_Request $request ): WP_REST_Response {
		$now     = time();
		$enabled = $this->enabled_ids();

		// Cron watcher.
		$cron_raw = get_option( 'uxstudio_cron_watch_result', null );
		$cron     = array( 'available' => false );
		if ( is_array( $cron_raw ) ) {
			$cron = array(
				'available'        => true,
				'total_events'     => (int) ( $cron_raw['total_events'] ?? 0 ),
				'unknown_count'    => count( (array) ( $cron_raw['unknown'] ?? array() ) ),
				'suspicious'       => array_slice( (array) ( $cron_raw['suspicious'] ?? array() ), 0, 50 ),
				'suspicious_count' => count( (array) ( $cron_raw['suspicious'] ?? array() ) ),
				'removed_count'    => count( (array) ( $cron_raw['removed'] ?? array() ) ),
				'scanned_at'       => $cron_raw['scanned_at'] ?? null,
				'age_hours'        => ! empty( $cron_raw['timestamp'] ) ? round( ( $now - (int) $cron_raw['timestamp'] ) / 3600, 1 ) : null,
			);
		}

		// File-injection scan (Upload Guard) + key-file integrity.
		$files     = array(
			'available'        => false,
			'unresolved_count' => 0,
			'severity_max'     => 'clean',
			'last_scan_at'     => null,
			'last_full_scan'   => null,
			'full_scan_files'  => 0,
			'findings'         => array(),
		);
		$integrity = array( 'available' => false );

		if ( in_array( 'security-optimization', $enabled, true ) ) {
			list( $files, $integrity ) = $this->upload_guard_report();
		}

		// New users + admin security from the activity log.
		$new_users      = array(
			'available'   => false,
			'count'       => 0,
			'admin_count' => 0,
			'items'       => array(),
		);
		$admin_security = array(
			'available'        => false,
			'new_admins'       => array(),
			'role_escalations' => array(),
			'new_ip_logins'    => array(),
		);
		$recent_plugin_activations = 0;

		if ( in_array( 'activity-log', $enabled, true ) ) {
			$activity                  = $this->activity_report( 7 );
			$new_users                 = $activity['new_users'];
			$admin_security            = $activity['admin_security'];
			$recent_plugin_activations = $activity['plugin_activations'];
		}

		// Hardening posture, read from the environment.
		$perms     = array(
			'wpconfig_writable'  => $this->is_wpconfig_writable(),
			'core_writable'      => is_writable( ABSPATH . 'wp-includes' ), // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable
			'uploads_executable' => false,
		);
		$hardening = array(
			'disallow_file_edit'        => defined( 'DISALLOW_FILE_EDIT' ) && DISALLOW_FILE_EDIT,
			'disallow_file_mods'        => defined( 'DISALLOW_FILE_MODS' ) && DISALLOW_FILE_MODS,
			'xmlrpc_enabled'            => (bool) apply_filters( 'xmlrpc_enabled', true ),
			'debug_display'             => defined( 'WP_DEBUG' ) && WP_DEBUG && ( ! defined( 'WP_DEBUG_DISPLAY' ) || WP_DEBUG_DISPLAY ),
			'wpconfig_writable'         => $perms['wpconfig_writable'],
			'core_writable'             => $perms['core_writable'],
			'uploads_executable'        => $perms['uploads_executable'],
			'recent_plugin_activations' => $recent_plugin_activations,
		);

		$hardening['risk_count'] =
			( ! $hardening['disallow_file_edit'] ? 1 : 0 )
			+ ( $hardening['xmlrpc_enabled'] ? 1 : 0 )
			+ ( $hardening['debug_display'] ? 1 : 0 )
			+ ( $hardening['wpconfig_writable'] ? 1 : 0 )
			+ ( $hardening['core_writable'] ? 1 : 0 )
			+ ( $hardening['uploads_executable'] ? 1 : 0 );

		// Weighted score (same weights as the legacy plugin).
		$score = (int) $files['unresolved_count']
			+ (int) ( $integrity['total_changes'] ?? 0 )
			+ (int) ( $cron['suspicious_count'] ?? 0 )
			+ (int) ( $new_users['admin_count'] ?? 0 )
			+ count( $admin_security['role_escalations'] )
			+ count( $admin_security['new_ip_logins'] );

		if ( 'critical' === ( $files['severity_max'] ?? '' ) ) {
			$score += 10;
		}
		if ( ( $integrity['added_count'] ?? 0 ) > 0 ) {
			$score += 10;
		}
		if ( ! empty( $integrity['keyfiles']['count'] ) ) {
			$score += 5 * (int) $integrity['keyfiles']['count'];
		}
		if ( ! empty( $admin_security['role_escalations'] ) || ! empty( $admin_security['new_admins'] ) ) {
			$score += 5;
		}

		return new WP_REST_Response(
			array(
				'available'      => true,
				'score'          => $score,
				'cron'           => $cron,
				'files'          => $files,
				'integrity'      => $integrity,
				'new_users'      => $new_users,
				'admin_security' => $admin_security,
				'hardening'      => $hardening,
				'checked_at'     => current_time( 'mysql' ),
			),
			200
		);
	}

	/**
	 * Last WordPress vulnerability scan, mapped to the legacy result shape
	 * (`wp_version`, `version_status`, `vulnerabilities[]`, `vuln_count`, ...).
	 * Plugin/theme "newer version available" findings are not vulnerabilities
	 * and stay out of `vulnerabilities`; they are exposed as `outdated_count`.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function vulnerabilities( WP_REST_Request $request ): WP_REST_Response {
		$unavailable = new WP_REST_Response(
			array(
				'available' => false,
				'data'      => null,
			),
			200
		);

		$module = $this->module_instance( 'vulnerability-scanner' );
		if ( ! $module instanceof VulnerabilityScannerModule ) {
			return $unavailable;
		}

		$result = $module->get_last_result();
		if ( empty( $result['scanned_at'] ) ) {
			return $unavailable;
		}

		$wp_version     = get_bloginfo( 'version' );
		$latest_version = $wp_version;
		$version_status = 'latest';
		$vulns          = array();
		$outdated       = 0;

		foreach ( (array) ( $result['findings'] ?? array() ) as $finding ) {
			if ( ! is_array( $finding ) ) {
				continue;
			}
			$is_outdated_only = 'wp-org-outdated' === ( $finding['source'] ?? '' );

			if ( 'core' === ( $finding['type'] ?? '' ) && $is_outdated_only ) {
				$version_status = 'high' === ( $finding['severity'] ?? '' ) ? 'insecure' : 'outdated';
				if ( ! empty( $finding['fixed_in'] ) ) {
					$latest_version = (string) $finding['fixed_in'];
				}
				if ( 'insecure' === $version_status ) {
					$vulns[] = $this->legacy_vulnerability( $finding );
				}
				continue;
			}
			if ( $is_outdated_only ) {
				++$outdated;
				continue;
			}
			$vulns[] = $this->legacy_vulnerability( $finding );
		}

		usort(
			$vulns,
			static fn ( array $a, array $b ): int => ( $b['cvss'] ?? 0 ) <=> ( $a['cvss'] ?? 0 )
		);

		$levels    = array(
			'critical' => 4,
			'high'     => 3,
			'medium'   => 2,
			'low'      => 1,
			'info'     => 0,
		);
		$max       = -1;
		$max_level = 'none';
		$must      = 'insecure' === $version_status;
		foreach ( $vulns as $vuln ) {
			$sev = (string) ( $vuln['severity'] ?? 'info' );
			if ( isset( $levels[ $sev ] ) && $levels[ $sev ] > $max ) {
				$max       = $levels[ $sev ];
				$max_level = $sev;
			}
			if ( in_array( $sev, array( 'critical', 'high' ), true ) ) {
				$must = true;
			}
		}

		if ( empty( $vulns ) && 'latest' === $version_status ) {
			$recommendation = __( 'No known vulnerabilities were found. No action is needed.', 'ux-studio' );
		} elseif ( $must ) {
			$recommendation = __( 'Important: critical or high severity issues were found. Update the affected components as soon as possible.', 'ux-studio' );
		} else {
			$recommendation = __( 'Updates or minor issues were found. Keep WordPress, plugins and themes up to date.', 'ux-studio' );
		}

		$data = array(
			'wp_version'      => $wp_version,
			'latest_version'  => $latest_version,
			'version_status'  => $version_status,
			'vulnerabilities' => $vulns,
			'vuln_count'      => count( $vulns ),
			'severity_level'  => $max_level,
			'must_update'     => $must,
			'recommendation'  => $recommendation,
			'data_source'     => 'ux-studio',
			'outdated_count'  => $outdated,
			'central_error'   => null,
			'scanned_at'      => (string) $result['scanned_at'],
		);

		return new WP_REST_Response(
			array(
				'available' => true,
				'data'      => $data,
				'age_hours' => round( ( time() - (int) strtotime( (string) $result['scanned_at'] ) ) / 3600, 1 ),
			),
			200
		);
	}

	/**
	 * Guide title + HTML content. Built from the Guide module's steps; falls
	 * back to the legacy `wpextended__guide_settings` option on migrated sites
	 * where the Guide module is off.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function guide( WP_REST_Request $request ): WP_REST_Response {
		$module = $this->module_instance( 'guide' );

		if ( $module instanceof GuideModule ) {
			$html = '<ol>';
			foreach ( $module->steps_with_progress() as $step ) {
				$html .= '<li><strong>' . esc_html( (string) $step['title'] ) . '</strong>'
					. ( ! empty( $step['completed'] ) ? ' &#10003;' : '' )
					. '<br>' . esc_html( (string) $step['description'] ) . '</li>';
			}
			$html .= '</ol>';

			return new WP_REST_Response(
				array(
					'title'   => __( 'Getting started guide', 'ux-studio' ),
					'content' => $html,
				),
				200
			);
		}

		$legacy = get_option( self::LEGACY_GUIDE_OPTION, array() );
		$legacy = is_array( $legacy ) ? $legacy : array();

		return new WP_REST_Response(
			array(
				'title'   => (string) ( $legacy['title'] ?? '' ),
				'content' => wp_kses_post( (string) ( $legacy['content'] ?? '' ) ),
			),
			200
		);
	}

	/**
	 * Service requests in the legacy list shape the Central App mirrors
	 * (`subject`, `user_name`, `status` new|in_progress|completed|rejected, ...).
	 *
	 * @param WP_REST_Request $request Request (`status` filter, legacy values).
	 */
	public function service_requests( WP_REST_Request $request ): WP_REST_Response {
		global $wpdb;
		$table = $wpdb->prefix . 'uxstudio_service_requests';

		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			return new WP_REST_Response( array( 'error' => 'Table not found' ), 404 );
		}

		$to_studio = array(
			'new'         => 'open',
			'in_progress' => 'in_progress',
			'completed'   => 'done',
		);
		$to_legacy = array(
			'open'        => 'new',
			'in_progress' => 'in_progress',
			'done'        => 'completed',
		);

		$status = sanitize_key( (string) $request->get_param( 'status' ) );
		if ( 'rejected' === $status ) {
			// The Studio has no "rejected" state.
			return new WP_REST_Response( array(), 200 );
		}

		$module = $this->module_instance( 'service-requests' );
		if ( $module instanceof ServiceRequestsModule ) {
			$rows = $module->list_items( $to_studio[ $status ] ?? '' );
		} else {
			// Module off: read the table directly (still the site's own data).
			$raw  = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY id DESC", ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$rows = array();
			foreach ( is_array( $raw ) ? $raw : array() as $row ) {
				if ( '' !== $status && isset( $to_studio[ $status ] ) && $row['status'] !== $to_studio[ $status ] ) {
					continue;
				}
				$rows[] = $row;
			}
		}

		$order = array(
			'new'         => 0,
			'in_progress' => 1,
			'completed'   => 2,
			'rejected'    => 3,
		);
		$out   = array();
		foreach ( $rows as $row ) {
			$legacy_status = $to_legacy[ (string) $row['status'] ] ?? 'new';
			$out[]         = array(
				'id'         => (int) $row['id'],
				'subject'    => (string) ( $row['title'] ?? '' ),
				'status'     => $legacy_status,
				'user_name'  => (string) ( $row['requester_name'] ?? '' ),
				'user_email' => (string) ( $row['requester_email'] ?? '' ),
				'url'        => (string) ( $row['page_url'] ?? '' ),
				'budget'     => '',
				'deadline'   => '',
				'admin_note' => '',
				'created_at' => (string) ( $row['created_at'] ?? '' ),
				'updated_at' => (string) ( $row['synced_at'] ?? $row['created_at'] ?? '' ),
			);
		}

		usort(
			$out,
			static function ( array $a, array $b ) use ( $order ): int {
				$cmp = $order[ $a['status'] ] <=> $order[ $b['status'] ];
				return 0 !== $cmp ? $cmp : strcmp( $b['created_at'], $a['created_at'] );
			}
		);

		return new WP_REST_Response( $out, 200 );
	}

	/* ------------------------------------------------------------------
	 * Helpers
	 * ------------------------------------------------------------------ */

	/**
	 * Enabled module ids.
	 *
	 * @return string[]
	 */
	private function enabled_ids(): array {
		return Plugin::instance()->modules->enabled_ids();
	}

	/**
	 * Instance of an enabled module, or null.
	 *
	 * @param string $id Module id.
	 * @return object|null
	 */
	private function module_instance( string $id ) {
		if ( ! in_array( $id, $this->enabled_ids(), true ) ) {
			return null;
		}
		return Plugin::instance()->modules->instance( $id );
	}

	/**
	 * Human readable byte size.
	 *
	 * @param int $bytes Bytes.
	 */
	private function format_bytes( int $bytes ): string {
		if ( $bytes >= 1048576 ) {
			return round( $bytes / 1048576, 1 ) . ' MB';
		}
		if ( $bytes >= 1024 ) {
			return round( $bytes / 1024, 1 ) . ' KB';
		}
		return $bytes . ' B';
	}

	/**
	 * Whether wp-config.php (here or one level up) is writable by PHP.
	 */
	private function is_wpconfig_writable(): bool {
		$path = ABSPATH . 'wp-config.php';
		if ( ! file_exists( $path ) ) {
			$path = dirname( ABSPATH ) . '/wp-config.php';
		}
		return file_exists( $path ) && is_writable( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable
	}

	/**
	 * Map one Studio finding to the legacy vulnerability item shape.
	 *
	 * @param array<string, mixed> $finding Studio finding.
	 * @return array<string, mixed>
	 */
	private function legacy_vulnerability( array $finding ): array {
		$cve = (string) ( $finding['cve'] ?? '' );
		return array(
			'id'          => '' !== $cve ? $cve : (string) ( $finding['key'] ?? '' ),
			'title'       => (string) ( $finding['title'] ?? '' ),
			'severity'    => (string) ( $finding['severity'] ?? 'info' ),
			'cvss'        => isset( $finding['cvss'] ) ? (float) $finding['cvss'] : null,
			'fixed_in'    => (string) ( $finding['fixed_in'] ?? '' ),
			'type'        => (string) ( $finding['type'] ?? '' ),
			'slug'        => (string) ( $finding['slug'] ?? '' ),
			'name'        => (string) ( $finding['name'] ?? '' ),
			'installed'   => (string) ( $finding['installed_version'] ?? '' ),
			'description' => (string) ( $finding['description'] ?? '' ),
			'references'  => (array) ( $finding['references'] ?? array() ),
		);
	}

	/**
	 * Upload Guard findings + key-file integrity in the legacy `files` /
	 * `integrity` shapes. The Studio has no WordPress core checksum comparison,
	 * so `changed|added|missing` stay empty and only `keyfiles` is populated.
	 *
	 * @return array{0:array<string,mixed>,1:array<string,mixed>}
	 */
	private function upload_guard_report(): array {
		global $wpdb;
		$findings_table = $wpdb->prefix . 'uxstudio_security_findings';
		$hashes_table   = $wpdb->prefix . 'uxstudio_security_file_hashes';

		$stats = get_option( 'uxstudio_security_scan_stats', array() );
		$stats = is_array( $stats ) ? $stats : array();

		$has_table = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $findings_table ) ) === $findings_table; // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$rows      = array();
		if ( $has_table ) {
			$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT file_path, finding_type, severity, status, details, created_at FROM {$findings_table}
				WHERE status IN ('scanned','quarantined') ORDER BY id DESC LIMIT 500",
				ARRAY_A
			);
			$rows = is_array( $rows ) ? $rows : array();
		}

		$rank         = array(
			'low'      => 1,
			'medium'   => 2,
			'high'     => 3,
			'critical' => 4,
		);
		$malware      = array();
		$key_changed  = array();
		$max_rank     = 0;
		$severity_max = 'clean';

		foreach ( $rows as $row ) {
			if ( 'integrity' === $row['finding_type'] ) {
				$key_changed[] = (string) $row['file_path'];
				continue;
			}
			$details  = json_decode( (string) $row['details'], true );
			$details  = is_array( $details ) ? $details : array();
			$malware[] = array(
				'file'        => basename( (string) $row['file_path'] ),
				'path'        => (string) $row['file_path'],
				'severity'    => (string) $row['severity'],
				'score'       => (int) ( $details['score'] ?? 0 ),
				'detected_at' => $row['created_at'],
				'source'      => (string) ( $details['source'] ?? 'upload' ),
			);
			if ( ( $rank[ $row['severity'] ] ?? 0 ) > $max_rank ) {
				$max_rank     = $rank[ $row['severity'] ];
				$severity_max = (string) $row['severity'];
			}
		}

		$files = array(
			'available'        => ! empty( $stats ) || $has_table,
			'unresolved_count' => count( $malware ),
			'severity_max'     => $severity_max,
			'last_scan_at'     => $stats['last_scan_at'] ?? null,
			'last_full_scan'   => $stats['last_scan_at'] ?? null,
			'full_scan_files'  => (int) ( $stats['files_scanned'] ?? 0 ),
			'findings'         => array_slice( $malware, 0, 50 ),
		);

		$baselined = 0;
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $hashes_table ) ) === $hashes_table ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$baselined = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$hashes_table} WHERE approved = 1" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}

		$kf_count  = count( $key_changed );
		$integrity = array(
			'available'     => $baselined > 0,
			'source'        => 'key-files',
			'changed_count' => 0,
			'missing_count' => 0,
			'added_count'   => 0,
			'total_changes' => $kf_count,
			'changed'       => array(),
			'added'         => array(),
			'missing'       => array(),
			'keyfiles'      => array(
				'changed' => array_slice( $key_changed, 0, 50 ),
				'added'   => array(),
				'removed' => array(),
				'count'   => $kf_count,
			),
			'scanned_at'    => $stats['last_scan_at'] ?? null,
			'age_hours'     => ! empty( $stats['last_scan_at'] ) ? round( ( time() - (int) strtotime( (string) $stats['last_scan_at'] ) ) / 3600, 1 ) : null,
		);

		return array( $files, $integrity );
	}

	/**
	 * New users, role escalations, new-IP admin logins and plugin activations
	 * from the Studio activity log over the last N days.
	 *
	 * @param int $days Window in days.
	 * @return array{new_users:array<string,mixed>,admin_security:array<string,mixed>,plugin_activations:int}
	 */
	private function activity_report( int $days ): array {
		global $wpdb;
		$table  = $wpdb->prefix . 'uxstudio_activity_log';
		$cutoff = gmdate( 'Y-m-d H:i:s', (int) strtotime( "-{$days} days", (int) current_time( 'timestamp' ) ) ); // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp.Requested

		// New registrations.
		$reg_rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT object_id, meta, created_at FROM {$table} WHERE module = 'activity-log' AND action = 'user_register' AND created_at >= %s ORDER BY id DESC LIMIT 200", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$cutoff
			),
			ARRAY_A
		);
		$reg_rows = is_array( $reg_rows ) ? $reg_rows : array();

		$items       = array();
		$new_admins  = array();
		$admin_count = 0;
		foreach ( $reg_rows as $row ) {
			$user_id = (int) $row['object_id'];
			$meta    = json_decode( (string) $row['meta'], true );
			$meta    = is_array( $meta ) ? $meta : array();
			$user    = $user_id ? get_userdata( $user_id ) : false;

			$role         = null;
			$email_domain = null;
			$flagged      = false;
			if ( $user instanceof \WP_User ) {
				$role = $user->roles[0] ?? null;
				if ( ! empty( $user->user_email ) && false !== strpos( $user->user_email, '@' ) ) {
					$email_domain = substr( (string) strrchr( $user->user_email, '@' ), 1 );
				}
				if ( array_intersect( $user->roles, array( 'administrator', 'editor', 'super_admin' ) ) ) {
					$flagged = true;
					++$admin_count;
				}
			}

			$item    = array(
				'user_id'      => $user_id,
				'name'         => (string) ( $meta['login'] ?? '' ),
				'role'         => $role,
				'email_domain' => $email_domain,
				'ip'           => '',
				'created_at'   => $row['created_at'],
				'flagged'      => $flagged,
				'exists'       => $user instanceof \WP_User,
			);
			$items[] = $item;
			if ( $flagged ) {
				$new_admins[] = $item;
			}
		}

		// Role escalations (role_change events).
		$esc_rows    = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT object_id, meta, created_at FROM {$table} WHERE module = 'activity-log' AND action = 'role_change' AND created_at >= %s ORDER BY id DESC LIMIT 100", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$cutoff
			),
			ARRAY_A
		);
		$escalations = array();
		foreach ( is_array( $esc_rows ) ? $esc_rows : array() as $row ) {
			$meta          = json_decode( (string) $row['meta'], true );
			$meta          = is_array( $meta ) ? $meta : array();
			$escalations[] = array(
				'user_id'    => (int) $row['object_id'],
				'detail'     => trim( (string) ( $meta['from'] ?? '' ) . ' -> ' . (string) ( $meta['role'] ?? '' ) ),
				'ip'         => '',
				'created_at' => $row['created_at'],
			);
		}

		// Logins of privileged users from an IP never seen before that login.
		$login_rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT id, user_id, meta, created_at FROM {$table} WHERE module = 'activity-log' AND action = 'login' AND created_at >= %s ORDER BY id ASC LIMIT 500", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$cutoff
			),
			ARRAY_A
		);
		$new_ip     = array();
		$seen       = array();
		foreach ( is_array( $login_rows ) ? $login_rows : array() as $row ) {
			$meta = json_decode( (string) $row['meta'], true );
			$ip   = is_array( $meta ) ? (string) ( $meta['ip'] ?? '' ) : '';
			$uid  = (int) $row['user_id'];
			if ( '' === $ip || $uid <= 0 || isset( $seen[ $uid . '|' . $ip ] ) ) {
				continue;
			}
			$seen[ $uid . '|' . $ip ] = true;

			$user = get_userdata( $uid );
			if ( ! $user || ! array_intersect( $user->roles, array( 'administrator', 'editor' ) ) ) {
				continue;
			}
			$before = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->prepare(
					"SELECT id FROM {$table} WHERE module = 'activity-log' AND action = 'login' AND user_id = %d AND id < %d AND meta LIKE %s LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$uid,
					(int) $row['id'],
					'%' . $wpdb->esc_like( '"ip":"' . $ip . '"' ) . '%'
				)
			);
			// First ever login of the account is the baseline, not an alert.
			$any_before = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->prepare(
					"SELECT id FROM {$table} WHERE module = 'activity-log' AND action = 'login' AND user_id = %d AND id < %d LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$uid,
					(int) $row['id']
				)
			);
			if ( null === $before && null !== $any_before ) {
				$new_ip[] = array(
					'user_id'    => $uid,
					'name'       => $user->display_name,
					'role'       => $user->roles[0] ?? null,
					'ip'         => $ip,
					'created_at' => $row['created_at'],
				);
			}
		}

		// Plugin activations.
		$activations = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE module = 'activity-log' AND action = 'activated' AND object_type = 'plugin' AND created_at >= %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$cutoff
			)
		);

		return array(
			'new_users'          => array(
				'available'   => true,
				'count'       => count( $items ),
				'admin_count' => $admin_count,
				'items'       => array_slice( $items, 0, 50 ),
			),
			'admin_security'     => array(
				'available'        => true,
				'new_admins'       => array_slice( $new_admins, 0, 50 ),
				'role_escalations' => array_slice( $escalations, 0, 50 ),
				'new_ip_logins'    => array_slice( $new_ip, 0, 50 ),
			),
			'plugin_activations' => $activations,
		);
	}
}
