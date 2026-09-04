<?php
/**
 * Transfers local attachments to a remote node (hub side).
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\ContentSync;

defined( 'ABSPATH' ) || exit;

/**
 * Sideloads a local WP attachment into a node's media library by streaming the
 * raw file to the node's /media/upload endpoint via SyncClient. Returns the
 * remote attachment id so callers can wire it up (e.g. featured image).
 */
final class MediaTransfer {

	private SyncClient $client;

	/**
	 * @param SyncClient $client Signed client for the target node.
	 */
	public function __construct( SyncClient $client ) {
		$this->client = $client;
	}

	/**
	 * Transfer one local attachment to the node.
	 *
	 * @param int $attachment_id Local attachment id.
	 * @return array{success:bool, remote_id?:int, remote_url?:string, error?:string}
	 */
	public function transfer( int $attachment_id ): array {
		$path = get_attached_file( $attachment_id );
		if ( ! $path || ! file_exists( $path ) ) {
			return array(
				'success' => false,
				'error'   => __( 'Local media file not found.', 'ux-studio' ),
			);
		}

		$result = $this->client->upload_file( '/media/upload', $path );
		if ( empty( $result['success'] ) ) {
			return array(
				'success' => false,
				'error'   => (string) ( $result['error'] ?? __( 'Media upload failed.', 'ux-studio' ) ),
			);
		}

		return array(
			'success'    => true,
			'remote_id'  => (int) ( $result['data']['attachment_id'] ?? 0 ),
			'remote_url' => (string) ( $result['data']['url'] ?? '' ),
		);
	}
}
