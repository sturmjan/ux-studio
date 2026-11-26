<?php
/**
 * "Suggest topics" for the Blog Pilot generator form: a brief (by default built from the site name and tagline) goes to
 * the core TopicPlanner together with the titles that already exist, so the AI proposes fresh topics. The user ticks,
 * edits or rejects proposals in the UI; a rejected one can be replaced by one new proposal.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\AiAssistant\BlogPilot;

use Ux1\AiCore\Content\ArticleTypes as CoreArticleTypes;
use Ux1\AiCore\Content\TopicPlanner;
use Ux1\AiCore\Content\TopicProposal;
use Ux1\AiCore\Content\TopicRequest;
use UxStudio\Modules\AiAssistant\UsageTracker;

defined( 'ABSPATH' ) || exit;

final class TopicSuggester {

	public const MAX_COUNT = 20;

	private ArticleGenerator $generator;

	public function __construct( ?ArticleGenerator $generator = null ) {
		$this->generator = $generator ?? new ArticleGenerator();
	}

	/**
	 * Defaults of the form: brief from the site, how many titles already exist.
	 *
	 * @return array{brief:string,language:string,existing_count:int}
	 */
	public function defaults(): array {
		return array(
			'brief'          => SiteContext::default_brief(),
			'language'       => self::site_language(),
			'existing_count' => count( SiteContext::existing_titles( 150 ) ),
		);
	}

	/**
	 * Proposes topics.
	 *
	 * @param array<string, mixed> $input brief, count, language, types, tone, audience, existing_topics, provider, model.
	 * @return array{proposals:array<int,array<string,mixed>>,usage:array{input_tokens:int,output_tokens:int}}
	 * @throws \RuntimeException Provider failure or no usable proposal (messages of the core, unchanged).
	 */
	public function suggest( array $input ): array {
		$resolved = $this->generator->provider_for( (string) ( $input['provider'] ?? '' ), (string) ( $input['model'] ?? '' ) );
		$request  = $this->build_request( $input );
		$usage    = array(
			'input_tokens'  => 0,
			'output_tokens' => 0,
		);

		$proposals = TopicPlanner::propose( $resolved['provider'], $resolved['model'], $request, $usage );
		$this->log_usage( $resolved['id'], $resolved['model'], $usage );

		return array(
			'proposals' => array_map( static fn( TopicProposal $p ): array => $p->to_array(), $proposals ),
			'usage'     => $usage,
		);
	}

	/**
	 * Replaces one rejected proposal with a new one.
	 *
	 * @param array<string, mixed>             $input    Same as suggest().
	 * @param array<string, mixed>             $rejected The proposal to replace (to_array() shape).
	 * @param array<int, array<string, mixed>> $current  Proposals the UI currently shows (kept out of the answer).
	 * @return array{proposal:array<string,mixed>,usage:array{input_tokens:int,output_tokens:int}}
	 * @throws \RuntimeException
	 */
	public function regenerate_one( array $input, array $rejected, array $current = array() ): array {
		$resolved = $this->generator->provider_for( (string) ( $input['provider'] ?? '' ), (string) ( $input['model'] ?? '' ) );
		$request  = $this->build_request( $input );
		$usage    = array(
			'input_tokens'  => 0,
			'output_tokens' => 0,
		);

		$proposal = TopicPlanner::regenerate_one(
			$resolved['provider'],
			$resolved['model'],
			$request,
			TopicProposal::from_array( $rejected ),
			array_map( static fn( array $p ): TopicProposal => TopicProposal::from_array( $p ), $current ),
			$usage
		);
		$this->log_usage( $resolved['id'], $resolved['model'], $usage );

		return array(
			'proposal' => $proposal->to_array(),
			'usage'    => $usage,
		);
	}

	/**
	 * @param array<string, mixed> $input
	 */
	public function build_request( array $input ): TopicRequest {
		$brief = trim( (string) ( $input['brief'] ?? '' ) );
		if ( '' === $brief ) {
			$brief = SiteContext::default_brief();
		}

		$existing = array_merge(
			SiteContext::existing_titles( 150 ),
			array_map( 'strval', (array) ( $input['existing_topics'] ?? array() ) )
		);
		$types    = array_values(
			array_filter(
				array_map( 'strval', (array) ( $input['types'] ?? array() ) ),
				static fn( string $id ): bool => null !== CoreArticleTypes::get( $id )
			)
		);

		return new TopicRequest(
			$brief,
			max( 1, min( self::MAX_COUNT, (int) ( $input['count'] ?? 5 ) ) ),
			'' !== (string) ( $input['language'] ?? '' ) ? (string) $input['language'] : self::site_language(),
			$existing,
			$types,
			(string) ( $input['audience'] ?? '' ),
			(string) ( $input['tone'] ?? '' ),
			(string) get_bloginfo( 'description' ),
			'',
			array(),
			false,
			(string) wp_date( 'Y-m-d' )
		);
	}

	/**
	 * @param array{input_tokens:int,output_tokens:int} $usage
	 */
	private function log_usage( string $provider, string $model, array $usage ): void {
		UsageTracker::log( $provider, $model, 'blog_pilot_topics', (int) ( $usage['input_tokens'] ?? 0 ), (int) ( $usage['output_tokens'] ?? 0 ) );
	}

	private static function site_language(): string {
		$locale = strtolower( (string) get_locale() );
		$code   = substr( $locale, 0, 2 );
		return isset( ArticleTypes::get_languages()[ $code ] ) ? $code : 'en';
	}
}
