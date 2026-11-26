<?php
/**
 * Adapts a provider of the shared AI core (ux1-ai-core, Ux1\AiCore\Provider\TextProvider) to the plugin contract.
 * The contracts have the same methods, so this only forwards; the plugin keeps type-hinting AiProviderInterface.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\AiAssistant\Providers;

use Ux1\AiCore\Provider\TextProvider;

defined( 'ABSPATH' ) || exit;

final class CoreProvider implements AiProviderInterface {

	public function __construct( private readonly TextProvider $provider ) {}

	/** The wrapped core provider. */
	public function core(): TextProvider {
		return $this->provider;
	}

	public function get_id(): string {
		return $this->provider->get_id();
	}

	public function get_label(): string {
		return $this->provider->get_label();
	}

	public function get_models(): array {
		return $this->provider->get_models();
	}

	public function generate_content( string $system_prompt, string $user_prompt, string $model, array $options = array() ): array {
		return $this->provider->generate_content( $system_prompt, $user_prompt, $model, $options );
	}

	public function stream_chat( array $messages, string $model, callable $on_chunk, array $options = array() ): void {
		$this->provider->stream_chat( $messages, $model, $on_chunk, $options );
	}

	public function get_last_stream_usage(): array {
		return $this->provider->get_last_stream_usage();
	}

	public function chat_with_tools( array $messages, string $model, array $tools = array(), array $options = array() ): array {
		return $this->provider->chat_with_tools( $messages, $model, $tools, $options );
	}

	public function format_tool_results( array $tool_results ): array {
		return $this->provider->format_tool_results( $tool_results );
	}

	public function test_connection( string $model ): array {
		return $this->provider->test_connection( $model );
	}
}
