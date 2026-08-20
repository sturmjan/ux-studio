<?php
/**
 * Indexes WooCommerce products (+ variations, ACF fields) into
 * uxstudio_ai_assistant_product_index for chatbot search.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\AiAssistant;

defined( 'ABSPATH' ) || exit;

/**
 * Ported from the legacy ai-assistant module's ProductIndexer. Every public
 * method degrades gracefully (empty results / no-ops) when WooCommerce is
 * not active, so this class is always safe to call regardless of which
 * plugins are installed - see is_woocommerce_active().
 */
final class ProductIndexer {

	private const QUEUE_OPTION = 'uxstudio_ai_assistant_product_queue';
	private const TOTAL_OPTION = 'uxstudio_ai_assistant_product_total';
	private const BATCH_SIZE   = 20;

	/**
	 * Whether WooCommerce is active. Every other method on this class is
	 * still safe to call when it is not (they just no-op / return empty).
	 */
	public static function is_woocommerce_active(): bool {
		return class_exists( '\\WooCommerce' );
	}

	/**
	 * FULLTEXT search with category/attribute/LIKE fallbacks, in-stock only.
	 * Called by the chat engine - the return shape (associative arrays keyed
	 * like the table columns, plus a numeric 'relevance' on FULLTEXT hits) is
	 * part of the cross-module contract and must not change without updating
	 * the caller.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function search( string $query, int $limit ): array {
		global $wpdb;

		$query = trim( $query );
		if ( '' === $query || ! self::is_woocommerce_active() ) {
			return array();
		}

		$table = "{$wpdb->prefix}uxstudio_ai_assistant_product_index";
		$limit = max( 1, $limit );
		$stems = self::extract_stems( self::significant_words( $query ) );

		$seen    = array();
		$results = array();
		$half    = max( 5, (int) ceil( $limit / 2 ) );

		$ft_results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT *, MATCH(name, description_text) AGAINST(%s IN NATURAL LANGUAGE MODE) AS relevance
				 FROM {$table}
				 WHERE MATCH(name, description_text) AGAINST(%s IN NATURAL LANGUAGE MODE) AND stock_status = 'instock'
				 ORDER BY relevance DESC, price ASC
				 LIMIT %d",
				$query,
				$query,
				$half
			),
			ARRAY_A
		);
		self::merge_rows( $ft_results, $results, $seen );

		if ( ! empty( $stems ) ) {
			$ascii_stems = self::to_ascii_stems( $stems );
			if ( ! empty( $ascii_stems ) ) {
				list( $where, $params ) = self::like_where( $ascii_stems, array( 'categories' ), 'AND' );
				$params[] = $limit;
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $where built from %s placeholders only.
				$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE ({$where}) AND stock_status = 'instock' ORDER BY price ASC LIMIT %d", ...$params ), ARRAY_A );

				if ( empty( $rows ) && count( $ascii_stems ) > 1 ) {
					list( $where, $params ) = self::like_where( $ascii_stems, array( 'categories' ), 'OR' );
					$params[] = $limit;
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE ({$where}) AND stock_status = 'instock' ORDER BY price ASC LIMIT %d", ...$params ), ARRAY_A );
				}
				self::merge_rows( $rows, $results, $seen );
			}
		}

		if ( count( $results ) < $limit && ! empty( $stems ) ) {
			list( $where, $params ) = self::like_where( $stems, array( 'attributes' ), 'OR' );
			$params[] = $limit;
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE ({$where}) AND stock_status = 'instock' ORDER BY price ASC LIMIT %d", ...$params ), ARRAY_A );
			self::merge_rows( $rows, $results, $seen );
		}

		if ( count( $results ) < $limit && ! empty( $stems ) ) {
			list( $where, $params ) = self::like_where( $stems, array( 'name', 'description_text', 'attributes' ), 'OR' );
			$params[] = $limit;
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE ({$where}) AND stock_status = 'instock' ORDER BY price ASC LIMIT %d", ...$params ), ARRAY_A );
			self::merge_rows( $rows, $results, $seen );
		}

		return array_slice( $results, 0, $limit );
	}

	/**
	 * One product's index row by WooCommerce product id, or null if not
	 * indexed / WooCommerce inactive. Called by the chat engine.
	 */
	public static function get_by_product_id( int $id ): ?array {
		global $wpdb;

		if ( ! self::is_woocommerce_active() ) {
			return null;
		}

		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$wpdb->prefix}uxstudio_ai_assistant_product_index WHERE product_id = %d", $id ),
			ARRAY_A
		);

		return is_array( $row ) ? $row : null;
	}

	/**
	 * Index a single product (and its variations, if variable); removes it
	 * from the index if unpublished.
	 */
	public function index_product( int $product_id ): void {
		if ( ! self::is_woocommerce_active() || ! function_exists( 'wc_get_product' ) ) {
			return;
		}

		$product = wc_get_product( $product_id );
		if ( ! $product || 'publish' !== $product->get_status() ) {
			$this->remove_product( $product_id );
			return;
		}

		$this->index_single_product( $product );

		if ( 'variable' === $product->get_type() ) {
			foreach ( $product->get_children() as $child_id ) {
				$variation = wc_get_product( $child_id );
				if ( $variation ) {
					$this->index_single_product( $variation );
				}
			}
		}
	}

	/**
	 * Remove a product (and its variations) from the index.
	 */
	public function remove_product( int $product_id ): void {
		global $wpdb;
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->prefix}uxstudio_ai_assistant_product_index WHERE product_id = %d OR parent_id = %d",
				$product_id,
				$product_id
			)
		);
	}

	/**
	 * Build the reindex queue and schedule the first batch. No-ops (empty
	 * queue) when WooCommerce is inactive.
	 *
	 * @return array{queued:int,total:int}
	 */
	public function queue_reindex(): array {
		if ( ! self::is_woocommerce_active() || ! function_exists( 'wc_get_products' ) ) {
			update_option( self::QUEUE_OPTION, array(), false );
			update_option( self::TOTAL_OPTION, 0, false );
			return array( 'queued' => 0, 'total' => 0 );
		}

		$products = wc_get_products( array( 'status' => 'publish', 'limit' => -1, 'return' => 'ids', 'type' => array( 'simple', 'variable' ) ) );
		$ids      = array_map( 'intval', (array) $products );

		update_option( self::QUEUE_OPTION, $ids, false );
		update_option( self::TOTAL_OPTION, count( $ids ), false );

		if ( ! empty( $ids ) && ! wp_next_scheduled( KnowledgeBootstrap::CRON_PRODUCTS ) ) {
			wp_schedule_single_event( time() + 5, KnowledgeBootstrap::CRON_PRODUCTS );
		}

		return array( 'queued' => count( $ids ), 'total' => count( $ids ) );
	}

	/**
	 * Process one batch of the reindex queue (also expands variable products
	 * into their variations); reschedules itself while items remain.
	 */
	public function process_batch(): void {
		$queue = (array) get_option( self::QUEUE_OPTION, array() );
		if ( empty( $queue ) ) {
			return;
		}

		$batch = array_splice( $queue, 0, self::BATCH_SIZE );
		foreach ( $batch as $product_id ) {
			$this->index_product( (int) $product_id );
		}

		update_option( self::QUEUE_OPTION, $queue, false );

		if ( ! empty( $queue ) ) {
			wp_schedule_single_event( time() + 5, KnowledgeBootstrap::CRON_PRODUCTS );
		}
	}

	/**
	 * Current reindex progress.
	 *
	 * @return array{queued:int,total:int,done:int,running:bool}
	 */
	public function queue_status(): array {
		$queue  = (array) get_option( self::QUEUE_OPTION, array() );
		$total  = (int) get_option( self::TOTAL_OPTION, 0 );
		$queued = count( $queue );

		return array(
			'queued'  => $queued,
			'total'   => $total,
			'done'    => max( 0, $total - $queued ),
			'running' => $queued > 0,
		);
	}

	/**
	 * Index stats for the admin UI.
	 *
	 * @return array{total:int,simple:int,variable:int,variation:int,last_indexed:?string}
	 */
	public function get_index_stats(): array {
		global $wpdb;
		$table = "{$wpdb->prefix}uxstudio_ai_assistant_product_index";

		$count_by_type = static function ( string $type ) use ( $wpdb, $table ): int {
			return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE product_type = %s", $type ) );
		};

		return array(
			'total'        => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ),
			'simple'       => $count_by_type( 'simple' ),
			'variable'     => $count_by_type( 'variable' ),
			'variation'    => $count_by_type( 'variation' ),
			'last_indexed' => $wpdb->get_var( "SELECT MAX(indexed_at) FROM {$table}" ) ?: null,
		);
	}

	/**
	 * Index one WC_Product/WC_Product_Variation, replacing any existing row
	 * for the same product id.
	 *
	 * @param \WC_Product $product Product or variation.
	 */
	private function index_single_product( $product ): void {
		global $wpdb;

		$product_id = $product->get_id();
		$parent_id  = $product->get_parent_id();

		$cat_product_id = ( $product instanceof \WC_Product_Variation ) ? $parent_id : $product_id;
		$cat_terms      = wp_get_post_terms( $cat_product_id, 'product_cat' );
		$categories     = array();
		$category_descriptions = array();

		if ( ! is_wp_error( $cat_terms ) ) {
			$categories = wp_list_pluck( $cat_terms, 'name' );
			foreach ( $cat_terms as $term ) {
				if ( ! empty( $term->description ) ) {
					$category_descriptions[] = wp_strip_all_tags( $term->description );
				}
			}
		}

		$attrs = array();
		foreach ( $product->get_attributes() as $attr ) {
			if ( ! ( $attr instanceof \WC_Product_Attribute ) ) {
				continue;
			}
			if ( $attr->is_taxonomy() ) {
				$label  = wc_attribute_label( $attr->get_name() );
				$terms  = $attr->get_terms();
				$values = ! empty( $terms ) ? wp_list_pluck( $terms, 'name' ) : array();
			} else {
				$label  = $attr->get_name();
				$values = $attr->get_options();
			}
			$attrs[ sanitize_title( $label ) ] = implode( ', ', $values );
		}

		if ( $product instanceof \WC_Product_Variation ) {
			foreach ( $product->get_variation_attributes() as $key => $val ) {
				$label = wc_attribute_label( str_replace( 'attribute_', '', $key ) );
				$attrs[ sanitize_title( $label ) ] = $val;
			}
		}

		if ( ! empty( $category_descriptions ) ) {
			$attrs['category-description'] = implode( '; ', $category_descriptions );
		}

		$acf_fields = function_exists( 'get_field_objects' ) ? self::get_acf_fields( $product_id ) : array();
		if ( $product instanceof \WC_Product_Variation && $parent_id ) {
			foreach ( self::get_acf_fields( $parent_id ) as $key => $val ) {
				$acf_fields[ $key ] ??= $val;
			}
		}
		foreach ( $acf_fields as $key => $val ) {
			$attrs[ $key ] = $val;
		}

		$description_text = wp_strip_all_tags( $product->get_description() . ' ' . $product->get_short_description() );
		if ( ! empty( $acf_fields ) ) {
			$description_text .= ' ' . implode( ' ', array_values( $acf_fields ) );
		}

		$wpdb->replace(
			"{$wpdb->prefix}uxstudio_ai_assistant_product_index",
			array(
				'product_id'       => $product_id,
				'parent_id'        => $parent_id ?: 0,
				'product_type'     => $product->get_type(),
				'name'             => $product->get_name(),
				'sku'              => $product->get_sku(),
				'price'            => $product->get_price(),
				'categories'       => wp_json_encode( array_values( $categories ) ),
				'attributes'       => wp_json_encode( $attrs ),
				'description_text' => $description_text,
				'image_url'        => wp_get_attachment_url( $product->get_image_id() ) ?: '',
				'permalink'        => (string) $product->get_permalink(),
				'stock_status'     => $product->get_stock_status(),
				'indexed_at'       => current_time( 'mysql' ),
			),
			array( '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);
	}

	/**
	 * ACF field values for a post, as sanitized key => text-value pairs.
	 *
	 * @return array<string, string>
	 */
	private static function get_acf_fields( int $post_id ): array {
		$result        = array();
		$field_objects = get_field_objects( $post_id );

		if ( empty( $field_objects ) || ! is_array( $field_objects ) ) {
			return $result;
		}

		$skip_types = array( 'tab', 'accordion', 'message', 'group', 'flexible_content', 'clone', 'image', 'gallery', 'file', 'google_map', 'post_object', 'relationship', 'page_link', 'oembed', 'user', 'color_picker', 'link' );

		foreach ( $field_objects as $field ) {
			$label = $field['label'] ?? $field['name'] ?? '';
			$value = $field['value'] ?? '';
			$type  = $field['type'] ?? '';

			if ( '' === $value || null === $value || false === $value || in_array( $type, $skip_types, true ) ) {
				continue;
			}

			$text_value = self::acf_value_to_text( $value );
			if ( '' !== $text_value ) {
				$result[ sanitize_title( $label ?: $field['name'] ) ] = $text_value;
			}
		}

		return $result;
	}

	/**
	 * Convert an ACF field value of any shape to a plain-text representation.
	 *
	 * @param mixed $value Field value.
	 */
	private static function acf_value_to_text( $value ): string {
		if ( is_string( $value ) ) {
			$clean = wp_strip_all_tags( $value );
			return self::is_useful_value( $clean ) ? $clean : '';
		}
		if ( is_numeric( $value ) ) {
			return (string) $value;
		}
		if ( is_bool( $value ) ) {
			return $value ? 'yes' : 'no';
		}
		if ( is_array( $value ) ) {
			if ( isset( $value[0] ) && is_string( $value[0] ) ) {
				return implode( ', ', $value );
			}
			if ( isset( $value['title'] ) ) {
				return wp_strip_all_tags( (string) $value['title'] );
			}
			if ( isset( $value['label'] ) ) {
				return wp_strip_all_tags( (string) $value['label'] );
			}
			$parts = array();
			foreach ( $value as $item ) {
				if ( is_string( $item ) ) {
					$parts[] = wp_strip_all_tags( $item );
				} elseif ( is_array( $item ) ) {
					foreach ( $item as $sub ) {
						if ( is_string( $sub ) ) {
							$parts[] = wp_strip_all_tags( $sub );
						}
					}
				}
			}
			return implode( ', ', array_filter( $parts ) );
		}
		return '';
	}

	/**
	 * Filters out empty/junk-looking values before they get indexed.
	 */
	private static function is_useful_value( string $value ): bool {
		$trimmed = trim( $value );
		if ( '' === $trimmed || '0' === $trimmed ) {
			return false;
		}
		return ! in_array( mb_strtolower( $trimmed ), array( 'null', 'n/a', '-', '--', '---', 'none' ), true );
	}

	/**
	 * Words of at least 3 characters from a query string.
	 *
	 * @return array<int, string>
	 */
	private static function significant_words( string $query ): array {
		$words = preg_split( '/\s+/', $query );
		return array_values( array_filter( array_map( 'trim', (array) $words ), static fn( string $w ): bool => mb_strlen( $w ) >= 3 ) );
	}

	/**
	 * Rough Czech-declension-aware stemming so LIKE fallbacks still match
	 * declined search terms.
	 *
	 * @param array<int, string> $words Significant words.
	 * @return array<int, string>
	 */
	private static function extract_stems( array $words ): array {
		$suffixes = array( 'ších', 'ními', 'ích', 'ých', 'ách', 'ech', 'ími', 'ové', 'ého', 'ému', 'ými', 'ním', 'ům', 'em', 'ém', 'ým', 'ím', 'ám', 'mi', 'ou', 'ky', 'ny', 'ty', 'ry', 'ly', 'ů', 'í', 'é', 'ý', 'á', 'ě', 'y', 'i', 'u' );

		$stems = array();
		foreach ( $words as $word ) {
			$len = mb_strlen( $word );
			if ( $len < 3 ) {
				continue;
			}
			$stem = $word;
			foreach ( $suffixes as $suffix ) {
				$suff_len = mb_strlen( $suffix );
				if ( $len <= $suff_len ) {
					continue;
				}
				if ( mb_substr( $word, -$suff_len ) === $suffix ) {
					$candidate = mb_substr( $word, 0, $len - $suff_len );
					if ( mb_strlen( $candidate ) >= 3 ) {
						$stem = $candidate;
						break;
					}
				}
			}
			$stems[] = $stem;
		}

		return array_values( array_unique( $stems ) );
	}

	/**
	 * Convert stems to ASCII (diacritics stripped) for matching inside
	 * Unicode-escaped JSON columns (e.g. `categories`).
	 *
	 * @param array<int, string> $stems Stems.
	 * @return array<int, string>
	 */
	private static function to_ascii_stems( array $stems ): array {
		$map = array(
			'á' => 'a', 'č' => 'c', 'ď' => 'd', 'é' => 'e', 'ě' => 'e', 'í' => 'i', 'ň' => 'n', 'ó' => 'o',
			'ř' => 'r', 'š' => 's', 'ť' => 't', 'ú' => 'u', 'ů' => 'u', 'ý' => 'y', 'ž' => 'z',
		);

		$result = array();
		foreach ( $stems as $stem ) {
			$ascii = strtr( mb_strtolower( $stem ), $map );
			if ( strlen( $ascii ) >= 3 ) {
				$result[] = $ascii;
			}
		}
		return array_values( array_unique( $result ) );
	}

	/**
	 * Build a WHERE fragment (with bound params) matching any/all stems
	 * against any of the given columns.
	 *
	 * @param array<int, string> $stems      Search stems.
	 * @param array<int, string> $columns    Column names (trusted, never user input).
	 * @param string              $combinator 'AND' (all stems must match) or 'OR' (any stem matches).
	 * @return array{0: string, 1: array<int, string>}
	 */
	private static function like_where( array $stems, array $columns, string $combinator ): array {
		global $wpdb;

		$conditions = array();
		$params     = array();
		foreach ( $stems as $stem ) {
			$escaped = $wpdb->esc_like( $stem );
			$conditions[] = '(' . implode(
				' OR ',
				array_map( static fn( string $col ): string => "{$col} LIKE %s", $columns )
			) . ')';
			foreach ( $columns as $ignored ) {
				$params[] = '%' . $escaped . '%';
			}
		}

		return array( implode( " {$combinator} ", $conditions ), $params );
	}

	/**
	 * Merge DB rows into the accumulator, deduplicated by product_id.
	 *
	 * @param array<int, array<string, mixed>>|null $rows    Rows to merge in.
	 * @param array<int, array<string, mixed>>       $results Accumulator (by reference).
	 * @param array<int, bool>                        $seen    Seen product ids (by reference).
	 */
	private static function merge_rows( ?array $rows, array &$results, array &$seen ): void {
		foreach ( (array) $rows as $row ) {
			$id = (int) $row['product_id'];
			if ( isset( $seen[ $id ] ) ) {
				continue;
			}
			$seen[ $id ] = true;
			$results[]   = $row;
		}
	}
}
