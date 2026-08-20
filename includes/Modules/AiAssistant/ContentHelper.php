<?php
/**
 * Helpers for turning AI-generated content into WordPress posts: public
 * post type listing, category/tag lookup-or-create, draft creation and
 * publishing.
 *
 * Ported from the legacy ux1-wordpress-customizer AI Assistant module
 * (includes/ContentHelper.php).
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\AiAssistant;

defined( 'ABSPATH' ) || exit;

final class ContentHelper {

	/**
	 * Public post types available for AI content generation (id => singular label).
	 *
	 * @return array<string, string>
	 */
	public static function get_public_post_types(): array {
		$types  = get_post_types( array( 'public' => true ), 'objects' );
		$result = array();

		foreach ( $types as $type ) {
			if ( in_array( $type->name, array( 'attachment', 'revision', 'nav_menu_item' ), true ) ) {
				continue;
			}
			$result[ $type->name ] = $type->labels->singular_name;
		}

		return $result;
	}

	/**
	 * Finds an existing category by name or creates it.
	 */
	public static function get_or_create_category( string $category_name ): int {
		$term = term_exists( $category_name, 'category' );
		if ( $term ) {
			return (int) ( is_array( $term ) ? $term['term_id'] : $term );
		}

		$result = wp_insert_term( $category_name, 'category' );
		if ( is_wp_error( $result ) ) {
			return 0;
		}

		return (int) $result['term_id'];
	}

	/**
	 * Finds/creates the given tag names and returns their term ids.
	 *
	 * @param array<int, string> $tags
	 * @return array<int, int>
	 */
	public static function get_or_create_tags( array $tags ): array {
		$tag_ids = array();

		foreach ( $tags as $tag_name ) {
			$tag_name = trim( (string) $tag_name );
			if ( '' === $tag_name ) {
				continue;
			}

			$term = term_exists( $tag_name, 'post_tag' );
			if ( $term ) {
				$tag_ids[] = (int) ( is_array( $term ) ? $term['term_id'] : $term );
				continue;
			}

			$result = wp_insert_term( $tag_name, 'post_tag' );
			if ( ! is_wp_error( $result ) ) {
				$tag_ids[] = (int) $result['term_id'];
			}
		}

		return $tag_ids;
	}

	/**
	 * Creates a draft post from AI-generated content (title/content/excerpt/category/tags).
	 *
	 * @param array<string, mixed> $generated
	 */
	public static function create_draft( array $generated, string $post_type = 'post' ): int {
		$post_data = array(
			'post_title'   => sanitize_text_field( (string) ( $generated['title'] ?? '' ) ),
			'post_content' => wp_kses_post( (string) ( $generated['content'] ?? '' ) ),
			'post_excerpt' => sanitize_text_field( (string) ( $generated['excerpt'] ?? '' ) ),
			'post_status'  => 'draft',
			'post_type'    => $post_type,
		);

		$post_id = wp_insert_post( $post_data, true );

		if ( is_wp_error( $post_id ) ) {
			throw new \RuntimeException( $post_id->get_error_message() );
		}

		if ( ! empty( $generated['category'] ) && 'post' === $post_type ) {
			$category_id = self::get_or_create_category( (string) $generated['category'] );
			if ( $category_id > 0 ) {
				wp_set_post_categories( $post_id, array( $category_id ) );
			}
		}

		if ( ! empty( $generated['tags'] ) && is_array( $generated['tags'] ) && 'post' === $post_type ) {
			$tag_ids = self::get_or_create_tags( $generated['tags'] );
			if ( ! empty( $tag_ids ) ) {
				wp_set_post_tags( $post_id, $tag_ids );
			}
		}

		return (int) $post_id;
	}

	/**
	 * Publishes an existing (draft) post.
	 */
	public static function publish_post( int $post_id ): bool {
		$result = wp_update_post(
			array(
				'ID'          => $post_id,
				'post_status' => 'publish',
			),
			true
		);

		return ! is_wp_error( $result );
	}
}
