<?php
/**
 * Sorting and filtering of the list table of a post type by its configured columns.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\AdminColumns;

defined( 'ABSPATH' ) || exit;

/**
 * A column with `sortable` gets a clickable header (`manage_edit-{type}_sortable_columns`) and the main admin query is
 * ordered by it (`pre_get_posts`, and `posts_clauses` for the orders a plain WP_Query cannot express); a column with
 * `filterable` gets a dropdown above the list (`restrict_manage_posts`) that narrows the main admin query. Supported
 * sources: custom field (meta), taxonomy, author, the post ID and the native title / date / comments columns.
 * Only the list of a post type (edit.php) is handled; the users, comments and media lists are not.
 *
 * Everything that decides what a request means is a pure static function on arrays (`sort_spec`, `sort_vars`, `order_sql`,
 * `filter_vars`), tested without WordPress; the instance only connects them to WordPress.
 *
 * Request parameters: `orderby=uxs_<key>` and `order=asc|desc` for sorting, `uxs_f_<key>=<value>` for filters, so a sorted and
 * filtered list keeps its state in the address and in the pagination links.
 */
final class ColumnQuery {

	public const SORT_PREFIX   = 'uxs_';
	public const FILTER_PREFIX = 'uxs_f_';

	/** Native columns that already sort by themselves in WordPress (nothing to add). */
	private const NATIVE_SORTABLE = array( 'title', 'date', 'comments' );

	/** Largest number of distinct custom field values offered in a dropdown (above that a text field is shown). */
	private const MAX_OPTIONS = 100;

	private string $post_type;

	/** @var array<int, array<string, mixed>> */
	private array $columns;

	/** @var array<string, mixed>|null Sort the current main query asked for, applied to the SQL in posts_clauses. */
	private ?array $pending = null;

	/**
	 * @param string                           $post_type Post type of the list.
	 * @param array<int, array<string, mixed>> $columns   Normalized column definitions.
	 */
	public function __construct( string $post_type, array $columns ) {
		$this->post_type = $post_type;
		$this->columns   = $columns;
	}

	/**
	 * Does any column ask for sorting or filtering (nothing is hooked otherwise)?
	 *
	 * @param array<int, array<string, mixed>> $columns Columns.
	 */
	public static function wanted( array $columns ): bool {
		foreach ( $columns as $column ) {
			if ( ! empty( $column['enabled'] ) && ( ! empty( $column['sortable'] ) || ! empty( $column['filterable'] ) ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Connect to WordPress.
	 */
	public function register(): void {
		if ( ! self::wanted( $this->columns ) ) {
			return;
		}
		add_filter( "manage_edit-{$this->post_type}_sortable_columns", array( $this, 'sortable_columns' ) );
		add_action( 'pre_get_posts', array( $this, 'pre_get_posts' ) );
		add_filter( 'posts_clauses', array( $this, 'posts_clauses' ), 10, 2 );
		add_action( 'restrict_manage_posts', array( $this, 'render_filters' ), 10, 2 );
	}

	/* ── Pure part ── */

	/**
	 * What kind of sort a column is (null = not sortable by this module).
	 *
	 * @param array<string, mixed> $column Column definition.
	 * @return array<string, string>|null kind (meta | taxonomy | author | id | native), key, orderby, meta_type.
	 */
	public static function sort_spec_of( array $column ): ?array {
		$key  = (string) ( $column['key'] ?? '' );
		$type = (string) ( $column['type'] ?? 'default' );
		if ( '' === $key || empty( $column['enabled'] ) || empty( $column['sortable'] ) ) {
			return null;
		}
		switch ( $type ) {
			case 'meta':
				$field = (string) ( $column['field_type'] ?? 'text' );
				return array(
					'kind'      => 'meta',
					'key'       => $key,
					'orderby'   => self::SORT_PREFIX . $key,
					'meta_type' => in_array( $field, array( 'number', 'boolean' ), true ) ? 'NUMERIC' : 'CHAR',
				);
			case 'taxonomy':
				return array(
					'kind'    => 'taxonomy',
					'key'     => $key,
					'orderby' => self::SORT_PREFIX . $key,
				);
			case 'post_id':
				return array(
					'kind'    => 'id',
					'key'     => $key,
					'orderby' => self::SORT_PREFIX . $key,
				);
			case 'default':
				if ( 'author' === $key ) {
					return array(
						'kind'    => 'author',
						'key'     => $key,
						'orderby' => self::SORT_PREFIX . $key,
					);
				}
				if ( 'categories' === $key || 'tags' === $key ) {
					return array(
						'kind'    => 'taxonomy',
						'key'     => 'categories' === $key ? 'category' : 'post_tag',
						'orderby' => self::SORT_PREFIX . $key,
					);
				}
				return null; // title, date, comments sort natively.
		}
		return null;
	}

	/**
	 * The sort spec for a request's `orderby`, or null.
	 *
	 * @param array<int, array<string, mixed>> $columns Columns.
	 * @param string                           $orderby Value of the `orderby` parameter.
	 * @return array<string, string>|null
	 */
	public static function sort_spec( array $columns, string $orderby ): ?array {
		if ( 0 !== strpos( $orderby, self::SORT_PREFIX ) ) {
			return null;
		}
		foreach ( $columns as $column ) {
			$spec = self::sort_spec_of( (array) $column );
			if ( null !== $spec && $spec['orderby'] === $orderby ) {
				return $spec;
			}
		}
		return null;
	}

	/**
	 * Can this module sort the list by the column (its own sort, or the native one WordPress already has)?
	 *
	 * @param array<string, mixed> $column Column definition (the `sortable` flag is not looked at).
	 */
	public static function can_sort( array $column ): bool {
		$column['enabled']  = true;
		$column['sortable'] = true;
		return null !== self::sort_spec_of( $column )
			|| ( 'default' === (string) ( $column['type'] ?? 'default' ) && in_array( (string) ( $column['key'] ?? '' ), self::NATIVE_SORTABLE, true ) );
	}

	/**
	 * Can this module filter the list by the column?
	 *
	 * @param array<string, mixed> $column Column definition (the `filterable` flag is not looked at).
	 */
	public static function can_filter( array $column ): bool {
		$column['enabled']    = true;
		$column['filterable'] = true;
		return null !== self::filter_spec_of( $column );
	}

	/**
	 * Sortable-columns map to add to what WordPress has: column key => [ orderby, initial desc ].
	 *
	 * @param array<int, array<string, mixed>> $columns Columns.
	 * @return array<string, array{0:string,1:bool}>
	 */
	public static function sortable_map( array $columns ): array {
		$out = array();
		foreach ( $columns as $column ) {
			$column = (array) $column;
			$spec   = self::sort_spec_of( $column );
			if ( null !== $spec ) {
				$out[ (string) $column['key'] ] = array( $spec['orderby'], false );
			}
		}
		return $out;
	}

	/**
	 * Normalize the `order` parameter.
	 *
	 * @param string $order Raw value.
	 */
	public static function direction( string $order ): string {
		return 'desc' === strtolower( $order ) ? 'DESC' : 'ASC';
	}

	/**
	 * Query variables for a sort: the ID order is a plain WP_Query order, every other kind is done in the SQL (see order_sql()) and
	 * only gets a harmless `orderby` here so WordPress builds the rest of the query as usual.
	 *
	 * A custom field is NOT sorted through `meta_key` / `meta_query`: with an OR of "exists" and "not exists" WordPress sorts the
	 * posts that lack the field by the value of some OTHER meta row of the post (its first JOIN is not limited to the key).
	 *
	 * @param array<string, mixed>  $vars  Current query variables.
	 * @param array<string, string> $spec  Sort spec.
	 * @param string                $order Direction.
	 * @return array<string, mixed>
	 */
	public static function sort_vars( array $vars, array $spec, string $order ): array {
		$order = self::direction( $order );
		if ( 'id' === $spec['kind'] ) {
			$vars['orderby'] = array( 'ID' => $order );
		} else {
			$vars['orderby'] = 'date';
		}
		$vars['order'] = $order;
		return $vars;
	}

	/**
	 * `ORDER BY` expression for the kinds a WP_Query cannot order by right (custom field, first term name, author name).
	 *
	 * @param array<string, string>      $spec    Sort spec.
	 * @param string                     $order   Direction.
	 * @param array<string, string>      $tables  Table names: posts, postmeta, users, term_relationships, term_taxonomy, terms.
	 * @param callable                   $quote   callable(string): string, SQL-quotes a string literal.
	 * @return string Empty when the kind is not an SQL one.
	 */
	public static function order_sql( array $spec, string $order, array $tables, callable $quote ): string {
		$order = self::direction( $order );
		$posts = $tables['posts'];
		if ( 'meta' === $spec['kind'] ) {
			// Value of the first row of the field of THIS post; posts without the field (NULL) go last in both directions.
			$cast  = 'NUMERIC' === ( $spec['meta_type'] ?? 'CHAR' ) ? 'CAST(uxs_pm.meta_value AS DECIMAL(30,10))' : 'uxs_pm.meta_value';
			$value = '( SELECT ' . $cast . ' FROM ' . $tables['postmeta'] . ' uxs_pm WHERE uxs_pm.post_id = ' . $posts . '.ID AND uxs_pm.meta_key = ' . call_user_func( $quote, $spec['key'] ) . ' ORDER BY uxs_pm.meta_id ASC LIMIT 1 )';
			return $value . ' IS NULL ASC, ' . $value . ' ' . $order . ', ' . $posts . '.ID DESC';
		}
		if ( 'taxonomy' === $spec['kind'] ) {
			return '( SELECT MIN(uxs_t.name) FROM ' . $tables['term_relationships'] . ' uxs_tr INNER JOIN ' . $tables['term_taxonomy'] . ' uxs_tt ON uxs_tt.term_taxonomy_id = uxs_tr.term_taxonomy_id INNER JOIN ' . $tables['terms'] . ' uxs_t ON uxs_t.term_id = uxs_tt.term_id WHERE uxs_tr.object_id = ' . $posts . '.ID AND uxs_tt.taxonomy = ' . call_user_func( $quote, $spec['key'] ) . ' ) ' . $order . ', ' . $posts . '.ID DESC';
		}
		if ( 'author' === $spec['kind'] ) {
			return '( SELECT uxs_u.display_name FROM ' . $tables['users'] . ' uxs_u WHERE uxs_u.ID = ' . $posts . '.post_author ) ' . $order . ', ' . $posts . '.ID DESC';
		}
		return '';
	}

	/**
	 * Query variables for the filters in a request.
	 *
	 * @param array<int, array<string, mixed>> $columns Columns.
	 * @param array<string, mixed>             $request Request parameters (`uxs_f_<key>` entries are read).
	 * @param array<string, mixed>             $vars    Current query variables.
	 * @return array<string, mixed>
	 */
	public static function filter_vars( array $columns, array $request, array $vars ): array {
		foreach ( $columns as $column ) {
			$column = (array) $column;
			$spec   = self::filter_spec_of( $column );
			if ( null === $spec ) {
				continue;
			}
			$raw = $request[ self::FILTER_PREFIX . $column['key'] ] ?? '';
			if ( ! is_string( $raw ) && ! is_int( $raw ) ) {
				continue;
			}
			$raw = trim( (string) $raw );
			if ( '' === $raw || '0' === $raw && 'meta' !== $spec['kind'] ) {
				continue;
			}
			switch ( $spec['kind'] ) {
				case 'meta':
					$clause = array(
						'key'     => $spec['key'],
						'value'   => $raw,
						'compare' => '=',
					);
					$query  = isset( $vars['meta_query'] ) && is_array( $vars['meta_query'] ) ? $vars['meta_query'] : array( 'relation' => 'AND' );
					$query[] = $clause;
					$vars['meta_query'] = $query;
					break;
				case 'taxonomy':
					$query   = isset( $vars['tax_query'] ) && is_array( $vars['tax_query'] ) ? $vars['tax_query'] : array( 'relation' => 'AND' );
					$query[] = array(
						'taxonomy' => $spec['key'],
						'field'    => 'term_id',
						'terms'    => array( (int) $raw ),
					);
					$vars['tax_query'] = $query;
					break;
				case 'author':
					$vars['author'] = (int) $raw;
					break;
			}
		}
		return $vars;
	}

	/**
	 * What a column is filtered by (null = not filterable).
	 *
	 * @param array<string, mixed> $column Column definition.
	 * @return array<string, string>|null kind (meta | taxonomy | author), key.
	 */
	public static function filter_spec_of( array $column ): ?array {
		$key  = (string) ( $column['key'] ?? '' );
		$type = (string) ( $column['type'] ?? 'default' );
		if ( '' === $key || empty( $column['enabled'] ) || empty( $column['filterable'] ) ) {
			return null;
		}
		if ( 'meta' === $type ) {
			return array(
				'kind' => 'meta',
				'key'  => $key,
			);
		}
		if ( 'taxonomy' === $type ) {
			return array(
				'kind' => 'taxonomy',
				'key'  => $key,
			);
		}
		if ( 'default' === $type ) {
			if ( 'author' === $key ) {
				return array(
					'kind' => 'author',
					'key'  => $key,
				);
			}
			if ( 'categories' === $key || 'tags' === $key ) {
				return array(
					'kind' => 'taxonomy',
					'key'  => 'categories' === $key ? 'category' : 'post_tag',
				);
			}
		}
		return null;
	}

	/* ── WordPress glue ── */

	/**
	 * Add the sortable headers.
	 *
	 * @param mixed $sortable Existing sortable columns.
	 * @return array<string, mixed>
	 */
	public function sortable_columns( $sortable ): array {
		$sortable = is_array( $sortable ) ? $sortable : array();
		return array_merge( $sortable, self::sortable_map( $this->columns ) );
	}

	/**
	 * Is this the query of the list screen of this post type?
	 *
	 * @param \WP_Query $query Query.
	 */
	private function is_list_query( $query ): bool {
		global $pagenow;
		return is_admin() && 'edit.php' === $pagenow && $query->is_main_query() && $this->post_type === (string) ( $query->get( 'post_type' ) ?: 'post' );
	}

	/**
	 * Apply the requested sort and the filters to the main query of the list.
	 *
	 * @param \WP_Query $query Query.
	 */
	public function pre_get_posts( $query ): void {
		if ( ! $this->is_list_query( $query ) ) {
			return;
		}
		// Reading the list screen's own filter / sort parameters (a GET form), nothing is stored or output.
		// phpcs:disable WordPress.Security.NonceVerification
		$request = array();
		foreach ( $_GET as $name => $value ) {
			if ( is_string( $name ) && 0 === strpos( $name, self::FILTER_PREFIX ) && is_string( $value ) ) {
				$request[ sanitize_key( $name ) ] = sanitize_text_field( wp_unslash( $value ) );
			}
		}
		$orderby = isset( $_GET['orderby'] ) && is_string( $_GET['orderby'] ) ? sanitize_key( wp_unslash( $_GET['orderby'] ) ) : '';
		$order   = isset( $_GET['order'] ) && is_string( $_GET['order'] ) ? sanitize_key( wp_unslash( $_GET['order'] ) ) : 'asc';
		// phpcs:enable

		$vars = array(
			'meta_query' => $query->get( 'meta_query' ),
			'tax_query'  => $query->get( 'tax_query' ),
			'author'     => $query->get( 'author' ),
		);
		$vars = self::filter_vars( $this->columns, $request, array_filter( $vars ) );

		$spec = self::sort_spec( $this->columns, $orderby );
		if ( null !== $spec ) {
			$vars = self::sort_vars( $vars + array( 'orderby' => '' ), $spec, $order );
			if ( in_array( $spec['kind'], array( 'meta', 'taxonomy', 'author' ), true ) ) {
				$this->pending = array(
					'spec'  => $spec,
					'order' => $order,
				);
			}
		}
		foreach ( $vars as $name => $value ) {
			$query->set( $name, $value );
		}
	}

	/**
	 * Order by a custom field, the first term or the author name (SQL only).
	 *
	 * @param mixed     $clauses Query clauses.
	 * @param \WP_Query $query   Query.
	 * @return mixed
	 */
	public function posts_clauses( $clauses, $query ) {
		if ( null === $this->pending || ! $this->is_list_query( $query ) || ! is_array( $clauses ) ) {
			return $clauses;
		}
		global $wpdb;
		$sql = self::order_sql(
			$this->pending['spec'],
			$this->pending['order'],
			array(
				'posts'              => $wpdb->posts,
				'postmeta'           => $wpdb->postmeta,
				'users'              => $wpdb->users,
				'term_relationships' => $wpdb->term_relationships,
				'term_taxonomy'      => $wpdb->term_taxonomy,
				'terms'              => $wpdb->terms,
			),
			static fn( string $value ): string => "'" . esc_sql( $value ) . "'"
		);
		if ( '' !== $sql ) {
			$clauses['orderby'] = $sql;
		}
		$this->pending = null;
		return $clauses;
	}

	/**
	 * Dropdowns above the list.
	 *
	 * @param mixed $post_type Post type of the list.
	 * @param mixed $which     top | bottom.
	 */
	public function render_filters( $post_type, $which = 'top' ): void {
		if ( $post_type !== $this->post_type || 'top' !== $which ) {
			return;
		}
		foreach ( $this->columns as $column ) {
			$column = (array) $column;
			$spec   = self::filter_spec_of( $column );
			if ( null === $spec ) {
				continue;
			}
			$name  = self::FILTER_PREFIX . $column['key'];
			$label = '' !== (string) $column['label'] ? (string) $column['label'] : (string) $column['key'];
			// phpcs:ignore WordPress.Security.NonceVerification
			$current = isset( $_GET[ $name ] ) && is_string( $_GET[ $name ] ) ? sanitize_text_field( wp_unslash( $_GET[ $name ] ) ) : '';
			$all     = sprintf(
				/* translators: %s: column title. */
				__( 'All: %s', 'ux-studio' ),
				$label
			);
			if ( 'taxonomy' === $spec['kind'] ) {
				if ( ! taxonomy_exists( $spec['key'] ) ) {
					continue;
				}
				wp_dropdown_categories(
					array(
						'taxonomy'         => $spec['key'],
						'name'             => $name,
						'id'               => 'uxs-f-' . $column['key'],
						'show_option_all'  => $all,
						'selected'         => (int) $current,
						'value_field'      => 'term_id',
						'hierarchical'     => is_taxonomy_hierarchical( $spec['key'] ),
						'hide_empty'       => false,
						'orderby'          => 'name',
					)
				);
			} elseif ( 'author' === $spec['kind'] ) {
				wp_dropdown_users(
					array(
						'name'            => $name,
						'id'              => 'uxs-f-' . $column['key'],
						'show_option_all' => $all,
						'selected'        => (int) $current,
						'capability'      => array( 'edit_posts' ),
					)
				);
			} else {
				$values = $this->meta_values( $spec['key'] );
				if ( count( $values ) > self::MAX_OPTIONS ) {
					printf(
						'<input type="search" name="%1$s" value="%2$s" placeholder="%3$s" aria-label="%3$s" />',
						esc_attr( $name ),
						esc_attr( $current ),
						esc_attr( $label )
					);
					continue;
				}
				echo '<select name="' . esc_attr( $name ) . '" id="uxs-f-' . esc_attr( (string) $column['key'] ) . '" aria-label="' . esc_attr( $label ) . '">';
				echo '<option value="">' . esc_html( $all ) . '</option>';
				foreach ( $values as $value ) {
					echo '<option value="' . esc_attr( $value ) . '"' . selected( $current, $value, false ) . '>' . esc_html( mb_strimwidth( $value, 0, 60, '…' ) ) . '</option>';
				}
				echo '</select>';
			}
		}
	}

	/**
	 * Distinct values of a custom field in this post type (one more than the limit, so the caller can tell it is too many).
	 *
	 * @param string $meta_key Meta key.
	 * @return string[]
	 */
	private function meta_values( string $meta_key ): array {
		global $wpdb;
		$cache_key = 'uxs_colvals_' . md5( $this->post_type . '|' . $meta_key );
		$found     = wp_cache_get( $cache_key, 'uxstudio' );
		if ( is_array( $found ) ) {
			return $found;
		}
		$rows = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT DISTINCT pm.meta_value FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE pm.meta_key = %s AND p.post_type = %s AND p.post_status NOT IN ('trash', 'auto-draft') AND pm.meta_value <> '' AND LENGTH(pm.meta_value) < 200 ORDER BY pm.meta_value ASC LIMIT %d",
				$meta_key,
				$this->post_type,
				self::MAX_OPTIONS + 1
			)
		);
		$rows = array_map( 'strval', is_array( $rows ) ? $rows : array() );
		wp_cache_set( $cache_key, $rows, 'uxstudio', 60 );
		return $rows;
	}
}
