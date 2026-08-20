<?php
/**
 * Media Replace REST controller.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\MediaReplace;

use UxStudio\Rest\Controller;
use WP_Error;
use WP_REST_Request;

defined( 'ABSPATH' ) || exit;

/**
 * POST uxstudio/v1/media-replace - replace an attachment's file with a new one.
 */
final class RestController extends Controller {

	/**
	 * Register routes.
	 */
	public function register_routes(): void {
		$this->route(
			'/media-replace',
			'POST',
			array( $this, 'replace' ),
			array(
				'attachment_id'     => array(
					'required'          => true,
					'type'              => 'integer',
					'sanitize_callback' => 'absint',
					'validate_callback' => static fn ( $param ): bool => is_numeric( $param ) && (int) $param > 0,
				),
				'new_attachment_id' => array(
					'required'          => true,
					'type'              => 'integer',
					'sanitize_callback' => 'absint',
					'validate_callback' => static fn ( $param ): bool => is_numeric( $param ) && (int) $param > 0,
				),
			),
			'upload_files'
		);
	}

	/**
	 * Handle the replacement request.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function replace( WP_REST_Request $request ) {
		$attachment_id     = (int) $request->get_param( 'attachment_id' );
		$new_attachment_id = (int) $request->get_param( 'new_attachment_id' );

		// Per-item authorization: the user must be able to edit the target attachment.
		if ( ! current_user_can( 'edit_post', $attachment_id ) ) {
			return new WP_Error( 'uxstudio_forbidden', __( 'You are not allowed to edit this media item.', 'ux-studio' ), array( 'status' => 403 ) );
		}

		$attachment     = get_post( $attachment_id );
		$new_attachment = get_post( $new_attachment_id );

		if ( ! $this->validate_attachments( $attachment, $new_attachment ) ) {
			if ( $new_attachment ) {
				wp_delete_attachment( $new_attachment_id, true );
			}
			return new WP_Error( 'uxstudio_not_found', __( 'One or both media items could not be found.', 'ux-studio' ), array( 'status' => 404 ) );
		}

		// Validate the new file has a compatible MIME type (content-checked).
		$type = $this->validate_file_types( $attachment_id, $new_attachment_id );
		if ( ! $type['valid'] ) {
			wp_delete_attachment( $new_attachment_id, true );
			return new WP_Error( 'uxstudio_type_mismatch', $type['message'], array( 'status' => 400 ) );
		}

		$result = $this->perform_replacement( $attachment_id, $new_attachment_id );

		if ( ! $result['success'] ) {
			wp_delete_attachment( $new_attachment_id, true );
			return new WP_Error( 'uxstudio_replace_failed', $result['message'], array( 'status' => 500 ) );
		}

		return $this->ok( array( 'message' => $result['message'] ) );
	}

	/**
	 * Both posts exist and are attachments.
	 *
	 * @param \WP_Post|null $attachment     Original.
	 * @param \WP_Post|null $new_attachment Replacement.
	 */
	private function validate_attachments( $attachment, $new_attachment ): bool {
		return $attachment && $new_attachment
			&& 'attachment' === $attachment->post_type
			&& 'attachment' === $new_attachment->post_type;
	}

	/**
	 * Ensure the new file's real MIME type matches the original.
	 *
	 * @param int $attachment_id     Original attachment ID.
	 * @param int $new_attachment_id Replacement attachment ID.
	 * @return array{valid:bool,message?:string}
	 */
	private function validate_file_types( int $attachment_id, int $new_attachment_id ): array {
		$source_file = get_attached_file( $attachment_id );
		$new_file    = get_attached_file( $new_attachment_id );

		if ( ! $source_file || ! $new_file ) {
			return array(
				'valid'   => false,
				'message' => __( 'The file for one of the media items could not be found.', 'ux-studio' ),
			);
		}

		// Content-aware check (reads magic bytes where possible), not just extension.
		$source_check = wp_check_filetype_and_ext( $source_file, wp_basename( $source_file ) );
		$new_check    = wp_check_filetype_and_ext( $new_file, wp_basename( $new_file ) );

		$source_mime = $source_check['type'] ? $source_check['type'] : get_post_mime_type( $attachment_id );
		$new_mime    = $new_check['type'] ? $new_check['type'] : get_post_mime_type( $new_attachment_id );

		if ( ! $source_mime || ! $new_mime || $source_mime !== $new_mime ) {
			return array(
				'valid'   => false,
				'message' => sprintf(
					/* translators: 1: original file type, 2: new file type */
					__( 'File type mismatch. The original file is %1$s, but you uploaded %2$s. Please upload a file with the same type.', 'ux-studio' ),
					$source_mime ?: __( 'unknown', 'ux-studio' ),
					$new_mime ?: __( 'unknown', 'ux-studio' )
				),
			);
		}

		return array( 'valid' => true );
	}

	/**
	 * Copy the new file over the original, regenerate metadata, delete old sizes.
	 *
	 * @param int $source_id Original attachment ID.
	 * @param int $target_id Replacement attachment ID.
	 * @return array{success:bool,message:string}
	 */
	private function perform_replacement( int $source_id, int $target_id ): array {
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$source_file = get_attached_file( $source_id );
		$target_file = get_attached_file( $target_id );

		if ( ! $target_file || ! file_exists( $target_file ) ) {
			return array(
				'success' => false,
				'message' => __( 'The uploaded file does not exist.', 'ux-studio' ),
			);
		}

		$metadata = wp_get_attachment_metadata( $source_id );

		// Delete all old thumbnail files before replacing the main file.
		if ( is_array( $metadata ) ) {
			$this->delete_attachment_thumbnails( $source_id, $metadata );
		}

		if ( file_exists( $source_file ) ) {
			@unlink( $source_file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}

		if ( ! copy( $target_file, $source_file ) ) {
			return array(
				'success' => false,
				'message' => __( 'Failed to copy the new file to the destination.', 'ux-studio' ),
			);
		}

		$metadata = wp_generate_attachment_metadata( $source_id, $source_file );
		wp_update_attachment_metadata( $source_id, $metadata );

		// Remove the temporary replacement attachment.
		wp_delete_attachment( $target_id, true );

		return array(
			'success' => true,
			'message' => __( 'Media content has been successfully replaced.', 'ux-studio' ),
		);
	}

	/**
	 * Delete all sized thumbnail files for an attachment (within uploads only).
	 *
	 * @param int   $attachment_id Attachment ID.
	 * @param array $metadata      Attachment metadata.
	 */
	private function delete_attachment_thumbnails( int $attachment_id, array $metadata ): void {
		if ( empty( $metadata['sizes'] ) || ! is_array( $metadata['sizes'] ) ) {
			return;
		}

		$upload_dir    = wp_upload_dir();
		$base_dir      = (string) $upload_dir['basedir'];
		$attached_file = get_attached_file( $attachment_id );
		if ( ! $attached_file ) {
			return;
		}
		$file_dir = dirname( $attached_file );

		foreach ( $metadata['sizes'] as $size_data ) {
			if ( empty( $size_data['file'] ) ) {
				continue;
			}
			$path = $file_dir . '/' . $size_data['file'];
			// Only delete files that live inside the uploads directory.
			if ( 0 !== strpos( $path, $base_dir ) || ! file_exists( $path ) ) {
				continue;
			}
			@unlink( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
	}
}
