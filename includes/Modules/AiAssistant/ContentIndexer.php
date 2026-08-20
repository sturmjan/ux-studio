<?php
/**
 * Indexes published posts/pages (+ ACF fields, when present) into
 * uxstudio_ai_assistant_content_index for chatbot search.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\AiAssistant;

defined( 'ABSPATH' ) || exit;

/**
 * Ported from the legacy ai-assistant module's ContentIndexer. Full
 * reindexing runs in small batches scheduled via wp_schedule_single_event()
 * (see queue_reindex()/process_batch(), driven by KnowledgeBootstrap's cron
 * hook) rather than in one long-running request.
 */
final class ContentIndexer {

	private const QUEUE_OPTION = 'uxstudio_ai_assistant_content_queue';
	private const TOTAL_OPTION = 'uxstudio_ai_assistant_content_total';
	private const BATCH_SIZE   = 20;

	/** Post types indexed by default. */
	private const DEFAULT_POST_TYPES = array( 'post', 'page' );

	/**
	 * FULLTEXT search with LIKE fallbacks (attributes, then title/content/attributes).
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
		if ( '' === $query ) {
			return array();
		}

		$table = "{$wpdb->prefix}uxstudio_ai_assistant_content_index";
		$limit = max( 1, $limit );
		$stems = self::extract_stems( self::significant_words( $query ) );

		$seen    = array();
		$results = array();
		$half    = max( 3, (int) ceil( $limit / 2 ) );

		$ft_results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT *, MATCH(post_title, content_text) AGAINST(%s IN NATURAL LANGUAGE MODE) AS relevance
				 FROM {$table}
				 WHERE MATCH(post_title, content_text) AGAINST(%s IN NATURAL LANGUAGE MODE)
				 ORDER BY relevance DESC
				 LIMIT %d",
				$query,
				$query,
				$half
			),
			ARRAY_A
		);
		self::merge_rows( $ft_results, $results, $seen, 'post_id' );

		if ( count( $results ) < $limit && ! empty( $stems ) ) {
			list( $where, $params ) = self::like_where( $stems, array( 'attributes' ) );
			$params[]               = $limit;
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $where built from %s placeholders only.
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE ({$where}) ORDER BY indexed_at DESC LIMIT %d", ...$params ), ARRAY_A );
			self::merge_rows( $rows, $results, $seen, 'post_id' );
		}

		if ( count( $results ) < $limit && ! empty( $stems ) ) {
			list( $where, $params ) = self::like_where( $stems, array( 'post_title', 'content_text', 'attributes' ) );
			$params[]               = $limit;
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE ({$where}) ORDER BY indexed_at DESC LIMIT %d", ...$params ), ARRAY_A );
			self::merge_rows( $rows, $results, $seen, 'post_id' );
		}

		return array_slice( $results, 0, $limit );
	}

	/**
	 * Index a single published post; removes it from the index if it is no
	 * longer published or not an allowed post type.
	 */
	public function index_post( int $post_id ): void {
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}

		$post = get_post( $post_id );
		if ( ! $post ) {
			return;
		}

		if ( ! in_array( $post->post_type, $this->get_allowed_post_types(), true ) ) {
			return;
		}

		if ( 'publish' !== $post->post_status ) {
			$this->remove_post( $post_id );
			return;
		}

		$this->index_single_post( $post );
	}

	/**
	 * Remove a post from the index.
	 */
	public function remove_post( int $post_id ): void {
		global $wpdb;
		$wpdb->delete( "{$wpdb->prefix}uxstudio_ai_assistant_content_index", array( 'post_id' => $post_id ), array( '%d' ) );
	}

	/**
	 * Build the reindex queue and schedule the first batch.
	 *
	 * @return array{queued:int,total:int}
	 */
	public function queue_reindex(): array {
		$ids = get_posts(
			array(
				'post_type'      => $this->get_allowed_post_types(),
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
			)
		);
		$ids = array_map( 'intval', $ids );

		update_option( self::QUEUE_OPTION, $ids, false );
		update_option( self::TOTAL_OPTION, count( $ids ), false );

		if ( ! empty( $ids ) && ! wp_next_scheduled( KnowledgeBootstrap::CRON_CONTENT ) ) {
			wp_schedule_single_event( time() + 5, KnowledgeBootstrap::CRON_CONTENT );
		}

		return array( 'queued' => count( $ids ), 'total' => count( $ids ) );
	}

	/**
	 * Process one batch of the reindex queue; reschedules itself while items remain.
	 */
	public function process_batch(): void {
		$queue = (array) get_option( self::QUEUE_OPTION, array() );
		if ( empty( $queue ) ) {
			return;
		}

		$batch = array_splice( $queue, 0, self::BATCH_SIZE );
		foreach ( $batch as $post_id ) {
			$post = get_post( (int) $post_id );
			if ( $post ) {
				$this->index_single_post( $post );
			}
		}

		update_option( self::QUEUE_OPTION, $queue, false );

		if ( ! empty( $queue ) ) {
			wp_schedule_single_event( time() + 5, KnowledgeBootstrap::CRON_CONTENT );
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
	 * @return array{total:int,breakdown:array<string,int>,last_indexed:?string}
	 */
	public function get_index_stats(): array {
		global $wpdb;
		$table = "{$wpdb->prefix}uxstudio_ai_assistant_content_index";

		$total        = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
		$last_indexed = $wpdb->get_var( "SELECT MAX(indexed_at) FROM {$table}" );

		$breakdown = array();
		$rows      = $wpdb->get_results( "SELECT post_type, COUNT(*) AS cnt FROM {$table} GROUP BY post_type ORDER BY cnt DESC", ARRAY_A );
		foreach ( (array) $rows as $row ) {
			$breakdown[ $row['post_type'] ] = (int) $row['cnt'];
		}

		return array( 'total' => $total, 'breakdown' => $breakdown, 'last_indexed' => $last_indexed ?: null );
	}

	/**
	 * Post types eligible for indexing.
	 *
	 * @return array<int, string>
	 */
	public function get_allowed_post_types(): array {
		/**
		 * Filters the post types indexed for the AI assistant chatbot.
		 *
		 * @param array<int, string> $post_types Allowed post types.
		 */
		return (array) apply_filters( 'uxstudio_ai_assistant_index_post_types', self::DEFAULT_POST_TYPES );
	}

	/**
	 * Index one WP_Post, replacing any existing row for the same post id.
	 */
	private function index_single_post( \WP_Post $post ): void {
		global $wpdb;

		$post_id = $post->ID;

		$categories = array();
		foreach ( get_object_taxonomies( $post->post_type, 'names' ) as $tax ) {
			$terms = wp_get_post_terms( $post_id, $tax );
			if ( ! is_wp_error( $terms ) ) {
				foreach ( $terms as $term ) {
					$categories[] = $term->name;
				}
			}
		}

		$attrs = function_exists( 'get_field_objects' ) ? self::get_acf_fields( $post_id ) : array();

		$content_text = wp_strip_all_tags( $post->post_content );
		if ( ! empty( $post->post_excerpt ) ) {
			$content_text = wp_strip_all_tags( $post->post_excerpt ) . ' ' . $content_text;
		}
		if ( ! empty( $attrs ) ) {
			$content_text .= ' ' . implode( ' ', array_values( $attrs ) );
		}

		$wpdb->replace(
			"{$wpdb->prefix}uxstudio_ai_assistant_content_index",
			array(
				'post_id'      => $post_id,
				'post_type'    => $post->post_type,
				'post_title'   => $post->post_title,
				'content_text' => $content_text,
				'categories'   => wp_json_encode( array_values( array_unique( $categories ) ) ),
				'attributes'   => wp_json_encode( $attrs ),
				'image_url'    => get_the_post_thumbnail_url( $post_id, 'medium' ) ?: '',
				'permalink'    => (string) get_permalink( $post_id ),
				'indexed_at'   => current_time( 'mysql' ),
			),
			array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
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

			if ( '' === $value || null === $value || false === $value ) {
				continue;
			}
			if ( in_array( $type, $skip_types, true ) ) {
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
	 * Rough Czech-declension-aware stemming (strips common noun/adjective
	 * suffixes) so LIKE fallbacks still match declined search terms.
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
	 * Build a "(colA LIKE %s OR colB LIKE %s ...) OR (...)" WHERE fragment (with
	 * bound params) matching any stem against any of the given columns.
	 *
	 * @param array<int, string> $stems   Search stems.
	 * @param array<int, string> $columns Column names (trusted, never user input).
	 * @return array{0: string, 1: array<int, string>}
	 */
	private static function like_where( array $stems, array $columns ): array {
		global $wpdb;

		$conditions = array();
		$params     = array();
		foreach ( $stems as $stem ) {
			$escaped   = $wpdb->esc_like( $stem );
			$col_parts = array_fill( 0, count( $columns ), '%s' );
			$conditions[] = '(' . implode(
				' OR ',
				array_map( static fn( string $col ): string => "{$col} LIKE %s", $columns )
			) . ')';
			foreach ( $columns as $ignored ) {
				$params[] = '%' . $escaped . '%';
			}
		}

		return array( implode( ' OR ', $conditions ), $params );
	}

	/**
	 * Merge DB rows into the accumulator, deduplicated by an id column.
	 *
	 * @param array<int, array<string, mixed>>|null $rows       Rows to merge in.
	 * @param array<int, array<string, mixed>>       $results    Accumulator (by reference).
	 * @param array<int|string, bool>                $seen       Seen ids (by reference).
	 * @param string                                  $id_column  Column used as the dedupe key.
	 */
	private static function merge_rows( ?array $rows, array &$results, array &$seen, string $id_column ): void {
		foreach ( (array) $rows as $row ) {
			$id = (int) $row[ $id_column ];
			if ( isset( $seen[ $id ] ) ) {
				continue;
			}
			$seen[ $id ] = true;
			$results[]   = $row;
		}
	}
}
