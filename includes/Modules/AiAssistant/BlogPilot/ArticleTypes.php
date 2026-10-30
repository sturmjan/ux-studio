<?php
/**
 * Blog Pilot article type/language/tone/length catalogues.
 *
 * Ported from the legacy ux1-wordpress-customizer AI Assistant module
 * (includes/blog-pilot/ArticleTypes.php). Pure static data + lookup helpers,
 * no WordPress dependency beyond none.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\AiAssistant\BlogPilot;

defined( 'ABSPATH' ) || exit;

final class ArticleTypes {

	/**
	 * All available article types (id => name/description/prompt_hint).
	 *
	 * @var array<string, array{name:string,description:string,prompt_hint:string}>
	 */
	public const TYPES = array(
		'how-to'            => array(
			'name'        => 'How-To Guide',
			'description' => 'Detailed step-by-step instructions on how to do something.',
			'prompt_hint' => 'Write a detailed step-by-step guide. Clearly describe and explain each step. Use numbered steps. Briefly summarise upfront what the reader will need.',
		),
		'listicle'          => array(
			'name'        => 'Listicle (Top X)',
			'description' => 'An article in list format (Top 10, Best X...).',
			'prompt_hint' => 'Create an article in numbered list format. Each item should have its own heading and a short description. Order items logically.',
		),
		'review'            => array(
			'name'        => 'Review',
			'description' => 'A detailed review of a product, service or tool.',
			'prompt_hint' => 'Write a detailed, balanced review. Include pros, cons, features, price and an overall verdict. Stay objective.',
		),
		'comparison'        => array(
			'name'        => 'Comparison',
			'description' => 'A comparison of two or more things (A vs B).',
			'prompt_hint' => 'Create a detailed comparison. Include a table of key parameters, pros/cons of each option and a clear recommendation.',
		),
		'faq'               => array(
			'name'        => 'FAQ Article',
			'description' => 'Frequently asked questions and answers.',
			'prompt_hint' => 'Write an article in question-and-answer format. Use questions readers would realistically have. Answers should be concise but thorough.',
		),
		'case-study'        => array(
			'name'        => 'Case Study',
			'description' => 'A real-world example with concrete results and data.',
			'prompt_hint' => 'Write a case study with a realistic scenario. Include the starting situation, challenges, solution, implementation and measurable results.',
		),
		'ultimate-guide'    => array(
			'name'        => 'Ultimate Guide',
			'description' => 'An exhaustive guide to a topic from A to Z.',
			'prompt_hint' => 'Create a comprehensive guide to the topic. Cover every aspect from basics to advanced techniques. Use subheadings for clarity.',
		),
		'beginner-guide'    => array(
			'name'        => 'Beginner Guide',
			'description' => 'An accessible introduction to a topic for newcomers.',
			'prompt_hint' => 'Write an accessible guide for beginners. Avoid jargon, explain terms, use simple examples. Assume the reader has no prior knowledge.',
		),
		'opinion'           => array(
			'name'        => 'Opinion / Commentary',
			'description' => 'An authored opinion on a current topic.',
			'prompt_hint' => 'Write an opinion piece with a clear thesis. Support your view with arguments and examples. It should be thoughtful and spark discussion.',
		),
		'trends'            => array(
			'name'        => 'Trends & Predictions',
			'description' => 'Analysis of current trends and forecasts.',
			'prompt_hint' => 'Analyse current trends in the field. Include data, examples and future predictions. Explain why these trends matter.',
		),
		'myth-busting'      => array(
			'name'        => 'Myth Busting',
			'description' => 'Debunking common myths and misconceptions.',
			'prompt_hint' => 'Debunk common myths about the topic. Name each myth clearly, explain why it is wrong and state the actual facts with evidence.',
		),
		'checklist'         => array(
			'name'        => 'Checklist',
			'description' => 'A practical checklist with actionable items.',
			'prompt_hint' => 'Create a practical checklist. Each item should be concrete and actionable. Group items into logical categories.',
		),
		'problem-solution'  => array(
			'name'        => 'Problem & Solution',
			'description' => 'Identifying a problem and offering a solution.',
			'prompt_hint' => 'Start with a clear description of the problem, its causes and impact. Then offer a concrete solution with practical implementation steps.',
		),
		'story'             => array(
			'name'        => 'Story / Narrative',
			'description' => 'An article driven by a story or personal experience.',
			'prompt_hint' => 'Write an article with an engaging story. Open with a hook, build tension, share the lesson learned. The reader should be drawn into the narrative.',
		),
		'resource-list'     => array(
			'name'        => 'Resource List',
			'description' => 'A curated list of useful resources, tools or links.',
			'prompt_hint' => 'Compile a curated list of the best resources. For each, give a name, a short description, who it suits and why you recommend it.',
		),
		'data-driven'       => array(
			'name'        => 'Data-Driven Article',
			'description' => 'An article backed by data, statistics and research.',
			'prompt_hint' => 'Write an article backed by data and statistics. Cite realistic sources, use numbers and percentages. Describe data visually in words (charts, tables).',
		),
		'expert-roundup'    => array(
			'name'        => 'Expert Roundup',
			'description' => 'A collection of opinions and tips from multiple experts.',
			'prompt_hint' => 'Create an article presenting the opinions of fictional experts in the field. Each expert should bring a unique perspective. Include their names and titles.',
		),
		'seasonal'          => array(
			'name'        => 'Seasonal Article',
			'description' => 'Content relevant to a specific season or period.',
			'prompt_hint' => 'Write an article relevant to the current season/period of the year. Include timely tips, trends and recommendations.',
		),
		'local-seo'         => array(
			'name'        => 'Local SEO Article',
			'description' => 'Content focused on a specific location or region.',
			'prompt_hint' => 'Write an article focused on a specific location. Include local specifics, addresses, tips for locals and visitors. Optimise for local search.',
		),
		'industry-analysis' => array(
			'name'        => 'Industry Analysis',
			'description' => 'An in-depth analysis of an industry with data and conclusions.',
			'prompt_hint' => 'Create an in-depth industry analysis. Include market size, key players, trends, challenges and opportunities. Back claims with data.',
		),
		'product-launch'    => array(
			'name'        => 'Product Launch',
			'description' => 'Introducing a new product or service.',
			'prompt_hint' => 'Write an article introducing a new product/service. Include key features, benefits, target audience, price and how it differs from competitors.',
		),
		'pillar-content'    => array(
			'name'        => 'Pillar Content',
			'description' => 'Extensive pillar content covering a topic in depth.',
			'prompt_hint' => 'Create an extensive pillar article covering the topic comprehensively. Include a table of contents, detailed sections and internal linking.',
		),
		'behind-scenes'     => array(
			'name'        => 'Behind the Scenes',
			'description' => 'A behind-the-scenes look at how something works or is made.',
			'prompt_hint' => 'Write an article showing the behind-the-scenes of a process/company/project. Be authentic, share details and facts not normally visible.',
		),
		'news'              => array(
			'name'        => 'News / Announcement',
			'description' => 'A current news item or announcement from the field.',
			'prompt_hint' => 'Write a news article. Answer who, what, when, where, why and how. Stay objective and provide context for the event.',
		),
		'interview'         => array(
			'name'        => 'Interview',
			'description' => 'An interview with an expert or interesting person.',
			'prompt_hint' => 'Create a fictional interview in question-and-answer format. Questions should be probing and answers should contain valuable practical insight.',
		),

		// Copywriting frameworks - same catalogue mechanism as the article
		// types above, just marketing-copy structures instead of blog-post
		// shapes. Reuses the whole Content Creator/Blog Pilot pipeline
		// (provider selection, tone/length, generate → draft → publish) with
		// no new architecture - only these entries were missing.
		'aida'              => array(
			'name'        => 'AIDA Copy',
			'description' => 'Attention - Interest - Desire - Action marketing copy.',
			'prompt_hint' => 'Structure the copy using the AIDA framework: grab Attention with a strong opening, build Interest with relevant benefits, create Desire by showing the value/transformation, end with a clear call to Action.',
		),
		'pas'               => array(
			'name'        => 'PAS Copy',
			'description' => 'Problem - Agitate - Solution marketing copy.',
			'prompt_hint' => 'Structure the copy using the PAS framework: state the reader\'s Problem clearly, Agitate it by describing the pain/consequences of not solving it, then present the Solution as the clear way out.',
		),
		'bab'               => array(
			'name'        => 'BAB Copy',
			'description' => 'Before - After - Bridge marketing copy.',
			'prompt_hint' => 'Structure the copy using the BAB framework: describe the reader\'s current (Before) situation, paint a picture of the improved (After) situation, then present the offer as the Bridge between the two.',
		),
		'hero'              => array(
			'name'        => 'HERO Copy',
			'description' => 'Hook - Empathize - Response - Outcome marketing copy.',
			'prompt_hint' => 'Structure the copy using the HERO framework: open with a Hook that grabs attention, Empathize with the reader\'s situation/pain, present the offer as the Response, end with the concrete Outcome/result they can expect.',
		),
		'spin'              => array(
			'name'        => 'SPIN Copy',
			'description' => 'Situation - Problem - Implication - Need-payoff selling copy.',
			'prompt_hint' => 'Structure the copy using the SPIN selling framework: establish the Situation, surface the Problem, explain the Implication of leaving it unsolved, then show the Need-payoff of the solution.',
		),
		'idca'              => array(
			'name'        => 'IDCA Copy',
			'description' => 'Identify - Develop - Communicate - Ask marketing copy.',
			'prompt_hint' => 'Structure the copy using the IDCA framework: Identify the reader\'s core need, Develop it with supporting detail/proof, Communicate the offer clearly, end with a direct Ask (call to action).',
		),
		'email'             => array(
			'name'        => 'Email',
			'description' => 'A standalone marketing or transactional email.',
			'prompt_hint' => 'Write a complete email with a compelling subject line (on the first line, prefixed "Subject: "), a personal greeting, a clear body focused on one main message, and a single clear call to action.',
		),
		'comment-reply'     => array(
			'name'        => 'Comment / Review Reply',
			'description' => 'A short, on-brand reply to a comment or review.',
			'prompt_hint' => 'Write a short, genuine-sounding reply (2-4 sentences) to the comment/review provided in the topic. Thank the author, address their specific point, and keep the site\'s tone. Never invent facts not present in the original comment.',
		),
	);

	/**
	 * Names and descriptions of the article types in the language of the admin, for the UI. The `name` and
	 * `description` in TYPES stay English: they are also part of the prompt sent to the AI.
	 *
	 * @return array<string, array{name:string,description:string}>
	 */
	private static function labels(): array {
		return array(
			'how-to' => array(
				'name'        => __( 'How-To Guide', 'ux-studio' ),
				'description' => __( 'Detailed step-by-step instructions on how to do something.', 'ux-studio' ),
			),
			'listicle' => array(
				'name'        => __( 'Listicle (Top X)', 'ux-studio' ),
				'description' => __( 'An article in list format (Top 10, Best X...).', 'ux-studio' ),
			),
			'review' => array(
				'name'        => __( 'Review', 'ux-studio' ),
				'description' => __( 'A detailed review of a product, service or tool.', 'ux-studio' ),
			),
			'comparison' => array(
				'name'        => __( 'Comparison', 'ux-studio' ),
				'description' => __( 'A comparison of two or more things (A vs B).', 'ux-studio' ),
			),
			'faq' => array(
				'name'        => __( 'FAQ Article', 'ux-studio' ),
				'description' => __( 'Frequently asked questions and answers.', 'ux-studio' ),
			),
			'case-study' => array(
				'name'        => __( 'Case Study', 'ux-studio' ),
				'description' => __( 'A real-world example with concrete results and data.', 'ux-studio' ),
			),
			'ultimate-guide' => array(
				'name'        => __( 'Ultimate Guide', 'ux-studio' ),
				'description' => __( 'An exhaustive guide to a topic from A to Z.', 'ux-studio' ),
			),
			'beginner-guide' => array(
				'name'        => __( 'Beginner Guide', 'ux-studio' ),
				'description' => __( 'An accessible introduction to a topic for newcomers.', 'ux-studio' ),
			),
			'opinion' => array(
				'name'        => __( 'Opinion / Commentary', 'ux-studio' ),
				'description' => __( 'An authored opinion on a current topic.', 'ux-studio' ),
			),
			'trends' => array(
				'name'        => __( 'Trends & Predictions', 'ux-studio' ),
				'description' => __( 'Analysis of current trends and forecasts.', 'ux-studio' ),
			),
			'myth-busting' => array(
				'name'        => __( 'Myth Busting', 'ux-studio' ),
				'description' => __( 'Debunking common myths and misconceptions.', 'ux-studio' ),
			),
			'checklist' => array(
				'name'        => __( 'Checklist', 'ux-studio' ),
				'description' => __( 'A practical checklist with actionable items.', 'ux-studio' ),
			),
			'problem-solution' => array(
				'name'        => __( 'Problem & Solution', 'ux-studio' ),
				'description' => __( 'Identifying a problem and offering a solution.', 'ux-studio' ),
			),
			'story' => array(
				'name'        => __( 'Story / Narrative', 'ux-studio' ),
				'description' => __( 'An article driven by a story or personal experience.', 'ux-studio' ),
			),
			'resource-list' => array(
				'name'        => __( 'Resource List', 'ux-studio' ),
				'description' => __( 'A curated list of useful resources, tools or links.', 'ux-studio' ),
			),
			'data-driven' => array(
				'name'        => __( 'Data-Driven Article', 'ux-studio' ),
				'description' => __( 'An article backed by data, statistics and research.', 'ux-studio' ),
			),
			'expert-roundup' => array(
				'name'        => __( 'Expert Roundup', 'ux-studio' ),
				'description' => __( 'A collection of opinions and tips from multiple experts.', 'ux-studio' ),
			),
			'seasonal' => array(
				'name'        => __( 'Seasonal Article', 'ux-studio' ),
				'description' => __( 'Content relevant to a specific season or period.', 'ux-studio' ),
			),
			'local-seo' => array(
				'name'        => __( 'Local SEO Article', 'ux-studio' ),
				'description' => __( 'Content focused on a specific location or region.', 'ux-studio' ),
			),
			'industry-analysis' => array(
				'name'        => __( 'Industry Analysis', 'ux-studio' ),
				'description' => __( 'An in-depth analysis of an industry with data and conclusions.', 'ux-studio' ),
			),
			'product-launch' => array(
				'name'        => __( 'Product Launch', 'ux-studio' ),
				'description' => __( 'Introducing a new product or service.', 'ux-studio' ),
			),
			'pillar-content' => array(
				'name'        => __( 'Pillar Content', 'ux-studio' ),
				'description' => __( 'Extensive pillar content covering a topic in depth.', 'ux-studio' ),
			),
			'behind-scenes' => array(
				'name'        => __( 'Behind the Scenes', 'ux-studio' ),
				'description' => __( 'A behind-the-scenes look at how something works or is made.', 'ux-studio' ),
			),
			'news' => array(
				'name'        => __( 'News / Announcement', 'ux-studio' ),
				'description' => __( 'A current news item or announcement from the field.', 'ux-studio' ),
			),
			'interview' => array(
				'name'        => __( 'Interview', 'ux-studio' ),
				'description' => __( 'An interview with an expert or interesting person.', 'ux-studio' ),
			),
			'aida' => array(
				'name'        => __( 'AIDA Copy', 'ux-studio' ),
				'description' => __( 'Attention - Interest - Desire - Action marketing copy.', 'ux-studio' ),
			),
			'pas' => array(
				'name'        => __( 'PAS Copy', 'ux-studio' ),
				'description' => __( 'Problem - Agitate - Solution marketing copy.', 'ux-studio' ),
			),
			'bab' => array(
				'name'        => __( 'BAB Copy', 'ux-studio' ),
				'description' => __( 'Before - After - Bridge marketing copy.', 'ux-studio' ),
			),
			'hero' => array(
				'name'        => __( 'HERO Copy', 'ux-studio' ),
				'description' => __( 'Hook - Empathize - Response - Outcome marketing copy.', 'ux-studio' ),
			),
			'spin' => array(
				'name'        => __( 'SPIN Copy', 'ux-studio' ),
				'description' => __( 'Situation - Problem - Implication - Need-payoff selling copy.', 'ux-studio' ),
			),
			'idca' => array(
				'name'        => __( 'IDCA Copy', 'ux-studio' ),
				'description' => __( 'Identify - Develop - Communicate - Ask marketing copy.', 'ux-studio' ),
			),
			'email' => array(
				'name'        => __( 'Email', 'ux-studio' ),
				'description' => __( 'A standalone marketing or transactional email.', 'ux-studio' ),
			),
			'comment-reply' => array(
				'name'        => __( 'Comment / Review Reply', 'ux-studio' ),
				'description' => __( 'A short, on-brand reply to a comment or review.', 'ux-studio' ),
			),
		);
	}

	/**
	 * All article types for the UI, with the name and description translated.
	 *
	 * @return array<string, array{name:string,description:string,prompt_hint:string}>
	 */
	public static function get_all(): array {
		$labels = self::labels();
		$all    = array();
		foreach ( self::TYPES as $id => $type ) {
			$all[ $id ] = isset( $labels[ $id ] ) ? array_merge( $type, $labels[ $id ] ) : $type;
		}
		return $all;
	}

	/**
	 * @return array{name:string,description:string,prompt_hint:string}|null
	 */
	public static function get( string $id ): ?array {
		return self::TYPES[ $id ] ?? null;
	}

	/**
	 * @return array<string, string>
	 */
	public static function get_names(): array {
		$names = array();
		foreach ( self::TYPES as $id => $type ) {
			$names[ $id ] = $type['name'];
		}
		return $names;
	}

	public static function get_prompt_hint( string $id ): string {
		return self::TYPES[ $id ]['prompt_hint'] ?? '';
	}

	/**
	 * @return array<string, string>
	 */
	public static function get_languages(): array {
		return array(
			'cs' => 'Čeština',
			'sk' => 'Slovenčina',
			'en' => 'English',
			'de' => 'Deutsch',
			'pl' => 'Polski',
			'fr' => 'Français',
			'es' => 'Español',
			'it' => 'Italiano',
			'pt' => 'Português',
			'nl' => 'Nederlands',
			'ru' => 'Русский',
			'uk' => 'Українська',
			'hu' => 'Magyar',
			'ro' => 'Română',
			'bg' => 'Български',
			'hr' => 'Hrvatski',
			'sr' => 'Srpski',
			'sl' => 'Slovenščina',
			'da' => 'Dansk',
			'sv' => 'Svenska',
			'no' => 'Norsk',
			'fi' => 'Suomi',
			'el' => 'Ελληνικά',
			'tr' => 'Türkçe',
			'ar' => 'العربية',
			'he' => 'עברית',
			'hi' => 'हिन्दी',
			'zh' => '中文',
			'ja' => '日本語',
			'ko' => '한국어',
			'th' => 'ไทย',
			'vi' => 'Tiếng Việt',
			'id' => 'Bahasa Indonesia',
			'ms' => 'Bahasa Melayu',
		);
	}

	/**
	 * @return array<string, string>
	 */
	public static function get_tones(): array {
		return array(
			'professional'  => 'Professional',
			'casual'        => 'Casual',
			'friendly'      => 'Friendly',
			'formal'        => 'Formal',
			'humorous'      => 'Humorous',
			'enthusiastic'  => 'Enthusiastic',
			'informative'   => 'Informative',
			'persuasive'    => 'Persuasive',
			'empathetic'    => 'Empathetic',
			'authoritative' => 'Authoritative',
			'conversational' => 'Conversational',
			'inspirational' => 'Inspirational',
		);
	}

	/**
	 * @return array<string, string>
	 */
	public static function get_lengths(): array {
		return array(
			'short'      => 'Short (400-800 words)',
			'medium'     => 'Medium (800-1200 words)',
			'long'       => 'Long (1200-1800 words)',
			'extra-long' => 'Extra long (1800-2500 words)',
		);
	}

	public static function get_length_range( string $key ): string {
		$ranges = array(
			'short'      => '400-800',
			'medium'     => '800-1200',
			'long'       => '1200-1800',
			'extra-long' => '1800-2500',
		);

		return $ranges[ $key ] ?? '800-1200';
	}
}
