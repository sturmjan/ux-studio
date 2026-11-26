<?php
/**
 * Proposes article topics from a brief (CA AI Agent "propose topics" step, also usable by Blog Pilot).
 *
 * The host stores the proposals, lets the user select / reject them and calls regenerate_one() to replace a rejected
 * one. The planner never touches storage and never calls a real API on its own: it only talks to the TextProvider
 * it is given.
 *
 * @package Ux1\AiCore
 */

declare(strict_types=1);

namespace Ux1\AiCore\Content;

use Ux1\AiCore\Provider\TextProvider;

final class TopicPlanner {

	/** Titles this similar (similar_text percent) to an existing one count as duplicates. */
	public const SIMILARITY_THRESHOLD = 88.0;

	/** Cap on avoid-list entries put into the prompt. */
	public const MAX_AVOID_TITLES = 100;

	/**
	 * @param array{input_tokens:int,output_tokens:int}|null $usage Filled with the token usage of the call.
	 * @return array<int, TopicProposal> At most $r->count proposals (more only with $r->return_extra), no duplicates.
	 * @throws \RuntimeException When the model answer contains no usable proposal, or the provider fails.
	 */
	public static function propose( TextProvider $p, string $model, TopicRequest $r, ?array &$usage = null ): array {
		$result = $p->generate_content(
			self::system_prompt( $r, $r->requested_count(), $r->existing_titles ),
			"Generate {$r->requested_count()} article proposals for this brief:\n\n" . $r->brief,
			$model,
			array(
				'max_tokens'  => 4096,
				'temperature' => 0.8,
			)
		);
		$usage  = self::usage_of( $result );
		$parsed = self::parse( (string) ( $result['content'] ?? '' ), $r );
		$unique = self::dedupe( $parsed, $r->existing_titles );
		if ( ! $unique ) {
			throw new \RuntimeException( 'Topic proposals: the model returned no usable (new) topic.' );
		}
		return array_slice( $unique, 0, $r->return_extra ? $r->requested_count() : $r->count );
	}

	/**
	 * Replaces one rejected proposal with a fresh, different one.
	 *
	 * @param array<int, TopicProposal>                       $current Proposals the host currently shows (kept out of the answer).
	 * @param array{input_tokens:int,output_tokens:int}|null $usage
	 * @throws \RuntimeException When no usable new topic comes back.
	 */
	public static function regenerate_one( TextProvider $p, string $model, TopicRequest $r, TopicProposal $rejected, array $current = array(), ?array &$usage = null ): TopicProposal {
		$avoid = $r->existing_titles;
		$avoid[] = $rejected->title;
		foreach ( $rejected->variants as $variant ) {
			$avoid[] = $variant;
		}
		foreach ( $current as $proposal ) {
			$avoid[] = $proposal->title;
		}
		$single = new TopicRequest(
			$r->brief,
			1,
			$r->language,
			$avoid,
			$r->allowed_types,
			$r->audience,
			$r->tone,
			$r->site_context,
			$r->brand_voice,
			$r->categories,
			false,
			$r->today
		);

		$result = $p->generate_content(
			self::system_prompt( $single, 2, $avoid ),
			"Generate 2 alternative article proposals for this brief. The previous proposal \"{$rejected->title}\" was rejected, so take a clearly different angle:\n\n" . $r->brief,
			$model,
			array(
				'max_tokens'  => 2048,
				'temperature' => 0.9,
			)
		);
		$usage  = self::usage_of( $result );
		$unique = self::dedupe( self::parse( (string) ( $result['content'] ?? '' ), $single ), $avoid );
		if ( ! $unique ) {
			throw new \RuntimeException( 'Topic proposals: the model returned no usable replacement topic.' );
		}
		return $unique[0];
	}

	/**
	 * Parses a model answer into proposals: accepts a JSON array, an object wrapping one ("proposals"/"topics"/
	 * "articles") or a single proposal object; skips entries without a title; coerces unknown article types.
	 *
	 * @return array<int, TopicProposal>
	 */
	public static function parse( string $raw, TopicRequest $r ): array {
		$data = ContentText::extract_json( $raw );
		if ( null === $data ) {
			return array();
		}
		foreach ( array( 'proposals', 'topics', 'articles', 'items' ) as $key ) {
			if ( isset( $data[ $key ] ) && is_array( $data[ $key ] ) ) {
				$data = $data[ $key ];
				break;
			}
		}
		if ( isset( $data['title'] ) ) {
			$data = array( $data );
		}
		$types  = self::allowed_types( $r );
		$out    = array();
		$index  = 0;
		foreach ( $data as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$title = trim( (string) ( $row['title'] ?? '' ) );
			if ( '' === $title ) {
				continue;
			}
			$type = (string) ( $row['article_type'] ?? $row['type'] ?? '' );
			if ( ! in_array( $type, $types, true ) ) {
				$type = ArticleTypes::rotate( $types, $index );
			}
			$variants = array();
			foreach ( (array) ( $row['variants'] ?? array() ) as $variant ) {
				$variant = trim( (string) ( is_array( $variant ) ? '' : $variant ) );
				if ( '' !== $variant && ContentText::normalize( $variant ) !== ContentText::normalize( $title ) ) {
					$variants[] = $variant;
				}
			}
			$out[] = new TopicProposal(
				TopicProposal::make_id( $title ),
				$title,
				trim( (string) ( $row['summary'] ?? $row['angle'] ?? '' ) ),
				mb_strtolower( trim( (string) ( $row['focus_keyword'] ?? $row['keyword'] ?? '' ) ), 'UTF-8' ),
				$type,
				array_slice( array_values( array_unique( $variants ) ), 0, 3 ),
				trim( (string) ( $row['category'] ?? '' ) )
			);
			++$index;
		}
		return $out;
	}

	/**
	 * Drops proposals duplicating an avoided title or an earlier proposal (normalised exact match or near-identical
	 * text), keeping the first.
	 *
	 * @param array<int, TopicProposal> $proposals
	 * @param array<int, string>        $avoid
	 * @return array<int, TopicProposal>
	 */
	public static function dedupe( array $proposals, array $avoid ): array {
		$seen = array();
		foreach ( $avoid as $title ) {
			$norm = ContentText::normalize( (string) $title );
			if ( '' !== $norm ) {
				$seen[] = $norm;
			}
		}
		$out = array();
		foreach ( $proposals as $proposal ) {
			$norm = ContentText::normalize( $proposal->title );
			if ( '' === $norm || self::is_duplicate( $norm, $seen ) ) {
				continue;
			}
			$seen[] = $norm;
			$out[]  = $proposal;
		}
		return $out;
	}

	/** @param array<int, string> $seen Normalised titles. */
	private static function is_duplicate( string $norm, array $seen ): bool {
		foreach ( $seen as $other ) {
			if ( $norm === $other ) {
				return true;
			}
			// "Top 5 ..." and "Top 10 ..." are different articles however similar the rest is.
			if ( self::digits( $norm ) !== self::digits( $other ) ) {
				continue;
			}
			similar_text( $norm, $other, $percent );
			if ( $percent >= self::SIMILARITY_THRESHOLD ) {
				return true;
			}
		}
		return false;
	}

	private static function digits( string $text ): string {
		return (string) preg_replace( '/\D+/', '', $text );
	}

	/** @return array<int, string> */
	private static function allowed_types( TopicRequest $r ): array {
		$types = array_values( array_filter( $r->allowed_types, static fn( $id ): bool => is_string( $id ) && null !== ArticleTypes::get( $id ) ) );
		return $types ? $types : ArticleTypes::ids( ArticleTypes::KIND_ARTICLE );
	}

	/**
	 * @param array<int, string> $avoid
	 */
	private static function system_prompt( TopicRequest $r, int $ask, array $avoid ): string {
		$language = ContentText::language_name( $r->language );
		$today    = '' !== $r->today ? $r->today : gmdate( 'Y-m-d' );
		$avoid    = array_slice( array_values( array_unique( array_map( 'strval', $avoid ) ) ), 0, self::MAX_AVOID_TITLES );

		$types = array();
		foreach ( self::allowed_types( $r ) as $id ) {
			$type    = ArticleTypes::get( $id );
			$types[] = '- ' . $id . ': ' . ( $type ? $type['label']['en'] : $id );
		}

		$p  = "You are an experienced content editor proposing topics for new blog articles.\n";
		$p .= "Today is {$today}. When a title mentions a year, use the current or the next one; never present older years as current.\n\n";
		$p .= "GOAL: return exactly {$ask} proposals that\n";
		$p .= "1) fit the brief in the user message,\n";
		$p .= "2) respect the site context and brand voice below,\n";
		$p .= "3) are NOT duplicates or near-duplicates of the existing titles below, nor of each other,\n";
		$p .= "4) cover different angles and search intents (not {$ask} variations of one idea),\n";
		$p .= "5) have a concrete, click-worthy title of roughly 50-65 characters that contains the focus keyword.\n\n";
		$p .= "LANGUAGE: write titles, summaries and keywords in {$language}.\n";
		if ( '' !== trim( $r->audience ) ) {
			$p .= 'AUDIENCE: ' . trim( $r->audience ) . "\n";
		}
		if ( '' !== trim( $r->tone ) ) {
			$p .= 'TONE: ' . trim( $r->tone ) . "\n";
		}
		if ( '' !== trim( $r->site_context ) ) {
			$p .= "\nSITE CONTEXT (keep to it):\n" . trim( $r->site_context ) . "\n";
		}
		if ( '' !== trim( $r->brand_voice ) ) {
			$p .= "\nBRAND VOICE:\n" . trim( $r->brand_voice ) . "\n";
		}
		$p .= "\nEXISTING TITLES (avoid these topics):\n" . ( $avoid ? implode( "\n", array_map( static fn( string $t ): string => '- ' . $t, $avoid ) ) : '(none)' ) . "\n";
		$p .= "\nARTICLE TYPES (set article_type to one of these ids, vary them):\n" . implode( "\n", $types ) . "\n";
		if ( $r->categories ) {
			$p .= "\nCATEGORIES (set category to exactly one of these names, spread proposals across several; \"\" if none fits):\n"
				. implode( "\n", array_map( static fn( $c ): string => '- ' . $c, array_map( 'strval', $r->categories ) ) ) . "\n";
		}
		$p .= "\nOUTPUT: a pure JSON array, no comments and no markdown fences. Schema:\n";
		$p .= "[{\"title\":\"main title\",\"summary\":\"1-2 sentences on the angle\",\"focus_keyword\":\"1-4 words, lower case\",";
		$p .= "\"article_type\":\"type id\",\"variants\":[\"alternative title A\",\"alternative title B\"],\"category\":\"category name or empty\"}]\n";
		$p .= 'variants are 2 alternative titles of the same article from a different angle (question, number, other hook).';
		return $p;
	}

	/**
	 * @param array<string, mixed> $result
	 * @return array{input_tokens:int,output_tokens:int}
	 */
	private static function usage_of( array $result ): array {
		return array(
			'input_tokens'  => (int) ( $result['usage']['input_tokens'] ?? 0 ),
			'output_tokens' => (int) ( $result['usage']['output_tokens'] ?? 0 ),
		);
	}
}
