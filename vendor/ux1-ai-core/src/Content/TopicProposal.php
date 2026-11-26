<?php
/**
 * One proposed article topic. The id is a stable hash of the normalised title, so a host can store proposals and
 * match them again after a regenerate or a reload.
 *
 * @package Ux1\AiCore
 */

declare(strict_types=1);

namespace Ux1\AiCore\Content;

final class TopicProposal {

	/**
	 * @param array<int, string> $variants Alternative titles for the same article (A/B choice).
	 */
	public function __construct(
		public readonly string $id,
		public readonly string $title,
		public readonly string $summary,
		public readonly string $focus_keyword,
		public readonly string $article_type,
		public readonly array $variants = array(),
		public readonly string $category = ''
	) {}

	public static function make_id( string $title ): string {
		return substr( sha1( ContentText::normalize( $title ) ), 0, 12 );
	}

	/** @return array{id:string,title:string,summary:string,focus_keyword:string,article_type:string,variants:array<int,string>,category:string} */
	public function to_array(): array {
		return array(
			'id'            => $this->id,
			'title'         => $this->title,
			'summary'       => $this->summary,
			'focus_keyword' => $this->focus_keyword,
			'article_type'  => $this->article_type,
			'variants'      => $this->variants,
			'category'      => $this->category,
		);
	}

	/** @param array<string, mixed> $data Output of to_array() (or a host row with the same keys). */
	public static function from_array( array $data ): self {
		$title = trim( (string) ( $data['title'] ?? '' ) );
		return new self(
			'' !== (string) ( $data['id'] ?? '' ) ? (string) $data['id'] : self::make_id( $title ),
			$title,
			trim( (string) ( $data['summary'] ?? '' ) ),
			trim( (string) ( $data['focus_keyword'] ?? '' ) ),
			(string) ( $data['article_type'] ?? '' ),
			array_values( array_filter( array_map( 'strval', (array) ( $data['variants'] ?? array() ) ), 'strlen' ) ),
			trim( (string) ( $data['category'] ?? '' ) )
		);
	}
}
