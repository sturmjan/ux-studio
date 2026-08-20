<?php
/**
 * Upload Guard integrity checker: hashes a small set of key files.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\SecurityOptimization;

defined( 'ABSPATH' ) || exit;

/**
 * Hashes ONLY a specific, bounded set of key files - wp-config.php, the
 * root .htaccess, and mu-plugins/*.php - never an arbitrary/full-disk
 * scan. Baseline hashes are stored (approved=1) in uxstudio_security_file_hashes
 * the first time a file is seen; subsequent runs compare against that
 * baseline and report a change without silently re-baselining (an admin
 * must explicitly approve the new hash via the findings REST endpoint).
 */
final class UploadGuardIntegrityChecker {

	/**
	 * Run the integrity check. Creates a 'high' severity finding in
	 * uxstudio_security_findings for every key file whose hash differs from
	 * its approved baseline.
	 *
	 * @return array{checked:int,changed:string[],baseline_built:string[]}
	 */
	public function run(): array {
		global $wpdb;

		$checked        = 0;
		$changed        = array();
		$baseline_built = array();

		foreach ( $this->key_file_paths() as $path ) {
			if ( ! is_file( $path ) || ! is_readable( $path ) ) {
				continue;
			}

			$checked++;
			$hash = hash_file( 'sha256', $path );
			if ( false === $hash ) {
				continue;
			}

			$table   = $wpdb->prefix . 'uxstudio_security_file_hashes';
			$existing = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE file_path = %s", $path ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

			if ( ! $existing ) {
				// First time seeing this file: establish the trusted baseline.
				$wpdb->insert(
					$table,
					array(
						'file_path'  => $path,
						'hash'       => $hash,
						'approved'   => 1,
						'updated_at' => current_time( 'mysql' ),
					),
					array( '%s', '%s', '%d', '%s' )
				);
				$baseline_built[] = $path;
				continue;
			}

			if ( $existing->hash !== $hash && (int) $existing->approved === 1 ) {
				$changed[] = $path;
				$this->record_finding( $path, $hash );
			}
		}

		return array(
			'checked'        => $checked,
			'changed'        => $changed,
			'baseline_built' => $baseline_built,
		);
	}

	/**
	 * Approve a new hash for a key file as the trusted baseline (called from
	 * the findings REST 'approve' action when finding_type = 'integrity').
	 */
	public function approve( string $path ): bool {
		global $wpdb;
		if ( ! is_file( $path ) || ! is_readable( $path ) ) {
			return false;
		}
		$hash = hash_file( 'sha256', $path );
		if ( false === $hash ) {
			return false;
		}

		$table = $wpdb->prefix . 'uxstudio_security_file_hashes';
		$updated = $wpdb->update(
			$table,
			array(
				'hash'       => $hash,
				'approved'   => 1,
				'updated_at' => current_time( 'mysql' ),
			),
			array( 'file_path' => $path ),
			array( '%s', '%d', '%s' ),
			array( '%s' )
		);

		return false !== $updated;
	}

	/**
	 * @return string[] Absolute paths of key files to watch.
	 */
	private function key_file_paths(): array {
		$paths = array();

		$paths[] = ABSPATH . 'wp-config.php';
		$paths[] = dirname( ABSPATH ) . '/wp-config.php';
		$paths[] = ABSPATH . '.htaccess';

		if ( function_exists( 'get_home_path' ) ) {
			$home = get_home_path();
			if ( $home ) {
				$paths[] = rtrim( $home, '/\\' ) . '/.htaccess';
			}
		}

		if ( defined( 'WPMU_PLUGIN_DIR' ) && is_dir( WPMU_PLUGIN_DIR ) ) {
			foreach ( glob( WPMU_PLUGIN_DIR . '/*.php' ) ?: array() as $file ) {
				$paths[] = $file;
			}
		}

		return array_values( array_unique( array_map( 'wp_normalize_path', $paths ) ) );
	}

	/**
	 * Insert (or refresh) an 'integrity' finding for a changed key file.
	 */
	private function record_finding( string $path, string $new_hash ): void {
		global $wpdb;
		$table = $wpdb->prefix . 'uxstudio_security_findings';

		$existing_id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$table} WHERE file_path = %s AND finding_type = 'integrity' AND status = 'scanned'", // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				$path
			)
		);

		$details = wp_json_encode(
			array(
				'message'  => 'Key file hash changed since the last approved baseline.',
				'new_hash' => $new_hash,
			)
		);

		if ( $existing_id ) {
			$wpdb->update(
				$table,
				array(
					'details'    => $details,
					'scanned_at' => current_time( 'mysql' ),
				),
				array( 'id' => (int) $existing_id ),
				array( '%s', '%s' ),
				array( '%d' )
			);
			return;
		}

		$wpdb->insert(
			$table,
			array(
				'created_at'  => current_time( 'mysql' ),
				'file_path'   => $path,
				'finding_type' => 'integrity',
				'severity'    => 'high',
				'status'      => 'scanned',
				'hash'        => $new_hash,
				'details'     => $details,
				'scanned_at'  => current_time( 'mysql' ),
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);
	}
}
