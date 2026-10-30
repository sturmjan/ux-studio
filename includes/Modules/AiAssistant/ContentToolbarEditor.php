<?php
/**
 * AI akce v toolbaru bloku nad označeným textem (RankMath Content AI parita,
 * PLAN.md §17.2, F2) — "Advanced Toolbar Options": Rozvinout / Přepsat /
 * Shrnout / Opravit gramatiku.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\AiAssistant;

defined( 'ABSPATH' ) || exit;

/**
 * Enqueue vrstva pro `assets/js/ai-toolbar.js`. Stejný vzor jako
 * SeoScoreEditor: vanilla JS bez wp-scripts buildu, konfigurace přes
 * wp_localize_script. Nástroje samotné žijí v PromptLibrary a volají se přes
 * jediný endpoint `POST /ai-assistant/content/tool`.
 */
final class ContentToolbarEditor {

	/**
	 * Toolbar actions: the keys are keys of the PromptLibrary (labels: see labels()). All four take
	 * `source_content` and return `{text}`, so one generic handler in JS serves them.
	 */
	private const ACTIONS = array(
		'sentence_expander',
		'paragraph_rewriter',
		'text_summarizer',
		'fix_grammar',
	);

	/**
	 * Button labels of the actions.
	 *
	 * @return array<string, string>
	 */
	private function labels(): array {
		return array(
			'sentence_expander'  => __( 'Expand', 'ux-studio' ),
			'paragraph_rewriter' => __( 'Rewrite better', 'ux-studio' ),
			'text_summarizer'    => __( 'Summarize', 'ux-studio' ),
			'fix_grammar'        => __( 'Fix grammar', 'ux-studio' ),
		);
	}

	public function register(): void {
		add_action( 'enqueue_block_editor_assets', array( $this, 'enqueue_assets' ) );
	}

	public function enqueue_assets(): void {
		if ( ! current_user_can( 'edit_posts' ) ) {
			return;
		}

		$version = defined( 'UXSTUDIO_VERSION' ) ? UXSTUDIO_VERSION : false;

		wp_enqueue_script(
			'uxstudio-ai-toolbar',
			plugins_url( 'assets/js/ai-toolbar.js', __FILE__ ),
			array( 'wp-rich-text', 'wp-block-editor', 'wp-components', 'wp-element', 'wp-data', 'wp-dom-ready' ),
			$version,
			true
		);

		wp_localize_script(
			'uxstudio-ai-toolbar',
			'uxStudioAiToolbar',
			array(
				'restUrl' => esc_url_raw( rest_url( 'uxstudio/v1/ai-assistant/content/tool' ) ),
				'nonce'   => wp_create_nonce( 'wp_rest' ),
				'actions' => $this->actions(),
				'i18n'    => array(
					'menuLabel'    => __( 'AI tools', 'ux-studio' ),
					'working'      => __( 'Working…', 'ux-studio' ),
					'noSelection'  => __( 'Select the text the AI should work with first.', 'ux-studio' ),
					'error'        => __( 'The AI tool failed:', 'ux-studio' ),
					'emptyResult'  => __( 'The AI returned no text.', 'ux-studio' ),
				),
			)
		);
	}

	/**
	 * @return array<int, array{tool:string, label:string}>
	 */
	private function actions(): array {
		$out    = array();
		$labels = $this->labels();
		foreach ( self::ACTIONS as $tool ) {
			$label = $labels[ $tool ] ?? $tool;
			// The PromptLibrary is only asked whether the tool exists: if its key is ever renamed,
			// the button disappears instead of silently returning "Unknown content tool".
			if ( null === PromptLibrary::get( $tool ) ) {
				continue;
			}
			$out[] = array(
				'tool'  => $tool,
				'label' => $label,
			);
		}
		return $out;
	}
}
