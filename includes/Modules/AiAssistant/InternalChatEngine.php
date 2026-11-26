<?php
/**
 * Orchestrates one internal (admin-side) chat turn: builds RAG/knowledge/guide
 * context, streams the AI reply, persists to uxstudio_ai_assistant_chat_history.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\AiAssistant;

use Ux1\AiCore\Image\ImageTool;
use UxStudio\Core\Settings;
use UxStudio\Modules\AiAssistant\Providers\AiProviderInterface;
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

	/**
	 * The message list comes from the client, so it is bounded before it is
	 * sent to the provider or stored: at most this many messages, each cut to
	 * MAX_MESSAGE_CHARS characters.
	 */
	private const MAX_STORED_MESSAGES = 200;
	private const MAX_MESSAGE_CHARS   = 20000;

	/** Tool-calling round trips of one image-enabled chat turn (the image tool, then the final answer). */
	private const MAX_TOOL_ROUNDS = 4;

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
	 * @param callable|null                                  $on_images       function( array $images ): void - called with the display data of images created in this turn (image tool only).
	 * @return array{response:string,conversation_id:int,images:array}
	 */
	public function process_message( array $messages, int $conversation_id, callable $on_chunk, ?callable $on_images = null ): array {
		$user_id = get_current_user_id();

		// Optional provider/model override for the internal chatbot (legacy chatbot_provider/chatbot_model).
		$provider_id = (string) self::settings()->get( 'chatbot_provider', '' );
		if ( '' === $provider_id || ! isset( ProviderFactory::get_all_providers()[ $provider_id ] ) ) {
			$provider_id = (string) self::settings()->get( 'default_provider', 'claude' );
		}
		$provider = ProviderFactory::create( $provider_id );
		$model    = AssistantConfig::text( 'chatbot_model' );
		if ( '' === $model || $provider_id !== (string) self::settings()->get( 'chatbot_provider', '' ) ) {
			$model = $this->get_model( $provider_id );
		}

		$system_prompt  = $this->build_persona();
		$system_prompt .= $this->build_rag_context( $messages );
		$system_prompt .= $this->build_knowledge_context( $messages );
		$system_prompt .= $this->build_guide_context();

		$chat_messages = array_slice( $this->normalize_messages( $messages ), -self::MAX_HISTORY_MESSAGES );

		$image_tools = ImageService::chat_tools( $provider_id );
		$image_ids   = array();

		if ( ! empty( $image_tools ) ) {
			// Image generation is on and the provider can call tools: a non-streaming tool loop (like the public
			// widget's MCP mode); the whole reply arrives as one chunk.
			$system_prompt .= $this->build_image_instructions();
			$full_response  = $this->run_image_tool_turn( $provider, $provider_id, $model, $chat_messages, $system_prompt, $image_tools, $image_ids );
			$on_chunk( $full_response );
			if ( ! empty( $image_ids ) && null !== $on_images ) {
				$on_images( $this->describe_images( $image_ids ) );
			}
		} else {
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
		}

		$history_messages  = $this->normalize_messages( $messages, true );
		$assistant_message = array(
			'role'    => 'assistant',
			'content' => $full_response,
		);
		if ( ! empty( $image_ids ) ) {
			$assistant_message['images'] = $image_ids;
		}
		$history_messages[] = $assistant_message;

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
			'images'          => $this->describe_images( $image_ids ),
		);
	}

	/**
	 * Tool-calling loop with the shared "generate_image" tool. The model never sees (or returns) an image URL: the tool
	 * result only confirms success, and the images are shown from the attachment ids collected here.
	 *
	 * @param array<int, array{role:string,content:string}> $chat_messages Trimmed history.
	 * @param array<int, array<string, mixed>>               $tools         Tool definitions offered (only generate_image).
	 * @param int[]                                          $image_ids     Out: ids of the attachments created in this turn.
	 */
	private function run_image_tool_turn( AiProviderInterface $provider, string $provider_id, string $model, array $chat_messages, string $system_prompt, array $tools, array &$image_ids ): string {
		$messages     = $chat_messages;
		$total_input  = 0;
		$total_output = 0;
		$text         = '';
		$service      = null;

		for ( $round = 0; $round < self::MAX_TOOL_ROUNDS; $round++ ) {
			$result = $provider->chat_with_tools(
				$messages,
				$model,
				$tools,
				array(
					'system'     => $system_prompt,
					'max_tokens' => 4096,
				)
			);

			$total_input  += (int) $result['input_tokens'];
			$total_output += (int) $result['output_tokens'];

			if ( empty( $result['tool_calls'] ) ) {
				$text = (string) ( $result['content'] ?? '' );
				break;
			}

			$messages[]   = $result['assistant_message'];
			$tool_results = array();
			foreach ( $result['tool_calls'] as $tool_call ) {
				if ( ImageTool::NAME !== $tool_call['name'] ) {
					$content = wp_json_encode( array( 'success' => false, 'error' => 'Unknown tool.' ) );
				} elseif ( count( $image_ids ) >= ImageService::MAX_CHAT_IMAGES_PER_TURN ) {
					$content = wp_json_encode( array( 'success' => false, 'error' => 'Image limit for this reply reached. Do not generate more images now.' ) );
				} else {
					try {
						$service     = $service ?? new ImageService();
						$image       = $service->generate_for_chat( (array) $tool_call['arguments'] );
						$image_ids[] = (int) $image['attachment_id'];
						$content     = wp_json_encode(
							array(
								'success' => true,
								'message' => 'The image was created and is shown to the user below your reply. Do not write its URL or markdown for it; just say briefly what you made.',
							)
						);
					} catch ( \RuntimeException $e ) {
						// Logged like the Content tab does, otherwise the admin never learns why chat images fail
						// (e.g. an invalid image key) - the model only paraphrases the error to the user.
						ErrorLogger::log(
							array(
								'error_type'    => ErrorLogger::TYPE_PROVIDER,
								'error_message' => $e->getMessage(),
								'context'       => array(
									'scope'   => 'image',
									'origin'  => 'internal chat generate_image',
									'user_id' => get_current_user_id(),
								),
							)
						);
						$content = wp_json_encode( array( 'success' => false, 'error' => $e->getMessage() ) );
					}
				}
				$tool_results[] = array(
					'tool_call_id' => $tool_call['id'],
					'content'      => (string) $content,
				);
			}
			foreach ( $provider->format_tool_results( $tool_results ) as $formatted_message ) {
				$messages[] = $formatted_message;
			}
		}

		if ( $total_input > 0 || $total_output > 0 ) {
			UsageTracker::log( $provider_id, $model, 'internal_chat', $total_input, $total_output );
		}

		if ( '' === $text ) {
			$text = empty( $image_ids ) ? __( 'Sorry, I was unable to process that request.', 'ux-studio' ) : __( 'Here is the image.', 'ux-studio' );
		}

		return $text;
	}

	private function build_image_instructions(): string {
		return "\n\nYou can create images with the generate_image tool, but only when the user explicitly asks for an image. "
			. "Each image costs money: never generate one on your own initiative, and ask a short clarifying question first when the request is vague. "
			. "The created image is shown to the user automatically.\n";
	}

	/**
	 * Display data of the generated attachments (ids that are not generated images are dropped).
	 *
	 * @param int[] $ids Attachment ids.
	 * @return array<int, array{id:int,url:string,alt:string,edit_url:string}>
	 */
	private function describe_images( array $ids ): array {
		$described = array();
		foreach ( $ids as $id ) {
			$data = ImageService::describe( (int) $id );
			if ( null !== $data ) {
				$described[] = $data;
			}
		}
		return $described;
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
		$item = ( new ChatHistory() )->get( $conversation_id, $user_id );
		if ( null !== $item && ! empty( $item['messages'] ) ) {
			$item['messages'] = ImageService::expand_message_images( (array) $item['messages'] );
		}
		return $item;
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
		return "You are the internal AI assistant built into the UX1 Studio admin panel.\n"
			. "You help logged-in editors and administrators of this WordPress site with their work: "
			. "content editing, site configuration, and how to use UX1 Studio's own modules.\n"
			. "Be concise, professional and practical. When relevant, mention which admin screen or "
			. "UX1 Studio module the user should use.\n"
			. "Respond in the same language the user writes in.\n"
			. ( '' !== ( $extra = trim( wp_strip_all_tags( (string) self::settings()->get( 'chatbot_system_prompt', '' ) ) ) ) ? "\n" . $extra . "\n" : '' );
	}

	/**
	 * RAG context from vectors trained for the internal chat target.
	 *
	 * @param array<int, array{role:string,content:string}> $messages Message history.
	 */
	private function build_rag_context( array $messages ): string {
		if ( ! AssistantConfig::flag( 'internal_rag_enabled', true ) ) {
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
			error_log( 'UX1 Studio internal chat RAG: ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
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
		if ( ! AssistantConfig::flag( 'internal_rag_enabled', true ) ) {
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
	 * UX1 Studio's own "Guide" module (includes/Modules/Guide) exposes a
	 * getting-started checklist for this site. Ported from the legacy plugin's
	 * buildGuideContext(), which pulled from that plugin's separate "Návod"
	 * module/docs - there is no such module here, so this now surfaces
	 * UX1 Studio's own setup guide/progress instead, which is the closest
	 * equivalent "how does this admin panel work" context available.
	 */
	private function build_guide_context(): string {
		if ( ! AssistantConfig::flag( 'internal_guide_enabled', true ) ) {
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

		return "\n\n=== UX1 STUDIO SETUP GUIDE (for the administrator) ===\n"
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
	 * @param bool              $keep_images Keep the validated image ids of assistant turns (storage, not the provider).
	 * @return array<int, array{role:string,content:string,images?:int[]}>
	 */
	private function normalize_messages( array $messages, bool $keep_images = false ): array {
		$clean = array();
		foreach ( $messages as $message ) {
			$role    = is_array( $message ) ? ( $message['role'] ?? '' ) : '';
			$content = is_array( $message ) ? ( $message['content'] ?? '' ) : '';
			if ( in_array( $role, array( 'user', 'assistant' ), true ) && is_string( $content ) && '' !== $content ) {
				$entry = array(
					'role'    => $role,
					'content' => mb_substr( $content, 0, self::MAX_MESSAGE_CHARS ),
				);
				// For storage only (never sent to the provider): images the chat tool created for this user.
				if ( $keep_images && 'assistant' === $role ) {
					$images = ImageService::owned_ids( $message['images'] ?? null, get_current_user_id() );
					if ( ! empty( $images ) ) {
						$entry['images'] = $images;
					}
				}
				$clean[] = $entry;
			}
		}
		return array_slice( $clean, -self::MAX_STORED_MESSAGES );
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
