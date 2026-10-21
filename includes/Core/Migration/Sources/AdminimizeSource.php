<?php
/**
 * Source: Adminimize.
 *
 * @package UxStudio
 */

namespace UxStudio\Core\Migration\Sources;

use UxStudio\Core\Migration\Mapping;

defined( 'ABSPATH' ) || exit;

/**
 * Option `mw_adminimize`, one big array. Per role `<role>`:
 *   mw_adminimize_disabled_menu_<role>_items            top-level menu slugs hidden for the role
 *   mw_adminimize_disabled_submenu_<role>_items         `<parent>__<index>` (index in the default sub-menu) or a slug
 *   mw_adminimize_disabled_admin_bar_<role>_items       admin bar node ids (admin; `_frontend_` = front end)
 *   mw_adminimize_disabled_global_option_<role>_items   CSS selectors: `.show-admin-bar`, `#screen-options, ...`, `.admin-notices` ...
 *   mw_adminimize_disabled_dashboard_option_<role>_items dashboard widget ids
 *   mw_adminimize_disabled_metaboxes_<type>_<role>_items meta boxes of an edit screen
 * and the snapshots `mw_adminimize_default_menu` / `mw_adminimize_default_submenu` of the menu it was set up against.
 *
 * Targets: Menu Organizer (hidden items, per-role items), Admin Customiser (hidden admin bar elements,
 * screen options, help tab, notices), Hide Admin Bar (per role, exact), Dashboard Widgets (hidden widgets).
 */
final class AdminimizeSource extends AbstractAdminSource {

	/** Admin bar node id => value of the module's `hide_admin_bar_elements`. */
	private const NODES = array(
		'wp-logo'     => 'wp_logo',
		'updates'     => 'updates',
		'customize'   => 'customize',
		'comments'    => 'comments',
		'new-content' => 'new_item',
	);

	protected function option_names(): array {
		return array( 'mw_adminimize' );
	}

	/**
	 * The option (array) or empty.
	 *
	 * @return array<string, mixed>
	 */
	private function data(): array {
		$o = $this->opt( 'mw_adminimize' );
		return is_array( $o ) ? $o : array();
	}

	/**
	 * Roles Adminimize keeps settings for.
	 *
	 * @param array<string, mixed> $o Option.
	 * @return string[]
	 */
	private function roles_of( array $o ): array {
		$roles = array();
		foreach ( array_keys( $o ) as $key ) {
			if ( 1 === preg_match( '/^mw_adminimize_disabled_menu_(.+)_items$/', (string) $key, $mm ) ) {
				$roles[] = $mm[1];
			}
		}
		return $roles;
	}

	/**
	 * Role => list for one family of options.
	 *
	 * @param array<string, mixed> $o      Option.
	 * @param string[]             $roles  Roles.
	 * @param string               $prefix Option key before `<role>_items`.
	 * @return array<string, string[]>
	 */
	private function per_role( array $o, array $roles, string $prefix ): array {
		$out = array();
		foreach ( $roles as $role ) {
			$list = $o[ $prefix . $role . '_items' ] ?? array();
			$list = is_array( $list ) ? array_values( array_filter( array_map( 'strval', $list ), static fn( string $v ): bool => '' !== $v ) ) : array();
			if ( $list ) {
				$out[ $role ] = $list;
			}
		}
		return $out;
	}

	protected function map( bool $active ): Mapping {
		$m = new Mapping();
		$o = $this->data();
		if ( ! $o ) {
			$m->note( __( 'The plugin has no stored settings, there is nothing to transfer.', 'ux-studio' ) );
			return $m;
		}
		$roles = $this->roles_of( $o );
		if ( ! $roles ) {
			$m->note( __( 'The plugin has no per-role settings, there is nothing to transfer.', 'ux-studio' ) );
			return $m;
		}
		$site_roles = $this->role_ids();

		/* ── Menu ── */
		$menu_titles = array();
		$tops        = array();
		foreach ( (array) ( $o['mw_adminimize_default_menu'] ?? array() ) as $row ) {
			$row = (array) $row;
			if ( ! empty( $row[2] ) && false === strpos( (string) ( $row[4] ?? '' ), 'wp-menu-separator' ) ) {
				$slug                 = $this->clean_slug( (string) $row[2] );
				$tops[]               = $slug;
				$menu_titles[ $slug ] = $this->plain( (string) ( $row[0] ?? $slug ) );
			}
		}
		$subs_default = array();
		foreach ( (array) ( $o['mw_adminimize_default_submenu'] ?? array() ) as $parent => $rows ) {
			foreach ( (array) $rows as $index => $row ) {
				$row = (array) $row;
				if ( ! empty( $row[2] ) ) {
					$subs_default[ $this->clean_slug( (string) $parent ) ][ (string) $index ] = array(
						'slug'  => $this->clean_slug( (string) $row[2] ),
						'title' => $this->plain( (string) ( $row[0] ?? $row[2] ) ),
					);
				}
			}
		}

		$denied      = array();
		foreach ( $this->per_role( $o, $roles, 'mw_adminimize_disabled_menu_' ) as $role => $slugs ) {
			foreach ( $slugs as $slug ) {
				$slug = $this->clean_slug( $slug );
				if ( ! in_array( $slug, $tops, true ) ) {
					$tops[] = $slug;
				}
				$denied[ $slug ][] = $role;
			}
		}
		$sub_lines   = array();
		$denied_subs = array();
		foreach ( $this->per_role( $o, $roles, 'mw_adminimize_disabled_submenu_' ) as $role => $entries ) {
			foreach ( $entries as $entry ) {
				$entry = html_entity_decode( $entry, ENT_QUOTES );
				if ( false !== strpos( $entry, '__' ) ) {
					list( $parent, $index ) = explode( '__', $entry, 2 );
					$parent                 = $this->clean_slug( $parent );
					$sub                    = $subs_default[ $parent ][ $index ] ?? null;
					$key                    = $parent . '>' . ( $sub ? $sub['slug'] : $index );
					$label                  = ( $menu_titles[ $parent ] ?? $parent ) . ' / ' . ( $sub ? $sub['title'] : $index );
				} else {
					$key   = '>' . $entry;
					$label = $entry;
				}
				$denied_subs[ $key ][] = $role;
				$sub_lines[ $label ]   = true;
			}
		}

		$model = array(
			'tops'        => $tops,
			'groups'      => null,
			'subs'        => array(),
			'titles'      => $menu_titles,
			'icons'       => array(),
			'hidden'      => array(),
			'denied'      => $denied,
			'denied_subs' => $denied_subs,
			'lossy'       => array(),
		);
		foreach ( $subs_default as $parent => $rows ) {
			foreach ( $rows as $row ) {
				$model['subs'][ $parent ][] = $row['slug'];
			}
		}
		if ( $sub_lines ) {
			$model['lossy'][] = sprintf(
				/* translators: %s: menu items. */
				__( 'Sub-menu items hidden for a role (the Menu Organizer cannot hide a single sub-item, it appears when its parent appears): %s.', 'ux-studio' ),
				$this->list_text( array_keys( $sub_lines ), 10 )
			);
		}
		if ( $denied || $denied_subs ) {
			$this->map_menu( $m, $model, 'mw_adminimize:menu', false );
			if ( ! empty( $o['mw_adminimize_prevent_page_access'] ) ) {
				$m->lossy( __( 'The option "Prevent page access" blocks the hidden pages even when their address is typed in. UX Studio only hides the menu items, so those pages stay reachable by address.', 'ux-studio' ) );
			}
			if ( ! empty( $o['mw_adminimize_multiple_roles'] ) ) {
				$m->lossy( __( 'The option for users with several roles (an item is hidden only when every role hides it) is not transferred: UX Studio shows an item when any role of the user may see it.', 'ux-studio' ) );
			}
		}

		/* ── Global options: admin bar for roles, screen options, help, notices ── */
		$global = $this->per_role( $o, $roles, 'mw_adminimize_disabled_global_option_' );
		$by     = array();
		foreach ( $global as $role => $selectors ) {
			foreach ( $selectors as $selector ) {
				$by[ $selector ][] = $role;
			}
		}
		$known = array(
			'.show-admin-bar'                         => 'admin_bar',
			'#screen-options, #screen-options-link-wrap' => 'screen_options',
			'#contextual-help-link-wrap'              => 'help',
			'.admin-notices'                          => 'notices',
		);
		$unmapped = array();
		foreach ( $by as $selector => $sel_roles ) {
			$kind = $known[ $selector ] ?? '';
			if ( 'admin_bar' === $kind ) {
				$m->set( 'hide-admin-bar', 'roles', array_values( array_intersect( $sel_roles, $site_roles ? $site_roles : $sel_roles ) ), __( 'Hide the admin bar for roles', 'ux-studio' ), 'mw_adminimize_disabled_global_option_*:.show-admin-bar' );
				$this->note_extra_module( $m, 'hide-admin-bar', __( 'Hide Admin Bar', 'ux-studio' ) );
			} elseif ( 'screen_options' === $kind || 'help' === $kind || 'notices' === $kind ) {
				if ( $this->covers_admin( $sel_roles ) ) {
					$key = array(
						'screen_options' => array( 'hide_screen_options', __( 'Hide "Screen Options" tab', 'ux-studio' ) ),
						'help'           => array( 'hide_help_tab', __( 'Hide "Help" tab', 'ux-studio' ) ),
						'notices'        => array( 'hide_notices_enabled', __( 'Hide admin notices', 'ux-studio' ) ),
					)[ $kind ];
					$m->set( self::MODULE, $key[0], true, $key[1], 'mw_adminimize_disabled_global_option_*:' . $selector );
					if ( 'notices' === $kind ) {
						$m->set( self::MODULE, 'hide_notices_relocate', false, __( 'Relocate instead of hiding', 'ux-studio' ), 'mw_adminimize_disabled_global_option_*:' . $selector );
					}
					$rest = array_values( array_diff( $site_roles, $sel_roles ) );
					if ( $rest ) {
						$m->warn(
							sprintf(
								/* translators: 1: setting, 2: roles that lose the feature. */
								__( '"%1$s" is switched on for everybody in UX Studio, the plugin applied it only to some roles. %2$s lose it as well.', 'ux-studio' ),
								$key[1],
								$this->role_names( $rest )
							)
						);
					}
				} else {
					$unmapped[] = $selector . ' (' . $this->role_names( $sel_roles ) . ')';
				}
			} else {
				$unmapped[] = $selector;
			}
		}
		if ( $unmapped ) {
			$m->lossy(
				sprintf(
					/* translators: %s: list of items. */
					__( 'Items hidden in the screens of the admin that UX Studio has no setting for (or that the plugin hid only for roles other than administrators): %s.', 'ux-studio' ),
					$this->list_text( $unmapped, 10 )
				)
			);
		}

		/* ── Admin bar nodes (admin and front end) ── */
		$nodes_admin = array();
		foreach ( $this->per_role( $o, $roles, 'mw_adminimize_disabled_admin_bar_' ) as $role => $ids ) {
			foreach ( $ids as $id ) {
				$nodes_admin[ $id ][] = $role;
			}
		}
		$nodes_front = array();
		foreach ( $this->per_role( $o, $roles, 'mw_adminimize_disabled_admin_bar_frontend_' ) as $role => $ids ) {
			foreach ( $ids as $id ) {
				$nodes_front[ $id ][] = $role;
			}
		}
		$hide   = array();
		$lost   = array();
		foreach ( $nodes_admin as $id => $node_roles ) {
			if ( isset( self::NODES[ $id ] ) && $this->covers_admin( $node_roles ) ) {
				$hide[] = self::NODES[ $id ];
				if ( array_diff( $site_roles, $node_roles ) ) {
					$lost[] = $id;
				}
			} else {
				$lost[] = $id . ' (' . $this->role_names( $node_roles ) . ')';
			}
		}
		foreach ( array_diff( array_keys( $nodes_front ), array_keys( $nodes_admin ) ) as $id ) {
			if ( isset( self::NODES[ $id ] ) && $this->covers_admin( $nodes_front[ $id ] ) ) {
				$hide[] = self::NODES[ $id ];
			}
			$lost[] = $id . ' (' . __( 'front end', 'ux-studio' ) . ')';
		}
		if ( $hide ) {
			$m->set( self::MODULE, 'hide_admin_bar_elements', array_values( array_unique( $hide ) ), __( 'Hide admin bar elements', 'ux-studio' ), 'mw_adminimize_disabled_admin_bar_*' );
		}
		if ( $lost ) {
			$m->lossy(
				sprintf(
					/* translators: %s: admin bar items. */
					__( 'Admin bar items UX Studio cannot hide, or can hide only for everybody and not for the roles named (the module hides: WordPress logo, updates, customize, comments, new): %s.', 'ux-studio' ),
					$this->list_text( array_unique( $lost ), 12 )
				)
			);
		}

		/* ── Dashboard widgets ── */
		$widgets = array();
		foreach ( $this->per_role( $o, $roles, 'mw_adminimize_disabled_dashboard_option_' ) as $role => $ids ) {
			foreach ( $ids as $id ) {
				$widgets[ $id ][] = $role;
			}
		}
		$hide_widgets = array();
		$skipped      = array();
		$lost_w       = array();
		foreach ( $widgets as $id => $w_roles ) {
			if ( $this->covers_admin( $w_roles ) ) {
				$hide_widgets[] = $id;
				if ( array_diff( $site_roles, $w_roles ) ) {
					$skipped[] = $id;
				}
			} else {
				$lost_w[] = $id . ' (' . $this->role_names( $w_roles ) . ')';
			}
		}
		if ( $hide_widgets ) {
			$m->set( 'dashboard-widgets', 'hidden_widgets', $hide_widgets, __( 'Hide specific dashboard widgets', 'ux-studio' ), 'mw_adminimize_disabled_dashboard_option_*' );
			$this->note_extra_module( $m, 'dashboard-widgets', __( 'Dashboard Widgets', 'ux-studio' ) );
		}
		if ( $skipped ) {
			$m->lossy(
				sprintf(
					/* translators: %s: widgets. */
					__( 'The module hides dashboard widgets for every role, the plugin did it only for some roles. Roles that kept these widgets lose them: %s.', 'ux-studio' ),
					$this->list_text( $skipped, 10 )
				)
			);
		}
		if ( $lost_w ) {
			$m->lossy(
				sprintf(
					/* translators: %s: widgets. */
					__( 'Dashboard widgets hidden only for roles other than administrators (the module hides widgets for every role at once): %s.', 'ux-studio' ),
					$this->list_text( $lost_w, 10 )
				)
			);
		}

		/* ── Everything else Adminimize does ── */
		$meta = 0;
		foreach ( $o as $key => $value ) {
			if ( 0 === strpos( (string) $key, 'mw_adminimize_disabled_metaboxes_' ) && is_array( $value ) && $value ) {
				++$meta;
			}
		}
		if ( $meta ) {
			$m->lossy( __( 'Hidden meta boxes and fields on the edit screens of posts, pages and other types (UX Studio has no setting for it).', 'ux-studio' ) );
		}
		foreach ( array( 'mw_adminimize_disabled_link_option_', 'mw_adminimize_disabled_nav_menu_option_', 'mw_adminimize_disabled_widget_option_' ) as $prefix ) {
			if ( $this->per_role( $o, $roles, $prefix ) ) {
				$m->lossy( __( 'Hidden options on the screens for links, menus (appearance) and widgets.', 'ux-studio' ) );
				break;
			}
		}
		$tweaks = array();
		foreach ( array(
			'_mw_adminimize_footer'    => __( 'hidden admin footer', 'ux-studio' ),
			'_mw_adminimize_header'    => __( 'hidden admin header', 'ux-studio' ),
			'_mw_adminimize_user_info' => __( 'changed user info in the admin bar', 'ux-studio' ),
			'_mw_adminimize_tb_window' => __( 'thickbox window size', 'ux-studio' ),
			'_mw_adminimize_cat_full'  => __( 'full category list', 'ux-studio' ),
			'_mw_adminimize_db_redirect' => __( 'dashboard redirect', 'ux-studio' ),
			'_mw_adminimize_ui_redirect' => __( 'redirect to the user profile', 'ux-studio' ),
			'_mw_adminimize_advice'    => __( 'advice text in the footer', 'ux-studio' ),
		) as $key => $name ) {
			if ( ! empty( $o[ $key ] ) ) {
				$tweaks[] = $name;
			}
		}
		if ( $tweaks ) {
			$m->lossy( sprintf( /* translators: %s: list of tweaks. */ __( 'Other tweaks of the plugin without an equivalent: %s.', 'ux-studio' ), implode( ', ', $tweaks ) ) );
		}
		foreach ( $o as $key => $value ) {
			if ( 0 === strpos( (string) $key, '_mw_adminimize_own_' ) && is_string( $value ) && '' !== trim( $value ) ) {
				$m->lossy( __( 'Own CSS selectors the plugin hides (the "own options" fields).', 'ux-studio' ) );
				break;
			}
		}
		return $m;
	}
}
