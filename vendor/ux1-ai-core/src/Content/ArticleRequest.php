<?php
/**
 * Input of ArticleWriter.
 *
 * @package Ux1\AiCore
 */

declare(strict_types=1);

namespace Ux1\AiCore\Content;

final class ArticleRequest {

	public const LENGTHS = array( 'short', 'medium', 'long', 'extra-long' );

	public readonly string $length;

	public readonly int $max_retries;

	/**
	 * @param string                                          $topic           Title / topic of the article (a TopicProposal title).
	 * @param string                                          $article_type    ArticleTypes id; '' or unknown = plain informative article.
	 * @param string                                          $tone            e.g. professional, friendly (free text is fine).
	 * @param string                                          $length          short | medium | long | extra-long; unknown falls back to medium.
	 * @param string                                          $language        Language code (cs, en, ...).
	 * @param string                                          $keyword         Focus keyword; '' = let the model choose one.
	 * @param string                                          $instructions    Custom instructions from the user (highest priority after safety rules).
	 * @param string                                          $brand_voice     Optional brand voice text.
	 * @param string                                          $site_name       Optional site / brand name.
	 * @param string                                          $site_context    Optional standing site description (audience, focus).
	 * @param string                                          $summary         Optional angle / summary (TopicProposal::$summary).
	 * @param bool                                            $with_images     true = the model may write <img> tags and must give every one an alt text.
	 * @param array<int, array{title:string,url:string}>      $link_candidates Existing pages the model may link to internally.
	 * @param int                                             $max_retries     Re-asks when the article is below the minimum length (CA: 1). Clamped to 0..3.
	 * @param string                                          $today           Y-m-d used for the date hint; '' = today's date.
	 */
	public function __construct(
		public readonly string $topic,
		public readonly string $article_type = '',
		public readonly string $tone = 'professional',
		string $length = 'medium',
		public readonly string $language = 'cs',
		public readonly string $keyword = '',
		public readonly string $instructions = '',
		public readonly string $brand_voice = '',
		public readonly string $site_name = '',
		public readonly string $site_context = '',
		public readonly string $summary = '',
		public readonly bool $with_images = false,
		public readonly array $link_candidates = array(),
		int $max_retries = 1,
		public readonly string $today = ''
	) {
		$this->length      = in_array( $length, self::LENGTHS, true ) ? $length : 'medium';
		$this->max_retries = max( 0, min( 3, $max_retries ) );
	}
}
