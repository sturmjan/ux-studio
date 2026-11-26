<?php
/**
 * Admin REST endpoints for the inquiries inbox: contact-form messages and callback requests that the chat
 * widget stores in uxstudio_ai_assistant_inquiries (and e-mails). All routes need manage_options.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\AiAssistant;

use UxStudio\Rest\Controller;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

/**
 *   GET    uxstudio/v1/ai-assistant/inquiries                 - { items, total, page, per_page, pages, new_count }; filters type/status
 *   POST   uxstudio/v1/ai-assistant/inquiries/{id}/status     - { status: new|handled }
 *   POST   uxstudio/v1/ai-assistant/inquiries/bulk-status     - { ids, status }
 *   DELETE uxstudio/v1/ai-assistant/inquiries/{id}
 *   POST   uxstudio/v1/ai-assistant/inquiries/bulk-delete     - { ids }
 *
 * The widget stores a contact message with type "email"; the API calls it "contact".
 */
final class InquiryRestController extends Controller {

	public const STATUS_NEW     = 'new';
	public const STATUS_HANDLED = 'handled';

	private const DEFAULT_PER_PAGE = 20;
	private const MAX_PER_PAGE     = 100;
	private const MAX_BULK         = 500;

	/** API type => stored `type` value. */
	private const TYPES = array(
		'contact'  => 'email',
		'callback' => 'callback',
	);

	public function register_routes(): void {
		$this->route(
			'/ai-assistant/inquiries',
			'GET',
			array( $this, 'list_inquiries' ),
			array(
				'page'     => array( 'required' => false, 'type' => 'integer', 'sanitize_callback' => 'absint' ),
				'per_page' => array( 'required' => false, 'type' => 'integer', 'sanitize_callback' => 'absint' ),
				'type'     => array( 'required' => false, 'type' => 'string' ),
				'status'   => array( 'required' => false, 'type' => 'string' ),
			)
		);

		$this->route(
			'/ai-assistant/inquiries/(?P<id>\d+)/status',
			'POST',
			array( $this, 'set_status' ),
			array(
				'id'     => array( 'required' => true, 'type' => 'integer' ),
				'status' => array( 'required' => true, 'type' => 'string' ),
			)
		);

		$this->route(
			'/ai-assistant/inquiries/bulk-status',
			'POST',
			array( $this, 'bulk_set_status' ),
			array(
				'ids'    => array( 'required' => true, 'type' => 'array' ),
				'status' => array( 'required' => true, 'type' => 'string' ),
			)
		);

		$this->route(
			'/ai-assistant/inquiries/(?P<id>\d+)',
			'DELETE',
			array( $this, 'delete_inquiry' ),
			array(
				'id' => array( 'required' => true, 'type' => 'integer' ),
			)
		);

		$this->route(
			'/ai-assistant/inquiries/bulk-delete',
			'POST',
			array( $this, 'bulk_delete' ),
			array(
				'ids' => array( 'required' => true, 'type' => 'array' ),
			)
		);
	}

	private static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'uxstudio_ai_assistant_inquiries';
	}

	/**
	 * Count of inquiries still marked "new" (the tab badge).
	 */
	public static function count_new(): int {
		global $wpdb;
		$table = self::table();
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE status = %s", self::STATUS_NEW ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	public function list_inquiries( WP_REST_Request $request ) {
		global $wpdb;
		$table    = self::table();
		$page     = max( 1, absint( $request->get_param( 'page' ) ) );
		$per_page = min( self::MAX_PER_PAGE, absint( $request->get_param( 'per_page' ) ) ?: self::DEFAULT_PER_PAGE );

		$where  = array();
		$params = array();

		$type = (string) $request->get_param( 'type' );
		if ( '' !== $type ) {
			if ( ! isset( self::TYPES[ $type ] ) ) {
				return new WP_Error( 'uxstudio_invalid_type', __( 'Invalid inquiry type.', 'ux-studio' ), array( 'status' => 400 ) );
			}
			$where[]  = 'i.type = %s';
			$params[] = self::TYPES[ $type ];
		}

		$status = (string) $request->get_param( 'status' );
		if ( '' !== $status ) {
			if ( ! self::valid_status( $status ) ) {
				return new WP_Error( 'uxstudio_invalid_status', __( 'Invalid inquiry status.', 'ux-studio' ), array( 'status' => 400 ) );
			}
			$where[]  = 'i.status = %s';
			$params[] = $status;
		}

		$where_sql = empty( $where ) ? '' : ' WHERE ' . implode( ' AND ', $where );
		$conv      = ConversationStore::table();

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $where_sql is built from fixed fragments with %s placeholders.
		$total = (int) $wpdb->get_var(
			empty( $params ) ? "SELECT COUNT(*) FROM {$table} i" : $wpdb->prepare( "SELECT COUNT(*) FROM {$table} i{$where_sql}", ...$params )
		);

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT i.id, i.type, i.name, i.email, i.phone, i.message, i.page_url, i.status, i.created_at,
					(SELECT MIN(c.id) FROM {$conv} c WHERE i.session_id <> '' AND c.session_id = i.session_id) AS conversation_id
				 FROM {$table} i{$where_sql}
				 ORDER BY i.created_at DESC, i.id DESC
				 LIMIT %d OFFSET %d",
				...array_merge( $params, array( $per_page, ( $page - 1 ) * $per_page ) )
			),
			ARRAY_A
		);
		// phpcs:enable

		return $this->ok(
			array(
				'items'     => array_map( array( self::class, 'present' ), (array) $rows ),
				'total'     => $total,
				'page'      => $page,
				'per_page'  => $per_page,
				'pages'     => (int) max( 1, ceil( $total / $per_page ) ),
				'new_count' => self::count_new(),
			)
		);
	}

	public function set_status( WP_REST_Request $request ) {
		$status = (string) $request->get_param( 'status' );
		if ( ! self::valid_status( $status ) ) {
			return new WP_Error( 'uxstudio_invalid_status', __( 'Invalid inquiry status.', 'ux-studio' ), array( 'status' => 400 ) );
		}

		global $wpdb;
		$id    = absint( $request->get_param( 'id' ) );
		$table = self::table();
		$exists = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE id = %d", $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( 0 === $exists ) {
			return new WP_Error( 'uxstudio_inquiry_not_found', __( 'Inquiry not found.', 'ux-studio' ), array( 'status' => 404 ) );
		}

		$wpdb->update( $table, array( 'status' => $status ), array( 'id' => $id ) );

		return $this->ok( array( 'id' => $id, 'status' => $status, 'new_count' => self::count_new() ) );
	}

	public function bulk_set_status( WP_REST_Request $request ) {
		$status = (string) $request->get_param( 'status' );
		if ( ! self::valid_status( $status ) ) {
			return new WP_Error( 'uxstudio_invalid_status', __( 'Invalid inquiry status.', 'ux-studio' ), array( 'status' => 400 ) );
		}
		$ids = self::clean_ids( $request->get_param( 'ids' ) );
		if ( empty( $ids ) ) {
			return $this->ok( array( 'updated' => 0, 'status' => $status, 'new_count' => self::count_new() ) );
		}

		global $wpdb;
		$table = self::table();
		$list  = implode( ',', $ids ); // Integers only (absint), safe to inline.
		$count = (int) $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET status = %s WHERE id IN ({$list})", $status ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return $this->ok( array( 'updated' => $count, 'status' => $status, 'new_count' => self::count_new() ) );
	}

	public function delete_inquiry( WP_REST_Request $request ) {
		global $wpdb;
		$id      = absint( $request->get_param( 'id' ) );
		$deleted = (int) $wpdb->delete( self::table(), array( 'id' => $id ) );
		if ( 0 === $deleted ) {
			return new WP_Error( 'uxstudio_inquiry_not_found', __( 'Inquiry not found.', 'ux-studio' ), array( 'status' => 404 ) );
		}

		return $this->ok( array( 'deleted' => true, 'new_count' => self::count_new() ) );
	}

	public function bulk_delete( WP_REST_Request $request ) {
		$ids = self::clean_ids( $request->get_param( 'ids' ) );
		if ( empty( $ids ) ) {
			return $this->ok( array( 'deleted' => 0, 'new_count' => self::count_new() ) );
		}

		global $wpdb;
		$table   = self::table();
		$list    = implode( ',', $ids );
		$deleted = (int) $wpdb->query( "DELETE FROM {$table} WHERE id IN ({$list})" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- integers only.

		return $this->ok( array( 'deleted' => $deleted, 'new_count' => self::count_new() ) );
	}

	private static function valid_status( string $status ): bool {
		return in_array( $status, array( self::STATUS_NEW, self::STATUS_HANDLED ), true );
	}

	/**
	 * Unique positive integer ids from a request value, capped.
	 *
	 * @param mixed $value Request param.
	 * @return array<int, int>
	 */
	private static function clean_ids( $value ): array {
		$ids = array();
		foreach ( (array) $value as $id ) {
			if ( is_numeric( $id ) && (int) $id > 0 ) {
				$ids[] = (int) $id;
			}
		}
		$ids = array_values( array_unique( $ids ) );
		return array_slice( $ids, 0, self::MAX_BULK );
	}

	/**
	 * Row of the inquiries table in the shape the admin UI gets.
	 *
	 * @param array<string, mixed> $row Database row.
	 * @return array<string, mixed>
	 */
	private static function present( array $row ): array {
		$stored = (string) ( $row['type'] ?? '' );
		$type   = array_search( $stored, self::TYPES, true );

		return array(
			'id'              => (int) $row['id'],
			'type'            => false === $type ? $stored : $type,
			'name'            => (string) ( $row['name'] ?? '' ),
			'email'           => (string) ( $row['email'] ?? '' ),
			'phone'           => (string) ( $row['phone'] ?? '' ),
			'message'         => (string) ( $row['message'] ?? '' ),
			'page_url'        => (string) ( $row['page_url'] ?? '' ),
			'status'          => (string) ( $row['status'] ?? self::STATUS_NEW ),
			'created_at'      => (string) ( $row['created_at'] ?? '' ),
			'conversation_id' => empty( $row['conversation_id'] ) ? null : (int) $row['conversation_id'],
		);
	}
}
