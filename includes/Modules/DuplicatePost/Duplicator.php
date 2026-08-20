<?php
/**
 * Post duplication engine for the Duplicate Post module.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\DuplicatePost;

defined( 'ABSPATH' ) || exit;

/**
 * Copies a post (content, meta, taxonomies) according to the selected
 * elements. Ported from the legacy module.
 */
final class Duplicator {

	/**
	 * Meta keys never copied to the duplicate.
	 *
	 * @var string[]
	 */
	private array $excluded_meta;

	/**
	 * @param string[] $excluded_meta Meta keys never copied.
	 */
	public function __construct( array $excluded_meta ) {
		$this->excluded_meta = $excluded_meta;
	}

	/**
	 * Duplicate a post.
	 *
	 * @param int      $post_id  Source post ID.
	 * @param string[] $elements Elements to copy (post_content, post_excerpt,
	 *                           featured_image, taxonomies, meta_fields,
	 *                           menu_order, post_parent, product_gallery).
	 * @param string   $status   Post status for the duplicate.
	 * @param string   $title    Title for the duplicate.
	 * @return \WP_Post|\WP_Error
	 */
	public function duplicate( int $post_id, array $elements, string $status, string $title ) {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return new \WP_Error( 'invalid_post', __( 'Invalid post ID.', 'ux-studio' ) );
		}

		$elements = apply_filters( 'ux_studio/duplicate_post/elements', $elements, $post_id );

		// wp_insert_post expects slashed data (it calls wp_unslash internally).
		// get_post() returns unslashed values, so we re-slash to prevent
		// double-unslash corruption of escaped chars (e.g. í in Elementor
		// JSON, \" in Gutenberg blocks).
		$new_post = array(
			'post_title'   => wp_slash( $title ),
			'post_content' => in_array( 'post_content', $elements, true ) ? wp_slash( $post->post_content ) : '',
			'post_excerpt' => in_array( 'post_excerpt', $elements, true ) ? wp_slash( $post->post_excerpt ) : '',
			'post_status'  => $status,
			'post_type'    => $post->post_type,
			'post_author'  => get_current_user_id(),
			'post_parent'  => in_array( 'post_parent', $elements, true ) ? $post->post_parent : 0,
			'menu_order'   => in_array( 'menu_order', $elements, true ) ? $post->menu_order : 0,
			'to_ping'      => $post->to_ping,
			'pinged'       => $post->pinged,
		);

		$new_post = apply_filters( 'ux_studio/duplicate_post/post_data', $new_post, $post, $elements );

		$new_post_id = wp_insert_post( $new_post, true );
		if ( is_wp_error( $new_post_id ) ) {
			return $new_post_id;
		}

		if ( in_array( 'meta_fields', $elements, true ) || in_array( 'featured_image', $elements, true ) || in_array( 'product_gallery', $elements, true ) ) {
			$this->duplicate_post_meta( $post_id, $new_post_id, $elements );
		}

		if ( in_array( 'taxonomies', $elements, true ) ) {
			$this->duplicate_taxonomies( $post_id, $new_post_id );
		}

		$this->handle_integrations( $new_post_id );

		do_action( 'ux_studio/duplicate_post/after_duplicate', $new_post_id, $post_id, $elements );

		return get_post( $new_post_id );
	}

	/**
	 * Copy post meta according to the selected elements.
	 *
	 * @param int      $source_id Source post ID.
	 * @param int      $target_id Target post ID.
	 * @param string[] $elements  Active elements.
	 */
	private function duplicate_post_meta( int $source_id, int $target_id, array $elements ): void {
		$meta = get_post_meta( $source_id );
		if ( ! is_array( $meta ) ) {
			return;
		}

		$excluded_meta = $this->excluded_meta;

		if ( ! in_array( 'featured_image', $elements, true ) ) {
			$excluded_meta[] = '_thumbnail_id';
		}
		if ( ! in_array( 'product_gallery', $elements, true ) ) {
			$excluded_meta[] = '_product_image_gallery';
		}

		$excluded_meta = apply_filters( 'ux_studio/duplicate_post/excluded_meta_keys', $excluded_meta, $source_id, $target_id, $elements );

		foreach ( $meta as $key => $values ) {
			if ( in_array( $key, $excluded_meta, true ) ) {
				continue;
			}

			// If meta_fields is not selected, only allow element-controlled keys through.
			if ( ! in_array( 'meta_fields', $elements, true ) ) {
				$allowed_keys = array();
				if ( in_array( 'featured_image', $elements, true ) ) {
					$allowed_keys[] = '_thumbnail_id';
				}
				if ( in_array( 'product_gallery', $elements, true ) ) {
					$allowed_keys[] = '_product_image_gallery';
				}
				$allowed_keys = apply_filters( 'ux_studio/duplicate_post/allowed_meta_keys', $allowed_keys, $elements, $source_id );
				if ( ! in_array( $key, $allowed_keys, true ) ) {
					continue;
				}
			}

			$should_copy = apply_filters( 'ux_studio/duplicate_post/should_copy_meta', true, $key, $values, $source_id, $target_id );
			if ( ! $should_copy ) {
				continue;
			}

			foreach ( $values as $value ) {
				// Skip object-serialized values (security).
				if ( is_string( $value ) && preg_match( '/^O:\d+:/', $value ) ) {
					continue;
				}
				$value = maybe_unserialize( $value );
				// wp_slash compensates add_post_meta's internal wp_unslash -
				// prevents corruption of Elementor JSON and other escaped data.
				add_post_meta( $target_id, $key, wp_slash( $value ) );
			}
		}
	}

	/**
	 * Copy taxonomy terms to the duplicate.
	 *
	 * @param int $source_id Source post ID.
	 * @param int $target_id Target post ID.
	 */
	private function duplicate_taxonomies( int $source_id, int $target_id ): void {
		$taxonomies = get_object_taxonomies( (string) get_post_type( $source_id ) );
		if ( ! is_array( $taxonomies ) ) {
			return;
		}

		$excluded_taxonomies = apply_filters( 'ux_studio/duplicate_post/excluded_taxonomies', array(), $source_id, $target_id );

		foreach ( $taxonomies as $taxonomy ) {
			if ( in_array( $taxonomy, $excluded_taxonomies, true ) ) {
				continue;
			}

			$should_copy = apply_filters( 'ux_studio/duplicate_post/should_copy_taxonomy', true, $taxonomy, $source_id, $target_id );
			if ( ! $should_copy ) {
				continue;
			}

			$terms = wp_get_object_terms( $source_id, $taxonomy, array( 'fields' => 'slugs' ) );
			if ( ! is_wp_error( $terms ) ) {
				wp_set_object_terms( $target_id, $terms, $taxonomy );
			}
		}
	}

	/**
	 * Third-party plugin integrations for the duplicated post (Elementor).
	 *
	 * @param int $post_id Duplicated post ID.
	 */
	private function handle_integrations( int $post_id ): void {
		if ( ! defined( 'ELEMENTOR_VERSION' ) ) {
			return;
		}

		// Clear Elementor CSS/assets cache on the duplicate.
		delete_post_meta( $post_id, '_elementor_css' );
		delete_post_meta( $post_id, '_elementor_page_assets' );

		// Regenerate the per-post CSS file.
		if ( class_exists( '\Elementor\Core\Files\CSS\Post' ) ) {
			$css = \Elementor\Core\Files\CSS\Post::create( $post_id );
			$css->update();
		}

		if ( class_exists( '\Elementor\Plugin' ) ) {
			\Elementor\Plugin::instance()->files_manager->clear_cache();
		}
	}
}
