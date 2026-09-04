<?php
/**
 * Folder Manager module - organize media library attachments into folders.
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
 * registered on the `attachment` post type. There is NO filesystem
 * involvement whatsoever - no directories are created, renamed or moved on
 * disk. Storage and relationships live entirely in WordPress' own
 * wp_terms / wp_term_taxonomy / wp_term_relationships tables.
 */
final class Module extends BaseModule {

	public const TAXONOMY = 'uxstudio_media_folder';

	/**
	 * Register hooks.
	 */
	public function boot(): void {
		add_action( 'init', array( $this, 'register_taxonomy' ) );
		add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );
	}

	/**
	 * Register the folder taxonomy on the attachment post type. Hierarchical
	 * so folders can be nested; no public UI/REST of its own - this module
	 * exposes its own REST controller and SPA screen instead.
	 */
	public function register_taxonomy(): void {
		register_taxonomy(
			self::TAXONOMY,
			array( 'attachment' ),
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
	 * All folders, shaped for the SPA (flat list with parent ids - the
	 * client builds the tree from parent/id relationships).
	 *
	 * @return array<int, array{id:int,name:string,parent:int,count:int}>
	 */
	public function list_folders(): array {
		$terms = get_terms(
			array(
				'taxonomy'   => self::TAXONOMY,
				'hide_empty' => false,
				'orderby'    => 'name',
				'order'      => 'ASC',
			)
		);

		if ( is_wp_error( $terms ) || ! is_array( $terms ) ) {
			return array();
		}

		return array_map(
			static function ( WP_Term $term ): array {
				return array(
					'id'     => (int) $term->term_id,
					'name'   => $term->name,
					'parent' => (int) $term->parent,
					'count'  => (int) $term->count,
				);
			},
			$terms
		);
	}

	/**
	 * Create a folder term.
	 *
	 * @param string $name   Folder name.
	 * @param int    $parent Optional parent folder id.
	 * @return array{id:int,name:string,parent:int,count:int}|WP_Error
	 */
	public function create_folder( string $name, int $parent = 0 ) {
		$name = sanitize_text_field( $name );
		if ( '' === $name ) {
			return new WP_Error( 'uxstudio_invalid_name', __( 'Folder name is required.', 'ux-studio' ), array( 'status' => 400 ) );
		}

		if ( $parent > 0 && ! get_term( $parent, self::TAXONOMY ) ) {
			return new WP_Error( 'uxstudio_invalid_parent', __( 'Parent folder does not exist.', 'ux-studio' ), array( 'status' => 400 ) );
		}

		$result = wp_insert_term( $name, self::TAXONOMY, array( 'parent' => $parent ) );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$term_id = (int) $result['term_id'];
		ActivityLog::log( 'folder-manager', 'create', 'term', $term_id, array( 'name' => $name, 'parent' => $parent ) );

		$term = get_term( $term_id, self::TAXONOMY );
		if ( is_wp_error( $term ) || ! $term instanceof WP_Term ) {
			return new WP_Error( 'uxstudio_folder_not_found', __( 'Folder was created but could not be reloaded.', 'ux-studio' ), array( 'status' => 500 ) );
		}

		return array(
			'id'     => (int) $term->term_id,
			'name'   => $term->name,
			'parent' => (int) $term->parent,
			'count'  => (int) $term->count,
		);
	}

	/**
	 * Delete a folder term. Attachments in it simply lose the relationship
	 * (WordPress core behaviour of wp_delete_term) - no files are touched.
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
	 * Assign (or clear, when $folder_id is 0) an attachment's folder.
	 *
	 * @param int $attachment_id Attachment post id.
	 * @param int $folder_id     Folder term id, or 0 to clear.
	 * @return true|WP_Error
	 */
	public function assign( int $attachment_id, int $folder_id ) {
		if ( 'attachment' !== get_post_type( $attachment_id ) ) {
			return new WP_Error( 'uxstudio_invalid_attachment', __( 'Attachment not found.', 'ux-studio' ), array( 'status' => 404 ) );
		}

		if ( 0 !== $folder_id ) {
			$term = get_term( $folder_id, self::TAXONOMY );
			if ( is_wp_error( $term ) || ! $term instanceof WP_Term ) {
				return new WP_Error( 'uxstudio_folder_not_found', __( 'Folder not found.', 'ux-studio' ), array( 'status' => 404 ) );
			}
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
	 * @return array{id:int,name:string,parent:int,count:int}|WP_Error
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

		if ( $this->sibling_name_exists( $name, (int) $term->parent, $id ) ) {
			return new WP_Error( 'uxstudio_duplicate_name', __( 'A folder with this name already exists at this level.', 'ux-studio' ), array( 'status' => 400 ) );
		}

		$slug   = wp_unique_term_slug( sanitize_title( $name ), $term );
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
	 * folder's own descendants.
	 *
	 * @param int $id     Folder term id.
	 * @param int $parent New parent folder id, or 0 for top level.
	 * @return array{id:int,name:string,parent:int,count:int}|WP_Error
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
	 * Assign (or clear, when $folder_id is 0) a folder on many attachments at
	 * once. Each attachment is checked individually: it must be an attachment
	 * the current user is allowed to edit. Invalid items are skipped and
	 * reported rather than aborting the whole batch.
	 *
	 * @param array<int, mixed> $attachment_ids Attachment post ids.
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
			if ( 'attachment' !== get_post_type( $att_id ) || ! current_user_can( 'edit_post', $att_id ) ) {
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
	 * Whether a sibling folder (same parent, different id) already uses $name.
	 *
	 * @param string $name      Candidate name.
	 * @param int    $parent    Parent folder id.
	 * @param int    $exclude_id Folder id to ignore (the one being renamed).
	 */
	private function sibling_name_exists( string $name, int $parent, int $exclude_id ): bool {
		$siblings = get_terms(
			array(
				'taxonomy'   => self::TAXONOMY,
				'hide_empty' => false,
				'parent'     => $parent,
				'exclude'    => array( $exclude_id ),
			)
		);

		if ( is_wp_error( $siblings ) || ! is_array( $siblings ) ) {
			return false;
		}

		foreach ( $siblings as $sibling ) {
			if ( $sibling instanceof WP_Term && 0 === strcasecmp( $sibling->name, $name ) ) {
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
	 * Reload a folder term and shape it for the SPA response.
	 *
	 * @param int $id Folder term id.
	 * @return array{id:int,name:string,parent:int,count:int}|WP_Error
	 */
	private function reload_folder( int $id ) {
		$term = get_term( $id, self::TAXONOMY );
		if ( is_wp_error( $term ) || ! $term instanceof WP_Term ) {
			return new WP_Error( 'uxstudio_folder_not_found', __( 'Folder could not be reloaded.', 'ux-studio' ), array( 'status' => 500 ) );
		}

		return array(
			'id'     => (int) $term->term_id,
			'name'   => $term->name,
			'parent' => (int) $term->parent,
			'count'  => (int) $term->count,
		);
	}
}
