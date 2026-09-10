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
	 * Accepted from the submit form; anything else falls back to the default.
	 * Deliberately narrower than the central app's own list - `incident` is an
	 * operator's classification, not something a client picks.
	 */
	private const TYPES      = array( 'bug', 'change', 'question', 'task' );
	private const PRIORITIES = array( 'low', 'normal', 'high', 'critical' );

	/**
	 * Register hooks.
	 */
	public function boot(): void {
		add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );

		\UxStudio\Core\DB::ensure_module_tables(
			'service-requests',
			2,
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
						requester_name VARCHAR(255) NOT NULL DEFAULT '',
						requester_user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
						page_url VARCHAR(500) NOT NULL DEFAULT '',
						type VARCHAR(20) NOT NULL DEFAULT 'bug',
						priority VARCHAR(10) NOT NULL DEFAULT 'normal',
						environment LONGTEXT NULL,
						attachment_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
						central_ticket_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
						central_number VARCHAR(20) NOT NULL DEFAULT '',
						central_status VARCHAR(20) NULL,
						central_thread LONGTEXT NULL,
						sync_state VARCHAR(10) NOT NULL DEFAULT 'pending',
						sync_attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
						sync_error VARCHAR(500) NOT NULL DEFAULT '',
						last_try_at DATETIME NULL,
						synced_at DATETIME NULL,
						PRIMARY KEY  (id),
						KEY status (status),
						KEY sync_state (sync_state, central_ticket_id)
					) {$charset};"
				);

				// Outbox for client replies. Separate from the request row
				// because one request can have several pending messages and a
				// message that failed to send must not block the next one.
				dbDelta(
					"CREATE TABLE {$wpdb->prefix}uxstudio_service_request_outbox (
						id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
						request_id BIGINT UNSIGNED NOT NULL,
						external_id VARCHAR(64) NOT NULL DEFAULT '',
						body LONGTEXT NOT NULL,
						author_name VARCHAR(255) NOT NULL DEFAULT '',
						author_email VARCHAR(255) NOT NULL DEFAULT '',
						attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
						last_error VARCHAR(500) NOT NULL DEFAULT '',
						last_try_at DATETIME NULL,
						created_at DATETIME NOT NULL,
						PRIMARY KEY  (id),
						KEY request_id (request_id)
					) {$charset};"
				);
			}
		);

		Sync::schedule();
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
					"SELECT * FROM {$wpdb->prefix}uxstudio_service_requests WHERE status = %s ORDER BY id DESC",
					$status
				),
				ARRAY_A
			);
		} else {
			$rows = $wpdb->get_results(
				"SELECT * FROM {$wpdb->prefix}uxstudio_service_requests ORDER BY id DESC",
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
				"SELECT * FROM {$wpdb->prefix}uxstudio_service_requests WHERE id = %d",
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

		$user     = wp_get_current_user();
		$page_url = isset( $data['page_url'] ) ? esc_url_raw( (string) $data['page_url'] ) : '';

		// The environment snapshot is taken HERE, at submit time. Reading it
		// later would describe the site as it is now, not as it was when the
		// client hit the problem - and that difference is what separates a
		// reproducible bug report from a guessing game.
		$environment = Sync::collect_environment( $page_url );

		$wpdb->insert(
			"{$wpdb->prefix}uxstudio_service_requests",
			array(
				'created_at'        => current_time( 'mysql' ),
				'title'             => mb_substr( (string) $data['title'], 0, 255 ),
				'description'       => isset( $data['description'] ) ? sanitize_textarea_field( (string) $data['description'] ) : null,
				'status'            => 'open',
				'requester_email'   => isset( $data['requester_email'] ) && '' !== (string) $data['requester_email']
					? sanitize_email( (string) $data['requester_email'] )
					: (string) $user->user_email,
				'requester_name'    => (string) ( $user->display_name ? $user->display_name : $user->user_login ),
				'requester_user_id' => (int) $user->ID,
				'page_url'          => $page_url,
				'type'              => in_array( (string) ( $data['type'] ?? '' ), self::TYPES, true ) ? (string) $data['type'] : 'bug',
				'priority'          => in_array( (string) ( $data['priority'] ?? '' ), self::PRIORITIES, true ) ? (string) $data['priority'] : 'normal',
				'environment'       => (string) wp_json_encode( $environment ),
				'attachment_id'     => $attachment_id,
				'sync_state'        => 'pending',
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%d', '%s' )
		);

		$id = (int) $wpdb->insert_id;

		ActivityLog::log( 'service-requests', 'create', 'service_request', $id );

		$item = (array) $this->get_item( $id );
		$this->notify_new_request( $item );

		// Best-effort immediate push so the usual case feels instant. When the
		// central app is unreachable the row simply stays `pending` and the
		// 5-minute cron retries it - the request is never lost, which is the
		// entire reason the local row exists.
		if ( CentralClient::is_configured() ) {
			$row = $wpdb->get_row(
				$wpdb->prepare( "SELECT * FROM {$wpdb->prefix}uxstudio_service_requests WHERE id = %d", $id ),
				ARRAY_A
			);
			if ( is_array( $row ) ) {
				Sync::push_one( $row );
			}
			$item = (array) $this->get_item( $id );
		}

		return $item;
	}

	/**
	 * Add a client reply to the conversation.
	 *
	 * The message is queued and pushed; it is never kept as a local thread row,
	 * because the thread is a mirror of the central app. Two copies of the same
	 * text would drift the moment either side edited or deleted anything.
	 *
	 * @param int    $id   Local request id.
	 * @param string $body Message text.
	 * @return array<string, mixed>|WP_Error
	 */
	public function reply( int $id, string $body ) {
		$item = $this->get_item( $id );
		if ( null === $item ) {
			return new WP_Error( 'uxstudio_not_found', __( 'Service request not found.', 'ux-studio' ), array( 'status' => 404 ) );
		}
		if ( (int) $item['central_ticket_id'] <= 0 ) {
			return new WP_Error(
				'uxstudio_not_synced',
				__( 'This request has not reached the central app yet. Try again once it is submitted.', 'ux-studio' ),
				array( 'status' => 409 )
			);
		}

		$user   = wp_get_current_user();
		$result = Sync::queue_reply(
			$id,
			$body,
			(string) ( $user->display_name ? $user->display_name : $user->user_login ),
			(string) $user->user_email
		);

		if ( $result instanceof WP_Error ) {
			return $result;
		}

		ActivityLog::log( 'service-requests', 'reply', 'service_request', $id );

		Sync::pull_one( $id );
		return (array) $this->get_item( $id );
	}

	/**
	 * Force a sync pass for one request (the refresh button in the UI).
	 *
	 * @param int $id Local request id.
	 * @return array<string, mixed>|WP_Error
	 */
	public function resync( int $id ) {
		global $wpdb;

		$item = $this->get_item( $id );
		if ( null === $item ) {
			return new WP_Error( 'uxstudio_not_found', __( 'Service request not found.', 'ux-studio' ), array( 'status' => 404 ) );
		}

		if ( (int) $item['central_ticket_id'] > 0 ) {
			Sync::pull_one( $id );
		} else {
			// A human pressing the button means the cause (wrong key, central
			// app down) has just been dealt with, so the backoff must not keep
			// the row waiting - the attempt counter is reset.
			$wpdb->query(
				$wpdb->prepare(
					"UPDATE {$wpdb->prefix}uxstudio_service_requests
					    SET sync_attempts = 0, sync_state = 'pending', sync_error = ''
					  WHERE id = %d",
					$id
				)
			);
			$row = $wpdb->get_row(
				$wpdb->prepare( "SELECT * FROM {$wpdb->prefix}uxstudio_service_requests WHERE id = %d", $id ),
				ARRAY_A
			);
			if ( is_array( $row ) ) {
				Sync::push_one( $row );
			}
		}

		Sync::flush_outbox( 10 );
		return (array) $this->get_item( $id );
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
		$thread      = json_decode( (string) ( $row['central_thread'] ?? '' ), true );
		$environment = json_decode( (string) ( $row['environment'] ?? '' ), true );

		return array(
			'id'                => (int) $row['id'],
			'created_at'        => $row['created_at'],
			'title'             => $row['title'],
			'description'       => $row['description'],
			'status'            => $row['status'],
			'requester_email'   => $row['requester_email'],
			'requester_name'    => (string) ( $row['requester_name'] ?? '' ),
			'page_url'          => (string) ( $row['page_url'] ?? '' ),
			'type'              => (string) ( $row['type'] ?? 'bug' ),
			'priority'          => (string) ( $row['priority'] ?? 'normal' ),
			'environment'       => is_array( $environment ) ? $environment : array(),
			'attachment_id'     => (int) $row['attachment_id'],
			// Everything below is the central app's answer, mirrored here.
			'central_ticket_id' => (int) ( $row['central_ticket_id'] ?? 0 ),
			'central_number'    => (string) ( $row['central_number'] ?? '' ),
			'central_status'    => (string) ( $row['central_status'] ?? '' ),
			'thread'            => is_array( $thread ) ? $thread : array(),
			'sync_state'        => (string) ( $row['sync_state'] ?? 'pending' ),
			'sync_error'        => (string) ( $row['sync_error'] ?? '' ),
			'synced_at'         => $row['synced_at'] ?? null,
		);
	}
}
