<?php
/**
 * Builds AI provider adapters, reading API keys from encrypted secrets.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\AiAssistant;

use UxStudio\Core\Security;
use UxStudio\Core\Settings;
use UxStudio\Modules\AiAssistant\Providers\AiProviderInterface;
use UxStudio\Modules\AiAssistant\Providers\ClaudeProvider;
use UxStudio\Modules\AiAssistant\Providers\DeepSeekProvider;
use UxStudio\Modules\AiAssistant\Providers\OpenAiProvider;

defined( 'ABSPATH' ) || exit;

/**
 * API keys are stored per-provider via Security::store_secret() under
 * uxstudio_secret_ai_assistant_<provider>_api_key - never in the plain
 * uxstudio_ai_assistant settings option.
 */
final class ProviderFactory {

	private const SECRET_PREFIX = 'uxstudio_secret_ai_assistant_';

	private static ?Settings $settings = null;

	private static function settings(): Settings {
		if ( null === self::$settings ) {
			self::$settings = new Settings( 'uxstudio_ai_assistant' );
		}
		return self::$settings;
	}

	/**
	 * Secret option name for a provider's API key.
	 */
	public static function secret_option( string $provider_id ): string {
		return self::SECRET_PREFIX . $provider_id . '_api_key';
	}

	public static function create( ?string $provider_id = null ): AiProviderInterface {
		$provider_id = $provider_id ?? (string) self::settings()->get( 'default_provider', 'claude' );

		switch ( $provider_id ) {
			case 'claude':
				return new ClaudeProvider( Security::get_secret( self::secret_option( 'claude' ) ) );

			case 'openai':
				return new OpenAiProvider( Security::get_secret( self::secret_option( 'openai' ) ) );

			case 'deepseek':
				return new DeepSeekProvider( Security::get_secret( self::secret_option( 'deepseek' ) ) );

			default:
				throw new \RuntimeException( "Unknown AI provider: {$provider_id}" );
		}
	}

	/**
	 * @return array<string, array{label:string,models:array<string,string>}>
	 */
	public static function get_all_providers(): array {
		return array(
			'claude'   => array(
				'label'  => 'Claude (Anthropic)',
				'models' => ( new ClaudeProvider( '' ) )->get_models(),
			),
			'openai'   => array(
				'label'  => 'ChatGPT (OpenAI)',
				'models' => ( new OpenAiProvider( '' ) )->get_models(),
			),
			'deepseek' => array(
				'label'  => 'DeepSeek',
				'models' => ( new DeepSeekProvider( '' ) )->get_models(),
			),
		);
	}

	public static function has_api_key( string $provider_id ): bool {
		return '' !== Security::get_secret( self::secret_option( $provider_id ) );
	}
}
