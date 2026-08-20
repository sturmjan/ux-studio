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
}
