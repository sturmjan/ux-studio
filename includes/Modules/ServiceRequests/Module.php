<?php
/**
 * Service Requests module - internal requests for changes/work on the site,
 * with media-library-backed attachments.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\ServiceRequests;

use UxStudio\Core\ActivityLog;
use UxStudio\Modules\BaseModule;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Ported/redesigned from the legacy service-requests module as a group-C
 * module with its own SPA screen. Same attachment pattern as
 * UxStudio\Modules\DownloadFiles\Module: an attachment is picked from the
 * standard WP media library (wp.media) in the admin UI and only the
 * resulting attachment_id is ever persisted - there is no custom upload
 * handler and no filesystem path ever comes from client input, which
 * eliminates path traversal risk entirely.
 */
final class Module extends BaseModule {

	private const STATUSES = array( 'open', 'in_progress', 'done' );

	/**
	 * Register hooks.
	 */
	public function boot(): void {
		add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );

		\UxStudio\Core\DB::ensure_module_tables(
			'service-requests',
			1,
			function ( int $from ): void {
				global $wpdb;
				$charset = $wpdb->get_charset_collate();
				dbDelta(
					"CREATE TABLE {$wpdb->prefix}uxstudio_service_requests (
						id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
						created_at DATETIME NOT NULL,
						title VARCHAR(255) NOT NULL DEFAULT '',
						description LONGTEXT NULL,
						status VARCHAR(20) NOT NULL DEFAULT 'open',
						requester_email VARCHAR(255) NOT NULL DEFAULT '',
						attachment_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
						PRIMARY KEY  (id),
						KEY status (status)
					) {$charset};"
				);
			}
		);
	}

	/**
	 * Register the module REST controller.
	 */
	public function register_rest_routes(): void {
		( new RestController( $this ) )->register_routes();
	}

	/**
	 * REST controller class.
	 */
	public function rest_controller(): ?string {
		return RestController::class;
	}

	/**
	 * Settings schema for the generic renderer / embedded Settings tab.
	 */
	public function settings_schema(): array {
		return array(
			array(
				'key'     => 'delivery_email',
				'type'    => 'text',
				'label'   => __( 'Notification email', 'ux-studio' ),
				'help'    => __( 'Where notifications about new service requests are sent. Defaults to the site admin email if left blank.', 'ux-studio' ),
				'default' => '',
			),
		);
	}

	/**
	 * All requests, newest first, optionally filtered by status.
	 *
	 * @param string $status Optional status filter.
	 * @return array<int, array<string, mixed>>
	 */
	public function list_items( string $status = '' ): array {
		global $wpdb;

		if ( '' !== $status && in_array( $status, self::STATUSES, true ) ) {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT id, created_at, title, description, status, requester_email, attachment_id FROM {$wpdb->prefix}uxstudio_service_requests WHERE status = %s ORDER BY id DESC",
					$status
				),
				ARRAY_A
			);
		} else {
			$rows = $wpdb->get_results(
				"SELECT id, created_at, title, description, status, requester_email, attachment_id FROM {$wpdb->prefix}uxstudio_service_requests ORDER BY id DESC",
				ARRAY_A
			);
		}

		$rows = is_array( $rows ) ? $rows : array();
		return array_map( array( $this, 'format_row' ), $rows );
	}

	/**
	 * One request by id.
	 *
	 * @param int $id Row id.
	 */
	public function get_item( int $id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, created_at, title, description, status, requester_email, attachment_id FROM {$wpdb->prefix}uxstudio_service_requests WHERE id = %d",
				$id
			),
			ARRAY_A
		);
		return is_array( $row ) ? $this->format_row( $row ) : null;
	}

	/**
	 * Create a new service request and notify the delivery email.
	 *
	 * @param array $data { title:string, description?:string, requester_email?:string, attachment_id?:int }.
	 * @return array<string, mixed>
	 */
	public function create_item( array $data ): array {
		global $wpdb;

		$attachment_id = absint( $data['attachment_id'] ?? 0 );
		if ( $attachment_id > 0 && 'attachment' !== get_post_type( $attachment_id ) ) {
			$attachment_id = 0;
		}

		$wpdb->insert(
			"{$wpdb->prefix}uxstudio_service_requests",
			array(
				'created_at'      => current_time( 'mysql' ),
				'title'           => mb_substr( (string) $data['title'], 0, 255 ),
				'description'     => isset( $data['description'] ) ? sanitize_textarea_field( (string) $data['description'] ) : null,
				'status'          => 'open',
				'requester_email' => isset( $data['requester_email'] ) ? sanitize_email( (string) $data['requester_email'] ) : '',
				'attachment_id'   => $attachment_id,
			),
			array( '%s', '%s', '%s', '%s', '%s', '%d' )
		);

		$id = (int) $wpdb->insert_id;

		ActivityLog::log( 'service-requests', 'create', 'service_request', $id );

		$item = (array) $this->get_item( $id );
		$this->notify_new_request( $item );

		return $item;
	}

	/**
	 * Update a request's status.
	 *
	 * @param int    $id     Row id.
	 * @param string $status One of open|in_progress|done.
	 * @return array<string, mixed>|WP_Error
	 */
	public function update_status( int $id, string $status ) {
		if ( ! in_array( $status, self::STATUSES, true ) ) {
			return new WP_Error( 'uxstudio_invalid_status', __( 'Invalid status.', 'ux-studio' ), array( 'status' => 400 ) );
		}

		$existing = $this->get_item( $id );
		if ( null === $existing ) {
			return new WP_Error( 'uxstudio_not_found', __( 'Service request not found.', 'ux-studio' ), array( 'status' => 404 ) );
		}

		global $wpdb;
		$wpdb->update(
			"{$wpdb->prefix}uxstudio_service_requests",
			array( 'status' => $status ),
			array( 'id' => $id ),
			array( '%s' ),
			array( '%d' )
		);

		ActivityLog::log( 'service-requests', 'status_change', 'service_request', $id, array( 'status' => $status ) );

		return (array) $this->get_item( $id );
	}

	/**
	 * Delete a request (does not delete the underlying media attachment).
	 *
	 * @param int $id Row id.
	 */
	public function delete_item( int $id ): bool {
		global $wpdb;

		$existing = $this->get_item( $id );
		if ( null === $existing ) {
			return false;
		}

		$deleted = $wpdb->delete( "{$wpdb->prefix}uxstudio_service_requests", array( 'id' => $id ), array( '%d' ) );

		if ( $deleted ) {
			ActivityLog::log( 'service-requests', 'delete', 'service_request', $id );
		}

		return (bool) $deleted;
	}

	/**
	 * Email the configured delivery address (or the site admin) about a new
	 * request. Best-effort: failures are not surfaced to the API caller.
	 *
	 * @param array $item Formatted request row.
	 */
	private function notify_new_request( array $item ): void {
		$to = (string) $this->settings->get( 'delivery_email', '' );
		if ( '' === $to || ! is_email( $to ) ) {
			$to = (string) get_option( 'admin_email' );
		}

		$subject = sprintf(
			/* translators: %s: request title */
			__( 'New service request: %s', 'ux-studio' ),
			$item['title']
		);
		$body = sprintf(
			/* translators: 1: title, 2: description, 3: requester email */
			__( "A new service request was submitted.\n\nTitle: %1\$s\nDescription: %2\$s\nRequester: %3\$s", 'ux-studio' ),
			$item['title'],
			$item['description'] ?? '',
			$item['requester_email'] ?? ''
		);

		wp_mail( $to, $subject, $body );
	}

	/**
	 * Normalize a raw DB row for REST output (types).
	 *
	 * @param array $row Raw row from $wpdb.
	 * @return array<string, mixed>
	 */
	private function format_row( array $row ): array {
		return array(
			'id'              => (int) $row['id'],
			'created_at'      => $row['created_at'],
			'title'           => $row['title'],
			'description'     => $row['description'],
			'status'          => $row['status'],
			'requester_email' => $row['requester_email'],
			'attachment_id'   => (int) $row['attachment_id'],
		);
	}
}
