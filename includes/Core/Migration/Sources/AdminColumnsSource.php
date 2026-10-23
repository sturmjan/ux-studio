<?php
/**
 * Source: Admin Columns (codepress-admin-columns, version 4 and later, and the older option storage).
 *
 * @package UxStudio
 */

namespace UxStudio\Core\Migration\Sources;

use UxStudio\Core\Migration\AbstractSource;
use UxStudio\Core\Migration\Mapping;
use UxStudio\Core\Migration\Staging;
use UxStudio\Modules\AdminColumns\ColumnQuery;
use UxStudio\Modules\AdminColumns\Module as ColumnsModule;

defined( 'ABSPATH' ) || exit;

/**
 * Admin Columns 4+ keeps one row per list screen in `{prefix}admin_columns` (`list_key`, `list_id`, `title`,
 * `columns` = PHP-serialized `name => settings`, `settings`, `status`); versions before 4 keep
 * `cpac_options_<list_key>` options with the same `name => settings` array. A column setting has `type`
 * (a WordPress column key for native columns, `column-meta`, `column-featured_image`, `column-postid` ...),
 * `label`, `width` + `width_unit`, and type specific fields (`field`, `field_type`, `taxonomy`).
 *
 * Target: option `uxstudio_admin_columns` of the Admin Columns module, keyed by content type
 * (post type, `attachment`, `users`, `comments`); each column is `key, label, type, field_type, enabled, width, sortable,
 * filterable` (the `sort` / `filter` settings of a column are carried over for the lists of post types).
 * A native column the plugin removed becomes a column with `enabled => false`.
 */
final class AdminColumnsSource extends AbstractSource {

	private const MODULE = 'admin-columns';

	/** WordPress list tables: columns a table has by itself (what "removed in Admin Columns" is measured against). */
	private const NATIVE = array(
		'post'       => array( 'title', 'author', 'categories', 'tags', 'comments', 'date' ),
		'page'       => array( 'title', 'author', 'comments', 'date' ),
		'attachment' => array( 'title', 'author', 'parent', 'comments', 'date' ),
		'users'      => array( 'username', 'name', 'email', 'role', 'posts' ),
		'comments'   => array( 'author', 'comment', 'response', 'date' ),
		'_default'   => array( 'title', 'author', 'date' ),
	);

	/** Admin Columns field type => renderer of the module. */
	private const FIELD_TYPES = array(
		''            => 'text',
		'text'        => 'text',
		'numeric'     => 'number',
		'checkmark'   => 'boolean',
		'date'        => 'date',
		'image'       => 'image',
		'link'        => 'url',
		'color'       => 'color',
		'title_by_id' => 'post',
		'email'       => 'email',
	);

	/** @var callable|null Test seam: returns the list screens (see read_lists()). */
	private static $reader = null;

	/**
	 * Replace how the list screens are read (tests only).
	 *
	 * @param callable|null $reader Returns array<int, array{list_key:string,list_id:string,title:string,status:string,columns:array,settings:array}>.
	 */
	public static function set_reader( ?callable $reader ): void {
		self::$reader = $reader;
	}

	/**
	 * Every stored list screen, oldest first.
	 *
	 * @return array<int, array{list_key:string,list_id:string,title:string,status:string,columns:array<string,mixed>,settings:array<string,mixed>}>
	 */
	private function read_lists(): array {
		if ( null !== self::$reader ) {
			return (array) call_user_func( self::$reader );
		}
		global $wpdb;
		$out = array();
		if ( ! isset( $wpdb ) ) {
			return $out;
		}
		$table = $wpdb->prefix . 'admin_columns';
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) === $table ) {
			$rows = $wpdb->get_results( "SELECT list_key, list_id, title, status, columns, settings FROM `{$table}` ORDER BY id ASC", ARRAY_A ); // phpcs:ignore WordPress.DB
			foreach ( (array) $rows as $row ) {
				$columns  = ! empty( $row['columns'] ) ? @unserialize( (string) $row['columns'], array( 'allowed_classes' => false ) ) : array(); // phpcs:ignore
				$settings = ! empty( $row['settings'] ) ? @unserialize( (string) $row['settings'], array( 'allowed_classes' => false ) ) : array(); // phpcs:ignore
				$out[]    = array(
					'list_key' => (string) $row['list_key'],
					'list_id'  => (string) $row['list_id'],
					'title'    => (string) $row['title'],
					'status'   => (string) $row['status'],
					'columns'  => is_array( $columns ) ? $columns : array(),
					'settings' => is_array( $settings ) ? $settings : array(),
				);
			}
		}
		if ( ! $out ) {
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( 'cpac_options_' ) . '%' ), ARRAY_A ); // phpcs:ignore WordPress.DB
			foreach ( (array) $rows as $row ) {
				$key     = substr( (string) $row['option_name'], strlen( 'cpac_options_' ) );
				$columns = @unserialize( (string) $row['option_value'], array( 'allowed_classes' => false ) ); // phpcs:ignore
				if ( '' !== $key && is_array( $columns ) && $columns ) {
					$out[] = array(
						'list_key' => $key,
						'list_id'  => '',
						'title'    => '',
						'status'   => '',
						'columns'  => $columns,
						'settings' => array(),
					);
				}
			}
		}
		return $out;
	}

	/**
	 * An Admin Columns on / off setting (`on`, `1`, true).
	 *
	 * @param mixed $value Stored value.
	 */
	private function flag( $value ): bool {
		return true === $value || 1 === $value || in_array( strtolower( (string) $value ), array( 'on', '1', 'yes', 'true' ), true );
	}

	protected function has_data(): bool {
		foreach ( $this->read_lists() as $list ) {
			if ( ! empty( $list['columns'] ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Content type of the module for an Admin Columns list key (null = not supported).
	 *
	 * @param string $list_key Admin Columns list key.
	 */
	private function content_type( string $list_key ): ?string {
		switch ( $list_key ) {
			case 'wp-media':
				return 'attachment';
			case 'wp-users':
				return 'users';
			case 'wp-comments':
				return 'comments';
		}
		if ( 0 === strpos( $list_key, 'wp-' ) ) {
			return null; // Taxonomy and network screens.
		}
		return $list_key;
	}

	/**
	 * Width as the module stores it (`10%`, `120px`).
	 *
	 * @param array<string, mixed> $c Column setting.
	 */
	private function width( array $c ): string {
		$w = trim( (string) ( $c['width'] ?? '' ) );
		if ( '' === $w || ! is_numeric( $w ) || (float) $w <= 0 ) {
			return '';
		}
		$unit = 'px' === ( $c['width_unit'] ?? '%' ) ? 'px' : '%';
		return $w . $unit;
	}

	protected function map( bool $active ): Mapping {
		$m     = new Mapping();
		$lists = $this->read_lists();
		if ( ! $lists ) {
			$m->note( __( 'The plugin has no saved column settings, there is nothing to transfer.', 'ux-studio' ) );
			return $m;
		}

		$values = array();
		$labels = array();
		$types  = array();
		$skipped_lists = array();
		$extra_layouts = 0;
		$per_role      = false;
		$unsupported   = array();
		$field_lost    = array();
		$sf_lost       = array();
		$seen          = array();

		foreach ( $lists as $list ) {
			$type = $this->content_type( $list['list_key'] );
			if ( null === $type ) {
				$skipped_lists[] = '' !== $list['title'] ? $list['title'] . ' (' . $list['list_key'] . ')' : $list['list_key'];
				continue;
			}
			if ( isset( $seen[ $type ] ) ) {
				++$extra_layouts; // Admin Columns Pro: several layouts of one list.
				continue;
			}
			$seen[ $type ] = true;
			if ( ! empty( $list['settings']['roles'] ) || ! empty( $list['settings']['users'] ) ) {
				$per_role = true;
			}

			$columns = array();
			$used    = array();
			$natives = array();
			foreach ( $list['columns'] as $name => $c ) {
				if ( ! is_array( $c ) ) {
					continue;
				}
				$ctype = (string) ( $c['type'] ?? '' );
				$label = trim( (string) ( $c['label'] ?? '' ) );
				$width = $this->width( $c );
				$entry = null;
				if ( 'cb' === $ctype ) {
					continue;
				}
				if ( 0 !== strpos( $ctype, 'column-' ) && '' !== $ctype ) {
					$entry     = array(
						'key'        => $ctype,
						'label'      => $label,
						'type'       => 'default',
						'field_type' => 'text',
						'enabled'    => true,
						'width'      => $width,
					);
					$natives[] = $ctype;
				} elseif ( 'column-meta' === $ctype && '' !== trim( (string) ( $c['field'] ?? '' ) ) ) {
					$ft = (string) ( $c['field_type'] ?? '' );
					if ( ! isset( self::FIELD_TYPES[ $ft ] ) ) {
						$field_lost[ $ft ] = true;
					}
					$entry = array(
						'key'        => trim( (string) $c['field'] ),
						'label'      => $label,
						'type'       => 'meta',
						'field_type' => self::FIELD_TYPES[ $ft ] ?? 'text',
						'enabled'    => true,
						'width'      => $width,
					);
				} elseif ( 'column-featured_image' === $ctype ) {
					$entry = array(
						'key'        => 'featured_image',
						'label'      => $label,
						'type'       => 'thumbnail',
						'field_type' => 'text',
						'enabled'    => true,
						'width'      => $width,
					);
				} elseif ( in_array( $ctype, array( 'column-postid', 'column-user_id', 'column-comment_id', 'column-id' ), true ) ) {
					$entry = array(
						'key'        => 'item_id',
						'label'      => $label,
						'type'       => 'post_id',
						'field_type' => 'text',
						'enabled'    => true,
						'width'      => $width,
					);
				} elseif ( 'column-taxonomy' === $ctype && '' !== trim( (string) ( $c['taxonomy'] ?? '' ) ) ) {
					$entry = array(
						'key'        => trim( (string) $c['taxonomy'] ),
						'label'      => $label,
						'type'       => 'taxonomy',
						'field_type' => 'text',
						'enabled'    => true,
						'width'      => $width,
					);
				}
				if ( null === $entry ) {
					$unsupported[ $ctype ] = true;
					continue;
				}
				// Sorting and filtering (post type lists only).
				$want_sort           = $this->flag( $c['sort'] ?? '' );
				$want_filter         = $this->flag( $c['filter'] ?? '' );
				$is_list             = ! in_array( $type, array( 'attachment', 'users', 'comments' ), true );
				$entry['sortable']   = $want_sort && $is_list && ColumnQuery::can_sort( $entry );
				$entry['filterable'] = $want_filter && $is_list && ColumnQuery::can_filter( $entry );
				if ( ( $want_sort && ! $entry['sortable'] ) || ( $want_filter && ! $entry['filterable'] ) ) {
					$what      = array_filter(
						array(
							$want_sort && ! $entry['sortable'] ? __( 'sorting', 'ux-studio' ) : '',
							$want_filter && ! $entry['filterable'] ? __( 'filtering', 'ux-studio' ) : '',
						)
					);
					$sf_lost[] = $type . ': ' . ( '' !== $label ? $label : $ctype ) . ' (' . implode( ', ', $what ) . ')';
				}
				$entry = ColumnsModule::clean_column( $entry );
				if ( '' === $entry['key'] || isset( $used[ $entry['key'] ] ) ) {
					continue;
				}
				$used[ $entry['key'] ] = true;
				$columns[]             = $entry;
			}
			// A native column that Admin Columns removed stays removed.
			foreach ( self::NATIVE[ $type ] ?? self::NATIVE['_default'] as $native ) {
				if ( ! in_array( $native, $natives, true ) && ! isset( $used[ $native ] ) ) {
					$columns[] = ColumnsModule::clean_column(
						array(
							'key'     => $native,
							'label'   => '',
							'type'    => 'default',
							'enabled' => false,
						)
					);
				}
			}
			$values[ $type ] = $columns;
			$labels[ $type ] = sprintf(
				/* translators: %s: content type. */
				__( 'List columns: %s', 'ux-studio' ),
				$type
			);
		}

		if ( $values ) {
			$m->option( self::MODULE, ColumnsModule::OPTION, $values, $labels, 'admin_columns' );
		}
		if ( $unsupported ) {
			$m->lossy(
				sprintf(
					/* translators: %s: column types. */
					__( 'Column types that UX Studio does not have (they are left out): %s. The module offers default columns, custom fields, taxonomies, the ID and the featured image.', 'ux-studio' ),
					implode( ', ', array_keys( $unsupported ) )
				)
			);
		}
		if ( $field_lost ) {
			$m->lossy(
				sprintf(
					/* translators: %s: value types. */
					__( 'Custom field display types without an equivalent are shown as plain text: %s.', 'ux-studio' ),
					implode( ', ', array_map( static fn( $t ): string => '' === $t ? 'text' : (string) $t, array_keys( $field_lost ) ) )
				)
			);
		}
		if ( $skipped_lists ) {
			$m->lossy(
				sprintf(
					/* translators: %s: list names. */
					__( 'List screens the module does not cover (taxonomies, network screens): %s.', 'ux-studio' ),
					implode( ', ', $skipped_lists )
				)
			);
		}
		if ( $extra_layouts || $per_role ) {
			$m->lossy( __( 'Several layouts of one list or layouts limited to roles or users (Admin Columns Pro): only the first layout of each list is transferred, for everybody.', 'ux-studio' ) );
		}
		if ( $sf_lost ) {
			$m->lossy(
				sprintf(
					/* translators: %s: list of columns. */
					__( 'Sorting or filtering set on columns that the module can not sort or filter (the users, comments and media lists, column kinds without a stored value): %s.', 'ux-studio' ),
					implode( '; ', array_slice( array_unique( $sf_lost ), 0, 10 ) )
				)
			);
		}
		$m->lossy( __( 'Inline editing and bulk editing set on the columns, saved search segments and the column set per user.', 'ux-studio' ) );
		$m->lossy( __( 'Native columns added by other plugins (SEO, shop ...) that you hid in Admin Columns stay visible: UX Studio knows only the WordPress columns.', 'ux-studio' ) );
		return $m;
	}

	public function target_options(): array {
		return array_values( array_unique( array_merge( parent::target_options(), array( ColumnsModule::OPTION ) ) ) );
	}

	/**
	 * Is the migrated configuration of a content type already there (stored or prepared)?
	 * Used by the smoke test list and by tests.
	 *
	 * @return array<string, array<int, array<string, mixed>>>
	 */
	public function effective_config(): array {
		$live = get_option( ColumnsModule::OPTION, array() );
		return array_merge( is_array( $live ) ? $live : array(), Staging::pending_options( ColumnsModule::OPTION ) );
	}
}
