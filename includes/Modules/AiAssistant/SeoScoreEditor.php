<?php
/**
 * Live SEO score sidebar in the block editor - reads/writes the same
 * `_uxstudio_ai_seo_*` meta as SeoManager, backed by SeoScorePanel/SeoAiClient
 * (RankMath Content AI parity, PLAN.md §17, F1).
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\AiAssistant;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the REST-exposed meta the panel reads/writes and enqueues the
 * vanilla-JS PluginSidebar - same pattern as
 * Modules\ExternalPermalinks\Module::enqueue_assets()/register_meta().
 * Only meta_title/meta_desc/focus_keyword/score/grade are handled here; the
 * post title and serialized block content come straight from core/editor.
 */
final class SeoScoreEditor {

	/** Focus keyword driving the keyword-dependent checks. */
	public const META_FOCUS_KEYWORD = '_uxstudio_ai_seo_focus_keyword';

	/** Last computed score/grade - written by the panel after each analyze() so it survives a normal post save. */
	public const META_SCORE = '_uxstudio_ai_seo_score';
	public const META_GRADE = '_uxstudio_ai_seo_grade';

	public function register(): void {
		$this->register_meta();
		add_action( 'init', array( $this, 'register_meta' ) );
		add_action( 'enqueue_block_editor_assets', array( $this, 'enqueue_assets' ) );
	}

	/**
	 * REST-expose the fields this panel edits. SeoManager::META_TITLE/
	 * META_DESCRIPTION already exist as plain post meta (written server-side
	 * by the AI content generator) - registering them here only adds REST
	 * read/write, it doesn't change how ContentGenerator writes them.
	 */
	public function register_meta(): void {
		$post_types = $this->get_post_types();
		if ( empty( $post_types ) ) {
			return;
		}

		$editable_by_post_author = static function ( $allowed, $meta_key, $post_id ) {
			return current_user_can( 'edit_post', $post_id );
		};

		foreach ( $post_types as $post_type ) {
			register_post_meta(
				$post_type,
				self::META_FOCUS_KEYWORD,
				array(
					'show_in_rest'      => true,
					'single'            => true,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
					'auth_callback'     => $editable_by_post_author,
				)
			);
			register_post_meta(
				$post_type,
				SeoManager::META_TITLE,
				array(
					'show_in_rest'      => true,
					'single'            => true,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
					'auth_callback'     => $editable_by_post_author,
				)
			);
			register_post_meta(
				$post_type,
				SeoManager::META_DESCRIPTION,
				array(
					'show_in_rest'      => true,
					'single'            => true,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_textarea_field',
					'auth_callback'     => $editable_by_post_author,
				)
			);
			register_post_meta(
				$post_type,
				self::META_SCORE,
				array(
					'show_in_rest'      => true,
					'single'            => true,
					'type'              => 'integer',
					'sanitize_callback' => 'absint',
					'auth_callback'     => $editable_by_post_author,
				)
			);
			register_post_meta(
				$post_type,
				self::META_GRADE,
				array(
					'show_in_rest'      => true,
					'single'            => true,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_key',
					'auth_callback'     => $editable_by_post_author,
				)
			);
		}
	}

	public function enqueue_assets(): void {
		$current_post_type = get_post_type();
		if ( ! in_array( (string) $current_post_type, $this->get_post_types(), true ) ) {
			return;
		}

		$version = defined( 'UXSTUDIO_VERSION' ) ? UXSTUDIO_VERSION : false;

		wp_enqueue_style(
			'uxstudio-seo-score-panel',
			plugins_url( 'assets/css/seo-score-panel.css', __FILE__ ),
			array(),
			$version
		);

		wp_enqueue_script(
			'uxstudio-seo-score-panel',
			plugins_url( 'assets/js/seo-score-panel.js', __FILE__ ),
			array( 'wp-plugins', 'wp-editor', 'wp-edit-post', 'wp-element', 'wp-components', 'wp-data', 'wp-dom-ready', 'wp-i18n' ),
			$version,
			true
		);

		wp_localize_script(
			'uxstudio-seo-score-panel',
			'uxStudioSeoScore',
			array(
				'restUrl'         => esc_url_raw( rest_url( 'uxstudio/v1/ai-assistant/seo/score' ) ),
				'restUrlTopics'   => esc_url_raw( rest_url( 'uxstudio/v1/ai-assistant/seo/topic-research' ) ),
				'nonce'   => wp_create_nonce( 'wp_rest' ),
				'i18n'    => array(
					'panelTitle'    => __( 'SEO skóre', 'ux-studio' ),
					'focusKeyword'  => __( 'Klíčové slovo', 'ux-studio' ),
					'metaTitle'     => __( 'SEO titulek', 'ux-studio' ),
					'metaDesc'      => __( 'SEO popis', 'ux-studio' ),
					'analyzing'     => __( 'Analyzuji…', 'ux-studio' ),
					'notConfigured' => __( 'Centrální aplikace není propojená (Content Sync → node_api_key / central_app_url).', 'ux-studio' ),
					'error'         => __( 'Analýza selhala.', 'ux-studio' ),
					'topicResearch' => __( 'Návrh klíčových slov', 'ux-studio' ),
					'researching'   => __( 'Hledám…', 'ux-studio' ),
					'relatedKeywords' => __( 'Související klíčová slova', 'ux-studio' ),
					'subtopics'     => __( 'Podtémata k pokrytí', 'ux-studio' ),
					'topicResearchHint' => __( 'AI odhad z kontextu, ne reálná data o vyhledávanosti.', 'ux-studio' ),
				),
			)
		);
	}

	/**
	 * Public post types, minus attachments - same universe SeoManager already
	 * writes AI SEO meta for.
	 *
	 * @return string[]
	 */
	private function get_post_types(): array {
		$post_types = get_post_types( array( 'public' => true, 'show_in_rest' => true ), 'names' );
		unset( $post_types['attachment'] );
		return array_values( $post_types );
	}
}
