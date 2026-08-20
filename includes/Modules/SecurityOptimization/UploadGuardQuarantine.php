<?php
/**
 * Upload Guard quarantine: moves suspicious files to a protected directory.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\SecurityOptimization;

defined( 'ABSPATH' ) || exit;

/**
 * Own quarantine directory under uploads/ (never the site-root .htaccess -
 * this is a module-owned directory, so a deny-all .htaccess here is safe
 * and does not touch the site's main rewrite rules). Files are MOVED
 * (rename), never deleted outright, so a false positive can be restored.
 */
final class UploadGuardQuarantine {

	private const QUARANTINE_DIR = 'uxstudio-security-quarantine';

	/**
	 * @return string Absolute path to the quarantine directory.
	 */
	public static function get_path(): string {
		$upload_dir = wp_upload_dir();
		return trailingslashit( $upload_dir['basedir'] ) . self::QUARANTINE_DIR;
	}

	/**
	 * Ensure the quarantine directory exists and is protected from direct
	 * HTTP access (deny-all .htaccess) and directory listing (index.php).
	 */
	public static function ensure_directory(): bool {
		$path = self::get_path();

		if ( ! is_dir( $path ) && ! wp_mkdir_p( $path ) ) {
			return false;
		}

		$htaccess = $path . '/.htaccess';
		if ( ! file_exists( $htaccess ) ) {
			file_put_contents( $htaccess, "# UX Studio Security Optimization - quarantine, deny all direct access.\n<IfModule mod_authz_core.c>\n\tRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n\tOrder deny,allow\n\tDeny from all\n</IfModule>\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_file_put_contents
		}

		$index = $path . '/index.php';
		if ( ! file_exists( $index ) ) {
			file_put_contents( $index, "<?php\n// Silence is golden.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_file_put_contents
		}

		return true;
	}

	/**
	 * Move a file into quarantine.
	 *
	 * @param string $file_path Absolute path of the file to quarantine.
	 * @param string $token     Unique token used in the quarantined filename (e.g. finding id).
	 * @return array{success:bool,quarantine_path:string,original_path:string,error:string}
	 */
	public static function quarantine_file( string $file_path, string $token ): array {
		$result = array(
			'success'         => false,
			'quarantine_path' => '',
			'original_path'   => $file_path,
			'error'           => '',
		);

		if ( ! file_exists( $file_path ) ) {
			$result['error'] = 'Source file does not exist.';
			return $result;
		}

		if ( ! self::ensure_directory() ) {
			$result['error'] = 'Failed to create quarantine directory.';
			return $result;
		}

		$dest_name = sanitize_file_name( $token . '_' . basename( $file_path ) ) . '.quarantined';
		$dest_path = self::get_path() . '/' . $dest_name;

		if ( rename( $file_path, $dest_path ) ) {
			$result['success']         = true;
			$result['quarantine_path'] = $dest_path;
		} else {
			$result['error'] = 'Failed to move file to quarantine.';
		}

		return $result;
	}

	/**
	 * Restore a quarantined file back to its original location. Refuses to
	 * overwrite an existing file at the destination.
	 */
	public static function restore_file( string $quarantine_path, string $original_path ): bool {
		if ( ! self::is_within_quarantine( $quarantine_path ) || ! file_exists( $quarantine_path ) ) {
			return false;
		}
		if ( file_exists( $original_path ) ) {
			return false;
		}

		$dir = dirname( $original_path );
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}

		return rename( $quarantine_path, $original_path );
	}

	/**
	 * Permanently delete a quarantined file. Verifies the path is actually
	 * inside the quarantine directory to prevent path-traversal deletion.
	 */
	public static function delete_quarantined( string $quarantine_path ): bool {
		if ( ! file_exists( $quarantine_path ) ) {
			return true; // Already gone.
		}
		if ( ! self::is_within_quarantine( $quarantine_path ) ) {
			return false;
		}
		return unlink( $quarantine_path );
	}

	/**
	 * Verify a path resolves to somewhere inside the quarantine directory.
	 */
	private static function is_within_quarantine( string $path ): bool {
		$real_dir  = realpath( self::get_path() );
		$real_path = realpath( $path );
		if ( false === $real_dir || false === $real_path ) {
			return false;
		}
		return 0 === strpos( $real_path, $real_dir );
	}
}
