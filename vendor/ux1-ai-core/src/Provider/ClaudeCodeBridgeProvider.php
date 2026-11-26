<?php
/**
 * Provider for the "Claude Code Bridge" daemon on a local PC (POST /api/chat, Bearer token, manual approval in the
 * bridge GUI). For development and internal use: answers are not streamed and the bridge is an agent, not a plain
 * completion API, so tool calling is not offered. Usage is estimated (the bridge reports none).
 *
 * @package Ux1\AiCore
 */

declare(strict_types=1);

namespace Ux1\AiCore\Provider;

use Ux1\AiCore\Http\HttpClient;
use Ux1\AiCore\Http\HttpException;
use Ux1\AiCore\Support\Json;

final class ClaudeCodeBridgeProvider implements TextProvider {

	public const MODEL = 'claude-code';

	private const TIMEOUT      = 120;
	private const PING_TIMEOUT = 10;
	private const MAX_TURNS    = 3;

	/** @var array{input_tokens:int,output_tokens:int} */
	private array $last_stream_usage = array(
		'input_tokens'  => 0,
		'output_tokens' => 0,
	);

	public function __construct(
		private readonly string $token,
		private readonly string $base_url,
		private readonly HttpClient $http,
		private readonly string $workdir = ''
	) {}

	public function get_id(): string {
		return 'claude_code';
	}

	public function get_label(): string {
		return 'Claude Code Bridge (local PC)';
	}

	public function get_models(): array {
		return array( self::MODEL => 'Claude Code (via bridge)' );
	}

	public function get_last_stream_usage(): array {
		return $this->last_stream_usage;
	}

	public function generate_content( string $system_prompt, string $user_prompt, string $model, array $options = array() ): array {
		$messages = array( array( 'role' => 'user', 'content' => $user_prompt ) );
		$text     = $this->request( $messages, $system_prompt );
		return array(
			'content' => $text,
			'usage'   => UsageEstimator::estimate( $messages, $system_prompt, $text ),
		);
	}

	public function stream_chat( array $messages, string $model, callable $on_chunk, array $options = array() ): void {
		$this->last_stream_usage = array(
			'input_tokens'  => 0,
			'output_tokens' => 0,
		);
		$system = (string) ( $options['system'] ?? '' );
		// The bridge answers in one piece, so the whole text is a single chunk.
		$text = $this->request( $messages, $system );
		$on_chunk( $text );
		$this->last_stream_usage = UsageEstimator::estimate( $messages, $system, $text );
	}

	public function chat_with_tools( array $messages, string $model, array $tools = array(), array $options = array() ): array {
		$system = (string) ( $options['system'] ?? '' );
		$text   = $this->request( $messages, $system );
		$usage  = UsageEstimator::estimate( $messages, $system, $text );
		return array(
			'content'           => $text,
			'tool_calls'        => array(),
			'input_tokens'      => $usage['input_tokens'],
			'output_tokens'     => $usage['output_tokens'],
			'assistant_message' => array(
				'role'    => 'assistant',
				'content' => $text,
			),
		);
	}

	public function format_tool_results( array $tool_results ): array {
		return array();
	}

	public function test_connection( string $model ): array {
		try {
			$response = $this->http->request( 'GET', rtrim( $this->base_url, '/' ) . '/api/ping', array( 'Authorization' => 'Bearer ' . $this->token ), null, self::PING_TIMEOUT );
		} catch ( HttpException $e ) {
			return array( 'success' => false, 'error' => $e->getMessage() );
		}
		if ( 200 !== $response->status ) {
			return array( 'success' => false, 'error' => 'Bridge HTTP ' . $response->status );
		}
		return array( 'success' => true, 'model' => self::MODEL );
	}

	/**
	 * Sends the conversation and returns the answer text. The bridge has no separate system field, so the system
	 * prompt goes in front of the first user message.
	 *
	 * @param array<int, array<string, mixed>> $messages Conversation.
	 */
	private function request( array $messages, string $system ): string {
		if ( '' === $this->token || '' === $this->base_url ) {
			throw new \RuntimeException( 'Claude Code Bridge: URL or token is not configured.' );
		}

		$messages = array_values( $messages );
		if ( '' !== $system ) {
			$prefix = "[System instructions - follow them strictly]\n" . $system . "\n[End of system instructions]\n\n";
			if ( ! empty( $messages ) && 'user' === ( $messages[0]['role'] ?? '' ) ) {
				$messages[0]['content'] = $prefix . (string) $messages[0]['content'];
			} else {
				array_unshift( $messages, array( 'role' => 'user', 'content' => $prefix . 'Continue.' ) );
			}
		}

		$body = array(
			'messages'  => $messages,
			'max_turns' => self::MAX_TURNS,
		);
		if ( '' !== $this->workdir ) {
			$body['workdir'] = $this->workdir;
		}

		try {
			$response = $this->http->request(
				'POST',
				rtrim( $this->base_url, '/' ) . '/api/chat',
				array(
					'Content-Type'  => 'application/json',
					'Authorization' => 'Bearer ' . $this->token,
				),
				Json::encode( $body ),
				self::TIMEOUT
			);
		} catch ( HttpException $e ) {
			throw new \RuntimeException( 'Claude Code Bridge: ' . $e->getMessage(), 0, $e );
		}

		$parsed = $response->json();
		if ( 202 === $response->status ) {
			throw new \RuntimeException( 'Claude Code Bridge: waiting for approval in the bridge panel.' );
		}
		if ( ! $response->is_success() || empty( $parsed['success'] ) ) {
			$detail = (string) ( $parsed['error'] ?? ( 'HTTP ' . $response->status ) );
			throw new \RuntimeException( 'Claude Code Bridge: HTTP ' . $response->status . ': ' . $detail );
		}

		$content = trim( (string) ( $parsed['content'] ?? '' ) );
		if ( '' === $content ) {
			throw new \RuntimeException( 'Claude Code Bridge: empty answer.' );
		}
		return $content;
	}
}
