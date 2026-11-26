<?php
/**
 * Builds AI provider adapters, reading API keys from encrypted secrets.
 *
 * The providers themselves live in the shared AI core (ux1-ai-core, Ux1\AiCore\Provider); this factory only feeds
 * them the plugin's secrets, settings and HTTP port and wraps them in CoreProvider (the plugin contract).
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\AiAssistant;

use Ux1\AiCore\Http\HttpClient;
use Ux1\AiCore\Provider\ClaudeCodeBridgeProvider;
use Ux1\AiCore\Provider\ClaudeProvider;
use Ux1\AiCore\Provider\DeepSeekProvider;
use Ux1\AiCore\Provider\OpenAiProvider;
use Ux1\AiCore\Provider\TextProvider;
use UxStudio\Core\Security;
use UxStudio\Core\Settings;
use UxStudio\Modules\AiAssistant\Providers\AiProviderInterface;
use UxStudio\Modules\AiAssistant\Providers\CoreProvider;

defined( 'ABSPATH' ) || exit;

/**
 * API keys are stored per-provider via Security::store_secret() under
 * uxstudio_secret_ai_assistant_<provider>_api_key - never in the plain
 * uxstudio_ai_assistant settings option.
 */
final class ProviderFactory {

	private const SECRET_PREFIX = 'uxstudio_secret_ai_assistant_';

	/** Provider ids in display and fallback order. */
	private const PROVIDER_IDS = array( 'claude', 'openai', 'deepseek', 'claude_code' );

	private static ?Settings $settings = null;

	private static ?HttpClient $http = null;

	private static function settings(): Settings {
		if ( null === self::$settings ) {
			self::$settings = new Settings( 'uxstudio_ai_assistant' );
		}
		return self::$settings;
	}

	private static function http(): HttpClient {
		if ( null === self::$http ) {
			self::$http = new WpHttpClient();
		}
		return self::$http;
	}

	/**
	 * Secret option name for a provider's API key.
	 */
	public static function secret_option( string $provider_id ): string {
		return self::SECRET_PREFIX . $provider_id . '_api_key';
	}

	public static function create( ?string $provider_id = null ): AiProviderInterface {
		$provider_id = $provider_id ?? (string) self::settings()->get( 'default_provider', 'claude' );
		$secret      = in_array( $provider_id, self::PROVIDER_IDS, true ) ? Security::get_secret( self::secret_option( $provider_id ) ) : '';

		return new CoreProvider( self::build( $provider_id, $secret ) );
	}

	/**
	 * @return array<string, array{label:string,models:array<string,string>}>
	 */
	public static function get_all_providers(): array {
		$providers = array();
		foreach ( self::PROVIDER_IDS as $id ) {
			$provider          = self::build( $id, '' );
			$providers[ $id ] = array(
				'label'  => $provider->get_label(),
				'models' => $provider->get_models(),
			);
		}
		return $providers;
	}

	public static function has_api_key( string $provider_id ): bool {
		return '' !== Security::get_secret( self::secret_option( $provider_id ) );
	}

	private static function build( string $provider_id, string $secret ): TextProvider {
		switch ( $provider_id ) {
			case 'claude':
				return new ClaudeProvider( $secret, self::http() );

			case 'openai':
				return new OpenAiProvider( $secret, self::http() );

			case 'deepseek':
				return new DeepSeekProvider( $secret, self::http() );

			case 'claude_code':
				return new ClaudeCodeBridgeProvider(
					$secret,
					(string) self::settings()->get( 'claude_code_url', '' ),
					self::http(),
					(string) self::settings()->get( 'claude_code_workdir', '' )
				);

			default:
				throw new \RuntimeException( "Unknown AI provider: {$provider_id}" );
		}
	}
}
