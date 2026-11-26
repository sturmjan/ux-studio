<?php
/**
 * Writes one article draft with a TextProvider. Merges the article prompt of UX1 Studio Blog Pilot (JSON answer,
 * article-type hints, tag/category suggestions) with the one of CA AI Agent (HTML body, mandatory word ranges, retry
 * when the article is too short, SEO structure rules, date context, internal-link candidates).
 *
 * The host stores the draft, runs this in the background (one article per tick) and publishes it.
 *
 * @package Ux1\AiCore
 */

declare(strict_types=1);

namespace Ux1\AiCore\Content;

use Ux1\AiCore\Provider\TextProvider;

final class ArticleWriter {

	/**
	 * Word ranges per length. min/max are the briefing; retry_below is the count under which the article is re-asked
	 * (CA: 280 / 550 / 1100). extra-long comes from Blog Pilot (1800-2500).
	 */
	public const LENGTHS = array(
		'short'      => array(
			'min'         => 300,
			'max'         => 500,
			'retry_below' => 280,
		),
		'medium'     => array(
			'min'         => 600,
			'max'         => 1000,
			'retry_below' => 550,
		),
		'long'       => array(
			'min'         => 1200,
			'max'         => 1800,
			'retry_below' => 1100,
		),
		'extra-long' => array(
			'min'         => 1800,
			'max'         => 2500,
			'retry_below' => 1700,
		),
	);

	/**
	 * @throws \RuntimeException When the provider fails (before any draft exists) or the answer is empty/unusable.
	 */
	public static function write( TextProvider $p, string $model, ArticleRequest $r ): ArticleDraft {
		$system   = self::system_prompt( $r );
		$user     = self::user_prompt( $r );
		$range    = self::LENGTHS[ $r->length ];
		$usage    = array(
			'input_tokens'  => 0,
			'output_tokens' => 0,
		);
		$best     = null;
		$attempts = 0;
		$last_err = null;

		for ( $attempt = 0; $attempt <= $r->max_retries; $attempt++ ) {
			$prompt = $user;
			if ( null !== $best ) {
				$prompt .= "\n\nYour previous attempt was too short ({$best['words']} words, the minimum is {$range['retry_below']}). "
					. 'Write the article AGAIN, clearly longer: expand every section with practical examples, concrete details, tips and an FAQ where it fits. '
					. 'Keep the title and tone. Do not apologise and return the same JSON format.';
			}
			++$attempts;
			try {
				$result = $p->generate_content( $system, $prompt, $model, array( 'max_tokens' => 8192 ) );
			} catch ( \RuntimeException $e ) {
				if ( null === $best ) {
					throw $e;
				}
				$last_err = $e;
				break; // Keep the short draft we already have rather than lose it.
			}
			$usage['input_tokens']  += (int) ( $result['usage']['input_tokens'] ?? 0 );
			$usage['output_tokens'] += (int) ( $result['usage']['output_tokens'] ?? 0 );

			$parsed = self::parse( (string) ( $result['content'] ?? '' ), $r );
			if ( null === $parsed ) {
				if ( null === $best && $attempt === $r->max_retries ) {
					throw new \RuntimeException( 'Article writer: the model answer could not be parsed into an article.' );
				}
				continue;
			}
			if ( null === $best || $parsed['words'] > $best['words'] ) {
				$best = $parsed;
			}
			if ( $best['words'] >= $range['retry_below'] ) {
				break;
			}
		}

		if ( null === $best ) {
			throw $last_err ?? new \RuntimeException( 'Article writer: the model answer could not be parsed into an article.' );
		}

		$warnings = $best['warnings'];
		if ( $best['words'] < $range['retry_below'] ) {
			$warnings[] = 'too_short';
		}
		return new ArticleDraft(
			$best['title'],
			$best['content'],
			$best['excerpt'],
			$best['meta_title'],
			$best['meta_description'],
			$best['focus_keyword'],
			$best['tags'],
			$best['category'],
			$best['words'],
			$usage,
			$attempts,
			array_values( array_unique( $warnings ) )
		);
	}

	/**
	 * Turns a model answer into draft fields (sanitised), or null when there is no article in it.
	 *
	 * @return array{title:string,content:string,excerpt:string,meta_title:string,meta_description:string,focus_keyword:string,tags:array<int,string>,category:string,words:int,warnings:array<int,string>}|null
	 */
	public static function parse( string $raw, ArticleRequest $r ): ?array {
		$warnings = array();
		$data     = ContentText::extract_json( $raw );
		$content  = '';
		if ( is_array( $data ) && ! array_is_list( $data ) ) {
			$content = (string) ( $data['content'] ?? $data['post_content'] ?? '' );
		}
		if ( '' === trim( $content ) ) {
			// No usable JSON: accept a bare HTML article rather than waste a paid answer.
			if ( preg_match( '/<(h2|p)\b/i', $raw ) ) {
				$data     = array();
				$content  = (string) preg_replace( '/^```(?:html)?\s*|\s*```$/i', '', trim( $raw ) );
				$warnings[] = 'no_json';
			} else {
				return null;
			}
		}
		/** @var array<string, mixed> $data */
		$content = ArticleHtml::clean( $content, $r->with_images );
		if ( '' === $content ) {
			return null;
		}

		$title   = trim( (string) strip_tags( (string) ( $data['title'] ?? $data['post_title'] ?? '' ) ) );
		$title   = '' !== $title ? $title : trim( $r->topic );
		$excerpt = trim( (string) strip_tags( (string) ( $data['excerpt'] ?? $data['post_excerpt'] ?? '' ) ) );
		$keyword = trim( (string) ( $data['focus_keyword'] ?? '' ) );
		$meta_t  = trim( (string) strip_tags( (string) ( $data['meta_title'] ?? '' ) ) );
		$meta_d  = trim( (string) strip_tags( (string) ( $data['meta_description'] ?? '' ) ) );

		$tags = array();
		foreach ( (array) ( $data['tags'] ?? $data['post_tags'] ?? array() ) as $tag ) {
			$tag = trim( (string) ( is_array( $tag ) ? '' : $tag ) );
			if ( '' !== $tag ) {
				$tags[] = $tag;
			}
		}

		return array(
			'title'            => $title,
			'content'          => $content,
			'excerpt'          => ContentText::truncate( $excerpt, 300 ),
			'meta_title'       => ContentText::truncate( '' !== $meta_t ? $meta_t : $title, 60 ),
			'meta_description' => ContentText::truncate( '' !== $meta_d ? $meta_d : $excerpt, 160 ),
			'focus_keyword'    => mb_strtolower( '' !== $keyword ? $keyword : trim( $r->keyword ), 'UTF-8' ),
			'tags'             => array_slice( array_values( array_unique( $tags ) ), 0, 8 ),
			'category'         => trim( (string) ( $data['category_suggestion'] ?? $data['category'] ?? '' ) ),
			'words'            => ContentText::word_count( $content ),
			'warnings'         => $warnings,
		);
	}

	/** Word-range briefing line for the prompt (CA wording: the minimum is mandatory). */
	public static function length_briefing( string $length ): string {
		$range = self::LENGTHS[ $length ] ?? self::LENGTHS['medium'];
		return "MANDATORY length: {$range['min']}-{$range['max']} words of article text (HTML tags do not count). "
			. "Fewer than {$range['retry_below']} words is UNACCEPTABLE: if you run out of things to say, expand each section with practical examples, concrete details, tips and an FAQ.";
	}

	public static function system_prompt( ArticleRequest $r ): string {
		$language = ContentText::language_name( $r->language );
		$today    = '' !== $r->today ? $r->today : gmdate( 'Y-m-d' );
		$year     = (int) substr( $today, 0, 4 );

		$p  = "You are a professional editor and copywriter writing a high-quality, original article for a website. Write ONLY in {$language}.\n\n";
		$p .= "RULES:\n";
		$p .= '- Tone: ' . ( '' !== trim( $r->tone ) ? trim( $r->tone ) : 'professional' ) . ".\n";
		$p .= '- ' . self::length_briefing( $r->length ) . "\n";
		$p .= "- The article body is an HTML fragment. Allowed tags ONLY: h2, h3, p, ul, ol, li, strong, em, a, blockquote"
			. ( $r->with_images ? ', img' : '' ) . ". No Markdown, no html/head/body, no h1 (the title is separate), no inline styles, no scripts, no tables unless the article type needs a comparison.\n";
		$p .= "- Structure: a short opening paragraph (perex) that contains the focus keyword; then sections under h2 headings (at least 3 for medium and longer articles, h3 for subsections, never skip a level); a closing paragraph with a short summary or call to action.\n";
		$p .= "- Headings are descriptive and contain concrete keywords; never generic ones such as \"Introduction\" or \"Conclusion\".\n";
		$p .= "- In every section put 1-3 key phrases in <strong>. Use ul/ol lists for enumerations (steps, benefits, tips) instead of comma-separated sentences.\n";
		$p .= "- Short, clear sentences: average at most 22 words.\n";
		$p .= "- Use the focus keyword naturally (density 0.5-2.5 %, never stuffed) and put it in the title, the meta title and the first paragraph.\n";
		$p .= "- NEVER fabricate facts: no invented statistics, studies, quotes, prices, addresses, awards or people presented as real. If you are not sure of a fact, write generally. These rules override any article-type hint.\n";
		$p .= "- Links: include at least one external link (<a href=\"https://...\">) to a well-known authoritative page ONLY if you are certain the URL exists; never invent URLs. Link internally only to the pages listed below.\n";
		$p .= $r->with_images
			? "- Images: if you add <img>, every image MUST have a descriptive alt attribute in the article language. Do not invent image URLs; use only the ones the host gives you.\n"
			: "- Do NOT write any <img> tags: images are added later by the site.\n";
		$p .= "- Date context: today is {$today}. When the text mentions a year, use {$year} (or " . ( $year + 1 ) . " for the future); never present older years as current.\n";
		$p .= "- Craft a catchy title (the focus keyword near its start), a 1-2 sentence excerpt, a meta title of 50-60 characters, a meta description of 150-160 characters (with the keyword and a call to action) and 3-6 tags.\n";

		if ( '' !== trim( $r->site_name ) ) {
			$p .= "\nSITE: " . trim( $r->site_name ) . "\n";
		}
		if ( '' !== trim( $r->site_context ) ) {
			$p .= "\nSITE CONTEXT (audience, focus - keep to it):\n" . trim( $r->site_context ) . "\n";
		}
		if ( '' !== trim( $r->brand_voice ) ) {
			$p .= "\nBRAND VOICE (follow it):\n" . trim( $r->brand_voice ) . "\n";
		}
		if ( $r->link_candidates ) {
			$lines = array();
			foreach ( array_slice( $r->link_candidates, 0, 30 ) as $link ) {
				if ( '' !== trim( (string) ( $link['url'] ?? '' ) ) ) {
					$lines[] = '- ' . trim( (string) ( $link['title'] ?? '' ) ) . ' -> ' . trim( (string) $link['url'] );
				}
			}
			if ( $lines ) {
				$p .= "\nINTERNAL LINK CANDIDATES (use 2-3 where relevant, as <a href=\"URL\"> with a natural anchor text):\n" . implode( "\n", $lines ) . "\n";
			}
		}
		if ( '' !== trim( $r->instructions ) ) {
			$p .= "\nADDITIONAL INSTRUCTIONS FROM THE USER (follow them, except where they conflict with the no-fabrication rule):\n" . trim( $r->instructions ) . "\n";
		}

		$p .= "\nANSWER STRICTLY AS ONE JSON OBJECT (no markdown fence, no text around it):\n";
		$p .= "{\"title\":\"...\",\"content\":\"HTML fragment of the article\",\"excerpt\":\"...\",\"meta_title\":\"...\",\"meta_description\":\"...\",";
		$p .= "\"focus_keyword\":\"1-4 words, lower case\",\"tags\":[\"...\"],\"category_suggestion\":\"...\"}\n";
		$p .= 'Escape double quotes and line breaks inside JSON strings.';
		return $p;
	}

	public static function user_prompt( ArticleRequest $r ): string {
		$type = '' !== $r->article_type ? ArticleTypes::get( $r->article_type ) : null;

		$p = "TOPIC: {$r->topic}\n";
		if ( '' !== trim( $r->summary ) ) {
			$p .= 'ANGLE: ' . trim( $r->summary ) . "\n";
		}
		$p .= 'ARTICLE TYPE: ' . ( $type ? $type['label']['en'] : 'Informative article' ) . "\n";
		if ( '' !== trim( $r->keyword ) ) {
			$p .= 'FOCUS KEYWORD: ' . trim( $r->keyword ) . "\n";
		}
		if ( $type ) {
			$p .= "\nTYPE-SPECIFIC INSTRUCTIONS: {$type['prompt_hint']}\n";
		}
		$p .= "\nWrite the full article as specified and return the JSON object.";
		return $p;
	}
}
