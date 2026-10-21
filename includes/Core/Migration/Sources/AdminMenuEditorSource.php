<?php
/**
 * Source: Admin Menu Editor (free and Pro).
 *
 * @package UxStudio
 */

namespace UxStudio\Core\Migration\Sources;

use UxStudio\Core\Migration\Mapping;

defined( 'ABSPATH' ) || exit;

/**
 * Option `ws_menu_editor` (Pro: `ws_menu_editor_pro`), key `custom_menu.tree`: an object keyed by the menu
 * file/slug, each item with `position`, `separator`, `template_id` (`<original parent>><file>`),
 * and only the fields the user changed (everything else `null`): `menu_title`, `icon_url`,
 * `hidden`, `hidden_from_actor` (`role:<id>` => true), `grant_access` (`role:<id>` or `user:<login>` => bool),
 * `extra_capability`, `access_level`, `custom`, `url`, `open_in`, `items` (sub-menu, same shape).
 *
 * Target: Admin Customiser Menu Organizer config (order, categories, renames, icons, hidden items,
 * per-role visibility, promoted sub-items, custom links) plus `menu_organizer_enabled`.
 */
final class AdminMenuEditorSource extends AbstractAdminSource {

	protected function option_names(): array {
		return array( 'ws_menu_editor', 'ws_menu_editor_pro' );
	}

	/**
	 * The saved config that holds a custom menu, or null.
	 *
	 * @return array<string, mixed>|null
	 */
	private function config(): ?array {
		foreach ( $this->option_names() as $name ) {
			$o = $this->opt( $name );
			if ( is_array( $o ) && ! empty( $o['custom_menu']['tree'] ) && is_array( $o['custom_menu']['tree'] ) ) {
				return $o;
			}
		}
		return null;
	}

	/**
	 * Roles named by AME actors (`role:editor`) and the rest.
	 *
	 * @param array<string, mixed> $actors Actor => value.
	 * @param bool                 $value  Which value to collect.
	 * @param string[]             $other  Receives user: / special: actors.
	 * @return string[] Role ids.
	 */
	private function actor_roles( array $actors, bool $value, array &$other ): array {
		$roles = array();
		foreach ( $actors as $actor => $v ) {
			if ( (bool) $v !== $value ) {
				continue;
			}
			$actor = (string) $actor;
			if ( 0 === strpos( $actor, 'role:' ) ) {
				$roles[] = substr( $actor, 5 );
			} else {
				$other[] = $actor;
			}
		}
		return $roles;
	}

	protected function map( bool $active ): Mapping {
		$m   = new Mapping();
		$cfg = $this->config();
		if ( null === $cfg ) {
			$m->note( __( 'The plugin has no saved custom menu, there is nothing to transfer.', 'ux-studio' ) );
			return $m;
		}
		$tree = (array) $cfg['custom_menu']['tree'];

		// Top-level items in the order the plugin shows them.
		$entries = array();
		$n       = 0;
		foreach ( $tree as $key => $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$entries[] = array(
				'key'  => (string) $key,
				'item' => $item,
				'pos'  => isset( $item['position'] ) && is_numeric( $item['position'] ) ? (float) $item['position'] : (float) $n,
				'n'    => $n,
			);
			++$n;
		}
		usort( $entries, static fn( array $a, array $b ): int => array( $a['pos'], $a['n'] ) <=> array( $b['pos'], $b['n'] ) );

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
		$lossy = array(
			'caps'       => array(),
			'users'      => array(),
			'grant'      => array(),
			'sub_title'  => array(),
			'sub_moved'  => array(),
			'sub_custom' => array(),
			'sub_hidden' => array(),
			'colors'     => array(),
			'iframe'     => array(),
			'always'     => array(),
			'css'        => array(),
		);
		$missing = 0;
		$group   = 0;

		foreach ( $entries as $entry ) {
			$item = $entry['item'];
			if ( ! empty( $item['separator'] ) ) {
				if ( $model['groups'][ $group ] ) {
					$model['groups'][] = array();
					++$group;
				}
				continue;
			}
			if ( ! empty( $item['missing'] ) ) {
				++$missing;
				continue;
			}
			$defaults = (array) ( $item['defaults'] ?? array() );
			$file     = $this->clean_slug( (string) ( $item['file'] ?? '' ) );
			if ( '' === $file ) {
				$file = $this->clean_slug( (string) ( $defaults['file'] ?? $entry['key'] ) );
			}
			$template = explode( '>', (string) ( $item['template_id'] ?? '' ), 2 );
			$parent   = 2 === count( $template ) ? $this->clean_slug( $template[0] ) : '';
			$title    = $this->plain( (string) ( $item['menu_title'] ?? '' ) );
			$label    = '' !== $title ? $title : $this->plain( (string) ( $defaults['menu_title'] ?? $file ) );
			if ( '' === $label ) {
				$label = $file;
			}

			// Own links (custom items with an address).
			if ( ! empty( $item['custom'] ) ) {
				$url = trim( (string) ( $item['url'] ?? $item['file'] ?? '' ) );
				if ( '' === $url || ! preg_match( '#^(https?://|/|[a-z0-9_\-]+\.php)#i', $url ) ) {
					$lossy['sub_custom'][] = $label;
					continue;
				}
				$open = (string) ( $item['open_in'] ?? 'same_window' );
				if ( in_array( $open, array( 'iframe', 'embedded_page' ), true ) ) {
					$lossy['iframe'][] = $label;
					continue;
				}
				$id = 'ame-' . preg_replace( '/[^a-z0-9]+/', '-', strtolower( (string) $entry['key'] ) );
				$model['custom_links'][] = array(
					'id'     => trim( $id, '-' ),
					'title'  => $label,
					'url'    => $url,
					'icon'   => (string) ( $item['icon_url'] ?? '' ),
					'target' => 'new_window' === $open ? '_blank' : '',
				);
				$model['groups'][ $group ][] = 'custom:' . trim( $id, '-' );
				continue;
			}

			$slug = $file;
			$model['tops'][]             = $slug;
			$model['groups'][ $group ][] = $slug;
			if ( '' !== $parent && $parent !== $slug ) {
				$model['promoted'][ $slug ] = array(
					'parent' => $parent,
					'title'  => '' !== $title ? $title : $this->plain( (string) ( $defaults['menu_title'] ?? $slug ) ),
					'icon'   => (string) ( $item['icon_url'] ?? $defaults['icon_url'] ?? '' ),
				);
			}
			if ( '' !== $title && $title !== $this->plain( (string) ( $defaults['menu_title'] ?? '' ) ) ) {
				$model['titles'][ $slug ] = $title;
			} elseif ( isset( $model['promoted'][ $slug ] ) ) {
				$model['titles'][ $slug ] = $model['promoted'][ $slug ]['title'];
			}
			if ( ! empty( $item['icon_url'] ) && (string) $item['icon_url'] !== (string) ( $defaults['icon_url'] ?? '' ) ) {
				$model['icons'][ $slug ] = (string) $item['icon_url'];
			}
			if ( ! empty( $item['colors'] ) ) {
				$lossy['colors'][] = $label;
			}
			if ( ! empty( $item['is_always_open'] ) ) {
				$lossy['always'][] = $label;
			}
			if ( ! empty( $item['css_class'] ) && (string) $item['css_class'] !== (string) ( $defaults['css_class'] ?? '' ) ) {
				$lossy['css'][] = $label;
			}
			$this->collect_access( $item, $slug, $label, $model, $lossy, false );

			foreach ( (array) ( $item['items'] ?? array() ) as $sub_key => $sub ) {
				if ( ! is_array( $sub ) ) {
					continue;
				}
				$sdef  = (array) ( $sub['defaults'] ?? array() );
				$sfile = $this->clean_slug( (string) ( $sub['file'] ?? '' ) );
				if ( '' === $sfile ) {
					$sfile = $this->clean_slug( (string) ( $sdef['file'] ?? $sub_key ) );
				}
				$stemplate = explode( '>', (string) ( $sub['template_id'] ?? '' ), 2 );
				$sparent   = 2 === count( $stemplate ) ? $this->clean_slug( $stemplate[0] ) : $slug;
				$slabel    = $this->plain( (string) ( $sub['menu_title'] ?? $sdef['menu_title'] ?? $sfile ) );
				if ( ! empty( $sub['missing'] ) ) {
					++$missing;
					continue;
				}
				if ( ! empty( $sub['custom'] ) ) {
					$lossy['sub_custom'][] = $slabel;
					continue;
				}
				if ( '' !== $sparent && $sparent !== $slug ) {
					$lossy['sub_moved'][] = $slabel;
				}
				$model['subs'][ $slug ][] = $sfile;
				$skey                     = $slug . '>' . $sfile;
				if ( ! empty( $sub['menu_title'] ) && $this->plain( (string) $sub['menu_title'] ) !== $this->plain( (string) ( $sdef['menu_title'] ?? '' ) ) ) {
					$lossy['sub_title'][] = $slabel;
				}
				$this->collect_access( $sub, $skey, $slabel, $model, $lossy, true );
			}
		}
		$model['groups'] = array_values( array_filter( $model['groups'] ) );
		if ( ! $model['groups'] ) {
			$model['groups'] = array( array() );
		}

		$this->lossy_lines( $m, $lossy, $missing );
		$this->map_menu( $m, $model, 'ws_menu_editor:custom_menu', true );
		return $m;
	}

	/**
	 * Visibility and access rules of one item into the model.
	 *
	 * @param array<string, mixed> $item  AME item.
	 * @param string               $key   Slug (top-level) or `parent>sub`.
	 * @param string               $label Display name.
	 * @param array<string, mixed> $model Model being built.
	 * @param array<string, mixed> $lossy Lossy collector.
	 * @param bool                 $sub   Sub-menu item.
	 */
	private function collect_access( array $item, string $key, string $label, array &$model, array &$lossy, bool $sub ): void {
		$other  = array();
		$denied = array();
		foreach ( $this->actor_roles( (array) ( $item['hidden_from_actor'] ?? array() ), true, $other ) as $role ) {
			$denied[] = $role;
		}
		foreach ( $this->actor_roles( (array) ( $item['grant_access'] ?? array() ), false, $other ) as $role ) {
			$denied[] = $role;
		}
		$granted_other = array();
		$granted       = $this->actor_roles( (array) ( $item['grant_access'] ?? array() ), true, $granted_other );
		if ( $granted ) {
			$lossy['grant'][] = $label;
		}
		foreach ( array_merge( $other, $granted_other ) as $actor ) {
			if ( 0 === strpos( $actor, 'user:' ) ) {
				$lossy['users'][] = $label . ' (' . substr( $actor, 5 ) . ')';
			}
		}
		$denied = array_values( array_unique( $denied ) );

		if ( ! empty( $item['hidden'] ) ) {
			if ( $sub ) {
				$model['hidden_subs'][] = $key;
				$lossy['sub_hidden'][]  = $label;
			} else {
				$model['hidden'][] = $key;
			}
		}
		if ( $denied ) {
			if ( $sub ) {
				$model['denied_subs'][ $key ] = $denied;
				$lossy['sub_hidden'][]        = $label;
			} else {
				$model['denied'][ $key ] = $denied;
			}
		}
		if ( ! empty( $item['extra_capability'] ) || ! empty( $item['access_level'] ) ) {
			$lossy['caps'][] = $label;
		}
	}

	/**
	 * The precise list of what AME does that UX Studio cannot.
	 *
	 * @param Mapping             $m       Mapping.
	 * @param array<string, string[]> $lossy Collected labels per reason.
	 * @param int                 $missing Items of plugins that are gone.
	 */
	private function lossy_lines( Mapping $m, array $lossy, int $missing ): void {
		$texts = array(
			'caps'       => __( 'A required capability set for the item (the access level is changed in the plugin, so the page stays blocked for users without it, whereas UX Studio does not change capabilities): %s.', 'ux-studio' ),
			'users'      => __( 'Access rules for one user instead of a role (the Menu Organizer only knows roles): %s.', 'ux-studio' ),
			'grant'      => __( 'The plugin grants a role access to an item that role would not have by itself (UX Studio never gives a role capabilities it lacks): %s.', 'ux-studio' ),
			'sub_title'  => __( 'Renamed sub-menu items (the Menu Organizer renames only top-level items): %s.', 'ux-studio' ),
			'sub_moved'  => __( 'Sub-menu items moved under another parent: %s.', 'ux-studio' ),
			'sub_custom' => __( 'Custom items without a usable link (HTML, JavaScript, empty address): %s.', 'ux-studio' ),
			'sub_hidden' => __( 'Hidden sub-menu items or sub-menu items hidden for a role (the Menu Organizer cannot hide a single sub-item, it appears when its parent appears): %s.', 'ux-studio' ),
			'colors'     => __( 'Individual colours of menu items: %s.', 'ux-studio' ),
			'iframe'     => __( 'Items that open a page inside the admin (iframe, embedded page): %s.', 'ux-studio' ),
			'always'     => __( 'Menus kept always open: %s.', 'ux-studio' ),
			'css'        => __( 'Own CSS classes of menu items: %s.', 'ux-studio' ),
		);
		foreach ( $texts as $key => $text ) {
			if ( ! empty( $lossy[ $key ] ) ) {
				$m->lossy( sprintf( $text, $this->list_text( $lossy[ $key ] ) ) );
			}
		}
		$m->lossy( __( 'Which role sees an item is decided in the plugin by "show for" / "hide for" rules together with the role capabilities; UX Studio shows an item to the roles that remain after the rules are applied, and always to users who can manage options.', 'ux-studio' ) );
		$m->lossy( __( 'Per-site menus of a multisite network, menu presets and the plugin\'s own tools (menu backup, import and export) are not transferred.', 'ux-studio' ) );
		if ( $missing > 0 ) {
			$m->lossy(
				sprintf(
					/* translators: %d: number of menu items. */
					_n( '%d menu item belongs to a plugin that is no longer installed and was skipped.', '%d menu items belong to plugins that are no longer installed and were skipped.', $missing, 'ux-studio' ),
					$missing
				)
			);
		}
	}
}
