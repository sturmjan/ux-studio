<?php
/**
 * AI-driven content generation: post/page copy, WooCommerce product
 * descriptions and SEO meta - all via the shared provider abstraction.
 *
 * Ported from the legacy ux1-wordpress-customizer AI Assistant module
 * (includes/ContentGenerator.php), adapted to this plugin's provider
 * interface (generate_content()) and settings/usage classes.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\AiAssistant;

use UxStudio\Core\Settings;
use UxStudio\Modules\AiAssistant\Providers\AiProviderInterface;

defined( 'ABSPATH' ) || exit;

final class ContentGenerator {

	private AiProviderInterface $provider;

	private static ?Settings $settings = null;

	public function __construct( ?AiProviderInterface $provider = null ) {
		$this->provider = $provider ?? ProviderFactory::create();
	}

	/**
	 * Generates content (post/page/CPT) from a short description.
	 *
	 * @return array<string, mixed> Decoded AI JSON (title/content/excerpt/category/tags) plus _usage/_provider/_model.
	 */
	public function generate( array $params ): array {
		$post_type   = sanitize_text_field( (string) ( $params['post_type'] ?? 'post' ) );
		$tone        = sanitize_text_field( (string) ( $params['tone'] ?? 'neutral' ) );
		$length      = sanitize_text_field( (string) ( $params['length'] ?? 'medium' ) );
		$description = sanitize_textarea_field( (string) ( $params['description'] ?? '' ) );
		$keywords    = sanitize_text_field( (string) ( $params['focus_keyword'] ?? '' ) );

		if ( strlen( $description ) < 10 ) {
			throw new \RuntimeException( __( 'The content description must be at least 10 characters long.', 'ux-studio' ) );
		}

		$system_prompt = self::content_system_prompt( $post_type, $tone, $length );
		$user_prompt   = sprintf(
			/* translators: %s: content description provided by the admin */
			__( 'Create content on the following topic: %s', 'ux-studio' ),
			$description
		);
		if ( '' !== $keywords ) {
			$user_prompt .= "\n\n" . sprintf(
				/* translators: %s: comma-separated SEO keywords */
				__( 'SEO focus keywords: %s', 'ux-studio' ),
				$keywords
			);
		}

		$model  = $this->get_model();
		$result = $this->provider->generate_content( $system_prompt, $user_prompt, $model );

		UsageTracker::log(
			$this->provider->get_id(),
			$model,
			'content_generation',
			$result['usage']['input_tokens'],
			$result['usage']['output_tokens']
		);

		$parsed              = $this->parse_json_response( $result['content'] );
		$parsed['_usage']    = $result['usage'];
		$parsed['_provider'] = $this->provider->get_id();
		$parsed['_model']    = $model;

		return $parsed;
	}

	/**
	 * Generates a WooCommerce product description.
	 *
	 * @return array<string, mixed>
	 */
	public function generate_woo_product( array $params ): array {
		$tone        = sanitize_text_field( (string) ( $params['tone'] ?? 'neutral' ) );
		$length      = sanitize_text_field( (string) ( $params['length'] ?? 'medium' ) );
		$description = sanitize_textarea_field( (string) ( $params['description'] ?? '' ) );

		if ( strlen( $description ) < 10 ) {
			throw new \RuntimeException( __( 'The product description must be at least 10 characters long.', 'ux-studio' ) );
		}

		$system_prompt = self::woo_system_prompt( $tone, $length );
		$user_prompt   = sprintf(
			/* translators: %s: product description provided by the admin */
			__( 'Create a product description for: %s', 'ux-studio' ),
			$description
		);

		$model  = $this->get_model();
		$result = $this->provider->generate_content( $system_prompt, $user_prompt, $model );

		UsageTracker::log(
			$this->provider->get_id(),
			$model,
			'woo_description',
			$result['usage']['input_tokens'],
			$result['usage']['output_tokens']
		);

		$parsed           = $this->parse_json_response( $result['content'] );
		$parsed['_usage'] = $result['usage'];

		return $parsed;
	}

	/**
	 * Generates SEO meta (title/description/keywords) from existing content.
	 *
	 * @return array<string, mixed>
	 */
	public function generate_seo_meta( string $content ): array {
		$system_prompt = self::seo_system_prompt();
		$user_prompt   = __( 'Content:', 'ux-studio' ) . "\n\n" . wp_strip_all_tags( $content );

		$model  = $this->get_model();
		$result = $this->provider->generate_content(
			$system_prompt,
			$user_prompt,
			$model,
			array( 'max_tokens' => 500 )
		);

		UsageTracker::log(
			$this->provider->get_id(),
			$model,
			'seo_meta',
			$result['usage']['input_tokens'],
			$result['usage']['output_tokens']
		);

		$parsed           = $this->parse_json_response( $result['content'] );
		$parsed['_usage'] = $result['usage'];

		return $parsed;
	}

	/**
	 * Generates platform-specific social captions (Facebook/Instagram/X)
	 * from existing content - one AI call, one JSON response for whichever
	 * platforms were requested (Rank Math's "Facebook Post"/"Instagram
	 * Caption"/"Tweet" tools, applied to a post the site already has).
	 *
	 * @param string   $content   Source content (HTML or plain text) to base captions on.
	 * @param string[] $platforms Subset of 'facebook', 'instagram', 'x'.
	 * @return array<string, mixed> Decoded AI JSON (one key per requested platform) plus _usage.
	 */
	public function generate_social_captions( string $content, array $platforms ): array {
		$platforms = array_values( array_intersect( $platforms, array( 'facebook', 'instagram', 'x' ) ) );
		if ( empty( $platforms ) ) {
			throw new \RuntimeException( __( 'Select at least one platform.', 'ux-studio' ) );
		}

		$system_prompt = self::social_system_prompt( $platforms );
		$user_prompt   = __( 'Source content:', 'ux-studio' ) . "\n\n" . wp_strip_all_tags( $content );

		$model  = $this->get_model();
		$result = $this->provider->generate_content(
			$system_prompt,
			$user_prompt,
			$model,
			array( 'max_tokens' => 600 )
		);

		UsageTracker::log(
			$this->provider->get_id(),
			$model,
			'social_caption',
			$result['usage']['input_tokens'],
			$result['usage']['output_tokens']
		);

		$parsed           = $this->parse_json_response( $result['content'] );
		$parsed['_usage'] = $result['usage'];

		return $parsed;
	}

	/**
	 * Generic entry point for the ~40 RankMath-Content-AI-style tools
	 * registered in PromptLibrary — one AI call, prompt built from the tool's
	 * template instead of a bespoke method per tool.
	 *
	 * @param string                $tool_key Key from PromptLibrary::all().
	 * @param array<string, string> $vars     Values for the tool's `{placeholder}` vars.
	 * @param array{tone?:string}   $opts     Optional overrides (tone; length is not used by these short-form tools).
	 * @return array<string, mixed> Decoded AI JSON (shape defined by the tool's `output`) plus _usage/_provider/_model/_tool.
	 */
	public function generate_from_prompt( string $tool_key, array $vars, array $opts = array() ): array {
		$tool = PromptLibrary::get( $tool_key );
		if ( null === $tool ) {
			throw new \RuntimeException(
				sprintf( /* translators: %s: unknown tool key */ __( 'Unknown content tool: %s', 'ux-studio' ), $tool_key )
			);
		}

		foreach ( $tool['required'] as $required_var ) {
			if ( '' === trim( (string) ( $vars[ $required_var ] ?? '' ) ) ) {
				throw new \RuntimeException(
					sprintf( /* translators: %s: missing variable name */ __( 'Missing required field: %s', 'ux-studio' ), $required_var )
				);
			}
		}

		$clean_vars = array();
		foreach ( $tool['vars'] as $var_name ) {
			$raw                    = (string) ( $vars[ $var_name ] ?? '' );
			$clean_vars[ $var_name ] = 'source_content' === $var_name ? wp_strip_all_tags( $raw ) : sanitize_textarea_field( $raw );
		}

		$tone          = sanitize_text_field( (string) ( $opts['tone'] ?? 'neutral' ) );
		$language_line = 'cs' === self::language() ? 'Piš v jazyce: čeština.' : 'Write in: English.';
		$instruction   = PromptLibrary::fill_instruction( $tool['instruction'], $clean_vars );

		$system_prompt = sprintf(
			"You are an expert content writer and SEO copywriter.\n%s\nTone: %s.\n%s%s",
			$language_line,
			$tone,
			$instruction,
			PromptLibrary::json_instructions( $tool['output'] )
		);

		$model    = $this->get_model();
		$max_toks = (int) ( $tool['max_tokens'] ?? 500 );
		$result   = $this->provider->generate_content( $system_prompt, $instruction, $model, array( 'max_tokens' => $max_toks ) );

		UsageTracker::log(
			$this->provider->get_id(),
			$model,
			'content_tool:' . $tool_key,
			$result['usage']['input_tokens'],
			$result['usage']['output_tokens']
		);

		$parsed              = $this->parse_json_response( $result['content'] );
		$parsed['_usage']    = $result['usage'];
		$parsed['_provider'] = $this->provider->get_id();
		$parsed['_model']    = $model;
		$parsed['_tool']     = $tool_key;

		return $parsed;
	}

	/**
	 * ALT text from CONTEXT (attachment title/caption/filename + the post
	 * it's attached to) - NOT from the actual image pixels. No provider in
	 * ProviderFactory implements vision input yet (AiProviderInterface is
	 * text-only), so this is an editorial estimate, same limited scope as
	 * emcp-tools' `add-alt-text-from-context` MCP tool. Honest about that in
	 * the prompt so the AI doesn't invent visual details it cannot know.
	 *
	 * @param array{title?:string, caption?:string, filename?:string, post_title?:string} $context
	 * @return array<string, mixed> {alt_text} plus _usage.
	 */
	public function generate_alt_text( array $context ): array {
		$title      = sanitize_text_field( (string) ( $context['title'] ?? '' ) );
		$caption    = sanitize_text_field( (string) ( $context['caption'] ?? '' ) );
		$filename   = sanitize_text_field( (string) ( $context['filename'] ?? '' ) );
		$post_title = sanitize_text_field( (string) ( $context['post_title'] ?? '' ) );

		if ( '' === $title && '' === $caption && '' === $filename && '' === $post_title ) {
			throw new \RuntimeException( __( 'No context available to describe this image (title, caption, filename or containing post are all empty).', 'ux-studio' ) );
		}

		$language_line = 'cs' === self::language() ? 'Piš v jazyce: čeština.' : 'Write in: English.';
		$system_prompt = 'You write concise, descriptive image ALT text for web accessibility and SEO. '
			. $language_line
			. ' You do NOT see the actual image - infer a plausible, generic description only from the '
			. "context below. Do not invent specific visual details (colours, exact objects, people) you cannot know.\n"
			. 'Return the answer strictly as JSON (no markdown code block wrapper), with exactly this key: '
			. '"alt_text" (string, max 125 characters).';
		$user_prompt = sprintf(
			"Image filename: %s\nAttachment title: %s\nAttachment caption: %s\nUsed in article titled: %s",
			$filename ?: '–',
			$title ?: '–',
			$caption ?: '–',
			$post_title ?: '–'
		);

		$model  = $this->get_model();
		$result = $this->provider->generate_content( $system_prompt, $user_prompt, $model, array( 'max_tokens' => 150 ) );

		UsageTracker::log(
			$this->provider->get_id(),
			$model,
			'alt_text',
			$result['usage']['input_tokens'],
			$result['usage']['output_tokens']
		);

		$parsed           = $this->parse_json_response( $result['content'] );
		$parsed['_usage'] = $result['usage'];

		return $parsed;
	}

	/**
	 * Drafts a set of form fields from a plain-language description, for the
	 * Form Builder's "Generate with AI" button (UxStudio\Modules\Forms,
	 * PLAN.md §20.11/F3). Returns a raw, UNTRUSTED field list - the caller
	 * (Forms\Module) MUST still run it through Fields::sanitize_fields()
	 * before it can touch a form definition; this method only talks to the
	 * AI, it never writes anything.
	 *
	 * @return array<string, mixed> { fields: array<int, array<string, mixed>> } plus _usage/_provider/_model.
	 */
	public function generate_form_fields( string $description ): array {
		$description = sanitize_textarea_field( $description );
		if ( strlen( $description ) < 10 ) {
			throw new \RuntimeException( __( 'Describe the form you want in at least 10 characters.', 'ux-studio' ) );
		}

		$language_line = 'cs' === self::language() ? 'Piš popisky a texty v jazyce: čeština.' : 'Write labels/text in: English.';
		$system_prompt = 'You design web form field lists for a WordPress form builder. ' . $language_line . "\n"
			. "Choose only from these field types: text, textarea, email, url, tel, number, hidden, select, radio, checkbox, checkbox_group, multiselect, acceptance, date, time, file. \n"
			. "Order fields sensibly (e.g. name before email before message). Mark fields required only when it genuinely makes sense. \n"
			. "For select/radio/checkbox_group/multiselect fields, include 2-6 short 'options' as plain strings. \n"
			. 'Return the answer strictly as JSON (no markdown code block wrapper), with exactly one key "fields": an array of objects, '
			. 'each with keys "type" (one of the allowed types above), "label" (string), "required" (boolean), '
			. 'and optionally "placeholder" (string) and "options" (array of strings, only for choice types).';
		$user_prompt = sprintf(
			/* translators: %s: plain-language description of the desired form. */
			__( 'Build the field list for this form: %s', 'ux-studio' ),
			$description
		);

		$model  = $this->get_model();
		$result = $this->provider->generate_content( $system_prompt, $user_prompt, $model, array( 'max_tokens' => 1200 ) );

		UsageTracker::log(
			$this->provider->get_id(),
			$model,
			'form_fields',
			$result['usage']['input_tokens'],
			$result['usage']['output_tokens']
		);

		$parsed              = $this->parse_json_response( $result['content'] );
		$parsed['fields']    = is_array( $parsed['fields'] ?? null ) ? $parsed['fields'] : array();
		$parsed['_usage']    = $result['usage'];
		$parsed['_provider'] = $this->provider->get_id();
		$parsed['_model']    = $model;

		return $parsed;
	}

	private static function social_system_prompt( array $platforms ): string {
		$language_line = 'cs' === self::language() ? 'Piš v jazyce: čeština.' : 'Write in: English.';
		$limits        = array(
			'facebook'  => 'Facebook: 1-3 short paragraphs, at most 1-2 emoji, no hashtags.',
			'instagram' => 'Instagram: short, engaging caption, up to 5 relevant hashtags at the end.',
			'x'         => 'X (Twitter): max 260 characters total including hashtags, at most 2 hashtags.',
		);
		$lines = array();
		foreach ( $platforms as $p ) {
			$lines[] = '- ' . $limits[ $p ];
		}

		return 'You are a social media manager. Write short promotional captions for the source content below, '
			. 'one per requested platform. ' . $language_line . "\n"
			. "Never invent facts not present in the source content. Never include raw URLs (a link is added separately).\n"
			. implode( "\n", $lines )
			. "\n" . 'Return the answer strictly as JSON (no markdown code block wrapper), with exactly these keys: '
			. implode( ', ', $platforms ) . ' (each a string).';
	}

	/**
	 * Parses an AI JSON response, stripping a ```json ... ``` markdown wrapper if present.
	 *
	 * @return array<string, mixed>
	 */
	private function parse_json_response( string $response ): array {
		$response = trim( $response );

		if ( preg_match( '/```(?:json)?\s*([\s\S]*?)```/', $response, $matches ) ) {
			$response = trim( $matches[1] );
		}

		$data = json_decode( $response, true );

		if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $data ) ) {
			throw new \RuntimeException(
				sprintf(
					/* translators: %s: json_last_error_msg() text */
					__( 'The AI returned an invalid JSON response: %s', 'ux-studio' ),
					json_last_error_msg()
				)
			);
		}

		return $data;
	}

	/**
	 * Current model for the active provider, from module settings.
	 */
	private function get_model(): string {
		$provider_id   = $this->provider->get_id();
		$setting_key   = $provider_id . '_model';
		$models        = $this->provider->get_models();
		$default_model = array_key_first( $models );

		return (string) self::settings()->get( $setting_key, $default_model );
	}

	private static function settings(): Settings {
		if ( null === self::$settings ) {
			self::$settings = new Settings( 'uxstudio_ai_assistant' );
		}
		return self::$settings;
	}

	/**
	 * Content language configured for the module ('cs'/'en'), default 'cs'.
	 */
	private static function language(): string {
		$language = (string) self::settings()->get( 'language', 'cs' );
		return in_array( $language, array( 'cs', 'en' ), true ) ? $language : 'cs';
	}

	private static function json_instructions_content(): string {
		return "\n" . 'Return the answer strictly as JSON (no markdown code block wrapper), with keys: '
			. '"title" (string), "content" (HTML string), "excerpt" (string, 1-2 sentences), '
			. '"category" (string), "tags" (array of strings).';
	}

	private static function json_instructions_woo(): string {
		return "\n" . 'Return the answer strictly as JSON (no markdown code block wrapper), with keys: '
			. '"title" (string), "content" (HTML string, the full product description), '
			. '"short_description" (HTML string, 1-2 sentences for the product summary).';
	}

	private static function json_instructions_seo(): string {
		return "\n" . 'Return the answer strictly as JSON (no markdown code block wrapper), with keys: '
			. '"seo_title" (string, max 60 characters), "seo_description" (string, max 160 characters), '
			. '"seo_keywords" (string, comma-separated).';
	}

	private static function content_system_prompt( string $post_type, string $tone, string $length ): string {
		$language_line = 'cs' === self::language() ? 'Piš v jazyce: čeština.' : 'Write in: English.';

		return sprintf(
			"You are a professional copywriter creating content for a WordPress %s.\n%s\nTone: %s.\nLength: %s.\nUse clean semantic HTML for the content field (headings, paragraphs, lists).%s",
			$post_type,
			$language_line,
			$tone,
			$length,
			self::json_instructions_content()
		);
	}

	private static function woo_system_prompt( string $tone, string $length ): string {
		$language_line = 'cs' === self::language() ? 'Piš v jazyce: čeština.' : 'Write in: English.';

		return sprintf(
			"You are a professional e-commerce copywriter creating a WooCommerce product description.\n%s\nTone: %s.\nLength: %s.\nHighlight benefits and features, use clean semantic HTML.%s",
			$language_line,
			$tone,
			$length,
			self::json_instructions_woo()
		);
	}

	private static function seo_system_prompt(): string {
		$language_line = 'cs' === self::language() ? 'Piš v jazyce: čeština.' : 'Write in: English.';

		return 'You are an SEO specialist. Analyse the given content and produce concise, search-optimised meta data. '
			. $language_line
			. self::json_instructions_seo();
	}
}
