<?php
/**
 * Inventory of the CURRENT site's public URLs plus a fuzzy "where did this
 * old URL go" lookup. Gives an AI agent what it needs to map old URLs to
 * new ones without scraping the site.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\Redirects;

defined( 'ABSPATH' ) || exit;

final class SiteIndex {

	/**
	 * Public post types meant to be landed on: no attachments and nothing
	 * excluded from search (Elementor templates, landing-page drafts…).
	 *
	 * @return array<int, string>
	 */
	private static function post_types(): array {
		$types = get_post_types( array( 'public' => true, 'exclude_from_search' => false ), 'names' );
		unset( $types['attachment'] );
		return array_values( $types );
	}

	/**
	 * Paged list of published URLs (posts) or archive URLs (terms).
	 *
	 * @param array<string, mixed> $args kind (posts|terms), post_type, taxonomy, search, limit, offset.
	 * @return array{items:array<int, array<string, mixed>>, total:int}
	 */
	public static function urls( array $args ): array {
		$limit  = max( 1, min( 500, (int) ( $args['limit'] ?? 100 ) ) );
		$offset = max( 0, (int) ( $args['offset'] ?? 0 ) );
		$search = trim( (string) ( $args['search'] ?? '' ) );

		if ( 'terms' === ( $args['kind'] ?? 'posts' ) ) {
			$taxonomies = get_taxonomies( array( 'public' => true ), 'names' );
			if ( ! empty( $args['taxonomy'] ) && isset( $taxonomies[ $args['taxonomy'] ] ) ) {
				$taxonomies = array( (string) $args['taxonomy'] );
			}
			$query = array(
				'taxonomy'   => array_values( $taxonomies ),
				'hide_empty' => true,
				'number'     => $limit,
				'offset'     => $offset,
				'search'     => $search,
			);
			$terms = get_terms( $query );
			$total = (int) wp_count_terms( array_diff_key( $query, array_flip( array( 'number', 'offset' ) ) ) );
			$items = array();
			foreach ( is_array( $terms ) ? $terms : array() as $term ) {
				$link    = get_term_link( $term );
				$items[] = array(
					'term_id'  => $term->term_id,
					'taxonomy' => $term->taxonomy,
					'title'    => $term->name,
					'url'      => is_string( $link ) ? $link : '',
					'path'     => is_string( $link ) ? Url::to_relative( $link ) : '',
					'count'    => $term->count,
				);
			}
			return array( 'items' => $items, 'total' => $total );
		}

		$types = self::post_types();
		if ( ! empty( $args['post_type'] ) && in_array( $args['post_type'], $types, true ) ) {
			$types = array( (string) $args['post_type'] );
		}
		$query = new \WP_Query(
			array(
				'post_type'              => $types,
				'post_status'            => 'publish',
				'posts_per_page'         => $limit,
				'offset'                 => $offset,
				's'                      => $search,
				'orderby'                => 'ID',
				'order'                  => 'ASC',
				'no_found_rows'          => false,
				'update_post_term_cache' => false,
				'update_post_meta_cache' => false,
				'suppress_filters'       => false,
			)
		);
		$items = array();
		foreach ( $query->posts as $post ) {
			$link    = (string) get_permalink( $post );
			$items[] = array(
				'post_id'   => $post->ID,
				'post_type' => $post->post_type,
				'title'     => get_the_title( $post ),
				'slug'      => $post->post_name,
				'url'       => $link,
				'path'      => Url::to_relative( $link ),
				'modified'  => $post->post_modified,
			);
		}
		return array( 'items' => $items, 'total' => (int) $query->found_posts );
	}

	/**
	 * Best-matching current posts for an old URL (slug similarity + search).
	 *
	 * @param string $old_url Old URL or path.
	 * @param string $title   Old page title, if known (improves matching).
	 * @param int    $limit   Max candidates.
	 * @return array<int, array<string, mixed>>
	 */
	public static function candidates( string $old_url, string $title = '', int $limit = 5 ): array {
		$path = Url::parse_source( $old_url )['path'];
		$slug = (string) preg_replace( '~\.(html?|php|aspx?|jsp)$~', '', basename( $path ) );
		$slug = sanitize_title( (string) preg_replace( '~^\d+[-_]~', '', $slug ) );
		$words = trim( str_replace( array( '-', '_' ), ' ', $slug ) );
		$types = self::post_types();

		$posts = array();
		if ( '' !== $slug ) {
			foreach ( get_posts( array( 'name' => $slug, 'post_type' => $types, 'post_status' => 'publish', 'numberposts' => 5 ) ) as $post ) {
				$posts[ $post->ID ] = $post;
			}
		}
		foreach ( array_unique( array_filter( array( $title, $words ) ) ) as $term ) {
			foreach ( get_posts( array( 's' => $term, 'post_type' => $types, 'post_status' => 'publish', 'numberposts' => 15, 'suppress_filters' => false ) ) as $post ) {
				$posts[ $post->ID ] = $post;
			}
		}

		$results = array();
		foreach ( $posts as $post ) {
			similar_text( $slug, $post->post_name, $slug_score );
			$score = $slug === $post->post_name ? 100.0 : $slug_score;
			if ( '' !== $title ) {
				similar_text( mb_strtolower( $title, 'UTF-8' ), mb_strtolower( get_the_title( $post ), 'UTF-8' ), $title_score );
				$score = max( $score, 0.5 * $score + 0.5 * $title_score );
			}
			$link      = (string) get_permalink( $post );
			$results[] = array(
				'post_id'   => $post->ID,
				'post_type' => $post->post_type,
				'title'     => get_the_title( $post ),
				'url'       => $link,
				'path'      => Url::to_relative( $link ),
				'score'     => (int) round( $score ),
			);
		}
		usort( $results, static fn ( array $a, array $b ): int => $b['score'] <=> $a['score'] );
		return array_slice( $results, 0, max( 1, min( 20, $limit ) ) );
	}
}
