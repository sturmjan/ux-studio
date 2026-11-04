<?php
/**
 * Tables module - tables built in a grid editor, embedded by shortcode.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\Tables;

use UxStudio\Core\ActivityLog;
use UxStudio\Modules\BaseModule;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Replaces TablePress (PLAN §41). A table is a private CPT (uxstudio_table):
 * the cells are a JSON 2D array of strings in post_content - the same shape
 * TablePress uses, so a migration is 1:1 - and the display options are one
 * JSON post meta (_uxstudio_table_options).
 *
 * Cell content is limited to a small wp_kses allowlist (links, line breaks,
 * basic emphasis, images). The size is capped so a table cannot grow without
 * bounds from a runaway import.
 */
final class Module extends BaseModule {

	public const CPT         = 'uxstudio_table';
	public const META        = '_uxstudio_table_options';
	public const LEGACY_META = '_uxstudio_table_legacy_id';

	public const MAX_ROWS = 500;
	public const MAX_COLS = 50;

	private const MAX_CELL_LENGTH = 20000;

	public const RESPONSIVE = array( 'scroll', 'stack', 'none' );

	/**
	 * Register hooks.
	 */
	public function boot(): void {
		add_action( 'init', array( $this, 'register_post_type' ) );
		add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );
		( new Frontend( $this ) )->register();
		// Fired only by Elementor itself, so the widget class (which extends an Elementor class) is never loaded without it.
		add_action(
			'elementor/widgets/register',
			function ( $widgets_manager ): void {
				ElementorWidget::register_with( $this, $widgets_manager );
			}
		);
	}

	/**
	 * Private CPT holding the tables.
	 */
	public function register_post_type(): void {
		register_post_type(
			self::CPT,
			array(
				'public'          => false,
				'show_ui'         => false,
				'show_in_rest'    => false,
				'capability_type' => 'post',
				'supports'        => array( 'title', 'excerpt' ),
				'label'           => __( 'Tables', 'ux-studio' ),
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
	 * Settings schema: defaults for new tables.
	 */
	public function settings_schema(): array {
		return array(
			array(
				'key'     => 'default_striped',
				'type'    => 'toggle',
				'label'   => __( 'Striped rows in new tables', 'ux-studio' ),
				'help'    => __( 'Existing tables keep their own setting.', 'ux-studio' ),
				'default' => true,
			),
			array(
				'key'     => 'default_responsive',
				'type'    => 'select',
				'label'   => __( 'Narrow screens in new tables', 'ux-studio' ),
				'default' => 'scroll',
				'options' => $this->responsive_options(),
			),
		);
	}

	/**
	 * Responsive mode options (value => label).
	 *
	 * @return array<string, string>
	 */
	public function responsive_options(): array {
		return array(
			'scroll' => __( 'Scroll sideways', 'ux-studio' ),
			'stack'  => __( 'Stack rows as cards', 'ux-studio' ),
			'none'   => __( 'Leave as is', 'ux-studio' ),
		);
	}

	/* ------------------------------------------------------------------ *
	 * Sanitizing
	 * ------------------------------------------------------------------ */

	/**
	 * Options of a brand new table.
	 *
	 * @return array<string, mixed>
	 */
	public function default_options(): array {
		$responsive = (string) $this->settings->get( 'default_responsive', 'scroll' );
		return array(
			'head'             => true,
			'foot'             => false,
			'striped'          => (bool) $this->settings->get( 'default_striped', true ),
			'hover'            => true,
			'responsive'       => in_array( $responsive, self::RESPONSIVE, true ) ? $responsive : 'scroll',
			'sort'             => false,
			'search'           => false,
			'paginate'         => false,
			'page_size'        => 10,
			'caption'          => '',
			'caption_position' => 'above',
			'extra_class'      => '',
		);
	}

	/**
	 * Normalize display options. Missing keys keep the defaults.
	 *
	 * @param array $input Raw options.
	 * @return array<string, mixed>
	 */
	public function sanitize_options( array $input ): array {
		$options = $this->default_options();
		foreach ( array( 'head', 'foot', 'striped', 'hover', 'sort', 'search', 'paginate' ) as $flag ) {
			if ( array_key_exists( $flag, $input ) ) {
				$options[ $flag ] = ! empty( $input[ $flag ] );
			}
		}
		if ( isset( $input['responsive'] ) && in_array( $input['responsive'], self::RESPONSIVE, true ) ) {
			$options['responsive'] = $input['responsive'];
		}
		if ( isset( $input['page_size'] ) ) {
			$options['page_size'] = min( 200, max( 1, (int) $input['page_size'] ) );
		}
		if ( isset( $input['caption'] ) ) {
			$options['caption'] = sanitize_text_field( (string) $input['caption'] );
		}
		if ( isset( $input['caption_position'] ) ) {
			$options['caption_position'] = 'below' === $input['caption_position'] ? 'below' : 'above';
		}
		if ( isset( $input['extra_class'] ) ) {
			$classes                = array_filter( array_map( 'sanitize_html_class', preg_split( '/\s+/', trim( (string) $input['extra_class'] ) ) ?: array() ) );
			$options['extra_class'] = implode( ' ', $classes );
		}
		return $options;
	}

	/**
	 * Allowed HTML inside a cell.
	 *
	 * @return array<string, array<string, bool>>
	 */
	public static function allowed_html(): array {
		return array(
			'a'      => array(
				'href'   => true,
				'title'  => true,
				'target' => true,
				'rel'    => true,
			),
			'br'     => array(),
			'strong' => array(),
			'b'      => array(),
			'em'     => array(),
			'i'      => array(),
			'span'   => array( 'class' => true ),
			'img'    => array(
				'src'    => true,
				'alt'    => true,
				'width'  => true,
				'height' => true,
			),
		);
	}

	/**
	 * One cell: a string limited to the allowlist.
	 *
	 * @param mixed $cell Raw cell.
	 */
	public function sanitize_cell( $cell ): string {
		$cell = is_scalar( $cell ) ? (string) $cell : '';
		return wp_kses( mb_substr( $cell, 0, self::MAX_CELL_LENGTH ), self::allowed_html(), array( 'http', 'https', 'mailto', 'tel' ) );
	}

	/**
	 * Normalize the grid: strings only, every row as long as the longest,
	 * at least one cell. Too large a grid is an error, never a silent cut.
	 *
	 * @param mixed $input Raw 2D array.
	 * @return array<int, array<int, string>>|WP_Error
	 */
	public function sanitize_cells( $input ) {
		$rows = array();
		foreach ( is_array( $input ) ? array_values( $input ) : array() as $row ) {
			$rows[] = array_map( array( $this, 'sanitize_cell' ), is_array( $row ) ? array_values( $row ) : array( $row ) );
		}
		$cols = $rows ? max( array_map( 'count', $rows ) ) : 0;
		if ( count( $rows ) > self::MAX_ROWS || $cols > self::MAX_COLS ) {
			return new WP_Error(
				'uxstudio_table_too_large',
				/* translators: 1: max rows, 2: max columns */
				sprintf( __( 'The table is too large. The limit is %1$d rows and %2$d columns.', 'ux-studio' ), self::MAX_ROWS, self::MAX_COLS ),
				array( 'status' => 400 )
			);
		}
		if ( ! $rows || ! $cols ) {
			return array( array( '' ) );
		}
		foreach ( $rows as $i => $row ) {
			$rows[ $i ] = array_pad( $row, $cols, '' );
		}
		return $rows;
	}

	/* ------------------------------------------------------------------ *
	 * Storage
	 * ------------------------------------------------------------------ */

	/**
	 * @param \WP_Post $post       Table post.
	 * @param bool     $with_cells Include the whole grid (the list sends only the size).
	 * @return array<string, mixed>
	 */
	private function to_array( \WP_Post $post, bool $with_cells ): array {
		$cells   = json_decode( $post->post_content, true );
		$cells   = is_array( $cells ) ? $cells : array();
		$options = json_decode( (string) get_post_meta( $post->ID, self::META, true ), true );
		$data    = array(
			'id'          => $post->ID,
			'title'       => $post->post_title,
			'description' => $post->post_excerpt,
			'options'     => $this->sanitize_options( is_array( $options ) ? $options : array() ),
			'rows'        => count( $cells ),
			'cols'        => count( (array) ( reset( $cells ) ?: array() ) ),
			'legacy_id'   => (int) get_post_meta( $post->ID, self::LEGACY_META, true ),
			'modified'    => $post->post_modified_gmt,
		);
		if ( $with_cells ) {
			$data['cells'] = $cells ? array_map( static fn ( $row ): array => array_map( 'strval', array_values( (array) $row ) ), array_values( $cells ) ) : array( array( '' ) );
		}
		return $data;
	}

	/**
	 * All tables, newest first, without cells.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function list_tables(): array {
		$posts = get_posts(
			array(
				'post_type'      => self::CPT,
				'post_status'    => 'publish',
				'posts_per_page' => 500,
				'orderby'        => 'date',
				'order'          => 'DESC',
			)
		);
		return array_map( fn ( $post ): array => $this->to_array( $post, false ), $posts );
	}

	/**
	 * One table with its cells.
	 *
	 * @param int $id Table ID.
	 * @return array<string, mixed>|null
	 */
	public function get_table( int $id ): ?array {
		$post = get_post( $id );
		return $post && self::CPT === $post->post_type && 'publish' === $post->post_status ? $this->to_array( $post, true ) : null;
	}

	/**
	 * Create (id 0) or update a table. Everything is sanitized here, so the
	 * editor, the duplicate action and the migration share one validation.
	 *
	 * @param int   $id    Table ID or 0.
	 * @param array $input {title, description, cells, options, legacy_id}.
	 * @return array<string, mixed>|WP_Error
	 */
	public function save_table( int $id, array $input ) {
		if ( $id && null === $this->get_table( $id ) ) {
			return new WP_Error( 'uxstudio_table_not_found', __( 'Table not found.', 'ux-studio' ), array( 'status' => 404 ) );
		}

		$cells = $this->sanitize_cells( $input['cells'] ?? array() );
		if ( is_wp_error( $cells ) ) {
			return $cells;
		}

		$title   = sanitize_text_field( (string) ( $input['title'] ?? '' ) );
		$options = $this->sanitize_options( (array) ( $input['options'] ?? array() ) );
		$postarr = array(
			'post_type'    => self::CPT,
			'post_status'  => 'publish',
			'post_title'   => '' !== $title ? $title : __( 'Table', 'ux-studio' ),
			'post_excerpt' => sanitize_textarea_field( (string) ( $input['description'] ?? '' ) ),
			'post_content' => wp_slash( (string) wp_json_encode( $cells, JSON_UNESCAPED_UNICODE ) ),
		);
		if ( $id ) {
			$postarr['ID'] = $id;
		}

		// The cells are already limited to the allowlist above; the post content filters
		// would only mangle the JSON (escaped quotes inside link attributes) for users without unfiltered_html.
		kses_remove_filters();
		$saved = $id ? wp_update_post( $postarr, true ) : wp_insert_post( $postarr, true );
		kses_init();
		if ( is_wp_error( $saved ) ) {
			return $saved;
		}

		update_post_meta( (int) $saved, self::META, wp_slash( (string) wp_json_encode( $options, JSON_UNESCAPED_UNICODE ) ) );
		if ( ! empty( $input['legacy_id'] ) ) {
			update_post_meta( (int) $saved, self::LEGACY_META, (int) $input['legacy_id'] );
		}

		ActivityLog::log( 'tables', $id ? 'update' : 'create', 'table', (int) $saved, array( 'title' => $postarr['post_title'] ) );
		return (array) $this->get_table( (int) $saved );
	}

	/**
	 * Copy a table (without the legacy ID, so the alias keeps pointing at the original).
	 *
	 * @param int $id Table ID.
	 * @return array<string, mixed>|WP_Error
	 */
	public function duplicate_table( int $id ) {
		$table = $this->get_table( $id );
		if ( null === $table ) {
			return new WP_Error( 'uxstudio_table_not_found', __( 'Table not found.', 'ux-studio' ), array( 'status' => 404 ) );
		}
		return $this->save_table(
			0,
			array(
				/* translators: %s: table name */
				'title'       => sprintf( __( '%s (copy)', 'ux-studio' ), $table['title'] ),
				'description' => $table['description'],
				'cells'       => $table['cells'],
				'options'     => $table['options'],
			)
		);
	}

	/**
	 * Delete a table.
	 *
	 * @param int $id Table ID.
	 * @return true|WP_Error
	 */
	public function delete_table( int $id ) {
		$table = $this->get_table( $id );
		if ( null === $table ) {
			return new WP_Error( 'uxstudio_table_not_found', __( 'Table not found.', 'ux-studio' ), array( 'status' => 404 ) );
		}
		wp_delete_post( $id, true );
		ActivityLog::log( 'tables', 'delete', 'table', $id, array( 'title' => (string) $table['title'] ) );
		return true;
	}

	/**
	 * Table ID created from a legacy plugin's ID (TablePress), or 0.
	 *
	 * @param int $legacy_id Legacy ID.
	 */
	public function find_by_legacy_id( int $legacy_id ): int {
		$found = get_posts(
			array(
				'post_type'      => self::CPT,
				'post_status'    => 'publish',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'meta_key'       => self::LEGACY_META, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'     => (string) $legacy_id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			)
		);
		return $found ? (int) $found[0] : 0;
	}
}
