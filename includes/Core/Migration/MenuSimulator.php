<?php
/**
 * Pure simulation of who sees which admin menu item (Menu Organizer config), and the admin-lockout rule.
 *
 * @package UxStudio
 */

namespace UxStudio\Core\Migration;

defined( 'ABSPATH' ) || exit;

/**
 * No rendering, no globals of the admin menu: only the Menu Organizer config array and the role
 * lists, so the migration can compare what a foreign plugin shows to a role with what UX Studio
 * will show (PLAN.md 26.11), and refuse to hide the screens an administrator needs to undo a mistake.
 *
 * Rules copied from `MenuOrganizer::reorganize_admin_menu()`:
 *  - a slug listed in `hidden_items` is gone for everybody, administrators included;
 *  - `item_roles[slug]` (non-empty) limits the item to those roles, `category_roles[cat]` the same for
 *    its category; both are ignored for a user who can `manage_options` (bypass);
 *  - the Organizer cannot hide a single sub-menu item: a sub-item is there when its parent is.
 */
final class MenuSimulator {

	/**
	 * Screens an administrator must always keep (slug => label shown in warnings).
	 *
	 * @return array<string, string>
	 */
	public static function protected_screens(): array {
		return array(
			'ux-studio'        => __( 'UX Studio', 'ux-studio' ),
			'plugins.php'      => __( 'Plugins', 'ux-studio' ),
			'users.php'        => __( 'Users', 'ux-studio' ),
			'options-general.php' => __( 'Settings', 'ux-studio' ),
		);
	}

	/**
	 * Admin URLs of the protected screens (relative to wp-admin), used by the smoke check.
	 *
	 * @return string[]
	 */
	public static function protected_urls(): array {
		return array( 'plugins.php', 'admin.php?page=ux-studio', 'users.php', 'options-general.php' );
	}

	/**
	 * Is a top-level item visible to a user with these roles?
	 *
	 * @param array<string, mixed> $config Menu Organizer config (stored keys only is fine).
	 * @param string               $slug   Top-level slug.
	 * @param string[]             $roles  Roles of the user.
	 * @param bool                 $bypass Whether the user can `manage_options`.
	 */
	public static function visible( array $config, string $slug, array $roles, bool $bypass ): bool {
		if ( in_array( $slug, array_map( 'strval', (array) ( $config['hidden_items'] ?? array() ) ), true ) ) {
			return false;
		}
		if ( $bypass ) {
			return true;
		}
		$required = (array) ( $config['item_roles'][ $slug ] ?? array() );
		if ( $required && ! array_intersect( array_map( 'strval', $required ), $roles ) ) {
			return false;
		}
		$cat      = (string) ( $config['assignments'][ $slug ] ?? '' );
		$cat_need = '' !== $cat ? (array) ( $config['category_roles'][ $cat ] ?? array() ) : array();
		if ( $cat_need && ! array_intersect( array_map( 'strval', $cat_need ), $roles ) ) {
			return false;
		}
		return true;
	}

	/**
	 * Top-level slugs (from a list) that a user with these roles sees.
	 *
	 * @param array<string, mixed> $config Menu Organizer config.
	 * @param string[]             $slugs  Slugs to look at.
	 * @param string[]             $roles  Roles of the user.
	 * @param bool                 $bypass Whether the user can `manage_options`.
	 * @return string[]
	 */
	public static function visible_set( array $config, array $slugs, array $roles, bool $bypass ): array {
		$out = array();
		foreach ( $slugs as $slug ) {
			if ( self::visible( $config, (string) $slug, $roles, $bypass ) ) {
				$out[] = (string) $slug;
			}
		}
		return $out;
	}

	/**
	 * Roles of the site that can manage options (the Organizer never restricts them).
	 *
	 * @return string[]
	 */
	public static function bypass_roles(): array {
		if ( ! function_exists( 'wp_roles' ) ) {
			return array( 'administrator' );
		}
		$out = array();
		foreach ( (array) wp_roles()->roles as $id => $role ) {
			if ( ! empty( $role['capabilities']['manage_options'] ) ) {
				$out[] = (string) $id;
			}
		}
		return $out ? $out : array( 'administrator' );
	}

	/**
	 * Who is logged in: roles and whether they can manage options. Null roles = unknown (CLI, tests).
	 *
	 * @return array{roles:string[],bypass:bool}
	 */
	public static function current_user(): array {
		if ( function_exists( 'wp_get_current_user' ) ) {
			$user = wp_get_current_user();
			if ( $user && ! empty( $user->ID ) ) {
				return array(
					'roles'  => array_map( 'strval', (array) $user->roles ),
					'bypass' => function_exists( 'current_user_can' ) && current_user_can( 'manage_options' ),
				);
			}
		}
		return array(
			'roles'  => array(),
			'bypass' => false,
		);
	}

	/**
	 * The lockout rule. A rule that would hide UX Studio, Plugins, Users or Settings from the
	 * administrator role or from the current user is dropped from the config.
	 *
	 * @param array<string, mixed> $config Menu Organizer config values the migration wants to write.
	 * @return array{config:array<string,mixed>,dropped:array<string,string>} Dropped: slug => reason (`hidden` | `roles`).
	 */
	public static function enforce_access( array $config ): array {
		$dropped = array();
		$user    = self::current_user();
		$admin   = self::bypass_roles();
		foreach ( array_keys( self::protected_screens() ) as $slug ) {
			// 1) Hidden for everybody (the only rule that reaches administrators).
			if ( ! self::visible( $config, $slug, $admin, true ) ) {
				$config['hidden_items'] = array_values( array_diff( array_map( 'strval', (array) ( $config['hidden_items'] ?? array() ) ), array( $slug ) ) );
				$dropped[ $slug ]       = 'hidden';
			}
			// 2) Restricted to roles that exclude the current user (who is not an administrator by capability).
			if ( $user['roles'] && ! $user['bypass'] && ! self::visible( $config, $slug, $user['roles'], false ) && isset( $config['item_roles'][ $slug ] ) ) {
				unset( $config['item_roles'][ $slug ] );
				$dropped[ $slug ] = 'roles';
			}
		}
		return array(
			'config'  => $config,
			'dropped' => $dropped,
		);
	}

	/**
	 * Per role: the top-level slugs and the sub-item keys (`parent>sub`) visible to it.
	 *
	 * @param array<string, mixed>              $config Menu Organizer config.
	 * @param string[]                          $tops   Top-level slugs to look at.
	 * @param array<string, string[]>           $subs   Parent slug => its sub-item slugs.
	 * @param string[]                          $roles  Role ids.
	 * @param string[]                          $bypass Roles that can manage options.
	 * @return array<string, string[]> Role => sorted visible keys.
	 */
	public static function target_matrix( array $config, array $tops, array $subs, array $roles, array $bypass ): array {
		$out = array();
		foreach ( $roles as $role ) {
			$is_bypass = in_array( $role, $bypass, true );
			$seen      = array();
			foreach ( $tops as $slug ) {
				if ( self::visible( $config, $slug, array( $role ), $is_bypass ) ) {
					$seen[] = $slug;
					foreach ( (array) ( $subs[ $slug ] ?? array() ) as $sub ) {
						$seen[] = $slug . '>' . $sub;
					}
				}
			}
			sort( $seen );
			$out[ $role ] = $seen;
		}
		return $out;
	}

	/**
	 * Hidden-before / hidden-after lists for the per-role table in the wizard.
	 *
	 * @param array<string, string[]> $before Role => keys the old plugin shows.
	 * @param array<string, string[]> $after  Role => keys UX Studio will show.
	 * @return array<int, array{role:string,hidden_before:string[],hidden_after:string[]}>
	 */
	public static function role_table( array $before, array $after, array $universe ): array {
		$rows = array();
		foreach ( $before as $role => $shown ) {
			$rows[] = array(
				'role'          => (string) $role,
				'hidden_before' => array_values( array_diff( $universe, $shown ) ),
				'hidden_after'  => array_values( array_diff( $universe, (array) ( $after[ $role ] ?? array() ) ) ),
			);
		}
		return $rows;
	}
}
