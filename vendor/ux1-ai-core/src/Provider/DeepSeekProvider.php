<?php
/**
 * DeepSeek (OpenAI-compatible Chat Completions).
 *
 * @package Ux1\AiCore
 */

declare(strict_types=1);

namespace Ux1\AiCore\Provider;

final class DeepSeekProvider extends OpenAiCompatibleProvider {

	public const API_URL = 'https://api.deepseek.com/v1/chat/completions';

	public function get_id(): string {
		return 'deepseek';
	}

	public function get_label(): string {
		return 'DeepSeek';
	}

	public function get_models(): array {
		return array(
			'deepseek-chat'     => 'DeepSeek Chat (general purpose)',
			'deepseek-reasoner' => 'DeepSeek Reasoner (chain-of-thought)',
		);
	}

	protected function api_url(): string {
		return self::API_URL;
	}

	protected function error_name(): string {
		return 'DeepSeek';
	}
}
