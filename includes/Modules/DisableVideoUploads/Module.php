<?php
/**
 * Disable Video Uploads module - remove video mime types from uploads (ported from legacy module).
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\DisableVideoUploads;

use UxStudio\Modules\BaseModule;

defined( 'ABSPATH' ) || exit;

/**
 * Removes all common video mime types from the allowed upload types.
 */
final class Module extends BaseModule {

	/**
	 * Register hooks.
	 */
	public function boot(): void {
		add_filter( 'upload_mimes', array( $this, 'disable_video_uploads' ) );
	}

	/**
	 * Strip video mime types from the allowed uploads.
	 *
	 * @param array $mimes Allowed mime types keyed by extension pattern.
	 */
	public function disable_video_uploads( array $mimes ): array {
		$video_mimes = array(
			'asf'          => 'video/x-ms-asf',
			'asx'          => 'video/x-ms-asf',
			'wmv'          => 'video/x-ms-wmv',
			'wmx'          => 'video/x-ms-wmx',
			'wm'           => 'video/x-ms-wm',
			'avi'          => 'video/avi',
			'divx'         => 'video/divx',
			'flv'          => 'video/x-flv',
			'mov|qt'       => 'video/quicktime',
			'mpeg|mpg|mpe' => 'video/mpeg',
			'mp4|m4v'      => 'video/mp4',
			'ogv'          => 'video/ogg',
			'webm'         => 'video/webm',
			'mkv'          => 'video/x-matroska',
		);

		foreach ( array_keys( $video_mimes ) as $ext ) {
			unset( $mimes[ $ext ] );
		}

		return $mimes;
	}
}
