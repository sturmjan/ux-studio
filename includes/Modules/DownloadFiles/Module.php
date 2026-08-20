<?php
/**
 * Download Files module - protected downloadable files served via a
 * tokenized link, backed by the standard WP media library.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\DownloadFiles;

use UxStudio\Core\ActivityLog;
use UxStudio\Modules\BaseModule;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Files are never uploaded through a custom endpoint: the admin UI picks an
 * existing attachment via the WP media library (wp.media) and this module
 * only ever persists the resulting attachment_id, never a filesystem path
 * from client input. Downloads are served through a per-row HMAC token
 * (see build_download_token()/verify_download_token()) rather than a WP
 * nonce, because a "download link" is meant to keep working indefinitely
 * and nonces expire.
 */
final class Module extends BaseModule {

	/**
	 * Register hooks.
	 */
	public function boot(): void {
		add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );

		\UxStudio\Core\DB::ensure_module_tables(
			'download-files',
			1,
			function ( int $from ): void {
				global $wpdb;
				$charset = $wpdb->get_charset_collate();
				dbDelta(
					"CREATE TABLE {$wpdb->prefix}uxstudio_download_files (
						id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
						created_at DATETIME NOT NULL,
						title VARCHAR(255) NOT NULL DEFAULT '',
						attachment_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
						download_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
						require_login TINYINT(1) NOT NULL DEFAULT 0,
						PRIMARY KEY  (id),
						KEY attachment_id (attachment_id)
					) {$charset};"
				);
			}
		);
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
				'key'     => 'require_login_default',
				'type'    => 'toggle',
				'label'   => __( 'Require login by default', 'ux-studio' ),
				'help'    => __( 'Default value of "require login" for newly added files.', 'ux-studio' ),
				'default' => true,
			),
		);
	}

	/**
	 * All files, newest first.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function list_files(): array {
		global $wpdb;
		$rows = $wpdb->get_results(
			"SELECT id, created_at, title, attachment_id, download_count, require_login FROM {$wpdb->prefix}uxstudio_download_files ORDER BY id DESC",
			ARRAY_A
		);
		$rows = is_array( $rows ) ? $rows : array();
		return array_map( array( $this, 'format_row' ), $rows );
	}

	/**
	 * One file row by id.
	 *
	 * @param int $id Row id.
	 */
	public function get_file( int $id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, created_at, title, attachment_id, download_count, require_login FROM {$wpdb->prefix}uxstudio_download_files WHERE id = %d",
				$id
			),
			ARRAY_A
		);
		return is_array( $row ) ? $this->format_row( $row ) : null;
	}

	/**
	 * Create a new protected file entry.
	 *
	 * @param array $data { title: string, attachment_id: int, require_login?: bool }.
	 * @return array<string, mixed>
	 */
	public function create_file( array $data ): array {
		global $wpdb;

		$require_login = array_key_exists( 'require_login', $data )
			? (bool) $data['require_login']
			: (bool) $this->settings->get( 'require_login_default', true );

		$wpdb->insert(
			"{$wpdb->prefix}uxstudio_download_files",
			array(
				'created_at'     => current_time( 'mysql' ),
				'title'          => mb_substr( (string) $data['title'], 0, 255 ),
				'attachment_id'  => absint( $data['attachment_id'] ),
				'download_count' => 0,
				'require_login'  => $require_login ? 1 : 0,
			),
			array( '%s', '%s', '%d', '%d', '%d' )
		);

		$id = (int) $wpdb->insert_id;

		ActivityLog::log( 'download-files', 'create', 'attachment', absint( $data['attachment_id'] ), array( 'file_id' => $id ) );

		return (array) $this->get_file( $id );
	}

	/**
	 * Update an existing file entry (title / require_login).
	 *
	 * @param int   $id   Row id.
	 * @param array $data Fields to update.
	 * @return array<string, mixed>|WP_Error
	 */
	public function update_file( int $id, array $data ) {
		global $wpdb;

		$existing = $this->get_file( $id );
		if ( null === $existing ) {
			return new WP_Error( 'uxstudio_not_found', __( 'File not found.', 'ux-studio' ), array( 'status' => 404 ) );
		}

		$update = array();
		$format = array();

		if ( array_key_exists( 'title', $data ) ) {
			$update['title'] = mb_substr( (string) $data['title'], 0, 255 );
			$format[]        = '%s';
		}
		if ( array_key_exists( 'require_login', $data ) ) {
			$update['require_login'] = (bool) $data['require_login'] ? 1 : 0;
			$format[]                = '%d';
		}

		if ( empty( $update ) ) {
			return $existing;
		}

		$wpdb->update(
			"{$wpdb->prefix}uxstudio_download_files",
			$update,
			array( 'id' => $id ),
			$format,
			array( '%d' )
		);

		return (array) $this->get_file( $id );
	}

	/**
	 * Delete a file entry (does not delete the underlying media attachment).
	 *
	 * @param int $id Row id.
	 */
	public function delete_file( int $id ): bool {
		global $wpdb;

		$existing = $this->get_file( $id );
		if ( null === $existing ) {
			return false;
		}

		$deleted = $wpdb->delete( "{$wpdb->prefix}uxstudio_download_files", array( 'id' => $id ), array( '%d' ) );

		if ( $deleted ) {
			ActivityLog::log( 'download-files', 'delete', 'attachment', (int) $existing['attachment_id'], array( 'file_id' => $id ) );
		}

		return (bool) $deleted;
	}

	/**
	 * Deterministic per-row download token. HMAC of the row id keyed with the
	 * site's auth salt; unlike a nonce this never expires, which is what a
	 * "download link" is expected to do.
	 *
	 * @param int $id Row id.
	 */
	public function build_download_token( int $id ): string {
		return hash_hmac( 'sha256', (string) $id, wp_salt( 'auth' ) );
	}

	/**
	 * Constant-time verification of a download token.
	 *
	 * @param int    $id    Row id.
	 * @param string $token Token to verify.
	 */
	public function verify_download_token( int $id, string $token ): bool {
		return hash_equals( $this->build_download_token( $id ), $token );
	}

	/**
	 * Stream the protected file to the client after validating the token,
	 * optional login requirement and that the resolved path stays inside the
	 * uploads directory. Called from RestController::serve(); this method
	 * never returns on success (it exits after streaming), and returns a
	 * WP_Error on any failure so the controller can render it.
	 *
	 * @param int    $id    Row id (uxstudio_download_files.id, NOT the attachment id).
	 * @param string $token Token from the ?token= query param.
	 * @return WP_Error|void
	 */
	public function serve( int $id, string $token ) {
		$file = $this->get_file( $id );
		if ( null === $file ) {
			return new WP_Error( 'uxstudio_not_found', __( 'File not found.', 'ux-studio' ), array( 'status' => 404 ) );
		}

		if ( ! $this->verify_download_token( $id, $token ) ) {
			return new WP_Error( 'uxstudio_invalid_token', __( 'Invalid or missing download token.', 'ux-studio' ), array( 'status' => 403 ) );
		}

		if ( $file['require_login'] && ! is_user_logged_in() ) {
			return new WP_Error( 'uxstudio_login_required', __( 'You must be logged in to download this file.', 'ux-studio' ), array( 'status' => 401 ) );
		}

		$attachment_id = (int) $file['attachment_id'];
		if ( 'attachment' !== get_post_type( $attachment_id ) ) {
			return new WP_Error( 'uxstudio_missing_attachment', __( 'The underlying media attachment is missing.', 'ux-studio' ), array( 'status' => 404 ) );
		}

		$path = get_attached_file( $attachment_id );
		if ( ! $path || ! is_file( $path ) ) {
			return new WP_Error( 'uxstudio_missing_file', __( 'The file is missing on disk.', 'ux-studio' ), array( 'status' => 404 ) );
		}

		// Mandatory defense-in-depth check: even though get_attached_file()
		// should always resolve inside the uploads dir, explicitly reject
		// anything that doesn't before touching the filesystem further.
		$real_path = realpath( $path );
		$real_base = realpath( wp_upload_dir()['basedir'] );
		if ( false === $real_path || false === $real_base || 0 !== strpos( $real_path, $real_base ) ) {
			return new WP_Error( 'uxstudio_path_traversal', __( 'Refused to serve a file outside the uploads directory.', 'ux-studio' ), array( 'status' => 403 ) );
		}

		global $wpdb;
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->prefix}uxstudio_download_files SET download_count = download_count + 1 WHERE id = %d",
				$id
			)
		);

		ActivityLog::log( 'download-files', 'download', 'attachment', $attachment_id, array( 'file_id' => $id ) );

		$filetype     = wp_check_filetype( basename( $real_path ) );
		$content_type = $filetype['type'] ?: 'application/octet-stream';
		$filename     = sanitize_file_name( basename( $real_path ) );

		nocache_headers();
		header( 'Content-Type: ' . $content_type );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'Content-Length: ' . (string) filesize( $real_path ) );
		header( 'X-Content-Type-Options: nosniff' );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_readfile -- streaming a validated, in-uploads-dir file to the client is the intended purpose of this endpoint.
		readfile( $real_path );
		exit;
	}

	/**
	 * Normalize a raw DB row for REST output (types, download url).
	 *
	 * @param array $row Raw row from $wpdb.
	 * @return array<string, mixed>
	 */
	private function format_row( array $row ): array {
		$id = (int) $row['id'];
		return array(
			'id'             => $id,
			'created_at'     => $row['created_at'],
			'title'          => $row['title'],
			'attachment_id'  => (int) $row['attachment_id'],
			'download_count' => (int) $row['download_count'],
			'require_login'  => (bool) $row['require_login'],
			'download_url'   => rest_url( 'uxstudio/v1/download-files/serve/' . $id ) . '?token=' . $this->build_download_token( $id ),
		);
	}
}
