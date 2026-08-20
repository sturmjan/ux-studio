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
