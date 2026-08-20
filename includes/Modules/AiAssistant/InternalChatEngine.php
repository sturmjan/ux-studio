<?php
/**
 * Orchestrates one internal (admin-side) chat turn: builds RAG/knowledge/guide
 * context, streams the AI reply, persists to uxstudio_ai_assistant_chat_history.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\AiAssistant;

use UxStudio\Core\Settings;
use UxStudio\Modules\AiAssistant\Rag\VectorSearch;
use UxStudio\Modules\AiAssistant\Rag\VectorStore;
use UxStudio\Modules\Guide\Module as GuideModule;

defined( 'ABSPATH' ) || exit;

/**
 * Separate from ChatEngine (public widget, uxstudio_ai_assistant_conversations)
 * on purpose - this persists to uxstudio_ai_assistant_chat_history via
 * ChatHistory, is always scoped to the logged-in user, and (unlike the public
 * widget) never runs the ChatTools tool-calling loop: the internal assistant's
 * context comes entirely from RAG + knowledge base + the Guide module's
 * setup checklist, injected straight into the system prompt. Simpler and
 * cheaper, and avoids duplicating ChatTools' visitor/product-oriented tools
 * for an admin audience that doesn't need them.
 */
final class InternalChatEngine {

	/**
	 * Conversation history is capped to the last N messages sent to the
	 * provider, to bound token spend on long-running chats.
	 */
	private const MAX_HISTORY_MESSAGES = 20;

	private static ?Settings $settings = null;

	private static function settings(): Settings {
		if ( null === self::$settings ) {
			self::$settings = new Settings( 'uxstudio_ai_assistant' );
		}
		return self::$settings;
	}

	/**
	 * Streams one reply for the logged-in user and persists the full
	 * conversation to the per-user chat history table.
	 *
	 * @param array<int, array{role:string,content:string}> $messages        Full message list, latest user message already appended by the caller.
	 * @param int                                            $conversation_id Existing chat_history row id to update, or 0 for a new one.
	 * @param callable                                       $on_chunk        function( string $text ): void - called for each streamed text fragment.
	 * @return array{response:string,conversation_id:int}
	 */
	public function process_message( array $messages, int $conversation_id, callable $on_chunk ): array {
		$user_id = get_current_user_id();

		$provider_id = (string) self::settings()->get( 'default_provider', 'claude' );
		$provider    = ProviderFactory::create( $provider_id );
		$model       = $this->get_model( $provider_id );

		$system_prompt  = $this->build_persona();
		$system_prompt .= $this->build_rag_context( $messages );
		$system_prompt .= $this->build_knowledge_context( $messages );
		$system_prompt .= $this->build_guide_context();

		$chat_messages = array_slice( $this->normalize_messages( $messages ), -self::MAX_HISTORY_MESSAGES );

		$full_response = '';
		$provider->stream_chat(
			$chat_messages,
			$model,
			function ( string $chunk ) use ( &$full_response, $on_chunk ) {
				$full_response .= $chunk;
				$on_chunk( $chunk );
			},
			array(
				'system'      => $system_prompt,
				'max_tokens'  => 4096,
				'temperature' => 0.7,
			)
		);

		$usage = $provider->get_last_stream_usage();
		if ( $usage['input_tokens'] > 0 || $usage['output_tokens'] > 0 ) {
			UsageTracker::log( $provider_id, $model, 'internal_chat', $usage['input_tokens'], $usage['output_tokens'] );
		}

		$history_messages   = $this->normalize_messages( $messages );
		$history_messages[] = array(
			'role'    => 'assistant',
			'content' => $full_response,
		);

		$saved_id = ( new ChatHistory() )->save(
			$user_id,
			$history_messages,
			$provider_id,
			$model,
			$conversation_id > 0 ? $conversation_id : null
		);

		return array(
			'response'        => $full_response,
			'conversation_id' => $saved_id,
		);
	}

	/**
	 * @return array<int, array{id:int,title:string,provider:string,model:string,created_at:string,updated_at:string}>
	 */
	public function get_history_list( int $user_id ): array {
		return ( new ChatHistory() )->list( $user_id );
	}

	/**
	 * @return array{id:int,title:string,messages:array,provider:string,model:string}|null
	 */
	public function get_history_item( int $conversation_id, int $user_id ): ?array {
		return ( new ChatHistory() )->get( $conversation_id, $user_id );
	}

	public function delete_history_item( int $conversation_id, int $user_id ): bool {
		return ( new ChatHistory() )->delete( $conversation_id, $user_id );
	}

	/**
	 * @return array<string, array{used:int,limit:int,percent:int,remaining:int}>
	 */
	public function get_user_limits(): array {
		return UsageLimiter::get_status();
	}

	/**
	 * Persona/intro for the internal assistant - distinct from the public
	 * widget's customer-support persona (see PromptTemplates::intro()): this
	 * one is addressed to a logged-in editor/administrator working in wp-admin.
	 */
	private function build_persona(): string {
		return "You are the internal AI assistant built into the UX Studio admin panel.\n"
			. "You help logged-in editors and administrators of this WordPress site with their work: "
			. "content editing, site configuration, and how to use UX Studio's own modules.\n"
			. "Be concise, professional and practical. When relevant, mention which admin screen or "
			. "UX Studio module the user should use.\n"
			. "Respond in the same language the user writes in.\n";
	}

	/**
	 * RAG context from vectors trained for the internal chat target.
	 *
	 * @param array<int, array{role:string,content:string}> $messages Message history.
	 */
	private function build_rag_context( array $messages ): string {
		if ( ! (bool) self::settings()->get( 'internal_rag_enabled', true ) ) {
			return '';
		}

		$last_user = $this->last_user_message( $messages );
		if ( '' === $last_user ) {
			return '';
		}

		if ( ! VectorSearch::is_available( VectorStore::TARGET_INTERNAL ) ) {
			return '';
		}

		try {
			$search  = new VectorSearch( VectorStore::TARGET_INTERNAL );
			$results = $search->hybrid_search( $last_user, 5 );
			if ( empty( $results ) ) {
				return '';
			}
			return "\n\n=== INTERNAL KNOWLEDGE (RAG) ===\n"
				. "The following was found via vector search and is highly relevant to the query:\n\n"
				. $search->format_for_context( $results );
		} catch ( \Throwable $e ) {
			error_log( 'UX Studio internal chat RAG: ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			return '';
		}
	}

	/**
	 * Knowledge base + FAQ entries flagged for the internal chat target
	 * (FULLTEXT search, complementary to the vector RAG search above).
	 *
	 * @param array<int, array{role:string,content:string}> $messages Message history.
	 */
	private function build_knowledge_context( array $messages ): string {
		if ( ! (bool) self::settings()->get( 'internal_rag_enabled', true ) ) {
			return '';
		}

		$query = $this->last_user_message( $messages );
		if ( '' === $query ) {
			return '';
		}

		$parts = array();

		try {
			foreach ( KnowledgeManager::search( $query, 4, VectorStore::TARGET_INTERNAL ) as $row ) {
				$title   = (string) ( $row['title'] ?? '' );
				$content = trim( (string) ( $row['content'] ?? '' ) );
				if ( '' !== $content ) {
					$parts[] = ( '' !== $title ? "### {$title}\n" : '' ) . $content;
				}
			}
		} catch ( \Throwable $e ) {
			// Ignore - knowledge base is a best-effort context source.
		}

		try {
			foreach ( FaqManager::search( $query, 4, VectorStore::TARGET_INTERNAL ) as $faq ) {
				$answer = trim( (string) ( $faq['answer'] ?? '' ) );
				if ( '' !== $answer ) {
					$parts[] = 'FAQ: ' . ( $faq['question'] ?? '' ) . "\n" . $answer;
				}
			}
		} catch ( \Throwable $e ) {
			// Ignore.
		}

		if ( empty( $parts ) ) {
			return '';
		}

		return "\n\n=== INTERNAL KNOWLEDGE BASE & FAQ ===\n"
			. "Relevant internal information (use it if it helps answer):\n\n"
			. implode( "\n\n---\n\n", $parts );
	}

	/**
	 * UX Studio's own "Guide" module (includes/Modules/Guide) exposes a
	 * getting-started checklist for this site. Ported from the legacy plugin's
	 * buildGuideContext(), which pulled from that plugin's separate "Návod"
	 * module/docs - there is no such module here, so this now surfaces
	 * UX Studio's own setup guide/progress instead, which is the closest
	 * equivalent "how does this admin panel work" context available.
	 */
	private function build_guide_context(): string {
		if ( ! (bool) self::settings()->get( 'internal_guide_enabled', true ) ) {
			return '';
		}

		if ( ! class_exists( GuideModule::class ) ) {
			return '';
		}

		try {
			$guide = new GuideModule( 'guide', array() );
			$steps = $guide->steps_with_progress();
		} catch ( \Throwable $e ) {
			return '';
		}

		if ( empty( $steps ) ) {
			return '';
		}

		$lines = array();
		foreach ( $steps as $step ) {
			$status  = ! empty( $step['completed'] ) ? '[done]' : '[pending]';
			$lines[] = sprintf( '- %s %s: %s', $status, $step['title'] ?? '', $step['description'] ?? '' );
		}

		return "\n\n=== UX STUDIO SETUP GUIDE (for the administrator) ===\n"
			. "This is the site's onboarding checklist. Use it when the admin asks how to set up or configure this site:\n\n"
			. implode( "\n", $lines );
	}

	/**
	 * @param array<int, array{role:string,content:string}> $messages Message history.
	 */
	private function last_user_message( array $messages ): string {
		foreach ( array_reverse( $messages ) as $message ) {
			$role = is_array( $message ) ? ( $message['role'] ?? '' ) : '';
			if ( 'user' === $role ) {
				$content = is_array( $message ) ? ( $message['content'] ?? '' ) : '';
				return is_string( $content ) ? trim( $content ) : '';
			}
		}
		return '';
	}

	/**
	 * Keep only well-formed user/assistant text turns before sending to the
	 * provider or persisting to history.
	 *
	 * @param array<int, mixed> $messages Raw message list.
	 * @return array<int, array{role:string,content:string}>
	 */
	private function normalize_messages( array $messages ): array {
		$clean = array();
		foreach ( $messages as $message ) {
			$role    = is_array( $message ) ? ( $message['role'] ?? '' ) : '';
			$content = is_array( $message ) ? ( $message['content'] ?? '' ) : '';
			if ( in_array( $role, array( 'user', 'assistant' ), true ) && is_string( $content ) && '' !== $content ) {
				$clean[] = array(
					'role'    => $role,
					'content' => $content,
				);
			}
		}
		return $clean;
	}

	private function get_model( string $provider_id ): string {
		$model = (string) self::settings()->get( $provider_id . '_model', '' );
		if ( '' !== $model ) {
			return $model;
		}

		$providers = ProviderFactory::get_all_providers();
		$models    = $providers[ $provider_id ]['models'] ?? array();
		return $models ? (string) array_key_first( $models ) : '';
	}
}
