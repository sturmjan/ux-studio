<?php
/**
 * Renders configured columns into an admin list table.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\AdminColumns;

defined( 'ABSPATH' ) || exit;

/**
 * Attaches the native manage_* filters/actions for a single content type and
 * renders each configured column by its declared type. Ported 1:1 from the
 * legacy ColumnManager, trimmed to the supported column types.
 */
final class ColumnRenderer {

	private string $content_type;

	/** @var array<int, array{key:string,label:string,type:string,field_type:string,enabled:bool,width:string}> */
	private array $columns;

	private FieldRenderer $fields;

	/**
	 * @param string $content_type Post type, or 'users' | 'comments' | 'attachment'.
	 * @param array  $columns      Normalized column definitions.
	 */
	public function __construct( string $content_type, array $columns ) {
		$this->content_type = $content_type;
		$this->columns      = $columns;
		$this->fields       = new FieldRenderer();
	}

	/**
	 * Register the appropriate list-table hooks for this content type.
	 */
	public function register(): void {
		if ( 'users' === $this->content_type ) {
			add_filter( 'manage_users_columns', array( $this, 'filter_columns' ) );
			add_filter( 'manage_users_custom_column', array( $this, 'render_user_column' ), 10, 3 );
			add_action( 'admin_head-users.php', array( $this, 'print_styles' ) );
		} elseif ( 'attachment' === $this->content_type ) {
			add_filter( 'manage_media_columns', array( $this, 'filter_columns' ) );
			add_action( 'manage_media_custom_column', array( $this, 'render_post_column' ), 10, 2 );
			add_action( 'admin_head-upload.php', array( $this, 'print_styles' ) );
		} elseif ( 'comments' === $this->content_type ) {
			add_filter( 'manage_edit-comments_columns', array( $this, 'filter_columns' ) );
			add_action( 'manage_comments_custom_column', array( $this, 'render_comment_column' ), 10, 2 );
			add_action( 'admin_head-edit-comments.php', array( $this, 'print_styles' ) );
		} else {
			add_filter( "manage_edit-{$this->content_type}_columns", array( $this, 'filter_columns' ), 9999 );
			add_action( "manage_{$this->content_type}_posts_custom_column", array( $this, 'render_post_column' ), 20, 2 );
			add_action( 'admin_head-edit.php', array( $this, 'print_styles' ) );
		}
	}

	/**
	 * Build the column header map: apply order, labels and hidden columns while
	 * keeping the checkbox first and appending untouched original columns.
	 *
	 * @param array $original Existing columns from WordPress/other plugins.
	 * @return array
	 */
	public function filter_columns( array $original ): array {
		$new = array();
		if ( isset( $original['cb'] ) ) {
			$new['cb'] = $original['cb'];
		}

		$disabled  = array();
		$known_key = array();
		foreach ( $this->columns as $column ) {
			$known_key[ $column['key'] ] = true;
			if ( ! $column['enabled'] ) {
				$disabled[ $column['key'] ] = true;
				continue;
			}
			$new[ $column['key'] ] = '' !== $column['label'] ? $column['label'] : ( $original[ $column['key'] ] ?? $column['key'] );
		}

		foreach ( $original as $key => $label ) {
			if ( 'cb' === $key || isset( $new[ $key ] ) || isset( $disabled[ $key ] ) || isset( $known_key[ $key ] ) ) {
				continue;
			}
			$new[ $key ] = $label;
		}

		return $new;
	}

	/**
	 * Render a post / media column value.
	 *
	 * @param string $column  Column key.
	 * @param int    $post_id Post ID.
	 */
	public function render_post_column( string $column, int $post_id ): void {
		$config = $this->find( $column );
		if ( null === $config ) {
			return;
		}

		switch ( $config['type'] ) {
			case 'meta':
				$values = get_post_meta( $post_id, $config['key'] );
				// FieldRenderer returns fully-escaped HTML for the configured field type.
				echo $this->fields->render( $config['field_type'] ?? 'text', (array) $values ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				break;
			case 'taxonomy':
				$this->render_taxonomy( $post_id, $config['key'] );
				break;
			case 'post_id':
				echo (int) $post_id;
				break;
			case 'thumbnail':
				echo has_post_thumbnail( $post_id )
					? wp_kses_post( get_the_post_thumbnail( $post_id, array( 60, 60 ) ) )
					: '';
				break;
		}
	}

	/**
	 * Render a users list column value (filter must return the value).
	 *
	 * @param string $value   Current value.
	 * @param string $column  Column key.
	 * @param int    $user_id User ID.
	 * @return string
	 */
	public function render_user_column( string $value, string $column, int $user_id ): string {
		$config = $this->find( $column );
		if ( null === $config ) {
			return $value;
		}

		switch ( $config['type'] ) {
			case 'meta':
				$values = get_user_meta( $user_id, $config['key'] );
				return $this->fields->render( $config['field_type'] ?? 'text', (array) $values );
			case 'post_id':
				return (string) (int) $user_id;
			default:
				return $value;
		}
	}

	/**
	 * Render a comments list column value.
	 *
	 * @param string $column     Column key.
	 * @param int    $comment_id Comment ID.
	 */
	public function render_comment_column( string $column, int $comment_id ): void {
		$config = $this->find( $column );
		if ( null === $config ) {
			return;
		}

		switch ( $config['type'] ) {
			case 'meta':
				$values = get_comment_meta( $comment_id, $config['key'] );
				// FieldRenderer returns fully-escaped HTML for the configured field type.
				echo $this->fields->render( $config['field_type'] ?? 'text', (array) $values ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				break;
			case 'post_id':
				echo (int) $comment_id;
				break;
		}
	}

	/**
	 * Output column-width CSS in the admin head.
	 */
	public function print_styles(): void {
		$rules = array();
		foreach ( $this->columns as $column ) {
			if ( $column['enabled'] && '' !== $column['width'] ) {
				$rules[] = sprintf( '.wp-list-table .column-%s { width: %s; }', sanitize_html_class( $column['key'] ), esc_attr( $column['width'] ) );
			}
		}
		if ( $rules ) {
			printf( '<style id="uxstudio-admin-columns-%s">%s</style>', esc_attr( $this->content_type ), implode( "\n", $rules ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
	}

	/**
	 * Print the terms of a taxonomy for a post.
	 *
	 * @param int    $post_id  Post ID.
	 * @param string $taxonomy Taxonomy name.
	 */
	private function render_taxonomy( int $post_id, string $taxonomy ): void {
		if ( ! taxonomy_exists( $taxonomy ) ) {
			return;
		}
		$terms = get_the_terms( $post_id, $taxonomy );
		if ( ! $terms || is_wp_error( $terms ) ) {
			return;
		}
		echo esc_html( implode( ', ', wp_list_pluck( $terms, 'name' ) ) );
	}

	/**
	 * Find the configuration for a rendered column key.
	 *
	 * @param string $column Column key.
	 * @return array|null
	 */
	private function find( string $column ): ?array {
		foreach ( $this->columns as $config ) {
			if ( $config['enabled'] && $config['key'] === $column ) {
				return $config;
			}
		}
		return null;
	}
}
