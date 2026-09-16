<?php
/**
 * MCP tools nad SEO mostem do centrální aplikace (RankMath Content AI
 * parita, PLAN.md §17.5, F5 "RankBot"). Díky nim může AI agent pracovat se
 * skóre, topic researchem, schema markupem i návrhy odkazů stejně jako
 * člověk v editorovém panelu.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\AiAssistant\Mcp\Tools;

use UxStudio\Modules\AiAssistant\SeoAiClient;
use UxStudio\Modules\AiAssistant\SeoManager;
use UxStudio\Modules\AiAssistant\SeoScoreEditor;

defined( 'ABSPATH' ) || exit;

/**
 * Bespoke callbacky (ne RestEndpointTool wrapper - ten je vyhrazený pro
 * wp/v2/wc/v3 routy, nikdy uxstudio/v1), stejný vzor jako SiteInfoTools.
 * Všechny čtyři nástroje jsou read-only: nic nezapisují do obsahu, jen
 * vracejí analýzu/návrhy, o jejichž použití rozhoduje volající.
 */
class SeoTools extends RestEndpointTool {

	protected function get_operations(): array {
		return array();
	}

	public function register(): void {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}

		$this->register_ability(
			'seo-score',
			__( 'Get SEO Score', 'ux-studio' ),
			__( 'Analyse a post (or raw content) against the central app SEO checks and return the score, grade and per-check findings.', 'ux-studio' ),
			array(
				'post_id'       => array(
					'type'        => 'integer',
					'description' => __( 'Post ID to analyse. Omit when passing raw content.', 'ux-studio' ),
				),
				'content'       => array(
					'type'        => 'string',
					'description' => __( 'Raw HTML content. Overrides the post content when both are given.', 'ux-studio' ),
				),
				'focus_keyword' => array(
					'type'        => 'string',
					'description' => __( 'Focus keyword. Falls back to the one stored on the post.', 'ux-studio' ),
				),
			),
			array( $this, 'seo_score' )
		);

		$this->register_ability(
			'seo-topic-research',
			__( 'SEO Topic Research', 'ux-studio' ),
			__( 'Suggest related/LSI keywords and subtopics for a seed keyword. Editorial AI estimate, NOT real search-volume data.', 'ux-studio' ),
			array(
				'seed_keyword' => array(
					'type'        => 'string',
					'description' => __( 'Seed keyword to research.', 'ux-studio' ),
					'required'    => true,
				),
			),
			array( $this, 'seo_topic_research' )
		);

		$this->register_ability(
			'seo-schema',
			__( 'Generate Schema Markup', 'ux-studio' ),
			__( 'Build JSON-LD schema (Article, plus FAQPage auto-detected from question headings) for a post.', 'ux-studio' ),
			array(
				'post_id' => array(
					'type'        => 'integer',
					'description' => __( 'Post ID to build schema for.', 'ux-studio' ),
					'required'    => true,
				),
			),
			array( $this, 'seo_schema' )
		);

		$this->register_ability(
			'seo-link-suggestions',
			__( 'Suggest Internal Links', 'ux-studio' ),
			__( 'Suggest internal links for a post from the client portfolio index in the central app. Empty when the site has no portfolio key there.', 'ux-studio' ),
			array(
				'post_id' => array(
					'type'        => 'integer',
					'description' => __( 'Post ID to suggest links for.', 'ux-studio' ),
					'required'    => true,
				),
			),
			array( $this, 'seo_link_suggestions' )
		);
	}

	/**
	 * @param array<string, array<string, mixed>> $properties
	 */
	private function register_ability( string $slug, string $label, string $description, array $properties, callable $callback ): void {
		wp_register_ability(
			'ai-assistant/' . $slug,
			array(
				'label'               => $label,
				'description'         => $description,
				'category'            => self::CATEGORY,
				'execute_callback'    => $callback,
				'permission_callback' => static function (): bool {
					return current_user_can( 'edit_posts' );
				},
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => $properties,
				),
				'meta'                => array(
					'show_in_rest' => true,
					'mcp'          => array(
						'public' => true,
						'type'   => 'tool',
					),
					'annotations'  => array(
						'title'    => $label,
						'readonly' => true,
					),
				),
			)
		);
	}

	public function seo_score( array $input = array() ): array {
		$fields = $this->fields_from_post( (int) ( $input['post_id'] ?? 0 ) );
		if ( ! empty( $input['content'] ) ) {
			$fields['content'] = (string) $input['content'];
		}
		if ( ! empty( $input['focus_keyword'] ) ) {
			$fields['focus_keyword'] = (string) $input['focus_keyword'];
		}
		if ( '' === trim( (string) $fields['content'] ) ) {
			return $this->text_result( array( 'error' => __( 'No content to analyse - pass post_id or content.', 'ux-studio' ) ) );
		}

		return $this->text_result( ( new SeoAiClient() )->analyze( $fields ) );
	}

	public function seo_topic_research( array $input = array() ): array {
		$seed = trim( (string) ( $input['seed_keyword'] ?? '' ) );
		if ( '' === $seed ) {
			return $this->text_result( array( 'error' => __( 'seed_keyword is required.', 'ux-studio' ) ) );
		}

		return $this->text_result( ( new SeoAiClient() )->topic_research( $seed ) );
	}

	public function seo_schema( array $input = array() ): array {
		$post_id = (int) ( $input['post_id'] ?? 0 );
		if ( $post_id <= 0 ) {
			return $this->text_result( array( 'error' => __( 'post_id is required.', 'ux-studio' ) ) );
		}

		return $this->text_result( ( new SeoAiClient() )->schema( $this->fields_from_post( $post_id ) ) );
	}

	public function seo_link_suggestions( array $input = array() ): array {
		$post_id = (int) ( $input['post_id'] ?? 0 );
		if ( $post_id <= 0 ) {
			return $this->text_result( array( 'error' => __( 'post_id is required.', 'ux-studio' ) ) );
		}

		$post = get_post( $post_id );
		if ( ! $post ) {
			return $this->text_result( array( 'error' => __( 'Post not found.', 'ux-studio' ) ) );
		}

		return $this->text_result(
			( new SeoAiClient() )->link_suggestions( (string) $post->post_content, (string) get_permalink( $post_id ) )
		);
	}

	/**
	 * Skládá vstup pro most ze skutečného postu - stejné meta klíče, jaké
	 * píše editorový panel (SeoScoreEditor) i AI generátor (SeoManager).
	 *
	 * @return array<string, string>
	 */
	private function fields_from_post( int $post_id ): array {
		$post = $post_id > 0 ? get_post( $post_id ) : null;
		if ( ! $post ) {
			return array(
				'title'         => '',
				'content'       => '',
				'meta_title'    => '',
				'meta_desc'     => '',
				'focus_keyword' => '',
				'slug'          => '',
			);
		}

		return array(
			'title'         => (string) $post->post_title,
			'content'       => (string) $post->post_content,
			'meta_title'    => (string) get_post_meta( $post_id, SeoManager::META_TITLE, true ),
			'meta_desc'     => (string) get_post_meta( $post_id, SeoManager::META_DESCRIPTION, true ),
			'focus_keyword' => (string) get_post_meta( $post_id, SeoScoreEditor::META_FOCUS_KEYWORD, true ),
			'slug'          => (string) $post->post_name,
		);
	}

	/**
	 * @param array<string, mixed> $data
	 * @return array<int, array<string, string>>
	 */
	private function text_result( array $data ): array {
		return array(
			array(
				'text' => (string) wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ),
			),
		);
	}
}
