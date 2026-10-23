<?php
/**
 * Shared part of the Admin Customiser sources (admin menu, login screen, admin bar ...).
 *
 * @package UxStudio
 */

namespace UxStudio\Core\Migration\Sources;

use UxStudio\Core\Migration\AbstractSource;
use UxStudio\Core\Migration\Mapping;
use UxStudio\Core\Migration\MenuSimulator;
use UxStudio\Core\Migration\SettingsWriter;
use UxStudio\Core\Migration\Staging;
use UxStudio\Modules\AdminCustomiser\MenuOrganizer;

defined( 'ABSPATH' ) || exit;

/**
 * PLAN.md 26.11. The admin menu of a foreign plugin is described as a small neutral "model" (known
 * slugs, order, renames, hidden items, roles that must not see an item ...); this class turns a model
 * into a Menu Organizer config, applies the lockout rule, and later compares what each role sees
 * according to the foreign plugin with what UX Studio will show.
 *
 * Model keys (all optional except `tops`):
 *   tops          string[]                     known top-level slugs, in display order
 *   groups        string[][]|null              the same slugs (plus `custom:<id>` links) cut into groups, null = one group
 *   subs          array<string,string[]>       parent slug => sub-item slugs (for the comparison)
 *   titles        array<string,string>         slug => new title
 *   icons         array<string,string>         slug => icon (dashicon class or something else)
 *   hidden        string[]                     top-level slugs hidden for everybody
 *   denied        array<string,string[]>       top-level slug => roles that must not see it
 *   denied_subs   array<string,string[]>       `parent>sub` => roles that must not see it
 *   hidden_subs   string[]                     `parent>sub` hidden for everybody
 *   blocked_subs  array<string,string[]>       `parent>sub` => roles whose access the plugin takes away (page blocked, not only hidden)
 *   sub_titles    array<string,string>         `parent>sub` => new title (B1)
 *   sub_moves     array<string,string>         `parent>sub` => top-level slug it is shown under instead (B1); the key uses the DISPLAY parent unless `sub_origin` says otherwise
 *   sub_origin    array<string,string>         display `parent>sub` => `original parent>sub` for moved sub-items (B1)
 *   item_caps     array<string,string>         slug or `parent>sub` => extra capability the item requires (B1)
 *   item_users    array<string,array>          slug or `parent>sub` => { show: logins, hide: logins } (B1)
 *   blocked       array<string,string[]>       top-level slug => roles whose access the plugin takes away (page blocked, not only hidden)
 *   block_all     bool                         every item in `denied` is blocked for its roles (Adminimize without "Allow Page Access")
 *   promoted      array<string,array>          sub slug => parent, title, icon (moved to the top level)
 *   custom_links  array<int,array>             id, title, url, icon, target
 *   lossy         string[]                     further sentences for the lossy list
 */
abstract class AbstractAdminSource extends AbstractSource {

	protected const MODULE = 'admin-customiser';

	/** @var callable|null Test seam: URL => attachment id. */
	private static $media_double = null;

	/** @var array<string, mixed> What each role is expected to see (set while mapping, used by verify and the plan). */
	protected array $menu_expect = array();

	/**
	 * Replace how a media URL becomes an attachment id (tests only).
	 *
	 * @param callable|null $double Receives the URL.
	 */
	public static function set_media_double( ?callable $double ): void {
		self::$media_double = $double;
	}

	/**
	 * Attachment id for an uploaded file URL (0 = not in the media library).
	 *
	 * @param string $url File URL or id.
	 */
	protected function attachment_id( string $url ): int {
		$url = trim( $url );
		if ( '' === $url ) {
			return 0;
		}
		if ( null !== self::$media_double ) {
			return (int) call_user_func( self::$media_double, $url );
		}
		if ( ctype_digit( $url ) ) {
			return (int) $url;
		}
		if ( ! function_exists( 'attachment_url_to_postid' ) ) {
			return 0;
		}
		$id = (int) attachment_url_to_postid( $url );
		if ( $id > 0 ) {
			return $id;
		}
		// The site moved (another host or scheme): try the path below `uploads/` against this site's uploads folder.
		$pos = strpos( $url, '/uploads/' );
		if ( false !== $pos && function_exists( 'wp_upload_dir' ) ) {
			$dir = wp_upload_dir();
			return (int) attachment_url_to_postid( rtrim( (string) ( $dir['baseurl'] ?? '' ), '/' ) . substr( $url, $pos + 8 ) );
		}
		return 0;
	}

	/**
	 * Translated role name.
	 *
	 * @param string $role Role id.
	 */
	protected function role_label( string $role ): string {
		if ( function_exists( 'wp_roles' ) && function_exists( 'translate_user_role' ) ) {
			$roles = (array) wp_roles()->roles;
			if ( isset( $roles[ $role ]['name'] ) ) {
				return (string) translate_user_role( (string) $roles[ $role ]['name'] );
			}
		}
		return $role;
	}

	/**
	 * Comma separated role names.
	 *
	 * @param string[] $roles Role ids.
	 */
	protected function role_names( array $roles ): string {
		return implode( ', ', array_map( fn( string $r ): string => $this->role_label( $r ), $roles ) );
	}

	/**
	 * Whether a role list covers the administrator role (the main audience: a plugin that hides
	 * something for administrators hides it for the person who runs the site).
	 *
	 * @param string[] $roles Role ids.
	 */
	protected function covers_admin( array $roles ): bool {
		return (bool) array_intersect( $roles, MenuSimulator::bypass_roles() );
	}

	/**
	 * Clean hex colour or empty string (the module accepts hex only).
	 *
	 * @param mixed $value Raw colour.
	 */
	protected function hex( $value ): string {
		$value = trim( (string) $value );
		return 1 === preg_match( '/^#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', $value ) ? $value : '';
	}

	/**
	 * Slug as WordPress stores it in the menu (`&amp;` decoded, trimmed).
	 *
	 * @param string $slug Slug from a foreign plugin.
	 */
	protected function clean_slug( string $slug ): string {
		return trim( html_entity_decode( $slug, ENT_QUOTES ) );
	}

	/* ── Menu model -> Menu Organizer config ── */

	/**
	 * Turn a model into mapped Menu Organizer values, apply the lockout rule, remember what each role
	 * is expected to see, and list what is lost.
	 *
	 * @param Mapping              $m      Mapping to fill.
	 * @param array<string, mixed> $model  Model (see class comment).
	 * @param string               $origin Where the data comes from.
	 * @param bool                 $own_order Whether the source dictates the order and grouping.
	 */
	protected function map_menu( Mapping $m, array $model, string $origin, bool $own_order ): void {
		$roles    = $this->role_ids();
		$bypass   = array_values( array_intersect( MenuSimulator::bypass_roles(), $roles ? $roles : array( 'administrator' ) ) );
		$tops     = array_values( array_unique( array_map( 'strval', (array) ( $model['tops'] ?? array() ) ) ) );
		$lossy    = (array) ( $model['lossy'] ?? array() );
		$explain  = array();
		$hidden   = array_values( array_unique( array_map( 'strval', (array) ( $model['hidden'] ?? array() ) ) ) );
		$item_roles = array();
		$keep_layout = false;

		foreach ( (array) ( $model['denied'] ?? array() ) as $slug => $denied ) {
			$denied = array_values( array_unique( array_map( 'strval', (array) $denied ) ) );
			if ( ! $denied ) {
				continue;
			}
			$known   = $roles ? array_values( array_intersect( $denied, $roles ) ) : $denied;
			$allowed = array_values( array_diff( $roles, $known ) );
			$admin_d = array_values( array_intersect( $known, $bypass ) );
			$other_d = array_values( array_diff( $known, $bypass ) );
			if ( $admin_d ) {
				$explain[] = (string) $slug;
				$lossy[]   = sprintf(
					/* translators: 1: menu item, 2: roles. */
					__( 'The item "%1$s" is hidden from %2$s in the plugin. The Menu Organizer always shows every item to users who can manage options, so it stays visible to them.', 'ux-studio' ),
					$this->title_of( $model, (string) $slug ),
					$this->role_names( $admin_d )
				);
			}
			if ( ! $other_d ) {
				continue;
			}
			if ( ! array_diff( $roles, $known ) ) {
				// Nobody may see it: the only way to say that is to hide the item for everybody.
				$hidden[] = (string) $slug;
				continue;
			}
			$item_roles[ (string) $slug ] = $allowed;
		}
		$hidden = array_values( array_unique( $hidden ) );

		$input = array(
			'hidden_items' => $hidden,
			'item_roles'   => $item_roles,
		);

		// B1: rules of sub-items, capabilities and single users. Model keys of sub-items are the DISPLAY position,
		// the config keys the ORIGINAL parent (the one WordPress registered the page under).
		$origin_of = static fn( string $key ): string => (string) ( $model['sub_origin'][ $key ] ?? $key );
		$sub_hidden = array();
		foreach ( (array) ( $model['hidden_subs'] ?? array() ) as $key ) {
			$sub_hidden[ $origin_of( (string) $key ) ] = array( '*' );
		}
		foreach ( (array) ( $model['denied_subs'] ?? array() ) as $key => $denied ) {
			$denied = array_values( array_unique( array_map( 'strval', (array) $denied ) ) );
			$known  = $roles ? array_values( array_intersect( $denied, $roles ) ) : $denied;
			$okey   = $origin_of( (string) $key );
			if ( ! $known || array( '*' ) === ( $sub_hidden[ $okey ] ?? null ) ) {
				continue;
			}
			// Every role of the site: the only way to say that is "everybody".
			$sub_hidden[ $okey ] = $roles && ! array_diff( $roles, $known ) ? array( '*' ) : $known;
		}
		$input['sub_hidden'] = $sub_hidden;
		$map_keys = static function ( array $map ) use ( $origin_of ): array {
			$out = array();
			foreach ( $map as $key => $value ) {
				$out[ $origin_of( (string) $key ) ] = $value;
			}
			return $out;
		};
		$input['sub_titles'] = $map_keys( (array) ( $model['sub_titles'] ?? array() ) );
		$input['sub_moves']  = $map_keys( (array) ( $model['sub_moves'] ?? array() ) );
		$input['item_caps']  = $map_keys( (array) ( $model['item_caps'] ?? array() ) );
		$input['item_users'] = $map_keys( (array) ( $model['item_users'] ?? array() ) );

		if ( $own_order ) {
			$groups   = (array) ( $model['groups'] ?? array( $tops ) );
			$cats     = array();
			$assign   = array();
			$order    = array();
			$cat_ids  = array();
			foreach ( array_values( $groups ) as $i => $group ) {
				$id             = 'group-' . ( $i + 1 );
				$cat_ids[]      = $id;
				$cats[ $id ]    = sprintf(
					/* translators: %d: group number. */
					__( 'Group %d', 'ux-studio' ),
					$i + 1
				);
				$order[ $id ]   = array();
				foreach ( (array) $group as $slug ) {
					$assign[ (string) $slug ] = $id;
					$order[ $id ][]           = (string) $slug;
				}
			}
			$cats['other']  = __( 'Other', 'ux-studio' );
			$cat_ids[]      = 'other';
			$input['categories']     = $cats;
			$input['category_order'] = $cat_ids;
			$input['assignments']    = $assign;
			$input['order']          = $order;
			$m->note( __( 'The groups of the old menu (the parts between its separators) become categories "Group 1, 2 ..." of the Menu Organizer, in the same order. Items the plugin does not know about land in "Other".', 'ux-studio' ) );
		} else {
			// Only hiding: one category keeps the native order and does not regroup the whole menu ...
			$input['categories']     = array( 'other' => __( 'Menu', 'ux-studio' ) );
			$input['category_order'] = array( 'other' );
			$input['assignments']    = array();
			$input['order']          = array();
			// ... unless an arrangement is already there (another plugin's menu that was migrated first): keep it.
			$existing = $this->effective_menu_config();
			if ( $existing['assignments'] || $existing['order'] ) {
				$keep_layout = true;
				$m->note( __( 'The menu already has an arrangement (categories and order), so only the hidden items and the role limits were added to it.', 'ux-studio' ) );
			}
		}

		$titles = array();
		foreach ( (array) ( $model['titles'] ?? array() ) as $slug => $title ) {
			$title = trim( (string) $title );
			if ( '' !== $title ) {
				$titles[ (string) $slug ] = $title;
			}
		}
		$input['item_titles'] = $titles;
		// Labels for the ids the plan prints inside the lists (menu slug -> the title the user sees).
		$originals = array_map( fn( $t ): string => $this->plain( (string) $t ), array_merge( (array) ( $model['titles'] ?? array() ), (array) ( $model['defaults'] ?? array() ) ) );
		// Defaults first, the titles the user sees now on top (the "orig:" copy is the title before any rename).
		$m->names( array_map( fn( $t ): string => $this->plain( (string) $t ), (array) ( $model['defaults'] ?? array() ) ) );
		$m->names( array_map( fn( $t ): string => $this->plain( (string) $t ), (array) ( $model['titles'] ?? array() ) ) );
		$m->names( array_combine( array_map( static fn( $k ): string => 'orig:' . $k, array_keys( $originals ) ), array_values( $originals ) ) ?: array() );

		$icons = array();
		$bad_icons = array();
		foreach ( (array) ( $model['icons'] ?? array() ) as $slug => $icon ) {
			$icon = trim( (string) $icon );
			if ( '' === $icon ) {
				continue;
			}
			if ( '' !== MenuOrganizer::sanitize_icon_value( $icon ) ) {
				$icons[ (string) $slug ] = $icon;
			} else {
				$bad_icons[] = $this->title_of( $model, (string) $slug );
			}
		}
		$input['item_icons'] = $icons;
		if ( $bad_icons ) {
			$lossy[] = sprintf(
				/* translators: %s: menu items. */
				__( 'Icons of these items (image, SVG or an icon that is not in the Menu Organizer set) are not transferred: %s.', 'ux-studio' ),
				$this->list_text( $bad_icons )
			);
		}

		$promoted = array();
		foreach ( (array) ( $model['promoted'] ?? array() ) as $sub => $p ) {
			$promoted[ (string) $sub ] = array(
				'parent'   => (string) $p['parent'],
				'title'    => (string) $p['title'],
				'icon'     => MenuOrganizer::sanitize_icon_value( (string) ( $p['icon'] ?? '' ) ),
				'category' => (string) ( $input['assignments'][ (string) $sub ] ?? 'other' ),
			);
		}
		$input['promoted_subs'] = $promoted;
		$links                  = array();
		foreach ( (array) ( $model['custom_links'] ?? array() ) as $link ) {
			$link['category'] = (string) ( $input['assignments'][ 'custom:' . $link['id'] ] ?? 'other' );
			$links[]          = $link;
		}
		$input['custom_links'] = $links;

		// The lockout rule: never hide UX Studio, Plugins, Users or Settings from whoever runs the migration.
		$safe    = MenuSimulator::enforce_access( $input );
		$input   = $safe['config'];
		foreach ( $safe['dropped'] as $slug => $why ) {
			$explain[] = (string) $slug;
			$labels    = MenuSimulator::protected_screens();
			$texts     = array(
				/* translators: %s: screen name. */
				'hidden' => __( 'The rule that hides "%s" was not transferred: the migration never hides UX Studio, Plugins, Users or Settings from administrators, so that nobody locks themselves out.', 'ux-studio' ),
				/* translators: %s: screen name. */
				'roles'  => __( 'The rule that limits "%s" to some roles was not transferred: it would have taken the screen away from the current user.', 'ux-studio' ),
				/* translators: %s: screen name. */
				'user'   => __( 'The rule that hides "%s" from a single user was not transferred: UX Studio, Plugins, Users and Settings are never hidden by rules of users, so that nobody locks themselves out.', 'ux-studio' ),
				/* translators: %s: screen name. */
				'cap'    => __( 'The required capability on "%s" was not transferred: UX Studio, Plugins, Users and Settings never depend on an extra capability, so that nobody locks themselves out.', 'ux-studio' ),
				/* translators: %s: screen name. */
				'sub'    => __( 'The rule that hides the sub-item "%s" was not transferred: UX Studio, Plugins, Users and Settings are never hidden, so that nobody locks themselves out.', 'ux-studio' ),
			);
			$warning   = sprintf( $texts[ $why ] ?? $texts['hidden'], $labels[ $slug ] ?? $slug );
			$m->warn( $warning );
			$lossy[] = $warning;
		}

		$clean = MenuOrganizer::clean_config( $input, MenuOrganizer::default_config() );
		$sub_keys = array( 'sub_titles', 'sub_hidden', 'sub_moves', 'item_caps', 'item_users' );
		$keys     = array_merge( array( 'categories', 'category_order', 'assignments', 'order', 'item_titles', 'item_icons', 'item_roles', 'hidden_items', 'promoted_subs', 'custom_links' ), $sub_keys );
		if ( ! $own_order ) {
			$keys = array_merge( $keep_layout ? array( 'item_roles', 'hidden_items' ) : array( 'categories', 'category_order', 'item_roles', 'hidden_items' ), $sub_keys );
		}
		// Keys the plugin left empty are not written: they would wipe what is already set up in the module.
		$always = array( 'categories', 'category_order' );
		$values = array();
		foreach ( $keys as $key ) {
			if ( in_array( $key, $always, true ) || array() !== $clean[ $key ] ) {
				$values[ $key ] = $clean[ $key ];
			}
		}
		$m->option(
			self::MODULE,
			MenuOrganizer::CONFIG_OPTION,
			$values,
			array(
				'categories'     => __( 'Menu: categories', 'ux-studio' ),
				'category_order' => __( 'Menu: order of categories', 'ux-studio' ),
				'assignments'    => __( 'Menu: item to category', 'ux-studio' ),
				'order'          => __( 'Menu: order of items', 'ux-studio' ),
				'item_titles'    => __( 'Menu: renamed items', 'ux-studio' ),
				'item_icons'     => __( 'Menu: item icons', 'ux-studio' ),
				'item_roles'     => __( 'Menu: items limited to roles', 'ux-studio' ),
				'hidden_items'   => __( 'Menu: hidden items', 'ux-studio' ),
				'promoted_subs'  => __( 'Menu: sub-items moved to the top level', 'ux-studio' ),
				'custom_links'   => __( 'Menu: custom links', 'ux-studio' ),
				'sub_titles'     => __( 'Menu: renamed sub-items', 'ux-studio' ),
				'sub_hidden'     => __( 'Menu: hidden sub-items', 'ux-studio' ),
				'sub_moves'      => __( 'Menu: sub-items moved under another parent', 'ux-studio' ),
				'item_caps'      => __( 'Menu: required capabilities', 'ux-studio' ),
				'item_users'     => __( 'Menu: rules for single users', 'ux-studio' ),
			),
			$origin
		);
		$m->set( self::MODULE, 'menu_organizer_enabled', true, __( 'Enable menu organizer', 'ux-studio' ), $origin );

		// Pages the plugin really blocks (not only hides): "Block access to hidden pages" does the same in UX Studio.
		$lock = $this->lock_plan( $model, $clean, $bypass, $origin_of );
		if ( $lock['open'] ) {
			$labels   = MenuSimulator::protected_screens();
			$lossy[] = sprintf(
				/* translators: %s: screens. */
				__( 'The plugin blocks these screens for some roles. UX Studio never blocks UX Studio, Plugins, Users and Settings (nobody may be locked out), so they stay reachable by address: %s.', 'ux-studio' ),
				$this->list_text( array_map( static fn( string $s ): string => (string) ( $labels[ $s ] ?? $s ), $lock['open'] ) )
			);
		}
		if ( $lock['on'] ) {
			$m->set( self::MODULE, 'menu_lock_enabled', true, __( 'Block access to hidden pages', 'ux-studio' ), $origin );
			if ( $lock['stricter'] ) {
				$text = sprintf(
					/* translators: %s: menu items. */
					__( 'The plugin only hid these items without blocking their pages. "Block access to hidden pages" is one switch for the whole menu, so in UX Studio they are blocked for the roles that do not see them as well: %s.', 'ux-studio' ),
					$this->list_text( array_map( fn( string $s ): string => $this->title_of( $model, $s ), $lock['stricter'] ) )
				);
				$m->warn( $text );
				$lossy[] = $text;
			}
			$m->note( __( 'A page hidden from a role is also refused when its address is typed in ("Block access to hidden pages" is switched on). Users who can manage options, the dashboard, profile, UX Studio, Plugins, Users and Settings are never blocked. You will see the setting, with a note that it came from this migration, in UX Studio under Admin Customiser, Sidebar menu, "Block access to hidden pages", and you can switch it off there.', 'ux-studio' ) );
		} else {
			$m->warn( __( 'The Menu Organizer only hides menu items. A page hidden from a role can still be opened with its address, whereas a plugin that changes capabilities blocks it. If the old plugin protected pages, check that before you switch (the setting "Block access to hidden pages" in Admin Customiser blocks them).', 'ux-studio' ) );
		}

		foreach ( $lossy as $line ) {
			if ( ! in_array( $line, $m->lossy, true ) ) {
				$m->lossy( $line );
			}
		}
		$m->note( __( 'Admin Menu Editor, Adminimize and Adminify all write the same Menu Organizer configuration. If you migrate more than one of them, the one processed later overwrites the same parts of the earlier one.', 'ux-studio' ) );

		$this->menu_expect = array(
			'model'   => $model,
			'roles'   => $roles,
			'bypass'  => $bypass,
			'explain' => array_values( array_unique( $explain ) ),
			'config'  => array_merge(
				$input,
				array(
					'hidden_items' => $clean['hidden_items'],
					'item_roles'   => $clean['item_roles'],
					'assignments'  => $clean['assignments'],
					'sub_titles'   => $clean['sub_titles'],
					'sub_hidden'   => $clean['sub_hidden'],
					'sub_moves'    => $clean['sub_moves'],
					'item_caps'    => $clean['item_caps'],
					'item_users'   => $clean['item_users'],
				)
			),
			'dropped' => $safe['dropped'],
			'lock'    => $lock['pairs'],
			'subs'    => $this->original_subs( $model, $origin_of ),
			'caps'    => MenuSimulator::role_caps( $roles ),
		);
	}

	/**
	 * Sub-items of the model by their ORIGINAL parent (the parent WordPress registered them under), for the comparison.
	 *
	 * @param array<string, mixed> $model     Model.
	 * @param callable             $origin_of display `parent>sub` => original `parent>sub`.
	 * @return array<string, string[]>
	 */
	protected function original_subs( array $model, callable $origin_of ): array {
		$out = array();
		foreach ( (array) ( $model['subs'] ?? array() ) as $parent => $children ) {
			foreach ( (array) $children as $sub ) {
				$key = (string) call_user_func( $origin_of, $parent . '>' . $sub );
				$pos = strpos( $key, '>' );
				$top = false === $pos || 0 === $pos ? (string) $parent : substr( $key, 0, $pos );
				$out[ $top ][] = (string) $sub;
			}
		}
		return $out;
	}

	/**
	 * Does the plugin block (not only hide) pages that end up in the Menu Organizer config? Then the lock is switched on.
	 *
	 * @param array<string, mixed> $model     Model.
	 * @param array<string, mixed> $clean     Cleaned Menu Organizer config the mapping writes.
	 * @param string[]             $bypass    Roles that can manage options (never blocked).
	 * @param callable|null        $origin_of display `parent>sub` => original `parent>sub` (sub-items; null = same).
	 * @return array{on:bool,pairs:array<string,string[]>,stricter:string[],open:string[]} Pairs: slug (or `parent>sub`) => roles the plugin blocks; stricter: items the lock blocks although the plugin only hid them; open: protected screens the plugin blocks but UX Studio never does.
	 */
	protected function lock_plan( array $model, array $clean, array $bypass, ?callable $origin_of = null ): array {
		$origin_of = $origin_of ?? static fn( string $key ): string => $key;
		$open      = array();
		$ruled     = array_values( array_unique( array_merge( array_keys( (array) $clean['item_roles'] ), array_map( 'strval', (array) $clean['hidden_items'] ) ) ) );
		$blocked   = array();
		foreach ( (array) ( $model['denied'] ?? array() ) as $slug => $denied ) {
			$roles = ! empty( $model['block_all'] ) ? (array) $denied : (array) ( $model['blocked'][ $slug ] ?? array() );
			$roles = array_values( array_diff( array_map( 'strval', $roles ), $bypass ) );
			if ( ! $roles || ! in_array( (string) $slug, $ruled, true ) ) {
				continue;
			}
			if ( isset( MenuSimulator::protected_screens()[ (string) $slug ] ) ) {
				// UX Studio, Plugins, Users and Settings are never blocked, so nobody can lock themselves out.
				$open[] = (string) $slug;
				continue;
			}
			$blocked[ (string) $slug ] = $roles;
		}
		// Sub-items (B1): the same, keyed `original parent>sub`.
		$ruled_subs = array_map( 'strval', array_keys( (array) ( $clean['sub_hidden'] ?? array() ) ) );
		foreach ( (array) ( $model['denied_subs'] ?? array() ) as $dkey => $denied ) {
			$key   = (string) call_user_func( $origin_of, (string) $dkey );
			$roles = ! empty( $model['block_all'] ) ? (array) $denied : (array) ( $model['blocked_subs'][ $dkey ] ?? array() );
			$roles = array_values( array_diff( array_map( 'strval', $roles ), $bypass ) );
			if ( ! $roles || ! in_array( $key, $ruled_subs, true ) ) {
				continue;
			}
			// Everything under UX Studio, Plugins, Users and Settings is never blocked (the same rule as the lock itself).
			$parent_of = substr( $key, 0, (int) strpos( $key, '>' ) );
			if ( MenuSimulator::is_protected_key( $key ) || isset( MenuSimulator::protected_screens()[ $parent_of ] ) ) {
				$open[] = $parent_of;
				continue;
			}
			$blocked[ $key ] = $roles;
		}
		$stricter = array();
		if ( $blocked ) {
			$everybody = array_map( 'strval', (array) ( $model['hidden'] ?? array() ) );
			foreach ( $ruled as $slug ) {
				$denied = array_values( array_diff( array_map( 'strval', (array) ( $model['denied'][ $slug ] ?? array() ) ), $bypass ) );
				$gap    = array_diff( $denied, $blocked[ $slug ] ?? array() );
				if ( $gap || ( in_array( $slug, $everybody, true ) && ! isset( $blocked[ $slug ] ) ) ) {
					$stricter[] = $slug;
				}
			}
			foreach ( (array) ( $model['denied_subs'] ?? array() ) as $dkey => $denied ) {
				$key = (string) call_user_func( $origin_of, (string) $dkey );
				if ( ! in_array( $key, $ruled_subs, true ) ) {
					continue;
				}
				$denied = array_values( array_diff( array_map( 'strval', (array) $denied ), $bypass ) );
				if ( array_diff( $denied, $blocked[ $key ] ?? array() ) ) {
					$stricter[] = (string) $dkey;
				}
			}
		}
		return array(
			'on'       => (bool) $blocked,
			'pairs'    => $blocked,
			'stricter' => array_values( array_unique( $stricter ) ),
			'open'     => array_values( array_unique( $open ) ),
		);
	}

	/**
	 * Title of a slug for messages.
	 *
	 * @param array<string, mixed> $model Model.
	 * @param string               $slug  Slug.
	 */
	protected function title_of( array $model, string $slug ): string {
		$title = $this->plain( (string) ( $model['titles'][ $slug ] ?? '' ) );
		return '' !== $title ? $title : $slug;
	}

	/**
	 * Text without tags and entities (menu titles carry notification bubbles).
	 *
	 * @param string $text Text.
	 */
	protected function plain( string $text ): string {
		// Notification bubbles ("Comments 3", "Orders 651") and their screen-reader text are not part of the title.
		$text = (string) preg_replace( '#<span[^>]*class="[^"]*(?:plugin-count|screen-reader-text|count-\d+)[^"]*"[^>]*>.*?</span>#is', '', $text );
		$text = (string) preg_replace( '#<span[^>]*class="[^"]*(?:update-plugins|awaiting-mod|pending-count|wc-count|count)[^"]*"[^>]*>.*?</span>#is', '', $text );
		return trim( preg_replace( '/\s+/', ' ', html_entity_decode( strip_tags( $text ), ENT_QUOTES ) ) );
	}

	/**
	 * Short list for a sentence (first ones, then a count).
	 *
	 * @param string[] $items Items.
	 * @param int      $max   How many to name.
	 */
	protected function list_text( array $items, int $max = 8 ): string {
		$items = array_values( array_unique( array_map( 'strval', $items ) ) );
		if ( count( $items ) <= $max ) {
			return implode( ', ', $items );
		}
		return implode( ', ', array_slice( $items, 0, $max ) ) . sprintf(
			/* translators: %d: how many more. */
			__( ' and %d more', 'ux-studio' ),
			count( $items ) - $max
		);
	}

	/* ── Expected vs actual menu per role ── */

	/**
	 * What the foreign plugin shows to each role: role => sorted keys (`slug` and `parent>sub`).
	 *
	 * @param array<string, mixed>         $model Model.
	 * @param string[]                     $roles Role ids.
	 * @param array<string, string[]>|null $caps  Role => capabilities (an item with a required capability is not shown to a role without it).
	 * @return array<string, string[]>
	 */
	protected function source_matrix( array $model, array $roles, ?array $caps = null ): array {
		$out    = array();
		$hidden = array_flip( array_map( 'strval', (array) ( $model['hidden'] ?? array() ) ) );
		$hsubs  = array_flip( array_map( 'strval', (array) ( $model['hidden_subs'] ?? array() ) ) );
		foreach ( $roles as $role ) {
			$have = null === $caps ? null : array_map( 'strval', (array) ( $caps[ $role ] ?? array() ) );
			$lack = static function ( string $key ) use ( $model, $have ): bool {
				$cap = (string) ( $model['item_caps'][ $key ] ?? '' );
				return '' !== $cap && null !== $have && ! in_array( $cap, $have, true );
			};
			$seen = array();
			foreach ( (array) ( $model['tops'] ?? array() ) as $slug ) {
				$slug = (string) $slug;
				if ( isset( $hidden[ $slug ] ) || in_array( $role, (array) ( $model['denied'][ $slug ] ?? array() ), true ) || $lack( $slug ) ) {
					continue;
				}
				$seen[] = $slug;
				foreach ( (array) ( $model['subs'][ $slug ] ?? array() ) as $sub ) {
					$key = $slug . '>' . $sub;
					if ( isset( $hsubs[ $key ] ) || in_array( $role, (array) ( $model['denied_subs'][ $key ] ?? array() ), true ) || $lack( $key ) ) {
						continue;
					}
					$seen[] = $key;
				}
			}
			sort( $seen );
			$out[ $role ] = $seen;
		}
		return $out;
	}

	/**
	 * The config UX Studio will have: stored values with the prepared ones on top.
	 *
	 * @return array<string, mixed>
	 */
	protected function effective_menu_config(): array {
		$live = get_option( MenuOrganizer::CONFIG_OPTION, array() );
		return array_merge( MenuOrganizer::default_config(), is_array( $live ) ? $live : array(), Staging::pending_options( MenuOrganizer::CONFIG_OPTION ) );
	}

	/**
	 * Role by role: what the plugin shows versus what UX Studio will show.
	 *
	 * @param bool $planned Compare with the config the mapping WOULD write (the plan), not with what is stored now (verify).
	 * @return array{src:array<string,string[]>,dst:array<string,string[]>,diff:array<string,string[]>,unexplained:array<string,string[]>,universe:string[],ruled:string[]}
	 */
	protected function compare_menu( bool $planned = false ): array {
		$e = $this->menu_expect;
		if ( empty( $e['model'] ) ) {
			return array(
				'src'         => array(),
				'dst'         => array(),
				'diff'        => array(),
				'unexplained' => array(),
				'universe'    => array(),
				'ruled'       => array(),
			);
		}
		$model = (array) $e['model'];
		$roles = (array) $e['roles'];
		$caps  = isset( $e['caps'] ) ? (array) $e['caps'] : null;
		$src   = $this->source_matrix( $model, $roles, $caps );
		$dst   = MenuSimulator::target_matrix( $planned ? array_merge( MenuOrganizer::default_config(), (array) $e['config'] ) : $this->effective_menu_config(), array_map( 'strval', (array) $model['tops'] ), (array) ( $e['subs'] ?? $model['subs'] ?? array() ), $roles, (array) $e['bypass'], $caps );
		$diff  = array();
		$unex  = array();
		$exp   = array_flip( (array) $e['explain'] );
		// Only items the plugin has a rule for are compared: for the rest it has no opinion (another plugin may hide them).
		$ruled = array_merge(
			array_map( 'strval', (array) ( $model['hidden'] ?? array() ) ),
			array_map( 'strval', array_keys( (array) ( $model['denied'] ?? array() ) ) ),
			array_map( 'strval', (array) ( $model['hidden_subs'] ?? array() ) ),
			array_map( 'strval', array_keys( (array) ( $model['denied_subs'] ?? array() ) ) ),
			array_map( 'strval', array_keys( (array) ( $model['sub_moves'] ?? array() ) ) ),
			array_map( 'strval', array_keys( (array) ( $model['item_caps'] ?? array() ) ) )
		);
		foreach ( $roles as $role ) {
			$a = array_values( array_intersect( $src[ $role ] ?? array(), $ruled ) );
			$b = array_values( array_intersect( $dst[ $role ] ?? array(), $ruled ) );
			$d = array_values( array_merge( array_diff( $a, $b ), array_diff( $b, $a ) ) );
			sort( $d );
			$diff[ $role ] = $d;
			$unex[ $role ] = array_values(
				array_filter(
					$d,
					static function ( string $key ) use ( $exp ): bool {
						$top = false === strpos( $key, '>' ) ? $key : substr( $key, 0, (int) strpos( $key, '>' ) );
						// A difference on an item the migration deliberately left out (protected screens, rules for administrators) is explained.
						return ! isset( $exp[ $key ] ) && ! isset( $exp[ $top ] );
					}
				)
			);
		}
		return array(
			'src'         => $src,
			'dst'         => $dst,
			'diff'        => $diff,
			'unexplained' => $unex,
			'universe'    => array_map( 'strval', (array) $model['tops'] ),
			'ruled'       => $ruled,
		);
	}

	public function plan(): array {
		$plan = parent::plan();
		$this->mapping();
		$cmp = $this->compare_menu( true );
		if ( $cmp['src'] ) {
			$model = (array) $this->menu_expect['model'];
			$rows  = array();
			foreach ( $cmp['src'] as $role => $shown ) {
				$before = array_values( array_diff( $cmp['universe'], array_filter( $shown, static fn( string $k ): bool => false === strpos( $k, '>' ) ) ) );
				$after  = array_values( array_diff( $cmp['universe'], array_filter( $cmp['dst'][ $role ] ?? array(), static fn( string $k ): bool => false === strpos( $k, '>' ) ) ) );
				$rows[] = array(
					'role'   => $role,
					'label'  => $this->role_label( (string) $role ),
					'before' => array_map( fn( string $s ): string => $this->title_of( $model, $s ), $before ),
					'after'  => array_map( fn( string $s ): string => $this->title_of( $model, $s ), $after ),
				);
			}
			$plan['menu_table'] = $rows;
		}
		return $plan;
	}

	public function verify(): array {
		$checks = parent::verify();
		if ( empty( $this->menu_expect['model'] ) ) {
			return $checks;
		}
		$cmp   = $this->compare_menu();
		$model = (array) $this->menu_expect['model'];
		foreach ( $cmp['src'] as $role => $shown ) {
			$unexplained = $cmp['unexplained'][ $role ] ?? array();
			$known       = count( (array) ( $cmp['diff'][ $role ] ?? array() ) ) - count( $unexplained );
			$checks[]    = array(
				'id'     => 'menu-role:' . $role,
				'label'  => sprintf(
					/* translators: %s: role name. */
					__( 'Menu for the role "%s"', 'ux-studio' ),
					$this->role_label( (string) $role )
				),
				'ok'     => array() === $unexplained,
				'staged' => array() === $unexplained && $this->menu_is_staged(),
				'detail' => array() === $unexplained
					? ( array() === $cmp['ruled']
						? __( 'The plugin has no hidden items or role limits in its menu, so there is nothing to compare for this role.', 'ux-studio' )
						: sprintf(
							/* translators: 1: number of menu items with a rule of the plugin on which both menus agree, 2: number of known differences. */
							__( '%1$d menu items with a rule of the plugin agree, %2$d known differences (listed as not transferred).', 'ux-studio' ),
							count( array_intersect( $shown, $cmp['dst'][ $role ] ?? array(), $cmp['ruled'] ) ),
							max( 0, $known )
						) )
					: sprintf(
						/* translators: %s: menu items. */
						__( 'The old plugin and UX Studio disagree about: %s.', 'ux-studio' ),
						$this->list_text( array_map( fn( string $s ): string => $this->title_of( $model, $s ), $unexplained ) )
					),
			);
		}
		if ( ! empty( $this->menu_expect['lock'] ) ) {
			$matrix  = MenuSimulator::blocked_matrix( $this->effective_menu_config(), array_map( 'strval', (array) $model['tops'] ), (array) $this->menu_expect['roles'], (array) $this->menu_expect['bypass'], (array) ( $this->menu_expect['subs'] ?? array() ), (array) ( $this->menu_expect['caps'] ?? array() ) );
			$missing = array();
			foreach ( (array) $this->menu_expect['lock'] as $slug => $blocked_roles ) {
				foreach ( (array) $blocked_roles as $role ) {
					if ( ! in_array( (string) $slug, $matrix[ $role ] ?? array(), true ) ) {
						$missing[] = $this->title_of( $model, (string) $slug ) . ' (' . $this->role_label( (string) $role ) . ')';
					}
				}
			}
			$checks[] = array(
				'id'     => 'menu-lock',
				'label'  => __( 'Pages the old plugin blocked are blocked in UX Studio', 'ux-studio' ),
				'ok'     => array() === $missing,
				'staged' => array() === $missing && $this->menu_is_staged(),
				'detail' => array() === $missing
					? sprintf(
						/* translators: %d: number of menu items. */
						_n( '%d menu item blocked for the roles the plugin blocked it for.', '%d menu items blocked for the roles the plugin blocked them for.', count( (array) $this->menu_expect['lock'] ), 'ux-studio' ),
						count( (array) $this->menu_expect['lock'] )
					)
					: sprintf(
						/* translators: %s: menu items with roles. */
						__( 'Not blocked in UX Studio: %s.', 'ux-studio' ),
						$this->list_text( $missing )
					),
			);
		}
		return $checks;
	}

	/**
	 * Is the menu config still waiting in the staging area?
	 */
	private function menu_is_staged(): bool {
		return array() !== Staging::pending_options( MenuOrganizer::CONFIG_OPTION ) && Staging::module_enabled( self::MODULE );
	}

	public function smoke_checks(): array {
		$m = $this->mapping();
		if ( ! isset( $m->options[ MenuOrganizer::CONFIG_OPTION ] ) ) {
			return array();
		}
		return array(
			array(
				'id'    => 'admin-access',
				'label' => __( 'The administrator can still open Plugins, Users, Settings and UX Studio', 'ux-studio' ),
				'type'  => 'admin_access',
				'urls'  => MenuSimulator::protected_urls(),
			),
		);
	}

	public function extra_modules(): array {
		$out = array();
		foreach ( array_keys( $this->mapping()->settings ) as $module ) {
			if ( self::MODULE !== $module && in_array( $module, array( 'hide-admin-bar', 'dashboard-widgets' ), true ) ) {
				$out[] = (string) $module;
			}
		}
		return $out;
	}

	/**
	 * Hidden dashboard widgets: the module takes only widgets it has seen on the dashboard. Say which
	 * ones it does not know yet, so the admin knows to open the dashboard once and check again.
	 *
	 * @param Mapping  $m   Mapping.
	 * @param string[] $ids Widget ids the plugin hides.
	 */
	protected function note_unknown_widgets( Mapping $m, array $ids ): void {
		$known = null;
		foreach ( (array) SettingsWriter::schema( 'dashboard-widgets' ) as $field ) {
			if ( 'hidden_widgets' === ( $field['key'] ?? '' ) ) {
				$known = array_map( 'strval', array_keys( (array) ( $field['options'] ?? array() ) ) );
			}
		}
		if ( null === $known ) {
			return;
		}
		$unknown = array_values( array_diff( $ids, $known ) );
		if ( $unknown ) {
			$m->lossy(
				sprintf(
					/* translators: %s: widget ids. */
					__( 'The Dashboard Widgets module does not know these widgets yet, so they were not transferred. Open the dashboard once so the module learns them, then run the check again: %s.', 'ux-studio' ),
					implode( ', ', $unknown )
				)
			);
		}
	}

	/**
	 * Warn that a secondary module is switched on together with Admin Customiser.
	 *
	 * @param Mapping $m      Mapping.
	 * @param string  $module Module id.
	 * @param string  $name   Module name (translated).
	 */
	protected function note_extra_module( Mapping $m, string $module, string $name ): void {
		$m->note(
			sprintf(
				/* translators: %s: module name. */
				__( 'The module "%s" is switched on together with Admin Customiser, because some settings belong to it.', 'ux-studio' ),
				$name
			)
		);
	}

	/**
	 * Names of the options of a schema that really exist (typo guard for mapped keys).
	 *
	 * @param string $module Module id.
	 * @return string[]
	 */
	protected function schema_keys( string $module ): array {
		$schema = SettingsWriter::schema( $module );
		return $schema ? array_map( 'strval', array_column( $schema, 'key' ) ) : array();
	}
}
