<?php
/**
 * Claude (Anthropic Messages API).
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

final class ClaudeProvider implements TextProvider {

	public const API_URL     = 'https://api.anthropic.com/v1/messages';
	public const API_VERSION = '2023-06-01';

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

	public function get_id(): string {
		return 'claude';
	}

	public function get_label(): string {
		return 'Claude (Anthropic)';
	}

	public function get_models(): array {
		return array(
			'claude-opus-4-8'           => 'Claude Opus 4.8 (best)',
			'claude-sonnet-5'           => 'Claude Sonnet 5 (balanced)',
			'claude-haiku-4-5-20251001' => 'Claude Haiku 4.5 (fast, cheap)',
		);
	}

	public function get_last_stream_usage(): array {
		return $this->last_stream_usage;
	}

	public function generate_content( string $system_prompt, string $user_prompt, string $model, array $options = array() ): array {
		$body = array(
			'model'      => $model,
			'max_tokens' => $options['max_tokens'] ?? 4096,
			'system'     => $system_prompt,
			'messages'   => array( array( 'role' => 'user', 'content' => $user_prompt ) ),
		);
		if ( isset( $options['temperature'] ) ) {
			$body['temperature'] = $options['temperature'];
		}

		$response = $this->post( $body );
		$data     = $response->json();

		if ( 200 !== $response->status ) {
			$error = $data['error']['message'] ?? substr( $response->body, 0, 300 );
			throw new \RuntimeException( "Claude API: HTTP {$response->status}: {$error}" );
		}

		$content = '';
		foreach ( (array) ( $data['content'] ?? array() ) as $block ) {
			if ( 'text' === ( $block['type'] ?? '' ) ) {
				$content .= $block['text'];
			}
		}

		return array(
			'content' => $content,
			'usage'   => array(
				'input_tokens'  => (int) ( $data['usage']['input_tokens'] ?? 0 ),
				'output_tokens' => (int) ( $data['usage']['output_tokens'] ?? 0 ),
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
				'model'      => $model,
				'max_tokens' => $options['max_tokens'] ?? 4096,
				'system'     => $options['system'] ?? '',
				'messages'   => $messages,
				'stream'     => true,
			)
		);

		$usage        = &$this->last_stream_usage;
		$stream_error = '';
		$lines        = new SseLineBuffer();

		try {
			$response = $this->http->stream(
				self::API_URL,
				$this->headers(),
				$body,
				self::TIMEOUT,
				static function ( string $data ) use ( $lines, $on_chunk, &$usage, &$stream_error ): void {
					foreach ( $lines->push( $data ) as $payload ) {
						$json = json_decode( $payload, true );
						if ( ! is_array( $json ) ) {
							continue;
						}
						$type = $json['type'] ?? '';
						if ( 'error' === $type && isset( $json['error']['message'] ) ) {
							$stream_error = (string) $json['error']['message'];
							continue;
						}
						if ( 'content_block_delta' === $type && isset( $json['delta']['text'] ) ) {
							$on_chunk( (string) $json['delta']['text'] );
						}
						if ( 'message_start' === $type && isset( $json['message']['usage'] ) ) {
							$usage['input_tokens'] = (int) ( $json['message']['usage']['input_tokens'] ?? 0 );
						}
						if ( 'message_delta' === $type && isset( $json['usage'] ) ) {
							$usage['output_tokens'] = (int) ( $json['usage']['output_tokens'] ?? 0 );
						}
					}
				}
			);
		} catch ( HttpException $e ) {
			throw new \RuntimeException( 'Claude streaming: ' . $e->getMessage(), 0, $e );
		}

		if ( '' !== $stream_error ) {
			$suffix = $response->status ? " (HTTP {$response->status})" : '';
			throw new \RuntimeException( "Claude API{$suffix}: {$stream_error}" );
		}
		if ( $response->status >= 400 ) {
			$excerpt = '' !== trim( $response->body ) ? ': ' . substr( trim( $response->body ), 0, 300 ) : '';
			throw new \RuntimeException( "Claude API: HTTP {$response->status}{$excerpt}" );
		}
	}

	public function chat_with_tools( array $messages, string $model, array $tools = array(), array $options = array() ): array {
		$body = array(
			'model'      => $model,
			'max_tokens' => $options['max_tokens'] ?? 4096,
			'messages'   => $messages,
		);
		if ( ! empty( $options['system'] ) ) {
			$body['system'] = $options['system'];
		}
		if ( ! empty( $tools ) ) {
			$body['tools'] = array_map(
				static fn ( array $tool ): array => array(
					'name'         => $tool['name'],
					'description'  => $tool['description'],
					'input_schema' => $tool['parameters'],
				),
				$tools
			);
		}

		$response = $this->post( $body );
		$data     = $response->json();
		if ( ! isset( $data['content'] ) ) {
			$error_msg = $data['error']['message'] ?? $response->body;
			throw new \RuntimeException( "Claude API: {$error_msg}" );
		}

		$result = array(
			'content'           => null,
			'tool_calls'        => array(),
			'input_tokens'      => (int) ( $data['usage']['input_tokens'] ?? 0 ),
			'output_tokens'     => (int) ( $data['usage']['output_tokens'] ?? 0 ),
			'assistant_message' => array(
				'role'    => 'assistant',
				'content' => $data['content'],
			),
		);

		foreach ( $data['content'] as $block ) {
			if ( 'text' === ( $block['type'] ?? '' ) ) {
				$result['content'] = ( $result['content'] ?? '' ) . $block['text'];
			} elseif ( 'tool_use' === ( $block['type'] ?? '' ) ) {
				$result['tool_calls'][] = array(
					'id'        => (string) $block['id'],
					'name'      => (string) $block['name'],
					'arguments' => (array) ( $block['input'] ?? array() ),
				);
			}
		}

		return $result;
	}

	public function format_tool_results( array $tool_results ): array {
		$content = array();
		foreach ( $tool_results as $result ) {
			$content[] = array(
				'type'        => 'tool_result',
				'tool_use_id' => $result['tool_call_id'],
				'content'     => $result['content'],
			);
		}
		return array(
			array(
				'role'    => 'user',
				'content' => $content,
			),
		);
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

	/** @return array<string, string> */
	private function headers(): array {
		return array(
			'Content-Type'      => 'application/json',
			'x-api-key'         => $this->api_key,
			'anthropic-version' => self::API_VERSION,
		);
	}

	/** @param array<string, mixed> $body */
	private function post( array $body ): \Ux1\AiCore\Http\HttpResponse {
		try {
			return $this->http->request( 'POST', self::API_URL, $this->headers(), Json::encode( $body ), self::TIMEOUT );
		} catch ( HttpException $e ) {
			throw new \RuntimeException( $e->getMessage(), 0, $e );
		}
	}
}
