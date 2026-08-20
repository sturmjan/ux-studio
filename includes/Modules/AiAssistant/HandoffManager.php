<?php
/**
 * Manages handing a conversation off from the AI to a live human operator.
 *
 * State machine (column `handoff_status` on uxstudio_ai_assistant_conversations):
 *   ai (default) -> requested (customer asked for a human) -> human (operator
 *   assigned, chatting live) -> ai (operator returns the chat to the AI) or
 *   closed (operator ends the conversation for good).
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\AiAssistant;

defined( 'ABSPATH' ) || exit;

final class HandoffManager {

	public const STATUS_AI        = 'ai';
	public const STATUS_REQUESTED = 'requested';
	public const STATUS_ACTIVE    = 'human';
	public const STATUS_CLOSED    = 'closed';

	private function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'uxstudio_ai_assistant_conversations';
	}

	/**
	 * Customer requests a live operator. Creates the conversation row if it
	 * doesn't exist yet (mirrors ChatEngine::process_message()'s upsert
	 * pattern - a visitor may click "call an operator" before sending any
	 * chat message). Sends the admin notification only on the transition
	 * into "requested" (not on every retry), so operators aren't spammed.
	 *
	 * @return array{status:string,requested:bool}
	 */
	public function request_handoff( string $session_id, string $reason = '' ): array {
		global $wpdb;
		$table = $this->table();
		$now   = current_time( 'mysql' );

		$conversation = $this->get_by_session( $session_id );

		if ( ! $conversation ) {
			$wpdb->insert(
				$table,
				array(
					'session_id'            => $session_id,
					'visitor_ip'            => $this->get_visitor_ip(),
					'visitor_user_agent'    => substr( (string) ( $_SERVER['HTTP_USER_AGENT'] ?? '' ), 0, 500 ),
					'page_url'              => '',
					'messages'              => wp_json_encode( array() ),
					'status'                => 'active',
					'gdpr_consent'          => 1,
					'handoff_status'        => self::STATUS_REQUESTED,
					'handoff_requested_at'  => $now,
					'operator_unread'       => 1,
					'last_message_at'       => $now,
					'created_at'            => $now,
					'updated_at'            => $now,
				)
			);
			$conversation_id = (int) $wpdb->insert_id;
		} else {
			// Already waiting or already with an operator - do not re-trigger the flow.
			if ( in_array( $conversation->handoff_status, array( self::STATUS_REQUESTED, self::STATUS_ACTIVE ), true ) ) {
				return array(
					'status'    => $conversation->handoff_status,
					'requested' => false,
				);
			}

			$wpdb->update(
				$table,
				array(
					'handoff_status'       => self::STATUS_REQUESTED,
					'handoff_requested_at' => $now,
					'operator_unread'      => 1,
					'last_message_at'      => $now,
					'updated_at'           => $now,
				),
				array( 'id' => $conversation->id )
			);
			$conversation_id = (int) $conversation->id;
		}

		$message = '' !== $reason
			? $reason
			: __( 'The customer requested a human operator. Waiting to connect.', 'ux-studio' );
		$this->append_message( $session_id, 'system', $message );

		$this->notify_operators( $conversation_id, $reason );

		return array(
			'status'    => self::STATUS_REQUESTED,
			'requested' => true,
		);
	}

	/**
	 * Customer cancels a still-pending request (before an operator joined).
	 */
	public function cancel_request( string $session_id ): bool {
		$conversation = $this->get_by_session( $session_id );
		if ( ! $conversation || self::STATUS_REQUESTED !== $conversation->handoff_status ) {
			return false;
		}

		global $wpdb;
		$wpdb->update(
			$this->table(),
			array(
				'handoff_status'   => self::STATUS_AI,
				'operator_unread'  => 0,
				'updated_at'       => current_time( 'mysql' ),
			),
			array( 'id' => $conversation->id )
		);

		$this->append_message( $session_id, 'system', __( 'The customer cancelled the operator request.', 'ux-studio' ) );

		return true;
	}

	/**
	 * Operator picks up a pending (or, defensively, already-active) conversation.
	 */
	public function assign_operator( int $conversation_id, int $user_id ): bool {
		global $wpdb;

		$updated = $wpdb->update(
			$this->table(),
			array(
				'handoff_status'       => self::STATUS_ACTIVE,
				'handoff_assigned_to'  => $user_id,
				'handoff_started_at'   => current_time( 'mysql' ),
				'operator_unread'      => 0,
				'updated_at'           => current_time( 'mysql' ),
			),
			array( 'id' => $conversation_id )
		);

		if ( false === $updated ) {
			return false;
		}

		$session_id = $this->get_session_id( $conversation_id );
		if ( null !== $session_id ) {
			$operator_name = $this->operator_name( $user_id );
			/* translators: %s: operator display name. */
			$this->append_message( $session_id, 'system', sprintf( __( '%s has joined the conversation.', 'ux-studio' ), $operator_name ), true );
		}

		return true;
	}

	/**
	 * Operator ends the live chat and hands the conversation back to the AI.
	 */
	public function return_to_ai( int $conversation_id, string $reason = '' ): bool {
		global $wpdb;

		$session_id = $this->get_session_id( $conversation_id );

		$updated = $wpdb->update(
			$this->table(),
			array(
				'handoff_status'      => self::STATUS_AI,
				'handoff_assigned_to' => null,
				'updated_at'          => current_time( 'mysql' ),
			),
			array( 'id' => $conversation_id )
		);

		if ( false !== $updated && null !== $session_id ) {
			$message = '' !== $reason ? $reason : __( 'The operator ended the conversation. The chatbot is available again.', 'ux-studio' );
			$this->append_message( $session_id, 'system', $message, true );
		}

		return false !== $updated;
	}

	/**
	 * Operator closes the conversation for good (no automatic return to AI).
	 */
	public function close_handoff( int $conversation_id, string $reason = '' ): bool {
		global $wpdb;

		$session_id = $this->get_session_id( $conversation_id );

		$updated = $wpdb->update(
			$this->table(),
			array(
				'handoff_status'      => self::STATUS_CLOSED,
				'handoff_assigned_to' => null,
				'updated_at'          => current_time( 'mysql' ),
			),
			array( 'id' => $conversation_id )
		);

		if ( false !== $updated && null !== $session_id ) {
			$message = '' !== $reason ? $reason : __( 'The operator closed the conversation.', 'ux-studio' );
			$this->append_message( $session_id, 'system', $message, true );
		}

		return false !== $updated;
	}

	/**
	 * Operator sends a message to the customer.
	 *
	 * @return array{message:array<string,mixed>,total_count:int}|false
	 */
	public function send_operator_message( int $conversation_id, int $user_id, string $message ) {
		global $wpdb;

		$conversation = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$this->table()} WHERE id = %d", $conversation_id ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);
		if ( ! $conversation ) {
			return false;
		}

		$operator_name = $this->operator_name( $user_id );

		$messages   = json_decode( (string) $conversation->messages, true ) ?: array();
		$new_message = array(
			'role'       => 'agent',
			'content'    => $message,
			'agent_id'   => $user_id,
			'agent_name' => $operator_name,
			'timestamp'  => current_time( 'mysql' ),
		);
		$messages[] = $new_message;

		$wpdb->update(
			$this->table(),
			array(
				'messages'        => wp_json_encode( $messages ),
				'customer_unread' => ( (int) ( $conversation->customer_unread ?? 0 ) ) + 1,
				'last_message_at' => current_time( 'mysql' ),
				'updated_at'      => current_time( 'mysql' ),
			),
			array( 'id' => $conversation_id )
		);

		return array(
			'message'     => $new_message,
			'total_count' => count( $messages ),
		);
	}

	/**
	 * Customer sends a message while the conversation is being handled by a
	 * human (the AI pipeline is bypassed entirely for these turns).
	 */
	public function send_customer_message( string $session_id, string $message, string $page_url = '' ): bool {
		global $wpdb;

		$conversation = $this->get_by_session( $session_id );
		if ( ! $conversation ) {
			return false;
		}

		$messages   = json_decode( (string) $conversation->messages, true ) ?: array();
		$messages[] = array(
			'role'      => 'user',
			'content'   => $message,
			'timestamp' => current_time( 'mysql' ),
		);

		$update = array(
			'messages'         => wp_json_encode( $messages ),
			'operator_unread'  => ( (int) ( $conversation->operator_unread ?? 0 ) ) + 1,
			'last_message_at'  => current_time( 'mysql' ),
			'updated_at'       => current_time( 'mysql' ),
		);
		if ( '' !== $page_url ) {
			$update['page_url'] = $page_url;
		}

		$wpdb->update( $this->table(), $update, array( 'id' => $conversation->id ) );

		return true;
	}

	/**
	 * Widget polling: fetch handoff status + any messages the customer
	 * hasn't seen yet (index-based, like the public chat's SSE stream is
	 * append-only). Resets the customer's unread counter as a side effect.
	 *
	 * @return array{messages:array<int,array<string,mixed>>,handoff_status:string,assigned_to:?string,total_count:int,handoff_requested_at:?string}
	 */
	public function poll_for_customer( string $session_id, int $since_index = 0 ): array {
		$conversation = $this->get_by_session( $session_id );

		if ( ! $conversation ) {
			return array(
				'messages'             => array(),
				'handoff_status'       => self::STATUS_AI,
				'assigned_to'          => null,
				'total_count'          => 0,
				'handoff_requested_at' => null,
			);
		}

		$all_messages = json_decode( (string) $conversation->messages, true ) ?: array();
		$new_messages = array_slice( $all_messages, $since_index );

		if ( ! empty( $new_messages ) ) {
			$this->mark_customer_read( (int) $conversation->id );
		}

		return array(
			'messages'             => array_values( $new_messages ),
			'handoff_status'       => $conversation->handoff_status ?: self::STATUS_AI,
			'assigned_to'          => $this->assigned_operator_name( $conversation ),
			'total_count'          => count( $all_messages ),
			'handoff_requested_at' => $conversation->handoff_requested_at,
		);
	}

	/**
	 * Admin inbox polling: fetch messages an operator hasn't seen yet.
	 *
	 * @return array{messages:array<int,array<string,mixed>>,handoff_status:string,total_count:int}
	 */
	public function poll_for_operator( int $conversation_id, int $since_index = 0 ): array {
		global $wpdb;

		$conversation = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$this->table()} WHERE id = %d", $conversation_id ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);
		if ( ! $conversation ) {
			return array( 'messages' => array(), 'handoff_status' => self::STATUS_CLOSED, 'total_count' => 0 );
		}

		$all_messages = json_decode( (string) $conversation->messages, true ) ?: array();
		$new_messages = array_slice( $all_messages, $since_index );

		if ( ! empty( $new_messages ) ) {
			$this->mark_operator_read( $conversation_id );
		}

		return array(
			'messages'       => array_values( $new_messages ),
			'handoff_status' => $conversation->handoff_status ?: self::STATUS_AI,
			'total_count'    => count( $all_messages ),
		);
	}

	/**
	 * Resets the "operator has unread customer messages" counter.
	 */
	public function mark_operator_read( int $conversation_id ): void {
		global $wpdb;
		$wpdb->update( $this->table(), array( 'operator_unread' => 0 ), array( 'id' => $conversation_id ) );
	}

	/**
	 * Resets the "customer has unread operator messages" counter.
	 */
	public function mark_customer_read( int $conversation_id ): void {
		global $wpdb;
		$wpdb->update( $this->table(), array( 'customer_unread' => 0 ), array( 'id' => $conversation_id ) );
	}

	/**
	 * Conversations waiting for an operator to pick them up, oldest first.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function get_pending_queue(): array {
		global $wpdb;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, session_id, visitor_ip, page_url, messages, handoff_status,
						handoff_requested_at, handoff_started_at, operator_unread, last_message_at
				 FROM {$this->table()}
				 WHERE handoff_status = %s
				 ORDER BY handoff_requested_at ASC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				self::STATUS_REQUESTED
			)
		);

		return array_map( array( $this, 'format_queue_item' ), $rows );
	}

	/**
	 * Conversations currently assigned to an operator (or all operators, when
	 * $operator_id is null), most recently active first.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function get_active_handoffs( ?int $operator_id = null ): array {
		global $wpdb;

		$query  = "SELECT id, session_id, visitor_ip, page_url, messages, handoff_status,
						  handoff_requested_at, handoff_started_at, handoff_assigned_to,
						  operator_unread, last_message_at
				   FROM {$this->table()}
				   WHERE handoff_status = %s";
		$params = array( self::STATUS_ACTIVE );

		if ( null !== $operator_id ) {
			$query   .= ' AND handoff_assigned_to = %d';
			$params[] = $operator_id;
		}

		$query .= ' ORDER BY last_message_at DESC';

		$rows = $wpdb->get_results( $wpdb->prepare( $query, ...$params ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return array_map( array( $this, 'format_queue_item' ), $rows );
	}

	/**
	 * Full message history + metadata for one conversation (admin inbox detail view).
	 */
	public function get_conversation( int $conversation_id ): ?array {
		global $wpdb;

		$conversation = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$this->table()} WHERE id = %d", $conversation_id ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);
		if ( ! $conversation ) {
			return null;
		}

		$this->mark_operator_read( $conversation_id );
		$messages = json_decode( (string) $conversation->messages, true ) ?: array();

		return array(
			'id'                   => (int) $conversation->id,
			'session_id'           => $conversation->session_id,
			'visitor_ip'           => $conversation->visitor_ip,
			'page_url'             => $conversation->page_url,
			'handoff_status'       => $conversation->handoff_status ?: self::STATUS_AI,
			'handoff_requested_at' => $conversation->handoff_requested_at,
			'handoff_started_at'   => $conversation->handoff_started_at,
			'handoff_assigned_to'  => $conversation->handoff_assigned_to ? (int) $conversation->handoff_assigned_to : null,
			'assigned_to_name'     => $this->assigned_operator_name( $conversation ),
			'messages'             => $messages,
			'total_count'          => count( $messages ),
			'created_at'           => $conversation->created_at,
			'updated_at'           => $conversation->updated_at,
		);
	}

	/**
	 * Current handoff status for a session (defaults to "ai" if unknown).
	 */
	public function get_handoff_status( string $session_id ): string {
		$conversation = $this->get_by_session( $session_id );
		return $conversation->handoff_status ?? self::STATUS_AI;
	}

	/**
	 * True while the session is waiting for, or being handled by, a human.
	 */
	public function is_handoff_active( string $session_id ): bool {
		return in_array( $this->get_handoff_status( $session_id ), array( self::STATUS_REQUESTED, self::STATUS_ACTIVE ), true );
	}

	/**
	 * Current handoff status by numeric conversation id (admin side), without
	 * the read-marking side effects of get_conversation().
	 */
	public function get_status_by_id( int $conversation_id ): ?string {
		global $wpdb;
		$status = $wpdb->get_var(
			$wpdb->prepare( "SELECT handoff_status FROM {$this->table()} WHERE id = %d", $conversation_id ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);
		return null !== $status ? (string) $status : null;
	}

	// ─── Internal helpers ──────────────────────────────────────────────

	private function get_by_session( string $session_id ): ?object {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$this->table()} WHERE session_id = %s", $session_id ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);
		return $row ?: null;
	}

	private function get_session_id( int $conversation_id ): ?string {
		global $wpdb;
		$session_id = $wpdb->get_var(
			$wpdb->prepare( "SELECT session_id FROM {$this->table()} WHERE id = %d", $conversation_id ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);
		return $session_id ?: null;
	}

	/**
	 * Appends a system/agent message to a session's transcript.
	 * $notify_customer bumps `customer_unread` (used for messages the
	 * customer-facing widget should surface, e.g. operator joined/left).
	 */
	private function append_message( string $session_id, string $role, string $content, bool $notify_customer = false ): void {
		global $wpdb;

		$conversation = $this->get_by_session( $session_id );
		if ( ! $conversation ) {
			return;
		}

		$messages   = json_decode( (string) $conversation->messages, true ) ?: array();
		$messages[] = array(
			'role'      => $role,
			'content'   => $content,
			'timestamp' => current_time( 'mysql' ),
		);

		$update = array(
			'messages'        => wp_json_encode( $messages ),
			'last_message_at' => current_time( 'mysql' ),
			'updated_at'      => current_time( 'mysql' ),
		);
		if ( $notify_customer ) {
			$update['customer_unread'] = ( (int) ( $conversation->customer_unread ?? 0 ) ) + 1;
		}

		$wpdb->update( $this->table(), $update, array( 'id' => $conversation->id ) );
	}

	/**
	 * Emails the site admin when a handoff is first requested. Deliberately
	 * not sent on every follow-up customer message - only on the ai -> requested
	 * transition (see request_handoff()) - to avoid spamming the inbox.
	 */
	private function notify_operators( int $conversation_id, string $reason ): void {
		$to = get_option( 'admin_email' );
		if ( empty( $to ) ) {
			return;
		}

		$site_name = get_bloginfo( 'name' );
		$inbox_url = admin_url( 'admin.php?page=ux-studio#/ai-assistant/handoff/' . $conversation_id );

		/* translators: %s: site name. */
		$subject = sprintf( __( '[%s] A customer is requesting a human operator', 'ux-studio' ), $site_name );

		$body = __( 'A customer is requesting to be connected with a human operator in the AI chat.', 'ux-studio' ) . "\n\n";
		if ( '' !== $reason ) {
			/* translators: %s: reason given by the customer. */
			$body .= sprintf( __( 'Reason: %s', 'ux-studio' ), $reason ) . "\n\n";
		}
		/* translators: %s: URL to the handoff inbox. */
		$body .= sprintf( __( 'Open the inbox: %s', 'ux-studio' ), $inbox_url ) . "\n";

		wp_mail( $to, $subject, $body );
	}

	/**
	 * @param object $row Row with handoff_status/messages/etc (queue or active list).
	 * @return array<string,mixed>
	 */
	private function format_queue_item( object $row ): array {
		$messages       = json_decode( (string) $row->messages, true ) ?: array();
		$last_user_text = '';

		for ( $i = count( $messages ) - 1; $i >= 0; $i-- ) {
			if ( 'user' === ( $messages[ $i ]['role'] ?? '' ) ) {
				$last_user_text = $messages[ $i ]['content'] ?? '';
				break;
			}
		}

		return array(
			'id'                => (int) $row->id,
			'session_id'        => $row->session_id,
			'visitor_ip'        => $row->visitor_ip,
			'page_url'          => $row->page_url,
			'status'            => $row->handoff_status,
			'last_user_message' => mb_substr( wp_strip_all_tags( $last_user_text ), 0, 120 ),
			'message_count'     => count( $messages ),
			'operator_unread'   => (int) ( $row->operator_unread ?? 0 ),
			'assigned_to'       => isset( $row->handoff_assigned_to ) ? $this->operator_name_nullable( (int) $row->handoff_assigned_to ) : null,
			'requested_at'      => $row->handoff_requested_at,
			'started_at'        => $row->handoff_started_at,
			'last_message_at'   => $row->last_message_at,
		);
	}

	private function assigned_operator_name( object $conversation ): ?string {
		if ( empty( $conversation->handoff_assigned_to ) ) {
			return null;
		}
		return $this->operator_name_nullable( (int) $conversation->handoff_assigned_to );
	}

	private function operator_name_nullable( int $user_id ): ?string {
		if ( $user_id <= 0 ) {
			return null;
		}
		$user = get_userdata( $user_id );
		return $user ? $user->display_name : null;
	}

	private function operator_name( int $user_id ): string {
		return $this->operator_name_nullable( $user_id ) ?? __( 'Operator', 'ux-studio' );
	}

	private function get_visitor_ip(): string {
		$headers = array( 'HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR' );
		foreach ( $headers as $header ) {
			if ( ! empty( $_SERVER[ $header ] ) ) {
				$ip = sanitize_text_field( wp_unslash( $_SERVER[ $header ] ) );
				if ( str_contains( $ip, ',' ) ) {
					$ip = trim( explode( ',', $ip )[0] );
				}
				if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
					return $ip;
				}
			}
		}
		return '';
	}
}
