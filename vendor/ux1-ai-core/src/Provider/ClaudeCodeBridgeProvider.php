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
		private readonly string $workdir = '',
		private readonly int $max_turns = self::MAX_TURNS,
		private readonly ?int $auth_ttl = null,
		private readonly int $timeout = self::TIMEOUT
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
		$this->assert_configured();

		$body = $this->build_body( $this->with_system_prefix( $messages, $system ), array() );

		$response = $this->send( $body );
		$parsed   = $response->json();
		if ( 202 === $response->status ) {
			throw new BridgeException( 'Claude Code Bridge: waiting for approval in the bridge panel.', 'approval', 202, null, '', 0, $this->approve_url( $parsed ) );
		}
		if ( ! $response->is_success() || empty( $parsed['success'] ) ) {
			$detail = (string) ( $parsed['error'] ?? ( 'HTTP ' . $response->status ) );
			throw new BridgeException( 'Claude Code Bridge: HTTP ' . $response->status . ': ' . $detail, 'http', $response->status, isset( $parsed['error'] ) ? (string) $parsed['error'] : null );
		}

		$content = trim( (string) ( $parsed['content'] ?? '' ) );
		if ( '' === $content ) {
			throw new BridgeException( 'Claude Code Bridge: empty answer.', 'empty', $response->status );
		}
		return $content;
	}

	/**
	 * Full bridge turn with everything the bridge reports (the agent runs its own tools, so there is no tool loop
	 * on the host side). Unlike generate_content()/stream_chat() the answer is returned untrimmed, the HTTP status is
	 * mapped to a BridgeException with structured detail, and the bridge's own token count is returned when present.
	 *
	 * @param array<int, array<string, mixed>>                                                    $messages Conversation, sent as given.
	 * @param array{system?:string,workdir?:string,session_id?:string,user_id?:int,max_turns?:int} $options
	 * @return array{content:string,tokens:?int,tool_calls:array<int,array{name:string,args:mixed,result:mixed}>,session_id:string}
	 * @throws BridgeException
	 */
	public function chat( array $messages, array $options = array() ): array {
		$this->assert_configured();

		$body     = $this->build_body( $this->with_system_prefix( $messages, (string) ( $options['system'] ?? '' ) ), $options );
		$response = $this->send( $body );
		$parsed   = $response->json();
		$status   = $response->status;

		$error      = isset( $parsed['error'] ) && is_scalar( $parsed['error'] ) ? (string) $parsed['error'] : null;
		$error_code = (string) ( $parsed['code'] ?? '' );
		$retry      = (int) ( $parsed['retry_after'] ?? 0 );

		// Status codes are resolved BEFORE the success check: any 2xx counts as success, so a 202 (waiting for
		// approval) would otherwise fall through to a misleading "empty answer".
		if ( 202 === $status ) {
			throw new BridgeException( 'Claude Code Bridge: waiting for approval in the bridge panel.', 'approval', 202, $error, $error_code, 0, $this->approve_url( $parsed ) );
		}
		$reasons = array(
			429 => 'rate_limit',
			403 => 'forbidden',
			503 => 'unavailable',
			401 => 'unauthorized',
		);
		if ( isset( $reasons[ $status ] ) ) {
			throw new BridgeException( 'Claude Code Bridge: HTTP ' . $status . ': ' . ( $error ?? ( 'HTTP ' . $status ) ), $reasons[ $status ], $status, $error, $error_code, 429 === $status ? $retry : 0 );
		}
		if ( ! $response->is_success() ) {
			throw new BridgeException( 'Claude Code Bridge: HTTP ' . $status . ': ' . ( $error ?? ( 'HTTP ' . $status ) ), 'http', $status, $error, $error_code );
		}

		$content = (string) ( $parsed['content'] ?? '' );
		if ( '' === $content ) {
			throw new BridgeException( 'Claude Code Bridge: empty answer.', 'empty', $status );
		}

		$tool_calls = array();
		foreach ( (array) ( $parsed['tool_calls'] ?? array() ) as $call ) {
			if ( ! is_array( $call ) ) {
				continue;
			}
			$tool_calls[] = array(
				'name'   => (string) ( $call['name'] ?? '' ),
				'args'   => $call['args'] ?? array(),
				'result' => $call['result'] ?? null,
			);
		}

		return array(
			'content'    => $content,
			'tokens'     => isset( $parsed['tokens'] ) ? (int) $parsed['tokens'] : null,
			'tool_calls' => $tool_calls,
			'session_id' => (string) ( $parsed['session_id'] ?? '' ),
		);
	}

	/**
	 * Pings GET /api/ping and returns what the bridge reports. HTTP statuses are NOT exceptions here (the host
	 * decides what 401 etc. means), only an unreachable bridge is.
	 *
	 * @return array{status:int,body:string,version:string,approval_status:string}
	 * @throws BridgeException With reason "transport" or "config".
	 */
	public function ping(): array {
		$this->assert_configured();
		try {
			$response = $this->http->request( 'GET', rtrim( $this->base_url, '/' ) . '/api/ping', array( 'Authorization' => 'Bearer ' . $this->token ), null, self::PING_TIMEOUT );
		} catch ( HttpException $e ) {
			throw new BridgeException( 'Claude Code Bridge: ' . $e->getMessage(), 'transport', 0, null, '', 0, '', $e );
		}
		$data = $response->json();
		return array(
			'status'          => $response->status,
			'body'            => $response->body,
			'version'         => (string) ( $data['version'] ?? '?' ),
			'approval_status' => (string) ( $data['approval_status'] ?? 'unknown' ),
		);
	}

	private function assert_configured(): void {
		if ( '' === $this->token || '' === $this->base_url ) {
			throw new BridgeException( 'Claude Code Bridge: URL or token is not configured.', 'config' );
		}
	}

	/**
	 * @param array<int, array<string, mixed>> $messages
	 * @return array<int, array<string, mixed>>
	 */
	private function with_system_prefix( array $messages, string $system ): array {
		$messages = array_values( $messages );
		if ( '' !== $system ) {
			$prefix = "[System instructions - follow them strictly]\n" . $system . "\n[End of system instructions]\n\n";
			if ( ! empty( $messages ) && 'user' === ( $messages[0]['role'] ?? '' ) ) {
				$messages[0]['content'] = $prefix . (string) $messages[0]['content'];
			} else {
				array_unshift( $messages, array( 'role' => 'user', 'content' => $prefix . 'Continue.' ) );
			}
		}
		return $messages;
	}

	/**
	 * @param array<int, array<string, mixed>> $messages
	 * @param array<string, mixed>             $options  workdir / session_id / user_id / max_turns override the defaults.
	 * @return array<string, mixed>
	 */
	private function build_body( array $messages, array $options ): array {
		$body = array(
			'messages'  => $messages,
			'max_turns' => ! empty( $options['max_turns'] ) ? (int) $options['max_turns'] : $this->max_turns,
		);
		if ( null !== $this->auth_ttl ) {
			$body['auth_ttl'] = $this->auth_ttl;
		}
		if ( '' !== $this->workdir ) {
			$body['workdir'] = $this->workdir;
		}
		if ( ! empty( $options['workdir'] ) ) {
			$body['workdir'] = (string) $options['workdir'];
		}
		if ( ! empty( $options['session_id'] ) ) {
			$body['session_id'] = (string) $options['session_id'];
		}
		if ( ! empty( $options['user_id'] ) && (int) $options['user_id'] > 0 ) {
			$body['user_id'] = (int) $options['user_id'];
		}
		return $body;
	}

	/** @param array<string, mixed> $body */
	private function send( array $body ): \Ux1\AiCore\Http\HttpResponse {
		try {
			return $this->http->request(
				'POST',
				rtrim( $this->base_url, '/' ) . '/api/chat',
				array(
					'Content-Type'  => 'application/json',
					'Authorization' => 'Bearer ' . $this->token,
				),
				Json::encode( $body ),
				$this->timeout
			);
		} catch ( HttpException $e ) {
			throw new BridgeException( 'Claude Code Bridge: ' . $e->getMessage(), 'transport', 0, null, '', 0, '', $e );
		}
	}

	/** @param array<string, mixed> $parsed */
	private function approve_url( array $parsed ): string {
		$url = trim( (string) ( $parsed['approve_url'] ?? '' ) );
		return '' !== $url ? $url : rtrim( $this->base_url, '/' ) . '/ui';
	}
}
