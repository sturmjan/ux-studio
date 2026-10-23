<?php
/**
 * Pure simulation of who sees which admin menu item (Menu Organizer config), and the admin-lockout rule.
 *
 * @package UxStudio
 */

namespace UxStudio\Core\Migration;

use UxStudio\Modules\AdminCustomiser\MenuOrganizer;

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
		$cat      = self::category_of( $config, $slug );
		$cat_need = '' !== $cat ? (array) ( $config['category_roles'][ $cat ] ?? array() ) : array();
		if ( $cat_need && ! array_intersect( array_map( 'strval', $cat_need ), $roles ) ) {
			return false;
		}
		return true;
	}

	/**
	 * Category of a top-level slug exactly as `MenuOrganizer::reorganize_admin_menu()` resolves it:
	 * the assignment, else the automatic guess, else the fallback category ("other", or the first one).
	 *
	 * @param array<string, mixed> $config Menu Organizer config.
	 * @param string               $slug   Slug.
	 */
	public static function category_of( array $config, string $slug ): string {
		$cats = (array) ( $config['categories'] ?? array() );
		$cat  = (string) ( $config['assignments'][ $slug ] ?? MenuOrganizer::auto_categorize( $slug ) );
		if ( ! $cats || isset( $cats[ $cat ] ) ) {
			return $cat;
		}
		if ( isset( $cats['other'] ) ) {
			return 'other';
		}
		$keys = array_keys( $cats );
		return (string) $keys[0];
	}

	/* ── Access blocking (PLAN.md 27.6): which request is refused to whom ── */

	/**
	 * Admin files that are never blocked, whatever the menu says (everybody needs them to work in the admin).
	 *
	 * @return string[]
	 */
	public static function lock_exempt_files(): array {
		return array( 'index.php', 'profile.php', 'admin-ajax.php', 'admin-post.php', 'async-upload.php', 'options.php', 'load-scripts.php', 'load-styles.php' );
	}

	/**
	 * Which menu entry does a request belong to? Pure: works on the menu as WordPress built it before the Organizer
	 * changed it (top-level slugs, and sub-item slugs per parent).
	 *
	 * `page` (plugin pages) is compared to the entry slug; otherwise the file and the query args of the entry must
	 * match the request (`edit.php?post_type=page`, `edit-tags.php?taxonomy=category`), the entry with the most
	 * matching args wins. `post.php` / `term.php` (editing) belong to the list screen of the same post type / taxonomy.
	 * A request that fits nothing is `null`: it is never blocked.
	 *
	 * @param string                  $file Admin file of the request (`edit.php`).
	 * @param array<string, string>   $args Relevant query args: page, post_type, taxonomy.
	 * @param string[]                $tops Top-level slugs.
	 * @param array<string, string[]> $subs Parent slug => sub-item slugs.
	 * @return array{top:string,sub:?string}|null
	 */
	public static function resolve_request( string $file, array $args, array $tops, array $subs ): ?array {
		$file = strtolower( basename( $file ) );
		if ( 'post.php' === $file ) {
			$file = 'edit.php';
		} elseif ( 'term.php' === $file ) {
			$file = 'edit-tags.php';
		}
		$page    = (string) ( $args['page'] ?? '' );
		$typed   = in_array( $file, array( 'edit.php', 'post-new.php', 'edit-tags.php' ), true );
		$request = array(
			'post_type' => (string) ( $args['post_type'] ?? '' ),
			'taxonomy'  => (string) ( $args['taxonomy'] ?? '' ),
		);
		if ( $typed && '' === $request['post_type'] ) {
			$request['post_type'] = 'post';
		}

		$entries = array();
		foreach ( $tops as $top ) {
			$entries[] = array( (string) $top, null, (string) $top );
			foreach ( (array) ( $subs[ $top ] ?? array() ) as $sub ) {
				$entries[] = array( (string) $top, (string) $sub, (string) $sub );
			}
		}

		$best  = null;
		$score = -1;
		foreach ( $entries as $entry ) {
			$slug   = html_entity_decode( $entry[2], ENT_QUOTES );
			$base   = $slug;
			$params = array();
			$q      = strpos( $slug, '?' );
			if ( false !== $q ) {
				$base = substr( $slug, 0, $q );
				parse_str( substr( $slug, $q + 1 ), $params );
			}
			$is_file = (bool) preg_match( '/\.php$/i', $base );
			if ( '' !== $page ) {
				// Plugin page: the slug is the page, or `admin.php?page=<slug>`; a core parent file (`tools.php`) is preferred as the parent.
				$entry_page = isset( $params['page'] ) ? (string) $params['page'] : ( $is_file ? '' : $base );
				if ( '' === $entry_page || $entry_page !== $page ) {
					continue;
				}
				$hit = 1 + ( $entry[0] === $file ? 1 : 0 );
			} else {
				if ( ! $is_file || strtolower( $base ) !== $file || isset( $params['page'] ) ) {
					continue;
				}
				$hit = 1;
				if ( $typed ) {
					$want_type = (string) ( $params['post_type'] ?? 'post' );
					if ( $want_type !== $request['post_type'] ) {
						continue;
					}
					$hit += isset( $params['post_type'] ) ? 1 : 0;
					if ( isset( $params['taxonomy'] ) ) {
						if ( (string) $params['taxonomy'] !== $request['taxonomy'] ) {
							continue;
						}
						++$hit;
					}
				} else {
					$ok = true;
					foreach ( $params as $name => $value ) {
						if ( (string) ( $args[ $name ] ?? '' ) !== (string) $value ) {
							$ok = false;
							break;
						}
						++$hit;
					}
					if ( ! $ok ) {
						continue;
					}
				}
			}
			if ( $hit > $score ) {
				$score = $hit;
				$best  = array(
					'top' => $entry[0],
					'sub' => $entry[1],
				);
			}
		}
		return $best;
	}

	/**
	 * Is a request refused? The one place that decides, used by the runtime and by the migration check.
	 *
	 * Never refused: users who can manage options (the Organizer shows them everything), the files in
	 * lock_exempt_files(), the four protected screens (and everything under them), a request that fits no menu entry.
	 * Otherwise refused when the top-level item the request belongs to is hidden for this user (hidden_items, item_roles,
	 * category_roles), except when the request is a sub-item that the Organizer moved to the top level and shows to the user.
	 *
	 * @param array<string, mixed>    $config Menu Organizer config.
	 * @param string                  $file   Admin file of the request.
	 * @param array<string, string>   $args   Relevant query args (page, post_type, taxonomy).
	 * @param string[]                $tops   Top-level slugs of the original menu.
	 * @param array<string, string[]> $subs   Parent slug => sub-item slugs of the original menu.
	 * @param string[]                $roles  Roles of the user.
	 * @param bool                    $bypass Whether the user can `manage_options`.
	 * @return array{blocked:bool,reason:string,top:?string}
	 */
	public static function request_blocked( array $config, string $file, array $args, array $tops, array $subs, array $roles, bool $bypass ): array {
		$file = strtolower( basename( $file ) );
		if ( $bypass ) {
			return array(
				'blocked' => false,
				'reason'  => 'bypass',
				'top'     => null,
			);
		}
		if ( in_array( $file, self::lock_exempt_files(), true ) ) {
			return array(
				'blocked' => false,
				'reason'  => 'exempt-file',
				'top'     => null,
			);
		}
		$hit = self::resolve_request( $file, $args, $tops, $subs );
		if ( null === $hit ) {
			return array(
				'blocked' => false,
				'reason'  => 'no-entry',
				'top'     => null,
			);
		}
		$top       = $hit['top'];
		$sub       = $hit['sub'];
		$protected = self::protected_screens();
		if ( isset( $protected[ $top ] ) || ( null !== $sub && isset( $protected[ $sub ] ) ) ) {
			return array(
				'blocked' => false,
				'reason'  => 'protected',
				'top'     => $top,
			);
		}
		if ( self::visible( $config, $top, $roles, false ) ) {
			return array(
				'blocked' => false,
				'reason'  => 'visible',
				'top'     => $top,
			);
		}
		if ( null !== $sub && isset( $config['promoted_subs'][ $sub ] ) && self::visible( $config, $sub, $roles, false ) ) {
			return array(
				'blocked' => false,
				'reason'  => 'promoted',
				'top'     => $top,
			);
		}
		return array(
			'blocked' => true,
			'reason'  => 'hidden',
			'top'     => $top,
		);
	}

	/**
	 * Per role: the top-level slugs (from a list) whose pages are refused with the lock on.
	 *
	 * @param array<string, mixed> $config Menu Organizer config.
	 * @param string[]             $tops   Top-level slugs.
	 * @param string[]             $roles  Role ids.
	 * @param string[]             $bypass Roles that can manage options.
	 * @return array<string, string[]> Role => blocked slugs.
	 */
	public static function blocked_matrix( array $config, array $tops, array $roles, array $bypass ): array {
		$out       = array();
		$protected = self::protected_screens();
		foreach ( $roles as $role ) {
			$list = array();
			if ( ! in_array( $role, $bypass, true ) ) {
				foreach ( $tops as $slug ) {
					if ( ! isset( $protected[ $slug ] ) && ! self::visible( $config, (string) $slug, array( $role ), false ) ) {
						$list[] = (string) $slug;
					}
				}
			}
			sort( $list );
			$out[ $role ] = $list;
		}
		return $out;
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
