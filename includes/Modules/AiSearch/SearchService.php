<?php
/**
 * Search over the AI Assistant's shared content + product index.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\AiSearch;

use UxStudio\Modules\AiAssistant\ContentIndexer;
use UxStudio\Modules\AiAssistant\ProductIndexer;

defined( 'ABSPATH' ) || exit;

/**
 * Reuses the index the chat assistant already builds (ContentIndexer /
 * ProductIndexer), so there is one RAG database to maintain. Falls back to the
 * core WP_Query search when that index does not exist (AI Assistant never
 * enabled) so the module still works on its own.
 *
 * Every item is re-checked against the live post (published, not password
 * protected) before it is returned: the endpoint is public and the index can
 * lag behind an unpublish.
 */
final class SearchService {

	public const MAX_QUERY = 100;
	public const MAX_ITEMS = 60;

	private static ?bool $has_index = null;

	/**
	 * Trim, strip tags and cap the length of a user-supplied query.
	 */
	public static function clean_query( string $q ): string {
		$q = trim( preg_replace( '/\s+/u', ' ', wp_strip_all_tags( $q ) ) ?? '' );
		return mb_substr( $q, 0, self::MAX_QUERY );
	}

	/**
	 * Whether the shared content index table exists.
	 */
	public static function has_index(): bool {
		if ( null === self::$has_index ) {
			global $wpdb;
			$table           = "{$wpdb->prefix}uxstudio_ai_assistant_content_index";
			self::$has_index = $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
		}
		return self::$has_index;
	}

	/**
	 * Full search, best matches first.
	 *
	 * @return array<int, array<string, mixed>> Normalised items, see normalise().
	 */
	public static function search( string $q, int $limit = self::MAX_ITEMS ): array {
		$q = self::clean_query( $q );
		if ( mb_strlen( $q ) < 2 ) {
			return array();
		}
		$limit = max( 1, min( self::MAX_ITEMS, $limit ) );

		if ( ! self::has_index() ) {
			return self::wp_query_search( $q, $limit );
		}

		$candidates = array();

		foreach ( ContentIndexer::search( $q, $limit ) as $row ) {
			$candidates[] = array(
				'id'        => (int) $row['post_id'],
				'title'     => (string) $row['post_title'],
				'text'      => (string) $row['content_text'],
				'url'       => (string) $row['permalink'],
				'image'     => (string) $row['image_url'],
				'relevance' => (float) ( $row['relevance'] ?? 0 ),
			);
		}

		$candidates = array_merge( $candidates, self::product_candidates( $q, $limit ) );

		return self::finish( $candidates, $q, $limit );
	}

	/**
	 * Fast "as you type" suggestions: title prefix/substring matches first,
	 * topped up with the full-text search.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function suggest( string $q, int $limit = 6 ): array {
		$q = self::clean_query( $q );
		if ( mb_strlen( $q ) < 2 ) {
			return array();
		}
		$limit = max( 1, min( 12, $limit ) );

		if ( ! self::has_index() ) {
			return array_slice( self::wp_query_search( $q, $limit ), 0, $limit );
		}

		global $wpdb;
		$like_any    = '%' . $wpdb->esc_like( $q ) . '%';
		$like_prefix = $wpdb->esc_like( $q ) . '%';
		$candidates  = array();

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT post_id, post_title, content_text, permalink, image_url
				 FROM {$wpdb->prefix}uxstudio_ai_assistant_content_index
				 WHERE post_title LIKE %s
				 ORDER BY (post_title LIKE %s) DESC, CHAR_LENGTH(post_title) ASC
				 LIMIT %d",
				$like_any,
				$like_prefix,
				$limit
			),
			ARRAY_A
		);
		foreach ( (array) $rows as $row ) {
			$candidates[] = array(
				'id'        => (int) $row['post_id'],
				'title'     => (string) $row['post_title'],
				'text'      => (string) $row['content_text'],
				'url'       => (string) $row['permalink'],
				'image'     => (string) $row['image_url'],
				'relevance' => 100.0,
			);
		}

		$candidates = array_merge( $candidates, self::product_candidates( $q, $limit ) );

		if ( count( $candidates ) < $limit ) {
			foreach ( ContentIndexer::search( $q, $limit ) as $row ) {
				$candidates[] = array(
					'id'        => (int) $row['post_id'],
					'title'     => (string) $row['post_title'],
					'text'      => (string) $row['content_text'],
					'url'       => (string) $row['permalink'],
					'image'     => (string) $row['image_url'],
					'relevance' => (float) ( $row['relevance'] ?? 0 ),
				);
			}
		}

		return self::finish( $candidates, $q, $limit );
	}

	/**
	 * Dedupe, rank (title hits first), verify against the live posts and
	 * normalise.
	 *
	 * @param array<int, array<string, mixed>> $candidates Raw hits.
	 * @return array<int, array<string, mixed>>
	 */
	private static function finish( array $candidates, string $q, int $limit ): array {
		$terms  = self::terms( $q );
		$seen   = array();
		$ranked = array();

		foreach ( $candidates as $pos => $c ) {
			$key = $c['id'] > 0 ? 'p' . $c['id'] : 'u' . $c['url'];
			if ( isset( $seen[ $key ] ) || '' === $c['title'] ) {
				continue;
			}
			$seen[ $key ] = true;

			$title = mb_strtolower( $c['title'] );
			$tier  = 0;
			if ( false !== mb_strpos( $title, mb_strtolower( $q ) ) ) {
				$tier = 2;
			} else {
				foreach ( $terms as $term ) {
					if ( false !== mb_strpos( $title, mb_strtolower( $term ) ) ) {
						$tier = 1;
						break;
					}
				}
			}
			$ranked[] = array( $tier, $c['relevance'], -$pos, $c );
		}

		usort(
			$ranked,
			static function ( array $a, array $b ): int {
				return array( $b[0], $b[1], $b[2] ) <=> array( $a[0], $a[1], $a[2] );
			}
		);

		$ids = array();
		foreach ( $ranked as $r ) {
			if ( $r[3]['id'] > 0 ) {
				$ids[] = $r[3]['id'];
			}
		}
		if ( $ids ) {
			_prime_post_caches( $ids, false, false );
		}

		$items = array();
		foreach ( $ranked as $r ) {
			$c    = $r[3];
			$post = $c['id'] > 0 ? get_post( $c['id'] ) : null;
			if ( ! $post instanceof \WP_Post || 'publish' !== $post->post_status || '' !== $post->post_password ) {
				continue;
			}
			if ( 'product' === $post->post_type && ! self::product_visible( $post->ID ) ) {
				continue;
			}
			$items[] = self::normalise( $post, $c['title'], $c['text'], $c['url'], $c['image'], $q, $c['props'] ?? array() );
			if ( count( $items ) >= $limit ) {
				break;
			}
		}

		return $items;
	}

	/**
	 * Core-search fallback when the shared index does not exist.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private static function wp_query_search( string $q, int $limit ): array {
		$query = new \WP_Query(
			array(
				's'                   => $q,
				'post_status'         => 'publish',
				'post_type'           => 'any',
				'has_password'        => false,
				'posts_per_page'      => $limit,
				'ignore_sticky_posts' => true,
				'no_found_rows'       => true,
			)
		);

		$items = array();
		foreach ( $query->posts as $post ) {
			$items[] = self::normalise(
				$post,
				get_the_title( $post ),
				wp_strip_all_tags( $post->post_excerpt ?: $post->post_content ),
				(string) get_permalink( $post ),
				(string) get_the_post_thumbnail_url( $post, 'medium' ),
				$q
			);
		}
		return $items;
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function normalise( \WP_Post $post, string $title, string $text, string $url, string $image, string $q, array $props = array() ): array {
		$type_obj = get_post_type_object( $post->post_type );
		$price    = '';
		if ( 'product' === $post->post_type && function_exists( 'wc_get_product' ) ) {
			$product = wc_get_product( $post->ID );
			$price   = $product ? (string) $product->get_price_html() : '';
		}

		return array(
			'id'         => $post->ID,
			'title'      => $title,
			'url'        => '' !== $url ? $url : (string) get_permalink( $post ),
			'type'       => $post->post_type,
			'type_label' => $type_obj ? (string) $type_obj->labels->singular_name : $post->post_type,
			'image'      => $image,
			'snippet'    => self::snippet( $text, $q ),
			'price'      => $price,
			'props'      => $props,
		);
	}

	/**
	 * Weighted product columns: where a word counts most.
	 */
	private const PRODUCT_COLUMNS = array(
		'name'             => 4,
		'sku'              => 4,
		'categories'       => 3,
		'attributes'       => 3,
		'description_text' => 1,
	);

	/**
	 * Search the product index (name, SKU, categories, properties, description).
	 *
	 * Every word of the query has to match in at least one column (so "red
	 * dress" finds a product whose name has "dress" and whose colour property is
	 * "red"); when nothing satisfies all words, products matching any word are
	 * returned. Czech declension is handled with the same suffix stemming the
	 * assistant uses. Out-of-stock products are included, they only rank lower.
	 *
	 * @return array<int, array<string, mixed>> Candidates for finish().
	 */
	private static function product_candidates( string $q, int $limit ): array {
		if ( ! ProductIndexer::is_woocommerce_active() ) {
			return array();
		}

		global $wpdb;
		$table = "{$wpdb->prefix}uxstudio_ai_assistant_product_index";

		$needles = array();
		foreach ( array_slice( self::terms( $q ), 0, 6 ) as $term ) {
			$stem      = ContentIndexer::extract_stems( array( $term ) )[0] ?? $term;
			$needles[] = $stem;
		}
		if ( ! $needles ) {
			return array();
		}

		// Score: weight of each column a word hits, summed over words.
		$score_sql    = array();
		$score_params = array();
		$term_sql     = array();
		$term_params  = array();
		foreach ( $needles as $needle ) {
			$likes   = array( '%' . $wpdb->esc_like( $needle ) . '%' );
			// Attributes/categories are stored as JSON with escaped unicode (č): match that form too.
			$escaped = trim( (string) wp_json_encode( $needle ), '"' );
			if ( $escaped !== $needle ) {
				$likes[] = '%' . $wpdb->esc_like( $escaped ) . '%';
			}

			$ors = array();
			foreach ( self::PRODUCT_COLUMNS as $col => $weight ) {
				$cond = array();
				foreach ( $likes as $i => $like ) {
					if ( $i > 0 && ! in_array( $col, array( 'categories', 'attributes' ), true ) ) {
						continue;
					}
					$cond[]         = "{$col} LIKE %s";
					$term_params[]  = $like;
					$score_params[] = $like;
				}
				$ors[]       = '(' . implode( ' OR ', $cond ) . ')';
				$score_sql[] = "(CASE WHEN (" . implode( ' OR ', $cond ) . ") THEN {$weight} ELSE 0 END)";
			}
			$term_sql[] = '(' . implode( ' OR ', $ors ) . ')';
		}

		$score = implode( ' + ', $score_sql );
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- fragments are built from fixed column names and %s placeholders.
		$run = static function ( string $glue ) use ( $wpdb, $table, $score, $score_params, $term_sql, $term_params, $limit ): array {
			$sql = "SELECT *, ({$score}) AS relevance FROM {$table} WHERE " . implode( " {$glue} ", $term_sql )
				. " ORDER BY relevance DESC, (stock_status = 'instock') DESC, price ASC LIMIT %d";
			$rows = $wpdb->get_results( $wpdb->prepare( $sql, ...array_merge( $score_params, $term_params, array( $limit * 2 ) ) ), ARRAY_A );
			return is_array( $rows ) ? $rows : array();
		};
		// phpcs:enable

		$rows = $run( 'AND' );
		if ( ! $rows && count( $term_sql ) > 1 ) {
			$rows = $run( 'OR' );
		}

		$candidates = array();
		foreach ( $rows as $row ) {
			$candidates[] = array(
				'id'        => (int) ( $row['parent_id'] ?: $row['product_id'] ),
				'title'     => (string) $row['name'],
				'text'      => (string) $row['description_text'],
				'url'       => (string) $row['permalink'],
				'image'     => (string) $row['image_url'],
				'relevance' => (float) $row['relevance'],
				'props'     => self::matched_properties( (string) $row['attributes'], $needles, (string) $row['name'] ),
			);
		}
		return $candidates;
	}

	/**
	 * Product properties whose value contains one of the searched words, as
	 * "Label: value" pairs (at most 3), so the result can show why it matched.
	 *
	 * @param array<int, string> $needles Search stems.
	 * @param string             $title   Product name (a property repeating it is skipped).
	 * @return array<int, array{label:string,value:string}>
	 */
	private static function matched_properties( string $json, array $needles, string $title ): array {
		$attrs = json_decode( $json, true );
		if ( ! is_array( $attrs ) ) {
			return array();
		}

		$out = array();
		foreach ( $attrs as $key => $value ) {
			if ( ! is_string( $value ) || '' === $value || 'category-description' === $key || 0 === strcasecmp( trim( $value ), trim( $title ) ) ) {
				continue;
			}
			foreach ( $needles as $needle ) {
				if ( false !== mb_stripos( $value, $needle ) ) {
					$out[] = array(
						'label' => ucfirst( str_replace( array( '-', '_' ), ' ', (string) $key ) ),
						'value' => mb_strlen( $value ) > 60 ? mb_substr( $value, 0, 59 ) . '…' : $value,
					);
					break;
				}
			}
			if ( count( $out ) >= 3 ) {
				break;
			}
		}
		return $out;
	}

	/**
	 * Whether WooCommerce lets this product show up in searches (catalog
	 * visibility, and its own "hide out of stock" setting).
	 */
	private static function product_visible( int $id ): bool {
		if ( ! function_exists( 'wc_get_product' ) ) {
			return true;
		}
		$product = wc_get_product( $id );
		if ( ! $product ) {
			return false;
		}
		return in_array( $product->get_catalog_visibility(), array( 'visible', 'search' ), true )
			&& ( 'yes' !== get_option( 'woocommerce_hide_out_of_stock_items' ) || $product->is_in_stock() );
	}

	/**
	 * Words of the query worth highlighting / ranking on.
	 *
	 * @return array<int, string>
	 */
	public static function terms( string $q ): array {
		$words = preg_split( '/\s+/u', $q ) ?: array();
		$words = array_filter( $words, static fn( string $w ): bool => mb_strlen( $w ) >= 2 );
		return array_values( array_unique( $words ) );
	}

	/**
	 * Plain-text excerpt (about 180 chars) centred on the first term hit.
	 */
	public static function snippet( string $text, string $q, int $length = 180 ): string {
		$text = trim( preg_replace( '/\s+/u', ' ', wp_strip_all_tags( $text ) ) ?? '' );
		if ( mb_strlen( $text ) <= $length ) {
			return $text;
		}

		$start = 0;
		foreach ( self::terms( $q ) as $term ) {
			$pos = mb_stripos( $text, $term );
			if ( false !== $pos ) {
				$start = max( 0, $pos - (int) ( $length / 3 ) );
				break;
			}
		}

		$slice = mb_substr( $text, $start, $length );
		return ( $start > 0 ? '…' : '' ) . trim( $slice ) . ( $start + $length < mb_strlen( $text ) ? '…' : '' );
	}

	/**
	 * Escape $text and wrap occurrences of the query terms in <mark>.
	 */
	public static function highlight( string $text, string $q ): string {
		$terms = self::terms( $q );
		if ( ! $terms ) {
			return esc_html( $text );
		}

		usort( $terms, static fn( string $a, string $b ): int => mb_strlen( $b ) <=> mb_strlen( $a ) );
		$pattern = '/(' . implode( '|', array_map( static fn( string $t ): string => preg_quote( $t, '/' ), $terms ) ) . ')/iu';
		$parts   = preg_split( $pattern, $text, -1, PREG_SPLIT_DELIM_CAPTURE );
		if ( false === $parts ) {
			return esc_html( $text );
		}

		$out = '';
		foreach ( $parts as $i => $part ) {
			$out .= ( 1 === $i % 2 ) ? '<mark>' . esc_html( $part ) . '</mark>' : esc_html( $part );
		}
		return $out;
	}
}
