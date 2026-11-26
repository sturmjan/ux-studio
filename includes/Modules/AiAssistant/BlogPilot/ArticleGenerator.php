<?php
/**
 * Thin adapter between a Blog Pilot generator row and the shared content pipeline of the AI core: it turns the
 * generator settings into an ArticleRequest, hands the plugin's provider/model to ArticleWriter and returns the draft.
 * Prompting, the length retry and the HTML cleaning all live in the core (Ux1\AiCore\Content).
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\AiAssistant\BlogPilot;

use Ux1\AiCore\Content\ArticleDraft;
use Ux1\AiCore\Content\ArticleRequest;
use Ux1\AiCore\Content\ArticleTypes as CoreArticleTypes;
use Ux1\AiCore\Content\ArticleWriter;
use Ux1\AiCore\Provider\TextProvider;
use UxStudio\Modules\AiAssistant\ProviderFactory;
use UxStudio\Modules\AiAssistant\Providers\CoreProvider;

defined( 'ABSPATH' ) || exit;

final class ArticleGenerator {

	/**
	 * Resolves the provider of a generator: fn( ?string $provider_id, string $model ): array{provider:TextProvider,id:string,model:string}.
	 *
	 * @var callable
	 */
	private $resolver;

	/**
	 * @param callable|null $resolver Test seam; the default uses the plugin's ProviderFactory.
	 */
	public function __construct( ?callable $resolver = null ) {
		$this->resolver = $resolver ?? array( self::class, 'resolve_default' );
	}

	/**
	 * @return array{provider:TextProvider,id:string,model:string}
	 */
	public static function resolve_default( ?string $provider_id, string $model ): array {
		$wrapped = ProviderFactory::create( $provider_id );
		$core    = $wrapped instanceof CoreProvider ? $wrapped->core() : $wrapped;

		if ( '' === $model ) {
			$models = $wrapped->get_models();
			$model  = (string) array_key_first( $models );
		}

		return array(
			'provider' => $core,
			'id'       => $wrapped->get_id(),
			'model'    => $model,
		);
	}

	/**
	 * @return array{provider:TextProvider,id:string,model:string}
	 */
	public function provider_for( ?string $provider_id, string $model ): array {
		return ( $this->resolver )( ( null !== $provider_id && '' !== $provider_id ) ? $provider_id : null, $model );
	}

	/**
	 * Builds the core request from the generator config (the columns of the old generator rows are enough).
	 *
	 * @param array<string, mixed>               $config Generator config (language, tone, length, custom_instructions, ...).
	 * @param array{summary?:string,keyword?:string} $meta   Per-topic extras (angle, focus keyword).
	 * @param array<string, mixed>               $site   SiteContext::for_article().
	 */
	public function build_request( array $config, string $topic, string $article_type, array $meta = array(), array $site = array() ): ArticleRequest {
		return new ArticleRequest(
			$topic,
			null !== CoreArticleTypes::get( $article_type ) ? $article_type : '',
			'' !== trim( (string) ( $config['tone'] ?? '' ) ) ? (string) $config['tone'] : 'professional',
			(string) ( $config['length'] ?? 'medium' ),
			'' !== (string) ( $config['language'] ?? '' ) ? (string) $config['language'] : 'cs',
			(string) ( $meta['keyword'] ?? '' ),
			(string) ( $config['custom_instructions'] ?? '' ),
			(string) ( $config['brand_voice'] ?? '' ),
			(string) ( $site['site_name'] ?? '' ),
			(string) ( $site['site_context'] ?? '' ),
			(string) ( $meta['summary'] ?? '' ),
			false,
			is_array( $site['link_candidates'] ?? null ) ? $site['link_candidates'] : array(),
			1,
			(string) ( $site['today'] ?? '' )
		);
	}

	/**
	 * Writes one article for a generator.
	 *
	 * @param object                             $generator Row of the generators table (provider, model, config).
	 * @param array{summary?:string,keyword?:string} $meta
	 * @param array<string, mixed>               $site
	 * @return array{draft:ArticleDraft,provider:string,model:string}
	 * @throws \RuntimeException Provider failure or an unusable answer (messages of the core, unchanged).
	 */
	public function write( object $generator, string $topic, string $article_type, array $meta = array(), array $site = array() ): array {
		$resolved = $this->provider_for( (string) ( $generator->provider ?? '' ), (string) ( $generator->model ?? '' ) );
		$request  = $this->build_request( (array) ( $generator->config ?? array() ), $topic, $article_type, $meta, $site );

		return array(
			'draft'    => ArticleWriter::write( $resolved['provider'], $resolved['model'], $request ),
			'provider' => $resolved['id'],
			'model'    => $resolved['model'],
		);
	}
}
