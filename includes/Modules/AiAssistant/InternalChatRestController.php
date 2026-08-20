<?php
/**
 * REST endpoints for the internal (admin-side) AI assistant chat.
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
 * All routes require at least 'edit_posts' (any editor/admin able to publish
 * content may use the assistant). Reading/deleting another user's saved
 * conversation additionally requires 'manage_options' - enforced in the
 * handlers below, since Controller::route() only gates on a single capability
 * and history rows are otherwise private per-user data.
 *
 *   POST   uxstudio/v1/ai-assistant/internal-chat                 - SSE-streamed AI reply
 *   GET    uxstudio/v1/ai-assistant/internal-chat/history          - current user's conversations
 *   GET    uxstudio/v1/ai-assistant/internal-chat/history/{id}     - one conversation (own, or any if manage_options)
 *   DELETE uxstudio/v1/ai-assistant/internal-chat/history/{id}     - delete (own, or any if manage_options)
 *   GET    uxstudio/v1/ai-assistant/internal-chat/limits           - current usage limits
 */
final class InternalChatRestController extends Controller {

	public function register_routes(): void {
		$this->route(
			'/ai-assistant/internal-chat',
			'POST',
			array( $this, 'chat' ),
			array(
				'messages'        => array( 'required' => true, 'type' => 'array' ),
				'conversation_id' => array( 'required' => false, 'type' => 'integer', 'sanitize_callback' => 'absint' ),
			),
			'edit_posts'
		);

		$this->route(
			'/ai-assistant/internal-chat/history',
			'GET',
			array( $this, 'history' ),
			array(),
			'edit_posts'
		);

		$this->route(
			'/ai-assistant/internal-chat/history/(?P<id>\d+)',
			'GET',
			array( $this, 'history_get' ),
			array(
				'id' => array( 'required' => true, 'type' => 'integer' ),
			),
			'edit_posts'
		);

		$this->route(
			'/ai-assistant/internal-chat/history/(?P<id>\d+)',
			'DELETE',
			array( $this, 'history_delete' ),
			array(
				'id' => array( 'required' => true, 'type' => 'integer' ),
			),
			'edit_posts'
		);

		$this->route(
			'/ai-assistant/internal-chat/limits',
			'GET',
			array( $this, 'limits' ),
			array(),
			'edit_posts'
		);
	}

	/**
	 * Streams the AI reply as Server-Sent Events, same wire format as the
	 * public widget's ChatRestController::chat() (see that method for why
	 * this bypasses the ok()/WP_REST_Response envelope: the response must be
	 * flushed chunk by chunk while the provider is still generating text).
	 */
	public function chat( WP_REST_Request $request ): void {
		$messages        = (array) $request->get_param( 'messages' );
		$conversation_id = absint( $request->get_param( 'conversation_id' ) );

		if ( empty( $messages ) ) {
			header( 'Content-Type: application/json' );
			echo wp_json_encode( array( 'error' => __( 'Message list is empty.', 'ux-studio' ) ) );
			exit;
		}

		// Existing conversations may only be continued by their owner, unless
		// the current user can manage all conversations.
		if ( $conversation_id > 0 && ! current_user_can( 'manage_options' ) ) {
			$owned = ( new InternalChatEngine() )->get_history_item( $conversation_id, get_current_user_id() );
			if ( null === $owned ) {
				header( 'Content-Type: application/json' );
				echo wp_json_encode( array( 'error' => __( 'Conversation not found.', 'ux-studio' ) ) );
				exit;
			}
		}

		$limit_error = UsageLimiter::check();
		if ( null !== $limit_error ) {
			header( 'Content-Type: text/event-stream' );
			header( 'Cache-Control: no-cache' );
			echo 'data: ' . wp_json_encode( array( 'error' => $limit_error ) ) . "\n\n";
			flush();
			exit;
		}

		header( 'Content-Type: text/event-stream' );
		header( 'Cache-Control: no-cache' );
		header( 'Connection: keep-alive' );
		header( 'X-Accel-Buffering: no' );

		if ( ob_get_level() ) {
			ob_end_clean();
		}

		try {
			$engine = new InternalChatEngine();
			$result = $engine->process_message(
				$messages,
				$conversation_id,
				function ( string $chunk ) {
					echo 'data: ' . wp_json_encode( array( 'content' => $chunk ) ) . "\n\n";
					if ( ob_get_level() ) {
						ob_flush();
					}
					flush();
				}
			);

			echo 'data: ' . wp_json_encode(
				array(
					'done'            => true,
					'conversation_id' => $result['conversation_id'],
				)
			) . "\n\n";
			flush();
		} catch ( \Throwable $e ) {
			ErrorLogger::log(
				array(
					'error_type'    => ErrorLogger::TYPE_PROVIDER,
					'error_message' => $e->getMessage(),
					'context'       => array(
						'scope'            => 'internal_chat',
						'user_id'          => get_current_user_id(),
						'exception_class'  => get_class( $e ),
					),
				)
			);
			echo 'data: ' . wp_json_encode( array( 'error' => $e->getMessage() ) ) . "\n\n";
			flush();
		}

		exit;
	}

	/**
	 * The current user's own saved conversations.
	 */
	public function history(): WP_REST_Response {
		$list = ( new InternalChatEngine() )->get_history_list( get_current_user_id() );
		return $this->ok( $list );
	}

	/**
	 * One conversation - own, or any conversation if the caller manages options.
	 */
	public function history_get( WP_REST_Request $request ) {
		$id = absint( $request->get_param( 'id' ) );

		$conversation = $this->find_conversation( $id );
		if ( null === $conversation ) {
			return new WP_Error( 'uxstudio_conversation_not_found', __( 'Conversation not found.', 'ux-studio' ), array( 'status' => 404 ) );
		}

		return $this->ok( $conversation );
	}

	/**
	 * Delete a conversation - own, or any conversation if the caller manages options.
	 */
	public function history_delete( WP_REST_Request $request ) {
		$id = absint( $request->get_param( 'id' ) );

		$conversation = $this->find_conversation( $id );
		if ( null === $conversation ) {
			return new WP_Error( 'uxstudio_conversation_not_found', __( 'Conversation not found.', 'ux-studio' ), array( 'status' => 404 ) );
		}

		$owner_id = current_user_can( 'manage_options' ) ? (int) $conversation['user_id'] : get_current_user_id();
		$deleted  = ( new InternalChatEngine() )->delete_history_item( $id, $owner_id );

		if ( ! $deleted ) {
			return new WP_Error( 'uxstudio_conversation_not_found', __( 'Conversation not found.', 'ux-studio' ), array( 'status' => 404 ) );
		}

		return $this->ok( array( 'deleted' => true ) );
	}

	/**
	 * Current usage limits for the logged-in user.
	 */
	public function limits(): WP_REST_Response {
		return $this->ok( ( new InternalChatEngine() )->get_user_limits() );
	}

	/**
	 * Looks up a conversation by id, scoped to the current user unless the
	 * caller can manage_options (in which case any user's conversation may be
	 * read/deleted - matches BackendController::checkAdminPermission() in the
	 * legacy module).
	 *
	 * @return array{id:int,user_id:int,title:string,messages:array,provider:string,model:string}|null
	 */
	private function find_conversation( int $id ): ?array {
		$engine = new InternalChatEngine();

		if ( current_user_can( 'manage_options' ) ) {
			global $wpdb;
			$table = $wpdb->prefix . 'uxstudio_ai_assistant_chat_history';
			$row   = $wpdb->get_row(
				$wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				ARRAY_A
			);
			if ( ! $row ) {
				return null;
			}
			$row['messages'] = json_decode( (string) $row['messages'], true ) ?: array();
			return $row;
		}

		// ChatHistory::get() already scopes the query to this user id, and
		// SELECT * includes the user_id column, so no extra assignment is needed.
		return $engine->get_history_item( $id, get_current_user_id() );
	}
}
