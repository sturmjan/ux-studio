<?php
/**
 * Result of ArticleWriter::write(): a sanitised article draft the host stores and later publishes.
 *
 * @package Ux1\AiCore
 */

declare(strict_types=1);

namespace Ux1\AiCore\Content;

final class ArticleDraft {

	/**
	 * @param string                                       $content       Sanitised HTML fragment (no h1).
	 * @param array<int, string>                           $tags
	 * @param array{input_tokens:int,output_tokens:int}    $usage         Summed over all attempts.
	 * @param int                                          $attempts      Provider calls made (1 + retries).
	 * @param array<int, string>                           $warnings      'too_short' (still below the minimum after the retries), 'no_json' (model ignored the JSON format; the HTML was used as is).
	 */
	public function __construct(
		public readonly string $title,
		public readonly string $content,
		public readonly string $excerpt,
		public readonly string $meta_title,
		public readonly string $meta_description,
		public readonly string $focus_keyword,
		public readonly array $tags,
		public readonly string $category_suggestion,
		public readonly int $word_count,
		public readonly array $usage,
		public readonly int $attempts = 1,
		public readonly array $warnings = array()
	) {}

	/** @return array<string, mixed> */
	public function to_array(): array {
		return array(
			'title'               => $this->title,
			'content'             => $this->content,
			'excerpt'             => $this->excerpt,
			'meta_title'          => $this->meta_title,
			'meta_description'    => $this->meta_description,
			'focus_keyword'       => $this->focus_keyword,
			'tags'                => $this->tags,
			'category_suggestion' => $this->category_suggestion,
			'word_count'          => $this->word_count,
			'usage'               => $this->usage,
			'attempts'            => $this->attempts,
			'warnings'            => $this->warnings,
		);
	}
}
