<?php
/**
 * Admin Columns REST controller.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\AdminColumns;

use UxStudio\Rest\Controller;
use WP_REST_Request;

defined( 'ABSPATH' ) || exit;

/**
 * GET  uxstudio/v1/admin-columns/config/{post_type} - read column config
 * POST uxstudio/v1/admin-columns/config/{post_type} - save column config
 *
 * {post_type} may be a real post type or the pseudo types users | comments |
 * attachment. The column builder UI in the SPA consumes these endpoints.
 */
final class RestController extends Controller {

	private Module $module;

	/**
	 * @param Module $module Owning module instance.
	 */
	public function __construct( Module $module ) {
		$this->module = $module;
	}

	/**
	 * Register routes.
	 */
	public function register_routes(): void {
		$this->route( '/admin-columns/content-types', 'GET', array( $this, 'get_content_types' ) );
		$this->route( '/admin-columns/configured', 'GET', array( $this, 'get_configured' ) );
		$this->route( '/admin-columns/keys/(?P<post_type>[\w-]+)', 'GET', array( $this, 'get_key_suggestions' ) );
		$this->route( '/admin-columns/config/(?P<post_type>[\w-]+)', 'GET', array( $this, 'get_config' ) );
		$this->route(
			'/admin-columns/config/(?P<post_type>[\w-]+)',
			'POST',
			array( $this, 'save_config' ),
			array(
				'columns' => array(
					'required' => true,
					'type'     => 'array',
				),
			)
		);
	}

	/**
	 * List every content type the column builder can target: public post
	 * types (except attachment, listed separately as "Media") plus the
	 * pseudo types users/comments/attachment that have their own list tables.
	 */
	public function get_content_types( WP_REST_Request $request ) {
		return $this->ok( $this->list_types() );
	}

	/**
	 * Content types that already have a saved column configuration (own data
	 * or migrated legacy data) — used to seed the accordion so it only shows
	 * types the site owner actually customized, not all ~15 available types.
	 */
	public function get_configured( WP_REST_Request $request ) {
		$result = array();
		foreach ( $this->list_types() as $type ) {
			$columns = $this->module->get_config( $type['id'] );
			if ( ! empty( $columns ) ) {
				$result[] = array(
					'post_type' => $type['id'],
					'label'     => $type['label'],
					'columns'   => $columns,
				);
			}
		}
		return $this->ok( $result );
	}

	/**
	 * @return array<int, array{id:string,label:string}>
	 */
	private function list_types(): array {
		$types = array();
		foreach ( get_post_types( array( 'public' => true, 'show_ui' => true ), 'objects' ) as $post_type ) {
			if ( 'attachment' === $post_type->name ) {
				continue;
			}
			$types[] = array(
				'id'    => $post_type->name,
				'label' => (string) ( $post_type->labels->name ?? $post_type->name ),
			);
		}
		$types[] = array( 'id' => 'attachment', 'label' => __( 'Media', 'ux-studio' ) );
		$types[] = array( 'id' => 'users', 'label' => __( 'Users', 'ux-studio' ) );
		$types[] = array( 'id' => 'comments', 'label' => __( 'Comments', 'ux-studio' ) );

		return $types;
	}

	/**
	 * Key autocomplete suggestions for a content type + column type, so the
	 * builder doesn't require memorizing native column slugs, meta keys or
	 * taxonomy slugs by hand.
	 *
	 * @param WP_REST_Request $request Request. `column_type` query param:
	 *                                 default|meta|taxonomy|post_id|thumbnail.
	 */
	public function get_key_suggestions( WP_REST_Request $request ) {
		$content_type = $this->content_type( $request );
		if ( null === $content_type ) {
			return $this->unknown_type();
		}

		$column_type = sanitize_key( (string) $request->get_param( 'column_type' ) );

		switch ( $column_type ) {
			case 'meta':
				return $this->ok( $this->meta_key_suggestions( $content_type ) );
			case 'taxonomy':
				return $this->ok( $this->taxonomy_suggestions( $content_type ) );
			default:
				return $this->ok( $this->native_column_suggestions( $content_type ) );
		}
	}

	/**
	 * Native/current columns for a content type's own list table — the exact
	 * same set WordPress core (+ any other active plugin) renders there right
	 * now, via the real WP_*_List_Table::get_columns() + the manage_*_columns
	 * filter chain. This is what "default" type columns relabel/reorder/hide.
	 *
	 * @param string $content_type Content type.
	 * @return array<int, array{key:string,label:string}>
	 */
	private function native_column_suggestions( string $content_type ): array {
		if ( ! class_exists( 'WP_List_Table' ) ) {
			require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
		}
		if ( ! function_exists( 'get_column_headers' ) ) {
			require_once ABSPATH . 'wp-admin/includes/screen.php';
		}

		switch ( $content_type ) {
			case 'users':
				if ( ! class_exists( 'WP_Users_List_Table' ) ) {
					require_once ABSPATH . 'wp-admin/includes/class-wp-users-list-table.php';
				}
				$screen_id = 'users';
				$table     = new \WP_Users_List_Table( array( 'screen' => $screen_id ) );
				break;
			case 'comments':
				if ( ! class_exists( 'WP_Comments_List_Table' ) ) {
					require_once ABSPATH . 'wp-admin/includes/class-wp-comments-list-table.php';
				}
				$screen_id = 'edit-comments';
				$table     = new \WP_Comments_List_Table( array( 'screen' => $screen_id ) );
				break;
			case 'attachment':
				if ( ! class_exists( 'WP_Media_List_Table' ) ) {
					require_once ABSPATH . 'wp-admin/includes/class-wp-media-list-table.php';
				}
				$screen_id = 'upload';
				$table     = new \WP_Media_List_Table( array( 'screen' => $screen_id ) );
				break;
			default:
				if ( ! post_type_exists( $content_type ) ) {
					return array();
				}
				if ( ! class_exists( 'WP_Posts_List_Table' ) ) {
					require_once ABSPATH . 'wp-admin/includes/class-wp-posts-list-table.php';
				}
				$screen_id = 'edit-' . $content_type;
				$table     = new \WP_Posts_List_Table( array( 'screen' => $screen_id ) );
		}

		// The list table's constructor self-registers as the base provider for
		// this filter (priority 0); calling get_column_headers() now runs the
		// FULL chain (core + this table + any other active plugin), exactly
		// like a real edit screen render — no separate get_columns() fallback
		// needed.
		$columns = get_column_headers( $screen_id );
		unset( $columns['cb'] );

		$result = array();
		foreach ( $columns as $key => $label ) {
			$result[] = array( 'key' => $key, 'label' => wp_strip_all_tags( (string) $label ) );
		}
		return $result;
	}

	/**
	 * Distinct meta keys already used by this content type (post/user/comment
	 * meta), excluding WordPress's own protected/internal keys (leading `_`).
	 *
	 * @param string $content_type Content type.
	 * @return array<int, array{key:string,label:string}>
	 */
	private function meta_key_suggestions( string $content_type ): array {
		global $wpdb;

		if ( 'users' === $content_type ) {
			$keys = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT DISTINCT meta_key FROM {$wpdb->usermeta} WHERE meta_key NOT LIKE %s ORDER BY meta_key ASC LIMIT 60",
					$wpdb->esc_like( '_' ) . '%'
				)
			);
		} elseif ( 'comments' === $content_type ) {
			$keys = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT DISTINCT meta_key FROM {$wpdb->commentmeta} WHERE meta_key NOT LIKE %s ORDER BY meta_key ASC LIMIT 60",
					$wpdb->esc_like( '_' ) . '%'
				)
			);
		} else {
			$post_type = 'attachment' === $content_type ? 'attachment' : $content_type;
			$keys      = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT DISTINCT pm.meta_key FROM {$wpdb->postmeta} pm
					INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
					WHERE p.post_type = %s AND pm.meta_key NOT LIKE %s
					ORDER BY pm.meta_key ASC LIMIT 60",
					$post_type,
					$wpdb->esc_like( '_' ) . '%'
				)
			);
		}

		return array_map( static fn( $key ) => array( 'key' => $key, 'label' => $key ), $keys );
	}

	/**
	 * Taxonomies registered for this content type.
	 *
	 * @param string $content_type Content type.
	 * @return array<int, array{key:string,label:string}>
	 */
	private function taxonomy_suggestions( string $content_type ): array {
		if ( in_array( $content_type, array( 'users', 'comments' ), true ) ) {
			return array();
		}

		$result = array();
		foreach ( get_object_taxonomies( $content_type, 'objects' ) as $taxonomy ) {
			$result[] = array( 'key' => $taxonomy->name, 'label' => (string) $taxonomy->label );
		}
		return $result;
	}

	/**
	 * Read the column configuration for a content type.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function get_config( WP_REST_Request $request ) {
		$content_type = $this->content_type( $request );
		if ( null === $content_type ) {
			return $this->unknown_type();
		}

		return $this->ok(
			array(
				'post_type' => $content_type,
				'columns'   => $this->module->get_config( $content_type ),
			)
		);
	}

	/**
	 * Save the column configuration for a content type.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function save_config( WP_REST_Request $request ) {
		$content_type = $this->content_type( $request );
		if ( null === $content_type ) {
			return $this->unknown_type();
		}

		$columns = (array) $request->get_param( 'columns' );
		$stored  = $this->module->save_config( $content_type, $columns );

		return $this->ok(
			array(
				'post_type' => $content_type,
				'columns'   => $stored,
			)
		);
	}

	/**
	 * Validate and resolve the content type from the request.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return string|null
	 */
	private function content_type( WP_REST_Request $request ): ?string {
		$type = sanitize_key( (string) $request->get_param( 'post_type' ) );
		if ( in_array( $type, array( 'users', 'comments', 'attachment' ), true ) ) {
			return $type;
		}
		return post_type_exists( $type ) ? $type : null;
	}

	/**
	 * Error for an unknown content type.
	 */
	private function unknown_type() {
		return new \WP_Error(
			'uxstudio_unknown_content_type',
			__( 'Unknown content type.', 'ux-studio' ),
			array( 'status' => 404 )
		);
	}
}
