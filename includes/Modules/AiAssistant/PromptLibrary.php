<?php
/**
 * Prompt template registry for the generic "content tool" endpoint (RankMath
 * Content AI parity, PLAN.md §17.2, F2) - one entry per tool, all running
 * through the same ContentGenerator::generate_from_prompt() call instead of
 * a dedicated method/endpoint each.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\AiAssistant;

defined( 'ABSPATH' ) || exit;

/**
 * Each tool is a system-prompt template + the JSON keys the AI must return.
 * `vars` names the placeholders the caller must fill via `{name}` in
 * `instruction`; `required` lists which of those must be non-empty.
 * Copywriting-formula tools (AIDA/PAS/...) and content tools share the same
 * shape - the formula ones just carry a fixed structural instruction.
 */
final class PromptLibrary {

	/**
	 * @return array<string, array{label:string, instruction:string, vars:string[], required:string[], output:array<string,string>, max_tokens?:int}>
	 */
	public static function all(): array {
		return array(
			// ── Content ──────────────────────────────────────────────
			'blog_post_idea'      => array(
				'label'       => __( 'Blog Post Idea', 'ux-studio' ),
				'instruction' => 'Suggest 5 compelling blog post title ideas about: {topic}.',
				'vars'        => array( 'topic' ),
				'required'    => array( 'topic' ),
				'output'      => array( 'ideas' => 'array of 5 strings' ),
			),
			'blog_post_outline'   => array(
				'label'       => __( 'Blog Post Outline', 'ux-studio' ),
				'instruction' => 'Create a detailed blog post outline (H2/H3 structure) about: {topic}.',
				'vars'        => array( 'topic' ),
				'required'    => array( 'topic' ),
				'output'      => array( 'outline' => 'array of {heading, sub_points: array of strings}' ),
			),
			'blog_post_intro'     => array(
				'label'       => __( 'Blog Post Introduction', 'ux-studio' ),
				'instruction' => 'Write an engaging introduction paragraph for a blog post about: {topic}.',
				'vars'        => array( 'topic' ),
				'required'    => array( 'topic' ),
				'output'      => array( 'text' => 'string, HTML' ),
			),
			'blog_post_conclusion' => array(
				'label'       => __( 'Blog Post Conclusion', 'ux-studio' ),
				'instruction' => 'Write a conclusion paragraph (with a call to action) summarising this article: {source_content}.',
				'vars'        => array( 'source_content' ),
				'required'    => array( 'source_content' ),
				'output'      => array( 'text' => 'string, HTML' ),
			),
			'post_title'          => array(
				'label'       => __( 'Post Title', 'ux-studio' ),
				'instruction' => 'Suggest 5 catchy post titles about: {topic}.',
				'vars'        => array( 'topic' ),
				'required'    => array( 'topic' ),
				'output'      => array( 'titles' => 'array of 5 strings' ),
			),
			'paragraph'           => array(
				'label'       => __( 'Paragraph', 'ux-studio' ),
				'instruction' => 'Write one paragraph about: {topic}.',
				'vars'        => array( 'topic' ),
				'required'    => array( 'topic' ),
				'output'      => array( 'text' => 'string, HTML' ),
			),
			'paragraph_rewriter'  => array(
				'label'       => __( 'Paragraph Rewriter', 'ux-studio' ),
				'instruction' => 'Rewrite the following paragraph, keeping the same meaning but different wording: {source_content}.',
				'vars'        => array( 'source_content' ),
				'required'    => array( 'source_content' ),
				'output'      => array( 'text' => 'string, HTML' ),
			),
			'sentence_expander'   => array(
				'label'       => __( 'Sentence Expander', 'ux-studio' ),
				'instruction' => 'Expand this sentence into a fuller paragraph with more detail: {source_content}.',
				'vars'        => array( 'source_content' ),
				'required'    => array( 'source_content' ),
				'output'      => array( 'text' => 'string, HTML' ),
			),
			'text_summarizer'     => array(
				'label'       => __( 'Text Summarizer', 'ux-studio' ),
				'instruction' => 'Summarise the following text in 2-3 sentences: {source_content}.',
				'vars'        => array( 'source_content' ),
				'required'    => array( 'source_content' ),
				'output'      => array( 'text' => 'string' ),
			),
			'fix_grammar'         => array(
				'label'       => __( 'Fix Grammar', 'ux-studio' ),
				'instruction' => 'Fix all spelling and grammar mistakes in the following text, without changing its meaning or style: {source_content}.',
				'vars'        => array( 'source_content' ),
				'required'    => array( 'source_content' ),
				'output'      => array( 'text' => 'string' ),
			),
			'analogy'             => array(
				'label'       => __( 'Analogy', 'ux-studio' ),
				'instruction' => 'Write a simple, relatable analogy that explains: {topic}.',
				'vars'        => array( 'topic' ),
				'required'    => array( 'topic' ),
				'output'      => array( 'text' => 'string' ),
			),
			'faq'                 => array(
				'label'       => __( 'Frequently Asked Questions', 'ux-studio' ),
				'instruction' => 'Write 5 frequently asked questions with concise answers about: {topic}. Base them only on this content when given: {source_content}.',
				'vars'        => array( 'topic', 'source_content' ),
				'required'    => array( 'topic' ),
				'output'      => array( 'faq' => 'array of {question, answer}' ),
			),
			'topic_research'      => array(
				'label'       => __( 'Topic Research', 'ux-studio' ),
				'instruction' => 'For the seed keyword "{topic}", suggest 10 closely related/LSI keywords and 5 sub-topics a comprehensive article should cover. This is an editorial estimate, not real search-volume data.',
				'vars'        => array( 'topic' ),
				'required'    => array( 'topic' ),
				'output'      => array(
					'related_keywords' => 'array of 10 strings',
					'subtopics'        => 'array of 5 strings',
				),
			),

			// ── Product ──────────────────────────────────────────────
			'product_description' => array(
				'label'       => __( 'Product Description', 'ux-studio' ),
				'instruction' => 'Write a compelling e-commerce product description for: {topic}. Highlight benefits, not just features.',
				'vars'        => array( 'topic' ),
				'required'    => array( 'topic' ),
				'output'      => array( 'text' => 'string, HTML' ),
			),
			'product_pros_cons'   => array(
				'label'       => __( 'Product Pros & Cons', 'ux-studio' ),
				'instruction' => 'List realistic pros and cons for this product: {topic}.',
				'vars'        => array( 'topic' ),
				'required'    => array( 'topic' ),
				'output'      => array(
					'pros' => 'array of strings',
					'cons' => 'array of strings',
				),
			),
			'product_review'      => array(
				'label'       => __( 'Product Review', 'ux-studio' ),
				'instruction' => 'Write a balanced, first-person-style product review for: {topic}.',
				'vars'        => array( 'topic' ),
				'required'    => array( 'topic' ),
				'output'      => array( 'text' => 'string, HTML' ),
			),

			// ── Social & communication ───────────────────────────────
			'facebook_post'          => array(
				'label'       => __( 'Facebook Post', 'ux-studio' ),
				'instruction' => 'Write a Facebook post (1-3 short paragraphs, at most 1-2 emoji, no hashtags) based on: {source_content}.',
				'vars'        => array( 'source_content' ),
				'required'    => array( 'source_content' ),
				'output'      => array( 'text' => 'string' ),
			),
			'facebook_comment_reply' => array(
				'label'       => __( 'Facebook Comment Reply', 'ux-studio' ),
				'instruction' => 'Write a short, friendly reply to this Facebook comment: {source_content}.',
				'vars'        => array( 'source_content' ),
				'required'    => array( 'source_content' ),
				'output'      => array( 'text' => 'string' ),
			),
			'tweet'                  => array(
				'label'       => __( 'Tweet', 'ux-studio' ),
				'instruction' => 'Write a tweet (max 260 characters including up to 2 hashtags) based on: {source_content}.',
				'vars'        => array( 'source_content' ),
				'required'    => array( 'source_content' ),
				'output'      => array( 'text' => 'string, max 260 characters' ),
			),
			'tweet_reply'            => array(
				'label'       => __( 'Tweet Reply', 'ux-studio' ),
				'instruction' => 'Write a short reply tweet (max 260 characters) to: {source_content}.',
				'vars'        => array( 'source_content' ),
				'required'    => array( 'source_content' ),
				'output'      => array( 'text' => 'string, max 260 characters' ),
			),
			'instagram_caption'      => array(
				'label'       => __( 'Instagram Caption', 'ux-studio' ),
				'instruction' => 'Write an engaging Instagram caption (with up to 5 relevant hashtags at the end) based on: {source_content}.',
				'vars'        => array( 'source_content' ),
				'required'    => array( 'source_content' ),
				'output'      => array( 'text' => 'string' ),
			),
			'email'                  => array(
				'label'       => __( 'Email', 'ux-studio' ),
				'instruction' => 'Write a professional email about: {topic}.',
				'vars'        => array( 'topic' ),
				'required'    => array( 'topic' ),
				'output'      => array(
					'subject' => 'string',
					'body'    => 'string',
				),
			),
			'email_reply'            => array(
				'label'       => __( 'Email Reply', 'ux-studio' ),
				'instruction' => 'Write a professional reply to this email: {source_content}.',
				'vars'        => array( 'source_content' ),
				'required'    => array( 'source_content' ),
				'output'      => array(
					'subject' => 'string',
					'body'    => 'string',
				),
			),
			'personal_bio'           => array(
				'label'       => __( 'Personal Bio', 'ux-studio' ),
				'instruction' => 'Write a short professional personal bio (third person) based on: {source_content}.',
				'vars'        => array( 'source_content' ),
				'required'    => array( 'source_content' ),
				'output'      => array( 'text' => 'string' ),
			),
			'company_bio'            => array(
				'label'       => __( 'Company Bio', 'ux-studio' ),
				'instruction' => 'Write a short company "About us" bio based on: {source_content}.',
				'vars'        => array( 'source_content' ),
				'required'    => array( 'source_content' ),
				'output'      => array( 'text' => 'string' ),
			),
			'job_description'        => array(
				'label'       => __( 'Job Description', 'ux-studio' ),
				'instruction' => 'Write a job description for this role: {topic}.',
				'vars'        => array( 'topic' ),
				'required'    => array( 'topic' ),
				'output'      => array( 'text' => 'string, HTML' ),
			),
			'testimonial'            => array(
				'label'       => __( 'Testimonial', 'ux-studio' ),
				'instruction' => 'Write a realistic, first-person customer testimonial based on: {topic}.',
				'vars'        => array( 'topic' ),
				'required'    => array( 'topic' ),
				'output'      => array( 'text' => 'string' ),
			),

			// ── Copywriting formulas (structured persuasive copy) ────
			'aida' => array(
				'label'       => __( 'AIDA (Attention, Interest, Desire, Action)', 'ux-studio' ),
				'instruction' => 'Write persuasive copy for "{topic}" using the AIDA formula: Attention, Interest, Desire, Action.',
				'vars'        => array( 'topic' ),
				'required'    => array( 'topic' ),
				'output'      => array(
					'attention' => 'string',
					'interest'  => 'string',
					'desire'    => 'string',
					'action'    => 'string',
				),
			),
			'idca' => array(
				'label'       => __( 'IDCA (Interest, Desire, Conviction, Action)', 'ux-studio' ),
				'instruction' => 'Write persuasive copy for "{topic}" using the IDCA formula: Interest, Desire, Conviction, Action.',
				'vars'        => array( 'topic' ),
				'required'    => array( 'topic' ),
				'output'      => array(
					'interest'   => 'string',
					'desire'     => 'string',
					'conviction' => 'string',
					'action'     => 'string',
				),
			),
			'pas'  => array(
				'label'       => __( 'PAS (Problem, Agitate, Solution)', 'ux-studio' ),
				'instruction' => 'Write persuasive copy for "{topic}" using the PAS formula: Problem, Agitate, Solution.',
				'vars'        => array( 'topic' ),
				'required'    => array( 'topic' ),
				'output'      => array(
					'problem'  => 'string',
					'agitate'  => 'string',
					'solution' => 'string',
				),
			),
			'hero' => array(
				'label'       => __( 'HERO (Hook, Engage, Reveal, Offer)', 'ux-studio' ),
				'instruction' => 'Write persuasive copy for "{topic}" using the HERO formula: Hook, Engage, Reveal, Offer.',
				'vars'        => array( 'topic' ),
				'required'    => array( 'topic' ),
				'output'      => array(
					'hook'   => 'string',
					'engage' => 'string',
					'reveal' => 'string',
					'offer'  => 'string',
				),
			),
			'spin' => array(
				'label'       => __( 'SPIN (Situation, Problem, Implication, Need-payoff)', 'ux-studio' ),
				'instruction' => 'Write persuasive copy for "{topic}" using the SPIN formula: Situation, Problem, Implication, Need-payoff.',
				'vars'        => array( 'topic' ),
				'required'    => array( 'topic' ),
				'output'      => array(
					'situation'   => 'string',
					'problem'     => 'string',
					'implication' => 'string',
					'need_payoff' => 'string',
				),
			),
			'bab'  => array(
				'label'       => __( 'BAB (Before, After, Bridge)', 'ux-studio' ),
				'instruction' => 'Write persuasive copy for "{topic}" using the BAB formula: Before, After, Bridge.',
				'vars'        => array( 'topic' ),
				'required'    => array( 'topic' ),
				'output'      => array(
					'before' => 'string',
					'after'  => 'string',
					'bridge' => 'string',
				),
			),

			// ── Video & podcast ──────────────────────────────────────
			'youtube_script'      => array(
				'label'       => __( 'YouTube Video Script', 'ux-studio' ),
				'instruction' => 'Write a YouTube video script (hook, main points, outro/CTA) about: {topic}.',
				'vars'        => array( 'topic' ),
				'required'    => array( 'topic' ),
				'output'      => array( 'text' => 'string' ),
				'max_tokens'  => 1200,
			),
			'youtube_description' => array(
				'label'       => __( 'YouTube Video Description', 'ux-studio' ),
				'instruction' => 'Write a YouTube video description (with a short summary and relevant keywords) about: {topic}.',
				'vars'        => array( 'topic' ),
				'required'    => array( 'topic' ),
				'output'      => array( 'text' => 'string' ),
			),
			'podcast_outline'     => array(
				'label'       => __( 'Podcast Episode Outline', 'ux-studio' ),
				'instruction' => 'Create a podcast episode outline (segments with talking points) about: {topic}.',
				'vars'        => array( 'topic' ),
				'required'    => array( 'topic' ),
				'output'      => array( 'outline' => 'array of {segment, talking_points: array of strings}' ),
			),

			// ── Other ────────────────────────────────────────────────
			'recipe'   => array(
				'label'       => __( 'Recipe', 'ux-studio' ),
				'instruction' => 'Write a recipe for: {topic}.',
				'vars'        => array( 'topic' ),
				'required'    => array( 'topic' ),
				'output'      => array(
					'ingredients' => 'array of strings',
					'steps'       => 'array of strings',
				),
			),
			'freeform' => array(
				'label'       => __( 'Freeform Writing', 'ux-studio' ),
				'instruction' => '{topic}',
				'vars'        => array( 'topic' ),
				'required'    => array( 'topic' ),
				'output'      => array( 'text' => 'string' ),
				'max_tokens'  => 1200,
			),
			'ai_command' => array(
				'label'       => __( 'AI Command', 'ux-studio' ),
				'instruction' => 'Follow this instruction exactly, optionally using the provided source content: {topic}. Source content (if relevant): {source_content}.',
				'vars'        => array( 'topic', 'source_content' ),
				'required'    => array( 'topic' ),
				'output'      => array( 'text' => 'string' ),
				'max_tokens'  => 1200,
			),
		);
	}

	/**
	 * One tool definition, or null when the key is unknown.
	 *
	 * @return array{label:string, instruction:string, vars:string[], required:string[], output:array<string,string>, max_tokens?:int}|null
	 */
	public static function get( string $tool_key ): ?array {
		$all = self::all();
		return $all[ $tool_key ] ?? null;
	}

	/**
	 * Fills `{var}` placeholders in the instruction template.
	 *
	 * @param array<string, string> $vars
	 */
	public static function fill_instruction( string $instruction, array $vars ): string {
		$replacements = array();
		foreach ( $vars as $key => $value ) {
			$replacements[ '{' . $key . '}' ] = (string) $value;
		}
		return strtr( $instruction, $replacements );
	}

	/**
	 * JSON-response instructions built from a tool's `output` schema.
	 *
	 * @param array<string, string> $output
	 */
	public static function json_instructions( array $output ): string {
		$parts = array();
		foreach ( $output as $key => $desc ) {
			$parts[] = '"' . $key . '" (' . $desc . ')';
		}
		return "\n" . 'Return the answer strictly as JSON (no markdown code block wrapper), with exactly these keys: '
			. implode( ', ', $parts ) . '.';
	}
}
