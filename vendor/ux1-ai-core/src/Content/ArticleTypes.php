<?php
/**
 * Catalogue of article types (25 blog shapes) and copywriting frameworks (8 marketing-copy shapes) with the prompt
 * hint each one adds to the writer prompt. Source: UX1 Studio Blog Pilot (ArticleTypes::TYPES); the Czech labels
 * are new. Hints and English labels are part of the prompt, so they stay English.
 *
 * @package Ux1\AiCore
 */

declare(strict_types=1);

namespace Ux1\AiCore\Content;

final class ArticleTypes {

	public const KIND_ARTICLE = 'article';
	public const KIND_COPY    = 'copy';

	/**
	 * @return array<string, array{id:string,kind:string,label:array{cs:string,en:string},prompt_hint:string}>
	 */
	public static function all(): array {
		static $all = null;
		if ( null === $all ) {
			$all = array();
			foreach ( self::rows() as $id => $row ) {
				$all[ $id ] = array(
					'id'          => $id,
					'kind'        => $row[0],
					'label'       => array(
						'cs' => $row[2],
						'en' => $row[1],
					),
					'prompt_hint' => $row[3],
				);
			}
		}
		return $all;
	}

	/** @return array{id:string,kind:string,label:array{cs:string,en:string},prompt_hint:string}|null */
	public static function get( string $id ): ?array {
		return self::all()[ $id ] ?? null;
	}

	/** @return array<int, string> Type ids, optionally only one kind (KIND_ARTICLE / KIND_COPY). */
	public static function ids( ?string $kind = null ): array {
		$ids = array();
		foreach ( self::all() as $id => $type ) {
			if ( null === $kind || $kind === $type['kind'] ) {
				$ids[] = $id;
			}
		}
		return $ids;
	}

	/** Label in $lang ('cs' or 'en'; anything else falls back to English), or '' for an unknown id. */
	public static function label( string $id, string $lang = 'en' ): string {
		$type = self::get( $id );
		if ( null === $type ) {
			return '';
		}
		return $type['label'][ 'cs' === $lang ? 'cs' : 'en' ];
	}

	/**
	 * Round-robin pick: the type at position $index in the list of allowed ids (unknown ids are ignored; an empty
	 * list means all article-kind types). The index wraps, negative values count from the end. Deterministic, so a
	 * batch of N articles gets varied types with rotate( $allowed, $i ).
	 *
	 * @param array<int, string> $allowed
	 */
	public static function rotate( array $allowed, int $index ): string {
		$pool = array();
		foreach ( $allowed as $id ) {
			if ( is_string( $id ) && null !== self::get( $id ) && ! in_array( $id, $pool, true ) ) {
				$pool[] = $id;
			}
		}
		if ( ! $pool ) {
			$pool = self::ids( self::KIND_ARTICLE );
		}
		$count = count( $pool );
		return $pool[ ( ( $index % $count ) + $count ) % $count ];
	}

	/** @return array<string, array{0:string,1:string,2:string,3:string}> id => kind, en, cs, hint. */
	private static function rows(): array {
		$a = self::KIND_ARTICLE;
		$c = self::KIND_COPY;
		return array(
			'how-to'            => array( $a, 'How-To Guide', 'Návod krok za krokem', 'Write a detailed step-by-step guide. Clearly describe and explain each step. Use numbered steps. Briefly summarise upfront what the reader will need.' ),
			'listicle'          => array( $a, 'Listicle (Top X)', 'Seznam (Top X)', 'Create an article in numbered list format. Each item should have its own heading and a short description. Order items logically.' ),
			'review'            => array( $a, 'Review', 'Recenze', 'Write a detailed, balanced review. Include pros, cons, features, price and an overall verdict. Stay objective.' ),
			'comparison'        => array( $a, 'Comparison', 'Srovnání', 'Create a detailed comparison. Include a table of key parameters, pros/cons of each option and a clear recommendation.' ),
			'faq'               => array( $a, 'FAQ Article', 'Často kladené otázky', 'Write an article in question-and-answer format. Use questions readers would realistically have. Answers should be concise but thorough.' ),
			'case-study'        => array( $a, 'Case Study', 'Případová studie', 'Write a case study with a realistic scenario. Include the starting situation, challenges, solution, implementation and measurable results.' ),
			'ultimate-guide'    => array( $a, 'Ultimate Guide', 'Kompletní průvodce', 'Create a comprehensive guide to the topic. Cover every aspect from basics to advanced techniques. Use subheadings for clarity.' ),
			'beginner-guide'    => array( $a, 'Beginner Guide', 'Průvodce pro začátečníky', 'Write an accessible guide for beginners. Avoid jargon, explain terms, use simple examples. Assume the reader has no prior knowledge.' ),
			'opinion'           => array( $a, 'Opinion / Commentary', 'Názor / komentář', 'Write an opinion piece with a clear thesis. Support your view with arguments and examples. It should be thoughtful and spark discussion.' ),
			'trends'            => array( $a, 'Trends & Predictions', 'Trendy a předpovědi', 'Analyse current trends in the field. Include data, examples and future predictions. Explain why these trends matter.' ),
			'myth-busting'      => array( $a, 'Myth Busting', 'Mýty a fakta', 'Debunk common myths about the topic. Name each myth clearly, explain why it is wrong and state the actual facts with evidence.' ),
			'checklist'         => array( $a, 'Checklist', 'Checklist', 'Create a practical checklist. Each item should be concrete and actionable. Group items into logical categories.' ),
			'problem-solution'  => array( $a, 'Problem & Solution', 'Problém a řešení', 'Start with a clear description of the problem, its causes and impact. Then offer a concrete solution with practical implementation steps.' ),
			'story'             => array( $a, 'Story / Narrative', 'Příběh', 'Write an article with an engaging story. Open with a hook, build tension, share the lesson learned. The reader should be drawn into the narrative.' ),
			'resource-list'     => array( $a, 'Resource List', 'Přehled zdrojů a nástrojů', 'Compile a curated list of the best resources. For each, give a name, a short description, who it suits and why you recommend it.' ),
			'data-driven'       => array( $a, 'Data-Driven Article', 'Článek založený na datech', 'Write an article backed by data and statistics. Use numbers only when you are sure of them and name the kind of source; never invent figures. Describe data visually in words (charts, tables).' ),
			'expert-roundup'    => array( $a, 'Expert Roundup', 'Rady odborníků', 'Create an article presenting perspectives of experts in the field. Each perspective should be unique. Do not present invented people as real: label them as illustrative or attribute advice to roles (e.g. "an experienced installer").' ),
			'seasonal'          => array( $a, 'Seasonal Article', 'Sezónní článek', 'Write an article relevant to the current season/period of the year. Include timely tips, trends and recommendations.' ),
			'local-seo'         => array( $a, 'Local SEO Article', 'Lokální článek', 'Write an article focused on a specific location. Include local specifics, tips for locals and visitors. Optimise for local search. Never invent addresses or opening hours.' ),
			'industry-analysis' => array( $a, 'Industry Analysis', 'Analýza oboru', 'Create an in-depth industry analysis. Include market structure, key players, trends, challenges and opportunities. Back claims with facts you are sure of.' ),
			'product-launch'    => array( $a, 'Product Launch', 'Představení novinky', 'Write an article introducing a new product/service. Include key features, benefits, target audience and how it differs from alternatives. Do not invent prices or specifications.' ),
			'pillar-content'    => array( $a, 'Pillar Content', 'Pilířový obsah', 'Create an extensive pillar article covering the topic comprehensively. Include a short table of contents (list of the h2 sections), detailed sections and internal linking.' ),
			'behind-scenes'     => array( $a, 'Behind the Scenes', 'Pohled do zákulisí', 'Write an article showing the behind-the-scenes of a process/company/project. Be authentic and concrete; use only details supplied in the brief or common knowledge.' ),
			'news'              => array( $a, 'News / Announcement', 'Novinka / oznámení', 'Write a news article. Answer who, what, when, where, why and how. Stay objective and provide context for the event.' ),
			'interview'         => array( $a, 'Interview', 'Rozhovor', 'Create an interview in question-and-answer format. Questions should be probing and answers should contain valuable practical insight. Mark it as an illustrative interview, not a real quote.' ),
			'aida'              => array( $c, 'AIDA Copy', 'Copy AIDA', 'Structure the copy using the AIDA framework: grab Attention with a strong opening, build Interest with relevant benefits, create Desire by showing the value/transformation, end with a clear call to Action.' ),
			'pas'               => array( $c, 'PAS Copy', 'Copy PAS', 'Structure the copy using the PAS framework: state the reader\'s Problem clearly, Agitate it by describing the pain/consequences of not solving it, then present the Solution as the clear way out.' ),
			'bab'               => array( $c, 'BAB Copy', 'Copy BAB', 'Structure the copy using the BAB framework: describe the reader\'s current (Before) situation, paint a picture of the improved (After) situation, then present the offer as the Bridge between the two.' ),
			'hero'              => array( $c, 'HERO Copy', 'Copy HERO', 'Structure the copy using the HERO framework: open with a Hook that grabs attention, Empathize with the reader\'s situation/pain, present the offer as the Response, end with the concrete Outcome/result they can expect.' ),
			'spin'              => array( $c, 'SPIN Copy', 'Copy SPIN', 'Structure the copy using the SPIN selling framework: establish the Situation, surface the Problem, explain the Implication of leaving it unsolved, then show the Need-payoff of the solution.' ),
			'idca'              => array( $c, 'IDCA Copy', 'Copy IDCA', 'Structure the copy using the IDCA framework: Identify the reader\'s core need, Develop it with supporting detail/proof, Communicate the offer clearly, end with a direct Ask (call to action).' ),
			'email'             => array( $c, 'Email', 'E-mail', 'Write a complete email with a compelling subject line (use it as the title), a personal greeting, a clear body focused on one main message, and a single clear call to action.' ),
			'comment-reply'     => array( $c, 'Comment / Review Reply', 'Odpověď na komentář / recenzi', 'Write a short, genuine-sounding reply (2-4 sentences) to the comment/review provided in the topic. Thank the author, address their specific point, and keep the site\'s tone. Never invent facts not present in the original comment.' ),
		);
	}
}
