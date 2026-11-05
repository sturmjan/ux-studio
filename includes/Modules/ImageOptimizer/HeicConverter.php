<?php
/**
 * Converts HEIC/HEIF uploads (iPhone photos) to JPEG while they are uploaded.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\ImageOptimizer;

defined( 'ABSPATH' ) || exit;

/**
 * Hooked on wp_handle_upload (the file is already in uploads, the media library
 * has not seen it yet). Needs an Imagick that can read HEIC; otherwise the
 * upload is left exactly as it is. The converted file is written next to the
 * upload under a unique name and the HEIC file is removed, so the media library
 * only ever knows the JPEG.
 */
final class HeicConverter {

	private int $quality;

	/**
	 * @param int $quality JPEG quality 1-100.
	 */
	public function __construct( int $quality ) {
		$this->quality = max( 1, min( 100, $quality ) );
	}

	/**
	 * Filter wp_handle_upload.
	 *
	 * @param mixed  $upload  {file, url, type} or {error}.
	 * @param string $context 'upload' or 'sideload'.
	 * @return mixed
	 */
	public function convert( $upload, $context = 'upload' ) {
		if ( ! is_array( $upload ) || ! empty( $upload['error'] ) || empty( $upload['file'] ) || empty( $upload['url'] ) ) {
			return $upload;
		}

		$file = (string) $upload['file'];
		if ( ! in_array( strtolower( pathinfo( $file, PATHINFO_EXTENSION ) ), array( 'heic', 'heif' ), true ) || ! Optimizer::supports_heic() || ! is_file( $file ) ) {
			return $upload;
		}

		$real = realpath( $file );
		$base = realpath( wp_upload_dir()['basedir'] );
		if ( false === $real || false === $base || 0 !== strpos( $real, $base ) ) {
			return $upload;
		}

		$dir  = dirname( $real );
		$name = wp_unique_filename( $dir, pathinfo( $real, PATHINFO_FILENAME ) . '.jpg' );
		$new  = $dir . '/' . $name;

		try {
			$imagick = new \Imagick( $real );
			if ( method_exists( $imagick, 'autoOrient' ) ) {
				$imagick->autoOrient();
			}
			$imagick->setImageFormat( 'jpeg' );
			$imagick->setImageCompressionQuality( $this->quality );
			$imagick->stripImage();
			$imagick->writeImage( $new );
			$imagick->destroy();
		} catch ( \Throwable $e ) {
			if ( is_file( $new ) ) {
				@unlink( $new ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			}
			return $upload;
		}

		if ( ! is_file( $new ) || (int) filesize( $new ) < 1 ) {
			return $upload;
		}

		@unlink( $real ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

		$upload['file'] = $new;
		$upload['url']  = (string) preg_replace( '#[^/]+$#', '', (string) $upload['url'] ) . $name;
		$upload['type'] = 'image/jpeg';

		return $upload;
	}
}
