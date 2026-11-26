<?php
/**
 * Builds the best available embedding provider.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\AiAssistant\Rag;

use Ux1\AiCore\Rag\EmbeddingProvider;
use Ux1\AiCore\Rag\FallbackEmbedding;
use Ux1\AiCore\Rag\OpenAiEmbedding;
use UxStudio\Core\Security;
use UxStudio\Modules\AiAssistant\ProviderFactory;
use UxStudio\Modules\AiAssistant\WpHttpClient;

defined( 'ABSPATH' ) || exit;

/**
 * Embeddings are always computed via OpenAI's dedicated embeddings endpoint
 * (AiProviderInterface has no embedding method - chat providers and the
 * embedding model are independent concerns), reusing the SAME encrypted
 * OpenAI API key already managed by AiAssistant\ProviderFactory/Security so
 * there is only one place to configure it. Falls back to a local TF-IDF
 * pseudo-embedding when no OpenAI key is set, so RAG search never hard-fails.
 */
final class EmbeddingFactory {

	/**
	 * Build the best available embedding provider.
	 */
	public static function create(): EmbeddingProvider {
		$api_key = Security::get_secret( ProviderFactory::secret_option( 'openai' ) );

		if ( '' !== $api_key ) {
			return new OpenAiEmbedding( $api_key, new WpHttpClient() );
		}

		return new FallbackEmbedding();
	}

	/**
	 * Id of the best available provider ('openai' or 'fallback').
	 */
	public static function get_best_available(): string {
		return '' !== Security::get_secret( ProviderFactory::secret_option( 'openai' ) ) ? 'openai' : 'fallback';
	}

	/**
	 * Whether any vectors have been generated yet.
	 */
	public static function is_vector_search_available(): bool {
		global $wpdb;
		$table = "{$wpdb->prefix}uxstudio_ai_assistant_vectors";
		if ( $wpdb->get_var( "SHOW TABLES LIKE '{$table}'" ) !== $table ) {
			return false;
		}
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ) > 0;
	}

	/**
	 * Current embedding provider status, for the RAG stats endpoint.
	 *
	 * @return array{provider:string,model:string,dimensions:int,available:bool,has_vectors:bool}
	 */
	public static function get_status(): array {
		$provider = self::create();

		return array(
			'provider'    => $provider->get_id(),
			'model'       => $provider->get_model_name(),
			'dimensions'  => $provider->get_dimensions(),
			'available'   => $provider->is_available(),
			'has_vectors' => self::is_vector_search_available(),
		);
	}
}
