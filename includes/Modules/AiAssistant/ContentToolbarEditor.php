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
	 * Akce v toolbaru → klíč v PromptLibrary. Všechny čtyři berou
	 * `source_content` a vracejí `{text}`, takže je zvládne jeden generický
	 * handler v JS.
	 */
	private const ACTIONS = array(
		'sentence_expander'  => 'Rozvinout',
		'paragraph_rewriter' => 'Přepsat lépe',
		'text_summarizer'    => 'Shrnout',
		'fix_grammar'        => 'Opravit gramatiku',
	);

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
					'menuLabel'    => __( 'AI nástroje', 'ux-studio' ),
					'working'      => __( 'Pracuji…', 'ux-studio' ),
					'noSelection'  => __( 'Nejdřív označ text, se kterým má AI pracovat.', 'ux-studio' ),
					'error'        => __( 'AI nástroj selhal:', 'ux-studio' ),
					'emptyResult'  => __( 'AI nevrátila žádný text.', 'ux-studio' ),
				),
			)
		);
	}

	/**
	 * @return array<int, array{tool:string, label:string}>
	 */
	private function actions(): array {
		$out = array();
		foreach ( self::ACTIONS as $tool => $label ) {
			// Popisek bere z PromptLibrary jen ověření, že nástroj existuje —
			// kdyby se klíč v knihovně přejmenoval, tlačítko se nezobrazí
			// místo toho, aby tiše vracelo "Unknown content tool".
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
