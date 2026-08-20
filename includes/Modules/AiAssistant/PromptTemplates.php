<?php
/**
 * Static prompt text fragments for the public chat widget, per language.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\AiAssistant;

defined( 'ABSPATH' ) || exit;

/**
 * Only the two languages exposed in Module::settings_schema() ('cs'/'en')
 * are supported here; unknown values fall back to English.
 */
final class PromptTemplates {

	/**
	 * Persona/intro line, filled in with the site name.
	 */
	public static function intro( string $language, string $site_name ): string {
		if ( 'cs' === $language ) {
			return sprintf(
				"Jsi přátelský a nápomocný zákaznický asistent webu \"%s\".\nOdpovídáš v jazyce: čeština.\nBuď stručný, vstřícný a profesionální.\n",
				$site_name
			);
		}
		return sprintf(
			"You are a friendly and helpful customer support assistant for the website \"%s\".\nRespond in: English.\nBe concise, welcoming and professional.\n",
			$site_name
		);
	}

	public static function knowledge_heading( string $language ): string {
		return 'cs' === $language
			? "=== ZNALOSTNÍ BÁZE ===\nNásledující informace pocházejí z interní znalostní báze. Používej je pro odpovědi:\n\n"
			: "=== KNOWLEDGE BASE ===\nThe following information comes from the internal knowledge base. Use it to answer:\n\n";
	}

	public static function faq_heading( string $language ): string {
		return 'cs' === $language
			? "=== ČASTO KLADENÉ DOTAZY ===\n"
			: "=== FREQUENTLY ASKED QUESTIONS ===\n";
	}

	public static function content_heading( string $language ): string {
		return 'cs' === $language
			? "=== OBSAH WEBU ===\nRelevantní stránky a příspěvky z webu:\n\n"
			: "=== SITE CONTENT ===\nRelevant pages and posts from the site:\n\n";
	}

	public static function products_heading( string $language ): string {
		return 'cs' === $language
			? "=== PRODUKTY ===\nNalezené produkty z e-shopu. Pokud je produkt relevantní, vlož do odpovědi tag [product:ID] (nahraď ID číslem produktu).\nTag [product:ID] se v chatu zobrazí jako interaktivní karta produktu s obrázkem a cenou.\n\n"
			: "=== PRODUCTS ===\nMatching products from the shop. When a product is relevant, insert a [product:ID] tag into your reply (replace ID with the product's numeric id).\nThe [product:ID] tag renders as an interactive product card with image and price.\n\n";
	}

	public static function rag_heading( string $language ): string {
		return 'cs' === $language
			? "=== RELEVANTNÍ ZNALOSTI (RAG) ===\nNásledující informace byly nalezeny vektorovým vyhledáváním a jsou vysoce relevantní k dotazu:\n\n"
			: "=== RELEVANT KNOWLEDGE (RAG) ===\nThe following information was found via vector search and is highly relevant to the query:\n\n";
	}

	/**
	 * Behavioural rules appended at the end of the system prompt.
	 */
	public static function rules( string $language ): string {
		if ( 'cs' === $language ) {
			return "=== PRAVIDLA ===\n"
				. "- Pokud najdeš vhodný produkt, vlož tag [product:ID] do odpovědi\n"
				. "- Pokud zákazník hledá levnější/dražší variantu, porovnej ceny VŠECH produktů v seznamu výše a vyber správně\n"
				. "- Pokud přesný produkt neexistuje, nabídni alternativu a upozorni na to\n"
				. "- Pokud nevíš odpověď, řekni to upřímně a nabídni kontaktování podpory\n"
				. "- Nikdy nevymýšlej informace o produktech - používej jen data z kontextu výše\n"
				. "- Pokud jsou dostupné informace z obsahu webu (stránky/příspěvky), využij je pro přesné odpovědi\n"
				. "- Odpovídej stručně, max 2-3 odstavce\n"
				. "- Nepoužívej markdown formátování (žádné **, ##, atd.)\n";
		}

		return "=== RULES ===\n"
			. "- When you find a suitable product, insert a [product:ID] tag into your reply\n"
			. "- If the customer wants a cheaper/pricier alternative, compare the prices of ALL products listed above before choosing\n"
			. "- If no exact product exists, offer an alternative and say so explicitly\n"
			. "- If you don't know the answer, say so honestly and offer to put them in touch with support\n"
			. "- Never invent product information - only use the data given in the context above\n"
			. "- When site content (pages/posts) is available, use it for accurate answers\n"
			. "- Keep answers short, at most 2-3 paragraphs\n"
			. "- Do not use markdown formatting (no **, ##, etc.)\n";
	}

	public static function default_greeting( string $language ): string {
		return 'cs' === $language
			? __( 'Dobrý den! Jak vám mohu pomoci?', 'ux-studio' )
			: __( 'Hello! How can I help you?', 'ux-studio' );
	}

	public static function default_gdpr_text( string $language ): string {
		return 'cs' === $language
			? __( 'Tento chat využívá AI. Odesláním zprávy souhlasíte se zpracováním vašeho dotazu. Vaše konverzace může být uložena pro zlepšení kvality služeb.', 'ux-studio' )
			: __( 'This chat uses AI. By sending a message you agree to your query being processed. Your conversation may be stored to improve service quality.', 'ux-studio' );
	}

	public static function default_placeholder( string $language ): string {
		return 'cs' === $language
			? __( 'Napište svůj dotaz...', 'ux-studio' )
			: __( 'Type your question...', 'ux-studio' );
	}

	public static function default_title( string $language ): string {
		return 'cs' === $language
			? __( 'AI Podpora', 'ux-studio' )
			: __( 'AI Support', 'ux-studio' );
	}
}
