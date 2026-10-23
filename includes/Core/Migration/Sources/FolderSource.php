<?php
/**
 * Source: FileBird, Folders, Real Media Library, Wicked Folders.
 *
 * @package UxStudio
 */

namespace UxStudio\Core\Migration\Sources;

use UxStudio\Core\Migration\AbstractSource;
use UxStudio\Core\Migration\Mapping;
use UxStudio\Core\Migration\MigrationMap;
use UxStudio\Core\Migration\Revert;

defined( 'ABSPATH' ) || exit;

/**
 * Target module `folder-manager`: folders are terms of the hierarchical taxonomy
 * `uxstudio_media_folder` (term meta `uxstudio_folder_post_type` = `attachment`), an item sits in
 * a folder through a term relationship. No files are moved.
 *
 * HARMLESS (written directly, also when the module is on): folders only organise the media
 * library. Idempotent: a folder with the same name under the same parent is reused, an
 * attachment already in a UX Studio folder stays where it is.
 *
 * Reading (only attachments are migrated, the module keeps one tree per post type):
 *  - FileBird: tables `fbv` (type 0 = folder) and `fbv_attachment_folder`.
 *  - Folders: taxonomy `media_folder` (SQL on the term tables).
 *  - Real Media Library: tables `realmedialibrary` (parent -1 = root, type 0 = folder, 1 = collection)
 *    and `realmedialibrary_posts` (shortcuts are not copied).
 *  - Wicked Folders: taxonomy `wf_attachment_folders`.
 */
final class FolderSource extends AbstractSource {

	/** Term meta that tells which post type a folder belongs to (FolderManager\Module::POST_TYPE_META). */
	private const POST_TYPE_META = 'uxstudio_folder_post_type';

	/** @var array{folders:array<int, array<string, mixed>>,items:int,skipped:array<string, int>}|null */
	private ?array $collected = null;

	/** Foreign taxonomy of the term based plugins. */
	private function foreign_taxonomy(): string {
		return 'wicked-folders' === $this->slug ? 'wf_attachment_folders' : 'media_folder';
	}

	protected function changes_behavior(): bool {
		return false;
	}

	protected function has_data(): bool {
		return count( $this->collect()['folders'] ) > 0;
	}

	/**
	 * Whether a table exists.
	 *
	 * @param string $table Full table name.
	 */
	private function table_exists( string $table ): bool {
		global $wpdb;
		return $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ); // phpcs:ignore WordPress.DB
	}

	/**
	 * All folders of the foreign plugin, parents before children, and what is left out.
	 *
	 * @return array{folders:array<int, array<string, mixed>>,items:int,skipped:array<string, int>}
	 */
	private function collect(): array {
		if ( null !== $this->collected ) {
			return $this->collected;
		}
		global $wpdb;
		$out  = array(
			'folders' => array(),
			'items'   => 0,
			'skipped' => array(),
		);
		$flat = array();
		switch ( $this->slug ) {
			case 'filebird':
				if ( $this->table_exists( $wpdb->prefix . 'fbv' ) ) {
					foreach ( (array) $wpdb->get_results( "SELECT id, name, parent, type FROM {$wpdb->prefix}fbv ORDER BY id ASC", ARRAY_A ) as $row ) { // phpcs:ignore WordPress.DB
						if ( 0 !== (int) $row['type'] ) {
							$out['skipped']['collection'] = ( $out['skipped']['collection'] ?? 0 ) + 1;
							continue;
						}
						$flat[] = array( (string) $row['id'], (string) $row['name'], (int) $row['parent'] > 0 ? (string) $row['parent'] : '' );
					}
					$out['items'] = $this->table_exists( $wpdb->prefix . 'fbv_attachment_folder' ) ? (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}fbv_attachment_folder" ) : 0; // phpcs:ignore WordPress.DB
				}
				break;
			case 'real-media-library-lite':
				if ( $this->table_exists( $wpdb->prefix . 'realmedialibrary' ) ) {
					foreach ( (array) $wpdb->get_results( "SELECT id, name, parent, type FROM {$wpdb->prefix}realmedialibrary ORDER BY id ASC", ARRAY_A ) as $row ) { // phpcs:ignore WordPress.DB
						if ( ! in_array( (string) $row['type'], array( '0', '1' ), true ) ) {
							$out['skipped']['special'] = ( $out['skipped']['special'] ?? 0 ) + 1;
							continue;
						}
						$flat[] = array( (string) $row['id'], (string) $row['name'], (int) $row['parent'] > 0 ? (string) $row['parent'] : '' );
					}
					if ( $this->table_exists( $wpdb->prefix . 'realmedialibrary_posts' ) ) {
						$out['items']                = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}realmedialibrary_posts WHERE isShortcut = 0 AND fid > 0" ); // phpcs:ignore WordPress.DB
						$out['skipped']['shortcut'] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}realmedialibrary_posts WHERE isShortcut > 0" ); // phpcs:ignore WordPress.DB
						if ( 0 === $out['skipped']['shortcut'] ) {
							unset( $out['skipped']['shortcut'] );
						}
					}
				}
				break;
			default:
				$taxonomy = $this->foreign_taxonomy();
				$rows     = $wpdb->get_results( $wpdb->prepare( "SELECT tt.term_taxonomy_id AS id, t.name, tt.parent, tt.term_id FROM {$wpdb->term_taxonomy} tt JOIN {$wpdb->terms} t ON t.term_id = tt.term_id WHERE tt.taxonomy = %s ORDER BY tt.term_id ASC", $taxonomy ), ARRAY_A ); // phpcs:ignore WordPress.DB
				$by_term  = array();
				foreach ( (array) $rows as $row ) {
					$by_term[ (int) $row['term_id'] ] = (string) $row['id'];
				}
				foreach ( (array) $rows as $row ) {
					$parent = (int) $row['parent'];
					$flat[] = array( (string) $row['id'], (string) $row['name'], $parent > 0 && isset( $by_term[ $parent ] ) ? $by_term[ $parent ] : '' );
				}
				if ( $rows ) {
					$out['items'] = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->term_relationships} tr JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id WHERE tt.taxonomy = %s", $taxonomy ) ); // phpcs:ignore WordPress.DB
				}
				// Folders of other post types (posts, pages ...) have their own taxonomies.
				$like = 'wicked-folders' === $this->slug ? 'wf\_%\_folders' : '%\_folder';
				$more = $wpdb->get_results( $wpdb->prepare( "SELECT taxonomy, COUNT(*) AS n FROM {$wpdb->term_taxonomy} WHERE taxonomy LIKE %s AND taxonomy <> %s GROUP BY taxonomy", $like, $taxonomy ), ARRAY_A ); // phpcs:ignore WordPress.DB
				foreach ( (array) $more as $row ) {
					$out['skipped']['other:' . $row['taxonomy']] = (int) $row['n'];
				}
				break;
		}
		$out['folders'] = $this->order_parents_first( $flat );
		$this->collected = $out;
		return $out;
	}

	/**
	 * Parents before their children (the module creates a child under an existing parent).
	 *
	 * @param array<int, array{0:string,1:string,2:string}> $flat id, name, parent id ('' = root).
	 * @return array<int, array<string, mixed>>
	 */
	private function order_parents_first( array $flat ): array {
		$ids    = array_column( $flat, 0 );
		$byid   = array();
		foreach ( $flat as $row ) {
			$byid[ $row[0] ] = $row;
		}
		$depth = static function ( string $id ) use ( $byid, $ids ): int {
			$d = 0;
			while ( isset( $byid[ $id ] ) && '' !== $byid[ $id ][2] && in_array( $byid[ $id ][2], $ids, true ) && $d < 50 ) {
				$id = $byid[ $id ][2];
				++$d;
			}
			return $d;
		};
		$out = array();
		foreach ( $flat as $i => $row ) {
			$out[] = array(
				'key'    => $this->slug . ':' . $row[0],
				'label'  => $row[1],
				'id'     => $row[0],
				'name'   => $row[1],
				'parent' => in_array( $row[2], $ids, true ) ? $row[2] : '',
				'depth'  => $depth( $row[0] ),
				'seq'    => $i,
			);
		}
		usort( $out, static fn( array $a, array $b ): int => array( $a['depth'], $a['seq'] ) <=> array( $b['depth'], $b['seq'] ) );
		return $out;
	}

	/**
	 * Attachment ids that sit in a foreign folder.
	 *
	 * @param string $folder_id Foreign folder id.
	 * @return int[]
	 */
	private function objects_of( string $folder_id ): array {
		global $wpdb;
		switch ( $this->slug ) {
			case 'filebird':
				$ids = $wpdb->get_col( $wpdb->prepare( "SELECT attachment_id FROM {$wpdb->prefix}fbv_attachment_folder WHERE folder_id = %d", (int) $folder_id ) ); // phpcs:ignore WordPress.DB
				break;
			case 'real-media-library-lite':
				$ids = $wpdb->get_col( $wpdb->prepare( "SELECT attachment FROM {$wpdb->prefix}realmedialibrary_posts WHERE fid = %d AND isShortcut = 0", (int) $folder_id ) ); // phpcs:ignore WordPress.DB
				break;
			default:
				$ids = $wpdb->get_col( $wpdb->prepare( "SELECT object_id FROM {$wpdb->term_relationships} WHERE term_taxonomy_id = %d", (int) $folder_id ) ); // phpcs:ignore WordPress.DB
		}
		return array_values( array_unique( array_map( 'intval', (array) $ids ) ) );
	}

	protected function map( bool $active ): Mapping {
		$m = new Mapping();
		$c = $this->collect();
		if ( ! $c['folders'] ) {
			$m->note( __( 'The plugin has no folders stored, there is nothing to transfer.', 'ux-studio' ) );
			return $m;
		}
		foreach ( $c['skipped'] as $reason => $n ) {
			if ( 0 === strpos( $reason, 'other:' ) ) {
				$m->lossy(
					sprintf(
						/* translators: 1: number of folders, 2: taxonomy name. */
						__( '%1$d folders of other content types (%2$s) are not transferred. This step moves the media library folders only.', 'ux-studio' ),
						$n,
						substr( $reason, 6 )
					)
				);
			} elseif ( 'collection' === $reason || 'special' === $reason ) {
				$m->lossy(
					sprintf(
						/* translators: %d: number of entries. */
						__( '%d collections, galleries or special entries are not transferred, only ordinary folders.', 'ux-studio' ),
						$n
					)
				);
			} elseif ( 'shortcut' === $reason ) {
				$m->lossy(
					sprintf(
						/* translators: %d: number of shortcuts. */
						__( '%d shortcuts (the same file in more than one folder) are not transferred. A file sits in one folder.', 'ux-studio' ),
						$n
					)
				);
			}
		}
		$m->lossy( __( 'The manual order of folders and their colours or icons are not transferred.', 'ux-studio' ) );
		$m->note( __( 'Files are not moved on disk, only the folder structure and which file is in which folder are copied. A file that is already in a UX Studio folder stays there.', 'ux-studio' ) );
		return $m;
	}

	protected function data_kind(): string {
		return 'folder';
	}

	protected function data_label(): string {
		return __( 'Folders', 'ux-studio' );
	}

	protected function data_total( array $options ): int {
		return count( $this->collect()['folders'] );
	}

	protected function data_plan( array $options ): array {
		$c = $this->collect();
		return array(
			array(
				'key'   => 'folder',
				'label' => __( 'Folders', 'ux-studio' ),
				'count' => count( $c['folders'] ),
			),
			array(
				'key'   => 'items',
				'label' => __( 'files placed in them', 'ux-studio' ),
				'count' => $c['items'],
			),
		);
	}

	protected function read_batch( $position, int $limit, array $options ): array {
		$all    = $this->collect()['folders'];
		$offset = (int) $position;
		return array(
			'items' => array_slice( $all, $offset, $limit ),
			'next'  => $offset + $limit,
			'done'  => $offset + $limit >= count( $all ),
		);
	}

	protected function prepare_data(): void {
		Revert::ensure_folder_taxonomy();
	}

	/**
	 * UX Studio term id for a foreign folder id (created earlier, in this or a previous batch).
	 *
	 * @param string $folder_id Foreign folder id.
	 */
	private function term_for( string $folder_id ): int {
		$id = MigrationMap::get( $this->slug, $this->slug . ':' . $folder_id, 'folder_ref' );
		if ( null === $id || ! function_exists( 'get_term' ) ) {
			return 0;
		}
		$term = get_term( (int) $id, Revert::FOLDER_TAXONOMY );
		return ( $term && ! is_wp_error( $term ) ) ? (int) $id : 0;
	}

	/**
	 * The existing UX Studio media folder with this name under a parent, or 0.
	 *
	 * @param string $name   Folder name.
	 * @param int    $parent Parent term id.
	 */
	private function find_folder( string $name, int $parent ): int {
		if ( ! function_exists( 'get_terms' ) || ! taxonomy_exists( Revert::FOLDER_TAXONOMY ) ) {
			return 0;
		}
		$terms = get_terms(
			array(
				'taxonomy'   => Revert::FOLDER_TAXONOMY,
				'hide_empty' => false,
				'parent'     => $parent,
				'name'       => $name,
			)
		);
		foreach ( is_array( $terms ) ? $terms : array() as $term ) {
			$type = (string) get_term_meta( $term->term_id, self::POST_TYPE_META, true );
			if ( 0 === strcasecmp( $term->name, $name ) && ( '' === $type || 'attachment' === $type ) ) {
				return (int) $term->term_id;
			}
		}
		return 0;
	}

	/**
	 * Attachments among `$ids` that are already in some UX Studio folder.
	 *
	 * @param int[] $ids Attachment ids.
	 * @return int[]
	 */
	private function already_filed( array $ids ): array {
		global $wpdb;
		if ( ! $ids ) {
			return array();
		}
		$in = implode( ',', array_map( 'intval', $ids ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_col( $wpdb->prepare( "SELECT tr.object_id FROM {$wpdb->term_relationships} tr JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id WHERE tt.taxonomy = %s AND tr.object_id IN ({$in})", Revert::FOLDER_TAXONOMY ) );
		return array_map( 'intval', (array) $rows );
	}

	protected function preview_item( array $item, array $ctx ): array {
		$parent = '' === $item['parent'] ? 0 : $this->term_for( (string) $item['parent'] );
		$term   = $this->find_folder( (string) $item['name'], $parent );
		$ids    = $this->objects_of( (string) $item['id'] );
		if ( $term ) {
			$new = array_diff( $ids, $this->already_filed( $ids ) );
			return array( 'status' => $new ? 'updated' : 'unchanged' );
		}
		return array( 'status' => 'created' );
	}

	protected function apply_item( array $item, array $ctx ): array {
		$run_id = (string) $ctx['run_id'];
		$parent = 0;
		if ( '' !== $item['parent'] ) {
			$parent = $this->term_for( (string) $item['parent'] );
			if ( 0 === $parent ) {
				return array(
					'status'  => 'skipped',
					'message' => __( 'The parent folder could not be created.', 'ux-studio' ),
				);
			}
		}
		$term    = $this->find_folder( (string) $item['name'], $parent );
		$created = false;
		if ( 0 === $term ) {
			$new = wp_insert_term( (string) $item['name'], Revert::FOLDER_TAXONOMY, array( 'parent' => $parent ) );
			if ( is_wp_error( $new ) ) {
				return array(
					'status'  => 'skipped',
					'message' => $new->get_error_message(),
				);
			}
			$term    = (int) $new['term_id'];
			$created = true;
			update_term_meta( $term, self::POST_TYPE_META, 'attachment' );
		}
		MigrationMap::put( $this->slug, $this->slug . ':' . $item['id'], 'folder_ref', (string) $term, $run_id );

		$ids     = array_values( array_filter( $this->objects_of( (string) $item['id'] ), static fn( int $id ): bool => 'attachment' === get_post_type( $id ) ) );
		$filed   = array_flip( $this->already_filed( $ids ) );
		$placed  = 0;
		foreach ( $ids as $attachment ) {
			if ( isset( $filed[ $attachment ] ) ) {
				continue;
			}
			wp_set_object_terms( $attachment, array( $term ), Revert::FOLDER_TAXONOMY, true );
			if ( ! $created ) {
				// An older folder keeps the term when the run is undone, so the assignment is undone one by one.
				MigrationMap::put( $this->slug, $this->slug . ':' . $item['id'] . ':' . $attachment, 'folder_rel', $attachment . '|' . $term, $run_id );
			}
			++$placed;
		}

		if ( $created ) {
			return array(
				'status' => 'created',
				'maps'   => array( array( 'folder_term', (string) $term ) ),
			);
		}
		return array( 'status' => $placed > 0 ? 'updated' : 'unchanged' );
	}

	protected function verify_item( array $item ): array {
		Revert::ensure_folder_taxonomy();
		$parent = '' === $item['parent'] ? 0 : $this->term_for( (string) $item['parent'] );
		$term   = $this->term_for( (string) $item['id'] );
		if ( 0 === $term ) {
			$term = $this->find_folder( (string) $item['name'], $parent );
		}
		if ( 0 === $term ) {
			return array(
				'ok'     => false,
				'detail' => __( 'The folder is not in UX Studio.', 'ux-studio' ),
			);
		}
		$ids      = array_filter( $this->objects_of( (string) $item['id'] ), static fn( int $id ): bool => 'attachment' === get_post_type( $id ) );
		$filed    = $this->already_filed( array_values( $ids ) );
		$missing  = count( $ids ) - count( array_intersect( $ids, $filed ) );
		if ( $missing > 0 ) {
			return array(
				'ok'     => false,
				'detail' => sprintf(
					/* translators: %d: number of files. */
					__( '%d files are not in any UX Studio folder.', 'ux-studio' ),
					$missing
				),
			);
		}
		return array(
			'ok'     => true,
			'detail' => '',
		);
	}
}
