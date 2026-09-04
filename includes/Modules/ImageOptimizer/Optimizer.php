<?php
/**
 * Image Optimizer core processing logic.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\ImageOptimizer;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Compresses/resizes a media library image in place and optionally emits
 * next-gen siblings (WebP and/or AVIF), using whichever of Imagick/GD is
 * available. Operates only on files resolved via get_attached_file() and
 * explicitly re-verified to sit inside wp_upload_dir()['basedir'] before any
 * filesystem write - never on arbitrary paths or external URLs. The very first
 * time an attachment is touched, the untouched original is copied to
 * "<name>-original.<ext>" in the same directory so optimize/restore is always
 * reversible. AVIF encoding is feature-detected and silently degrades to a
 * no-op (webp/avif_done = false) when the runtime cannot encode it.
 */
final class Optimizer {

	public const META_ORIGINAL_SIZE  = '_uxstudio_io_original_size';
	public const META_OPTIMIZED_SIZE = '_uxstudio_io_optimized_size';
	public const META_WEBP_DONE      = '_uxstudio_io_webp_done';
	public const META_AVIF_DONE      = '_uxstudio_io_avif_done';

	private const SUPPORTED_MIME = array( 'image/jpeg', 'image/png', 'image/gif' );

	/**
	 * Whether the runtime can encode WebP (GD imagewebp or Imagick WEBP).
	 */
	public static function supports_webp(): bool {
		if ( function_exists( 'imagewebp' ) ) {
			return true;
		}
		return self::imagick_supports( 'WEBP' );
	}

	/**
	 * Whether the runtime can encode AVIF (GD imageavif >= PHP 8.1 built with
	 * AVIF, or Imagick built against a libheif-enabled ImageMagick).
	 */
	public static function supports_avif(): bool {
		if ( function_exists( 'imageavif' ) ) {
			return true;
		}
		return self::imagick_supports( 'AVIF' );
	}

	/**
	 * Whether Imagick is loaded and advertises the given format.
	 *
	 * @param string $format Uppercase format name, e.g. 'AVIF'.
	 */
	private static function imagick_supports( string $format ): bool {
		if ( ! extension_loaded( 'imagick' ) || ! class_exists( '\Imagick' ) ) {
			return false;
		}
		try {
			return ! empty( \Imagick::queryFormats( $format ) );
		} catch ( \Throwable $e ) {
			return false;
		}
	}

	/**
	 * Optimize a single attachment: resize to max_width, recompress at
	 * quality, optionally emit WebP and/or AVIF siblings. Idempotent-ish: a
	 * backup is only created once (on first run), so running it again
	 * re-compresses the already-optimized file, not the backup.
	 *
	 * @param int  $attachment_id Attachment post id.
	 * @param int  $quality       JPEG/WebP/AVIF quality 1-100.
	 * @param bool $convert_webp  Whether to also emit a .webp sibling.
	 * @param int  $max_width     Max width in pixels; 0 disables resizing.
	 * @param bool $convert_avif  Whether to also emit an .avif sibling.
	 * @return array|WP_Error {attachment_id, original_size, optimized_size, webp_done, avif_done}
	 */
	public function optimize( int $attachment_id, int $quality, bool $convert_webp, int $max_width, bool $convert_avif = false ) {
		if ( 'attachment' !== get_post_type( $attachment_id ) ) {
			return new WP_Error( 'uxstudio_not_attachment', __( 'Not a media library attachment.', 'ux-studio' ) );
		}

		$path = $this->resolve_safe_path( $attachment_id );
		if ( is_wp_error( $path ) ) {
			return $path;
		}

		$mime = (string) get_post_mime_type( $attachment_id );
		if ( ! in_array( $mime, self::SUPPORTED_MIME, true ) ) {
			return new WP_Error( 'uxstudio_unsupported_type', __( 'Only JPEG, PNG and GIF images are supported.', 'ux-studio' ) );
		}

		$quality   = max( 1, min( 100, $quality ) );
		$max_width = max( 0, $max_width );

		// AVIF is best-effort: only attempt when the runtime can actually encode it.
		$convert_avif = $convert_avif && self::supports_avif();
		$convert_webp = $convert_webp && self::supports_webp();

		$backup = $this->backup_path( $path );
		if ( ! is_file( $backup ) ) {
			if ( ! @copy( $path, $backup ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				return new WP_Error( 'uxstudio_backup_failed', __( 'Could not create a backup of the original image.', 'ux-studio' ) );
			}
		}

		$original_size = (int) filesize( $backup );

		$result = extension_loaded( 'imagick' )
			? $this->process_with_imagick( $path, $mime, $quality, $max_width, $convert_webp, $convert_avif )
			: ( extension_loaded( 'gd' )
				? $this->process_with_gd( $path, $mime, $quality, $max_width, $convert_webp, $convert_avif )
				: new WP_Error( 'uxstudio_no_image_library', __( 'Neither the Imagick nor the GD PHP extension is available.', 'ux-studio' ) ) );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		clearstatcache( true, $path );
		$optimized_size = (int) filesize( $path );

		update_post_meta( $attachment_id, self::META_ORIGINAL_SIZE, $original_size );
		update_post_meta( $attachment_id, self::META_OPTIMIZED_SIZE, $optimized_size );
		update_post_meta( $attachment_id, self::META_WEBP_DONE, $result['webp_done'] ? 1 : 0 );
		update_post_meta( $attachment_id, self::META_AVIF_DONE, $result['avif_done'] ? 1 : 0 );

		return array(
			'attachment_id'  => $attachment_id,
			'original_size'  => $original_size,
			'optimized_size' => $optimized_size,
			'webp_done'      => $result['webp_done'],
			'avif_done'      => $result['avif_done'],
		);
	}

	/**
	 * Restore the original from its backup and drop the optimization meta and
	 * any generated next-gen siblings.
	 *
	 * @param int $attachment_id Attachment post id.
	 */
	public function restore( int $attachment_id ) {
		if ( 'attachment' !== get_post_type( $attachment_id ) ) {
			return new WP_Error( 'uxstudio_not_attachment', __( 'Not a media library attachment.', 'ux-studio' ) );
		}

		$path = $this->resolve_safe_path( $attachment_id );
		if ( is_wp_error( $path ) ) {
			return $path;
		}

		$backup = $this->backup_path( $path );
		if ( ! is_file( $backup ) ) {
			return new WP_Error( 'uxstudio_no_backup', __( 'No backup exists for this image.', 'ux-studio' ) );
		}

		if ( ! @copy( $backup, $path ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			return new WP_Error( 'uxstudio_restore_failed', __( 'Could not restore the original image.', 'ux-studio' ) );
		}

		foreach ( array( $this->webp_path( $path ), $this->avif_path( $path ) ) as $sibling ) {
			if ( is_file( $sibling ) ) {
				@unlink( $sibling ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			}
		}

		delete_post_meta( $attachment_id, self::META_OPTIMIZED_SIZE );
		delete_post_meta( $attachment_id, self::META_WEBP_DONE );
		delete_post_meta( $attachment_id, self::META_AVIF_DONE );

		return array( 'attachment_id' => $attachment_id, 'restored' => true );
	}

	/**
	 * Resolve get_attached_file() and defense-in-depth verify it stays inside
	 * wp_upload_dir()['basedir'].
	 *
	 * @param int $attachment_id Attachment post id.
	 * @return string|WP_Error
	 */
	private function resolve_safe_path( int $attachment_id ) {
		$path = get_attached_file( $attachment_id );
		if ( ! $path || ! is_file( $path ) ) {
			return new WP_Error( 'uxstudio_missing_file', __( 'The file is missing on disk.', 'ux-studio' ) );
		}

		$real_path = realpath( $path );
		$real_base = realpath( wp_upload_dir()['basedir'] );
		if ( false === $real_path || false === $real_base || 0 !== strpos( $real_path, $real_base ) ) {
			return new WP_Error( 'uxstudio_path_traversal', __( 'Refused to operate on a file outside the uploads directory.', 'ux-studio' ) );
		}

		return $real_path;
	}

	/**
	 * @param string $path Absolute file path.
	 */
	private function backup_path( string $path ): string {
		$info = pathinfo( $path );
		return $info['dirname'] . '/' . $info['filename'] . '-original.' . $info['extension'];
	}

	/**
	 * @param string $path Absolute file path.
	 */
	private function webp_path( string $path ): string {
		$info = pathinfo( $path );
		return $info['dirname'] . '/' . $info['filename'] . '.webp';
	}

	/**
	 * @param string $path Absolute file path.
	 */
	private function avif_path( string $path ): string {
		$info = pathinfo( $path );
		return $info['dirname'] . '/' . $info['filename'] . '.avif';
	}

	/**
	 * @param string $path         Absolute file path (overwritten in place).
	 * @param string $mime         Mime type.
	 * @param int    $quality      1-100.
	 * @param int    $max_width    0 disables resize.
	 * @param bool   $convert_webp Whether to emit a .webp sibling.
	 * @param bool   $convert_avif Whether to emit an .avif sibling.
	 * @return array{webp_done:bool,avif_done:bool}|WP_Error
	 */
	private function process_with_gd( string $path, string $mime, int $quality, int $max_width, bool $convert_webp, bool $convert_avif ) {
		$image = $this->gd_load( $path, $mime );
		if ( ! $image ) {
			return new WP_Error( 'uxstudio_decode_failed', __( 'Could not decode the image.', 'ux-studio' ) );
		}

		$width  = imagesx( $image );
		$height = imagesy( $image );

		if ( $max_width > 0 && $width > $max_width ) {
			$new_width  = $max_width;
			$new_height = (int) round( $height * ( $max_width / $width ) );
			$resized    = imagecreatetruecolor( $new_width, $new_height );
			imagealphablending( $resized, false );
			imagesavealpha( $resized, true );
			imagecopyresampled( $resized, $image, 0, 0, 0, 0, $new_width, $new_height, $width, $height );
			imagedestroy( $image );
			$image = $resized;
		}

		switch ( $mime ) {
			case 'image/jpeg':
				imagejpeg( $image, $path, $quality );
				break;
			case 'image/png':
				// GD PNG quality is 0 (none) - 9 (max compression); map 1-100 inversely.
				imagepng( $image, $path, (int) round( ( 100 - $quality ) / 100 * 9 ) );
				break;
			case 'image/gif':
				imagegif( $image, $path );
				break;
		}

		$webp_done = false;
		if ( $convert_webp && function_exists( 'imagewebp' ) ) {
			$webp_done = (bool) @imagewebp( $image, $this->webp_path( $path ), $quality ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}

		$avif_done = false;
		if ( $convert_avif && function_exists( 'imageavif' ) ) {
			// imageavif( image, file, quality, speed ). speed -1 = auto/default.
			$avif_done = (bool) @imageavif( $image, $this->avif_path( $path ), $quality ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}

		imagedestroy( $image );

		return array( 'webp_done' => $webp_done, 'avif_done' => $avif_done );
	}

	/**
	 * @param string $path Absolute file path.
	 * @param string $mime Mime type.
	 * @return \GdImage|resource|false
	 */
	private function gd_load( string $path, string $mime ) {
		switch ( $mime ) {
			case 'image/jpeg':
				return @imagecreatefromjpeg( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			case 'image/png':
				$img = @imagecreatefrompng( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				if ( $img ) {
					imagepalettetotruecolor( $img );
					imagealphablending( $img, false );
					imagesavealpha( $img, true );
				}
				return $img;
			case 'image/gif':
				return @imagecreatefromgif( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			default:
				return false;
		}
	}

	/**
	 * @param string $path         Absolute file path (overwritten in place).
	 * @param string $mime         Mime type.
	 * @param int    $quality      1-100.
	 * @param int    $max_width    0 disables resize.
	 * @param bool   $convert_webp Whether to emit a .webp sibling.
	 * @param bool   $convert_avif Whether to emit an .avif sibling.
	 * @return array{webp_done:bool,avif_done:bool}|WP_Error
	 */
	private function process_with_imagick( string $path, string $mime, int $quality, int $max_width, bool $convert_webp, bool $convert_avif ) {
		try {
			$imagick = new \Imagick( $path );
			$imagick->setImageCompressionQuality( $quality );

			if ( $max_width > 0 && $imagick->getImageWidth() > $max_width ) {
				$imagick->resizeImage( $max_width, 0, \Imagick::FILTER_LANCZOS, 1, false );
			}

			$imagick->stripImage();
			$imagick->writeImage( $path );

			$webp_done = false;
			if ( $convert_webp && self::imagick_supports( 'WEBP' ) ) {
				$webp_done = $this->imagick_write_variant( $imagick, 'webp', $quality, $this->webp_path( $path ) );
			}

			$avif_done = false;
			if ( $convert_avif && self::imagick_supports( 'AVIF' ) ) {
				$avif_done = $this->imagick_write_variant( $imagick, 'avif', $quality, $this->avif_path( $path ) );
			}

			$imagick->destroy();

			return array( 'webp_done' => $webp_done, 'avif_done' => $avif_done );
		} catch ( \Throwable $e ) {
			return new WP_Error( 'uxstudio_imagick_failed', $e->getMessage() );
		}
	}

	/**
	 * Clone an Imagick instance and write it out in a next-gen format.
	 *
	 * @param \Imagick $source  Source instance (not modified).
	 * @param string   $format  Target format, e.g. 'webp'.
	 * @param int      $quality 1-100.
	 * @param string   $target  Absolute output path.
	 */
	private function imagick_write_variant( \Imagick $source, string $format, int $quality, string $target ): bool {
		try {
			$variant = clone $source;
			$variant->setImageFormat( $format );
			$variant->setImageCompressionQuality( $quality );
			$variant->writeImage( $target );
			$variant->destroy();
			return true;
		} catch ( \Throwable $e ) {
			return false;
		}
	}
}
