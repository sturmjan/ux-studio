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
	);

	/**
	 * @return array<string, array{name:string,description:string,prompt_hint:string}>
	 */
	public static function get_all(): array {
		return self::TYPES;
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
