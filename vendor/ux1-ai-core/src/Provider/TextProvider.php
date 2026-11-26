<?php
/**
 * Contract of a text AI provider (chat, one-shot generation, streaming, tool calling).
 *
 * Method names follow the UX1 Studio contract it was extracted from, so the plugin adapts without renames.
 * Every method throws \RuntimeException with a "<Provider> API: HTTP <code>: <detail>"-style message on failure
 * (hosts parse the HTTP code out of it for their error logs).
 *
 * @package Ux1\AiCore
 */

declare(strict_types=1);

namespace Ux1\AiCore\Provider;

interface TextProvider {

	public function get_id(): string;

	public function get_label(): string;

	/** @return array<string, string> Model id => label. */
	public function get_models(): array;

	/**
	 * One-shot generation.
	 *
	 * @param array{max_tokens?:int,temperature?:float} $options
	 * @return array{content:string, usage:array{input_tokens:int,output_tokens:int}}
	 */
	public function generate_content( string $system_prompt, string $user_prompt, string $model, array $options = array() ): array;

	/**
	 * Streams a chat answer; $on_chunk receives text deltas in order.
	 *
	 * @param array<int, array{role:string,content:mixed}> $messages
	 * @param callable(string): void                       $on_chunk
	 * @param array{system?:string,max_tokens?:int,temperature?:float} $options
	 */
	public function stream_chat( array $messages, string $model, callable $on_chunk, array $options = array() ): void;

	/** @return array{input_tokens:int,output_tokens:int} Usage of the last stream_chat() call. */
	public function get_last_stream_usage(): array;

	/**
	 * One tool-calling turn.
	 *
	 * @param array<int, array<string, mixed>>                                         $messages
	 * @param array<int, array{name:string,description:string,parameters:array<string,mixed>}> $tools
	 * @param array{system?:string,max_tokens?:int}                                    $options
	 * @return array{content:?string,tool_calls:array<int,array{id:string,name:string,arguments:array<string,mixed>}>,input_tokens:int,output_tokens:int,assistant_message:array<string,mixed>}
	 */
	public function chat_with_tools( array $messages, string $model, array $tools = array(), array $options = array() ): array;

	/**
	 * Provider-specific messages carrying tool results back.
	 *
	 * @param array<int, array{tool_call_id:string,content:string}> $tool_results
	 * @return array<int, array<string, mixed>>
	 */
	public function format_tool_results( array $tool_results ): array;

	/** @return array{success:bool,error?:string,model?:string,usage?:array{input_tokens:int,output_tokens:int}} */
	public function test_connection( string $model ): array;
}
