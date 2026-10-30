<?php
/**
 * Source: WP Adminify (and its Pro add-on, which stores into the same options).
 *
 * @package UxStudio
 */

namespace UxStudio\Core\Migration\Sources;

use UxStudio\Core\Migration\Mapping;

defined( 'ABSPATH' ) || exit;

/**
 * Options `_wpadminify` (module settings) and `_wpadminify_menu_settings` (menu editor): a map keyed by
 * the top-level slug with `order` (string number), `name`, `link`, `external_link`, `icon`,
 * `separator` ("1" = a separator follows the item), `hidden_for` (role display names or user names)
 * and `submenu` (same fields by sub-item slug).
 *
 * Only the parts with a clear target move: the menu (order, groups, renames, icons, hidden for roles),
 * footer text, hidden notices, the quick search switch and the hidden dashboard widgets. The rest of the plugin
 * (its own admin theme, dark mode, fonts, favicons, security, performance, folders ...) is listed as not transferred.
 */
final class AdminifySource extends AbstractAdminSource {

	protected function option_names(): array {
		return array( '_wpadminify', '_wpadminify_menu_settings' );
	}

	/**
	 * Role display name => role id for this site.
	 *
	 * @return array<string, string>
	 */
	private function role_names_map(): array {
		$out = array();
		if ( function_exists( 'wp_roles' ) ) {
			foreach ( (array) wp_roles()->roles as $id => $role ) {
				$name = (string) ( $role['name'] ?? $id );
				if ( function_exists( 'translate_user_role' ) ) {
					$out[ (string) translate_user_role( $name ) ] = (string) $id;
				}
				$out[ $name ] = (string) $id;
			}
		}
		return $out;
	}

	/**
	 * Truthy Adminify switch.
	 *
	 * @param mixed $v Value.
	 */
	private function truthy( $v ): bool {
		return true === $v || 1 === $v || '1' === $v || 'on' === $v;
	}

	protected function map( bool $active ): Mapping {
		$m    = new Mapping();
		$o    = $this->opt( '_wpadminify' );
		$menu = $this->opt( '_wpadminify_menu_settings' );
		$o    = is_array( $o ) ? $o : array();
		$menu = is_array( $menu ) ? $menu : array();
		if ( ! $o && ! $menu ) {
			$m->note( __( 'The plugin has no stored settings, there is nothing to transfer.', 'ux-studio' ) );
			return $m;
		}
		$origin = '_wpadminify';
		$lossy  = array();

		/* ── Menu editor ── */
		if ( $menu ) {
			$names  = $this->role_names_map();
			$rows   = array();
			$n      = 0;
			foreach ( $menu as $slug => $item ) {
				if ( ! is_array( $item ) || '' === (string) $slug ) {
					continue;
				}
				$rows[] = array(
					'slug' => $this->clean_slug( (string) $slug ),
					'item' => $item,
					'ord'  => isset( $item['order'] ) && is_numeric( $item['order'] ) ? (float) $item['order'] : (float) $n,
					'n'    => $n++,
				);
			}
			usort( $rows, static fn( array $a, array $b ): int => array( $a['ord'], $a['n'] ) <=> array( $b['ord'], $b['n'] ) );

			$model = array(
				'tops'         => array(),
				'groups'       => array( array() ),
				'subs'         => array(),
				'titles'       => array(),
				'icons'        => array(),
				'hidden'       => array(),
				'denied'       => array(),
				'denied_subs'  => array(),
				'hidden_subs'  => array(),
				'promoted'     => array(),
				'custom_links' => array(),
				'lossy'        => array(),
			);
			$group = 0;
			$users = array();
			$links = array();
			$subs_lost = array();
			foreach ( $rows as $row ) {
				$item = $row['item'];
				$slug = $row['slug'];
				if ( false !== strpos( $slug, 'separator' ) && '' === trim( (string) ( $item['name'] ?? '' ) ) ) {
					if ( $model['groups'][ $group ] ) {
						$model['groups'][] = array();
						++$group;
					}
					continue;
				}
				$model['tops'][]             = $slug;
				$model['groups'][ $group ][] = $slug;
				if ( $this->truthy( $item['separator'] ?? '' ) ) {
					// "A separator follows this item": the next item starts a new group.
					$model['groups'][] = array();
					++$group;
				}
				$name = $this->plain( (string) ( $item['name'] ?? '' ) );
				if ( '' !== $name ) {
					$model['titles'][ $slug ] = $name;
				}
				$icon = trim( (string) ( $item['icon'] ?? '' ) );
				if ( '' !== $icon ) {
					$model['icons'][ $slug ] = trim( str_replace( 'dashicons ', '', $icon ) );
				}
				if ( '' !== trim( (string) ( $item['link'] ?? '' ) ) ) {
					$links[] = $name ? $name : $slug;
				}
				$denied = array();
				foreach ( (array) ( $item['hidden_for'] ?? array() ) as $who ) {
					$who = (string) $who;
					if ( isset( $names[ $who ] ) ) {
						$denied[] = $names[ $who ];
					} else {
						$users[] = $who;
					}
				}
				if ( $denied ) {
					$model['denied'][ $slug ] = array_values( array_unique( $denied ) );
				}
				foreach ( (array) ( $item['submenu'] ?? array() ) as $sub_slug => $sub ) {
					$sub_slug = $this->clean_slug( (string) $sub_slug );
					$sub      = (array) $sub;
					if ( 0 === strpos( $sub_slug, 'adminify-custom-submenu-' ) ) {
						$subs_lost[] = (string) ( $sub['name'] ?? $sub_slug );
						continue;
					}
					$model['subs'][ $slug ][] = $sub_slug;
					$skey                     = $slug . '>' . $sub_slug;
					if ( ! empty( $sub['hidden_for'] ) ) {
						$sub_names = array();
						foreach ( (array) $sub['hidden_for'] as $who ) {
							if ( isset( $names[ (string) $who ] ) ) {
								$sub_names[] = $names[ (string) $who ];
							}
						}
						if ( $sub_names ) {
							$model['denied_subs'][ $skey ] = $sub_names;
							$subs_lost[]                   = (string) ( $sub['name'] ?? $sub_slug );
						}
					}
					if ( '' !== trim( (string) ( $sub['name'] ?? '' ) ) || '' !== trim( (string) ( $sub['link'] ?? '' ) ) ) {
						$subs_lost[] = (string) ( $sub['name'] ?? $sub_slug );
					}
				}
			}
			$model['groups'] = array_values( array_filter( $model['groups'] ) );
			if ( ! $model['groups'] ) {
				$model['groups'] = array( array() );
			}
			if ( $links ) {
				$model['lossy'][] = sprintf(
					/* translators: %s: menu items. */
					__( 'Items with their own link (the item points somewhere else than WordPress made it): %s.', 'ux-studio' ),
					$this->list_text( $links )
				);
			}
			if ( $users ) {
				$model['lossy'][] = sprintf(
					/* translators: %s: names. */
					__( 'Menu items hidden for single users or capabilities (the Menu Organizer only knows roles): %s.', 'ux-studio' ),
					$this->list_text( array_unique( $users ) )
				);
			}
			if ( $subs_lost ) {
				$model['lossy'][] = sprintf(
					/* translators: %s: menu items. */
					__( 'Renamed, relinked, hidden or own sub-menu items (the Menu Organizer changes only top-level items): %s.', 'ux-studio' ),
					$this->list_text( array_unique( $subs_lost ) )
				);
			}
			$layout = is_array( $o['menu_layout_settings'] ?? null ) ? (string) ( $o['menu_layout_settings']['layout_type'] ?? '' ) : '';
			if ( 'horizontal' === $layout ) {
				$model['lossy'][] = __( 'The horizontal menu layout of Adminify (the Menu Organizer has its own horizontal layout built from categories, which is not the same).', 'ux-studio' );
			}
			if ( $model['tops'] ) {
				$this->map_menu( $m, $model, '_wpadminify_menu_settings', true );
			}
		}

		/* ── Settings ── */
		$wl = (array) ( $o['white_label']['wordpress'] ?? array() );
		$footer = $this->plain( (string) ( $wl['footer_text'] ?? '' ) );
		if ( '' !== $footer && false === stripos( (string) ( $wl['footer_text'] ?? '' ), 'wpadminify.com' ) ) {
			$m->set( self::MODULE, 'admin_footer_text', $footer, __( 'Admin footer text', 'ux-studio' ), $origin . ':white_label.wordpress.footer_text' );
		} elseif ( '' !== $footer ) {
			$lossy[] = __( 'The footer text is still the one Adminify ships ("Developed by WP Adminify"), so it was not copied. Set your own in Admin Customiser.', 'ux-studio' );
		}
		if ( $this->truthy( $wl['remove_howdy_msg'] ?? '' ) || ( '' !== (string) ( $wl['change_howdy_text'] ?? '' ) && 'Howdy' !== (string) $wl['change_howdy_text'] ) ) {
			$lossy[] = __( 'The changed or removed "Howdy" greeting in the admin bar.', 'ux-studio' );
		}
		if ( $this->truthy( $o['hide_notices'] ?? '' ) ) {
			$m->set( self::MODULE, 'hide_notices_enabled', true, __( 'Hide admin notices', 'ux-studio' ), $origin . ':hide_notices' );
			if ( $this->truthy( $o['hide_notices_non_admin'] ?? '' ) ) {
				$lossy[] = __( 'Hide notices only for users who are not administrators (UX Studio hides or tucks away notices for everybody).', 'ux-studio' );
			}
		}
		if ( array_key_exists( 'admin_bar_search', $o ) ) {
			$m->set( self::MODULE, 'quick_search_enabled', $this->truthy( $o['admin_bar_search'] ), __( 'Enable quick search', 'ux-studio' ), $origin . ':admin_bar_search' );
		}
		$rw = (array) ( $o['remove_widgets'] ?? array() );
		$dash = array_values( array_filter( array_map( 'strval', (array) ( $rw['dashboard_widgets_list'] ?? array() ) ) ) );
		if ( $dash ) {
			$m->set( 'dashboard-widgets', 'hidden_widgets', $dash, __( 'Hide specific dashboard widgets', 'ux-studio' ), $origin . ':remove_widgets.dashboard_widgets_list' );
			$this->note_extra_module( $m, 'dashboard-widgets', __( 'Dashboard Widgets', 'ux-studio' ) );
			$this->note_unknown_widgets( $m, $dash );
		}
		if ( ! empty( $rw['sidebar_widgets_list'] ) ) {
			$lossy[] = __( 'Removed sidebar widgets (widget types the plugin takes away from the Widgets screen).', 'ux-studio' );
		}

		/* ── What stays behind ── */
		$other = array();
		if ( $this->truthy( $o['admin_ui'] ?? '' ) ) {
			$other[] = __( 'its own admin interface (theme, colors, fonts, dark mode switch, favicons)', 'ux-studio' );
		}
		if ( ! empty( $o['screen_help_tab']['enable_for_screen'] ) ) {
			$other[] = __( 'custom help tabs', 'ux-studio' );
		}
		if ( $this->truthy( $o['custom_admin_columns']['enable'] ?? '' ) ) {
			$other[] = __( 'custom list columns (set them up again in Admin Columns)', 'ux-studio' );
		}
		if ( ! empty( $o['redirect_urls_fields'] ) || ! empty( $o['security_head'] ) || ! empty( $o['heartbeat_api'] ) || ! empty( $o['performance'] ) ) {
			$other[] = __( 'security, performance and redirect settings (UX Studio has its own modules for them, they are not read here)', 'ux-studio' );
		}
		if ( $other ) {
			$lossy[] = sprintf(
				/* translators: %s: list. */
				__( 'Not transferred from Adminify: %s.', 'ux-studio' ),
				implode( '; ', $other )
			);
		}
		if ( function_exists( 'get_plugins' ) && \UxStudio\Core\Migration\PluginInfo::find( 'adminify-pro' ) ) {
			$lossy[] = __( 'Adminify Pro modules (admin pages, redirects) are not transferred.', 'ux-studio' );
		}
		foreach ( array_unique( $lossy ) as $line ) {
			$m->lossy( $line );
		}
		if ( $m->is_empty() && ! $m->lossy ) {
			$m->note( __( 'The plugin keeps only its defaults, there is nothing to transfer.', 'ux-studio' ) );
		}
		return $m;
	}
}
