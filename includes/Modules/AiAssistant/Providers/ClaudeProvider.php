<?php
/**
 * Claude (Anthropic) provider adapter.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\AiAssistant\Providers;

defined( 'ABSPATH' ) || exit;

final class ClaudeProvider implements AiProviderInterface {

	private const API_URL     = 'https://api.anthropic.com/v1/messages';
	private const API_VERSION = '2023-06-01';

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

		$response = wp_remote_post(
			self::API_URL,
			array(
				'timeout' => 120,
				'headers' => array(
					'Content-Type'      => 'application/json',
					'x-api-key'         => $this->api_key,
					'anthropic-version' => self::API_VERSION,
				),
				'body'    => wp_json_encode( $body ),
			)
		);

		if ( is_wp_error( $response ) ) {
			throw new \RuntimeException( $response->get_error_message() );
		}

		$status    = wp_remote_retrieve_response_code( $response );
		$raw_body  = wp_remote_retrieve_body( $response );
		$data      = json_decode( $raw_body, true );

		if ( 200 !== $status ) {
			$error = $data['error']['message'] ?? substr( $raw_body, 0, 300 );
			throw new \RuntimeException( "Claude API: HTTP {$status}: {$error}" );
		}

		$content = '';
		foreach ( (array) ( $data['content'] ?? array() ) as $block ) {
			if ( 'text' === $block['type'] ) {
				$content .= $block['text'];
			}
		}

		return array(
			'content' => $content,
			'usage'   => array(
				'input_tokens'  => $data['usage']['input_tokens'] ?? 0,
				'output_tokens' => $data['usage']['output_tokens'] ?? 0,
			),
		);
	}

	public function stream_chat( array $messages, string $model, callable $on_chunk, array $options = array() ): void {
		$this->last_stream_usage = array(
			'input_tokens'  => 0,
			'output_tokens' => 0,
		);

		$body = wp_json_encode(
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
		$raw_buffer   = '';
		// curl delivers data in chunks NOT aligned to SSE line boundaries (or
		// UTF-8 byte boundaries) - an incomplete trailing line must carry over
		// into the next chunk or split deltas/characters get lost.
		$line_buffer = '';

		$ch        = curl_init( self::API_URL ); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_init
		$curl_opts = array(
			CURLOPT_POST           => true,
			CURLOPT_HTTPHEADER     => array(
				'Content-Type: application/json',
				'x-api-key: ' . $this->api_key,
				'anthropic-version: ' . self::API_VERSION,
			),
			CURLOPT_POSTFIELDS     => $body,
			CURLOPT_RETURNTRANSFER => false,
			CURLOPT_TIMEOUT        => 120,
			CURLOPT_WRITEFUNCTION  => function ( $ch, $data ) use ( $on_chunk, &$usage, &$stream_error, &$raw_buffer, &$line_buffer ) {
				if ( strlen( $raw_buffer ) < 2000 ) {
					$raw_buffer .= substr( $data, 0, 2000 - strlen( $raw_buffer ) );
				}
				$line_buffer .= $data;
				while ( false !== ( $nl_pos = strpos( $line_buffer, "\n" ) ) ) { // phpcs:ignore Generic.CodeAnalysis.AssignmentInCondition
					$line        = substr( $line_buffer, 0, $nl_pos );
					$line_buffer = substr( $line_buffer, $nl_pos + 1 );
					$line        = trim( $line );
					if ( 0 !== strpos( $line, 'data: ' ) ) {
						continue;
					}
					$json = json_decode( substr( $line, 6 ), true );
					if ( ! $json ) {
						continue;
					}
					if ( 'error' === ( $json['type'] ?? '' ) && isset( $json['error']['message'] ) ) {
						$stream_error = $json['error']['message'];
						continue;
					}
					if ( 'content_block_delta' === ( $json['type'] ?? '' ) && isset( $json['delta']['text'] ) ) {
						$on_chunk( $json['delta']['text'] );
					}
					if ( 'message_start' === ( $json['type'] ?? '' ) && isset( $json['message']['usage'] ) ) {
						$usage['input_tokens'] = $json['message']['usage']['input_tokens'] ?? 0;
					}
					if ( 'message_delta' === ( $json['type'] ?? '' ) && isset( $json['usage'] ) ) {
						$usage['output_tokens'] = $json['usage']['output_tokens'] ?? 0;
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
			throw new \RuntimeException( "Claude streaming: {$error}" );
		}

		$http_code = curl_getinfo( $ch, CURLINFO_HTTP_CODE ); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_getinfo
		curl_close( $ch ); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_close

		if ( ! empty( $stream_error ) ) {
			$suffix = $http_code ? " (HTTP {$http_code})" : '';
			throw new \RuntimeException( "Claude API{$suffix}: {$stream_error}" );
		}
		if ( $http_code >= 400 ) {
			$excerpt = '' !== trim( $raw_buffer ) ? ': ' . substr( trim( $raw_buffer ), 0, 300 ) : '';
			throw new \RuntimeException( "Claude API: HTTP {$http_code}{$excerpt}" );
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
				static fn ( $tool ) => array(
					'name'         => $tool['name'],
					'description'  => $tool['description'],
					'input_schema' => $tool['parameters'],
				),
				$tools
			);
		}

		$response = wp_remote_post(
			self::API_URL,
			array(
				'timeout' => 120,
				'headers' => array(
					'Content-Type'      => 'application/json',
					'x-api-key'         => $this->api_key,
					'anthropic-version' => self::API_VERSION,
				),
				'body'    => wp_json_encode( $body ),
			)
		);

		if ( is_wp_error( $response ) ) {
			throw new \RuntimeException( $response->get_error_message() );
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! isset( $data['content'] ) ) {
			$error_msg = $data['error']['message'] ?? wp_remote_retrieve_body( $response );
			throw new \RuntimeException( "Claude API: {$error_msg}" );
		}

		$result = array(
			'content'           => null,
			'tool_calls'        => array(),
			'input_tokens'      => $data['usage']['input_tokens'] ?? 0,
			'output_tokens'     => $data['usage']['output_tokens'] ?? 0,
			'assistant_message' => array(
				'role'    => 'assistant',
				'content' => $data['content'],
			),
		);

		foreach ( $data['content'] as $block ) {
			if ( 'text' === $block['type'] ) {
				$result['content'] = ( $result['content'] ?? '' ) . $block['text'];
			} elseif ( 'tool_use' === $block['type'] ) {
				$result['tool_calls'][] = array(
					'id'        => $block['id'],
					'name'      => $block['name'],
					'arguments' => $block['input'] ?? array(),
				);
			}
		}

		return $result;
	}

	public function format_tool_results( array $tool_results ): array {
		$content = array();
		foreach ( $tool_results as $result ) {
			$content[] = array(
				'type'         => 'tool_result',
				'tool_use_id'  => $result['tool_call_id'],
				'content'      => $result['content'],
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
