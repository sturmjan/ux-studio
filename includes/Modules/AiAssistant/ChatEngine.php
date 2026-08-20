<?php
/**
 * Orchestrates one public/internal chat turn: builds RAG context, calls the
 * AI provider (streaming or tool-calling), persists the conversation.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\AiAssistant;

use UxStudio\Core\Settings;
use UxStudio\Modules\AiAssistant\Providers\AiProviderInterface;

defined( 'ABSPATH' ) || exit;

final class ChatEngine {

	public const TARGET_PUBLIC   = 'public';
	public const TARGET_INTERNAL = 'internal';

	/**
	 * Conversation history is capped to the last N messages sent to the
	 * provider, to bound token spend on long-running chats.
	 */
	private const MAX_HISTORY_MESSAGES = 20;

	/**
	 * Maximum tool-calling round trips before giving up and returning
	 * whatever text the model produced (guards against infinite tool loops).
	 */
	private const MAX_TOOL_ROUNDS = 5;

	private static ?Settings $settings = null;

	private static function settings(): Settings {
		if ( null === self::$settings ) {
			self::$settings = new Settings( 'uxstudio_ai_assistant' );
		}
		return self::$settings;
	}

	/**
	 * Processes one visitor message and streams the AI's reply through
	 * $on_chunk. Returns a summary of the turn for the REST layer to relay
	 * as final SSE events (product ids extracted from [product:ID] tags).
	 *
	 * @param callable $on_chunk function( string $text ): void - called for each streamed text fragment.
	 * @return array{response:string,product_ids:array<int,int>}
	 */
	public function process_message( string $message, string $session_id, string $page_url, callable $on_chunk, int $product_id = 0, string $chat_target = self::TARGET_PUBLIC ): array {
		global $wpdb;
		$table = $wpdb->prefix . 'uxstudio_ai_assistant_conversations';

		$conversation = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE session_id = %s", $session_id ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);

		$messages = array();
		if ( $conversation ) {
			$messages = json_decode( (string) $conversation->messages, true ) ?: array();
		}

		$context = PromptBuilder::build_context( $message, $page_url, $messages, $product_id, $chat_target );

		$messages[]    = array(
			'role'    => 'user',
			'content' => $message,
		);
		$chat_messages = array_slice( $messages, -self::MAX_HISTORY_MESSAGES );

		$providers  = $this->get_provider_order();
		$has_any_key = false;
		foreach ( $providers as $provider_id ) {
			if ( ProviderFactory::has_api_key( $provider_id ) ) {
				$has_any_key = true;
				break;
			}
		}
		if ( ! $has_any_key ) {
			ErrorLogger::log(
				array(
					'session_id'    => $session_id,
					'provider'      => $providers[0] ?? '',
					'error_type'    => ErrorLogger::TYPE_CONFIG,
					'error_message' => 'No AI provider has an API key configured.',
					'user_message'  => $message,
					'page_url'      => $page_url,
					'context'       => array( 'providers_tried' => $providers ),
				)
			);
		}

		$mcp_enabled   = (bool) self::settings()->get( 'mcp_enabled', false );
		$full_response = '';
		$last_error    = null;
		$chunks_sent   = 0;

		foreach ( $providers as $provider_id ) {
			$model = $this->get_model( $provider_id );

			try {
				$provider = ProviderFactory::create( $provider_id );

				if ( $mcp_enabled ) {
					$full_response = $this->run_tool_calling_turn( $provider, $provider_id, $model, $chat_messages, $context, $chat_target, $session_id, $message, $page_url );
					$on_chunk( $full_response );
					$chunks_sent = 1;
				} else {
					$system_prompt = PromptBuilder::build_system_prompt( $context );
					$usage_before  = 0;

					$provider->stream_chat(
						$chat_messages,
						$model,
						function ( string $chunk ) use ( &$full_response, &$chunks_sent, $on_chunk ) {
							$full_response .= $chunk;
							++$chunks_sent;
							$on_chunk( $chunk );
						},
						array(
							'system'      => $system_prompt,
							'max_tokens'  => 1024,
							'temperature' => 0.7,
						)
					);
					unset( $usage_before );

					$usage = $provider->get_last_stream_usage();
					if ( $usage['input_tokens'] > 0 || $usage['output_tokens'] > 0 ) {
						UsageTracker::log_chat( $session_id, $provider_id, $model, $usage['input_tokens'], $usage['output_tokens'] );
					}
				}

				$last_error = null;
				break;
			} catch ( \Throwable $e ) {
				$detail = ErrorLogger::parse_exception_detail( $e->getMessage() );
				ErrorLogger::log(
					array(
						'session_id'       => $session_id,
						'provider'         => $provider_id,
						'model'            => $model,
						'error_type'       => ErrorLogger::TYPE_PROVIDER,
						'error_message'    => $e->getMessage(),
						'http_status'      => $detail['http_status'],
						'response_excerpt' => $detail['excerpt'],
						'user_message'     => $message,
						'page_url'         => $page_url,
						'context'          => array(
							'has_api_key'             => ProviderFactory::has_api_key( $provider_id ),
							'chunks_sent_before_fail' => $chunks_sent,
							'exception_class'         => get_class( $e ),
						),
					)
				);
				$last_error = $e;

				if ( $chunks_sent > 0 ) {
					break;
				}
			}
		}

		if ( null !== $last_error && 0 === $chunks_sent ) {
			throw $last_error;
		}

		$messages[] = array(
			'role'    => 'assistant',
			'content' => $full_response,
		);

		$now = current_time( 'mysql' );
		if ( $conversation ) {
			$wpdb->update(
				$table,
				array(
					'messages'        => wp_json_encode( $messages ),
					'page_url'        => $page_url,
					'last_message_at' => $now,
					'updated_at'      => $now,
				),
				array( 'id' => $conversation->id )
			);
		} else {
			$wpdb->insert(
				$table,
				array(
					'session_id'         => $session_id,
					'visitor_ip'         => $this->get_visitor_ip(),
					'visitor_user_agent' => substr( (string) ( $_SERVER['HTTP_USER_AGENT'] ?? '' ), 0, 500 ),
					'page_url'           => $page_url,
					'messages'           => wp_json_encode( $messages ),
					'status'             => 'active',
					'gdpr_consent'       => 1,
					'last_message_at'    => $now,
					'created_at'         => $now,
					'updated_at'         => $now,
				)
			);
		}

		$product_ids = array();
		if ( preg_match_all( '/\[product:(\d+)\]/', $full_response, $matches ) ) {
			$product_ids = array_map( 'intval', $matches[1] );
		}

		return array(
			'response'     => $full_response,
			'product_ids'  => $product_ids,
		);
	}

	/**
	 * Non-streaming tool-calling loop: lets the model call search_* tools
	 * (see ChatTools) before producing its final answer. Used only when the
	 * "Enable MCP (tool calling)" setting is on.
	 *
	 * @param array<int, array{role:string,content:mixed}> $chat_messages Trimmed conversation history.
	 * @param array<string, mixed>                          $context       RAG context (used only for its language, tool-calling gets its own data via tools).
	 */
	private function run_tool_calling_turn( AiProviderInterface $provider, string $provider_id, string $model, array $chat_messages, array $context, string $chat_target, string $session_id, string $message, string $page_url ): string {
		$language      = $context['language'] ?? 'cs';
		$system_prompt = PromptTemplates::intro( $language, get_bloginfo( 'name' ) ) . "\n" . PromptTemplates::rules( $language );
		$custom_prompt = (string) self::settings()->get( 'custom_system_prompt', '' );
		if ( '' !== $custom_prompt ) {
			$system_prompt .= "\n{$custom_prompt}\n";
		}

		$tools    = ChatTools::get_definitions( $chat_target );
		$messages = $chat_messages;

		$total_input  = 0;
		$total_output = 0;
		$final_text   = '';

		for ( $round = 0; $round < self::MAX_TOOL_ROUNDS; $round++ ) {
			$result = $provider->chat_with_tools(
				$messages,
				$model,
				$tools,
				array(
					'system'     => $system_prompt,
					'max_tokens' => 1024,
				)
			);

			$total_input  += (int) $result['input_tokens'];
			$total_output += (int) $result['output_tokens'];

			if ( empty( $result['tool_calls'] ) ) {
				$final_text = (string) ( $result['content'] ?? '' );
				break;
			}

			$messages[]   = $result['assistant_message'];
			$tool_results = array();
			foreach ( $result['tool_calls'] as $tool_call ) {
				$tool_results[] = array(
					'tool_call_id' => $tool_call['id'],
					'content'      => ChatTools::execute( $tool_call['name'], (array) $tool_call['arguments'], $chat_target ),
				);
			}
			foreach ( $provider->format_tool_results( $tool_results ) as $formatted_message ) {
				$messages[] = $formatted_message;
			}
		}

		if ( $total_input > 0 || $total_output > 0 ) {
			UsageTracker::log_chat( $session_id, $provider_id, $model, $total_input, $total_output );
		}

		if ( '' === $final_text ) {
			$final_text = 'cs' === $language
				? 'Omlouvám se, nepodařilo se mi zpracovat požadavek.'
				: 'Sorry, I was unable to process that request.';
		}

		return $final_text;
	}

	/**
	 * Records (or backfills) GDPR consent for a session.
	 */
	public function record_gdpr_consent( string $session_id ): void {
		global $wpdb;
		$table = $wpdb->prefix . 'uxstudio_ai_assistant_conversations';
		$now   = current_time( 'mysql' );

		$exists = $wpdb->get_var(
			$wpdb->prepare( "SELECT id FROM {$table} WHERE session_id = %s", $session_id ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);

		if ( $exists ) {
			$wpdb->update(
				$table,
				array(
					'gdpr_consent' => 1,
					'updated_at'   => $now,
				),
				array( 'session_id' => $session_id )
			);
			return;
		}

		$wpdb->insert(
			$table,
			array(
				'session_id'         => $session_id,
				'visitor_ip'         => $this->get_visitor_ip(),
				'visitor_user_agent' => substr( (string) ( $_SERVER['HTTP_USER_AGENT'] ?? '' ), 0, 500 ),
				'messages'           => '[]',
				'status'             => 'active',
				'gdpr_consent'       => 1,
				'created_at'         => $now,
				'updated_at'         => $now,
			)
		);
	}

	/**
	 * @param array<string, int> $filters Optional filter: rating (0-5, 0 = unrated).
	 * @return array<int, object>
	 */
	public function get_conversations( int $page = 1, int $per_page = 20, array $filters = array() ): array {
		global $wpdb;
		$table  = $wpdb->prefix . 'uxstudio_ai_assistant_conversations';
		$offset = max( 0, ( $page - 1 ) * $per_page );

		list( $where, $params ) = $this->build_conversation_filters( $filters );
		$params[] = $per_page;
		$params[] = $offset;

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, session_id, visitor_ip, page_url, status, gdpr_consent, rating, rating_feedback,
						JSON_LENGTH(messages) as message_count, created_at, updated_at
				 FROM {$table}
				 WHERE {$where}
				 ORDER BY updated_at DESC
				 LIMIT %d OFFSET %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				...$params
			)
		);
	}

	/**
	 * @param array<string, int> $filters Optional filter: rating.
	 */
	public function get_conversation_count( array $filters = array() ): int {
		global $wpdb;
		$table = $wpdb->prefix . 'uxstudio_ai_assistant_conversations';

		list( $where, $params ) = $this->build_conversation_filters( $filters );

		if ( ! empty( $params ) ) {
			return (int) $wpdb->get_var(
				$wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE {$where}", ...$params ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			);
		}
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE {$where}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	public function get_average_rating(): ?float {
		global $wpdb;
		$table = $wpdb->prefix . 'uxstudio_ai_assistant_conversations';
		$avg   = $wpdb->get_var( "SELECT AVG(rating) FROM {$table} WHERE rating > 0" );
		return null !== $avg ? round( (float) $avg, 1 ) : null;
	}

	public function get_conversation( int $id ): ?object {
		global $wpdb;
		$table = $wpdb->prefix . 'uxstudio_ai_assistant_conversations';
		$row   = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);
		return $row ?: null;
	}

	public function delete_conversation( int $id ): bool {
		global $wpdb;
		$table = $wpdb->prefix . 'uxstudio_ai_assistant_conversations';
		return (bool) $wpdb->delete( $table, array( 'id' => $id ), array( '%d' ) );
	}

	/**
	 * @param array<int, int> $ids Conversation ids.
	 */
	public function bulk_delete_conversations( array $ids ): int {
		global $wpdb;
		$table = $wpdb->prefix . 'uxstudio_ai_assistant_conversations';
		$ids   = array_values( array_filter( array_map( 'intval', $ids ) ) );
		if ( empty( $ids ) ) {
			return 0;
		}
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		return (int) $wpdb->query(
			$wpdb->prepare( "DELETE FROM {$table} WHERE id IN ({$placeholders})", ...$ids ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);
	}

	/**
	 * @param array<string, int> $filters Optional filter: rating.
	 * @return array{0:string,1:array<int,int>}
	 */
	private function build_conversation_filters( array $filters ): array {
		$where  = '1=1';
		$params = array();

		if ( isset( $filters['rating'] ) ) {
			$rating = (int) $filters['rating'];
			if ( 0 === $rating ) {
				$where .= ' AND rating = 0';
			} elseif ( $rating >= 1 && $rating <= 5 ) {
				$where   .= ' AND rating = %d';
				$params[] = $rating;
			}
		}

		return array( $where, $params );
	}

	/**
	 * Ordered provider ids to try: the configured default first, then any
	 * other provider that has an API key configured (auto-fallback).
	 *
	 * @return array<int, string>
	 */
	private function get_provider_order(): array {
		$primary = (string) self::settings()->get( 'default_provider', 'claude' );
		$all     = array( 'claude', 'openai', 'deepseek' );
		$order   = array( $primary );

		foreach ( $all as $id ) {
			if ( $id !== $primary && ProviderFactory::has_api_key( $id ) ) {
				$order[] = $id;
			}
		}

		return $order;
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
