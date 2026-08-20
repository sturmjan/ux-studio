<?php
/**
 * OpenAI (ChatGPT) provider adapter.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\AiAssistant\Providers;

defined( 'ABSPATH' ) || exit;

final class OpenAiProvider implements AiProviderInterface {

	private const API_URL = 'https://api.openai.com/v1/chat/completions';

	private string $api_key;
	/** @var array{input_tokens:int,output_tokens:int} */
	private array $last_stream_usage = array(
		'input_tokens'  => 0,
		'output_tokens' => 0,
	);

	public function __construct( string $api_key ) {
		$this->api_key = $api_key;
	}

	public function get_last_stream_usage(): array {
		return $this->last_stream_usage;
	}

	public function get_id(): string {
		return 'openai';
	}

	public function get_label(): string {
		return 'ChatGPT (OpenAI)';
	}

	public function get_models(): array {
		return array(
			'gpt-5.1'      => 'GPT-5.1 (best)',
			'gpt-5.1-mini' => 'GPT-5.1 Mini (balanced)',
			'gpt-5.1-nano' => 'GPT-5.1 Nano (fast, cheap)',
		);
	}

	/**
	 * Reasoning models (GPT-5 series, o-series) follow different Chat
	 * Completions rules:
	 * - `max_completion_tokens` instead of `max_tokens`
	 * - `temperature` / `top_p` / penalty params are NOT supported.
	 */
	private function is_reasoning_model( string $model ): bool {
		return (bool) preg_match( '/^(gpt-5|o\d)/i', $model );
	}

	/**
	 * Correct token-limit parameter name for the given model.
	 */
	private function token_param_key( string $model ): string {
		return $this->is_reasoning_model( $model ) ? 'max_completion_tokens' : 'max_tokens';
	}

	public function generate_content( string $system_prompt, string $user_prompt, string $model, array $options = array() ): array {
		$body = array(
			'model'                          => $model,
			$this->token_param_key( $model ) => $options['max_tokens'] ?? 4096,
			'messages'                       => array(
				array(
					'role'    => 'system',
					'content' => $system_prompt,
				),
				array(
					'role'    => 'user',
					'content' => $user_prompt,
				),
			),
		);

		// Temperature only applies to non-reasoning models (gpt-4o etc.).
		if ( isset( $options['temperature'] ) && ! $this->is_reasoning_model( $model ) ) {
			$body['temperature'] = $options['temperature'];
		}

		$response = wp_remote_post(
			self::API_URL,
			array(
				'timeout' => 120,
				'headers' => array(
					'Content-Type'  => 'application/json',
					'Authorization' => 'Bearer ' . $this->api_key,
				),
				'body'    => wp_json_encode( $body ),
			)
		);

		if ( is_wp_error( $response ) ) {
			throw new \RuntimeException( $response->get_error_message() );
		}

		$status = wp_remote_retrieve_response_code( $response );
		$data   = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 200 !== $status ) {
			$error = $data['error']['message'] ?? "HTTP {$status}";
			throw new \RuntimeException( "OpenAI API: {$error}" );
		}

		return array(
			'content' => $data['choices'][0]['message']['content'] ?? '',
			'usage'   => array(
				'input_tokens'  => $data['usage']['prompt_tokens'] ?? 0,
				'output_tokens' => $data['usage']['completion_tokens'] ?? 0,
			),
		);
	}

	public function stream_chat( array $messages, string $model, callable $on_chunk, array $options = array() ): void {
		$this->last_stream_usage = array(
			'input_tokens'  => 0,
			'output_tokens' => 0,
		);

		$all_messages = array();
		if ( ! empty( $options['system'] ) ) {
			$all_messages[] = array(
				'role'    => 'system',
				'content' => $options['system'],
			);
		}
		$all_messages = array_merge( $all_messages, $messages );

		$body = wp_json_encode(
			array(
				'model'                          => $model,
				$this->token_param_key( $model ) => $options['max_tokens'] ?? 4096,
				'messages'                       => $all_messages,
				'stream'                         => true,
				'stream_options'                 => array( 'include_usage' => true ),
			)
		);

		$usage = &$this->last_stream_usage;
		// curl delivers data in chunks NOT aligned to SSE line boundaries (or
		// UTF-8 byte boundaries) - an incomplete trailing line must carry over
		// into the next chunk or split deltas/characters get lost.
		$line_buffer = '';

		$ch        = curl_init( self::API_URL ); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_init
		$curl_opts = array(
			CURLOPT_POST           => true,
			CURLOPT_HTTPHEADER     => array(
				'Content-Type: application/json',
				'Authorization: Bearer ' . $this->api_key,
			),
			CURLOPT_POSTFIELDS     => $body,
			CURLOPT_RETURNTRANSFER => false,
			CURLOPT_TIMEOUT        => 120,
			CURLOPT_WRITEFUNCTION  => function ( $ch, $data ) use ( $on_chunk, &$usage, &$line_buffer ) {
				$line_buffer .= $data;
				while ( false !== ( $nl_pos = strpos( $line_buffer, "\n" ) ) ) { // phpcs:ignore Generic.CodeAnalysis.AssignmentInCondition
					$line        = substr( $line_buffer, 0, $nl_pos );
					$line_buffer = substr( $line_buffer, $nl_pos + 1 );
					$line        = trim( $line );
					if ( 0 !== strpos( $line, 'data: ' ) ) {
						continue;
					}
					$payload = substr( $line, 6 );
					if ( '[DONE]' === $payload ) {
						break;
					}
					$json = json_decode( $payload, true );
					if ( ! $json ) {
						continue;
					}
					if ( isset( $json['choices'][0]['delta']['content'] ) ) {
						$on_chunk( $json['choices'][0]['delta']['content'] );
					}
					// Capture usage from the final chunk.
					if ( isset( $json['usage'] ) ) {
						$usage['input_tokens']  = $json['usage']['prompt_tokens'] ?? 0;
						$usage['output_tokens'] = $json['usage']['completion_tokens'] ?? 0;
					}
				}
				return strlen( $data );
			},
		);

		$ca_bundle = ABSPATH . WPINC . '/certificates/ca-bundle.crt';
		if ( file_exists( $ca_bundle ) ) {
			$curl_opts[ CURLOPT_CAINFO ] = $ca_bundle;
		}

		curl_setopt_array( $ch, $curl_opts ); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_setopt_array
		curl_exec( $ch ); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_exec

		if ( curl_errno( $ch ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_errno
			$error = curl_error( $ch ); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_error
			curl_close( $ch ); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_close
			throw new \RuntimeException( "OpenAI streaming: {$error}" );
		}

		curl_close( $ch ); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_close
	}

	public function chat_with_tools( array $messages, string $model, array $tools = array(), array $options = array() ): array {
		$all_messages = array();
		if ( ! empty( $options['system'] ) ) {
			$all_messages[] = array(
				'role'    => 'system',
				'content' => $options['system'],
			);
		}
		$all_messages = array_merge( $all_messages, $messages );

		$body = array(
			'model'                          => $model,
			$this->token_param_key( $model ) => $options['max_tokens'] ?? 4096,
			'messages'                       => $all_messages,
		);

		if ( ! empty( $tools ) ) {
			$body['tools'] = array_map(
				static fn ( $tool ) => array(
					'type'     => 'function',
					'function' => array(
						'name'        => $tool['name'],
						'description' => $tool['description'],
						'parameters'  => $tool['parameters'],
					),
				),
				$tools
			);
		}

		$response = wp_remote_post(
			self::API_URL,
			array(
				'timeout' => 120,
				'headers' => array(
					'Content-Type'  => 'application/json',
					'Authorization' => 'Bearer ' . $this->api_key,
				),
				'body'    => wp_json_encode( $body ),
			)
		);

		if ( is_wp_error( $response ) ) {
			throw new \RuntimeException( $response->get_error_message() );
		}

		$data   = json_decode( wp_remote_retrieve_body( $response ), true );
		$choice = $data['choices'][0] ?? null;

		if ( ! $choice ) {
			$error_msg = $data['error']['message'] ?? wp_remote_retrieve_body( $response );
			throw new \RuntimeException( "OpenAI API: {$error_msg}" );
		}

		$result = array(
			'content'           => $choice['message']['content'] ?? null,
			'tool_calls'        => array(),
			'input_tokens'      => $data['usage']['prompt_tokens'] ?? 0,
			'output_tokens'     => $data['usage']['completion_tokens'] ?? 0,
			'assistant_message' => $choice['message'],
		);

		if ( ! empty( $choice['message']['tool_calls'] ) ) {
			foreach ( $choice['message']['tool_calls'] as $tc ) {
				$result['tool_calls'][] = array(
					'id'        => $tc['id'],
					'name'      => $tc['function']['name'],
					'arguments' => json_decode( $tc['function']['arguments'], true ) ?? array(),
				);
			}
		}

		return $result;
	}

	public function format_tool_results( array $tool_results ): array {
		$messages = array();
		foreach ( $tool_results as $result ) {
			$messages[] = array(
				'role'         => 'tool',
				'tool_call_id' => $result['tool_call_id'],
				'content'      => $result['content'],
			);
		}
		return $messages;
	}

	public function test_connection( string $model ): array {
		try {
			$result = $this->generate_content(
				'Respond with a single word.',
				'Say "OK".',
				$model,
				array( 'max_tokens' => 10 )
			);
			return array(
				'success' => true,
				'model'   => $model,
				'usage'   => $result['usage'],
			);
		} catch ( \Throwable $e ) {
			return array(
				'success' => false,
				'error'   => $e->getMessage(),
			);
		}
	}
}
