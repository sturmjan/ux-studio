<?php
/**
 * Private (outside-webroot) storage for form file-field uploads.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\Forms;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Uploaded files never land in wp-content/uploads (public by default).
 * They're stored under wp-content/uxstudio-forms-private/, guarded by a
 * deny-all .htaccess + index.php, and only ever leave that directory through
 * Module::download_file() (capability-gated REST route, ownership-checked).
 */
final class FileStorage {

	/** Extension => allowed real MIME types (checked via finfo, not just the extension). */
	public const ALLOWED_TYPES = array(
		'pdf'  => array( 'application/pdf' ),
		'doc'  => array( 'application/msword' ),
		'docx' => array(
			'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
			'application/zip',
		),
		'xls'  => array( 'application/vnd.ms-excel' ),
		'xlsx' => array(
			'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
			'application/zip',
		),
		'jpg'  => array( 'image/jpeg' ),
		'jpeg' => array( 'image/jpeg' ),
		'png'  => array( 'image/png' ),
		'gif'  => array( 'image/gif' ),
		'webp' => array( 'image/webp' ),
		'txt'  => array( 'text/plain' ),
		'zip'  => array( 'application/zip' ),
	);

	public const DEFAULT_MAX_MB = 10;

	/**
	 * Private storage directory, created (with its .htaccess/index.php guard
	 * files) on first use.
	 */
	public static function private_dir(): string {
		$dir = WP_CONTENT_DIR . '/uxstudio-forms-private';

		if ( ! file_exists( $dir ) ) {
			wp_mkdir_p( $dir );
		}

		$htaccess = $dir . '/.htaccess';
		if ( ! file_exists( $htaccess ) ) {
			file_put_contents( // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
				$htaccess,
				"# Deny all direct web access to form upload files.\n"
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
	 * Validate + move an uploaded file ($_FILES entry) into private storage.
	 *
	 * @param array    $file        One $_FILES / get_file_params() entry.
	 * @param string[] $allowed_ext Extensions allowed for this field.
	 * @param int      $max_mb      Max size in MB for this field.
	 * @return array{stored_name:string,original_name:string,mime:string,size:int}|WP_Error
	 */
	public static function store( array $file, array $allowed_ext, int $max_mb ) {
		if ( empty( $file['tmp_name'] ) || ! is_uploaded_file( $file['tmp_name'] ) ) {
			return new WP_Error( 'uxstudio_forms_upload_invalid', __( 'Invalid file upload.', 'ux-studio' ) );
		}
		if ( ! empty( $file['error'] ) && UPLOAD_ERR_OK !== (int) $file['error'] ) {
			return new WP_Error( 'uxstudio_forms_upload_error', __( 'The file upload failed.', 'ux-studio' ) );
		}

		$max_bytes = max( 1, $max_mb ) * MB_IN_BYTES;
		$size      = (int) $file['size'];
		if ( $size <= 0 || $size > $max_bytes ) {
			return new WP_Error(
				'uxstudio_forms_upload_too_large',
				sprintf(
					/* translators: %d: max size in MB. */
					__( 'The file is too large (max %d MB).', 'ux-studio' ),
					$max_mb
				)
			);
		}

		$allowed_ext = array_values( array_intersect( $allowed_ext, array_keys( self::ALLOWED_TYPES ) ) );
		if ( empty( $allowed_ext ) ) {
			$allowed_ext = array_keys( self::ALLOWED_TYPES );
		}

		$checked = wp_check_filetype( (string) $file['name'] );
		$ext     = strtolower( (string) $checked['ext'] );
		if ( '' === $ext || ! in_array( $ext, $allowed_ext, true ) ) {
			return new WP_Error( 'uxstudio_forms_upload_type', __( 'This file type is not allowed.', 'ux-studio' ) );
		}

		$real_mime = '';
		if ( function_exists( 'finfo_open' ) ) {
			$finfo = finfo_open( FILEINFO_MIME_TYPE );
			if ( $finfo ) {
				$real_mime = (string) finfo_file( $finfo, $file['tmp_name'] );
				finfo_close( $finfo );
			}
		}
		if ( '' === $real_mime || ! in_array( $real_mime, self::ALLOWED_TYPES[ $ext ], true ) ) {
			return new WP_Error( 'uxstudio_forms_upload_type', __( 'The file content does not match an allowed type.', 'ux-studio' ) );
		}
		if ( in_array( $ext, array( 'jpg', 'jpeg', 'png', 'gif', 'webp' ), true ) ) {
			$dims = @getimagesize( $file['tmp_name'] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			if ( false === $dims ) {
				return new WP_Error( 'uxstudio_forms_upload_type', __( 'The file is not a valid image.', 'ux-studio' ) );
			}
		}

		$dir         = self::private_dir();
		$stored_name = wp_generate_password( 32, false, false ) . '.' . $ext;
		$dest        = $dir . '/' . $stored_name;

		if ( ! @move_uploaded_file( $file['tmp_name'], $dest ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
			return new WP_Error( 'uxstudio_forms_upload_failed', __( 'Could not store the uploaded file.', 'ux-studio' ) );
		}
		@chmod( $dest, 0640 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors

		return array(
			'stored_name'   => $stored_name,
			'original_name' => sanitize_file_name( (string) $file['name'] ),
			'mime'          => $real_mime,
			'size'          => $size,
		);
	}

	/**
	 * Delete a stored file by its random stored name (never trusts a path).
	 */
	public static function delete( string $stored_name ): void {
		$stored_name = basename( $stored_name );
		if ( '' === $stored_name ) {
			return;
		}
		$path = self::private_dir() . '/' . $stored_name;
		if ( file_exists( $path ) ) {
			@unlink( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}
	}

	/**
	 * Stream a stored file to the client and terminate the request. Callers
	 * MUST already have verified the requesting user is allowed to see it.
	 */
	public static function stream( string $stored_name, string $original_name, string $mime ): void {
		$stored_name = basename( $stored_name );
		$path        = self::private_dir() . '/' . $stored_name;
		if ( '' === $stored_name || ! file_exists( $path ) ) {
			status_header( 404 );
			exit;
		}
		nocache_headers();
		header( 'Content-Type: ' . $mime );
		header( 'Content-Disposition: attachment; filename="' . rawurlencode( $original_name ) . '"' );
		header( 'Content-Length: ' . filesize( $path ) );
		header( 'X-Content-Type-Options: nosniff' );
		readfile( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_readfile
		exit;
	}
}
