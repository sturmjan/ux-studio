<?php
/**
 * Upload Guard REST controller.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\SecurityOptimization;

use UxStudio\Rest\Controller;
use WP_Error;
use WP_REST_Request;

defined( 'ABSPATH' ) || exit;

/**
 * GET  uxstudio/v1/security-optimization/findings         - list findings (filter status/severity)
 * POST uxstudio/v1/security-optimization/findings/{id}/approve - approve a finding (restore + whitelist hash)
 * POST uxstudio/v1/security-optimization/findings/{id}/delete  - permanently delete a quarantined file
 * POST uxstudio/v1/security-optimization/scan             - trigger a manual, batched full scan
 * GET  uxstudio/v1/security-optimization/scan-status       - current scan progress
 *
 * All routes here require manage_options (the only public endpoint in this
 * module, csp-report, lives in CspRestController).
 */
final class UploadGuardRestController extends Controller {

	/**
	 * Register routes.
	 */
	public function register_routes(): void {
		$this->route( '/security-optimization/findings', 'GET', array( $this, 'list_findings' ) );
		$this->route(
			'/security-optimization/findings/(?P<id>\d+)/approve',
			'POST',
			array( $this, 'approve_finding' ),
			array(
				'id' => array(
					'required' => true,
					'type'     => 'integer',
				),
			)
		);
		$this->route(
			'/security-optimization/findings/(?P<id>\d+)/delete',
			'POST',
			array( $this, 'delete_finding' ),
			array(
				'id' => array(
					'required' => true,
					'type'     => 'integer',
				),
			)
		);
		$this->route( '/security-optimization/scan', 'POST', array( $this, 'trigger_scan' ) );
		$this->route( '/security-optimization/scan-status', 'GET', array( $this, 'scan_status' ) );
	}

	/**
	 * @param WP_REST_Request $request Request.
	 */
	public function list_findings( WP_REST_Request $request ) {
		global $wpdb;
		$table = $wpdb->prefix . 'uxstudio_security_findings';

		$status   = sanitize_key( (string) $request->get_param( 'status' ) );
		$severity = sanitize_key( (string) $request->get_param( 'severity' ) );
		$per_page = max( 1, min( 200, (int) ( $request->get_param( 'per_page' ) ?: 50 ) ) );
		$paged    = max( 1, (int) ( $request->get_param( 'paged' ) ?: 1 ) );

		$where  = array( '1=1' );
		$params = array();

		if ( '' !== $status && in_array( $status, array( 'queued', 'scanned', 'quarantined', 'approved', 'deleted' ), true ) ) {
			$where[]  = 'status = %s';
			$params[] = $status;
		}
		if ( '' !== $severity && in_array( $severity, array( 'low', 'medium', 'high', 'critical' ), true ) ) {
			$where[]  = 'severity = %s';
			$params[] = $severity;
		}

		$where_sql = implode( ' AND ', $where );
		$offset    = ( $paged - 1 ) * $per_page;

		$count_sql = "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}"; // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$total     = (int) $wpdb->get_var( empty( $params ) ? $count_sql : $wpdb->prepare( $count_sql, $params ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		$sql        = "SELECT * FROM {$table} WHERE {$where_sql} ORDER BY id DESC LIMIT %d OFFSET %d"; // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$list_params = array_merge( $params, array( $per_page, $offset ) );
		$items       = $wpdb->get_results( $wpdb->prepare( $sql, $list_params ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		foreach ( $items as &$item ) {
			if ( isset( $item->details ) && is_string( $item->details ) && '' !== $item->details ) {
				$decoded         = json_decode( $item->details, true );
				$item->details = is_array( $decoded ) ? $decoded : null;
			}
		}
		unset( $item );

		return $this->ok( $items ?: array(), array( 'total' => $total ) );
	}

	/**
	 * Approve a finding: restores a quarantined file (if any) and marks its
	 * hash as an approved baseline so future scans no longer flag it; for
	 * 'integrity' findings, accepts the new hash as the trusted baseline.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function approve_finding( WP_REST_Request $request ) {
		global $wpdb;
		$id    = (int) $request->get_param( 'id' );
		$table = $wpdb->prefix . 'uxstudio_security_findings';
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		if ( ! $row ) {
			return new WP_Error( 'uxstudio_finding_not_found', __( 'Finding not found.', 'ux-studio' ), array( 'status' => 404 ) );
		}

		if ( 'integrity' === $row->finding_type ) {
			( new UploadGuardIntegrityChecker() )->approve( (string) $row->file_path );
			$wpdb->update( $table, array( 'status' => 'approved' ), array( 'id' => $id ), array( '%s' ), array( '%d' ) );
			return $this->ok( array( 'approved' => true ) );
		}

		$details = is_string( $row->details ) ? json_decode( $row->details, true ) : array();
		$details = is_array( $details ) ? $details : array();

		if ( 'quarantined' === $row->status && ! empty( $details['quarantine_path'] ) ) {
			$restored = UploadGuardQuarantine::restore_file( (string) $details['quarantine_path'], (string) $row->file_path );
			if ( ! $restored ) {
				return new WP_Error( 'uxstudio_restore_failed', __( 'Could not restore the file from quarantine.', 'ux-studio' ), array( 'status' => 500 ) );
			}
		}

		$this->mark_hash_approved( (string) $row->file_path, (string) $row->hash );

		$wpdb->update( $table, array( 'status' => 'approved' ), array( 'id' => $id ), array( '%s' ), array( '%d' ) );

		return $this->ok( array( 'approved' => true ) );
	}

	/**
	 * Permanently delete a quarantined file. The finding row is kept
	 * (status = 'deleted') as an audit trail.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function delete_finding( WP_REST_Request $request ) {
		global $wpdb;
		$id    = (int) $request->get_param( 'id' );
		$table = $wpdb->prefix . 'uxstudio_security_findings';
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		if ( ! $row ) {
			return new WP_Error( 'uxstudio_finding_not_found', __( 'Finding not found.', 'ux-studio' ), array( 'status' => 404 ) );
		}

		$details = is_string( $row->details ) ? json_decode( $row->details, true ) : array();
		$details = is_array( $details ) ? $details : array();

		if ( ! empty( $details['quarantine_path'] ) ) {
			UploadGuardQuarantine::delete_quarantined( (string) $details['quarantine_path'] );
		}

		$wpdb->update( $table, array( 'status' => 'deleted' ), array( 'id' => $id ), array( '%s' ), array( '%d' ) );

		return $this->ok( array( 'deleted' => true ) );
	}

	/**
	 * Trigger a manual full scan. Runs in batches via wp_schedule_single_event,
	 * never inline in this request.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function trigger_scan( WP_REST_Request $request ) {
		$scanner = new UploadGuardScanner();
		$scanner->trigger_full_scan();
		return $this->ok( $scanner->get_status() );
	}

	/**
	 * @param WP_REST_Request $request Request.
	 */
	public function scan_status( WP_REST_Request $request ) {
		$scanner = new UploadGuardScanner();
		return $this->ok(
			array(
				'status' => $scanner->get_status(),
				'stats'  => $scanner->get_stats(),
			)
		);
	}

	/**
	 * Mark a file's current hash as an approved baseline in the shared
	 * hash-cache table, so future scans skip it.
	 */
	private function mark_hash_approved( string $file_path, string $hash ): void {
		global $wpdb;
		if ( '' === $file_path || '' === $hash ) {
			return;
		}
		$table  = $wpdb->prefix . 'uxstudio_security_file_hashes';
		$exists = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE file_path = %s", $file_path ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		if ( $exists ) {
			$wpdb->update(
				$table,
				array(
					'hash'       => $hash,
					'approved'   => 1,
					'updated_at' => current_time( 'mysql' ),
				),
				array( 'file_path' => $file_path ),
				array( '%s', '%d', '%s' ),
				array( '%s' )
			);
			return;
		}

		$wpdb->insert(
			$table,
			array(
				'file_path'  => $file_path,
				'hash'       => $hash,
				'approved'   => 1,
				'updated_at' => current_time( 'mysql' ),
			),
			array( '%s', '%s', '%d', '%s' )
		);
	}
}
