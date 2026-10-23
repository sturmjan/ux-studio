<?php
/**
 * Menu Visibility rules: pure logic (no WordPress calls), shared by the module and the migration.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\MenuVisibility;

defined( 'ABSPATH' ) || exit;

/**
 * A menu item has a list of rules and is shown only when ALL of them hold (AND), like If Menu.
 *
 * Rule types:
 *  - `in`       the visitor is logged in
 *  - `out`      the visitor is logged out
 *  - `role`     the visitor has at least one of the roles (a logged-out visitor has none, so it fails)
 *  - `not_role` the visitor has none of the roles (a logged-out visitor passes)
 *
 * Storage (post meta of the menu item), backward compatible with the first version of the module:
 *  - `_uxstudio_menu_item_visible` = `1` (only logged in) | `2` (only logged out): written when the item has exactly
 *    that one rule, so older code and the migration keep working;
 *  - `_uxstudio_menu_item_rules` = canonical JSON list `[{"t":"role","r":["editor"]}, {"t":"in"}]`: written for everything else.
 * The rules meta wins when both exist.
 */
final class Rules {

	public const MAX_RULES = 8;

	public const MAX_ROLES = 40;

	/**
	 * Rule types.
	 *
	 * @return string[]
	 */
	public static function types(): array {
		return array( 'in', 'out', 'role', 'not_role' );
	}

	/**
	 * Clean a list of rules: known types only, roles as slugs, no duplicates, canonical order.
	 *
	 * @param mixed         $raw         List of rules (`type`/`t` and `roles`/`r`), or anything else (becomes empty).
	 * @param string[]|null $valid_roles Roles that exist (null = accept any slug).
	 * @return array<int, array{type:string,roles:string[]}>
	 */
	public static function normalize( $raw, ?array $valid_roles = null ): array {
		if ( ! is_array( $raw ) ) {
			return array();
		}
		$out = array();
		foreach ( $raw as $rule ) {
			if ( ! is_array( $rule ) ) {
				continue;
			}
			$type = (string) ( $rule['type'] ?? $rule['t'] ?? '' );
			if ( ! in_array( $type, self::types(), true ) ) {
				continue;
			}
			if ( 'in' === $type || 'out' === $type ) {
				$out[ $type ] = array(
					'type'  => $type,
					'roles' => array(),
				);
				continue;
			}
			$roles = array();
			foreach ( (array) ( $rule['roles'] ?? $rule['r'] ?? array() ) as $role ) {
				if ( ! is_string( $role ) ) {
					continue;
				}
				$role = strtolower( (string) preg_replace( '/[^a-z0-9_\-]/i', '', $role ) );
				if ( '' === $role || ( null !== $valid_roles && ! in_array( $role, $valid_roles, true ) ) ) {
					continue;
				}
				$roles[ $role ] = $role;
			}
			if ( ! $roles ) {
				continue; // A role rule without roles says nothing.
			}
			$roles = array_values( $roles );
			sort( $roles );
			$roles = array_slice( $roles, 0, self::MAX_ROLES );
			$out[ $type . ':' . implode( ',', $roles ) ] = array(
				'type'  => $type,
				'roles' => $roles,
			);
		}
		$order = array_flip( self::types() );
		uasort(
			$out,
			static function ( array $a, array $b ) use ( $order ): int {
				return array( $order[ $a['type'] ], implode( ',', $a['roles'] ) ) <=> array( $order[ $b['type'] ], implode( ',', $b['roles'] ) );
			}
		);
		return array_slice( array_values( $out ), 0, self::MAX_RULES );
	}

	/**
	 * Does a visitor pass the rules?
	 *
	 * @param array<int, array{type:string,roles:string[]}> $rules     Normalized rules.
	 * @param bool                                          $logged_in Logged in.
	 * @param string[]                                      $roles     Roles of the visitor (empty when logged out).
	 */
	public static function passes( array $rules, bool $logged_in, array $roles ): bool {
		foreach ( $rules as $rule ) {
			switch ( $rule['type'] ) {
				case 'in':
					$ok = $logged_in;
					break;
				case 'out':
					$ok = ! $logged_in;
					break;
				case 'role':
					$ok = $logged_in && (bool) array_intersect( $rule['roles'], $roles );
					break;
				case 'not_role':
					$ok = ! array_intersect( $rule['roles'], $roles );
					break;
				default:
					$ok = true;
			}
			if ( ! $ok ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Rules of an item from its two meta values (the rules JSON wins over the legacy `1` / `2`).
	 *
	 * @param string $legacy Value of `_uxstudio_menu_item_visible` (or the older plugin key).
	 * @param string $json   Value of `_uxstudio_menu_item_rules`.
	 * @return array<int, array{type:string,roles:string[]}>
	 */
	public static function from_meta( string $legacy, string $json ): array {
		if ( '' !== $json ) {
			$decoded = json_decode( $json, true );
			if ( is_array( $decoded ) ) {
				return self::normalize( $decoded );
			}
		}
		if ( '1' === $legacy ) {
			return array(
				array(
					'type'  => 'in',
					'roles' => array(),
				),
			);
		}
		if ( '2' === $legacy ) {
			return array(
				array(
					'type'  => 'out',
					'roles' => array(),
				),
			);
		}
		return array();
	}

	/**
	 * The two meta values for a list of rules.
	 *
	 * @param array<int, array{type:string,roles:string[]}> $rules Normalized rules.
	 * @return array{visible:string,rules:string} Empty string = delete that meta.
	 */
	public static function to_meta( array $rules ): array {
		if ( ! $rules ) {
			return array(
				'visible' => '',
				'rules'   => '',
			);
		}
		if ( 1 === count( $rules ) && 'in' === $rules[0]['type'] ) {
			return array(
				'visible' => '1',
				'rules'   => '',
			);
		}
		if ( 1 === count( $rules ) && 'out' === $rules[0]['type'] ) {
			return array(
				'visible' => '2',
				'rules'   => '',
			);
		}
		$list = array();
		foreach ( $rules as $rule ) {
			$row = array( 't' => $rule['type'] );
			if ( $rule['roles'] ) {
				$row['r'] = $rule['roles'];
			}
			$list[] = $row;
		}
		return array(
			'visible' => '',
			'rules'   => (string) json_encode( $list, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
		);
	}

	/**
	 * Whether two rule lists mean the same.
	 *
	 * @param array $a Rules.
	 * @param array $b Rules.
	 */
	public static function same( array $a, array $b ): bool {
		return self::normalize( $a ) === self::normalize( $b );
	}

	/**
	 * Hide the descendants of removed items too (a child of a hidden parent would otherwise float up to the top level).
	 *
	 * @param array<int, array{id:int,parent:int}> $items   Items in menu order.
	 * @param array<int, bool>                     $visible Item id => passes its own rules.
	 * @return int[] Ids that stay.
	 */
	public static function keep( array $items, array $visible ): array {
		$parent = array();
		foreach ( $items as $item ) {
			$parent[ $item['id'] ] = $item['parent'];
		}
		$out = array();
		foreach ( $items as $item ) {
			$id    = $item['id'];
			$ok    = true;
			$guard = 0;
			while ( $id > 0 && $guard++ < 50 ) {
				if ( isset( $visible[ $id ] ) && ! $visible[ $id ] ) {
					$ok = false;
					break;
				}
				$id = $parent[ $id ] ?? 0;
			}
			if ( $ok ) {
				$out[] = $item['id'];
			}
		}
		return $out;
	}
}
