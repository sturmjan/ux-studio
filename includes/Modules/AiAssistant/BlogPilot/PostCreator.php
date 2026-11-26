<?php
/**
 * Generates one Blog Pilot article through the shared content pipeline (ArticleGenerator -> core ArticleWriter) and
 * creates, or rewrites, the resulting WordPress post: status (draft / pending / publish / future with a planned
 * datetime), category, tags, author and token usage logging.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\AiAssistant\BlogPilot;

use DateTimeImmutable;
use DateTimeZone;
use Ux1\AiCore\Content\ArticleDraft;
use UxStudio\Modules\AiAssistant\ContentHelper;
use UxStudio\Modules\AiAssistant\UsageTracker;

defined( 'ABSPATH' ) || exit;

final class PostCreator {

	public const STATUSES = array( 'draft', 'pending', 'publish', 'future' );

	private ArticleGenerator $writer;

	/** @var callable():DateTimeImmutable */
	private $clock;

	/**
	 * @param callable|null $clock Returns "now" in the site time zone (test seam).
	 */
	public function __construct( ?ArticleGenerator $writer = null, ?callable $clock = null ) {
		$this->writer = $writer ?? new ArticleGenerator();
		$this->clock  = $clock ?? static fn(): DateTimeImmutable => new DateTimeImmutable( 'now', new DateTimeZone( wp_timezone_string() ) );
	}

	/**
	 * Generates an article via AI and creates the WP post.
	 *
	 * @param array{summary?:string,keyword?:string} $meta Optional per-topic angle and focus keyword.
	 * @return array{post_id:int,title:string,usage:array{input_tokens:int,output_tokens:int},status:string,scheduled_for:?string,warnings:array<int,string>,keyword:string,summary:string}
	 */
	public function generate( object $generator, string $topic, string $article_type, array $meta = array() ): array {
		$config  = (array) $generator->config;
		$written = $this->writer->write( $generator, $topic, $article_type, $meta, SiteContext::for_article() );
		$draft   = $written['draft'];

		$warnings = $draft->warnings;
		$status   = $this->target_status( $config );
		$slot     = null;
		if ( 'future' === $status ) {
			try {
				$slot = $this->next_slot( $config );
			} catch ( \Throwable $e ) {
				// A plan that cannot be satisfied must not lose a paid article: keep it as a draft for review.
				$status     = 'draft';
				$warnings[] = 'schedule_failed';
			}
		}

		$post_id = $this->create_post( $draft, $config, $status, $slot );

		$this->log_usage( $written['provider'], $written['model'], $draft->usage );

		return array(
			'post_id'       => $post_id,
			'title'         => $draft->title,
			'usage'         => $draft->usage,
			'status'        => $status,
			'scheduled_for' => $slot ? $slot->format( 'Y-m-d H:i:s' ) : null,
			'warnings'      => array_values( array_unique( $warnings ) ),
			'keyword'       => $draft->focus_keyword,
			'summary'       => (string) ( $meta['summary'] ?? '' ),
		);
	}

	/**
	 * Writes the article again and replaces title, body and excerpt of an existing (not yet published) post. Status,
	 * date, category, tags and author stay as they are.
	 *
	 * @param array{summary?:string,keyword?:string} $meta
	 * @return array{post_id:int,title:string,usage:array{input_tokens:int,output_tokens:int},warnings:array<int,string>}
	 */
	public function regenerate( int $post_id, object $generator, string $topic, string $article_type, array $meta = array() ): array {
		$written = $this->writer->write( $generator, $topic, $article_type, $meta, SiteContext::for_article() );
		$draft   = $written['draft'];

		$result = wp_update_post(
			array(
				'ID'           => $post_id,
				'post_title'   => sanitize_text_field( $draft->title ),
				'post_content' => wp_kses_post( $draft->content ),
				'post_excerpt' => sanitize_text_field( $draft->excerpt ),
			),
			true
		);
		if ( is_wp_error( $result ) ) {
			throw new \RuntimeException(
				sprintf(
					/* translators: %s: WP_Error message */
					__( 'Error updating the post: %s', 'ux-studio' ),
					$result->get_error_message()
				)
			);
		}

		$this->log_usage( $written['provider'], $written['model'], $draft->usage );

		return array(
			'post_id'  => $post_id,
			'title'    => $draft->title,
			'usage'    => $draft->usage,
			'warnings' => $draft->warnings,
		);
	}

	/**
	 * The status the generator asks for; anything unknown (old rows) is a draft.
	 *
	 * @param array<string, mixed> $config
	 */
	private function target_status( array $config ): string {
		$status = (string) ( $config['post_status'] ?? 'draft' );
		return in_array( $status, self::STATUSES, true ) ? $status : 'draft';
	}

	/**
	 * The next free publication datetime according to the generator's publish plan (default plan when none is stored).
	 *
	 * @param array<string, mixed> $config
	 */
	private function next_slot( array $config ): DateTimeImmutable {
		$now      = ( $this->clock )();
		$timezone = $now->getTimezone()->getName();
		$occupied = PublishSlots::occupied( $now->format( 'Y-m-d H:i:s' ) );
		$plan     = PublishSlots::plan( (array) ( $config['publish_plan'] ?? array() ), 1, $occupied, $now, $timezone );

		return $plan[0];
	}

	/**
	 * @param array{input_tokens:int,output_tokens:int} $usage
	 */
	private function log_usage( string $provider, string $model, array $usage ): void {
		UsageTracker::log( $provider, $model, 'blog_pilot', (int) $usage['input_tokens'], (int) $usage['output_tokens'] );
	}

	/**
	 * @param array<string, mixed> $config
	 */
	private function create_post( ArticleDraft $draft, array $config, string $status, ?DateTimeImmutable $slot ): int {
		$author_id = (int) ( $config['author_id'] ?? 0 );

		$post_data = array(
			'post_title'   => sanitize_text_field( $draft->title ),
			'post_content' => wp_kses_post( $draft->content ),
			'post_excerpt' => sanitize_text_field( $draft->excerpt ),
			'post_status'  => $status,
			'post_type'    => 'post',
		);

		if ( 'future' === $status && $slot ) {
			$post_data['post_date']     = $slot->format( 'Y-m-d H:i:s' );
			$post_data['post_date_gmt'] = $slot->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
		}

		if ( $author_id > 0 ) {
			$post_data['post_author'] = $author_id;
		}

		$post_id = wp_insert_post( $post_data, true );

		if ( is_wp_error( $post_id ) ) {
			throw new \RuntimeException(
				sprintf(
					/* translators: %s: WP_Error message */
					__( 'Error creating the post: %s', 'ux-studio' ),
					$post_id->get_error_message()
				)
			);
		}

		$category_id = (int) ( $config['category_id'] ?? 0 );
		if ( $category_id > 0 ) {
			wp_set_post_categories( $post_id, array( $category_id ) );
		} elseif ( '' !== $draft->category_suggestion ) {
			$cat_id = ContentHelper::get_or_create_category( sanitize_text_field( $draft->category_suggestion ) );
			if ( $cat_id > 0 ) {
				wp_set_post_categories( $post_id, array( $cat_id ) );
			}
		}

		if ( $draft->tags ) {
			$tag_ids = ContentHelper::get_or_create_tags( array_map( 'sanitize_text_field', $draft->tags ) );
			if ( ! empty( $tag_ids ) ) {
				wp_set_post_tags( $post_id, $tag_ids );
			}
		}

		update_post_meta( $post_id, '_uxstudio_blog_pilot', '1' );

		return (int) $post_id;
	}
}
