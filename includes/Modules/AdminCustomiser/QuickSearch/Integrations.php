<?php
/**
 * Core Quick Search integrations: posts, terms, users, media and the UX
 * Studio module list. Ported from the legacy admin-customiser module
 * (includes/quick-search/Integrations.php).
 *
 * Every row carries what the dropdown needs to draw it without guessing:
 *   label  plain-text title (no markup, emoji images replaced by their alt text)
 *   type   raw machine type (status, taxonomy slug); only used by the fuzzy filter
 *   kind   icon family: post, page, product, cpt, media, user, term, module
 *   meta   human readable secondary text ("Page", "PNG", "Product tag")
 *   thumb  image URL (media thumbnail, user avatar), when there is one
 *   links  [ { label, url, action: edit|view|open } ]
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\AdminCustomiser\QuickSearch;

defined( 'ABSPATH' ) || exit;

final class Integrations {

	/** Rows fetched per group; the dropdown shows at most 5, the sixth only proves that "show all" is worth a link. */
	private const PER_GROUP = 6;

	/**
	 * Allowed post type slugs (from the `quick_search_post_types` setting).
	 * Empty = every public, non-attachment post type.
	 *
	 * @var string[]
	 */
	private array $post_types;

	/**
	 * Per group: total number of matches and the admin list screen that shows all of them.
	 *
	 * @var array<string, array{total:int, url:string}>
	 */
	private array $group_meta = array();

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
		$this->group_meta = array();
		$results          = array();

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
	 * Totals and "show all" URLs of the groups found by the last search().
	 *
	 * @return array<string, array{total:int, url:string}>
	 */
	public function group_meta(): array {
		return $this->group_meta;
	}

	/**
	 * Plain-text title: markup stripped and WordPress emoji images (<img alt="...">) replaced by their alt text.
	 *
	 * @param string $title Stored title.
	 */
	public static function clean_title( string $title ): string {
		$title = preg_replace_callback(
			'/<img[^>]*>/i',
			static function ( array $m ): string {
				return preg_match( '/alt\s*=\s*(?:"([^"]*)"|\'([^\']*)\')/i', $m[0], $a ) ? ( $a[1] ?? '' ) . ( $a[2] ?? '' ) : '';
			},
			$title
		);
		$title = wp_strip_all_tags( (string) $title );
		$title = html_entity_decode( $title, ENT_QUOTES, 'UTF-8' );
		return trim( (string) preg_replace( '/\s+/u', ' ', $title ) );
	}

	/**
	 * Upper-case file type for a media row ("PNG", "JPG", "PDF").
	 *
	 * @param \WP_Post $post Attachment.
	 */
	private function media_type_label( \WP_Post $post ): string {
		$file = get_attached_file( $post->ID );
		$ext  = $file ? strtolower( (string) pathinfo( $file, PATHINFO_EXTENSION ) ) : '';
		if ( '' === $ext ) {
			$mime = (string) get_post_mime_type( $post );
			$ext  = strtolower( (string) substr( strrchr( '/' . $mime, '/' ), 1 ) );
		}
		if ( 'jpeg' === $ext ) {
			$ext = 'jpg';
		}
		return strtoupper( $ext );
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
			$query = new \WP_Query(
				array(
					's'                      => $search_term,
					'post_type'              => $post_type->name,
					'post_status'            => 'publish',
					'posts_per_page'         => self::PER_GROUP,
					'ignore_sticky_posts'    => true,
					// As get_posts() did before: search plugins that hook posts_search must not reshape this list.
					'suppress_filters'       => true,
					'update_post_term_cache' => false,
				)
			);

			if ( empty( $query->posts ) ) {
				continue;
			}

			$singular = '' !== (string) ( $post_type->labels->singular_name ?? '' ) ? (string) $post_type->labels->singular_name : $post_type->label;
			$kind     = in_array( $post_type->name, array( 'post', 'page', 'product' ), true ) ? $post_type->name : 'cpt';

			$group = array();
			foreach ( $query->posts as $post ) {
				$links = array();
				if ( current_user_can( 'edit_post', $post->ID ) ) {
					$links[] = array(
						'label'  => __( 'Edit', 'ux-studio' ),
						'url'    => get_edit_post_link( $post->ID, 'raw' ),
						'action' => 'edit',
					);
				}
				if ( current_user_can( 'read_post', $post->ID ) ) {
					$links[] = array(
						'label'  => __( 'View', 'ux-studio' ),
						'url'    => get_permalink( $post->ID ),
						'action' => 'view',
					);
				}
				if ( empty( $links ) ) {
					continue;
				}

				$title = self::clean_title( (string) $post->post_title );
				$group[] = array(
					'label' => '' !== $title ? $title : __( '(no title)', 'ux-studio' ),
					'type'  => 'publish' !== $post->post_status ? $post->post_status : '',
					'kind'  => $kind,
					'meta'  => $singular,
					'links' => $links,
				);
			}

			if ( ! empty( $group ) ) {
				$results[ $post_type->label ]    = $group;
				$this->group_meta[ $post_type->label ] = array(
					'total' => (int) $query->found_posts,
					'url'   => add_query_arg(
						array(
							's'         => $search_term,
							'post_type' => $post_type->name,
						),
						admin_url( 'edit.php' )
					),
				);
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
			$args  = array(
				'taxonomy'   => $taxonomy,
				'name__like' => $search_term,
				'hide_empty' => true,
			);
			$terms = get_terms( $args + array( 'number' => self::PER_GROUP ) );

			if ( empty( $terms ) || is_wp_error( $terms ) ) {
				continue;
			}

			$taxonomy_obj  = get_taxonomy( $taxonomy );
			$taxonomy_name = $taxonomy_obj->labels->name;
			$singular      = '' !== (string) ( $taxonomy_obj->labels->singular_name ?? '' ) ? (string) $taxonomy_obj->labels->singular_name : $taxonomy_name;
			$group         = array();

			foreach ( $terms as $term ) {
				$links = array();
				if ( current_user_can( 'edit_term', $term->term_id ) ) {
					$links[] = array(
						'label'  => __( 'Edit', 'ux-studio' ),
						'url'    => get_edit_term_link( $term ),
						'action' => 'edit',
					);
				}
				$view = get_term_link( $term );
				if ( ! is_wp_error( $view ) ) {
					$links[] = array(
						'label'  => __( 'View', 'ux-studio' ),
						'url'    => $view,
						'action' => 'view',
					);
				}
				if ( empty( $links ) ) {
					continue;
				}

				$title   = self::clean_title( (string) $term->name );
				$group[] = array(
					'label' => $title,
					'type'  => $term->taxonomy,
					'kind'  => 'term',
					'meta'  => $singular,
					'links' => $links,
				);
			}

			if ( ! empty( $group ) ) {
				$results[ $taxonomy_name ] = $group;

				$total = wp_count_terms( $args );
				$url   = add_query_arg(
					array(
						'taxonomy' => $taxonomy,
						's'        => $search_term,
					),
					admin_url( 'edit-tags.php' )
				);
				$object_type = (string) ( $taxonomy_obj->object_type[0] ?? '' );
				if ( '' !== $object_type && 'post' !== $object_type ) {
					$url = add_query_arg( 'post_type', $object_type, $url );
				}
				$this->group_meta[ $taxonomy_name ] = array(
					'total' => is_wp_error( $total ) ? count( $terms ) : (int) $total,
					'url'   => $url,
				);
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

		$query = new \WP_User_Query(
			array(
				'search' => "*{$search_term}*",
				'number' => self::PER_GROUP,
			)
		);

		$group = array();
		foreach ( $query->get_results() as $user ) {
			$links = array();
			if ( current_user_can( 'edit_user', $user->ID ) ) {
				$links[] = array(
					'label'  => __( 'Edit', 'ux-studio' ),
					'url'    => get_edit_user_link( $user->ID ),
					'action' => 'edit',
				);
			}
			$links[] = array(
				'label'  => __( 'View', 'ux-studio' ),
				'url'    => get_author_posts_url( $user->ID ),
				'action' => 'view',
			);

			$roles   = (array) $user->roles;
			$wp_roles = wp_roles();
			$role    = '';
			if ( $roles && isset( $wp_roles->role_names[ $roles[0] ] ) ) {
				$role = translate_user_role( $wp_roles->role_names[ $roles[0] ] );
			}

			$group[] = array(
				'label' => self::clean_title( (string) $user->display_name ),
				'kind'  => 'user',
				'meta'  => $role,
				'thumb' => (string) get_avatar_url( $user->ID, array( 'size' => 40 ) ),
				'links' => $links,
			);
		}

		if ( empty( $group ) ) {
			return null;
		}

		$label                      = __( 'Users', 'ux-studio' );
		$this->group_meta[ $label ] = array(
			'total' => (int) $query->get_total(),
			'url'   => add_query_arg( 's', $search_term, admin_url( 'users.php' ) ),
		);

		return array( $label => $group );
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
				's'                      => $search_term,
				'post_type'              => 'attachment',
				'post_status'            => 'inherit',
				'posts_per_page'         => self::PER_GROUP,
				'update_post_term_cache' => false,
			)
		);

		if ( ! $query->have_posts() ) {
			return array();
		}

		$group = array();
		foreach ( $query->posts as $post ) {
			$links = array();
			if ( current_user_can( 'edit_post', $post->ID ) ) {
				$links[] = array(
					'label'  => __( 'Edit', 'ux-studio' ),
					'url'    => get_edit_post_link( $post->ID, 'raw' ),
					'action' => 'edit',
				);
			}
			if ( current_user_can( 'read_post', $post->ID ) ) {
				$links[] = array(
					'label'  => __( 'View', 'ux-studio' ),
					'url'    => wp_get_attachment_url( $post->ID ),
					'action' => 'view',
				);
			}
			if ( empty( $links ) ) {
				continue;
			}

			$thumb = wp_attachment_is_image( $post ) ? wp_get_attachment_image_url( $post->ID, 'thumbnail' ) : '';
			$title = self::clean_title( (string) $post->post_title );

			$group[] = array(
				'label' => '' !== $title ? $title : wp_basename( (string) get_attached_file( $post->ID ) ),
				'type'  => (string) get_post_mime_type( $post ),
				'kind'  => 'media',
				'meta'  => $this->media_type_label( $post ),
				'thumb' => $thumb ? (string) $thumb : '',
				'links' => $links,
			);
		}

		if ( empty( $group ) ) {
			return array();
		}

		$label                      = __( 'Media', 'ux-studio' );
		$this->group_meta[ $label ] = array(
			'total' => (int) $query->found_posts,
			'url'   => add_query_arg(
				array(
					's'      => $search_term,
					'search' => $search_term,
				),
				admin_url( 'upload.php' )
			),
		);

		return array( $label => $group );
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
				'kind'  => 'module',
				'links' => array(
					array(
						'label'  => __( 'Open', 'ux-studio' ),
						'url'    => admin_url( 'admin.php?page=ux-studio#/modules/' . $id ),
						'action' => 'open',
					),
				),
			);
		}

		return empty( $group ) ? array() : array( __( 'UX Studio', 'ux-studio' ) => $group );
	}
}
