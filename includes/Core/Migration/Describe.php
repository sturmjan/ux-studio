<?php
/**
 * Human readable form of a value for the check and verify steps.
 *
 * @package UxStudio
 */

namespace UxStudio\Core\Migration;

defined( 'ABSPATH' ) || exit;

/**
 * Pure functions (no I/O except translating). A value becomes
 *
 *  - `text`   : one short line (a scalar as it is, a short list of names, or a count such as "42 items"),
 *  - `detail` : rows `label -> value` for the expandable "Show detail" part, empty for simple values.
 *
 * Internal ids (menu slugs, category ids, role ids) are replaced by their label whenever a name is known:
 * from the mapping (titles the source read), from the WordPress core menu, from the roles of the site.
 * An id without a known label stays as it is, which is still better than a dump of `key: value, key: value`.
 */
final class Describe {

	/** How many names of a list stay in the one-line text. */
	public const INLINE = 6;

	/** How many rows the detail holds at most (the rest is summarised in a last row). */
	public const DETAIL_MAX = 120;

	/**
	 * Describe one value.
	 *
	 * @param mixed                 $value Any value.
	 * @param string                $key   Top-level key of the setting (picks the noun for counts).
	 * @param array<string, string> $names Id => label.
	 * @return array{text:string,detail:array<int,array{label:string,value:string}>}
	 */
	public static function value( $value, string $key = '', array $names = array() ): array {
		// The given names win over the ones every site has.
		$names = $names + self::base_names();
		if ( ! is_array( $value ) ) {
			return array(
				'text'   => Mapping::display( $value ),
				'detail' => array(),
			);
		}
		if ( array() === $value ) {
			return array(
				'text'   => '',
				'detail' => array(),
			);
		}
		if ( array_is_list( $value ) && self::all_scalar( $value ) ) {
			$labels = array_map( static fn( $v ): string => self::name( $v, $names ), $value );
			$text   = self::short_list( $labels );
			return array(
				'text'   => $text,
				'detail' => count( $labels ) > self::INLINE ? self::rows( array_map( static fn( string $l ): array => array( '', $l ), $labels ) ) : array(),
			);
		}
		$rows = array();
		if ( array_is_list( $value ) ) {
			// A list of records (custom links ...): title -> address.
			foreach ( $value as $record ) {
				$rows[] = is_array( $record ) ? array( self::record_label( $record, $names ), self::record_value( $record, $names ) ) : array( '', self::name( $record, $names ) );
			}
		} else {
			foreach ( $value as $id => $child ) {
				$rows[] = array( self::name( $id, $names, 'item_titles' === $key ), self::inline( $child, $names ) );
			}
		}
		return array(
			'text'   => self::count_text( $key, $value ),
			'detail' => self::rows( $rows ),
		);
	}

	/**
	 * Names every WordPress site knows: the core admin menu and the roles.
	 *
	 * @return array<string, string>
	 */
	public static function base_names(): array {
		if ( null !== self::$base ) {
			return self::$base;
		}
		$names = array(
			// phpcs:disable WordPress.WP.I18n.MissingArgDomain -- these are WordPress core strings, translated by core.
			'index.php'                      => __( 'Dashboard' ),
			'edit.php'                       => __( 'Posts' ),
			'upload.php'                     => __( 'Media' ),
			'edit.php?post_type=page'        => __( 'Pages' ),
			'edit-comments.php'              => __( 'Comments' ),
			'themes.php'                     => __( 'Appearance' ),
			'plugins.php'                    => __( 'Plugins' ),
			'users.php'                      => __( 'Users' ),
			'tools.php'                      => __( 'Tools' ),
			'options-general.php'            => __( 'Settings' ),
			'profile.php'                    => __( 'Profile' ),
			'separator1'                     => __( 'Separator', 'ux-studio' ),
			'separator2'                     => __( 'Separator', 'ux-studio' ),
			'separator-last'                 => __( 'Separator', 'ux-studio' ),
			// phpcs:enable
			'ux-studio'                      => __( 'UX Studio', 'ux-studio' ),
		);
		if ( function_exists( 'wp_roles' ) ) {
			$roles = wp_roles();
			if ( is_object( $roles ) && method_exists( $roles, 'get_names' ) ) {
				foreach ( (array) $roles->get_names() as $id => $label ) {
					$names[ (string) $id ] = function_exists( 'translate_user_role' ) ? (string) translate_user_role( (string) $label ) : (string) $label;
				}
			}
		}
		self::$base = $names;
		return $names;
	}

	/** @var array<string, string>|null Per request cache of base_names(). */
	private static ?array $base = null;

	/**
	 * Label of an id.
	 *
	 * @param mixed                 $id       Id (anything scalar).
	 * @param array<string, string> $names    Id => label.
	 * @param bool                  $original Prefer the id as WordPress knows it (the key of a rename list is the OLD name).
	 */
	private static function name( $id, array $names, bool $original = false ): string {
		$id = is_bool( $id ) ? ( $id ? '✓' : '✗' ) : (string) $id;
		if ( $original ) {
			// The title before any rename: the source told us (`orig:` entries), else WordPress core knows it.
			$base = self::base_names();
			return (string) ( $names[ 'orig:' . $id ] ?? $base[ $id ] ?? $id );
		}
		return (string) ( $names[ $id ] ?? $id );
	}

	/**
	 * Whether every element is a scalar (or null).
	 *
	 * @param array<mixed> $list Array.
	 */
	private static function all_scalar( array $list ): bool {
		foreach ( $list as $v ) {
			if ( is_array( $v ) || is_object( $v ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * The first names, then "+ N more".
	 *
	 * @param string[] $labels Labels.
	 */
	private static function short_list( array $labels ): string {
		$labels = array_values( array_unique( array_filter( $labels, static fn( string $l ): bool => '' !== $l ) ) );
		if ( count( $labels ) <= self::INLINE ) {
			return implode( ', ', $labels );
		}
		return implode( ', ', array_slice( $labels, 0, self::INLINE ) ) . ' ' . sprintf(
			/* translators: %d: how many more items there are. */
			__( '+ %d more', 'ux-studio' ),
			count( $labels ) - self::INLINE
		);
	}

	/**
	 * The value of one detail row as a single line.
	 *
	 * @param mixed                 $value Any value.
	 * @param array<string, string> $names Id => label.
	 */
	private static function inline( $value, array $names ): string {
		if ( ! is_array( $value ) ) {
			return self::name( $value, $names );
		}
		if ( array_is_list( $value ) && self::all_scalar( $value ) ) {
			return self::short_list( array_map( static fn( $v ): string => self::name( $v, $names ), $value ) );
		}
		// A sub-item moved to the top level: "Parent / title".
		if ( ! array_is_list( $value ) && isset( $value['parent'], $value['title'] ) && is_scalar( $value['parent'] ) && is_scalar( $value['title'] ) ) {
			return self::name( $value['parent'], $names ) . ' / ' . (string) $value['title'] . ( ! empty( $value['category'] ) ? ' (' . self::name( $value['category'], $names ) . ')' : '' );
		}
		$parts = array();
		foreach ( $value as $k => $v ) {
			$label   = array_is_list( $value ) ? '' : self::name( $k, $names ) . ': ';
			$parts[] = $label . ( is_array( $v ) ? self::inline( $v, $names ) : self::name( $v, $names ) );
		}
		$text = implode( '; ', array_filter( $parts, static fn( string $p ): bool => '' !== $p ) );
		return mb_strlen( $text ) > 160 ? mb_substr( $text, 0, 157 ) . '...' : $text;
	}

	/**
	 * Title of a record in a list of records.
	 *
	 * @param array<mixed>          $record Record.
	 * @param array<string, string> $names  Id => label.
	 */
	private static function record_label( array $record, array $names ): string {
		foreach ( array( 'title', 'label', 'name', 'id', 'key' ) as $field ) {
			if ( isset( $record[ $field ] ) && is_scalar( $record[ $field ] ) && '' !== (string) $record[ $field ] ) {
				return self::name( $record[ $field ], $names );
			}
		}
		return '';
	}

	/**
	 * Main value of a record (an address if it has one).
	 *
	 * @param array<mixed>          $record Record.
	 * @param array<string, string> $names  Id => label.
	 */
	private static function record_value( array $record, array $names ): string {
		if ( self::is_column( $record ) ) {
			$text = (string) $record['type'];
			if ( isset( $record['width'] ) && '' !== (string) $record['width'] ) {
				$text .= ', ' . $record['width'];
			}
			return $text . ( empty( $record['enabled'] ) ? ' - ' . __( 'hidden', 'ux-studio' ) : '' );
		}
		foreach ( array( 'url', 'link', 'href', 'value' ) as $field ) {
			if ( isset( $record[ $field ] ) && is_scalar( $record[ $field ] ) && '' !== (string) $record[ $field ] ) {
				return (string) $record[ $field ];
			}
		}
		return self::inline( $record, $names );
	}

	/**
	 * A list column of the Admin Columns module.
	 *
	 * @param array<mixed> $record Record.
	 */
	private static function is_column( array $record ): bool {
		return isset( $record['key'], $record['type'] ) && array_key_exists( 'enabled', $record ) && is_scalar( $record['key'] ) && is_scalar( $record['type'] );
	}

	/**
	 * Detail rows, capped.
	 *
	 * @param array<int, array{0:string,1:string}> $pairs Label/value pairs.
	 * @return array<int, array{label:string,value:string}>
	 */
	private static function rows( array $pairs ): array {
		$out = array();
		foreach ( $pairs as $i => $pair ) {
			if ( $i >= self::DETAIL_MAX ) {
				$out[] = array(
					'label' => '',
					'value' => sprintf(
						/* translators: %d: how many more rows there are. */
						__( '+ %d more', 'ux-studio' ),
						count( $pairs ) - self::DETAIL_MAX
					),
				);
				break;
			}
			$out[] = array(
				// "Group 1 -> Group 1" says nothing twice.
				'label' => $pair[0] === $pair[1] ? '' : $pair[0],
				'value' => $pair[1],
			);
		}
		return $out;
	}

	/**
	 * "6 categories", "42 items in 6 categories" ... by the kind of setting.
	 *
	 * @param string       $key   Setting key.
	 * @param array<mixed> $value Value.
	 */
	private static function count_text( string $key, array $value ): string {
		$n = count( $value );
		if ( array_is_list( $value ) && isset( $value[0] ) && is_array( $value[0] ) && self::is_column( $value[0] ) ) {
			$hidden = count( array_filter( $value, static fn( $c ): bool => is_array( $c ) && empty( $c['enabled'] ) ) );
			$text   = sprintf( _n( '%d column', '%d columns', $n, 'ux-studio' ), $n );
			return $hidden > 0 ? $text . ', ' . sprintf( __( '%d hidden', 'ux-studio' ), $hidden ) : $text;
		}
		switch ( $key ) {
			case 'categories':
			case 'category_order':
				return sprintf( _n( '%d category', '%d categories', $n, 'ux-studio' ), $n );
			case 'assignments':
				return sprintf( _n( '%d item placed in a category', '%d items placed in categories', $n, 'ux-studio' ), $n );
			case 'order':
				$items = 0;
				foreach ( $value as $list ) {
					$items += is_array( $list ) ? count( $list ) : 1;
				}
				return sprintf( _n( '%d item', '%d items', $items, 'ux-studio' ), $items ) . ', ' . sprintf( _n( '%d category', '%d categories', $n, 'ux-studio' ), $n );
			case 'item_titles':
				return sprintf( _n( '%d renamed item', '%d renamed items', $n, 'ux-studio' ), $n );
			case 'item_icons':
				return sprintf( _n( '%d item with its own icon', '%d items with their own icon', $n, 'ux-studio' ), $n );
			case 'item_roles':
				return sprintf( _n( '%d item limited to roles', '%d items limited to roles', $n, 'ux-studio' ), $n );
			case 'hidden_items':
				return sprintf( _n( '%d hidden item', '%d hidden items', $n, 'ux-studio' ), $n );
			case 'promoted_subs':
				return sprintf( _n( '%d sub-item moved up', '%d sub-items moved up', $n, 'ux-studio' ), $n );
			case 'custom_links':
				return sprintf( _n( '%d custom link', '%d custom links', $n, 'ux-studio' ), $n );
			default:
				return sprintf( _n( '%d value', '%d values', $n, 'ux-studio' ), $n );
		}
	}
}
