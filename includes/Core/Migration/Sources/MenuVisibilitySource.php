<?php
/**
 * Source: Nav Menu Roles, If Menu, Menu Items Visibility Control.
 *
 * @package UxStudio
 */

namespace UxStudio\Core\Migration\Sources;

use UxStudio\Core\Migration\AbstractMetaSource;
use UxStudio\Core\Migration\Mapping;

defined( 'ABSPATH' ) || exit;

/**
 * Target module `menu-visibility`: one post meta on the menu item, `_uxstudio_menu_item_visible`,
 * `1` = only logged-in visitors, `2` = only logged-out visitors. That is ALL the module can express,
 * so everything beyond it is reported item by item.
 *
 * BEHAVIOUR-CHANGING (who sees which menu link): with the module on, the values wait in the staging
 * area; with it off they are written at once.
 *
 *  - Nav Menu Roles: meta `_nav_menu_role` = `in` | `out` | a list of roles, `_nav_menu_role_display_mode` =
 *    show | hide. Logged in / out maps exactly. A list of roles is narrowed to "logged in" (the item at least
 *    disappears for visitors) and listed by name as lossy; "hide for roles" cannot be expressed and is skipped.
 *  - If Menu: metas `if_menu_enable`, `if_menu_condition_type` (show|hide), `if_menu_condition`. Only a single rule
 *    "user is logged in" maps; any other condition or several rules are skipped and counted.
 *  - Menu Items Visibility Control: meta `_menu_item_visibility`, a PHP expression. Only `is_user_logged_in()` and
 *    its negation map, every other expression is skipped.
 */
final class MenuVisibilitySource extends AbstractMetaSource {

	private const META_KEY = '_uxstudio_menu_item_visible';

	protected function meta_type(): string {
		return 'post';
	}

	protected function data_kind(): string {
		return 'menu_visibility';
	}

	protected function data_label(): string {
		return __( 'Menu item visibility', 'ux-studio' );
	}

	/**
	 * Menu item ids that have the given meta key.
	 *
	 * @param string $meta_key Meta key.
	 * @return int[]
	 */
	private function item_ids( string $meta_key ): array {
		global $wpdb;
		$rows = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT m.post_id FROM {$wpdb->postmeta} m JOIN {$wpdb->posts} p ON p.ID = m.post_id WHERE m.meta_key = %s AND p.post_type = 'nav_menu_item' AND p.post_status <> 'trash' ORDER BY m.post_id ASC", $meta_key ) ); // phpcs:ignore WordPress.DB
		return array_map( 'intval', (array) $rows );
	}

	/**
	 * Readable name of a menu item.
	 *
	 * @param int $id Menu item id.
	 */
	private function title( int $id ): string {
		$post  = get_post( $id );
		$title = $post ? trim( (string) $post->post_title ) : '';
		if ( '' === $title ) {
			$object = (int) get_post_meta( $id, '_menu_item_object_id', true );
			$title  = $object > 0 ? trim( (string) get_the_title( $object ) ) : '';
		}
		return '' !== $title ? $title : '#' . $id;
	}

	protected function build(): array {
		$out = array(
			'items'   => array(),
			'skipped' => array(),
			'narrowed' => array(),
			'hidden_for_roles' => array(),
		);
		$add = function ( int $id, string $value ) use ( &$out ): void {
			$out['items'][] = array(
				'key'    => $this->slug . ':' . $id,
				'label'  => $this->title( $id ),
				'id'     => $id,
				'meta'   => array( self::META_KEY => $value ),
				'review' => false,
			);
		};
		$skip = static function ( string $reason ) use ( &$out ): void {
			$out['skipped'][ $reason ] = ( $out['skipped'][ $reason ] ?? 0 ) + 1;
		};

		switch ( $this->slug ) {
			case 'nav-menu-roles':
				foreach ( $this->item_ids( '_nav_menu_role' ) as $id ) {
					$roles = get_post_meta( $id, '_nav_menu_role', true );
					$mode  = 'hide' === get_post_meta( $id, '_nav_menu_role_display_mode', true ) ? 'hide' : 'show';
					if ( in_array( $roles, array( 'in', 'out' ), true ) ) {
						$add( $id, ( 'in' === $roles ) === ( 'show' === $mode ) ? '1' : '2' );
						continue;
					}
					$roles = is_array( $roles ) ? array_values( array_filter( array_map( 'strval', $roles ) ) ) : array();
					if ( ! $roles ) {
						continue; // Visible to everybody.
					}
					if ( 'hide' === $mode ) {
						$out['hidden_for_roles'][] = $this->title( $id ) . ' (' . implode( ', ', $roles ) . ')';
						continue;
					}
					$add( $id, '1' );
					$out['narrowed'][] = $this->title( $id ) . ' (' . implode( ', ', $roles ) . ')';
				}
				break;
			case 'if-menu':
				foreach ( $this->item_ids( 'if_menu_enable' ) as $id ) {
					$enable = (array) get_post_meta( $id, 'if_menu_enable' );
					if ( ! $enable || '0' === (string) $enable[0] || '' === (string) $enable[0] ) {
						continue;
					}
					$conditions = (array) get_post_meta( $id, 'if_menu_condition' );
					$types      = (array) get_post_meta( $id, 'if_menu_condition_type' );
					if ( 1 !== count( $enable ) ) {
						$skip( 'several' );
						continue;
					}
					if ( 'user-logged-in' !== (string) ( $conditions[0] ?? '' ) ) {
						$skip( 'condition:' . (string) ( $conditions[0] ?? '?' ) );
						continue;
					}
					$add( $id, 'hide' === (string) ( $types[0] ?? 'show' ) ? '2' : '1' );
				}
				break;
			case 'menu-items-visibility-control':
				foreach ( $this->item_ids( '_menu_item_visibility' ) as $id ) {
					$logic = (string) preg_replace( '/\s+/', '', (string) get_post_meta( $id, '_menu_item_visibility', true ) );
					if ( '' === $logic ) {
						continue;
					}
					if ( 'is_user_logged_in()' === $logic ) {
						$add( $id, '1' );
					} elseif ( '!is_user_logged_in()' === $logic ) {
						$add( $id, '2' );
					} else {
						$skip( 'php' );
					}
				}
				break;
		}
		return $out;
	}

	protected function map( bool $active ): Mapping {
		$m = new Mapping();
		$c = $this->collect();
		if ( ! $c['items'] && ! $c['skipped'] && empty( $c['narrowed'] ) && empty( $c['hidden_for_roles'] ) ) {
			$m->note( __( 'No menu item has a visibility rule, there is nothing to transfer.', 'ux-studio' ) );
			return $m;
		}
		if ( ! empty( $c['narrowed'] ) ) {
			$m->lossy(
				sprintf(
					/* translators: %s: list of menu items with their roles. */
					__( 'The module only knows "logged in" and "logged out", not roles. These items were visible only for some roles, they become visible for every logged-in visitor: %s.', 'ux-studio' ),
					implode( '; ', $c['narrowed'] )
				)
			);
		}
		if ( ! empty( $c['hidden_for_roles'] ) ) {
			$m->lossy(
				sprintf(
					/* translators: %s: list of menu items with their roles. */
					__( 'These items were hidden for certain roles. The module cannot hide an item for a role, so they are not transferred and stay visible to everybody: %s.', 'ux-studio' ),
					implode( '; ', $c['hidden_for_roles'] )
				)
			);
		}
		foreach ( $c['skipped'] as $reason => $n ) {
			if ( 'several' === $reason ) {
				$m->lossy(
					sprintf(
						/* translators: %d: number of menu items. */
						__( '%d menu items combine several rules (AND / OR). The module has one rule per item, they are not transferred.', 'ux-studio' ),
						$n
					)
				);
			} elseif ( 'php' === $reason ) {
				$m->lossy(
					sprintf(
						/* translators: %d: number of menu items. */
						__( '%d menu items are controlled by a PHP expression. The module cannot run expressions, only logged in / logged out, so they are not transferred.', 'ux-studio' ),
						$n
					)
				);
			} else {
				$m->lossy(
					sprintf(
						/* translators: 1: number of menu items, 2: condition id. */
						__( '%1$d menu items depend on the condition "%2$s", which the module does not have. They are not transferred.', 'ux-studio' ),
						$n,
						substr( $reason, 10 )
					)
				);
			}
		}
		return $m;
	}

	protected function data_plan( array $options ): array {
		$in  = 0;
		$out = 0;
		foreach ( $this->collect()['items'] as $item ) {
			if ( '1' === $item['meta'][ self::META_KEY ] ) {
				++$in;
			} else {
				++$out;
			}
		}
		return array(
			array(
				'key'   => 'in',
				'label' => __( 'Menu items visible only to logged-in visitors', 'ux-studio' ),
				'count' => $in,
			),
			array(
				'key'   => 'out',
				'label' => __( 'Menu items visible only to logged-out visitors', 'ux-studio' ),
				'count' => $out,
			),
		);
	}
}
