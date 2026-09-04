<?php
/**
 * Admin Columns module - configurable admin list-table columns.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\AdminColumns;

use UxStudio\Modules\BaseModule;

defined( 'ABSPATH' ) || exit;

/**
 * Lets you relabel, reorder, hide and add custom columns (meta, taxonomy,
 * post ID, thumbnail) in the admin list tables for post types, media,
 * comments and users. Ported pragmatically from the legacy module: the column
 * configuration is stored per content type and the column builder UI is served
 * later by the SPA through the REST endpoints; rendering happens 1:1 through
 * the native manage_* filters.
 */
final class Module extends BaseModule {

	/** Option holding the whole configuration, keyed by content type. */
	private const OPTION = 'uxstudio_admin_columns';

	/** Legacy option (wpextended) read as a fallback. */
	private const LEGACY_OPTION = 'wpextended__admin-columns';

	/**
	 * Register hooks.
	 */
	public function boot(): void {
		add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );
		// Post types are only registered on init, so attach the column hooks late.
		add_action( 'init', array( $this, 'register_columns' ), 99 );
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
	 * Attach the list-table hooks for every configured content type.
	 */
	public function register_columns(): void {
		if ( ! is_admin() ) {
			return;
		}

		$config = $this->all_config();
		foreach ( $config as $content_type => $columns ) {
			if ( empty( $columns ) ) {
				continue;
			}
			( new ColumnRenderer( (string) $content_type, (array) $columns ) )->register();
		}
	}

	/**
	 * Whole stored configuration (our option only), keyed by content type.
	 *
	 * @return array<string, array>
	 */
	public function all_config(): array {
		$stored = get_option( self::OPTION, array() );
		return is_array( $stored ) ? $stored : array();
	}

	/**
	 * Column configuration for one content type. Falls back to the legacy
	 * wpextended format when we have nothing stored yet.
	 *
	 * @param string $content_type Post type, or 'users' | 'comments' | 'attachment'.
	 * @return array<int, array{key:string,label:string,type:string,field_type:string,enabled:bool,width:string}>
	 */
	public function get_config( string $content_type ): array {
		$all = $this->all_config();
		if ( ! empty( $all[ $content_type ] ) && is_array( $all[ $content_type ] ) ) {
			return array_values( array_map( array( $this, 'normalize_column' ), $all[ $content_type ] ) );
		}
		return $this->legacy_config( $content_type );
	}

	/**
	 * Persist the column configuration for one content type.
	 *
	 * @param string $content_type Content type key.
	 * @param array  $columns      Raw column definitions from the SPA.
	 * @return array Normalized, stored columns.
	 */
	public function save_config( string $content_type, array $columns ): array {
		$clean = array();
		foreach ( $columns as $column ) {
			if ( ! is_array( $column ) || empty( $column['key'] ) ) {
				continue;
			}
			$clean[] = $this->normalize_column( $column );
		}

		$all                  = $this->all_config();
		$all[ $content_type ] = $clean;
		update_option( self::OPTION, $all );

		return $clean;
	}

	/**
	 * Coerce one column definition into the canonical shape.
	 *
	 * `type` is the data source (default native column, post/user/comment meta,
	 * taxonomy, the object ID, or the featured image). `field_type` is the
	 * renderer applied to a meta value (text, number, boolean, date, image,
	 * url, email, color, post relationship); it is ignored for non-meta sources
	 * but always stored so the SPA round-trips it.
	 *
	 * @param array $column Raw column.
	 * @return array{key:string,label:string,type:string,field_type:string,enabled:bool,width:string}
	 */
	public function normalize_column( array $column ): array {
		$allowed_types = array( 'default', 'meta', 'taxonomy', 'post_id', 'thumbnail' );
		$type          = isset( $column['type'] ) && in_array( $column['type'], $allowed_types, true )
			? $column['type']
			: 'default';

		$field_type = isset( $column['field_type'] ) && FieldRenderer::is_valid_type( (string) $column['field_type'] )
			? (string) $column['field_type']
			: 'text';

		return array(
			'key'        => sanitize_key( (string) ( $column['key'] ?? '' ) ),
			'label'      => sanitize_text_field( (string) ( $column['label'] ?? '' ) ),
			'type'       => $type,
			'field_type' => $field_type,
			'enabled'    => ! isset( $column['enabled'] ) || (bool) $column['enabled'],
			'width'      => sanitize_text_field( (string) ( $column['width'] ?? '' ) ),
		);
	}

	/**
	 * Convert the legacy wpextended column format to the canonical shape.
	 *
	 * @param string $content_type Content type key.
	 * @return array
	 */
	private function legacy_config( string $content_type ): array {
		$legacy = get_option( self::LEGACY_OPTION, array() );
		if ( ! is_array( $legacy ) ) {
			return array();
		}

		$key  = ( 'attachment' === $content_type ) ? 'attachment_columns' : $content_type . '_columns';
		$rows = $legacy[ $key ] ?? array();
		if ( ! is_array( $rows ) ) {
			return array();
		}

		$columns = array();
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$label     = (string) ( $row['column_label'] ?? '' );
			$is_custom = ( 'custom_column' === $label );
			$col_key   = $is_custom ? sanitize_key( (string) ( $row['column_title'] ?? '' ) ) : sanitize_key( $label );
			if ( '' === $col_key ) {
				continue;
			}
			$type = 'default';
			if ( ! empty( $row['data_source'] ) && 'meta' === $row['data_source'] ) {
				$type    = 'meta';
				$col_key = sanitize_key( (string) ( $row['meta_key'] ?? $col_key ) );
			}
			$columns[] = array(
				'key'        => $col_key,
				'label'      => sanitize_text_field( (string) ( $row['column_title'] ?? $col_key ) ),
				'type'       => $type,
				'field_type' => 'text',
				'enabled'    => empty( $row['disable_column'] ),
				'width'      => '',
			);
		}

		return $columns;
	}
}
