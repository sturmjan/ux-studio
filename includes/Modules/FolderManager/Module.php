<?php
/**
 * Folder Manager module - organize media library attachments (and, optionally,
 * posts / pages / custom post types) into folders.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\FolderManager;

use UxStudio\Core\ActivityLog;
use UxStudio\Modules\BaseModule;
use WP_Error;
use WP_Term;

defined( 'ABSPATH' ) || exit;

/**
 * Deliberate simplification vs the legacy module: folders are implemented
 * purely as a custom hierarchical taxonomy (`uxstudio_media_folder`)
 * registered on the `attachment` post type (plus the post types enabled in the
 * settings). There is NO filesystem involvement whatsoever - no directories
 * are created, renamed or moved on disk. Storage and relationships live
 * entirely in WordPress' own wp_terms / wp_term_taxonomy /
 * wp_term_relationships tables.
 *
 * Like in the legacy module every folder belongs to exactly one post type
 * (term meta `uxstudio_folder_post_type`; folders without the meta are media
 * folders), so the media tree and e.g. the "Pages" tree are independent.
 */
final class Module extends BaseModule {

	public const TAXONOMY = 'uxstudio_media_folder';

	/** Term meta holding the post type a folder belongs to. */
	public const POST_TYPE_META = 'uxstudio_folder_post_type';

	/** Query var / request parameter used to filter lists by folder. */
	public const FILTER_VAR = 'uxstudio_folder';

	/**
	 * Register hooks.
	 */
	public function boot(): void {
		add_action( 'init', array( $this, 'register_taxonomy' ), 20 );
		add_action( 'init', array( $this, 'maybe_migrate_legacy_folders' ), 30 );
		add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );

		if ( is_admin() ) {
			( new AdminIntegration( $this ) )->register();
		}
	}

	/** Set once the legacy `wpext_folder` terms were copied into TAXONOMY. */
	private const LEGACY_MIGRATED_OPTION = 'uxstudio_folders_legacy_migrated';

	/**
	 * One-time copy of the legacy plugin's folders (taxonomy `wpext_folder`,
	 * term meta `wpext_folder_post_type`) incl. hierarchy and item assignments.
	 * The legacy taxonomy is not registered any more, so it is read with SQL;
	 * writes go through the term API of the registered TAXONOMY. Idempotent:
	 * existing folders with the same name/parent are reused.
	 */
	public function maybe_migrate_legacy_folders(): void {
		if ( get_option( self::LEGACY_MIGRATED_OPTION ) || ! taxonomy_exists( self::TAXONOMY ) ) {
			return;
		}
		global $wpdb;

		$legacy = $wpdb->get_results(
			"SELECT tt.term_taxonomy_id, tt.term_id, tt.parent, t.name, t.slug, tm.meta_value AS post_type
			 FROM {$wpdb->term_taxonomy} tt
			 JOIN {$wpdb->terms} t ON t.term_id = tt.term_id
			 LEFT JOIN {$wpdb->termmeta} tm ON tm.term_id = tt.term_id AND tm.meta_key = 'wpext_folder_post_type'
			 WHERE tt.taxonomy = 'wpext_folder'",
			ARRAY_A
		);

		$map     = array(); // legacy term_id => new term_id.
		$pending = is_array( $legacy ) ? $legacy : array();
		// Parents first: repeat passes until every folder whose parent is mapped is created.
		for ( $pass = 0; $pass < 20 && ! empty( $pending ); $pass++ ) {
			foreach ( $pending as $i => $row ) {
				$parent = (int) $row['parent'];
				if ( $parent && ! isset( $map[ $parent ] ) ) {
					continue;
				}
				$created = wp_insert_term(
					$row['name'],
					self::TAXONOMY,
					array( 'parent' => $parent ? $map[ $parent ] : 0 )
				);
				if ( is_wp_error( $created ) ) {
					$existing = $created->get_error_data( 'term_exists' );
					if ( ! $existing ) {
						unset( $pending[ $i ] );
						continue;
					}
					$new_id = (int) ( is_array( $existing ) ? reset( $existing ) : $existing );
				} else {
					$new_id = (int) $created['term_id'];
				}
				$map[ (int) $row['term_id'] ] = $new_id;
				update_term_meta( $new_id, self::POST_TYPE_META, $row['post_type'] ? sanitize_key( $row['post_type'] ) : 'attachment' );

				$objects = $wpdb->get_col( $wpdb->prepare( "SELECT object_id FROM {$wpdb->term_relationships} WHERE term_taxonomy_id = %d", (int) $row['term_taxonomy_id'] ) );
				foreach ( $objects as $object_id ) {
					wp_set_object_terms( (int) $object_id, $new_id, self::TAXONOMY, true );
				}
				unset( $pending[ $i ] );
			}
		}

		update_option( self::LEGACY_MIGRATED_OPTION, gmdate( 'c' ), true );
	}

	/**
	 * Settings schema (keys shared with the legacy module).
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function settings_schema(): array {
		return array(
			array(
				'key'     => 'post_types',
				'type'    => 'multiselect',
				'label'   => __( 'Post types', 'ux-studio' ),
				'help'    => __( 'Select which post types get a folder tree. Each post type has its own, independent folders.', 'ux-studio' ),
				'options' => $this->available_post_types(),
				'default' => array( 'attachment' ),
			),
			array(
				'key'     => 'show_folder_counts',
				'type'    => 'toggle',
				'label'   => __( 'Show folder counts', 'ux-studio' ),
				'help'    => __( 'Display item counts next to folder names.', 'ux-studio' ),
				'default' => true,
			),
		);
	}

	/**
	 * Post types that can be switched on (label by slug).
	 *
	 * @return array<string, string>
	 */
	public function available_post_types(): array {
		$choices = array();
		foreach ( get_post_types( array( 'public' => true, 'show_ui' => true ), 'objects' ) as $type ) {
			$choices[ $type->name ] = $type->labels->name;
		}
		$choices['attachment'] = __( 'Media Library', 'ux-studio' );

		return $choices;
	}

	/**
	 * Post types the folder tree is enabled for (setting `post_types`,
	 * default: Media Library only).
	 *
	 * @return array<int, string>
	 */
	public function enabled_post_types(): array {
		$saved = $this->settings->get( 'post_types', array( 'attachment' ) );
		if ( ! is_array( $saved ) || empty( $saved ) ) {
			$saved = array( 'attachment' );
		}

		$types = array();
		foreach ( $saved as $type ) {
			$type = sanitize_key( (string) $type );
			if ( '' !== $type && ( 'attachment' === $type || post_type_exists( $type ) ) ) {
				$types[ $type ] = $type;
			}
		}

		return array_values( $types );
	}

	/**
	 * Whether folders are enabled for a post type.
	 *
	 * @param string $post_type Post type slug.
	 */
	public function is_enabled( string $post_type ): bool {
		return in_array( $post_type, $this->enabled_post_types(), true );
	}

	/**
	 * Whether item counts should be shown in the folder tree.
	 */
	public function show_counts(): bool {
		return (bool) $this->settings->get( 'show_folder_counts', true );
	}

	/**
	 * Register the folder taxonomy on the attachment post type and the enabled
	 * post types. Hierarchical so folders can be nested; no public UI/REST of
	 * its own - this module exposes its own REST controller, SPA screen and
	 * media-library sidebar instead.
	 */
	public function register_taxonomy(): void {
		$object_types = array_values( array_unique( array_merge( array( 'attachment' ), $this->enabled_post_types() ) ) );

		register_taxonomy(
			self::TAXONOMY,
			$object_types,
			array(
				'hierarchical'      => true,
				'public'            => false,
				'show_ui'           => false,
				'show_admin_column' => false,
				'show_in_nav_menus' => false,
				'show_tagcloud'     => false,
				'show_in_rest'      => false,
				'rewrite'           => false,
				'labels'            => array(
					'name'              => __( 'Folders', 'ux-studio' ),
					'singular_name'     => __( 'Folder', 'ux-studio' ),
					'search_items'      => __( 'Search Folders', 'ux-studio' ),
					'all_items'         => __( 'All Folders', 'ux-studio' ),
					'parent_item'       => __( 'Parent Folder', 'ux-studio' ),
					'parent_item_colon' => __( 'Parent Folder:', 'ux-studio' ),
					'edit_item'         => __( 'Edit Folder', 'ux-studio' ),
					'update_item'       => __( 'Update Folder', 'ux-studio' ),
					'add_new_item'      => __( 'Add New Folder', 'ux-studio' ),
					'new_item_name'     => __( 'New Folder Name', 'ux-studio' ),
					'not_found'         => __( 'No folders found', 'ux-studio' ),
				),
			)
		);
	}

	/**
	 * Register the module REST controller.
	 */
	public function register_rest_routes(): void {
		( new RestController( $this ) )->register_routes();
	}

	/**
	 * REST controller class.
	 */
	public function rest_controller(): ?string {
		return RestController::class;
	}

	/**
	 * Post type a folder belongs to (folders created before per-post-type
	 * folders existed have no meta and are media folders).
	 *
	 * @param int $term_id Folder term id.
	 */
	public function folder_post_type( int $term_id ): string {
		$type = (string) get_term_meta( $term_id, self::POST_TYPE_META, true );

		return '' === $type ? 'attachment' : $type;
	}

	/**
	 * Post statuses that count as "an item" for a post type.
	 *
	 * @param string $post_type Post type slug.
	 * @return array<int, string>
	 */
	public static function counted_statuses( string $post_type ): array {
		return 'attachment' === $post_type
			? array( 'inherit' )
			: array( 'publish', 'pending', 'draft', 'private', 'future' );
	}

	/**
	 * Item counts for one post type: direct items per folder (children are not
	 * rolled up), items without any folder and the total. One query each, no
	 * per-folder loops.
	 *
	 * @param string $post_type Post type slug.
	 * @return array{folders:array<int,int>,uncategorized:int,total:int}
	 */
	public function counts_for( string $post_type ): array {
		global $wpdb;

		$statuses     = self::counted_statuses( $post_type );
		$placeholders = implode( ',', array_fill( 0, count( $statuses ), '%s' ) );

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT tt.term_id AS term_id, COUNT(DISTINCT p.ID) AS cnt
				FROM {$wpdb->term_relationships} tr
				INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id AND tt.taxonomy = %s
				INNER JOIN {$wpdb->posts} p ON p.ID = tr.object_id AND p.post_type = %s AND p.post_status IN ({$placeholders})
				GROUP BY tt.term_id", // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
				array_merge( array( self::TAXONOMY, $post_type ), $statuses )
			)
		);

		$by_folder = array();
		foreach ( (array) $rows as $row ) {
			$by_folder[ (int) $row->term_id ] = (int) $row->cnt;
		}

		$total = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->posts} p WHERE p.post_type = %s AND p.post_status IN ({$placeholders})", // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
				array_merge( array( $post_type ), $statuses )
			)
		);

		$uncategorized = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->posts} p
				WHERE p.post_type = %s AND p.post_status IN ({$placeholders})
				AND NOT EXISTS (
					SELECT 1 FROM {$wpdb->term_relationships} tr
					INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
					WHERE tr.object_id = p.ID AND tt.taxonomy = %s
				)", // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
				array_merge( array( $post_type ), $statuses, array( self::TAXONOMY ) )
			)
		);

		return array(
			'folders'       => $by_folder,
			'uncategorized' => $uncategorized,
			'total'         => $total,
		);
	}

	/**
	 * Everything the folder sidebar needs for one post type.
	 *
	 * @param string $post_type Post type slug.
	 * @return array{folders:array<int,array{id:int,name:string,parent:int,count:int,post_type:string}>,uncategorized:int,total:int}
	 */
	public function overview( string $post_type ): array {
		$counts = $this->counts_for( $post_type );

		return array(
			'folders'       => $this->list_folders( $post_type, $counts ),
			'uncategorized' => $counts['uncategorized'],
			'total'         => $counts['total'],
		);
	}

	/**
	 * Folder terms (optionally restricted to one post type).
	 *
	 * @param string|null $post_type Post type slug, null = all folders.
	 * @return array<int, WP_Term>
	 */
	public function folder_terms( ?string $post_type = null ): array {
		$args = array(
			'taxonomy'   => self::TAXONOMY,
			'hide_empty' => false,
			'orderby'    => 'name',
			'order'      => 'ASC',
		);

		if ( null !== $post_type ) {
			if ( 'attachment' === $post_type ) {
				$args['meta_query'] = array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					'relation' => 'OR',
					array(
						'key'   => self::POST_TYPE_META,
						'value' => 'attachment',
					),
					array(
						'key'     => self::POST_TYPE_META,
						'compare' => 'NOT EXISTS',
					),
				);
			} else {
				$args['meta_query'] = array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'key'   => self::POST_TYPE_META,
						'value' => $post_type,
					),
				);
			}
		}

		$terms = get_terms( $args );
		if ( is_wp_error( $terms ) || ! is_array( $terms ) ) {
			return array();
		}

		return array_values( array_filter( $terms, static fn ( $term ): bool => $term instanceof WP_Term ) );
	}

	/**
	 * Folders, shaped for the SPA (flat list with parent ids - the client
	 * builds the tree from parent/id relationships).
	 *
	 * @param string|null                                                      $post_type Restrict to one post type, null = all.
	 * @param array{folders:array<int,int>,uncategorized:int,total:int}|null $counts    Pre-computed counts (single post type only).
	 * @return array<int, array{id:int,name:string,parent:int,count:int,post_type:string}>
	 */
	public function list_folders( ?string $post_type = null, ?array $counts = null ): array {
		$counts_by_type = array();
		if ( null !== $post_type && null !== $counts ) {
			$counts_by_type[ $post_type ] = $counts;
		}

		$out = array();
		foreach ( $this->folder_terms( $post_type ) as $term ) {
			$type = $this->folder_post_type( (int) $term->term_id );
			if ( ! isset( $counts_by_type[ $type ] ) ) {
				$counts_by_type[ $type ] = $this->counts_for( $type );
			}
			$out[] = $this->shape( $term, $counts_by_type[ $type ]['folders'][ (int) $term->term_id ] ?? 0, $type );
		}

		return $out;
	}

	/**
	 * Create a folder term.
	 *
	 * @param string $name      Folder name.
	 * @param int    $parent    Optional parent folder id.
	 * @param string $post_type Post type the folder belongs to.
	 * @return array{id:int,name:string,parent:int,count:int,post_type:string}|WP_Error
	 */
	public function create_folder( string $name, int $parent = 0, string $post_type = 'attachment' ) {
		$name = sanitize_text_field( $name );
		if ( '' === $name ) {
			return new WP_Error( 'uxstudio_invalid_name', __( 'Folder name is required.', 'ux-studio' ), array( 'status' => 400 ) );
		}

		if ( mb_strlen( $name ) > 200 ) {
			return new WP_Error( 'uxstudio_name_too_long', __( 'Folder name cannot exceed 200 characters.', 'ux-studio' ), array( 'status' => 400 ) );
		}

		if ( ! $this->is_enabled( $post_type ) ) {
			return new WP_Error( 'uxstudio_post_type_disabled', __( 'Folders are not enabled for this post type.', 'ux-studio' ), array( 'status' => 400 ) );
		}

		if ( $parent > 0 ) {
			$parent_term = get_term( $parent, self::TAXONOMY );
			if ( is_wp_error( $parent_term ) || ! $parent_term instanceof WP_Term ) {
				return new WP_Error( 'uxstudio_invalid_parent', __( 'Parent folder does not exist.', 'ux-studio' ), array( 'status' => 400 ) );
			}
			if ( $this->folder_post_type( $parent ) !== $post_type ) {
				return new WP_Error( 'uxstudio_invalid_parent', __( 'Parent folder must belong to the same post type.', 'ux-studio' ), array( 'status' => 400 ) );
			}
		}

		if ( $this->sibling_name_exists( $name, $parent, 0, $post_type ) ) {
			return new WP_Error( 'uxstudio_duplicate_name', __( 'A folder with this name already exists at this level.', 'ux-studio' ), array( 'status' => 400 ) );
		}

		$result = wp_insert_term(
			$name,
			self::TAXONOMY,
			array(
				'parent' => $parent,
				'slug'   => $this->folder_slug( $name, $post_type ),
			)
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$term_id = (int) $result['term_id'];
		update_term_meta( $term_id, self::POST_TYPE_META, $post_type );
		ActivityLog::log( 'folder-manager', 'create', 'term', $term_id, array( 'name' => $name, 'parent' => $parent, 'post_type' => $post_type ) );

		return $this->reload_folder( $term_id );
	}

	/**
	 * Delete a folder term. Items in it simply lose the relationship
	 * (WordPress core behaviour of wp_delete_term, child folders move up one
	 * level) - no files are touched.
	 *
	 * @param int $id Folder term id.
	 * @return true|WP_Error
	 */
	public function delete_folder( int $id ) {
		$term = get_term( $id, self::TAXONOMY );
		if ( is_wp_error( $term ) || ! $term instanceof WP_Term ) {
			return new WP_Error( 'uxstudio_folder_not_found', __( 'Folder not found.', 'ux-studio' ), array( 'status' => 404 ) );
		}

		$deleted = wp_delete_term( $id, self::TAXONOMY );
		if ( is_wp_error( $deleted ) || false === $deleted ) {
			return new WP_Error( 'uxstudio_delete_failed', __( 'Could not delete folder.', 'ux-studio' ), array( 'status' => 500 ) );
		}

		ActivityLog::log( 'folder-manager', 'delete', 'term', $id, array( 'name' => $term->name ) );

		return true;
	}

	/**
	 * Assign (or clear, when $folder_id is 0) an item's folder.
	 *
	 * @param int $attachment_id Post id (attachment or any enabled post type).
	 * @param int $folder_id     Folder term id, or 0 to clear.
	 * @return true|WP_Error
	 */
	public function assign( int $attachment_id, int $folder_id ) {
		$check = $this->check_item( $attachment_id, $folder_id );
		if ( is_wp_error( $check ) ) {
			return $check;
		}

		$terms  = 0 === $folder_id ? array() : array( $folder_id );
		$result = wp_set_object_terms( $attachment_id, $terms, self::TAXONOMY );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		ActivityLog::log( 'folder-manager', 'assign', 'attachment', $attachment_id, array( 'folder_id' => $folder_id ) );

		return true;
	}

	/**
	 * Rename a folder term. Updates both the display name and the slug (kept in
	 * sync, guaranteed unique within the taxonomy). Guards against duplicate
	 * names among siblings sharing the same parent.
	 *
	 * @param int    $id   Folder term id.
	 * @param string $name New folder name.
	 * @return array{id:int,name:string,parent:int,count:int,post_type:string}|WP_Error
	 */
	public function rename_folder( int $id, string $name ) {
		$term = get_term( $id, self::TAXONOMY );
		if ( is_wp_error( $term ) || ! $term instanceof WP_Term ) {
			return new WP_Error( 'uxstudio_folder_not_found', __( 'Folder not found.', 'ux-studio' ), array( 'status' => 404 ) );
		}

		$name = sanitize_text_field( $name );
		if ( '' === $name ) {
			return new WP_Error( 'uxstudio_invalid_name', __( 'Folder name is required.', 'ux-studio' ), array( 'status' => 400 ) );
		}

		if ( mb_strlen( $name ) > 200 ) {
			return new WP_Error( 'uxstudio_name_too_long', __( 'Folder name cannot exceed 200 characters.', 'ux-studio' ), array( 'status' => 400 ) );
		}

		$post_type = $this->folder_post_type( $id );
		if ( $this->sibling_name_exists( $name, (int) $term->parent, $id, $post_type ) ) {
			return new WP_Error( 'uxstudio_duplicate_name', __( 'A folder with this name already exists at this level.', 'ux-studio' ), array( 'status' => 400 ) );
		}

		$slug   = wp_unique_term_slug( $this->folder_slug( $name, $post_type ), $term );
		$result = wp_update_term(
			$id,
			self::TAXONOMY,
			array(
				'name' => $name,
				'slug' => $slug,
			)
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		ActivityLog::log( 'folder-manager', 'rename', 'term', $id, array( 'name' => $name ) );

		return $this->reload_folder( $id );
	}

	/**
	 * Move a folder under a new parent (0 = top level). Prevents a folder from
	 * becoming its own parent and any cycle where the new parent is one of the
	 * folder's own descendants. The parent must belong to the same post type.
	 *
	 * @param int $id     Folder term id.
	 * @param int $parent New parent folder id, or 0 for top level.
	 * @return array{id:int,name:string,parent:int,count:int,post_type:string}|WP_Error
	 */
	public function reparent_folder( int $id, int $parent ) {
		$term = get_term( $id, self::TAXONOMY );
		if ( is_wp_error( $term ) || ! $term instanceof WP_Term ) {
			return new WP_Error( 'uxstudio_folder_not_found', __( 'Folder not found.', 'ux-studio' ), array( 'status' => 404 ) );
		}

		if ( $parent > 0 ) {
			if ( $parent === $id ) {
				return new WP_Error( 'uxstudio_invalid_parent', __( 'A folder cannot be its own parent.', 'ux-studio' ), array( 'status' => 400 ) );
			}

			$parent_term = get_term( $parent, self::TAXONOMY );
			if ( is_wp_error( $parent_term ) || ! $parent_term instanceof WP_Term ) {
				return new WP_Error( 'uxstudio_invalid_parent', __( 'Parent folder does not exist.', 'ux-studio' ), array( 'status' => 400 ) );
			}

			if ( $this->folder_post_type( $parent ) !== $this->folder_post_type( $id ) ) {
				return new WP_Error( 'uxstudio_invalid_parent', __( 'Parent folder must belong to the same post type.', 'ux-studio' ), array( 'status' => 400 ) );
			}

			if ( $this->is_descendant( $parent, $id ) ) {
				return new WP_Error( 'uxstudio_circular_reference', __( 'Cannot move folder: would create a circular reference.', 'ux-studio' ), array( 'status' => 400 ) );
			}
		}

		$result = wp_update_term( $id, self::TAXONOMY, array( 'parent' => max( 0, $parent ) ) );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		ActivityLog::log( 'folder-manager', 'reparent', 'term', $id, array( 'parent' => max( 0, $parent ) ) );

		return $this->reload_folder( $id );
	}

	/**
	 * Assign (or clear, when $folder_id is 0) a folder on many items at once.
	 * Each item is checked individually: it must be an item the current user is
	 * allowed to edit, of a post type the folder belongs to. Invalid items are
	 * skipped and reported rather than aborting the whole batch.
	 *
	 * @param array<int, mixed> $attachment_ids Post ids.
	 * @param int               $folder_id      Folder term id, or 0 to clear.
	 * @return array{updated:int,skipped:array<int, int>,folder_id:int}|WP_Error
	 */
	public function bulk_move( array $attachment_ids, int $folder_id ) {
		if ( 0 !== $folder_id ) {
			$term = get_term( $folder_id, self::TAXONOMY );
			if ( is_wp_error( $term ) || ! $term instanceof WP_Term ) {
				return new WP_Error( 'uxstudio_folder_not_found', __( 'Folder not found.', 'ux-studio' ), array( 'status' => 404 ) );
			}
		}

		$ids = array();
		foreach ( $attachment_ids as $raw_id ) {
			$att_id = absint( $raw_id );
			if ( $att_id > 0 ) {
				$ids[ $att_id ] = $att_id;
			}
		}

		if ( empty( $ids ) ) {
			return new WP_Error( 'uxstudio_no_items', __( 'No valid attachment IDs provided.', 'ux-studio' ), array( 'status' => 400 ) );
		}

		$terms   = 0 === $folder_id ? array() : array( $folder_id );
		$updated = 0;
		$skipped = array();

		foreach ( $ids as $att_id ) {
			if ( is_wp_error( $this->check_item( $att_id, $folder_id ) ) ) {
				$skipped[] = $att_id;
				continue;
			}

			$result = wp_set_object_terms( $att_id, $terms, self::TAXONOMY );
			if ( is_wp_error( $result ) ) {
				$skipped[] = $att_id;
				continue;
			}

			++$updated;
		}

		if ( 0 === $updated ) {
			return new WP_Error(
				'uxstudio_no_items_updated',
				__( 'No attachments were moved. Check the IDs and your permissions.', 'ux-studio' ),
				array(
					'status'  => 400,
					'skipped' => array_values( $skipped ),
				)
			);
		}

		ActivityLog::log(
			'folder-manager',
			'bulk-move',
			'attachment',
			0,
			array(
				'folder_id' => $folder_id,
				'updated'   => $updated,
				'skipped'   => array_values( $skipped ),
			)
		);

		return array(
			'updated'   => $updated,
			'skipped'   => array_values( $skipped ),
			'folder_id' => $folder_id,
		);
	}

	/**
	 * Whether the current user may put $post_id into $folder_id (0 = clear):
	 * the post exists, its type has folders enabled, the user can edit it and
	 * the folder belongs to the same post type.
	 *
	 * @param int $post_id   Post id.
	 * @param int $folder_id Folder term id, 0 = clear.
	 * @return true|WP_Error
	 */
	public function check_item( int $post_id, int $folder_id ) {
		$post = get_post( $post_id );
		if ( ! $post instanceof \WP_Post || ! $this->is_enabled( $post->post_type ) ) {
			return new WP_Error( 'uxstudio_invalid_attachment', __( 'Attachment not found.', 'ux-studio' ), array( 'status' => 404 ) );
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return new WP_Error( 'uxstudio_forbidden', __( 'You are not allowed to edit this item.', 'ux-studio' ), array( 'status' => 403 ) );
		}

		if ( 0 !== $folder_id ) {
			$term = get_term( $folder_id, self::TAXONOMY );
			if ( is_wp_error( $term ) || ! $term instanceof WP_Term ) {
				return new WP_Error( 'uxstudio_folder_not_found', __( 'Folder not found.', 'ux-studio' ), array( 'status' => 404 ) );
			}
			if ( $this->folder_post_type( $folder_id ) !== $post->post_type ) {
				return new WP_Error( 'uxstudio_folder_type_mismatch', __( 'Selected folder does not belong to this post type.', 'ux-studio' ), array( 'status' => 400 ) );
			}
		}

		return true;
	}

	/**
	 * Slug for a folder. Non-media folders get the post type appended so equal
	 * names in different post types never collide on the unique slug.
	 *
	 * @param string $name      Folder name.
	 * @param string $post_type Post type slug.
	 */
	private function folder_slug( string $name, string $post_type ): string {
		$slug = sanitize_title( $name );
		if ( '' === $slug ) {
			$slug = 'folder';
		}

		return 'attachment' === $post_type ? $slug : $slug . '-' . $post_type;
	}

	/**
	 * Whether a sibling folder (same parent and post type, different id) already
	 * uses $name.
	 *
	 * @param string $name       Candidate name.
	 * @param int    $parent     Parent folder id.
	 * @param int    $exclude_id Folder id to ignore (the one being renamed), 0 = none.
	 * @param string $post_type  Post type slug.
	 */
	private function sibling_name_exists( string $name, int $parent, int $exclude_id, string $post_type ): bool {
		$args = array(
			'taxonomy'   => self::TAXONOMY,
			'hide_empty' => false,
			'parent'     => $parent,
		);
		if ( $exclude_id > 0 ) {
			$args['exclude'] = array( $exclude_id );
		}

		$siblings = get_terms( $args );
		if ( is_wp_error( $siblings ) || ! is_array( $siblings ) ) {
			return false;
		}

		foreach ( $siblings as $sibling ) {
			if (
				$sibling instanceof WP_Term
				&& 0 === strcasecmp( $sibling->name, $name )
				&& $this->folder_post_type( (int) $sibling->term_id ) === $post_type
			) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether $candidate_id is $ancestor_id itself or one of its descendants,
	 * walking up the parent chain from $candidate_id.
	 *
	 * @param int $candidate_id Proposed new parent.
	 * @param int $ancestor_id  Folder being moved.
	 */
	private function is_descendant( int $candidate_id, int $ancestor_id ): bool {
		$current = $candidate_id;
		$guard   = 0;

		while ( $current > 0 && $guard < 1000 ) {
			if ( $current === $ancestor_id ) {
				return true;
			}
			$parent_term = get_term( $current, self::TAXONOMY );
			if ( is_wp_error( $parent_term ) || ! $parent_term instanceof WP_Term ) {
				break;
			}
			$current = (int) $parent_term->parent;
			++$guard;
		}

		return false;
	}

	/**
	 * Shape a term for the SPA / sidebar.
	 *
	 * @param WP_Term $term      Folder term.
	 * @param int     $count     Direct item count.
	 * @param string  $post_type Post type slug.
	 * @return array{id:int,name:string,parent:int,count:int,post_type:string}
	 */
	private function shape( WP_Term $term, int $count, string $post_type ): array {
		return array(
			'id'        => (int) $term->term_id,
			'name'      => $term->name,
			'parent'    => (int) $term->parent,
			'count'     => $count,
			'post_type' => $post_type,
		);
	}

	/**
	 * Reload a folder term and shape it for the SPA response.
	 *
	 * @param int $id Folder term id.
	 * @return array{id:int,name:string,parent:int,count:int,post_type:string}|WP_Error
	 */
	private function reload_folder( int $id ) {
		$term = get_term( $id, self::TAXONOMY );
		if ( is_wp_error( $term ) || ! $term instanceof WP_Term ) {
			return new WP_Error( 'uxstudio_folder_not_found', __( 'Folder could not be reloaded.', 'ux-studio' ), array( 'status' => 500 ) );
		}

		$type   = $this->folder_post_type( $id );
		$counts = $this->counts_for( $type );

		return $this->shape( $term, $counts['folders'][ $id ] ?? 0, $type );
	}
}
