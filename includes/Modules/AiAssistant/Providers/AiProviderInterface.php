<?php
/**
 * Contract every AI provider adapter must implement.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\AiAssistant\Providers;

defined( 'ABSPATH' ) || exit;

interface AiProviderInterface {

	public function get_id(): string;

	public function get_label(): string;

	/** @return array<int, string> */
	public function get_models(): array;

	/**
	 * @return array{content:string, usage:array{input_tokens:int,output_tokens:int}}
	 */
	public function generate_content( string $system_prompt, string $user_prompt, string $model, array $options = array() ): array;

	/**
	 * @param callable $on_chunk function( string $text ): void
	 */
	public function stream_chat( array $messages, string $model, callable $on_chunk, array $options = array() ): void;

	/** @return array{input_tokens:int,output_tokens:int} */
	public function get_last_stream_usage(): array;

	/**
	 * @return array{content:?string,tool_calls:array,input_tokens:int,output_tokens:int,assistant_message:array}
	 */
	public function chat_with_tools( array $messages, string $model, array $tools = array(), array $options = array() ): array;

	public function format_tool_results( array $tool_results ): array;

	/** @return array{success:bool,error?:string,model?:string} */
	public function test_connection( string $model ): array;
}
