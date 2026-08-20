<?php
/**
 * Provider-agnostic tool (function-calling) definitions for the chat, used
 * when the "Enable MCP (tool calling)" setting is on - see AiProviderInterface
 * ::chat_with_tools()/::format_tool_results(). When it is off, ChatEngine
 * falls back to plain RAG context injection via PromptBuilder instead.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\AiAssistant;

defined( 'ABSPATH' ) || exit;

/**
 * Every tool wraps one of the contracted search methods on KnowledgeManager/
 * FaqManager/ContentIndexer/ProductIndexer (owned by a different wave of this
 * module). Tool results are returned as JSON strings so any provider's
 * format_tool_results() can embed them as plain tool_result content.
 */
final class ChatTools {

	/**
	 * @return array<int, array{name:string,description:string,parameters:array<string, mixed>}>
	 */
	public static function get_definitions( string $chat_target ): array {
		$tools = array(
			array(
				'name'        => 'search_knowledge',
				'description' => __( 'Search the internal knowledge base (manual entries and uploaded documents) for information relevant to the visitor\'s question.', 'ux-studio' ),
				'parameters'  => array(
					'type'       => 'object',
					'properties' => array(
						'query' => array(
							'type'        => 'string',
							'description' => __( 'Search query, in the visitor\'s own words.', 'ux-studio' ),
						),
					),
					'required'   => array( 'query' ),
				),
			),
			array(
				'name'        => 'search_faq',
				'description' => __( 'Search frequently asked questions for a matching question/answer pair.', 'ux-studio' ),
				'parameters'  => array(
					'type'       => 'object',
					'properties' => array(
						'query' => array(
							'type'        => 'string',
							'description' => __( 'Search query.', 'ux-studio' ),
						),
					),
					'required'   => array( 'query' ),
				),
			),
			array(
				'name'        => 'search_content',
				'description' => __( 'Search the site\'s pages and posts for information relevant to the visitor\'s question.', 'ux-studio' ),
				'parameters'  => array(
					'type'       => 'object',
					'properties' => array(
						'query' => array(
							'type'        => 'string',
							'description' => __( 'Search query.', 'ux-studio' ),
						),
					),
					'required'   => array( 'query' ),
				),
			),
		);

		if ( ProductIndexer::is_woocommerce_active() ) {
			$tools[] = array(
				'name'        => 'search_products',
				'description' => __( 'Search the shop\'s product catalog. Returns product id, name, price and stock status. Reference a result in your reply with a [product:ID] tag.', 'ux-studio' ),
				'parameters'  => array(
					'type'       => 'object',
					'properties' => array(
						'query' => array(
							'type'        => 'string',
							'description' => __( 'Search query describing what the visitor is looking for.', 'ux-studio' ),
						),
					),
					'required'   => array( 'query' ),
				),
			);
			$tools[] = array(
				'name'        => 'get_product_details',
				'description' => __( 'Fetch full details for one specific product by its numeric id.', 'ux-studio' ),
				'parameters'  => array(
					'type'       => 'object',
					'properties' => array(
						'product_id' => array(
							'type'        => 'integer',
							'description' => __( 'Product id.', 'ux-studio' ),
						),
					),
					'required'   => array( 'product_id' ),
				),
			);
		}

		return $tools;
	}

	/**
	 * Dispatches one tool call and returns its result as a JSON string,
	 * ready to hand to AiProviderInterface::format_tool_results().
	 *
	 * @param array<string, mixed> $arguments Arguments as decoded from the provider's tool_use payload.
	 */
	public static function execute( string $name, array $arguments, string $chat_target ): string {
		$query = sanitize_text_field( (string) ( $arguments['query'] ?? '' ) );

		switch ( $name ) {
			case 'search_knowledge':
				return self::encode( KnowledgeManager::search( $query, 5, $chat_target ) );

			case 'search_faq':
				return self::encode( FaqManager::search( $query, 5, $chat_target ) );

			case 'search_content':
				return self::encode( ContentIndexer::search( $query, 5 ) );

			case 'search_products':
				if ( ! ProductIndexer::is_woocommerce_active() ) {
					return self::encode( array() );
				}
				return self::encode( ProductIndexer::search( $query, 10 ) );

			case 'get_product_details':
				if ( ! ProductIndexer::is_woocommerce_active() ) {
					return self::encode( null );
				}
				$product_id = absint( $arguments['product_id'] ?? 0 );
				return self::encode( $product_id > 0 ? ProductIndexer::get_by_product_id( $product_id ) : null );

			default:
				return self::encode( array( 'error' => __( 'Unknown tool.', 'ux-studio' ) ) );
		}
	}

	/**
	 * Human-readable label for a tool call, for admin-facing activity display.
	 */
	public static function get_label( string $name ): string {
		$labels = array(
			'search_knowledge'    => __( 'Knowledge base search', 'ux-studio' ),
			'search_faq'          => __( 'FAQ search', 'ux-studio' ),
			'search_content'      => __( 'Site content search', 'ux-studio' ),
			'search_products'     => __( 'Product search', 'ux-studio' ),
			'get_product_details' => __( 'Product details', 'ux-studio' ),
		);
		return $labels[ $name ] ?? $name;
	}

	/**
	 * @param mixed $data Value to JSON-encode for a tool result.
	 */
	private static function encode( $data ): string {
		return (string) wp_json_encode( $data );
	}
}
