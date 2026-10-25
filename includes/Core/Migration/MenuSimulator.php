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
 *  - B1 (PLAN.md 27.7): a sub-item has its own rules (`sub_hidden` roles, `sub_moves` to another parent); every item
 *    (a top-level slug or `parent>sub`) can have a required capability (`item_caps`) and show/hide rules for single
 *    users by login (`item_users`). A sub-item is there when its (effective) parent is and its own rules pass.
 *
 * `$ctx` of the functions below: `login` (user login, '' = unknown) and `can` (callable(string $cap): bool,
 * null = capabilities unknown, the rule is then considered satisfied).
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
	 * Key of a sub-item in the rule maps.
	 *
	 * @param string $parent Parent slug.
	 * @param string $sub    Sub-item slug.
	 */
	public static function sub_key( string $parent, string $sub ): string {
		return $parent . '>' . $sub;
	}

	/**
	 * Is a rule key one of the protected screens (top-level slug, or `parent>sub` whose sub-item IS a protected screen)?
	 *
	 * @param string $key Slug or `parent>sub`.
	 */
	public static function is_protected_key( string $key ): bool {
		$protected = self::protected_screens();
		$pos       = strpos( $key, '>' );
		if ( false !== $pos && $pos > 0 ) {
			return isset( $protected[ substr( $key, $pos + 1 ) ] );
		}
		return isset( $protected[ $key ] );
	}

	/**
	 * What a per-user rule says about this user: `hide`, `show` or '' (no rule). Hide wins over show.
	 *
	 * @param array<string, mixed> $config Menu Organizer config.
	 * @param string               $key    Slug or `parent>sub`.
	 * @param string               $login  User login.
	 */
	public static function user_rule( array $config, string $key, string $login ): string {
		if ( '' === $login ) {
			return '';
		}
		$rule  = (array) ( $config['item_users'][ $key ] ?? array() );
		$hide  = array_map( 'strtolower', array_map( 'strval', (array) ( $rule['hide'] ?? array() ) ) );
		$show  = array_map( 'strtolower', array_map( 'strval', (array) ( $rule['show'] ?? array() ) ) );
		$login = strtolower( $login );
		if ( in_array( $login, $hide, true ) ) {
			return 'hide';
		}
		return in_array( $login, $show, true ) ? 'show' : '';
	}

	/**
	 * Does the user have the capability the item requires (none required = yes, capabilities unknown = yes)?
	 *
	 * @param array<string, mixed> $config Menu Organizer config.
	 * @param string               $key    Slug or `parent>sub`.
	 * @param callable|null        $can    callable(string $cap): bool.
	 */
	public static function cap_ok( array $config, string $key, ?callable $can ): bool {
		$cap = (string) ( $config['item_caps'][ $key ] ?? '' );
		if ( '' === $cap || null === $can ) {
			return true;
		}
		return (bool) call_user_func( $can, $cap );
	}

	/**
	 * Role limits of a whole category for these roles (ignored for users who can manage options).
	 *
	 * @param array<string, mixed> $config Menu Organizer config.
	 * @param string               $cat    Category id.
	 * @param string[]             $roles  Roles of the user.
	 * @param bool                 $bypass Whether the user can `manage_options`.
	 */
	public static function category_pass( array $config, string $cat, array $roles, bool $bypass ): bool {
		if ( $bypass || '' === $cat ) {
			return true;
		}
		$cat_need = (array) ( $config['category_roles'][ $cat ] ?? array() );
		return ! $cat_need || (bool) array_intersect( array_map( 'strval', $cat_need ), $roles );
	}

	/**
	 * The role / user / capability rules of one item (not `hidden_items`, that is the caller's business).
	 *
	 * Order: a per-user "hide" and a missing required capability hide the item (except the protected screens for users who can
	 * manage options); users who can manage options see everything else; a per-user "show" beats the role limits of the item
	 * and of its category; otherwise `item_roles` and `category_roles` decide.
	 *
	 * @param array<string, mixed> $config Menu Organizer config.
	 * @param string               $slug   Top-level slug (or any key present in the rule maps).
	 * @param string               $cat    Category of the item ('' = none).
	 * @param string[]             $roles  Roles of the user.
	 * @param bool                 $bypass Whether the user can `manage_options`.
	 * @param array<string, mixed> $ctx    login, can.
	 */
	public static function pass( array $config, string $slug, string $cat, array $roles, bool $bypass, array $ctx = array() ): bool {
		$rule    = self::user_rule( $config, $slug, (string) ( $ctx['login'] ?? '' ) );
		$protect = $bypass && self::is_protected_key( $slug );
		if ( ! $protect && ( 'hide' === $rule || ! self::cap_ok( $config, $slug, $ctx['can'] ?? null ) ) ) {
			return false;
		}
		if ( $bypass || 'show' === $rule ) {
			return true;
		}
		$required = (array) ( $config['item_roles'][ $slug ] ?? array() );
		if ( $required && ! array_intersect( array_map( 'strval', $required ), $roles ) ) {
			return false;
		}
		return self::category_pass( $config, $cat, $roles, false );
	}

	/**
	 * Is a top-level item visible to a user with these roles?
	 *
	 * @param array<string, mixed> $config Menu Organizer config (stored keys only is fine).
	 * @param string               $slug   Top-level slug.
	 * @param string[]             $roles  Roles of the user.
	 * @param bool                 $bypass Whether the user can `manage_options`.
	 * @param array<string, mixed> $ctx    login, can (see the class comment).
	 */
	public static function visible( array $config, string $slug, array $roles, bool $bypass, array $ctx = array() ): bool {
		if ( in_array( $slug, array_map( 'strval', (array) ( $config['hidden_items'] ?? array() ) ), true ) ) {
			return false;
		}
		return self::pass( $config, $slug, self::category_of( $config, $slug ), $roles, $bypass, $ctx );
	}

	/**
	 * The top-level parent a sub-item is shown under (its own parent unless the config moves it).
	 *
	 * @param array<string, mixed> $config Menu Organizer config.
	 * @param string               $parent Original parent slug.
	 * @param string               $sub    Sub-item slug.
	 */
	public static function effective_parent( array $config, string $parent, string $sub ): string {
		$to = (string) ( $config['sub_moves'][ self::sub_key( $parent, $sub ) ] ?? '' );
		return '' !== $to ? $to : $parent;
	}

	/**
	 * The sub-item's OWN rules (hidden for roles / users, required capability), without looking at its parent.
	 *
	 * `sub_hidden[key]` is a list of roles the item is hidden for (`*` = everybody). A user is hidden when every role they
	 * have is in the list (one role that may see it is enough, like everywhere else); a user without roles only by `*`.
	 * A per-user "show" beats the role list. Users who can manage options are not exempt, except for the protected screens.
	 *
	 * @param array<string, mixed> $config Menu Organizer config.
	 * @param string               $parent Original parent slug.
	 * @param string               $sub    Sub-item slug.
	 * @param string[]             $roles  Roles of the user.
	 * @param bool                 $bypass Whether the user can `manage_options`.
	 * @param array<string, mixed> $ctx    login, can.
	 */
	public static function sub_own_visible( array $config, string $parent, string $sub, array $roles, bool $bypass, array $ctx = array() ): bool {
		$key = self::sub_key( $parent, $sub );
		if ( $bypass && self::is_protected_key( $key ) ) {
			return true;
		}
		$rule = self::user_rule( $config, $key, (string) ( $ctx['login'] ?? '' ) );
		if ( 'hide' === $rule || ! self::cap_ok( $config, $key, $ctx['can'] ?? null ) ) {
			return false;
		}
		if ( 'show' === $rule ) {
			return true;
		}
		$hidden = array_map( 'strval', (array) ( $config['sub_hidden'][ $key ] ?? array() ) );
		if ( ! $hidden ) {
			return true;
		}
		if ( in_array( '*', $hidden, true ) ) {
			return false;
		}
		return ! $roles || (bool) array_diff( $roles, $hidden );
	}

	/**
	 * Is a sub-item shown to the user: its (effective) parent is visible and its own rules pass.
	 *
	 * @param array<string, mixed> $config Menu Organizer config.
	 * @param string               $parent Original parent slug.
	 * @param string               $sub    Sub-item slug.
	 * @param string[]             $roles  Roles of the user.
	 * @param bool                 $bypass Whether the user can `manage_options`.
	 * @param array<string, mixed> $ctx    login, can.
	 */
	public static function sub_visible( array $config, string $parent, string $sub, array $roles, bool $bypass, array $ctx = array() ): bool {
		$eff = self::effective_parent( $config, $parent, $sub );
		return self::visible( $config, $eff, $roles, $bypass, $ctx ) && self::sub_own_visible( $config, $parent, $sub, $roles, $bypass, $ctx );
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
	 * @param array<string, mixed>    $ctx    login, can (B1: per-user rules and required capabilities).
	 * @return array{blocked:bool,reason:string,top:?string}
	 */
	public static function request_blocked( array $config, string $file, array $args, array $tops, array $subs, array $roles, bool $bypass, array $ctx = array() ): array {
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
		// The first sub-item of a menu is the page of the menu itself (same slug): the request is that sub-item, so its own
		// rules (B1) apply as well as the rules of the parent.
		if ( null === $sub && in_array( $top, array_map( 'strval', (array) ( $subs[ $top ] ?? array() ) ), true ) ) {
			$sub = $top;
		}
		$protected = self::protected_screens();
		if ( isset( $protected[ $top ] ) || ( null !== $sub && isset( $protected[ $sub ] ) ) ) {
			return array(
				'blocked' => false,
				'reason'  => 'protected',
				'top'     => $top,
			);
		}
		if ( null === $sub ) {
			if ( self::visible( $config, $top, $roles, false, $ctx ) ) {
				return array(
					'blocked' => false,
					'reason'  => 'visible',
					'top'     => $top,
				);
			}
		} else {
			// A sub-item is shown under its effective parent (B1: it may have been moved) when that parent is visible ...
			$eff = self::effective_parent( $config, $top, $sub );
			if ( self::visible( $config, $eff, $roles, false, $ctx ) ) {
				// ... and its own rules (roles, users, required capability) pass.
				if ( self::sub_own_visible( $config, $top, $sub, $roles, false, $ctx ) ) {
					return array(
						'blocked' => false,
						'reason'  => 'visible',
						'top'     => $top,
					);
				}
				return array(
					'blocked' => true,
					'reason'  => 'hidden-sub',
					'top'     => $top,
				);
			}
		}
		if ( null !== $sub && isset( $config['promoted_subs'][ $sub ] ) && self::visible( $config, $sub, $roles, false, $ctx ) ) {
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
	 * @param array<string, string[]> $subs Parent slug => sub-item slugs: when given, `parent>sub` keys of sub-items refused for the role are listed too (B1).
	 * @param array<string, string[]>|null $caps Role => capabilities (for required capabilities; null = unknown, such rules are skipped).
	 * @return array<string, string[]> Role => blocked slugs (and `parent>sub` keys).
	 */
	public static function blocked_matrix( array $config, array $tops, array $roles, array $bypass, array $subs = array(), ?array $caps = null ): array {
		$out       = array();
		$protected = self::protected_screens();
		foreach ( $roles as $role ) {
			$list = array();
			if ( ! in_array( $role, $bypass, true ) ) {
				$ctx = self::role_ctx( $role, $caps );
				foreach ( $tops as $slug ) {
					if ( ! isset( $protected[ $slug ] ) && ! self::visible( $config, (string) $slug, array( $role ), false, $ctx ) ) {
						$list[] = (string) $slug;
					}
				}
				foreach ( $subs as $parent => $children ) {
					if ( isset( $protected[ $parent ] ) ) {
						continue;
					}
					foreach ( (array) $children as $sub ) {
						$key = self::sub_key( (string) $parent, (string) $sub );
						if ( ! self::is_protected_key( $key ) && ! self::sub_visible( $config, (string) $parent, (string) $sub, array( $role ), false, $ctx ) && ! isset( $config['promoted_subs'][ $sub ] ) ) {
							$list[] = $key;
						}
					}
				}
			}
			sort( $list );
			$out[ $role ] = $list;
		}
		return $out;
	}

	/**
	 * Context for a simulation by role (no login; capabilities from a role => capabilities map).
	 *
	 * @param string                       $role Role id.
	 * @param array<string, string[]>|null $caps Role => capabilities, null = unknown.
	 * @return array<string, mixed>
	 */
	public static function role_ctx( string $role, ?array $caps ): array {
		if ( null === $caps ) {
			return array();
		}
		$have = array_map( 'strval', (array) ( $caps[ $role ] ?? array() ) );
		return array(
			'can' => static fn( string $cap ): bool => in_array( $cap, $have, true ),
		);
	}

	/**
	 * Role => capabilities of the site (granted ones), for simulations that look at required capabilities.
	 *
	 * @param string[] $roles Role ids.
	 * @return array<string, string[]>
	 */
	public static function role_caps( array $roles ): array {
		$out = array();
		foreach ( $roles as $role ) {
			$out[ $role ] = array();
			if ( function_exists( 'wp_roles' ) ) {
				$all = (array) wp_roles()->roles;
				if ( isset( $all[ $role ]['capabilities'] ) ) {
					$out[ $role ] = array_map( 'strval', array_keys( array_filter( (array) $all[ $role ]['capabilities'] ) ) );
				}
			}
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
	 * Who is logged in: roles, login and whether they can manage options. Empty roles = unknown (CLI, tests).
	 *
	 * @return array{roles:string[],bypass:bool,login:string}
	 */
	public static function current_user(): array {
		if ( function_exists( 'wp_get_current_user' ) ) {
			$user = wp_get_current_user();
			if ( $user && ! empty( $user->ID ) ) {
				return array(
					'roles'  => array_map( 'strval', (array) $user->roles ),
					'bypass' => function_exists( 'current_user_can' ) && current_user_can( 'manage_options' ),
					'login'  => (string) ( $user->user_login ?? '' ),
				);
			}
		}
		return array(
			'roles'  => array(),
			'bypass' => false,
			'login'  => '',
		);
	}

	/**
	 * Context of the current user for the rule functions (login and a capability check).
	 *
	 * @return array<string, mixed>
	 */
	public static function current_ctx(): array {
		$user = self::current_user();
		return array(
			'login' => $user['login'],
			'can'   => static fn( string $cap ): bool => function_exists( 'current_user_can' ) && current_user_can( $cap ),
		);
	}

	/**
	 * The lockout rule. A rule that would hide UX Studio, Plugins, Users or Settings from the
	 * administrator role or from the current user is dropped from the config. B1: the same for rules
	 * of single users, required capabilities and hidden sub-items that ARE one of those screens.
	 *
	 * @param array<string, mixed> $config Menu Organizer config values the migration wants to write.
	 * @return array{config:array<string,mixed>,dropped:array<string,string>} Dropped: slug => reason (`hidden` | `roles` | `user` | `cap` | `sub`).
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

		// 3) Per-user hide rules, required capabilities and hidden sub-items of the protected screens.
		foreach ( array( 'item_users', 'item_caps', 'sub_hidden' ) as $map ) {
			foreach ( array_keys( (array) ( $config[ $map ] ?? array() ) ) as $key ) {
				$key = (string) $key;
				if ( ! self::is_protected_key( $key ) ) {
					continue;
				}
				$pos  = strpos( $key, '>' );
				$slug = false !== $pos && $pos > 0 ? substr( $key, $pos + 1 ) : $key;
				if ( 'item_users' === $map ) {
					if ( ! empty( $config['item_users'][ $key ]['hide'] ) ) {
						unset( $config['item_users'][ $key ]['hide'] );
						if ( empty( $config['item_users'][ $key ]['show'] ) ) {
							unset( $config['item_users'][ $key ] );
						}
						$dropped[ $slug ] = $dropped[ $slug ] ?? 'user';
					}
				} elseif ( 'item_caps' === $map ) {
					unset( $config['item_caps'][ $key ] );
					$dropped[ $slug ] = $dropped[ $slug ] ?? 'cap';
				} else {
					$hidden = array_map( 'strval', (array) $config['sub_hidden'][ $key ] );
					if ( in_array( '*', $hidden, true ) || array_intersect( $hidden, $admin ) || ( $user['roles'] && ! array_diff( $user['roles'], $hidden ) ) ) {
						unset( $config['sub_hidden'][ $key ] );
						$dropped[ $slug ] = $dropped[ $slug ] ?? 'sub';
					}
				}
			}
		}
		return array(
			'config'  => $config,
			'dropped' => $dropped,
		);
	}

	/**
	 * Per role: the top-level slugs and the sub-item keys (`effective parent>sub`) visible to it.
	 *
	 * @param array<string, mixed>              $config Menu Organizer config.
	 * @param string[]                          $tops   Top-level slugs to look at.
	 * @param array<string, string[]>           $subs   ORIGINAL parent slug => its sub-item slugs.
	 * @param string[]                          $roles  Role ids.
	 * @param string[]                          $bypass Roles that can manage options.
	 * @param array<string, string[]>|null      $caps   Role => capabilities (null = unknown, required capabilities are not checked).
	 * @return array<string, string[]> Role => sorted visible keys.
	 */
	public static function target_matrix( array $config, array $tops, array $subs, array $roles, array $bypass, ?array $caps = null ): array {
		$out = array();
		foreach ( $roles as $role ) {
			$is_bypass = in_array( $role, $bypass, true );
			$ctx       = self::role_ctx( $role, $caps );
			$seen      = array();
			foreach ( $tops as $slug ) {
				if ( self::visible( $config, $slug, array( $role ), $is_bypass, $ctx ) ) {
					$seen[] = $slug;
				}
			}
			foreach ( $subs as $parent => $children ) {
				foreach ( (array) $children as $sub ) {
					if ( self::sub_visible( $config, (string) $parent, (string) $sub, array( $role ), $is_bypass, $ctx ) ) {
						$seen[] = self::effective_parent( $config, (string) $parent, (string) $sub ) . '>' . $sub;
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
