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
 * `uxstudio_media_folder` (term meta `uxstudio_folder_post_type` = the post type the folder belongs to, `attachment`
 * for media; `uxstudio_folder_color`, `uxstudio_folder_order`), an item sits in a folder through a term
 * relationship. No files are moved.
 *
 * HARMLESS (written directly, also when the module is on): folders only organise content in the administration.
 * Idempotent: a folder with the same name under the same parent and post type is reused (its colour and order stay as
 * they are), an item already in a UX Studio folder stays where it is. The post types that got folders are added to the
 * post types the module offers folders for.
 *
 * Reading (media AND posts, pages, custom post types where the plugin has them; the module keeps one tree per post type):
 *  - FileBird: tables `fbv` (type 0 = folder, `ord` = order) and `fbv_attachment_folder`, colours in the option
 *    `fbv_folder_colors`; folders of other post types are terms of `fbv_pt_tax_<post type>` (order `fbv_tax_order`).
 *  - Folders: taxonomies `media_folder`, `folder` (pages), `post_folder`, `<post type>_folder`; term meta `wcp_custom_order`
 *    (order) and `folder_info` { has_color }.
 *  - Real Media Library: tables `realmedialibrary` (parent -1 = root, type 0 = folder, 1 = collection; `ord`) and
 *    `realmedialibrary_posts` (shortcuts are not copied). Media only; the plugin has no colours.
 *  - Wicked Folders: taxonomies `wf_<post type>_folders`; term meta `wf_order` and `wf_color`.
 */
final class FolderSource extends AbstractSource {

	/** Term meta that tells which post type a folder belongs to (FolderManager\Module::POST_TYPE_META). */
	private const POST_TYPE_META = 'uxstudio_folder_post_type';

	/** Term meta with the colour (FolderManager\Module::COLOR_META, checked by the tests). */
	private const COLOR_META = 'uxstudio_folder_color';

	/** Term meta with the position among siblings (FolderManager\Module::ORDER_META, checked by the tests). */
	private const ORDER_META = 'uxstudio_folder_order';

	/** @var array{folders:array<int, array<string, mixed>>,items:int,skipped:array<string, int>,flags:array<string, int>}|null */
	private ?array $collected = null;

	/** Foreign taxonomy of the term based plugins for media. */
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
	 * Post type a term based folder taxonomy belongs to, '' when it is not one of this plugin's folder taxonomies.
	 *
	 * @param string $taxonomy Taxonomy name.
	 */
	private function post_type_of_taxonomy( string $taxonomy ): string {
		switch ( $this->slug ) {
			case 'wicked-folders':
				return 1 === preg_match( '/^wf_(.+)_folders$/', $taxonomy, $m ) ? $m[1] : '';
			case 'filebird':
				return 0 === strpos( $taxonomy, 'fbv_pt_tax_' ) ? substr( $taxonomy, 11 ) : '';
			default:
				if ( 'media_folder' === $taxonomy ) {
					return 'attachment';
				}
				if ( 'folder' === $taxonomy ) {
					return 'page';
				}
				return 1 === preg_match( '/^(.+)_folder$/', $taxonomy, $m ) ? $m[1] : '';
		}
	}

	/**
	 * Colour of a term based folder (valid `#hex` or '').
	 *
	 * @param int $term_id Term id.
	 */
	private function term_color( int $term_id ): string {
		if ( ! function_exists( 'get_term_meta' ) ) {
			return '';
		}
		if ( 'wicked-folders' === $this->slug ) {
			return (string) sanitize_hex_color( (string) get_term_meta( $term_id, 'wf_color', true ) );
		}
		if ( 'folders' === $this->slug ) {
			$info = get_term_meta( $term_id, 'folder_info', true );
			return is_array( $info ) ? (string) sanitize_hex_color( (string) ( $info['has_color'] ?? '' ) ) : '';
		}
		return '';
	}

	/**
	 * Order of a term based folder.
	 *
	 * @param int $term_id Term id.
	 */
	private function term_order( int $term_id ): int {
		if ( ! function_exists( 'get_term_meta' ) ) {
			return 0;
		}
		$key = array(
			'wicked-folders' => 'wf_order',
			'folders'        => 'wcp_custom_order',
			'filebird'       => 'fbv_tax_order',
		)[ $this->slug ] ?? '';
		return '' === $key ? 0 : (int) get_term_meta( $term_id, $key, true );
	}

	/**
	 * Read the folders of one taxonomy.
	 *
	 * @param string $taxonomy  Taxonomy name.
	 * @param string $post_type Post type the folders belong to.
	 * @param string $prefix    Prefix of the folder ids (keeps them apart from other id spaces).
	 * @return array{flat:array<int, array<string, mixed>>,items:int}
	 */
	private function read_taxonomy( string $taxonomy, string $post_type, string $prefix = '' ): array {
		global $wpdb;
		$rows    = $wpdb->get_results( $wpdb->prepare( "SELECT tt.term_taxonomy_id AS id, t.name, tt.parent, tt.term_id FROM {$wpdb->term_taxonomy} tt JOIN {$wpdb->terms} t ON t.term_id = tt.term_id WHERE tt.taxonomy = %s ORDER BY tt.term_id ASC", $taxonomy ), ARRAY_A ); // phpcs:ignore WordPress.DB
		$by_term = array();
		foreach ( (array) $rows as $row ) {
			$by_term[ (int) $row['term_id'] ] = $prefix . (string) $row['id'];
		}
		$flat = array();
		foreach ( (array) $rows as $row ) {
			$parent = (int) $row['parent'];
			$flat[] = array(
				'id'        => $prefix . (string) $row['id'],
				'name'      => (string) $row['name'],
				'parent'    => $parent > 0 && isset( $by_term[ $parent ] ) ? $by_term[ $parent ] : '',
				'post_type' => $post_type,
				'kind'      => 'term',
				'color'     => $this->term_color( (int) $row['term_id'] ),
				'order'     => $this->term_order( (int) $row['term_id'] ),
			);
		}
		$items = 0;
		if ( $rows ) {
			$items = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->term_relationships} tr JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id WHERE tt.taxonomy = %s", $taxonomy ) ); // phpcs:ignore WordPress.DB
		}
		return array(
			'flat'  => $flat,
			'items' => $items,
		);
	}

	/**
	 * Folder taxonomies of the plugin besides media: posts, pages, custom post types.
	 *
	 * @param array<string, mixed>             $out  Result being built (items, skipped).
	 * @param array<int, array<string, mixed>> $flat Folders read so far.
	 */
	private function read_other_taxonomies( array &$out, array &$flat ): void {
		global $wpdb;
		if ( 'folders' === $this->slug ) {
			$like = '%\_folder';
		} elseif ( 'wicked-folders' === $this->slug ) {
			$like = 'wf\_%\_folders';
		} else {
			$like = 'fbv\_pt\_tax\_%';
		}
		// Folders plugin keeps the folders of pages in the taxonomy `folder` (no prefix), which the LIKE pattern cannot match.
		$bare = 'folders' === $this->slug ? 'folder' : '';
		$more = $wpdb->get_results( $wpdb->prepare( "SELECT taxonomy, COUNT(*) AS n FROM {$wpdb->term_taxonomy} WHERE (taxonomy LIKE %s OR taxonomy = %s) AND taxonomy <> %s GROUP BY taxonomy", $like, $bare, $this->foreign_taxonomy() ), ARRAY_A ); // phpcs:ignore WordPress.DB
		foreach ( (array) $more as $row ) {
			$taxonomy  = (string) $row['taxonomy'];
			$post_type = $this->post_type_of_taxonomy( $taxonomy );
			// A taxonomy of a post type this site does not have is another plugin's, or leftovers: not ours to move.
			if ( '' === $post_type || ! function_exists( 'post_type_exists' ) || ! post_type_exists( $post_type ) ) {
				$out['skipped']['other:' . $taxonomy] = (int) $row['n'];
				continue;
			}
			$read          = $this->read_taxonomy( $taxonomy, $post_type, 'filebird' === $this->slug ? 't' : '' );
			$flat          = array_merge( $flat, $read['flat'] );
			$out['items'] += $read['items'];
		}
	}

	/**
	 * All folders of the foreign plugin, parents before children, and what is left out.
	 *
	 * @return array{folders:array<int, array<string, mixed>>,items:int,skipped:array<string, int>,flags:array<string, int>}
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
			'flags'   => array(),
		);
		$flat = array();
		switch ( $this->slug ) {
			case 'filebird':
				if ( $this->table_exists( $wpdb->prefix . 'fbv' ) ) {
					$colors = get_option( 'fbv_folder_colors', array() );
					$colors = is_array( $colors ) ? $colors : array();
					foreach ( (array) $wpdb->get_results( "SELECT id, name, parent, type, ord, created_by FROM {$wpdb->prefix}fbv ORDER BY id ASC", ARRAY_A ) as $row ) { // phpcs:ignore WordPress.DB
						if ( 0 !== (int) $row['type'] ) {
							$out['skipped']['collection'] = ( $out['skipped']['collection'] ?? 0 ) + 1;
							continue;
						}
						if ( (int) ( $row['created_by'] ?? 0 ) > 0 ) {
							$out['flags']['per_user'] = ( $out['flags']['per_user'] ?? 0 ) + 1;
						}
						$flat[] = array(
							'id'        => (string) $row['id'],
							'name'      => (string) $row['name'],
							'parent'    => (int) $row['parent'] > 0 ? (string) $row['parent'] : '',
							'post_type' => 'attachment',
							'kind'      => 'fbv',
							'color'     => (string) sanitize_hex_color( (string) ( $colors[ $row['id'] ] ?? '' ) ),
							'order'     => (int) ( $row['ord'] ?? 0 ),
						);
					}
					$out['items'] = $this->table_exists( $wpdb->prefix . 'fbv_attachment_folder' ) ? (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}fbv_attachment_folder" ) : 0; // phpcs:ignore WordPress.DB
				}
				$this->read_other_taxonomies( $out, $flat );
				break;
			case 'real-media-library-lite':
				if ( $this->table_exists( $wpdb->prefix . 'realmedialibrary' ) ) {
					foreach ( (array) $wpdb->get_results( "SELECT id, name, parent, type, ord FROM {$wpdb->prefix}realmedialibrary ORDER BY id ASC", ARRAY_A ) as $row ) { // phpcs:ignore WordPress.DB
						if ( ! in_array( (string) $row['type'], array( '0', '1' ), true ) ) {
							$out['skipped']['special'] = ( $out['skipped']['special'] ?? 0 ) + 1;
							continue;
						}
						$flat[] = array(
							'id'        => (string) $row['id'],
							'name'      => (string) $row['name'],
							'parent'    => (int) $row['parent'] > 0 ? (string) $row['parent'] : '',
							'post_type' => 'attachment',
							'kind'      => 'rml',
							'color'     => '',
							'order'     => (int) ( $row['ord'] ?? 0 ),
						);
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
				// Media first (the taxonomy every folder plugin has), then every other folder taxonomy of the plugin.
				$media = $this->read_taxonomy( $this->foreign_taxonomy(), 'attachment' );
				$flat  = $media['flat'];
				$out['items'] += $media['items'];

				$this->read_other_taxonomies( $out, $flat );
				break;
		}
		$out['folders'] = $this->order_parents_first( $flat );
		$this->collected = $out;
		return $out;
	}

	/**
	 * Parents before their children (the module creates a child under an existing parent).
	 *
	 * @param array<int, array<string, mixed>> $flat Folders: id, name, parent ('' = root), post_type, kind, color, order.
	 * @return array<int, array<string, mixed>>
	 */
	private function order_parents_first( array $flat ): array {
		$ids  = array_map( 'strval', array_column( $flat, 'id' ) );
		$byid = array();
		foreach ( $flat as $row ) {
			$byid[ (string) $row['id'] ] = $row;
		}
		$depth = static function ( string $id ) use ( $byid, $ids ): int {
			$d = 0;
			while ( isset( $byid[ $id ] ) && '' !== $byid[ $id ]['parent'] && in_array( (string) $byid[ $id ]['parent'], $ids, true ) && $d < 50 ) {
				$id = (string) $byid[ $id ]['parent'];
				++$d;
			}
			return $d;
		};
		$out = array();
		foreach ( $flat as $i => $row ) {
			$id    = (string) $row['id'];
			$out[] = array(
				'key'       => $this->slug . ':' . $id,
				'label'     => 'attachment' === $row['post_type'] ? (string) $row['name'] : sprintf( '%s (%s)', $row['name'], $row['post_type'] ),
				'id'        => $id,
				'name'      => (string) $row['name'],
				'parent'    => in_array( (string) $row['parent'], $ids, true ) ? (string) $row['parent'] : '',
				'depth'     => $depth( $id ),
				'seq'       => $i,
				'post_type' => (string) $row['post_type'],
				'kind'      => (string) $row['kind'],
				'color'     => (string) $row['color'],
				'order'     => (int) $row['order'],
			);
		}
		usort( $out, static fn( array $a, array $b ): int => array( $a['depth'], $a['seq'] ) <=> array( $b['depth'], $b['seq'] ) );
		return $out;
	}

	/**
	 * Ids of the items that sit in a foreign folder.
	 *
	 * @param array<string, mixed> $item Folder item.
	 * @return int[]
	 */
	private function objects_of( array $item ): array {
		global $wpdb;
		$folder_id = (string) $item['id'];
		switch ( $item['kind'] ?? 'term' ) {
			case 'fbv':
				$ids = $wpdb->get_col( $wpdb->prepare( "SELECT attachment_id FROM {$wpdb->prefix}fbv_attachment_folder WHERE folder_id = %d", (int) $folder_id ) ); // phpcs:ignore WordPress.DB
				break;
			case 'rml':
				$ids = $wpdb->get_col( $wpdb->prepare( "SELECT attachment FROM {$wpdb->prefix}realmedialibrary_posts WHERE fid = %d AND isShortcut = 0", (int) $folder_id ) ); // phpcs:ignore WordPress.DB
				break;
			default:
				$ids = $wpdb->get_col( $wpdb->prepare( "SELECT object_id FROM {$wpdb->term_relationships} WHERE term_taxonomy_id = %d", (int) ltrim( $folder_id, 't' ) ) ); // phpcs:ignore WordPress.DB
		}
		return array_values( array_unique( array_map( 'intval', (array) $ids ) ) );
	}

	/**
	 * The items of a folder that really are of the folder's post type.
	 *
	 * @param array<string, mixed> $item Folder item.
	 * @return int[]
	 */
	private function items_of_type( array $item ): array {
		$type = (string) ( $item['post_type'] ?? 'attachment' );
		return array_values( array_filter( $this->objects_of( $item ), static fn( int $id ): bool => $type === get_post_type( $id ) ) );
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
						__( '%1$d folders of the taxonomy %2$s are not transferred: it belongs to a content type this site does not have.', 'ux-studio' ),
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

		// The module offers folders only for the post types switched on in its settings: add the ones that got folders.
		$types = array();
		foreach ( $c['folders'] as $folder ) {
			$types[ (string) $folder['post_type'] ] = true;
		}
		if ( array_diff_key( $types, array( 'attachment' => true ) ) ) {
			$current = get_option( 'uxstudio_folder_manager', array() );
			$current = is_array( $current ) && ! empty( $current['post_types'] ) && is_array( $current['post_types'] ) ? array_map( 'strval', $current['post_types'] ) : array( 'attachment' );
			$m->set( 'folder-manager', 'post_types', array_values( array_unique( array_merge( $current, array_keys( $types ) ) ) ), __( 'Post types', 'ux-studio' ), 'folder taxonomies of ' . $this->slug );
			$m->note( __( 'Folders of posts, pages and other content types are transferred too; the module is set to offer folders for these post types.', 'ux-studio' ) );
		}

		$colors = count( array_filter( array_column( $c['folders'], 'color' ) ) );
		$order  = count( array_filter( array_column( $c['folders'], 'order' ) ) );
		if ( $colors || $order ) {
			$m->note(
				sprintf(
					/* translators: 1: number of folders with a colour, 2: number of folders with a manual position. */
					__( 'Folder colors (%1$d) and the manual order (%2$d folders with a position) are carried over to the folders created by this step.', 'ux-studio' ),
					$colors,
					$order
				)
			);
		}
		if ( ! empty( $c['flags']['per_user'] ) ) {
			$m->lossy(
				sprintf(
					/* translators: %d: number of folders. */
					__( '%d folders belong to a single user in the plugin; in the module all folders are shared.', 'ux-studio' ),
					$c['flags']['per_user']
				)
			);
		}
		$m->lossy( __( 'Folder icons and the other flags of the plugin (sticky, locked or high priority folders) are not transferred.', 'ux-studio' ) );
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
	 * The existing UX Studio folder with this name under a parent for a post type, or 0.
	 *
	 * @param string $name      Folder name.
	 * @param int    $parent    Parent term id.
	 * @param string $post_type Post type the folder belongs to.
	 */
	private function find_folder( string $name, int $parent, string $post_type = 'attachment' ): int {
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
			$type = '' === $type ? 'attachment' : $type;
			if ( 0 === strcasecmp( $term->name, $name ) && $type === $post_type ) {
				return (int) $term->term_id;
			}
		}
		return 0;
	}

	/**
	 * Items among `$ids` that are already in some UX Studio folder.
	 *
	 * @param int[] $ids Post ids.
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
		$term   = $this->find_folder( (string) $item['name'], $parent, (string) $item['post_type'] );
		$ids    = $this->objects_of( $item );
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
		$post_type = (string) $item['post_type'];
		$term      = $this->find_folder( (string) $item['name'], $parent, $post_type );
		$created   = false;
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
			update_term_meta( $term, self::POST_TYPE_META, $post_type );
			// Colour and manual order belong to the folder this run created; an existing folder keeps its own.
			if ( '' !== (string) $item['color'] ) {
				update_term_meta( $term, self::COLOR_META, (string) $item['color'] );
			}
			update_term_meta( $term, self::ORDER_META, max( 0, (int) $item['order'] ) + 1 );
		}
		MigrationMap::put( $this->slug, $this->slug . ':' . $item['id'], 'folder_ref', (string) $term, $run_id );

		$ids    = $this->items_of_type( $item );
		$filed  = array_flip( $this->already_filed( $ids ) );
		$placed = 0;
		foreach ( $ids as $object ) {
			if ( isset( $filed[ $object ] ) ) {
				continue;
			}
			wp_set_object_terms( $object, array( $term ), Revert::FOLDER_TAXONOMY, true );
			if ( ! $created ) {
				// An older folder keeps the term when the run is undone, so the assignment is undone one by one.
				MigrationMap::put( $this->slug, $this->slug . ':' . $item['id'] . ':' . $object, 'folder_rel', $object . '|' . $term, $run_id );
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
			$term = $this->find_folder( (string) $item['name'], $parent, (string) $item['post_type'] );
		}
		if ( 0 === $term ) {
			return array(
				'ok'     => false,
				'detail' => __( 'The folder is not in UX Studio.', 'ux-studio' ),
			);
		}
		$ids      = $this->items_of_type( $item );
		$filed    = $this->already_filed( $ids );
		$missing  = count( $ids ) - count( array_intersect( $ids, $filed ) );
		if ( $missing > 0 ) {
			return array(
				'ok'     => false,
				'detail' => sprintf(
					/* translators: %d: number of files. */
					__( '%d items are not in any UX Studio folder.', 'ux-studio' ),
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
