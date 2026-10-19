<?php
/**
 * Folder Manager - wp-admin integration: folder sidebar on the Media Library
 * and post lists, filter by folder (list view, grid view, media modal), folder
 * column, "Move to folder" bulk action, folder field in attachment details,
 * folder meta box for posts and "upload into the selected folder".
 *
 * The sidebar / modal UI is plain JS + CSS (assets/folder-manager.*), no webpack.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\FolderManager;

use WP_Post;
use WP_Query;
use WP_Term;

defined( 'ABSPATH' ) || exit;

final class AdminIntegration {

	private const BULK_ACTION = 'uxstudio_move_to_folder';

	private Module $module;

	/** Script data is localized once per request. */
	private static bool $enqueued = false;

	/** @var array<string, array<int, array{id:int,label:string}>> Per-request cache of select options. */
	private array $choices_cache = array();

	/**
	 * @param Module $module Owning module.
	 */
	public function __construct( Module $module ) {
		$this->module = $module;
	}

	/**
	 * Register hooks.
	 */
	public function register(): void {
		add_action( 'admin_init', array( $this, 'register_list_hooks' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_list_assets' ) );
		add_action( 'wp_enqueue_media', array( $this, 'enqueue_media_assets' ) );
		add_action( 'admin_notices', array( $this, 'bulk_notice' ) );

		// Filter by folder (list views + media grid / modal AJAX queries).
		add_action( 'pre_get_posts', array( $this, 'filter_main_query' ) );
		add_filter( 'ajax_query_attachments_args', array( $this, 'filter_ajax_attachments' ) );

		// Media items.
		add_action( 'add_attachment', array( $this, 'assign_on_upload' ) );
		add_filter( 'attachment_fields_to_edit', array( $this, 'attachment_field' ), 10, 2 );
		add_filter( 'attachment_fields_to_save', array( $this, 'save_attachment_field' ), 10, 2 );

		// Posts / pages / CPT.
		add_action( 'add_meta_boxes', array( $this, 'add_meta_box' ) );
		add_action( 'save_post', array( $this, 'save_meta_box' ), 10, 2 );
	}

	/**
	 * Whether the current user may use folders of a post type at all.
	 *
	 * @param string $post_type Post type slug.
	 */
	public static function user_can_use( string $post_type ): bool {
		if ( 'attachment' === $post_type ) {
			return current_user_can( 'upload_files' );
		}

		$object = get_post_type_object( $post_type );

		return $object && current_user_can( $object->cap->edit_posts );
	}

	/**
	 * Columns and bulk actions for every enabled post type.
	 */
	public function register_list_hooks(): void {
		foreach ( $this->module->enabled_post_types() as $post_type ) {
			if ( 'attachment' === $post_type ) {
				add_filter( 'manage_media_columns', array( $this, 'add_column' ) );
				add_action( 'manage_media_custom_column', array( $this, 'render_column' ), 10, 2 );
				add_filter( 'bulk_actions-upload', array( $this, 'add_bulk_action' ) );
				add_filter( 'handle_bulk_actions-upload', array( $this, 'handle_bulk_action' ), 10, 3 );
				continue;
			}

			add_filter( "manage_{$post_type}_posts_columns", array( $this, 'add_column' ) );
			add_action( "manage_{$post_type}_posts_custom_column", array( $this, 'render_column' ), 10, 2 );
			add_filter( "bulk_actions-edit-{$post_type}", array( $this, 'add_bulk_action' ) );
			add_filter( "handle_bulk_actions-edit-{$post_type}", array( $this, 'handle_bulk_action' ), 10, 3 );
		}
	}

	// ------------------------------------------------------------------
	// Assets.
	// ------------------------------------------------------------------

	/**
	 * Sidebar on upload.php (list view) and edit.php (posts / pages / CPT).
	 *
	 * @param string $hook Admin page hook suffix.
	 */
	public function enqueue_list_assets( string $hook ): void {
		if ( 'upload.php' === $hook ) {
			$post_type = 'attachment';
		} elseif ( 'edit.php' === $hook ) {
			$screen    = get_current_screen();
			$post_type = $screen && $screen->post_type ? $screen->post_type : 'post';
		} else {
			return;
		}

		if ( $this->module->is_enabled( $post_type ) && self::user_can_use( $post_type ) ) {
			$this->enqueue( $post_type, false );
		}
	}

	/**
	 * Folder panel inside the media modal / grid view.
	 */
	public function enqueue_media_assets(): void {
		if ( $this->module->is_enabled( 'attachment' ) && self::user_can_use( 'attachment' ) ) {
			$this->enqueue( 'attachment', true );
		}
	}

	/**
	 * Enqueue script + style and hand the folder tree to the script.
	 *
	 * @param string $post_type Post type the page lists.
	 * @param bool   $media     Whether wp.media is (going to be) available.
	 */
	private function enqueue( string $post_type, bool $media ): void {
		if ( self::$enqueued ) {
			return;
		}
		self::$enqueued = true;

		$version = defined( 'UXSTUDIO_VERSION' ) ? UXSTUDIO_VERSION : false;
		$base    = UXSTUDIO_URL . 'includes/Modules/FolderManager/assets/';

		wp_enqueue_style( 'uxstudio-folder-manager', $base . 'folder-manager.css', array( 'dashicons' ), $version );
		wp_enqueue_script(
			'uxstudio-folder-manager',
			$base . 'folder-manager.js',
			$media ? array( 'jquery', 'media-views' ) : array( 'jquery' ),
			$version,
			true
		);

		$overview = $this->module->overview( $post_type );
		$current  = null;
		if ( isset( $_GET[ Module::FILTER_VAR ] ) && is_numeric( wp_unslash( $_GET[ Module::FILTER_VAR ] ) ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$current = absint( wp_unslash( $_GET[ Module::FILTER_VAR ] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}

		$object = get_post_type_object( $post_type );

		wp_localize_script(
			'uxstudio-folder-manager',
			'uxsFolders',
			array(
				'restUrl'       => esc_url_raw( rest_url( 'uxstudio/v1/folder-manager' ) ),
				'nonce'         => wp_create_nonce( 'wp_rest' ),
				'filterVar'     => Module::FILTER_VAR,
				'postType'      => $post_type,
				'folders'       => $overview['folders'],
				'uncategorized' => $overview['uncategorized'],
				'total'         => $overview['total'],
				'current'       => $current,
				'showCounts'    => $this->module->show_counts(),
				'canManage'     => current_user_can( 'manage_categories' ),
				'manageUrl'     => admin_url( 'admin.php?page=ux-studio#/module?id=folder-manager' ),
				'bulkAction'    => self::BULK_ACTION,
				'itemLabel'     => $object ? $object->labels->singular_name : '',
				'i18n'          => array(
					'folders'         => __( 'Folders', 'ux-studio' ),
					'allFolders'      => __( 'All Folders', 'ux-studio' ),
					'uncategorized'   => __( 'Uncategorized', 'ux-studio' ),
					'newFolder'       => __( 'New Folder', 'ux-studio' ),
					'addChildFolder'  => __( 'Add child folder', 'ux-studio' ),
					'renameFolder'    => __( 'Rename folder', 'ux-studio' ),
					'deleteFolder'    => __( 'Delete folder', 'ux-studio' ),
					'folderName'      => __( 'Folder name', 'ux-studio' ),
					'confirmDelete'   => __( 'Delete this folder? Items in it will become uncategorized.', 'ux-studio' ),
					'refresh'         => __( 'Refresh folders', 'ux-studio' ),
					'manage'          => __( 'Manage folders', 'ux-studio' ),
					'expand'          => __( 'Expand', 'ux-studio' ),
					'collapse'        => __( 'Collapse', 'ux-studio' ),
					'error'           => __( 'An error occurred.', 'ux-studio' ),
					'moveFolderLabel' => __( 'Folder to move the selected items to', 'ux-studio' ),
				),
			)
		);
	}

	// ------------------------------------------------------------------
	// Filtering.
	// ------------------------------------------------------------------

	/**
	 * Build the tax_query clause for a folder id (0 = uncategorized).
	 *
	 * @param int $folder_id Folder term id or 0.
	 * @return array<string, mixed>
	 */
	private function folder_clause( int $folder_id ): array {
		if ( 0 === $folder_id ) {
			return array(
				'taxonomy' => Module::TAXONOMY,
				'operator' => 'NOT EXISTS',
			);
		}

		return array(
			'taxonomy'         => Module::TAXONOMY,
			'field'            => 'term_id',
			'terms'            => array( $folder_id ),
			'include_children' => false,
		);
	}

	/**
	 * Append a clause to an existing tax_query (AND).
	 *
	 * @param mixed                $existing Existing tax_query value.
	 * @param array<string, mixed> $clause   New clause.
	 * @return array<int|string, mixed>
	 */
	private function merge_tax_query( $existing, array $clause ): array {
		if ( ! is_array( $existing ) || empty( $existing ) ) {
			return array( $clause );
		}

		return array(
			'relation' => 'AND',
			$existing,
			$clause,
		);
	}

	/**
	 * `?uxstudio_folder=ID` on upload.php (list mode) and edit.php.
	 *
	 * @param WP_Query $query Query.
	 */
	public function filter_main_query( WP_Query $query ): void {
		global $pagenow;

		if ( ! is_admin() || ! $query->is_main_query() || ! in_array( $pagenow, array( 'edit.php', 'upload.php' ), true ) ) {
			return;
		}

		if ( ! isset( $_GET[ Module::FILTER_VAR ] ) || ! is_numeric( wp_unslash( $_GET[ Module::FILTER_VAR ] ) ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}
		$folder_id = absint( wp_unslash( $_GET[ Module::FILTER_VAR ] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		$post_type = $query->get( 'post_type' );
		if ( is_array( $post_type ) ) {
			return;
		}
		if ( '' === $post_type ) {
			$post_type = 'upload.php' === $pagenow ? 'attachment' : 'post';
		}

		if ( ! taxonomy_exists( Module::TAXONOMY ) || ! $this->module->is_enabled( (string) $post_type ) ) {
			return;
		}

		$query->set( 'tax_query', $this->merge_tax_query( $query->get( 'tax_query' ), $this->folder_clause( $folder_id ) ) );
	}

	/**
	 * Media grid / modal: the script sends query[uxstudio_folder]. Core already
	 * verified the query-attachments nonce and drops unknown keys, so read the
	 * request directly.
	 *
	 * @param array<string, mixed> $args Query args.
	 * @return array<string, mixed>
	 */
	public function filter_ajax_attachments( array $args ): array {
		if ( isset( $args['post__in'] ) || ! taxonomy_exists( Module::TAXONOMY ) || ! $this->module->is_enabled( 'attachment' ) ) {
			return $args;
		}

		if ( ! isset( $_REQUEST['query'] ) || ! is_array( $_REQUEST['query'] ) || ! isset( $_REQUEST['query'][ Module::FILTER_VAR ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return $args;
		}

		$raw = wp_unslash( $_REQUEST['query'][ Module::FILTER_VAR ] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! is_scalar( $raw ) || ! is_numeric( $raw ) ) {
			return $args;
		}

		$args['tax_query'] = $this->merge_tax_query( $args['tax_query'] ?? array(), $this->folder_clause( absint( $raw ) ) );

		return $args;
	}

	// ------------------------------------------------------------------
	// Upload into the selected folder.
	// ------------------------------------------------------------------

	/**
	 * The upload scripts send uxstudio_folder with the folder selected in the
	 * panel; put the new attachment into it.
	 *
	 * @param int $attachment_id New attachment id.
	 */
	public function assign_on_upload( int $attachment_id ): void {
		if ( ! isset( $_POST['uxstudio_folder'] ) || ! $this->module->is_enabled( 'attachment' ) ) {
			return;
		}

		// Same nonce core's own upload handlers verified (media-form).
		$nonce = '';
		if ( isset( $_POST['_wpnonce'] ) ) {
			$nonce = sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) );
		} elseif ( isset( $_POST['_ajax_nonce'] ) ) {
			$nonce = sanitize_text_field( wp_unslash( $_POST['_ajax_nonce'] ) );
		}
		if ( '' === $nonce || ! wp_verify_nonce( $nonce, 'media-form' ) ) {
			return;
		}

		$folder_id = absint( wp_unslash( $_POST['uxstudio_folder'] ) );
		if ( $folder_id > 0 && true === $this->module->check_item( $attachment_id, $folder_id ) ) {
			wp_set_object_terms( $attachment_id, array( $folder_id ), Module::TAXONOMY );
		}
	}

	// ------------------------------------------------------------------
	// Folder field for attachments (media modal details / attachment edit screen).
	// ------------------------------------------------------------------

	/**
	 * Add a "Folder" select to the attachment fields.
	 *
	 * @param array<string, mixed> $fields Fields.
	 * @param WP_Post              $post   Attachment.
	 * @return array<string, mixed>
	 */
	public function attachment_field( array $fields, WP_Post $post ): array {
		if ( ! $this->module->is_enabled( 'attachment' ) || ! self::user_can_use( 'attachment' ) ) {
			return $fields;
		}

		$fields['uxstudio_folder'] = array(
			'label' => __( 'Folder', 'ux-studio' ),
			'input' => 'html',
			'html'  => $this->select_html(
				sprintf( 'attachments[%d][uxstudio_folder]', $post->ID ),
				sprintf( 'attachments-%d-uxstudio-folder', $post->ID ),
				'attachment',
				$this->current_folder( $post->ID )
			),
		);

		return $fields;
	}

	/**
	 * Save the attachment "Folder" field (only when it was posted).
	 *
	 * @param array<string, mixed> $post       Attachment post data.
	 * @param array<string, mixed> $attachment Submitted attachment fields.
	 * @return array<string, mixed>
	 */
	public function save_attachment_field( array $post, array $attachment ): array {
		$post_id = (int) ( $post['ID'] ?? 0 );
		if ( $post_id <= 0 || ! isset( $attachment['uxstudio_folder'] ) ) {
			return $post;
		}

		$folder_id = absint( $attachment['uxstudio_folder'] );
		if ( true === $this->module->check_item( $post_id, $folder_id ) ) {
			wp_set_object_terms( $post_id, 0 === $folder_id ? array() : array( $folder_id ), Module::TAXONOMY );
		}

		return $post;
	}

	// ------------------------------------------------------------------
	// Meta box for posts / pages / CPT.
	// ------------------------------------------------------------------

	/**
	 * Folder meta box (works in the classic and in the block editor).
	 *
	 * @param string $post_type Post type being edited.
	 */
	public function add_meta_box( string $post_type ): void {
		if ( 'attachment' === $post_type || ! $this->module->is_enabled( $post_type ) ) {
			return;
		}

		add_meta_box(
			'uxstudio_folder',
			__( 'Folders', 'ux-studio' ),
			array( $this, 'render_meta_box' ),
			$post_type,
			'side',
			'default',
			array( '__back_compat_meta_box' => false )
		);
	}

	/**
	 * Render the meta box.
	 *
	 * @param WP_Post $post Post being edited.
	 */
	public function render_meta_box( WP_Post $post ): void {
		wp_nonce_field( 'uxstudio_folder_save', 'uxstudio_folder_nonce' );

		echo $this->select_html( 'uxstudio_folder_id', 'uxstudio-folder-select', $post->post_type, $this->current_folder( $post->ID ), true ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in select_html().
		echo '<p class="description">' . esc_html__( 'Select a folder for this item.', 'ux-studio' ) . '</p>';
	}

	/**
	 * Persist the meta box choice.
	 *
	 * @param int     $post_id Post id.
	 * @param WP_Post $post    Post.
	 */
	public function save_meta_box( int $post_id, WP_Post $post ): void {
		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || wp_is_post_revision( $post_id ) ) {
			return;
		}

		if ( ! isset( $_POST['uxstudio_folder_nonce'], $_POST['uxstudio_folder_id'] ) ) {
			return;
		}

		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['uxstudio_folder_nonce'] ) ), 'uxstudio_folder_save' ) ) {
			return;
		}

		$folder_id = absint( wp_unslash( $_POST['uxstudio_folder_id'] ) );
		if ( true === $this->module->check_item( $post_id, $folder_id ) ) {
			wp_set_object_terms( $post_id, 0 === $folder_id ? array() : array( $folder_id ), Module::TAXONOMY );
		}
	}

	// ------------------------------------------------------------------
	// Folder column.
	// ------------------------------------------------------------------

	/**
	 * Insert the folder column after title / file.
	 *
	 * @param array<string, string> $columns Columns.
	 * @return array<string, string>
	 */
	public function add_column( array $columns ): array {
		$out      = array();
		$inserted = false;
		foreach ( $columns as $key => $label ) {
			$out[ $key ] = $label;
			if ( ! $inserted && in_array( $key, array( 'title', 'name' ), true ) ) {
				$out['uxstudio_folder'] = __( 'Folder', 'ux-studio' );
				$inserted               = true;
			}
		}

		if ( ! $inserted ) {
			$out['uxstudio_folder'] = __( 'Folder', 'ux-studio' );
		}

		return $out;
	}

	/**
	 * Render the folder column cell.
	 *
	 * @param string $column  Column key.
	 * @param int    $post_id Post id.
	 */
	public function render_column( string $column, int $post_id ): void {
		if ( 'uxstudio_folder' !== $column ) {
			return;
		}

		$terms = get_the_terms( $post_id, Module::TAXONOMY );
		if ( ! is_array( $terms ) || empty( $terms ) ) {
			echo '<span class="uxs-folder-none">' . esc_html__( 'Uncategorized', 'ux-studio' ) . '</span>';
			return;
		}

		$term = reset( $terms );
		if ( ! $term instanceof WP_Term ) {
			return;
		}

		$post_type = get_post_type( $post_id );
		$base      = 'attachment' === $post_type
			? admin_url( 'upload.php?mode=list' )
			: admin_url( 'edit.php?post_type=' . rawurlencode( (string) $post_type ) );

		printf(
			'<a href="%s">%s</a>',
			esc_url( add_query_arg( Module::FILTER_VAR, (int) $term->term_id, $base ) ),
			esc_html( $term->name )
		);
	}

	// ------------------------------------------------------------------
	// Bulk action.
	// ------------------------------------------------------------------

	/**
	 * Add "Move to folder" to the bulk actions.
	 *
	 * @param array<string, string> $actions Actions.
	 * @return array<string, string>
	 */
	public function add_bulk_action( array $actions ): array {
		$actions[ self::BULK_ACTION ] = __( 'Move to folder', 'ux-studio' );

		return $actions;
	}

	/**
	 * Process the bulk action. Core has already verified the bulk-posts /
	 * bulk-media nonce before this filter runs; every item is additionally
	 * checked with edit_post in Module::bulk_move().
	 *
	 * @param string           $redirect Redirect URL.
	 * @param string           $action   Bulk action.
	 * @param array<int, int>  $post_ids Selected ids.
	 */
	public function handle_bulk_action( string $redirect, string $action, array $post_ids ): string {
		if ( self::BULK_ACTION !== $action || ! isset( $_REQUEST['uxstudio_bulk_folder'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return $redirect;
		}

		$folder_id = absint( wp_unslash( $_REQUEST['uxstudio_bulk_folder'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$result    = $this->module->bulk_move( $post_ids, $folder_id );

		if ( is_wp_error( $result ) ) {
			return add_query_arg(
				array(
					'uxstudio_moved'  => 0,
					'uxstudio_failed' => count( $post_ids ),
				),
				$redirect
			);
		}

		return add_query_arg(
			array(
				'uxstudio_moved'  => $result['updated'],
				'uxstudio_failed' => count( $result['skipped'] ),
			),
			$redirect
		);
	}

	/**
	 * Result notice after the bulk action.
	 */
	public function bulk_notice(): void {
		if ( ! isset( $_GET['uxstudio_moved'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}

		$moved  = absint( wp_unslash( $_GET['uxstudio_moved'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$failed = isset( $_GET['uxstudio_failed'] ) ? absint( wp_unslash( $_GET['uxstudio_failed'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( $moved > 0 ) {
			printf(
				'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
				esc_html(
					sprintf(
						/* translators: %d: number of items. */
						_n( '%d item moved to the folder.', '%d items moved to the folder.', $moved, 'ux-studio' ),
						$moved
					)
				)
			);
		}

		if ( $failed > 0 ) {
			printf(
				'<div class="notice notice-error is-dismissible"><p>%s</p></div>',
				esc_html(
					sprintf(
						/* translators: %d: number of items. */
						_n( '%d item could not be moved.', '%d items could not be moved.', $failed, 'ux-studio' ),
						$failed
					)
				)
			);
		}
	}

	// ------------------------------------------------------------------
	// Helpers.
	// ------------------------------------------------------------------

	/**
	 * Id of the folder an item is in (0 = none).
	 *
	 * @param int $post_id Post id.
	 */
	private function current_folder( int $post_id ): int {
		$ids = wp_get_object_terms( $post_id, Module::TAXONOMY, array( 'fields' => 'ids' ) );

		return is_array( $ids ) && ! empty( $ids ) ? (int) reset( $ids ) : 0;
	}

	/**
	 * Select options for a post type, depth-first with indentation (cached per request).
	 *
	 * @param string $post_type Post type slug.
	 * @return array<int, array{id:int,label:string}>
	 */
	private function choices( string $post_type ): array {
		if ( isset( $this->choices_cache[ $post_type ] ) ) {
			return $this->choices_cache[ $post_type ];
		}

		$by_parent = array();
		foreach ( $this->module->folder_terms( $post_type ) as $term ) {
			$by_parent[ (int) $term->parent ][] = $term;
		}

		$out  = array();
		$walk = static function ( int $parent, int $depth ) use ( &$walk, &$out, $by_parent ): void {
			foreach ( $by_parent[ $parent ] ?? array() as $term ) {
				$out[] = array(
					'id'    => (int) $term->term_id,
					'label' => str_repeat( '— ', $depth ) . $term->name,
				);
				if ( $depth < 20 ) {
					$walk( (int) $term->term_id, $depth + 1 );
				}
			}
		};
		$walk( 0, 0 );

		$this->choices_cache[ $post_type ] = $out;

		return $out;
	}

	/**
	 * Escaped HTML of a folder <select>.
	 *
	 * @param string $name       Field name.
	 * @param string $id         Element id.
	 * @param string $post_type  Post type whose folders are listed.
	 * @param int    $selected   Selected folder id.
	 * @param bool   $full_width Stretch to the container.
	 */
	private function select_html( string $name, string $id, string $post_type, int $selected, bool $full_width = false ): string {
		$html = sprintf(
			'<select name="%s" id="%s"%s><option value="0">%s</option>',
			esc_attr( $name ),
			esc_attr( $id ),
			$full_width ? ' style="width:100%"' : '',
			esc_html__( 'Uncategorized', 'ux-studio' )
		);

		foreach ( $this->choices( $post_type ) as $choice ) {
			$html .= sprintf(
				'<option value="%d"%s>%s</option>',
				$choice['id'],
				selected( $selected, $choice['id'], false ),
				esc_html( $choice['label'] )
			);
		}

		return $html . '</select>';
	}
}
