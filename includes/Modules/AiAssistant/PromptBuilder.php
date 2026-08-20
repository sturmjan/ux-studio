<?php
/**
 * Builds the RAG context (knowledge/FAQ/content/products) and the resulting
 * system prompt for the public/internal chat.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\AiAssistant;

use UxStudio\Core\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Search results are sourced from KnowledgeManager/FaqManager/ContentIndexer/
 * ProductIndexer (owned by a different wave of this module, see the class
 * docblocks for their exact contract) and merged into a system prompt via
 * PromptTemplates. RAG (vector) results are intentionally out of scope here -
 * KnowledgeManager::search()/etc. are expected to already blend keyword and
 * vector search internally.
 */
final class PromptBuilder {

	private static ?Settings $settings = null;

	private static function settings(): Settings {
		if ( null === self::$settings ) {
			self::$settings = new Settings( 'uxstudio_ai_assistant' );
		}
		return self::$settings;
	}

	/**
	 * @param array<int, array{role:string,content:string}> $conversation_history Prior messages (for product query expansion).
	 * @return array<string, mixed>
	 */
	public static function build_context( string $message, string $page_url, array $conversation_history, int $product_id, string $chat_target ): array {
		$language = (string) self::settings()->get( 'language', 'cs' );
		if ( ! in_array( $language, array( 'cs', 'en' ), true ) ) {
			$language = 'cs';
		}

		$context = array(
			'language'            => $language,
			'page_url'            => $page_url,
			'current_product_id'  => $product_id,
			'knowledge_chunks'    => array(),
			'faq_matches'         => array(),
			'content'             => array(),
			'products'            => array(),
		);

		if ( class_exists( KnowledgeManager::class ) ) {
			$context['knowledge_chunks'] = KnowledgeManager::search( $message, 5, $chat_target );
		}

		if ( class_exists( FaqManager::class ) ) {
			$context['faq_matches'] = FaqManager::search( $message, 3, $chat_target );
		}

		if ( class_exists( ContentIndexer::class ) ) {
			$context['content'] = ContentIndexer::search( $message, 5 );
		}

		if ( class_exists( ProductIndexer::class ) && ProductIndexer::is_woocommerce_active() ) {
			$search_query   = self::build_product_search_query( $message, $conversation_history );
			$search_results = ProductIndexer::search( $search_query, 15 );

			if ( $product_id > 0 ) {
				$current_product = ProductIndexer::get_by_product_id( $product_id );
				if ( null !== $current_product ) {
					$search_results = array_values(
						array_filter(
							$search_results,
							static fn ( $p ) => (int) ( is_array( $p ) ? $p['product_id'] : $p->product_id ) !== $product_id
						)
					);
					array_unshift( $search_results, $current_product );
				}
			}

			$context['products'] = $search_results;
		}

		return $context;
	}

	/**
	 * @param array<string, mixed> $context Context built by build_context().
	 */
	public static function build_system_prompt( array $context ): string {
		$language = $context['language'] ?? 'cs';
		$prompt   = PromptTemplates::intro( $language, get_bloginfo( 'name' ) );

		if ( ! empty( $context['page_url'] ) ) {
			$prompt .= 'cs' === $language
				? "\nNávštěvník se aktuálně nachází na stránce: {$context['page_url']}\n\n"
				: "\nThe visitor is currently on this page: {$context['page_url']}\n\n";
		}

		if ( ! empty( $context['knowledge_chunks'] ) ) {
			$prompt .= PromptTemplates::knowledge_heading( $language );
			foreach ( $context['knowledge_chunks'] as $chunk ) {
				$title   = self::field( $chunk, 'title' );
				$content = self::field( $chunk, 'content' );
				if ( '' !== $title ) {
					$prompt .= "--- {$title} ---\n";
				}
				$prompt .= "{$content}\n\n";
			}
		}

		if ( ! empty( $context['faq_matches'] ) ) {
			$prompt .= PromptTemplates::faq_heading( $language );
			foreach ( $context['faq_matches'] as $faq ) {
				$question = self::field( $faq, 'question' );
				$answer   = wp_strip_all_tags( self::field( $faq, 'answer' ) );
				$prompt  .= ( 'cs' === $language ? 'Otázka' : 'Question' ) . ": {$question}\n";
				$prompt  .= ( 'cs' === $language ? 'Odpověď' : 'Answer' ) . ": {$answer}\n\n";
			}
		}

		if ( ! empty( $context['content'] ) ) {
			$prompt .= PromptTemplates::content_heading( $language );
			foreach ( $context['content'] as $item ) {
				$prompt .= ( 'cs' === $language ? 'Stránka' : 'Page' ) . ': ' . self::field( $item, 'post_title' ) . "\n";
				$desc    = mb_substr( self::field( $item, 'content_text' ), 0, 500 );
				if ( '' !== $desc ) {
					$prompt .= "{$desc}\n";
				}
				$prompt .= "\n";
			}
		}

		if ( ! empty( $context['products'] ) ) {
			$current_product_id = (int) ( $context['current_product_id'] ?? 0 );
			$prompt             .= PromptTemplates::products_heading( $language );

			foreach ( $context['products'] as $product ) {
				$product_id = (int) self::field( $product, 'product_id' );
				$is_current = $current_product_id > 0 && $product_id === $current_product_id;
				if ( $is_current ) {
					$prompt .= 'cs' === $language
						? ">> AKTUÁLNÍ PRODUKT (návštěvník je právě na stránce tohoto produktu) <<\n"
						: ">> CURRENT PRODUCT (the visitor is currently on this product's page) <<\n";
				}

				$name         = self::field( $product, 'name' );
				$price        = self::field( $product, 'price' );
				$stock_status = self::field( $product, 'stock_status' );
				$categories   = json_decode( (string) self::field( $product, 'categories' ), true ) ?: array();

				$prompt .= "Produkt #{$product_id}: {$name}";
				if ( '' !== $price ) {
					$prompt .= " - {$price}";
				}
				$prompt .= ' | ' . ( 'cs' === $language ? 'Skladem' : 'In stock' ) . ': ' . ( 'instock' === $stock_status ? ( 'cs' === $language ? 'ano' : 'yes' ) : ( 'cs' === $language ? 'ne' : 'no' ) );
				if ( ! empty( $categories ) ) {
					$prompt .= ' | ' . ( 'cs' === $language ? 'Kategorie' : 'Categories' ) . ': ' . implode( ', ', $categories );
				}
				$prompt .= "\n\n";
			}
		}

		$prompt .= PromptTemplates::rules( $language );

		$custom_prompt = (string) self::settings()->get( 'custom_system_prompt', '' );
		if ( '' !== $custom_prompt ) {
			$prompt .= "\n" . ( 'cs' === $language ? '=== VLASTNÍ INSTRUKCE ===' : '=== CUSTOM INSTRUCTIONS ===' ) . "\n{$custom_prompt}\n";
		}

		return $prompt;
	}

	/**
	 * Builds the product search query from the current message, expanded with
	 * recent user messages when the current one is too short to be specific.
	 *
	 * @param array<int, array{role:string,content:string}> $history Conversation history.
	 */
	private static function build_product_search_query( string $current_message, array $history ): string {
		if ( mb_strlen( $current_message ) > 40 ) {
			return $current_message;
		}

		$user_messages = array();
		foreach ( array_reverse( $history ) as $message ) {
			if ( 'user' === ( $message['role'] ?? '' ) ) {
				$user_messages[] = (string) ( $message['content'] ?? '' );
				if ( count( $user_messages ) >= 3 ) {
					break;
				}
			}
		}

		if ( empty( $user_messages ) ) {
			return $current_message;
		}

		return trim( $current_message . ' ' . implode( ' ', $user_messages ) );
	}

	/**
	 * Reads a field off a search result row regardless of whether it is an
	 * object (typical wpdb row) or an array.
	 *
	 * @param object|array<string, mixed> $row Row.
	 */
	private static function field( $row, string $key ): string {
		if ( is_array( $row ) ) {
			return (string) ( $row[ $key ] ?? '' );
		}
		return (string) ( $row->{$key} ?? '' );
	}
}
