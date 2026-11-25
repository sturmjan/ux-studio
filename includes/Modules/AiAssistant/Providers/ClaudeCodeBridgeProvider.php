<?php
/**
 * Provider that talks to the "Claude Code Bridge" daemon running on a local PC.
 *
 * The bridge wraps the Claude Agent SDK behind `POST /api/chat` (Bearer token,
 * manual approval in the bridge GUI). It is meant for development and internal
 * use: answers are not streamed and the bridge is an agent, not a plain
 * completion API, so tool calling is not offered.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\AiAssistant\Providers;

defined( 'ABSPATH' ) || exit;

final class ClaudeCodeBridgeProvider implements AiProviderInterface {

	public const MODEL = 'claude-code';

	/** @var array{input_tokens:int,output_tokens:int} */
	private array $last_stream_usage = array(
		'input_tokens'  => 0,
		'output_tokens' => 0,
	);

	public function __construct(
		private readonly string $token,
		private readonly string $base_url,
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

	public function generate_content( string $system_prompt, string $user_prompt, string $model, array $options = array() ): array {
		$text = $this->request(
			array( array( 'role' => 'user', 'content' => $user_prompt ) ),
			$system_prompt
		);
		return array(
			'content' => $text,
			'usage'   => array(
				'input_tokens'  => 0,
				'output_tokens' => 0,
			),
		);
	}

	public function stream_chat( array $messages, string $model, callable $on_chunk, array $options = array() ): void {
		$this->last_stream_usage = array(
			'input_tokens'  => 0,
			'output_tokens' => 0,
		);
		// The bridge answers in one piece, so the whole text is a single chunk.
		$on_chunk( $this->request( $messages, (string) ( $options['system'] ?? '' ) ) );
	}

	public function get_last_stream_usage(): array {
		return $this->last_stream_usage;
	}

	public function chat_with_tools( array $messages, string $model, array $tools = array(), array $options = array() ): array {
		$text = $this->request( $messages, (string) ( $options['system'] ?? '' ) );
		return array(
			'content'           => $text,
			'tool_calls'        => array(),
			'input_tokens'      => 0,
			'output_tokens'     => 0,
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
		$response = wp_remote_get(
			rtrim( $this->base_url, '/' ) . '/api/ping',
			array(
				'timeout' => 10,
				'headers' => array( 'Authorization' => 'Bearer ' . $this->token ),
			)
		);
		if ( is_wp_error( $response ) ) {
			return array( 'success' => false, 'error' => $response->get_error_message() );
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code ) {
			return array( 'success' => false, 'error' => 'Bridge HTTP ' . $code );
		}
		return array( 'success' => true, 'model' => self::MODEL );
	}

	/**
	 * Sends the conversation to the bridge and returns the answer text.
	 *
	 * The bridge has no separate system field, so the system prompt is placed in
	 * front of the first user message.
	 *
	 * @param array<int, array{role:string,content:string}> $messages Conversation.
	 * @throws \RuntimeException On any bridge failure.
	 */
	private function request( array $messages, string $system ): string {
		if ( '' === $this->token || '' === $this->base_url ) {
			throw new \RuntimeException( 'Claude Code Bridge: URL or token is not configured.' );
		}

		if ( '' !== $system ) {
			$prefix = "[System instructions - follow them strictly]\n" . $system . "\n[End of system instructions]\n\n";
			if ( ! empty( $messages ) && 'user' === ( $messages[0]['role'] ?? '' ) ) {
				$messages[0]['content'] = $prefix . $messages[0]['content'];
			} else {
				array_unshift( $messages, array( 'role' => 'user', 'content' => $prefix . 'Continue.' ) );
			}
		}

		$body = array(
			'messages'  => array_values( $messages ),
			'max_turns' => 3,
		);
		if ( '' !== $this->workdir ) {
			$body['workdir'] = $this->workdir;
		}

		$response = wp_remote_post(
			rtrim( $this->base_url, '/' ) . '/api/chat',
			array(
				'timeout' => 120,
				'headers' => array(
					'Content-Type'  => 'application/json',
					'Authorization' => 'Bearer ' . $this->token,
				),
				'body'    => wp_json_encode( $body ),
			)
		);
		if ( is_wp_error( $response ) ) {
			throw new \RuntimeException( 'Claude Code Bridge: ' . $response->get_error_message() );
		}

		$code   = (int) wp_remote_retrieve_response_code( $response );
		$parsed = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		$parsed = is_array( $parsed ) ? $parsed : array();

		if ( 202 === $code ) {
			throw new \RuntimeException( 'Claude Code Bridge: waiting for approval in the bridge panel.' );
		}
		if ( $code < 200 || $code >= 300 || empty( $parsed['success'] ) ) {
			$detail = (string) ( $parsed['error'] ?? ( 'HTTP ' . $code ) );
			throw new \RuntimeException( 'Claude Code Bridge: HTTP ' . $code . ': ' . $detail );
		}

		$content = trim( (string) ( $parsed['content'] ?? '' ) );
		if ( '' === $content ) {
			throw new \RuntimeException( 'Claude Code Bridge: empty answer.' );
		}
		return $content;
	}
}
