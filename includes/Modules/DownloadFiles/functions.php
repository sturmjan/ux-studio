<?php
/**
 * Global template function kept for themes written against the legacy plugin.
 *
 * @package UxStudio
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'ux1_download_files' ) ) {
	/**
	 * Echo the downloadable files attached to a post.
	 *
	 * @param int|null $post_id Post ID, defaults to the current post.
	 */
	function ux1_download_files( ?int $post_id = null ): void {
		$module = \UxStudio\Modules\DownloadFiles\Module::instance();
		if ( null === $module ) {
			return;
		}
		$post_id = $post_id ?? (int) get_the_ID();
		if ( $post_id > 0 ) {
			echo $module->render_for_post( $post_id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in render_list().
		}
	}
}
