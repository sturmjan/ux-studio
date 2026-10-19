<?php
/**
 * Export Posts module - export post data to CSV / JSON / TXT.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\ExportPosts;

use UxStudio\Modules\BaseModule;
use WP_Error;
use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * Adds "Download as ..." row actions and "Export to ..." bulk actions to the
 * post list tables and streams the export through an admin-post handler with a
 * nonce. The SPA can also request a download URL through the module REST
 * endpoint. Ported from the legacy module (free + pro merged): the meta-field
 * export from the pro add-on is included, and post meta values are now read
 * from post meta (the legacy build exported them empty - see the port notes).
 */
final class Module extends BaseModule {

	/**
	 * admin-post action + nonce action.
	 */
	private const ACTION = 'uxstudio_export_posts';

	/**
	 * Valid export types.
	 */
	private const VALID_TYPES = array( 'csv', 'json', 'txt' );

	/**
	 * Register hooks.
	 */
	public function boot(): void {
		add_action( 'admin_post_' . self::ACTION, array( $this, 'handle_download' ) );
		add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );

		// Single-post export from the edit screen (classic metabox + block editor panel).
		add_action( 'add_meta_boxes', array( $this, 'add_meta_box' ), 10, 2 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_classic_assets' ) );
		add_action( 'enqueue_block_editor_assets', array( $this, 'enqueue_block_editor_assets' ) );

		foreach ( $this->get_post_types() as $post_type ) {
			add_filter( "bulk_actions-edit-{$post_type->name}", array( $this, 'add_bulk_actions' ) );
			add_filter( "handle_bulk_actions-edit-{$post_type->name}", array( $this, 'handle_bulk_export' ), 10, 3 );
			add_filter( "{$post_type->name}_row_actions", array( $this, 'add_row_actions' ), 10, 2 );
		}
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
	 * Names of exportable post types.
	 *
	 * @return string[]
	 */
	private function get_post_type_names(): array {
		return array_map( static fn ( $post_type ): string => (string) $post_type->name, $this->get_post_types() );
	}

	/**
	 * Add the "Export Post" metabox to the classic editor.
	 *
	 * @param string $post_type Post type.
	 */
	public function add_meta_box( $post_type ): void {
		if ( array() === $this->enabled_types() || ! in_array( $post_type, $this->get_post_type_names(), true ) ) {
			return;
		}

		$screen = get_current_screen();
		if ( $screen && method_exists( $screen, 'is_block_editor' ) && $screen->is_block_editor() ) {
			return; // Block editor uses the post status panel instead.
		}

		add_meta_box(
			'uxstudio-export-posts',
			__( 'Export Post', 'ux-studio' ),
			array( $this, 'render_meta_box' ),
			$post_type,
			'side',
			'high'
		);
	}

	/**
	 * Render the classic editor metabox (format select + export button).
	 *
	 * @param WP_Post $post Current post.
	 */
	public function render_meta_box( $post ): void {
		if ( ! $post instanceof WP_Post || ! current_user_can( 'edit_post', $post->ID ) ) {
			return;
		}
		?>
		<div class="uxstudio-export-posts">
			<select class="uxstudio-export-posts__select" aria-label="<?php esc_attr_e( 'Export post', 'ux-studio' ); ?>">
				<option value=""><?php esc_html_e( 'Select Type', 'ux-studio' ); ?></option>
				<?php foreach ( $this->enabled_types() as $type ) : ?>
					<option value="<?php echo esc_attr( $type ); ?>"><?php echo esc_html( strtoupper( $type ) ); ?></option>
				<?php endforeach; ?>
			</select>
			<button type="button" class="button uxstudio-export-posts__button"><?php esc_html_e( 'Export', 'ux-studio' ); ?></button>
		</div>
		<?php
	}

	/**
	 * Data handed to the edit-screen scripts.
	 *
	 * @param int $post_id Current post ID.
	 * @return array<string, mixed>
	 */
	private function script_data( int $post_id ): array {
		return array(
			'adminPostUrl' => admin_url( 'admin-post.php' ),
			'action'       => self::ACTION,
			'nonce'        => wp_create_nonce( self::ACTION ),
			'postId'       => $post_id,
			'types'        => $this->enabled_types(),
			'i18n'         => array(
				'selectType'    => __( 'Select Type', 'ux-studio' ),
				'confirmExport' => __( 'Are you sure you want to export this post?', 'ux-studio' ),
				'exporting'     => __( 'Exporting...', 'ux-studio' ),
				'export'        => __( 'Export', 'ux-studio' ),
				'ariaLabel'     => __( 'Export post', 'ux-studio' ),
			),
		);
	}

	/**
	 * Post ID of the edited post when the export UI may be shown.
	 *
	 * @return int Post ID, or 0 when the UI must not be shown.
	 */
	private function exportable_edit_post_id(): int {
		$post = get_post();
		if ( ! $post instanceof WP_Post || 'auto-draft' === $post->post_status || array() === $this->enabled_types() ) {
			return 0;
		}
		if ( ! in_array( $post->post_type, $this->get_post_type_names(), true ) || ! current_user_can( 'edit_post', $post->ID ) ) {
			return 0;
		}
		return (int) $post->ID;
	}

	/**
	 * Classic editor script (metabox button).
	 *
	 * @param string $hook Admin page hook.
	 */
	public function enqueue_classic_assets( $hook ): void {
		if ( 'post.php' !== $hook ) {
			return;
		}
		$screen = get_current_screen();
		if ( $screen && method_exists( $screen, 'is_block_editor' ) && $screen->is_block_editor() ) {
			return;
		}
		$post_id = $this->exportable_edit_post_id();
		if ( ! $post_id ) {
			return;
		}

		wp_enqueue_script(
			'uxstudio-export-posts-classic',
			UXSTUDIO_URL . 'includes/Modules/ExportPosts/assets/export-post-classic.js',
			array(),
			defined( 'UXSTUDIO_VERSION' ) ? UXSTUDIO_VERSION : false,
			true
		);
		wp_localize_script( 'uxstudio-export-posts-classic', 'uxStudioExportPost', $this->script_data( $post_id ) );
	}

	/**
	 * Block editor script (post status panel).
	 */
	public function enqueue_block_editor_assets(): void {
		$post_id = $this->exportable_edit_post_id();
		if ( ! $post_id ) {
			return;
		}

		wp_enqueue_script(
			'uxstudio-export-posts-editor',
			UXSTUDIO_URL . 'includes/Modules/ExportPosts/assets/export-post-editor.js',
			array( 'wp-plugins', 'wp-edit-post', 'wp-editor', 'wp-element', 'wp-components', 'wp-data' ),
			defined( 'UXSTUDIO_VERSION' ) ? UXSTUDIO_VERSION : false,
			true
		);
		wp_localize_script( 'uxstudio-export-posts-editor', 'uxStudioExportPost', $this->script_data( $post_id ) );
	}

	/**
	 * Exportable post types.
	 *
	 * @return \WP_Post_Type[]
	 */
	private function get_post_types(): array {
		$excluded = array( 'attachment', 'elementor_library', 'e-landing-page' );

		$post_types = get_post_types(
			array(
				'public'  => true,
				'show_ui' => true,
			),
			'objects'
		);

		foreach ( $excluded as $name ) {
			unset( $post_types[ $name ] );
		}

		return array_values( $post_types );
	}

	/**
	 * Enabled export types from settings, restricted to valid ones.
	 *
	 * @return string[]
	 */
	private function enabled_types(): array {
		$types = (array) $this->settings->get( 'export_types', array( 'csv' ) );
		return array_values( array_intersect( $types, self::VALID_TYPES ) );
	}

	/**
	 * Whether a type is a valid export type.
	 *
	 * @param string $type Export type.
	 */
	public function is_valid_type( string $type ): bool {
		return in_array( $type, self::VALID_TYPES, true );
	}

	/**
	 * Build a signed admin-post download URL.
	 *
	 * @param string $type Export type.
	 * @param int[]  $ids  Post IDs.
	 */
	public function build_download_url( string $type, array $ids ): string {
		return wp_nonce_url(
			add_query_arg(
				array(
					'action'      => self::ACTION,
					'export_type' => $type,
					'ids'         => implode( ',', array_map( 'absint', $ids ) ),
				),
				admin_url( 'admin-post.php' )
			),
			self::ACTION
		);
	}

	/**
	 * Add "Download as ..." row actions.
	 *
	 * @param array   $actions Row actions.
	 * @param WP_Post $post    Current post.
	 * @return array
	 */
	public function add_row_actions( array $actions, WP_Post $post ): array {
		if ( ! current_user_can( 'edit_post', $post->ID ) ) {
			return $actions;
		}

		foreach ( $this->enabled_types() as $type ) {
			$actions[ self::ACTION . '_' . $type ] = sprintf(
				'<a href="%s">%s</a>',
				esc_url( $this->build_download_url( $type, array( $post->ID ) ) ),
				esc_html( sprintf( /* translators: %s: export format */ __( 'Download as %s', 'ux-studio' ), strtoupper( $type ) ) )
			);
		}

		return $actions;
	}

	/**
	 * Add "Export to ..." bulk actions.
	 *
	 * @param array $actions Bulk actions.
	 * @return array
	 */
	public function add_bulk_actions( array $actions ): array {
		foreach ( $this->enabled_types() as $type ) {
			$actions[ self::ACTION . '_' . $type ] = sprintf(
				/* translators: %s: export format */
				__( 'Export to %s', 'ux-studio' ),
				strtoupper( $type )
			);
		}

		return $actions;
	}

	/**
	 * Handle a bulk export by redirecting to the signed download URL.
	 *
	 * @param string $sendback Redirect URL.
	 * @param string $doaction Selected bulk action.
	 * @param array  $ids      Selected post IDs.
	 * @return string
	 */
	public function handle_bulk_export( string $sendback, string $doaction, array $ids ): string {
		if ( ! preg_match( '/^' . self::ACTION . '_(csv|json|txt)$/', $doaction, $matches ) ) {
			return $sendback;
		}

		$ids = array_values( array_filter( array_map( 'absint', $ids ) ) );
		if ( array() === $ids ) {
			return $sendback;
		}

		return $this->build_download_url( $matches[1], $ids );
	}

	/**
	 * Stream the export (admin-post.php handler).
	 */
	public function handle_download(): void {
		if ( ! wp_verify_nonce( sanitize_key( $_GET['_wpnonce'] ?? '' ), self::ACTION ) ) {
			wp_die( esc_html__( 'Security check failed.', 'ux-studio' ), '', array( 'response' => 403 ) );
		}

		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to export posts.', 'ux-studio' ), '', array( 'response' => 403 ) );
		}

		$type = sanitize_key( $_GET['export_type'] ?? '' );
		if ( ! $this->is_valid_type( $type ) ) {
			wp_die( esc_html__( 'Invalid export type.', 'ux-studio' ), '', array( 'response' => 400 ) );
		}

		$raw_ids = sanitize_text_field( wp_unslash( $_GET['ids'] ?? '' ) );
		$ids     = array_values( array_filter( array_map( 'absint', explode( ',', $raw_ids ) ) ) );

		// Per-post capability check (prevents exporting posts the user cannot edit).
		$ids = array_values(
			array_filter(
				$ids,
				static fn ( int $id ): bool => current_user_can( 'edit_post', $id )
			)
		);

		if ( array() === $ids ) {
			wp_die( esc_html__( 'No valid post IDs provided.', 'ux-studio' ), '', array( 'response' => 400 ) );
		}

		$prepared = $this->prepare_export_data( $ids, $type );
		if ( is_wp_error( $prepared ) ) {
			wp_die( esc_html( $prepared->get_error_message() ), '', array( 'response' => 500 ) );
		}

		( new Exporter() )->stream( $prepared['data'], $type, $prepared['filename'], $prepared['keys'] );
	}

	/**
	 * Build the export dataset for the given posts.
	 *
	 * @param int[]  $post_ids Post IDs.
	 * @param string $type     Export type.
	 * @return array{data:array,filename:string,keys:array}|WP_Error
	 */
	public function prepare_export_data( array $post_ids, string $type ) {
		$default_fields = (array) $this->settings->get( 'default_fields', $this->default_field_defaults() );
		$meta_fields    = (array) $this->settings->get( 'meta_fields', array() );

		if ( array() === $default_fields && array() === $meta_fields ) {
			return new WP_Error( 'no_fields', __( 'No fields selected for export.', 'ux-studio' ) );
		}

		$labels = $this->get_default_fields();
		$fields = array();

		foreach ( $default_fields as $field ) {
			$fields[ $field ] = $labels[ $field ] ?? $field;
		}
		foreach ( $meta_fields as $field ) {
			$fields[ $field ] = $field;
		}

		$headers = array_values( $fields );
		$keys    = array_keys( $fields );
		$body    = array();

		foreach ( $post_ids as $post_id ) {
			$post = get_post( $post_id );
			if ( ! $post ) {
				continue;
			}

			$row = array();
			foreach ( $fields as $field => $label ) {
				$row[] = $this->get_field_value( $post, (string) $field, $labels );
			}
			$body[] = $row;
		}

		$filename = sprintf(
			'%s-posts-export-%s.%s',
			sanitize_file_name( get_bloginfo( 'name' ) ),
			gmdate( 'Y-m-d' ),
			$type
		);

		return array(
			'data'     => array(
				'headers' => $headers,
				'body'    => $body,
			),
			'filename' => $filename,
			'keys'     => $keys,
		);
	}

	/**
	 * Resolve a single field value for a post.
	 *
	 * @param WP_Post              $post   Post object.
	 * @param string               $field  Field key.
	 * @param array<string,string> $labels Known default field labels.
	 * @return string
	 */
	private function get_field_value( WP_Post $post, string $field, array $labels ): string {
		switch ( $field ) {
			case 'post_author':
				$author = get_user_by( 'ID', $post->post_author );
				return $author ? $author->display_name : '';

			case 'post_category':
				return implode( ', ', wp_list_pluck( get_the_category( $post->ID ), 'name' ) );

			case 'tags_input':
				$tags = get_the_tags( $post->ID );
				return $tags ? implode( ', ', wp_list_pluck( $tags, 'name' ) ) : '';

			case 'post_thumbnail':
				return (string) get_the_post_thumbnail_url( $post->ID, 'full' );
		}

		// Known post column.
		if ( isset( $labels[ $field ] ) && isset( $post->$field ) ) {
			return (string) $post->$field;
		}

		// Otherwise treat it as a post meta key.
		$value = get_post_meta( $post->ID, $field, true );
		if ( is_array( $value ) ) {
			return implode( ', ', array_map( 'strval', $value ) );
		}

		return (string) $value;
	}

	/**
	 * Default field defaults (pre-selected keys).
	 *
	 * @return string[]
	 */
	private function default_field_defaults(): array {
		return array(
			'ID',
			'post_title',
			'post_name',
			'post_content',
			'post_excerpt',
			'post_status',
			'post_type',
			'post_date',
			'post_author',
			'post_thumbnail',
		);
	}

	/**
	 * Selectable default fields: key => label.
	 *
	 * @return array<string, string>
	 */
	private function get_default_fields(): array {
		return array(
			'ID'                    => __( 'Post ID', 'ux-studio' ),
			'post_title'            => __( 'Title', 'ux-studio' ),
			'post_name'             => __( 'Slug', 'ux-studio' ),
			'post_content'          => __( 'Content', 'ux-studio' ),
			'post_excerpt'          => __( 'Excerpt', 'ux-studio' ),
			'post_status'           => __( 'Status', 'ux-studio' ),
			'post_type'             => __( 'Post Type', 'ux-studio' ),
			'post_date'             => __( 'Date', 'ux-studio' ),
			'post_modified'         => __( 'Modified Date', 'ux-studio' ),
			'post_author'           => __( 'Author', 'ux-studio' ),
			'post_thumbnail'        => __( 'Featured Image', 'ux-studio' ),
			'comment_count'         => __( 'Comment Count', 'ux-studio' ),
			'menu_order'            => __( 'Menu Order', 'ux-studio' ),
			'guid'                  => __( 'GUID', 'ux-studio' ),
			'post_parent'           => __( 'Parent Post', 'ux-studio' ),
			'post_password'         => __( 'Password', 'ux-studio' ),
			'post_date_gmt'         => __( 'Date (GMT)', 'ux-studio' ),
			'post_modified_gmt'     => __( 'Modified Date (GMT)', 'ux-studio' ),
			'post_content_filtered' => __( 'Filtered Content', 'ux-studio' ),
			'post_category'         => __( 'Categories', 'ux-studio' ),
			'tags_input'            => __( 'Tags', 'ux-studio' ),
			'comment_status'        => __( 'Comment Status', 'ux-studio' ),
			'ping_status'           => __( 'Ping Status', 'ux-studio' ),
			'post_mime_type'        => __( 'MIME Type', 'ux-studio' ),
		);
	}

	/**
	 * Distinct post meta keys for the meta-field selector (excluding internal keys).
	 *
	 * @return array<string, string>
	 */
	private function get_meta_fields(): array {
		global $wpdb;

		$excluded = array(
			'_wp_page_template',
			'_edit_lock',
			'_edit_last',
			'_wp_attachment_backup_sizes',
			'_wp_attachment_metadata',
			'_wp_attached_file',
			'_wp_attachment_image_alt',
			'_thumbnail_id',
			'_wp_old_slug',
			'_wp_old_date',
			'_wp_old_status',
			'_wp_trash_meta_status',
			'_wp_trash_meta_time',
			'_wp_desired_post_slug',
			'_menu_item_classes',
			'_menu_item_menu_item_parent',
			'_menu_item_object',
			'_menu_item_object_id',
			'_menu_item_target',
			'_menu_item_type',
			'_menu_item_url',
			'_menu_item_xfn',
		);

		$keys = $wpdb->get_col( "SELECT DISTINCT meta_key FROM {$wpdb->postmeta} ORDER BY meta_key" );
		$keys = array_diff( (array) $keys, $excluded );

		$fields = array();
		foreach ( $keys as $key ) {
			$fields[ $key ] = $key;
		}

		return $fields;
	}

	/**
	 * Settings schema for the generic renderer.
	 */
	public function settings_schema(): array {
		return array(
			array(
				'key'     => 'export_types',
				'type'    => 'multiselect',
				'label'   => __( 'Export types', 'ux-studio' ),
				'help'    => __( 'Formats offered in the row and bulk export actions.', 'ux-studio' ),
				'options' => array(
					'csv'  => __( 'CSV', 'ux-studio' ),
					'json' => __( 'JSON', 'ux-studio' ),
					'txt'  => __( 'TXT', 'ux-studio' ),
				),
				'default' => array( 'csv' ),
			),
			array(
				'key'     => 'default_fields',
				'type'    => 'multiselect',
				'label'   => __( 'Fields', 'ux-studio' ),
				'help'    => __( 'Post fields included in the export.', 'ux-studio' ),
				'options' => $this->get_default_fields(),
				'default' => $this->default_field_defaults(),
			),
			array(
				'key'     => 'meta_fields',
				'type'    => 'multiselect',
				'label'   => __( 'Meta fields', 'ux-studio' ),
				'help'    => __( 'Custom fields (post meta) to include in the export.', 'ux-studio' ),
				'options' => $this->get_meta_fields(),
				'default' => array(),
			),
		);
	}
}
