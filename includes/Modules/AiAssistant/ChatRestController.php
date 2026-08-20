<?php
/**
 * Public + admin REST endpoints for the chat widget.
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
 * Public (rate-limited, permission_callback __return_true):
 *   POST   uxstudio/v1/ai-assistant/chat               - SSE-streamed AI reply
 *   POST   uxstudio/v1/ai-assistant/gdpr-consent
 *   POST   uxstudio/v1/ai-assistant/feedback            - FAQ helpful/not-helpful
 *   POST   uxstudio/v1/ai-assistant/contact              - contact form -> email + inquiry log
 *   POST   uxstudio/v1/ai-assistant/callback              - "call me back" request
 *   POST   uxstudio/v1/ai-assistant/rating                - 1-5 star conversation rating
 *   GET    uxstudio/v1/ai-assistant/faqs                  - active FAQs for the widget's quick-suggestions
 *   GET    uxstudio/v1/ai-assistant/product-card/{id}     - product card data (WooCommerce)
 *
 * Admin (manage_options, via Controller::route()):
 *   GET    uxstudio/v1/ai-assistant/conversations
 *   GET    uxstudio/v1/ai-assistant/conversations/{id}
 *   DELETE uxstudio/v1/ai-assistant/conversations/{id}
 *   POST   uxstudio/v1/ai-assistant/conversations/bulk-delete
 */
final class ChatRestController extends Controller {

	/** Requests per IP-hash allowed within the window below, for /chat. */
	private const CHAT_IP_LIMIT   = 20;
	private const CHAT_IP_WINDOW  = 60;
	private const CHAT_SESSION_LIMIT = 30;

	/** Simple per-hour caps for the lightweight public write endpoints. */
	private const FORM_HOURLY_LIMIT = 3;

	public function register_routes(): void {
		$this->register_public_routes();
		$this->register_admin_routes();
	}

	// ─── Public routes ───────────────────────────────────────────────

	private function register_public_routes(): void {
		register_rest_route(
			self::NS,
			'/ai-assistant/chat',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'chat' ),
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			self::NS,
			'/ai-assistant/gdpr-consent',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'gdpr_consent' ),
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			self::NS,
			'/ai-assistant/feedback',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'feedback' ),
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			self::NS,
			'/ai-assistant/contact',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'contact' ),
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			self::NS,
			'/ai-assistant/callback',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'callback_request' ),
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			self::NS,
			'/ai-assistant/rating',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'rating' ),
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			self::NS,
			'/ai-assistant/faqs',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'faqs' ),
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			self::NS,
			'/ai-assistant/product-card/(?P<id>\d+)',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'product_card' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'id' => array(
						'required' => true,
						'type'     => 'integer',
					),
				),
			)
		);
	}

	// ─── Admin routes ────────────────────────────────────────────────

	private function register_admin_routes(): void {
		$this->route(
			'/ai-assistant/conversations',
			'GET',
			array( $this, 'list_conversations' ),
			array(
				'page'     => array( 'required' => false, 'type' => 'integer', 'sanitize_callback' => 'absint' ),
				'per_page' => array( 'required' => false, 'type' => 'integer', 'sanitize_callback' => 'absint' ),
				'rating'   => array( 'required' => false, 'type' => 'integer', 'sanitize_callback' => 'absint' ),
			)
		);

		$this->route(
			'/ai-assistant/conversations/(?P<id>\d+)',
			'GET',
			array( $this, 'get_conversation' ),
			array(
				'id' => array( 'required' => true, 'type' => 'integer' ),
			)
		);

		$this->route(
			'/ai-assistant/conversations/(?P<id>\d+)',
			'DELETE',
			array( $this, 'delete_conversation' ),
			array(
				'id' => array( 'required' => true, 'type' => 'integer' ),
			)
		);

		$this->route(
			'/ai-assistant/conversations/bulk-delete',
			'POST',
			array( $this, 'bulk_delete_conversations' ),
			array(
				'ids' => array( 'required' => true, 'type' => 'array' ),
			)
		);
	}

	// ─── Public handlers ─────────────────────────────────────────────

	/**
	 * Streams the AI reply as Server-Sent Events. This bypasses the normal
	 * ok()/WP_REST_Response envelope entirely (echoes directly + exit) because
	 * the response must be flushed chunk by chunk while the provider is still
	 * generating text - see ChatEngine::process_message()'s $on_chunk callback.
	 */
	public function chat( WP_REST_Request $request ): void {
		$message    = sanitize_text_field( (string) $request->get_param( 'message' ) );
		$session_id = sanitize_text_field( (string) $request->get_param( 'session_id' ) );
		$page_url   = esc_url_raw( (string) $request->get_param( 'page_url' ) );
		$product_id = absint( $request->get_param( 'product_id' ) );

		if ( '' === $message || '' === $session_id ) {
			ErrorLogger::log(
				array(
					'session_id'    => $session_id,
					'error_type'    => ErrorLogger::TYPE_VALIDATION,
					'error_message' => 'Missing message or session id.',
					'user_message'  => $message,
					'page_url'      => $page_url,
				)
			);
			$this->send_json_error( __( 'Message or session id is missing.', 'ux-studio' ) );
			return;
		}

		if ( ! $this->check_chat_rate_limit( $session_id ) ) {
			ErrorLogger::log(
				array(
					'session_id'    => $session_id,
					'error_type'    => ErrorLogger::TYPE_RATELIMIT,
					'error_message' => 'Public chat rate limit exceeded.',
					'user_message'  => $message,
					'page_url'      => $page_url,
				)
			);
			$this->send_json_error( __( 'Too many requests. Please try again shortly.', 'ux-studio' ) );
			return;
		}

		$limit_error = UsageLimiter::check( 0 );
		if ( null !== $limit_error ) {
			ErrorLogger::log(
				array(
					'session_id'    => $session_id,
					'error_type'    => ErrorLogger::TYPE_RATELIMIT,
					'error_message' => $limit_error,
					'user_message'  => $message,
					'page_url'      => $page_url,
					'context'       => array( 'scope' => 'usage_limiter_public' ),
				)
			);
			$this->send_json_error( $limit_error );
			return;
		}

		header( 'Content-Type: text/event-stream' );
		header( 'Cache-Control: no-cache' );
		header( 'Connection: keep-alive' );
		header( 'X-Accel-Buffering: no' );

		if ( ob_get_level() ) {
			ob_end_clean();
		}

		try {
			$engine = new ChatEngine();
			$result = $engine->process_message(
				$message,
				$session_id,
				$page_url,
				function ( string $chunk ) {
					echo 'data: ' . wp_json_encode( array( 'text' => $chunk ) ) . "\n\n";
					if ( ob_get_level() ) {
						ob_flush();
					}
					flush();
				},
				$product_id,
				ChatEngine::TARGET_PUBLIC
			);

			if ( ! empty( $result['product_ids'] ) ) {
				echo 'data: ' . wp_json_encode( array( 'products' => $result['product_ids'] ) ) . "\n\n";
				flush();
			}

			echo 'data: ' . wp_json_encode( array( 'done' => true ) ) . "\n\n";
			flush();
		} catch ( \Throwable $e ) {
			$detail = ErrorLogger::parse_exception_detail( $e->getMessage() );
			ErrorLogger::log(
				array(
					'session_id'    => $session_id,
					'error_type'    => ErrorLogger::TYPE_UNKNOWN,
					'error_message' => $e->getMessage(),
					'http_status'   => $detail['http_status'],
					'user_message'  => $message,
					'page_url'      => $page_url,
					'context'       => array(
						'exception_class' => get_class( $e ),
						'origin'          => 'chat() catch-all (all providers failed)',
					),
				)
			);

			echo 'data: ' . wp_json_encode(
				array(
					'error'             => __( 'We are currently at capacity. Please contact us by email instead.', 'ux-studio' ),
					'show_contact_form' => true,
				)
			) . "\n\n";
			flush();
		}

		exit;
	}

	public function gdpr_consent( WP_REST_Request $request ) {
		$session_id = sanitize_text_field( (string) $request->get_param( 'session_id' ) );
		if ( '' === $session_id ) {
			return new WP_Error( 'uxstudio_missing_session', __( 'Session id is missing.', 'ux-studio' ), array( 'status' => 400 ) );
		}

		( new ChatEngine() )->record_gdpr_consent( $session_id );

		return $this->ok( array( 'success' => true ) );
	}

	public function feedback( WP_REST_Request $request ) {
		$faq_id  = absint( $request->get_param( 'faq_id' ) );
		$helpful = (bool) $request->get_param( 'helpful' );

		if ( $faq_id > 0 && class_exists( FaqManager::class ) && method_exists( FaqManager::class, 'record_feedback' ) ) {
			FaqManager::record_feedback( $faq_id, $helpful );
		}

		return $this->ok( array( 'success' => true ) );
	}

	public function contact( WP_REST_Request $request ) {
		$session_id = sanitize_text_field( (string) $request->get_param( 'session_id' ) );
		$name       = sanitize_text_field( (string) $request->get_param( 'name' ) );
		$email      = sanitize_email( (string) $request->get_param( 'email' ) );
		$phone      = sanitize_text_field( (string) $request->get_param( 'phone' ) );
		$message    = sanitize_textarea_field( (string) $request->get_param( 'message' ) );
		$page_url   = esc_url_raw( (string) $request->get_param( 'page_url' ) );

		if ( '' === $name || '' === $email || '' === $message ) {
			return new WP_Error( 'uxstudio_missing_fields', __( 'Please fill in all fields.', 'ux-studio' ), array( 'status' => 400 ) );
		}
		if ( ! is_email( $email ) ) {
			return new WP_Error( 'uxstudio_invalid_email', __( 'Invalid email address.', 'ux-studio' ), array( 'status' => 400 ) );
		}
		if ( ! $this->check_form_rate_limit( 'contact', $session_id ) ) {
			return new WP_Error( 'uxstudio_rate_limited', __( 'You have reached the maximum number of messages. Please try again later.', 'ux-studio' ), array( 'status' => 429 ) );
		}

		$to        = get_option( 'admin_email' );
		$site_name = get_bloginfo( 'name' );
		$subject   = sprintf(
			/* translators: 1: site name, 2: sender name */
			__( '[%1$s] Message from the AI chat by %2$s', 'ux-studio' ),
			$site_name,
			$name
		);

		$body = sprintf(
			"<h3>%s</h3>\n<p><strong>%s:</strong> %s</p>\n<p><strong>%s:</strong> %s</p>\n<p><strong>%s:</strong> %s</p>\n<p><strong>%s:</strong> %s</p>\n<hr>\n<p><strong>%s:</strong></p>\n<p>%s</p>",
			esc_html__( 'New message from the AI Assistant chat', 'ux-studio' ),
			esc_html__( 'Name', 'ux-studio' ),
			esc_html( $name ),
			esc_html__( 'Email', 'ux-studio' ),
			esc_html( $email ),
			esc_html__( 'Phone', 'ux-studio' ),
			esc_html( $phone ?: '-' ),
			esc_html__( 'Page', 'ux-studio' ),
			esc_html( $page_url ),
			esc_html__( 'Message', 'ux-studio' ),
			nl2br( esc_html( $message ) )
		);

		$sent = wp_mail(
			$to,
			$subject,
			$body,
			array(
				'Content-Type: text/html; charset=UTF-8',
				'Reply-To: ' . $name . ' <' . $email . '>',
			)
		);

		if ( ! $sent ) {
			return new WP_Error( 'uxstudio_mail_failed', __( 'Could not send the email. Please try again.', 'ux-studio' ), array( 'status' => 500 ) );
		}

		global $wpdb;
		$wpdb->insert(
			$wpdb->prefix . 'uxstudio_ai_assistant_inquiries',
			array(
				'type'       => 'email',
				'session_id' => $session_id,
				'name'       => $name,
				'email'      => $email,
				'phone'      => $phone,
				'message'    => $message,
				'page_url'   => $page_url,
				'status'     => 'new',
				'created_at' => current_time( 'mysql' ),
			)
		);

		return $this->ok( array( 'success' => true ) );
	}

	public function callback_request( WP_REST_Request $request ) {
		$session_id = sanitize_text_field( (string) $request->get_param( 'session_id' ) );
		$phone      = sanitize_text_field( (string) $request->get_param( 'phone' ) );
		$message    = sanitize_textarea_field( (string) $request->get_param( 'message' ) );
		$page_url   = esc_url_raw( (string) $request->get_param( 'page_url' ) );

		if ( '' === $phone ) {
			return new WP_Error( 'uxstudio_missing_phone', __( 'Please enter a phone number.', 'ux-studio' ), array( 'status' => 400 ) );
		}

		$phone_sanitized = (string) preg_replace( '/[\s\-()]/', '', $phone );
		if ( ! preg_match( '/^\+?\d{9,15}$/', $phone_sanitized ) ) {
			return new WP_Error( 'uxstudio_invalid_phone', __( 'Invalid phone number.', 'ux-studio' ), array( 'status' => 400 ) );
		}
		if ( ! $this->check_form_rate_limit( 'callback', $session_id ) ) {
			return new WP_Error( 'uxstudio_rate_limited', __( 'You have reached the maximum number of requests.', 'ux-studio' ), array( 'status' => 429 ) );
		}

		global $wpdb;
		$wpdb->insert(
			$wpdb->prefix . 'uxstudio_ai_assistant_inquiries',
			array(
				'type'       => 'callback',
				'session_id' => $session_id,
				'name'       => '',
				'email'      => '',
				'phone'      => $phone,
				'message'    => $message,
				'page_url'   => $page_url,
				'status'     => 'new',
				'created_at' => current_time( 'mysql' ),
			)
		);

		$to        = get_option( 'admin_email' );
		$site_name = get_bloginfo( 'name' );
		$subject   = sprintf(
			/* translators: %s: site name */
			__( '[%s] New callback request', 'ux-studio' ),
			$site_name
		);
		$body      = sprintf(
			"<h3>%s</h3>\n<p><strong>%s:</strong> %s</p>\n<p><strong>%s:</strong> %s</p>\n<p><strong>%s:</strong> %s</p>",
			esc_html__( 'New callback request from the AI chat', 'ux-studio' ),
			esc_html__( 'Phone', 'ux-studio' ),
			esc_html( $phone ),
			esc_html__( 'Page', 'ux-studio' ),
			esc_html( $page_url ),
			esc_html__( 'Note', 'ux-studio' ),
			esc_html( $message ?: '-' )
		);
		wp_mail( $to, $subject, $body, array( 'Content-Type: text/html; charset=UTF-8' ) );

		return $this->ok( array( 'success' => true ) );
	}

	public function rating( WP_REST_Request $request ) {
		$session_id = sanitize_text_field( (string) $request->get_param( 'session_id' ) );
		$rating     = absint( $request->get_param( 'rating' ) );
		$feedback   = sanitize_textarea_field( (string) $request->get_param( 'feedback' ) );

		if ( '' === $session_id || $rating < 1 || $rating > 5 ) {
			return new WP_Error( 'uxstudio_invalid_rating', __( 'Invalid rating.', 'ux-studio' ), array( 'status' => 400 ) );
		}
		if ( ! $this->check_form_rate_limit( 'rating', $session_id ) ) {
			return new WP_Error( 'uxstudio_rate_limited', __( 'You have reached the maximum number of ratings.', 'ux-studio' ), array( 'status' => 429 ) );
		}

		global $wpdb;
		$updated = $wpdb->update(
			$wpdb->prefix . 'uxstudio_ai_assistant_conversations',
			array(
				'rating'          => $rating,
				'rating_feedback' => $feedback ?: null,
				'updated_at'      => current_time( 'mysql' ),
			),
			array( 'session_id' => $session_id )
		);

		if ( false === $updated ) {
			return new WP_Error( 'uxstudio_rating_failed', __( 'Could not save the rating.', 'ux-studio' ), array( 'status' => 500 ) );
		}

		return $this->ok( array( 'success' => true ) );
	}

	/**
	 * Active FAQs for the widget's quick-suggestion chips, scoped to the
	 * public chat target. No contracted "list all" method exists on
	 * FaqManager (only search()), so this reads the shared table directly.
	 */
	public function faqs(): WP_REST_Response {
		global $wpdb;
		$table = $wpdb->prefix . 'uxstudio_ai_assistant_faqs';

		$rows = $wpdb->get_results(
			"SELECT question, answer FROM {$table} WHERE is_active = 1 AND use_public = 1 ORDER BY sort_order ASC, id ASC LIMIT 50" // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		);

		return $this->ok( $rows ?: array() );
	}

	public function product_card( WP_REST_Request $request ) {
		$id = absint( $request->get_param( 'id' ) );

		if ( ! ProductIndexer::is_woocommerce_active() ) {
			return new WP_Error( 'uxstudio_woocommerce_inactive', __( 'WooCommerce is not active.', 'ux-studio' ), array( 'status' => 404 ) );
		}

		$product = ProductIndexer::get_by_product_id( $id );
		if ( null === $product ) {
			return new WP_Error( 'uxstudio_product_not_found', __( 'Product not found.', 'ux-studio' ), array( 'status' => 404 ) );
		}

		return $this->ok( $product );
	}

	// ─── Admin handlers ──────────────────────────────────────────────

	public function list_conversations( WP_REST_Request $request ): WP_REST_Response {
		$page     = max( 1, absint( $request->get_param( 'page' ) ) ?: 1 );
		$per_page = absint( $request->get_param( 'per_page' ) ) ?: 20;
		$filters  = array();
		if ( null !== $request->get_param( 'rating' ) ) {
			$filters['rating'] = absint( $request->get_param( 'rating' ) );
		}

		$engine = new ChatEngine();

		return $this->ok(
			$engine->get_conversations( $page, $per_page, $filters ),
			array(
				'total'          => $engine->get_conversation_count( $filters ),
				'average_rating' => $engine->get_average_rating(),
			)
		);
	}

	public function get_conversation( WP_REST_Request $request ) {
		$id           = absint( $request->get_param( 'id' ) );
		$conversation = ( new ChatEngine() )->get_conversation( $id );

		if ( null === $conversation ) {
			return new WP_Error( 'uxstudio_conversation_not_found', __( 'Conversation not found.', 'ux-studio' ), array( 'status' => 404 ) );
		}

		return $this->ok( $conversation );
	}

	public function delete_conversation( WP_REST_Request $request ) {
		$id      = absint( $request->get_param( 'id' ) );
		$deleted = ( new ChatEngine() )->delete_conversation( $id );

		if ( ! $deleted ) {
			return new WP_Error( 'uxstudio_conversation_not_found', __( 'Conversation not found.', 'ux-studio' ), array( 'status' => 404 ) );
		}

		return $this->ok( array( 'deleted' => true ) );
	}

	public function bulk_delete_conversations( WP_REST_Request $request ): WP_REST_Response {
		$ids     = array_map( 'absint', (array) $request->get_param( 'ids' ) );
		$deleted = ( new ChatEngine() )->bulk_delete_conversations( $ids );

		return $this->ok( array( 'deleted' => $deleted ) );
	}

	// ─── Helpers ─────────────────────────────────────────────────────

	/**
	 * Emits a JSON error and exits - used by chat() before SSE headers are
	 * sent, so early failures (validation, rate limit) stay plain JSON.
	 */
	private function send_json_error( string $message ): void {
		header( 'Content-Type: application/json' );
		echo wp_json_encode( array( 'error' => $message ) );
		exit;
	}

	/**
	 * Sliding-window rate limit for /chat: primarily per-IP-hash (session id
	 * is client-controlled and can be rotated on every request, so a limit
	 * bound only to it would be trivially bypassable), with a secondary
	 * per-session cap. The raw IP is never stored, only a salted hash.
	 */
	private function check_chat_rate_limit( string $session_id ): bool {
		$ip     = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : '';
		$ip_key = 'uxstudio_ais_rl_ip_' . md5( ( $ip ?: 'unknown' ) . wp_salt() );
		$ip_count = (int) get_transient( $ip_key );

		$session_key   = 'uxstudio_ais_rl_session_' . md5( $session_id . wp_salt() );
		$session_count = (int) get_transient( $session_key );

		if ( $ip_count >= self::CHAT_IP_LIMIT || $session_count >= self::CHAT_SESSION_LIMIT ) {
			return false;
		}

		set_transient( $ip_key, $ip_count + 1, self::CHAT_IP_WINDOW );
		set_transient( $session_key, $session_count + 1, self::CHAT_IP_WINDOW );

		return true;
	}

	/**
	 * Per-hour cap (per session-hash, falling back to IP-hash) for the
	 * lightweight public write endpoints (contact/callback/rating).
	 */
	private function check_form_rate_limit( string $scope, string $session_id ): bool {
		$ip  = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : '';
		$key = 'uxstudio_ais_rl_' . $scope . '_' . md5( ( $session_id ?: $ip ) . wp_salt() );

		$count = (int) get_transient( $key );
		if ( $count >= self::FORM_HOURLY_LIMIT ) {
			return false;
		}
		set_transient( $key, $count + 1, HOUR_IN_SECONDS );

		return true;
	}
}
