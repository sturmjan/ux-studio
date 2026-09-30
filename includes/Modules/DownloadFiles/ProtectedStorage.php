<?php
/**
 * Private storage for login-only download files.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\DownloadFiles;

defined( 'ABSPATH' ) || exit;

/**
 * A "require login" download is only protected if its file is NOT reachable
 * at its public uploads URL. So while at least one download entry with
 * require_login references an attachment, the attachment's file (and its
 * generated sizes) are moved out of wp-content/uploads into
 * wp-content/uxstudio-downloads-private/<attachment_id>-<random>/, guarded by
 * a deny-all .htaccess + index.php (same pattern as Forms\FileStorage). The
 * attachment's _wp_attached_file then holds that absolute path, so
 * get_attached_file() - and therefore the token endpoint - still finds it.
 * When no login-only entry references it any more, the file is moved back to
 * its original uploads location.
 *
 * nginx ignores .htaccess: there the random directory name is the only
 * barrier unless the directory is denied in the server config (or moved
 * outside the web root via the `uxstudio_download_files_private_dir` filter).
 */
final class ProtectedStorage {

	/** Post meta holding the original uploads-relative _wp_attached_file. */
	public const META_ORIGINAL = '_uxstudio_df_original_file';

	/**
	 * Private storage root, created with its guard files on first use.
	 */
	public static function root(): string {
		/**
		 * Directory for login-only download files. Point it outside the web
		 * root on nginx (where .htaccess has no effect).
		 *
		 * @param string $dir Absolute path.
		 */
		$dir = untrailingslashit( (string) apply_filters( 'uxstudio_download_files_private_dir', WP_CONTENT_DIR . '/uxstudio-downloads-private' ) );

		if ( ! file_exists( $dir ) ) {
			wp_mkdir_p( $dir );
		}
		$htaccess = $dir . '/.htaccess';
		if ( ! file_exists( $htaccess ) ) {
			file_put_contents( // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
				$htaccess,
				"# Deny all direct web access to login-only download files.\n"
				. "Options -Indexes\n"
				. "<IfModule mod_authz_core.c>\n\tRequire all denied\n</IfModule>\n"
				. "<IfModule !mod_authz_core.c>\n\tOrder allow,deny\n\tDeny from all\n</IfModule>\n"
			);
		}
		$index = $dir . '/index.php';
		if ( ! file_exists( $index ) ) {
			file_put_contents( $index, "<?php\n// Silence is golden.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		}
		return $dir;
	}

	/**
	 * Whether an attachment's file currently lives in private storage.
	 *
	 * @param int $attachment_id Attachment id.
	 */
	public static function is_protected( int $attachment_id ): bool {
		return '' !== (string) get_post_meta( $attachment_id, self::META_ORIGINAL, true );
	}

	/**
	 * Whether a real path is inside the private storage root.
	 *
	 * @param string $real_path realpath() of a file.
	 */
	public static function contains( string $real_path ): bool {
		$base = realpath( self::root() );
		return false !== $base
			&& 0 === strpos( wp_normalize_path( $real_path ), trailingslashit( wp_normalize_path( $base ) ) );
	}

	/**
	 * Move the attachment's files into private storage (idempotent).
	 *
	 * @param int $attachment_id Attachment id.
	 */
	public static function protect( int $attachment_id ): bool {
		if ( self::is_protected( $attachment_id ) ) {
			return true;
		}
		$relative = (string) get_post_meta( $attachment_id, '_wp_attached_file', true );
		$source   = get_attached_file( $attachment_id, true );
		if ( '' === $relative || ! $source || ! is_file( $source ) ) {
			return false;
		}

		$dir = self::root() . '/' . $attachment_id . '-' . wp_generate_password( 12, false, false );
		if ( ! wp_mkdir_p( $dir ) ) {
			return false;
		}

		$files = self::files_of( $attachment_id, $source );
		foreach ( $files as $file ) {
			if ( ! @rename( $file, $dir . '/' . wp_basename( $file ) ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				// Roll back what was already moved; leave the attachment public.
				foreach ( $files as $moved ) {
					if ( is_file( $dir . '/' . wp_basename( $moved ) ) ) {
						@rename( $dir . '/' . wp_basename( $moved ), $moved ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
					}
				}
				return false;
			}
		}

		// wp_slash(): update_post_meta() unslashes, which would eat the
		// backslashes of a Windows absolute path.
		update_post_meta( $attachment_id, self::META_ORIGINAL, wp_slash( $relative ) );
		update_post_meta( $attachment_id, '_wp_attached_file', wp_slash( $dir . '/' . wp_basename( $source ) ) );
		return true;
	}

	/**
	 * Move the attachment's files back to their original uploads location.
	 *
	 * @param int $attachment_id Attachment id.
	 */
	public static function unprotect( int $attachment_id ): bool {
		$relative = (string) get_post_meta( $attachment_id, self::META_ORIGINAL, true );
		if ( '' === $relative ) {
			return true;
		}
		$current = (string) get_post_meta( $attachment_id, '_wp_attached_file', true );
		if ( '' === $current || ! is_file( $current ) ) {
			return false;
		}

		$target_dir = dirname( trailingslashit( wp_upload_dir()['basedir'] ) . $relative );
		if ( ! wp_mkdir_p( $target_dir ) ) {
			return false;
		}
		foreach ( self::files_of( $attachment_id, $current ) as $file ) {
			$dest = $target_dir . '/' . wp_basename( $file );
			if ( ! file_exists( $dest ) ) {
				@rename( $file, $dest ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			}
		}
		self::remove_dir_if_empty( dirname( $current ) );

		update_post_meta( $attachment_id, '_wp_attached_file', wp_slash( $relative ) );
		delete_post_meta( $attachment_id, self::META_ORIGINAL );
		return true;
	}

	/**
	 * Delete private copies when the attachment itself is deleted (WP only
	 * deletes files inside the uploads dir).
	 *
	 * @param int $attachment_id Attachment id.
	 */
	public static function delete_files( int $attachment_id ): void {
		if ( ! self::is_protected( $attachment_id ) ) {
			return;
		}
		$current = (string) get_post_meta( $attachment_id, '_wp_attached_file', true );
		$real    = '' !== $current ? realpath( $current ) : false;
		if ( false === $real || ! self::contains( $real ) ) {
			return;
		}
		foreach ( self::files_of( $attachment_id, $real ) as $file ) {
			wp_delete_file( $file );
		}
		self::remove_dir_if_empty( dirname( $real ) );
	}

	/**
	 * Main file + generated sizes (+ original_image) that sit next to it.
	 *
	 * @param int    $attachment_id Attachment id.
	 * @param string $main          Absolute path of the main file.
	 * @return string[]
	 */
	private static function files_of( int $attachment_id, string $main ): array {
		$dir   = dirname( $main );
		$files = array( $main );
		$meta  = wp_get_attachment_metadata( $attachment_id );
		if ( is_array( $meta ) ) {
			foreach ( (array) ( $meta['sizes'] ?? array() ) as $size ) {
				if ( ! empty( $size['file'] ) ) {
					$files[] = $dir . '/' . wp_basename( (string) $size['file'] );
				}
			}
			if ( ! empty( $meta['original_image'] ) ) {
				$files[] = $dir . '/' . wp_basename( (string) $meta['original_image'] );
			}
		}
		return array_values( array_unique( array_filter( $files, 'is_file' ) ) );
	}

	/**
	 * Remove a per-attachment private directory once it holds no files.
	 *
	 * @param string $dir Directory.
	 */
	private static function remove_dir_if_empty( string $dir ): void {
		$real = realpath( $dir );
		if ( false === $real || ! self::contains( $real . '/x' ) ) {
			return;
		}
		$entries = @scandir( $real ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( is_array( $entries ) && array() === array_diff( $entries, array( '.', '..' ) ) ) {
			@rmdir( $real ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
		}
	}
}
