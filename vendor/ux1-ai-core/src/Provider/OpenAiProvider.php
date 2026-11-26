<?php
/**
 * OpenAI (ChatGPT) Chat Completions.
 *
 * @package Ux1\AiCore
 */

declare(strict_types=1);

namespace Ux1\AiCore\Provider;

final class OpenAiProvider extends OpenAiCompatibleProvider {

	public const API_URL = 'https://api.openai.com/v1/chat/completions';

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

	protected function api_url(): string {
		return self::API_URL;
	}

	protected function error_name(): string {
		return 'OpenAI';
	}

	/**
	 * Reasoning models (GPT-5 series, o-series) take max_completion_tokens instead of max_tokens and reject
	 * temperature / top_p / penalties.
	 */
	public static function is_reasoning_model( string $model ): bool {
		return (bool) preg_match( '/^(gpt-5|o\d)/i', $model );
	}

	protected function token_param_key( string $model ): string {
		return self::is_reasoning_model( $model ) ? 'max_completion_tokens' : 'max_tokens';
	}

	protected function supports_temperature( string $model ): bool {
		return ! self::is_reasoning_model( $model );
	}
}
