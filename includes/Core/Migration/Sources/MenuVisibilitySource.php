<?php
/**
 * Source: Nav Menu Roles, If Menu, Menu Items Visibility Control.
 *
 * @package UxStudio
 */

namespace UxStudio\Core\Migration\Sources;

use UxStudio\Core\Migration\AbstractMetaSource;
use UxStudio\Core\Migration\Mapping;
use UxStudio\Modules\MenuVisibility\Rules;

defined( 'ABSPATH' ) || exit;

/**
 * Target module `menu-visibility` (PLAN.md 27.6): rules per menu item, all of which must hold (AND):
 * logged in, logged out, has one of the roles, has none of the roles. Stored in the post meta of the
 * menu item: `_uxstudio_menu_item_visible` (`1` / `2`, the single logged in / out case) or
 * `_uxstudio_menu_item_rules` (JSON list, see `MenuVisibility\Rules`).
 *
 * BEHAVIOUR-CHANGING (who sees which menu link): with the module on, the values wait in the staging
 * area; with it off they are written at once.
 *
 *  - Nav Menu Roles: meta `_nav_menu_role` = `in` | `out` | a list of roles, `_nav_menu_role_display_mode` =
 *    show | hide (the plugin inverts the visibility). Logged in / out and role lists map exactly; "hide for roles"
 *    becomes "has none of the roles". Roles the site does not have (the plugin also accepts capabilities) are left out
 *    and listed.
 *  - If Menu: metas `if_menu_enable` (`1`, then `and` / `or` per extra rule), `if_menu_condition_type` (show|hide),
 *    `if_menu_condition`. Conditions "User is logged in" and "User is <role>" map, also several of them joined by AND,
 *    and an OR of "show if the user is <role>" rules becomes one role rule. Every other condition (pages, dates,
 *    WooCommerce, languages ...) and every other use of OR is skipped and counted.
 *  - Menu Items Visibility Control: meta `_menu_item_visibility`, a PHP expression. The plugin runs it with `eval`;
 *    UX Studio never runs code from settings, so only a few simple, fixed forms are recognized by pattern
 *    (`is_user_logged_in()`, `!is_user_logged_in()`, `current_user_can('role')`, their negation, joined by `&&`),
 *    nothing is executed. Every other expression is skipped.
 */
final class MenuVisibilitySource extends AbstractMetaSource {

	private const META_KEY  = '_uxstudio_menu_item_visible';
	private const RULES_KEY = '_uxstudio_menu_item_rules';
	private const OLD_KEY   = '_wpext_menu_item_visible';

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
	 * What the item has now and what is wanted, compared as rules (the meta keys mean one thing).
	 *
	 * @param int                  $id   Menu item id.
	 * @param array<string, mixed> $meta Wanted meta.
	 */
	protected function meta_state( int $id, array $meta ): string {
		$have_any = metadata_exists( 'post', $id, self::RULES_KEY ) || metadata_exists( 'post', $id, self::META_KEY ) || metadata_exists( 'post', $id, self::OLD_KEY );
		if ( ! $have_any ) {
			return 'missing';
		}
		$flag = (string) get_post_meta( $id, self::META_KEY, true );
		if ( '' === $flag ) {
			$flag = (string) get_post_meta( $id, self::OLD_KEY, true );
		}
		$have = Rules::from_meta( $flag, (string) get_post_meta( $id, self::RULES_KEY, true ) );
		$want = Rules::from_meta( (string) ( $meta[ self::META_KEY ] ?? '' ), (string) ( $meta[ self::RULES_KEY ] ?? '' ) );
		return Rules::same( $want, $have ) ? 'same' : 'conflict';
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

	/**
	 * Keep the role ids the site has; the rest is returned separately.
	 *
	 * @param string[] $roles Role ids from the old plugin.
	 * @return array{0:string[],1:string[]} Known, unknown.
	 */
	private function split_roles( array $roles ): array {
		$site  = $this->role_ids();
		$roles = array_values( array_unique( array_filter( array_map( 'strval', $roles ), static fn( string $r ): bool => '' !== $r ) ) );
		if ( ! $site ) {
			return array( $roles, array() );
		}
		return array( array_values( array_intersect( $roles, $site ) ), array_values( array_diff( $roles, $site ) ) );
	}

	/**
	 * Rules of an If Menu item, or the reason why they cannot be transferred.
	 *
	 * @param string[] $enable     Values of `if_menu_enable` (`1`, then `and` / `or`).
	 * @param string[] $conditions Condition ids.
	 * @param string[] $types      `show` / `hide`.
	 * @return array{rules:array<int,array<string,mixed>>,skip:string}
	 */
	public function if_menu_rules( array $enable, array $conditions, array $types ): array {
		$groups = array( array() );
		foreach ( array_values( $enable ) as $i => $operator ) {
			if ( $i > 0 && 'or' === (string) $operator ) {
				$groups[] = array();
			}
			$id   = (string) ( $conditions[ $i ] ?? '' );
			$show = 'hide' !== (string) ( $types[ $i ] ?? 'show' );
			if ( 'user-logged-in' === $id ) {
				$term = array( 'type' => $show ? 'in' : 'out' );
			} elseif ( 0 === strpos( $id, 'user-is-' ) && 'user-is-super-admin' !== $id ) {
				list( $known ) = $this->split_roles( array( substr( $id, 8 ) ) );
				if ( ! $known ) {
					return array(
						'rules' => array(),
						'skip'  => 'role:' . substr( $id, 8 ),
					);
				}
				$term = array(
					'type'  => $show ? 'role' : 'not_role',
					'roles' => $known,
				);
			} else {
				return array(
					'rules' => array(),
					'skip'  => 'condition:' . $id,
				);
			}
			$groups[ count( $groups ) - 1 ][] = $term;
		}
		if ( 1 === count( $groups ) ) {
			return array(
				'rules' => $groups[0],
				'skip'  => '',
			);
		}
		// OR: only "show if the user is <role>" terms, one per group, can be written as one role rule.
		$roles = array();
		foreach ( $groups as $group ) {
			if ( 1 !== count( $group ) || 'role' !== $group[0]['type'] ) {
				return array(
					'rules' => array(),
					'skip'  => 'or',
				);
			}
			$roles = array_merge( $roles, $group[0]['roles'] );
		}
		return array(
			'rules' => array(
				array(
					'type'  => 'role',
					'roles' => array_values( array_unique( $roles ) ),
				),
			),
			'skip'  => '',
		);
	}

	/**
	 * Rules of a Menu Items Visibility Control expression, only for a few fixed forms (never executed).
	 *
	 * @param string $logic The expression.
	 * @return array<int, array<string, mixed>>|null Null = not a form we recognize.
	 */
	public function php_rules( string $logic ): ?array {
		$logic = (string) preg_replace( '/\s+/', '', $logic );
		$logic = rtrim( $logic, ';' );
		if ( '' === $logic ) {
			return null;
		}
		$rules = array();
		foreach ( explode( '&&', $logic ) as $term ) {
			if ( 1 !== preg_match( '/^(!?)(?:(is_user_logged_in\(\))|current_user_can\((?:\'([a-z0-9_\-]+)\'|"([a-z0-9_\-]+)")\))$/i', $term, $mm ) ) {
				return null;
			}
			$negate = '!' === $mm[1];
			if ( '' !== $mm[2] ) {
				$rules[] = array( 'type' => $negate ? 'out' : 'in' );
				continue;
			}
			$role = strtolower( '' !== ( $mm[3] ?? '' ) ? $mm[3] : ( $mm[4] ?? '' ) );
			list( $known ) = $this->split_roles( array( $role ) );
			if ( ! $known ) {
				// A capability (edit_posts ...) is not a role: not recognized.
				return null;
			}
			$rules[] = array(
				'type'  => $negate ? 'not_role' : 'role',
				'roles' => $known,
			);
		}
		return $rules;
	}

	protected function build(): array {
		$out = array(
			'items'   => array(),
			'skipped' => array(),
			'unknown' => array(),
		);
		$add = function ( int $id, array $rules ) use ( &$out ): void {
			$rules = Rules::normalize( $rules );
			if ( ! $rules ) {
				return;
			}
			$meta           = Rules::to_meta( $rules );
			$out['items'][] = array(
				'key'    => $this->slug . ':' . $id,
				'label'  => $this->title( $id ),
				'id'     => $id,
				'meta'   => '' !== $meta['visible'] ? array( self::META_KEY => $meta['visible'] ) : array( self::RULES_KEY => $meta['rules'] ),
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
					$hide  = 'hide' === get_post_meta( $id, '_nav_menu_role_display_mode', true );
					if ( in_array( $roles, array( 'in', 'out' ), true ) ) {
						$logged_in = ( 'in' === $roles ) === ! $hide;
						$add( $id, array( array( 'type' => $logged_in ? 'in' : 'out' ) ) );
						continue;
					}
					$roles = is_array( $roles ) ? array_values( array_filter( array_map( 'strval', $roles ) ) ) : array();
					if ( ! $roles ) {
						continue; // Visible to everybody.
					}
					list( $known, $bad ) = $this->split_roles( $roles );
					if ( $bad ) {
						$out['unknown'][] = $this->title( $id ) . ' (' . implode( ', ', $bad ) . ')';
					}
					if ( ! $known ) {
						$skip( 'norole' );
						continue;
					}
					$add(
						$id,
						array(
							array(
								'type'  => $hide ? 'not_role' : 'role',
								'roles' => $known,
							),
						)
					);
				}
				break;
			case 'if-menu':
				foreach ( $this->item_ids( 'if_menu_enable' ) as $id ) {
					$enable = array_map( 'strval', (array) get_post_meta( $id, 'if_menu_enable' ) );
					if ( ! $enable || '0' === $enable[0] || '' === $enable[0] ) {
						continue;
					}
					$res = $this->if_menu_rules( $enable, array_map( 'strval', (array) get_post_meta( $id, 'if_menu_condition' ) ), array_map( 'strval', (array) get_post_meta( $id, 'if_menu_condition_type' ) ) );
					if ( '' !== $res['skip'] ) {
						$skip( $res['skip'] );
						continue;
					}
					$add( $id, $res['rules'] );
				}
				break;
			case 'menu-items-visibility-control':
				foreach ( $this->item_ids( '_menu_item_visibility' ) as $id ) {
					$logic = (string) get_post_meta( $id, '_menu_item_visibility', true );
					if ( '' === trim( $logic ) ) {
						continue;
					}
					$rules = $this->php_rules( $logic );
					if ( null === $rules ) {
						$skip( 'php' );
						continue;
					}
					$add( $id, $rules );
				}
				break;
		}
		return $out;
	}

	protected function map( bool $active ): Mapping {
		$m = new Mapping();
		$c = $this->collect();
		if ( ! $c['items'] && ! $c['skipped'] && empty( $c['unknown'] ) ) {
			$m->note( __( 'No menu item has a visibility rule, there is nothing to transfer.', 'ux-studio' ) );
			return $m;
		}
		if ( ! empty( $c['unknown'] ) ) {
			$m->lossy(
				sprintf(
					/* translators: %s: list of menu items with their roles. */
					__( 'These items name roles (or capabilities) that do not exist on this site. The unknown names are left out of the rule: %s.', 'ux-studio' ),
					implode( '; ', $c['unknown'] )
				)
			);
		}
		foreach ( $c['skipped'] as $reason => $n ) {
			if ( 'or' === $reason ) {
				$m->lossy(
					sprintf(
						/* translators: %d: number of menu items. */
						__( '%d menu items combine rules with OR. The module needs all conditions to hold (AND) and writes only an OR of "user is <role>" as one role rule, so these are not transferred.', 'ux-studio' ),
						$n
					)
				);
			} elseif ( 'norole' === $reason ) {
				$m->lossy(
					sprintf(
						/* translators: %d: number of menu items. */
						__( '%d menu items are limited to roles or capabilities that do not exist on this site, they are not transferred.', 'ux-studio' ),
						$n
					)
				);
			} elseif ( 'php' === $reason ) {
				$m->lossy(
					sprintf(
						/* translators: %d: number of menu items. */
						__( '%d menu items are controlled by a PHP expression. UX Studio never runs code from a setting; only logged in / logged out and "user can <role>" (joined by &&) are recognized, so these are not transferred.', 'ux-studio' ),
						$n
					)
				);
			} elseif ( 0 === strpos( $reason, 'role:' ) ) {
				$m->lossy(
					sprintf(
						/* translators: 1: number of menu items, 2: role. */
						__( '%1$d menu items depend on the role "%2$s", which this site does not have. They are not transferred.', 'ux-studio' ),
						$n,
						substr( $reason, 5 )
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
		$in    = 0;
		$out   = 0;
		$rules = 0;
		foreach ( $this->collect()['items'] as $item ) {
			$flag = (string) ( $item['meta'][ self::META_KEY ] ?? '' );
			if ( '1' === $flag ) {
				++$in;
			} elseif ( '2' === $flag ) {
				++$out;
			} else {
				++$rules;
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
			array(
				'key'   => 'rules',
				'label' => __( 'Menu items with roles or several conditions', 'ux-studio' ),
				'count' => $rules,
			),
		);
	}
}
