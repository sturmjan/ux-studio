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

	/** Relative uploads path of the PNG a JPEG was converted from (PNG -> JPG option). */
	public const META_CONVERTED_FROM = '_uxstudio_io_converted_from';

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
	 * @param array $opts         Optional: max_height (0 = unlimited), png_to_jpg (bool),
	 *                            jpeg_quality / webp_quality / avif_quality (1-100, default $quality).
	 * @return array|WP_Error {attachment_id, original_size, optimized_size, webp_done, avif_done, converted}
	 */
	public function optimize( int $attachment_id, int $quality, bool $convert_webp, int $max_width, bool $convert_avif = false, array $opts = array() ) {
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

		$quality    = max( 1, min( 100, $quality ) );
		$max_width  = max( 0, $max_width );
		$max_height = max( 0, (int) ( $opts['max_height'] ?? 0 ) );
		$qualities  = array(
			'jpeg' => $this->clamp_quality( $opts['jpeg_quality'] ?? $quality, $quality ),
			'webp' => $this->clamp_quality( $opts['webp_quality'] ?? $quality, $quality ),
			'avif' => $this->clamp_quality( $opts['avif_quality'] ?? $quality, $quality ),
		);

		// AVIF is best-effort: only attempt when the runtime can actually encode it.
		$convert_avif = $convert_avif && self::supports_avif();
		$convert_webp = $convert_webp && self::supports_webp();

		$backup = $this->backup_for( $attachment_id, $path );
		if ( ! is_file( $backup ) ) {
			if ( ! @copy( $path, $backup ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				return new WP_Error( 'uxstudio_backup_failed', __( 'Could not create a backup of the original image.', 'ux-studio' ) );
			}
		}

		$original_size = (int) filesize( $backup );

		// Optional PNG -> JPEG conversion (opaque PNGs only). The PNG stays as
		// the "-original" backup, so Restore can bring it back.
		$converted = false;
		if ( ! empty( $opts['png_to_jpg'] ) && 'image/png' === $mime ) {
			$jpeg = $this->convert_png_to_jpeg( $path, $qualities['jpeg'] );
			if ( null !== $jpeg ) {
				$old_meta = wp_get_attachment_metadata( $attachment_id );
				$old_rel  = (string) get_post_meta( $attachment_id, '_wp_attached_file', true );
				$this->delete_subsizes( $path, is_array( $old_meta ) ? $old_meta : array() );
				@unlink( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				update_attached_file( $attachment_id, $jpeg );
				wp_update_post(
					array(
						'ID'             => $attachment_id,
						'post_mime_type' => 'image/jpeg',
					)
				);
				update_post_meta( $attachment_id, self::META_CONVERTED_FROM, $old_rel );
				$path      = $jpeg;
				$mime      = 'image/jpeg';
				$converted = true;
			}
		}

		$result = extension_loaded( 'imagick' )
			? $this->process_with_imagick( $path, $mime, $qualities, $max_width, $max_height, $convert_webp, $convert_avif )
			: ( extension_loaded( 'gd' )
				? $this->process_with_gd( $path, $mime, $qualities, $max_width, $max_height, $convert_webp, $convert_avif )
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
		update_post_meta( $attachment_id, MediaLibrary::META_FILESIZE, $optimized_size );

		if ( $converted ) {
			// New file name / mime -> thumbnails and metadata must be rebuilt.
			// Meta above is set first, so the auto-optimize filter skips this attachment.
			require_once ABSPATH . 'wp-admin/includes/image.php';
			wp_update_attachment_metadata( $attachment_id, wp_generate_attachment_metadata( $attachment_id, $path ) );
		}

		return array(
			'attachment_id'  => $attachment_id,
			'original_size'  => $original_size,
			'optimized_size' => $optimized_size,
			'webp_done'      => $result['webp_done'],
			'avif_done'      => $result['avif_done'],
			'converted'      => $converted,
		);
	}

	/**
	 * Restore the original from its backup and drop the optimization meta and
	 * any generated next-gen siblings. Reverses a PNG -> JPEG conversion. Also
	 * understands backups made by the legacy UX1 plugin (uploads "originals/"
	 * folder), so images optimized before the migration can be restored too.
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

		$backup = $this->backup_for( $attachment_id, $path );
		if ( ! is_file( $backup ) ) {
			$backup = $this->legacy_backup( $path );
			if ( null === $backup ) {
				return new WP_Error( 'uxstudio_no_backup', __( 'No backup exists for this image.', 'ux-studio' ) );
			}
		}

		$base_dir  = (string) realpath( wp_upload_dir()['basedir'] );
		$target    = $path;
		$was_png   = false;
		$backup_ex = strtolower( pathinfo( $backup, PATHINFO_EXTENSION ) );
		$cur_ex    = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );

		// Reverse a PNG -> JPEG conversion: bring the PNG back under its own name.
		if ( 'png' === $backup_ex && in_array( $cur_ex, array( 'jpg', 'jpeg' ), true ) ) {
			$target = wp_normalize_path( (string) preg_replace( '/\.jpe?g$/i', '.png', $path ) );
			if ( '' === $target || 0 !== strpos( $target, wp_normalize_path( $base_dir ) ) ) {
				return new WP_Error( 'uxstudio_path_traversal', __( 'Refused to operate on a file outside the uploads directory.', 'ux-studio' ) );
			}
			$was_png = true;
		}

		if ( ! @copy( $backup, $target ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			return new WP_Error( 'uxstudio_restore_failed', __( 'Could not restore the original image.', 'ux-studio' ) );
		}

		foreach ( array( $this->webp_path( $path ), $this->avif_path( $path ) ) as $sibling ) {
			if ( is_file( $sibling ) ) {
				@unlink( $sibling ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			}
		}

		if ( $was_png ) {
			$old_meta = wp_get_attachment_metadata( $attachment_id );
			$this->delete_subsizes( $path, is_array( $old_meta ) ? $old_meta : array() );
			@unlink( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			update_attached_file( $attachment_id, $target );
			wp_update_post(
				array(
					'ID'             => $attachment_id,
					'post_mime_type' => 'image/png',
				)
			);
			require_once ABSPATH . 'wp-admin/includes/image.php';
			wp_update_attachment_metadata( $attachment_id, wp_generate_attachment_metadata( $attachment_id, $target ) );
		}

		delete_post_meta( $attachment_id, self::META_OPTIMIZED_SIZE );
		delete_post_meta( $attachment_id, self::META_WEBP_DONE );
		delete_post_meta( $attachment_id, self::META_AVIF_DONE );
		delete_post_meta( $attachment_id, self::META_CONVERTED_FROM );
		update_post_meta( $attachment_id, MediaLibrary::META_FILESIZE, (int) filesize( $target ) );

		return array( 'attachment_id' => $attachment_id, 'restored' => true );
	}

	/**
	 * Whether an attachment has a backup that restore() can use (own or legacy).
	 *
	 * @param int $attachment_id Attachment post id.
	 */
	public function has_backup( int $attachment_id ): bool {
		$path = get_attached_file( $attachment_id );
		if ( ! $path || ! is_file( $path ) ) {
			return false;
		}
		return is_file( $this->backup_for( $attachment_id, $path ) ) || null !== $this->legacy_backup( $path );
	}

	/**
	 * Backup file for an attachment: the "-original" copy next to the current
	 * file, or - after a PNG -> JPEG conversion - next to the original PNG name.
	 *
	 * @param int    $attachment_id Attachment post id.
	 * @param string $path          Current absolute file path.
	 */
	private function backup_for( int $attachment_id, string $path ): string {
		$from = (string) get_post_meta( $attachment_id, self::META_CONVERTED_FROM, true );
		if ( '' !== $from ) {
			$info = pathinfo( $from );
			return dirname( $path ) . '/' . $info['filename'] . '-original.' . ( $info['extension'] ?? 'png' );
		}

		return $this->backup_path( $path );
	}

	/**
	 * Backup written by the legacy UX1 optimizer: uploads/.../originals/<file>
	 * (a converted PNG is stored there with its .png name).
	 *
	 * @param string $path Current absolute file path.
	 * @return string|null Absolute backup path or null.
	 */
	private function legacy_backup( string $path ): ?string {
		$info      = pathinfo( $path );
		$candidate = $info['dirname'] . '/originals/' . $info['basename'];
		if ( is_file( $candidate ) ) {
			return $candidate;
		}

		if ( preg_match( '/\.jpe?g$/i', $path ) ) {
			$png = $info['dirname'] . '/originals/' . $info['filename'] . '.png';
			if ( is_file( $png ) ) {
				return $png;
			}
		}

		return null;
	}

	/**
	 * @param mixed $value    Raw quality.
	 * @param int   $fallback Used when $value is not a usable 1-100 number.
	 */
	private function clamp_quality( $value, int $fallback ): int {
		$value = (int) $value;
		return $value >= 1 ? min( 100, $value ) : $fallback;
	}

	/**
	 * Delete generated thumbnails (and their webp/avif siblings) listed in
	 * attachment metadata.
	 *
	 * @param string $path Main file path (directory anchor).
	 * @param array  $meta Attachment metadata.
	 */
	private function delete_subsizes( string $path, array $meta ): void {
		$dir = dirname( $path );
		foreach ( (array) ( $meta['sizes'] ?? array() ) as $size ) {
			if ( empty( $size['file'] ) ) {
				continue;
			}
			$file = $dir . '/' . wp_basename( (string) $size['file'] );
			foreach ( array( $file, $this->webp_path( $file ), $this->avif_path( $file ) ) as $target ) {
				if ( is_file( $target ) ) {
					@unlink( $target ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				}
			}
		}
	}

	/**
	 * Convert an opaque PNG to a JPEG next to it (same name, .jpg; a unique name
	 * if that is taken). Returns null when the PNG uses transparency, GD is
	 * missing or encoding fails - the caller then keeps the PNG.
	 *
	 * @param string $path    Absolute path of the PNG.
	 * @param int    $quality JPEG quality 1-100.
	 * @return string|null Absolute path of the new JPEG.
	 */
	private function convert_png_to_jpeg( string $path, int $quality ): ?string {
		if ( ! function_exists( 'imagecreatefrompng' ) || ! function_exists( 'imagejpeg' ) ) {
			return null;
		}

		$alpha_capable = $this->png_has_alpha_channel( $path );
		$image         = @imagecreatefrompng( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( ! $image ) {
			return null;
		}
		imagepalettetotruecolor( $image );

		$width  = imagesx( $image );
		$height = imagesy( $image );
		if ( $width < 1 || $height < 1 || ( $alpha_capable && $this->gd_has_transparency( $image, $width, $height ) ) ) {
			imagedestroy( $image );
			return null;
		}

		$canvas = imagecreatetruecolor( $width, $height );
		if ( ! $canvas ) {
			imagedestroy( $image );
			return null;
		}
		imagefill( $canvas, 0, 0, (int) imagecolorallocate( $canvas, 255, 255, 255 ) );
		imagecopy( $canvas, $image, 0, 0, 0, 0, $width, $height );
		imagedestroy( $image );

		// Same name with .jpg; only if that is taken by another file get a unique name.
		$dir    = wp_normalize_path( dirname( $path ) );
		$stem   = pathinfo( $path, PATHINFO_FILENAME );
		$target = $dir . '/' . $stem . '.jpg';
		if ( file_exists( $target ) ) {
			$target = $dir . '/' . wp_unique_filename( $dir, $stem . '.jpg' );
		}
		$ok = imagejpeg( $canvas, $target, $quality );
		imagedestroy( $canvas );

		if ( ! $ok || ! is_file( $target ) ) {
			return null;
		}

		return $target;
	}

	/**
	 * PNG colour type other than gray / RGB (palette may carry tRNS, 4 / 6 have
	 * an alpha channel) = may be transparent.
	 *
	 * @param string $path Absolute PNG path.
	 */
	private function png_has_alpha_channel( string $path ): bool {
		$handle = @fopen( $path, 'rb' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( ! $handle ) {
			return true;
		}
		$header = (string) fread( $handle, 26 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

		if ( strlen( $header ) < 26 || "\x89PNG\r\n\x1a\n" !== substr( $header, 0, 8 ) ) {
			return true;
		}

		$color_type = ord( $header[25] );

		return 0 !== $color_type && 2 !== $color_type;
	}

	/**
	 * Sample pixels for any non-opaque alpha.
	 *
	 * @param \GdImage|resource $image  GD image.
	 * @param int               $width  Width.
	 * @param int               $height Height.
	 */
	private function gd_has_transparency( $image, int $width, int $height ): bool {
		$step = max( 1, (int) ( min( $width, $height ) / 100 ) );
		for ( $x = 0; $x < $width; $x += $step ) {
			for ( $y = 0; $y < $height; $y += $step ) {
				if ( ( ( imagecolorat( $image, $x, $y ) >> 24 ) & 0x7F ) > 0 ) {
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * Target size when an image exceeds the max box (aspect ratio kept).
	 *
	 * @param int $width      Current width.
	 * @param int $height     Current height.
	 * @param int $max_width  0 = unlimited.
	 * @param int $max_height 0 = unlimited.
	 * @return array{0:int,1:int}|null Null when no resize is needed.
	 */
	private function fit_dimensions( int $width, int $height, int $max_width, int $max_height ): ?array {
		$ratio = 1.0;
		if ( $max_width > 0 && $width > $max_width ) {
			$ratio = min( $ratio, $max_width / $width );
		}
		if ( $max_height > 0 && $height > $max_height ) {
			$ratio = min( $ratio, $max_height / $height );
		}
		if ( $ratio >= 1.0 ) {
			return null;
		}

		return array( max( 1, (int) round( $width * $ratio ) ), max( 1, (int) round( $height * $ratio ) ) );
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
	 * @param array{jpeg:int,webp:int,avif:int} $quality Per-format quality 1-100.
	 * @param int    $max_width    0 = no width limit.
	 * @param int    $max_height   0 = no height limit.
	 * @param bool   $convert_webp Whether to emit a .webp sibling.
	 * @param bool   $convert_avif Whether to emit an .avif sibling.
	 * @return array{webp_done:bool,avif_done:bool}|WP_Error
	 */
	private function process_with_gd( string $path, string $mime, array $quality, int $max_width, int $max_height, bool $convert_webp, bool $convert_avif ) {
		$image = $this->gd_load( $path, $mime );
		if ( ! $image ) {
			return new WP_Error( 'uxstudio_decode_failed', __( 'Could not decode the image.', 'ux-studio' ) );
		}

		$width  = imagesx( $image );
		$height = imagesy( $image );

		$fit = $this->fit_dimensions( $width, $height, $max_width, $max_height );
		if ( null !== $fit ) {
			list( $new_width, $new_height ) = $fit;
			$resized = imagecreatetruecolor( $new_width, $new_height );
			imagealphablending( $resized, false );
			imagesavealpha( $resized, true );
			imagecopyresampled( $resized, $image, 0, 0, 0, 0, $new_width, $new_height, $width, $height );
			imagedestroy( $image );
			$image = $resized;
		}

		switch ( $mime ) {
			case 'image/jpeg':
				imagejpeg( $image, $path, $quality['jpeg'] );
				break;
			case 'image/png':
				// GD PNG quality is 0 (none) - 9 (max compression); map 1-100 inversely.
				imagepng( $image, $path, (int) round( ( 100 - $quality['jpeg'] ) / 100 * 9 ) );
				break;
			case 'image/gif':
				imagegif( $image, $path );
				break;
		}

		$webp_done = false;
		if ( $convert_webp && function_exists( 'imagewebp' ) ) {
			$webp_done = (bool) @imagewebp( $image, $this->webp_path( $path ), $quality['webp'] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}

		$avif_done = false;
		if ( $convert_avif && function_exists( 'imageavif' ) ) {
			// imageavif( image, file, quality, speed ). speed -1 = auto/default.
			$avif_done = (bool) @imageavif( $image, $this->avif_path( $path ), $quality['avif'] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
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
	 * @param array{jpeg:int,webp:int,avif:int} $quality Per-format quality 1-100.
	 * @param int    $max_width    0 = no width limit.
	 * @param int    $max_height   0 = no height limit.
	 * @param bool   $convert_webp Whether to emit a .webp sibling.
	 * @param bool   $convert_avif Whether to emit an .avif sibling.
	 * @return array{webp_done:bool,avif_done:bool}|WP_Error
	 */
	private function process_with_imagick( string $path, string $mime, array $quality, int $max_width, int $max_height, bool $convert_webp, bool $convert_avif ) {
		try {
			$imagick = new \Imagick( $path );
			$imagick->setImageCompressionQuality( $quality['jpeg'] );

			$fit = $this->fit_dimensions( $imagick->getImageWidth(), $imagick->getImageHeight(), $max_width, $max_height );
			if ( null !== $fit ) {
				$imagick->resizeImage( $fit[0], $fit[1], \Imagick::FILTER_LANCZOS, 1, false );
			}

			$imagick->stripImage();
			$imagick->writeImage( $path );

			$webp_done = false;
			if ( $convert_webp && self::imagick_supports( 'WEBP' ) ) {
				$webp_done = $this->imagick_write_variant( $imagick, 'webp', $quality['webp'], $this->webp_path( $path ) );
			}

			$avif_done = false;
			if ( $convert_avif && self::imagick_supports( 'AVIF' ) ) {
				$avif_done = $this->imagick_write_variant( $imagick, 'avif', $quality['avif'], $this->avif_path( $path ) );
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
