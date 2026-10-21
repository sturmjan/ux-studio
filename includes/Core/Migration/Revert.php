<?php
/**
 * Undo of migrated objects that do not live in a table the snapshot copies.
 *
 * @package UxStudio
 */

namespace UxStudio\Core\Migration;

defined( 'ABSPATH' ) || exit;

/**
 * Tables of UX Studio itself (redirects, snippets, e-mail log) and options come back from
 * the snapshot. Objects in WordPress core tables (folder terms, post and user meta) and files
 * (snippet code) are removed one by one: the migration records every object it CREATED in
 * the mapping table (target type + id), and the rollback walks those rows of the run.
 *
 * Only created objects are ever recorded, never objects that already existed, so a rollback
 * cannot remove data it did not add.
 *
 * Mapping row types handled here (`target_id` format in brackets):
 *  folder_term  [term id]            term of the Folder Manager taxonomy
 *  folder_rel   [object id|term id]  assignment of an item to an older folder
 *  postmeta     [post id|meta key]   post meta added by the migration
 *  usermeta     [user id|meta key]   user meta added by the migration
 *  cron_event   [timestamp|hook|post id] single cron event scheduled for a post
 *  snippet_file [file name]          code file in wp-content/uxstudio-snippets
 */
final class Revert {

	/** Folder Manager taxonomy (kept in sync with FolderManager\Module::TAXONOMY, checked by the tests). */
	public const FOLDER_TAXONOMY = 'uxstudio_media_folder';

	/**
	 * Undo what a run created outside the snapshot.
	 *
	 * @param string $run_id Run id.
	 * @return int Number of objects removed.
	 */
	public static function run( string $run_id ): int {
		$count = 0;
		foreach ( MigrationMap::rows_of_run( $run_id ) as $row ) {
			if ( self::one( (string) $row['target_type'], (string) $row['target_id'] ) ) {
				++$count;
			}
		}
		return $count;
	}

	/**
	 * Undo one object.
	 *
	 * @param string $type Mapping row type.
	 * @param string $id   Mapping row target id.
	 */
	public static function one( string $type, string $id ): bool {
		$parts = explode( '|', $id, 3 );
		switch ( $type ) {
			case 'folder_term':
				if ( function_exists( 'wp_delete_term' ) && (int) $id > 0 ) {
					self::ensure_folder_taxonomy();
					return true === wp_delete_term( (int) $id, self::FOLDER_TAXONOMY );
				}
				return false;
			case 'folder_rel':
				if ( function_exists( 'wp_remove_object_terms' ) && 2 === count( $parts ) ) {
					self::ensure_folder_taxonomy();
					return true === wp_remove_object_terms( (int) $parts[0], (int) $parts[1], self::FOLDER_TAXONOMY );
				}
				return false;
			case 'postmeta':
				if ( function_exists( 'delete_post_meta' ) && 2 === count( $parts ) && '' !== $parts[1] ) {
					return delete_post_meta( (int) $parts[0], $parts[1] );
				}
				return false;
			case 'usermeta':
				if ( function_exists( 'delete_user_meta' ) && 2 === count( $parts ) && '' !== $parts[1] ) {
					return delete_user_meta( (int) $parts[0], $parts[1] );
				}
				return false;
			case 'cron_event':
				if ( function_exists( 'wp_unschedule_event' ) && 3 === count( $parts ) ) {
					return false !== wp_unschedule_event( (int) $parts[0], $parts[1], array( (int) $parts[2] ) );
				}
				return false;
			case 'snippet_file':
				return self::delete_snippet_file( $id );
		}
		return false;
	}

	/**
	 * The Folder Manager taxonomy only exists while its module is on; register it for the
	 * duration of the call so terms can be removed with the term API.
	 */
	public static function ensure_folder_taxonomy(): void {
		if ( ! function_exists( 'taxonomy_exists' ) || taxonomy_exists( self::FOLDER_TAXONOMY ) ) {
			return;
		}
		$module = class_exists( '\UxStudio\Plugin' ) && isset( \UxStudio\Plugin::instance()->modules ) ? \UxStudio\Plugin::instance()->modules->instance( 'folder-manager' ) : null;
		if ( $module && method_exists( $module, 'register_taxonomy' ) ) {
			$module->register_taxonomy();
		}
	}

	/**
	 * Remove a snippet code file the migration wrote (the name is confined to the snippets folder).
	 *
	 * @param string $name File name.
	 */
	private static function delete_snippet_file( string $name ): bool {
		if ( ! defined( 'WP_CONTENT_DIR' ) || '' === $name || $name !== basename( $name ) || ! preg_match( '/^[a-z0-9-]+\.php$/i', $name ) ) {
			return false;
		}
		$path = WP_CONTENT_DIR . '/uxstudio-snippets/' . $name;
		return is_file( $path ) && unlink( $path );
	}
}
