<?php
/**
 * Core Quick Search integrations: posts, terms, users, media and the UX
 * Studio module list. Ported from the legacy admin-customiser module
 * (includes/quick-search/Integrations.php).
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\AdminCustomiser\QuickSearch;

defined( 'ABSPATH' ) || exit;

final class Integrations {

	/**
	 * Allowed post type slugs (from the `quick_search_post_types` setting).
	 * Empty = every public, non-attachment post type.
	 *
	 * @var string[]
	 */
	private array $post_types;

	/**
	 * @param string[] $post_types Allowed post type slugs (empty = all public post types).
	 */
	public function __construct( array $post_types = array() ) {
		$this->post_types = $post_types;
	}

	/**
	 * Run every core integration and merge their results.
	 *
	 * @param string $search_term Search query.
	 */
	public function search( string $search_term ): array {
		$results = array();

		foreach ( $this->search_posts( $search_term ) as $key => $group ) {
			$results[ $key ] = $group;
		}
		foreach ( $this->search_terms( $search_term ) as $key => $group ) {
			$results[ $key ] = $group;
		}
		$users = $this->search_users( $search_term );
		if ( null !== $users ) {
			$results = array_merge( $results, $users );
		}
		foreach ( $this->search_media( $search_term ) as $key => $group ) {
			$results[ $key ] = $group;
		}
		foreach ( $this->search_modules( $search_term ) as $key => $group ) {
			$results[ $key ] = $group;
		}

		return $results;
	}

	/**
	 * Post types eligible for search, honouring the `quick_search_post_types` setting.
	 *
	 * @return \WP_Post_Type[]
	 */
	private function searchable_post_types(): array {
		$all = get_post_types( array( 'public' => true ), 'objects' );
		unset( $all['attachment'] );

		if ( empty( $this->post_types ) ) {
			return $all;
		}

		return array_intersect_key( $all, array_flip( $this->post_types ) );
	}

	/**
	 * Search posts across the allowed public post types.
	 *
	 * @param string $search_term Search query.
	 */
	private function search_posts( string $search_term ): array {
		$results = array();

		foreach ( $this->searchable_post_types() as $post_type ) {
			$posts = get_posts(
				array(
					's'         => $search_term,
					'post_type' => $post_type->name,
					'posts_per_page' => 3,
					'no_found_rows'  => true,
				)
			);

			if ( empty( $posts ) ) {
				continue;
			}

			$group = array();
			foreach ( $posts as $post ) {
				$links = array();
				if ( current_user_can( 'edit_post', $post->ID ) ) {
					$links[] = array( 'label' => __( 'Edit', 'ux-studio' ), 'url' => get_edit_post_link( $post->ID, 'raw' ) );
				}
				if ( current_user_can( 'read_post', $post->ID ) ) {
					$links[] = array( 'label' => __( 'View', 'ux-studio' ), 'url' => get_permalink( $post->ID ) );
				}
				if ( empty( $links ) ) {
					continue;
				}

				$group[] = array(
					'label' => $post->post_title,
					'type'  => 'publish' !== $post->post_status ? $post->post_status : '',
					'links' => $links,
				);
			}

			if ( ! empty( $group ) ) {
				$results[ $post_type->label ] = $group;
			}
		}

		return $results;
	}

	/**
	 * Search terms across every public taxonomy.
	 *
	 * @param string $search_term Search query.
	 */
	private function search_terms( string $search_term ): array {
		$results = array();

		foreach ( get_taxonomies( array( 'public' => true ) ) as $taxonomy ) {
			$terms = get_terms(
				array(
					'taxonomy'   => $taxonomy,
					'name__like' => $search_term,
					'number'     => 3,
					'hide_empty' => true,
				)
			);

			if ( empty( $terms ) || is_wp_error( $terms ) ) {
				continue;
			}

			$taxonomy_obj  = get_taxonomy( $taxonomy );
			$taxonomy_name = $taxonomy_obj->labels->name;
			$group         = array();

			foreach ( $terms as $term ) {
				$links = array( array( 'label' => __( 'View', 'ux-studio' ), 'url' => get_term_link( $term ) ) );
				if ( current_user_can( 'edit_term', $term->term_id ) ) {
					$links[] = array( 'label' => __( 'Edit', 'ux-studio' ), 'url' => get_edit_term_link( $term ) );
				}

				$group[] = array(
					'label' => $term->name,
					'type'  => $term->taxonomy,
					'links' => $links,
				);
			}

			if ( ! empty( $group ) ) {
				$results[ $taxonomy_name ] = $group;
			}
		}

		return $results;
	}

	/**
	 * Search users. Requires `list_users` - skipped entirely otherwise.
	 *
	 * @param string $search_term Search query.
	 */
	private function search_users( string $search_term ): ?array {
		if ( ! current_user_can( 'list_users' ) ) {
			return null;
		}

		$users = get_users(
			array(
				'search' => "*{$search_term}*",
				'number' => 3,
			)
		);

		$group = array();
		foreach ( $users as $user ) {
			$links = array();
			if ( current_user_can( 'edit_user', $user->ID ) ) {
				$links[] = array( 'label' => __( 'Edit', 'ux-studio' ), 'url' => get_edit_user_link( $user->ID ) );
			}
			$links[] = array( 'label' => __( 'View', 'ux-studio' ), 'url' => get_author_posts_url( $user->ID ) );

			$group[] = array(
				'label' => $user->display_name,
				'links' => $links,
			);
		}

		if ( empty( $group ) ) {
			return null;
		}

		return array( __( 'Users', 'ux-studio' ) => $group );
	}

	/**
	 * Search media (attachments) by title.
	 *
	 * @param string $search_term Search query.
	 */
	private function search_media( string $search_term ): array {
		if ( ! in_array( 'attachment', $this->post_types, true ) && ! empty( $this->post_types ) ) {
			return array();
		}

		$query = new \WP_Query(
			array(
				's'              => $search_term,
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'posts_per_page' => 10,
			)
		);

		if ( ! $query->have_posts() ) {
			return array();
		}

		$group = array();
		foreach ( $query->posts as $post ) {
			$links = array();
			if ( current_user_can( 'edit_post', $post->ID ) ) {
				$links[] = array( 'label' => __( 'Edit', 'ux-studio' ), 'url' => get_edit_post_link( $post->ID, 'raw' ) );
			}
			if ( current_user_can( 'read_post', $post->ID ) ) {
				$links[] = array( 'label' => __( 'View', 'ux-studio' ), 'url' => wp_get_attachment_url( $post->ID ) );
			}
			if ( empty( $links ) ) {
				continue;
			}

			$group[] = array(
				'label' => $post->post_title,
				'type'  => get_post_mime_type( $post ),
				'links' => $links,
			);
		}

		return empty( $group ) ? array() : array( __( 'Media', 'ux-studio' ) => $group );
	}

	/**
	 * Search UX Studio's own module list (name/description match).
	 *
	 * @param string $search_term Search query.
	 */
	private function search_modules( string $search_term ): array {
		if ( '' === trim( $search_term ) || ! current_user_can( 'manage_options' ) ) {
			return array();
		}

		if ( ! class_exists( '\UxStudio\Plugin' ) ) {
			return array();
		}

		$modules = \UxStudio\Plugin::instance()->modules->all();
		$group   = array();

		foreach ( $modules as $id => $meta ) {
			$name = (string) ( $meta['name'] ?? $id );
			$desc = (string) ( $meta['description'] ?? '' );
			if ( false === stripos( $name, $search_term ) && false === stripos( $desc, $search_term ) ) {
				continue;
			}

			$group[] = array(
				'label' => $name,
				'links' => array(
					array(
						'label' => __( 'Open', 'ux-studio' ),
						'url'   => admin_url( 'admin.php?page=ux-studio#/modules/' . $id ),
					),
				),
			);
		}

		return empty( $group ) ? array() : array( __( 'UX Studio', 'ux-studio' ) => $group );
	}
}
