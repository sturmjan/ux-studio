<?php
/**
 * Base for providers speaking the OpenAI Chat Completions protocol (OpenAI, DeepSeek, and later others).
 * Subclasses only declare the endpoint, identity, models and parameter rules.
 *
 * @package Ux1\AiCore
 */

declare(strict_types=1);

namespace Ux1\AiCore\Provider;

use Ux1\AiCore\Http\HttpClient;
use Ux1\AiCore\Http\HttpException;
use Ux1\AiCore\Http\HttpResponse;
use Ux1\AiCore\Http\SseLineBuffer;
use Ux1\AiCore\Support\Json;

abstract class OpenAiCompatibleProvider implements TextProvider {

	private const TIMEOUT = 120;

	/** @var array{input_tokens:int,output_tokens:int} */
	private array $last_stream_usage = array(
		'input_tokens'  => 0,
		'output_tokens' => 0,
	);

	public function __construct(
		private readonly string $api_key,
		private readonly HttpClient $http
	) {}

	/** Chat Completions endpoint. */
	abstract protected function api_url(): string;

	/** Name used in error messages, e.g. "OpenAI" -> "OpenAI API: ...", "OpenAI streaming: ...". */
	abstract protected function error_name(): string;

	/** Name of the output-token limit parameter for the model (max_tokens / max_completion_tokens). */
	protected function token_param_key( string $model ): string {
		return 'max_tokens';
	}

	/** Whether the model accepts the temperature parameter. */
	protected function supports_temperature( string $model ): bool {
		return true;
	}

	public function get_last_stream_usage(): array {
		return $this->last_stream_usage;
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
		if ( isset( $options['temperature'] ) && $this->supports_temperature( $model ) ) {
			$body['temperature'] = $options['temperature'];
		}

		$response = $this->post( $body );
		$data     = $response->json();
		if ( 200 !== $response->status ) {
			$error = $data['error']['message'] ?? "HTTP {$response->status}";
			throw new \RuntimeException( $this->error_name() . " API: {$error}" );
		}

		return array(
			'content' => (string) ( $data['choices'][0]['message']['content'] ?? '' ),
			'usage'   => array(
				'input_tokens'  => (int) ( $data['usage']['prompt_tokens'] ?? 0 ),
				'output_tokens' => (int) ( $data['usage']['completion_tokens'] ?? 0 ),
			),
		);
	}

	public function stream_chat( array $messages, string $model, callable $on_chunk, array $options = array() ): void {
		$this->last_stream_usage = array(
			'input_tokens'  => 0,
			'output_tokens' => 0,
		);

		$body = Json::encode(
			array(
				'model'                          => $model,
				$this->token_param_key( $model ) => $options['max_tokens'] ?? 4096,
				'messages'                       => $this->with_system( $messages, $options ),
				'stream'                         => true,
				'stream_options'                 => array( 'include_usage' => true ),
			)
		);

		$usage = &$this->last_stream_usage;
		$lines = new SseLineBuffer();
		$done  = false;

		try {
			$response = $this->http->stream(
				$this->api_url(),
				$this->headers(),
				$body,
				self::TIMEOUT,
				static function ( string $data ) use ( $lines, $on_chunk, &$usage, &$done ): void {
					foreach ( $lines->push( $data ) as $payload ) {
						if ( $done ) {
							return;
						}
						if ( '[DONE]' === $payload ) {
							$done = true;
							return;
						}
						$json = json_decode( $payload, true );
						if ( ! is_array( $json ) ) {
							continue;
						}
						if ( isset( $json['choices'][0]['delta']['content'] ) ) {
							$on_chunk( (string) $json['choices'][0]['delta']['content'] );
						}
						if ( isset( $json['usage'] ) && is_array( $json['usage'] ) ) {
							$usage['input_tokens']  = (int) ( $json['usage']['prompt_tokens'] ?? 0 );
							$usage['output_tokens'] = (int) ( $json['usage']['completion_tokens'] ?? 0 );
						}
					}
				}
			);
		} catch ( HttpException $e ) {
			throw new \RuntimeException( $this->error_name() . ' streaming: ' . $e->getMessage(), 0, $e );
		}

		// Fixed while extracting to the core: an HTTP error (bad key, quota, model) used to end silently with an empty
		// answer, so the host never fell back to another provider and logged nothing.
		if ( $response->status >= 400 ) {
			$data    = json_decode( $response->body, true );
			$message = is_array( $data ) ? ( $data['error']['message'] ?? '' ) : '';
			$detail  = '' !== $message ? $message : substr( trim( $response->body ), 0, 300 );
			throw new \RuntimeException( $this->error_name() . " API: HTTP {$response->status}" . ( '' !== $detail ? ": {$detail}" : '' ) );
		}
	}

	public function chat_with_tools( array $messages, string $model, array $tools = array(), array $options = array() ): array {
		$body = array(
			'model'                          => $model,
			$this->token_param_key( $model ) => $options['max_tokens'] ?? 4096,
			'messages'                       => $this->with_system( $messages, $options ),
		);
		if ( ! empty( $tools ) ) {
			$body['tools'] = array_map(
				static fn ( array $tool ): array => array(
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

		$response = $this->post( $body );
		$data     = $response->json();
		$choice   = $data['choices'][0] ?? null;
		if ( ! $choice ) {
			$error_msg = $data['error']['message'] ?? $response->body;
			throw new \RuntimeException( $this->error_name() . " API: {$error_msg}" );
		}

		$result = array(
			'content'           => $choice['message']['content'] ?? null,
			'tool_calls'        => array(),
			'input_tokens'      => (int) ( $data['usage']['prompt_tokens'] ?? 0 ),
			'output_tokens'     => (int) ( $data['usage']['completion_tokens'] ?? 0 ),
			'assistant_message' => $choice['message'],
		);
		foreach ( (array) ( $choice['message']['tool_calls'] ?? array() ) as $tc ) {
			$result['tool_calls'][] = array(
				'id'        => (string) $tc['id'],
				'name'      => (string) $tc['function']['name'],
				'arguments' => json_decode( (string) $tc['function']['arguments'], true ) ?? array(),
			);
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
			$result = $this->generate_content( 'Respond with a single word.', 'Say "OK".', $model, array( 'max_tokens' => 10 ) );
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

	/**
	 * @param array<int, array<string, mixed>> $messages
	 * @param array{system?:string}            $options
	 * @return array<int, array<string, mixed>>
	 */
	private function with_system( array $messages, array $options ): array {
		if ( empty( $options['system'] ) ) {
			return array_values( $messages );
		}
		return array_merge(
			array(
				array(
					'role'    => 'system',
					'content' => $options['system'],
				),
			),
			array_values( $messages )
		);
	}

	/** @return array<string, string> */
	private function headers(): array {
		return array(
			'Content-Type'  => 'application/json',
			'Authorization' => 'Bearer ' . $this->api_key,
		);
	}

	/** @param array<string, mixed> $body */
	private function post( array $body ): HttpResponse {
		try {
			return $this->http->request( 'POST', $this->api_url(), $this->headers(), Json::encode( $body ), self::TIMEOUT );
		} catch ( HttpException $e ) {
			throw new \RuntimeException( $e->getMessage(), 0, $e );
		}
	}
}
