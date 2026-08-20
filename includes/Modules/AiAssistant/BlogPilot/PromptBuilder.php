<?php
/**
 * Builds the system/user prompts sent to the AI provider for one Blog Pilot
 * article, and parses the resulting JSON response.
 *
 * Ported from the legacy ux1-wordpress-customizer AI Assistant module
 * (includes/blog-pilot/PromptBuilder.php).
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\AiAssistant\BlogPilot;

defined( 'ABSPATH' ) || exit;

final class PromptBuilder {

	/**
	 * @param array<string, mixed> $config
	 * @return array<int, array{role:string,content:string}>
	 */
	public static function build_messages( string $topic, string $article_type_id, array $config ): array {
		return array(
			array(
				'role'    => 'system',
				'content' => self::build_system_prompt( $article_type_id, $config ),
			),
			array(
				'role'    => 'user',
				'content' => self::build_user_prompt( $topic, $article_type_id, $config ),
			),
		);
	}

	/**
	 * @param array<string, mixed> $config
	 */
	public static function build_system_prompt( string $article_type_id, array $config ): string {
		$language     = self::get_language_name( (string) ( $config['language'] ?? 'cs' ) );
		$tone         = self::get_tone_name( (string) ( $config['tone'] ?? 'professional' ) );
		$length_range = ArticleTypes::get_length_range( (string) ( $config['length'] ?? 'medium' ) );

		$prompt  = "You are a professional copywriter and blogger. Your task is to write a high-quality blog article.\n\n";
		$prompt .= "RULES:\n";
		$prompt .= "- Write in this language: {$language}\n";
		$prompt .= "- Tone: {$tone}\n";
		$prompt .= "- Length: {$length_range} words\n";
		$prompt .= "- Use markdown formatting (## for headings, **bold**, - for lists)\n";
		$prompt .= "- The article must be original, informative and engaging\n";
		$prompt .= "- Weave relevant keywords in naturally\n";
		$prompt .= "- Craft a catchy title and a short excerpt (1-2 sentences)\n";

		if ( ! empty( $config['custom_instructions'] ) ) {
			$prompt .= "\nADDITIONAL INSTRUCTIONS:\n" . $config['custom_instructions'] . "\n";
		}

		$prompt .= "\nRETURN THE ANSWER STRICTLY IN THIS JSON FORMAT (no markdown code block wrapper):\n";
		$prompt .= "{\n";
		$prompt .= '  "post_title": "Article title",' . "\n";
		$prompt .= '  "post_content": "Full article content in markdown",' . "\n";
		$prompt .= '  "post_excerpt": "Short excerpt (1-2 sentences)",' . "\n";
		$prompt .= '  "post_tags": ["tag1", "tag2", "tag3"],' . "\n";
		$prompt .= '  "category_suggestion": "Suggested category"' . "\n";
		$prompt .= "}\n";

		return $prompt;
	}

	/**
	 * @param array<string, mixed> $config
	 */
	public static function build_user_prompt( string $topic, string $article_type_id, array $config ): string {
		$article_type = ArticleTypes::get( $article_type_id );
		$type_name    = $article_type ? $article_type['name'] : 'Informative article';
		$prompt_hint  = $article_type ? $article_type['prompt_hint'] : '';

		$prompt  = "TOPIC: {$topic}\n";
		$prompt .= "ARTICLE TYPE: {$type_name}\n";

		if ( $prompt_hint ) {
			$prompt .= "\nTYPE-SPECIFIC INSTRUCTIONS: {$prompt_hint}\n";
		}

		$prompt .= "\nGenerate the full article as specified and return the result as JSON.";

		return $prompt;
	}

	private static function get_language_name( string $code ): string {
		$languages = ArticleTypes::get_languages();
		return $languages[ $code ] ?? $code;
	}

	private static function get_tone_name( string $key ): string {
		$tones = ArticleTypes::get_tones();
		return $tones[ $key ] ?? $key;
	}

	/**
	 * Parses the AI's JSON response, stripping a markdown code block wrapper
	 * and recovering from minor formatting issues where possible.
	 *
	 * @return array{title:string,content:string,excerpt:string,tags:array<int,string>,category:string}|null
	 */
	public static function parse_ai_response( string $response ): ?array {
		$response = trim( $response );

		if ( preg_match( '/^```(?:json)?\s*\n?(.*?)\n?\s*```$/s', $response, $matches ) ) {
			$response = trim( $matches[1] );
		}

		$data = json_decode( $response, true );

		if ( JSON_ERROR_NONE !== json_last_error() ) {
			if ( preg_match( '/\{[^{}]*"post_title"[^{}]*\}/s', $response, $matches ) ) {
				$data = json_decode( $matches[0], true );
			}
		}

		if ( ! is_array( $data ) || empty( $data['post_title'] ) || empty( $data['post_content'] ) ) {
			return null;
		}

		return array(
			'title'    => sanitize_text_field( (string) $data['post_title'] ),
			'content'  => (string) $data['post_content'],
			'excerpt'  => sanitize_text_field( (string) ( $data['post_excerpt'] ?? '' ) ),
			'tags'     => array_map( 'sanitize_text_field', (array) ( $data['post_tags'] ?? array() ) ),
			'category' => sanitize_text_field( (string) ( $data['category_suggestion'] ?? '' ) ),
		);
	}
}
