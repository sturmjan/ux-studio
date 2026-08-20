<?php
/**
 * Public + admin REST endpoints for the human-handoff flow (customer asks
 * for an operator, operator picks up/replies/closes from the admin inbox).
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
 * Public (rate-limited, permission_callback __return_true), all keyed by the
 * anonymous visitor's session_id - the same identifier ChatRestController's
 * /chat, /gdpr-consent and /rating endpoints use, since the widget never
 * learns the numeric conversation id:
 *   POST uxstudio/v1/ai-assistant/handoff/request  - ask for an operator
 *   POST uxstudio/v1/ai-assistant/handoff/cancel   - cancel a pending request
 *   POST uxstudio/v1/ai-assistant/handoff/message  - customer message during an active handoff
 *   POST uxstudio/v1/ai-assistant/handoff/poll     - widget polling (status + new messages).
 *                                                     POST (not GET) so session_id never ends up
 *                                                     in access logs / Referer headers / browser history.
 *
 * Admin (manage_options, via Controller::route()):
 *   GET  uxstudio/v1/ai-assistant/handoff/queue           - pending requests
 *   GET  uxstudio/v1/ai-assistant/handoff/active           - handoffs currently assigned to an operator
 *   POST uxstudio/v1/ai-assistant/handoff/{id}/assign      - operator picks up a conversation
 *   POST uxstudio/v1/ai-assistant/handoff/{id}/message     - operator replies
 *   POST uxstudio/v1/ai-assistant/handoff/{id}/close       - close, optionally returning the chat to the AI
 *   GET  uxstudio/v1/ai-assistant/handoff/{id}/messages    - full transcript + metadata (also used for polling)
 */
final class HandoffRestController extends Controller {

	/** Requests per IP-hash allowed within the window below, for the public handoff endpoints. */
	private const IP_LIMIT     = 30;
	private const IP_WINDOW    = 60;
	private const SESSION_LIMIT = 30;

	public function register_routes(): void {
		$this->register_public_routes();
		$this->register_admin_routes();
	}

	// ─── Public routes ───────────────────────────────────────────────

	private function register_public_routes(): void {
		register_rest_route(
			self::NS,
			'/ai-assistant/handoff/request',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'request_handoff' ),
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			self::NS,
			'/ai-assistant/handoff/cancel',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'cancel_request' ),
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			self::NS,
			'/ai-assistant/handoff/message',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'customer_message' ),
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			self::NS,
			'/ai-assistant/handoff/poll',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'customer_poll' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	// ─── Admin routes ────────────────────────────────────────────────

	private function register_admin_routes(): void {
		$this->route( '/ai-assistant/handoff/queue', 'GET', array( $this, 'get_queue' ) );

		$this->route(
			'/ai-assistant/handoff/active',
			'GET',
			array( $this, 'get_active' ),
			array(
				'mine' => array( 'required' => false, 'type' => 'boolean' ),
			)
		);

		$this->route(
			'/ai-assistant/handoff/(?P<id>\d+)/assign',
			'POST',
			array( $this, 'assign_conversation' ),
			array(
				'id' => array( 'required' => true, 'type' => 'integer' ),
			)
		);

		$this->route(
			'/ai-assistant/handoff/(?P<id>\d+)/message',
			'POST',
			array( $this, 'operator_message' ),
			array(
				'id'      => array( 'required' => true, 'type' => 'integer' ),
				'message' => array( 'required' => true, 'type' => 'string' ),
			)
		);

		$this->route(
			'/ai-assistant/handoff/(?P<id>\d+)/close',
			'POST',
			array( $this, 'close_conversation' ),
			array(
				'id'            => array( 'required' => true, 'type' => 'integer' ),
				'return_to_ai'  => array( 'required' => false, 'type' => 'boolean' ),
				'reason'        => array( 'required' => false, 'type' => 'string' ),
			)
		);

		$this->route(
			'/ai-assistant/handoff/(?P<id>\d+)/messages',
			'GET',
			array( $this, 'get_messages' ),
			array(
				'id' => array( 'required' => true, 'type' => 'integer' ),
			)
		);
	}

	// ─── Public handlers ─────────────────────────────────────────────

	public function request_handoff( WP_REST_Request $request ): WP_REST_Response {
		$session_id = $this->sanitize_session( $request->get_param( 'session_id' ) );
		if ( '' === $session_id ) {
			return new WP_REST_Response( array( 'error' => __( 'session_id is required.', 'ux-studio' ) ), 400 );
		}

		if ( ! $this->check_rate_limit( $session_id ) ) {
			return new WP_REST_Response( array( 'error' => __( 'Too many requests. Please try again shortly.', 'ux-studio' ) ), 429 );
		}

		$reason = mb_substr( sanitize_textarea_field( (string) ( $request->get_param( 'reason' ) ?? '' ) ), 0, 500 );

		$manager = new HandoffManager();
		$result  = $manager->request_handoff( $session_id, $reason );

		return new WP_REST_Response(
			array(
				'status'  => $result['status'],
				'message' => __( 'Your request has been recorded. Please wait for an operator to join.', 'ux-studio' ),
			),
			200
		);
	}

	public function cancel_request( WP_REST_Request $request ): WP_REST_Response {
		$session_id = $this->sanitize_session( $request->get_param( 'session_id' ) );
		if ( '' === $session_id ) {
			return new WP_REST_Response( array( 'error' => __( 'session_id is required.', 'ux-studio' ) ), 400 );
		}

		$manager   = new HandoffManager();
		$cancelled = $manager->cancel_request( $session_id );

		return new WP_REST_Response(
			array(
				'status'    => $manager->get_handoff_status( $session_id ),
				'cancelled' => $cancelled,
			),
			200
		);
	}

	public function customer_message( WP_REST_Request $request ): WP_REST_Response {
		$session_id = $this->sanitize_session( $request->get_param( 'session_id' ) );
		$message    = sanitize_textarea_field( (string) ( $request->get_param( 'message' ) ?? '' ) );
		$page_url   = esc_url_raw( (string) ( $request->get_param( 'page_url' ) ?? '' ) );

		if ( '' === $session_id || '' === $message ) {
			return new WP_REST_Response( array( 'error' => __( 'session_id and message are required.', 'ux-studio' ) ), 400 );
		}

		if ( ! $this->check_rate_limit( $session_id ) ) {
			return new WP_REST_Response( array( 'error' => __( 'Too many messages.', 'ux-studio' ) ), 429 );
		}

		$manager = new HandoffManager();

		if ( ! $manager->is_handoff_active( $session_id ) ) {
			return new WP_REST_Response( array( 'error' => __( 'This conversation is not currently handled by an operator.', 'ux-studio' ) ), 400 );
		}

		$sent = $manager->send_customer_message( $session_id, $message, $page_url );

		return new WP_REST_Response(
			array(
				'sent'   => $sent,
				'status' => $manager->get_handoff_status( $session_id ),
			),
			200
		);
	}

	public function customer_poll( WP_REST_Request $request ): WP_REST_Response {
		$session_id = $this->sanitize_session( $request->get_param( 'session_id' ) );
		$since      = max( 0, (int) $request->get_param( 'since' ) );

		if ( '' === $session_id ) {
			return new WP_REST_Response( array( 'error' => __( 'session_id is required.', 'ux-studio' ) ), 400 );
		}

		$manager = new HandoffManager();

		return new WP_REST_Response( $manager->poll_for_customer( $session_id, $since ), 200 );
	}

	// ─── Admin handlers ──────────────────────────────────────────────

	public function get_queue(): WP_REST_Response {
		return $this->ok( ( new HandoffManager() )->get_pending_queue() );
	}

	public function get_active( WP_REST_Request $request ): WP_REST_Response {
		$mine        = (bool) $request->get_param( 'mine' );
		$operator_id = $mine ? get_current_user_id() : null;

		return $this->ok( ( new HandoffManager() )->get_active_handoffs( $operator_id ) );
	}

	public function assign_conversation( WP_REST_Request $request ) {
		$id      = absint( $request->get_param( 'id' ) );
		$user_id = get_current_user_id();

		$assigned = ( new HandoffManager() )->assign_operator( $id, $user_id );
		if ( ! $assigned ) {
			return new WP_Error( 'uxstudio_handoff_assign_failed', __( 'Could not take over the conversation.', 'ux-studio' ), array( 'status' => 400 ) );
		}

		return $this->ok( array( 'assigned' => true, 'assigned_to' => $user_id ) );
	}

	public function operator_message( WP_REST_Request $request ) {
		$id      = absint( $request->get_param( 'id' ) );
		$message = sanitize_textarea_field( (string) $request->get_param( 'message' ) );

		if ( '' === $message ) {
			return new WP_Error( 'uxstudio_handoff_message_required', __( 'Message is required.', 'ux-studio' ), array( 'status' => 400 ) );
		}

		$user_id = get_current_user_id();
		$manager = new HandoffManager();

		// Auto-assign the operator replying to a still-pending request.
		if ( HandoffManager::STATUS_REQUESTED === $manager->get_status_by_id( $id ) ) {
			$manager->assign_operator( $id, $user_id );
		}

		$sent = $manager->send_operator_message( $id, $user_id, $message );
		if ( ! $sent ) {
			return new WP_Error( 'uxstudio_handoff_send_failed', __( 'Could not send the message.', 'ux-studio' ), array( 'status' => 400 ) );
		}

		return $this->ok(
			array(
				'sent'        => true,
				'message'     => $sent['message'],
				'total_count' => $sent['total_count'],
			)
		);
	}

	public function close_conversation( WP_REST_Request $request ) {
		$id           = absint( $request->get_param( 'id' ) );
		$return_to_ai = (bool) $request->get_param( 'return_to_ai' );
		$reason       = sanitize_textarea_field( (string) ( $request->get_param( 'reason' ) ?? '' ) );

		$manager = new HandoffManager();
		$closed  = $return_to_ai
			? $manager->return_to_ai( $id, $reason )
			: $manager->close_handoff( $id, $reason );

		if ( ! $closed ) {
			return new WP_Error( 'uxstudio_handoff_close_failed', __( 'Could not close the conversation.', 'ux-studio' ), array( 'status' => 400 ) );
		}

		return $this->ok( array( 'closed' => true, 'returned_to_ai' => $return_to_ai ) );
	}

	public function get_messages( WP_REST_Request $request ) {
		$id           = absint( $request->get_param( 'id' ) );
		$conversation = ( new HandoffManager() )->get_conversation( $id );

		if ( null === $conversation ) {
			return new WP_Error( 'uxstudio_handoff_not_found', __( 'Conversation not found.', 'ux-studio' ), array( 'status' => 404 ) );
		}

		return $this->ok( $conversation );
	}

	// ─── Helpers ─────────────────────────────────────────────────────

	private function sanitize_session( $raw ): string {
		$id = sanitize_text_field( (string) $raw );
		// Alphanumeric + dash/underscore only, matches the session id format
		// generated by crypto.randomUUID() in the widget.
		if ( ! preg_match( '/^[a-zA-Z0-9_\-]{1,64}$/', $id ) ) {
			return '';
		}
		return $id;
	}

	/**
	 * Sliding-window rate limit, same shape as ChatRestController's - primarily
	 * per-IP-hash (a client-controlled session id would make a session-only
	 * limit trivially bypassable), with a secondary per-session cap.
	 */
	private function check_rate_limit( string $session_id ): bool {
		$ip     = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : '';
		$ip_key = 'uxstudio_ah_rl_ip_' . md5( ( $ip ?: 'unknown' ) . wp_salt() );
		$ip_count = (int) get_transient( $ip_key );

		$session_key   = 'uxstudio_ah_rl_session_' . md5( $session_id . wp_salt() );
		$session_count = (int) get_transient( $session_key );

		if ( $ip_count >= self::IP_LIMIT || $session_count >= self::SESSION_LIMIT ) {
			return false;
		}

		set_transient( $ip_key, $ip_count + 1, self::IP_WINDOW );
		set_transient( $session_key, $session_count + 1, self::IP_WINDOW );

		return true;
	}
}
