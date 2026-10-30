<?php
/**
 * Source: White Label CMS.
 *
 * @package UxStudio
 */

namespace UxStudio\Core\Migration\Sources;

use UxStudio\Core\Migration\Mapping;
use UxStudio\Modules\DashboardWidgets\RoleWidgets;

defined( 'ABSPATH' ) || exit;

/**
 * Option `wlcms_options` (one array). Login: `login_logo` (URL), `background_color`, `form_*_color`,
 * `back_to_register_link_color` ... ; admin: `admin_bar_logo` (URL), `hide_wordpress_logo_and_links`,
 * `footer_text`, `footer_html`, `hide_help_box`, `hide_screen_options`, `hide_nag_messages`,
 * `hide_admin_bar_all`; dashboard: `dashboard_widgets` (ids hidden for the roles in
 * `dashboard_widgets_visibility_roles`), `hide_all_dashboard_panels`, `welcome_panel[]`.
 *
 * Target: Admin Customiser (login screen incl. background image, hover colours and own CSS, admin bar, footer,
 * screens, own CSS of the admin), plus Hide Admin Bar and Dashboard Widgets (widgets hidden per role, the custom
 * welcome panel) for the settings that belong to those modules.
 */
final class WhiteLabelCmsSource extends AbstractAdminSource {

	protected function option_names(): array {
		return array( 'wlcms_options' );
	}

	/**
	 * Option value or empty array.
	 *
	 * @return array<string, mixed>
	 */
	private function data(): array {
		$o = $this->opt( 'wlcms_options' );
		return is_array( $o ) ? $o : array();
	}

	/**
	 * A setting as plain text.
	 *
	 * @param array<string, mixed> $o   Options.
	 * @param string               $key Key.
	 */
	private function str( array $o, string $key ): string {
		$v = $o[ $key ] ?? '';
		return is_scalar( $v ) ? trim( (string) $v ) : '';
	}

	/**
	 * A WLCMS toggle (`"1"`, `1`, true).
	 *
	 * @param array<string, mixed> $o   Options.
	 * @param string               $key Key.
	 */
	private function on( array $o, string $key ): bool {
		$v = $o[ $key ] ?? '';
		return true === $v || 1 === $v || '1' === $v;
	}

	protected function map( bool $active ): Mapping {
		$m = new Mapping();
		$o = $this->data();
		if ( ! $o ) {
			$m->note( __( 'The plugin has no stored settings, there is nothing to transfer.', 'ux-studio' ) );
			return $m;
		}
		$origin = 'wlcms_options';
		$lossy  = array();

		/* ── Login screen ── */
		$login = 0;
		$logo  = $this->str( $o, 'login_logo' );
		if ( '' !== $logo ) {
			$id = $this->attachment_id( $logo );
			if ( $id > 0 ) {
				$m->set( self::MODULE, 'login_logo', $id, __( 'Login screen logo', 'ux-studio' ), $origin . ':login_logo' );
				++$login;
			} else {
				$lossy[] = sprintf(
					/* translators: %s: file address. */
					__( 'The login logo %s is not in the media library of this site, so no attachment could be linked. Upload it and choose it in the Admin Customiser settings.', 'ux-studio' ),
					$logo
				);
			}
		}
		$colors = array(
			'background_color'            => array( 'login_background_color', __( 'Login screen background color', 'ux-studio' ) ),
			'form_background_color'       => array( 'login_form_bg_color', __( 'Login form background color', 'ux-studio' ) ),
			'form_label_color'            => array( 'login_form_text_color', __( 'Login form text color', 'ux-studio' ) ),
			'form_button_color'           => array( 'login_button_bg_color', __( 'Login button background color', 'ux-studio' ) ),
			'form_button_text_color'      => array( 'login_button_text_color', __( 'Login button text color', 'ux-studio' ) ),
			'back_to_register_link_color' => array( 'login_text_color', __( 'Login screen text color', 'ux-studio' ) ),
			'form_button_hover_color'     => array( 'login_button_hover_bg_color', __( 'Login button background color on hover', 'ux-studio' ) ),
			'form_button_text_hover_color' => array( 'login_button_hover_text_color', __( 'Login button text color on hover', 'ux-studio' ) ),
			'back_to_register_link_hover_color' => array( 'login_link_hover_color', __( 'Login screen link color on hover', 'ux-studio' ) ),
		);
		foreach ( $colors as $key => $target ) {
			$raw = $this->str( $o, $key );
			if ( '' === $raw ) {
				continue;
			}
			$hex = $this->hex( $raw );
			if ( '' === $hex ) {
				$lossy[] = sprintf(
					/* translators: 1: setting, 2: value. */
					__( 'The color "%1$s" (%2$s) is not a plain hex color and was not transferred.', 'ux-studio' ),
					$key,
					$raw
				);
				continue;
			}
			$m->set( self::MODULE, $target[0], $hex, $target[1], $origin . ':' . $key );
			++$login;
		}
		// Background image: size and repeat map to the module's "fit" choice.
		$bg = $this->str( $o, 'background_image' );
		if ( '' !== $bg ) {
			$id = $this->attachment_id( $bg );
			if ( $id > 0 ) {
				$repeat = $this->str( $o, 'background_repeat' );
				$style  = $this->on( $o, 'full_screen_background_image' ) ? 'cover' : ( in_array( $repeat, array( 'repeat', 'repeat-x', 'repeat-y' ), true ) ? 'repeat' : 'center' );
				$m->set( self::MODULE, 'login_background_image', $id, __( 'Login screen background image', 'ux-studio' ), $origin . ':background_image' );
				$m->set( self::MODULE, 'login_background_style', $style, __( 'Login screen background image: fit', 'ux-studio' ), $origin . ':background_repeat' );
				++$login;
				$position = $this->str( $o, 'background_positions' );
				if ( 'cover' !== $style && '' !== $position && 'center center' !== $position ) {
					$lossy[] = sprintf(
						/* translators: %s: CSS position. */
						__( 'The position of the login background image (%s): UX Studio centres it.', 'ux-studio' ),
						$position
					);
				}
				if ( in_array( $repeat, array( 'repeat-x', 'repeat-y' ), true ) ) {
					$lossy[] = __( 'The login background repeats in one direction only in the plugin; UX Studio repeats it both ways.', 'ux-studio' );
				}
			} else {
				$lossy[] = sprintf(
					/* translators: %s: file address. */
					__( 'The login background image %s is not in the media library of this site, so no attachment could be linked. Upload it and choose it in the Admin Customiser settings.', 'ux-studio' ),
					$bg
				);
			}
		}
		$login_css = $this->str( $o, 'login_custom_css' );
		if ( '' !== $login_css ) {
			$m->set( self::MODULE, 'login_custom_css', $login_css, __( 'Custom CSS of the login page', 'ux-studio' ), $origin . ':login_custom_css' );
			$lossy[] = __( 'The own CSS of the login screen is copied as it is; UX Studio removes HTML tags from it and cuts it at 50 KB, so check the login screen after the switch.', 'ux-studio' );
		}
		if ( $login ) {
			$m->set( self::MODULE, 'login_customization_enabled', true, __( 'Turn on login screen customization', 'ux-studio' ), $origin );
		}
		$login_lost = array();
		foreach ( array(
			'retina_login_logo'            => __( 'retina logo', 'ux-studio' ),
			'logo_width'                   => __( 'logo size', 'ux-studio' ),
			'logo_height'                  => __( 'logo size', 'ux-studio' ),
			'logo_bottom_margin'           => __( 'space under the logo', 'ux-studio' ),
			'privacy_policy_link_color'    => __( 'privacy link colors', 'ux-studio' ),
			'privacy_policy_link_hover_color' => __( 'privacy link colors', 'ux-studio' ),
			'hide_register_lost_password'  => __( 'hidden "Lost your password" links', 'ux-studio' ),
			'hide_back_to_link'            => __( 'hidden "Back to the site" link', 'ux-studio' ),
			'login_custom_js'              => __( 'own JavaScript of the login screen', 'ux-studio' ),
		) as $key => $name ) {
			if ( ! empty( $o[ $key ] ) && '0' !== (string) $o[ $key ] ) {
				$login_lost[ $name ] = true;
			}
		}
		if ( $login_lost ) {
			$lossy[] = sprintf(
				/* translators: %s: list of login screen settings. */
				__( 'Login screen settings without a target in the module: %s.', 'ux-studio' ),
				implode( ', ', array_keys( $login_lost ) )
			);
		}

		/* ── Admin bar, footer, screens ── */
		if ( $this->on( $o, 'hide_wordpress_logo_and_links' ) ) {
			$m->set( self::MODULE, 'hide_admin_bar_elements', array( 'wp_logo' ), __( 'Hide admin bar elements', 'ux-studio' ), $origin . ':hide_wordpress_logo_and_links' );
			$lossy[] = __( 'The option "Hide WordPress logo and links" also removes the WordPress links from the footer and changes the exit icon in the block editor; UX Studio only hides the WordPress logo in the admin bar.', 'ux-studio' );
		}
		$bar_logo = $this->str( $o, 'admin_bar_logo' );
		if ( '' !== $bar_logo ) {
			$id = $this->attachment_id( $bar_logo );
			if ( $id > 0 ) {
				$m->set( self::MODULE, 'admin_bar_logo', $id, __( 'Admin bar logo', 'ux-studio' ), $origin . ':admin_bar_logo' );
				$lossy[] = __( 'The admin bar logo of the plugin is an extra item with its own link and size; in UX Studio it replaces the WordPress logo icon (the link and the alt text are not transferred).', 'ux-studio' );
			} else {
				$lossy[] = sprintf(
					/* translators: %s: file address. */
					__( 'The admin bar logo %s is not in the media library of this site, so no attachment could be linked.', 'ux-studio' ),
					$bar_logo
				);
			}
		}
		$footer = $this->str( $o, 'footer_text' );
		$html   = $this->str( $o, 'footer_html' );
		if ( '' === $footer && '' !== $html ) {
			$footer = trim( html_entity_decode( strip_tags( $html ), ENT_QUOTES ) );
			$lossy[] = __( 'The footer is HTML in the plugin; only its text is transferred, links and formatting are lost.', 'ux-studio' );
		}
		if ( '' !== $footer ) {
			$m->set( self::MODULE, 'admin_footer_text', $footer, __( 'Admin footer text', 'ux-studio' ), $origin . ':footer_text' );
			if ( '' !== $this->str( $o, 'footer_url' ) || '' !== $this->str( $o, 'footer_image' ) ) {
				$lossy[] = __( 'The footer link and the footer image are not transferred.', 'ux-studio' );
			}
		} elseif ( $this->on( $o, 'hide_wordpress_logo_and_links' ) ) {
			$lossy[] = __( 'The plugin empties the admin footer; UX Studio keeps the default footer text when no own text is set.', 'ux-studio' );
		}
		if ( $this->on( $o, 'hide_help_box' ) ) {
			$m->set( self::MODULE, 'hide_help_tab', true, __( 'Hide "Help" tab', 'ux-studio' ), $origin . ':hide_help_box' );
		}
		if ( $this->on( $o, 'hide_screen_options' ) ) {
			$m->set( self::MODULE, 'hide_screen_options', true, __( 'Hide "Screen Options" tab', 'ux-studio' ), $origin . ':hide_screen_options' );
		}
		if ( $this->on( $o, 'hide_nag_messages' ) ) {
			$m->set( self::MODULE, 'hide_notices_enabled', true, __( 'Hide admin notices', 'ux-studio' ), $origin . ':hide_nag_messages' );
			$m->set( self::MODULE, 'hide_notices_relocate', true, __( 'Relocate instead of hiding', 'ux-studio' ), $origin . ':hide_nag_messages' );
			$m->set( self::MODULE, 'global__update_notifications', array( 'core' ), __( 'Suppress update notifications', 'ux-studio' ), $origin . ':hide_nag_messages' );
			$m->warn( __( 'The plugin only removed the WordPress update notice. UX Studio\'s "hide admin notices" moves every notice of the admin into a tray (nothing is deleted).', 'ux-studio' ) );
		}
		if ( $this->on( $o, 'hide_admin_bar_all' ) ) {
			$roles = array_values( array_diff( $this->role_ids(), array( 'administrator' ) ) );
			$m->set( 'hide-admin-bar', 'roles', $roles, __( 'Hide the admin bar for roles', 'ux-studio' ), $origin . ':hide_admin_bar_all' );
			$this->note_extra_module( $m, 'hide-admin-bar', __( 'Hide Admin Bar', 'ux-studio' ) );
			$lossy[] = __( 'The plugin hides the admin bar for everybody except its own "administrators" (chosen users); UX Studio hides it for all roles except the administrator role.', 'ux-studio' );
		}

		/* ── Dashboard ── */
		$widgets    = array_values( array_filter( array_map( 'strval', (array) ( $o['dashboard_widgets'] ?? array() ) ) ) );
		$all        = $this->on( $o, 'hide_all_dashboard_panels' );
		$by_roles   = $this->on( $o, 'dashboard_role_stat' );
		$d_roles    = $by_roles ? array_map( 'strval', (array) ( $o['dashboard_widgets_visibility_roles'] ?? array() ) ) : array();
		$site_roles = $this->role_ids();
		if ( $all ) {
			if ( ! $by_roles || $this->covers_admin( $d_roles ) ) {
				$m->set( 'dashboard-widgets', 'disable_all_widgets', true, __( 'Hide all dashboard widgets', 'ux-studio' ), $origin . ':hide_all_dashboard_panels' );
				$this->note_extra_module( $m, 'dashboard-widgets', __( 'Dashboard Widgets', 'ux-studio' ) );
				$rest = $d_roles ? array_values( array_diff( $site_roles, $d_roles ) ) : array();
				if ( $rest ) {
					$lossy[] = sprintf(
						/* translators: %s: role names. */
						__( 'The module hides all dashboard widgets for every role at once; the plugin hid them only for some roles. These roles lose the widgets too: %s.', 'ux-studio' ),
						$this->role_names( $rest )
					);
				}
			} else {
				$lossy[] = sprintf(
					/* translators: %s: role names. */
					__( 'The plugin hides all dashboard widgets only for these roles, not for administrators (the module hides them for every role at once): %s.', 'ux-studio' ),
					$this->role_names( $d_roles )
				);
			}
		}
		if ( $widgets ) {
			if ( ! $by_roles ) {
				// No role limit in the plugin: hidden for everybody.
				if ( $this->known_widgets( $widgets ) ) {
					$m->set( 'dashboard-widgets', 'hidden_widgets', $this->known_widgets( $widgets ), __( 'Hide specific dashboard widgets', 'ux-studio' ), $origin . ':dashboard_widgets' );
				}
			} else {
				foreach ( $d_roles as $role ) {
					if ( $site_roles && ! in_array( $role, $site_roles, true ) ) {
						continue;
					}
					if ( ! $this->known_widgets( $widgets ) ) {
						continue;
					}
					$m->set(
						'dashboard-widgets',
						RoleWidgets::key( $role ),
						$this->known_widgets( $widgets ),
						sprintf(
							/* translators: %s: role name. */
							__( 'Hide dashboard widgets for the role: %s', 'ux-studio' ),
							$this->role_label( $role )
						),
						$origin . ':dashboard_widgets'
					);
				}
				$missing_roles = $site_roles ? array_values( array_diff( $d_roles, $site_roles ) ) : array();
				if ( $missing_roles ) {
					$lossy[] = sprintf(
						/* translators: %s: role names. */
						__( 'Dashboard widgets are hidden in the plugin for roles that do not exist on this site: %s.', 'ux-studio' ),
						implode( ', ', $missing_roles )
					);
				}
			}
			$this->note_extra_module( $m, 'dashboard-widgets', __( 'Dashboard Widgets', 'ux-studio' ) );
			$this->note_unknown_widgets( $m, $widgets );
		}
		$this->map_welcome_panels( $m, $o, $origin, $site_roles, $lossy );
		if ( $this->on( $o, 'add_own_rss_panel' ) && '' !== $this->str( $o, 'rss_feed_address' ) ) {
			$lossy[] = __( 'The own RSS widget of the dashboard.', 'ux-studio' );
		}
		if ( '' !== $this->str( $o, 'dashboard_title' ) && 'Dashboard' !== $this->str( $o, 'dashboard_title' ) || '' !== $this->str( $o, 'dashboard_icon' ) ) {
			$lossy[] = __( 'The changed title and icon of the dashboard page.', 'ux-studio' );
		}

		/* ── Other branding the module has no setting for ── */
		$admin_css = $this->str( $o, 'settings_custom_css_admin' );
		if ( '' !== $admin_css ) {
			$m->set( self::MODULE, 'admin_custom_css', $admin_css, __( 'Custom CSS of the admin area', 'ux-studio' ), $origin . ':settings_custom_css_admin' );
			$lossy[] = __( 'The own CSS of the admin is copied as it is; UX Studio removes HTML tags from it and cuts it at 50 KB, so check the admin screens after the switch.', 'ux-studio' );
		}
		$other = array();
		foreach ( array(
			'custom_page_title'           => __( 'admin page title suffix', 'ux-studio' ),
			'hide_wp_version'             => __( 'hidden WordPress version in the footer', 'ux-studio' ),
			'admin_bar_howdy_text'        => __( 'text of the greeting in the admin bar', 'ux-studio' ),
			'side_menu_image'             => __( 'logo above the admin menu', 'ux-studio' ),
			'developer_icon'              => __( 'developer icon', 'ux-studio' ),
			'developer_side_menu_image'   => __( 'developer logo above the menu', 'ux-studio' ),
			'settings_custom_css_url'     => __( 'editor stylesheet', 'ux-studio' ),
			'gutenberg_exit_custom_icon'  => __( 'exit icon of the block editor', 'ux-studio' ),
		) as $key => $name ) {
			if ( '' !== $this->str( $o, $key ) && ! ( 'hide_wp_version' === $key && ! $this->on( $o, $key ) ) ) {
				$other[ $name ] = true;
			}
		}
		if ( $this->on( $o, 'enable_wlcms_admin' ) ) {
			$other[ __( 'the plugin\'s own admin menu and admin bar editor', 'ux-studio' ) ] = true;
		}
		if ( $other ) {
			$lossy[] = sprintf(
				/* translators: %s: list of branding settings. */
				__( 'Branding without a target in UX Studio: %s.', 'ux-studio' ),
				implode( ', ', array_keys( $other ) )
			);
		}

		foreach ( array_unique( $lossy ) as $line ) {
			$m->lossy( $line );
		}
		if ( $m->is_empty() && ! $m->lossy ) {
			$m->note( __( 'The plugin keeps only its defaults, there is nothing to transfer.', 'ux-studio' ) );
		}
		return $m;
	}

	/**
	 * Welcome panels of the dashboard. The module has one panel (title, HTML, roles), so the first active HTML panel is
	 * transferred and the others, and the panels built from a page or a page builder, are named as not transferred.
	 *
	 * @param Mapping              $m          Mapping.
	 * @param array<string, mixed> $o          Options.
	 * @param string               $origin     Origin text.
	 * @param string[]             $site_roles Roles of this site.
	 * @param string[]             $lossy      Lossy collector (by reference).
	 */
	private function map_welcome_panels( Mapping $m, array $o, string $origin, array $site_roles, array &$lossy ): void {
		$html_panels = array();
		$other       = 0;
		foreach ( (array) ( $o['welcome_panel'] ?? array() ) as $panel ) {
			$panel  = (array) $panel;
			$active = isset( $panel['is_active'] ) && ( true === $panel['is_active'] || 1 === $panel['is_active'] || '1' === $panel['is_active'] );
			$type   = (string) ( $panel['template_type'] ?? 'html' );
			$has    = ! empty( $panel['title'] ) || ! empty( $panel['description'] ) || ! empty( $panel['page_id_page'] ) || ! empty( $panel['page_id_elementor'] ) || ! empty( $panel['page_id_beaver'] );
			if ( ! $has ) {
				continue;
			}
			if ( $active && ( 'html' === $type || '' === $type ) && '' !== trim( strip_tags( (string) ( $panel['description'] ?? '' ) ) ) ) {
				$html_panels[] = $panel;
			} else {
				++$other;
			}
		}
		if ( $html_panels ) {
			$panel   = $html_panels[0];
			$visible = array_values( array_filter( array_map( 'strval', (array) ( $panel['visible_to'] ?? array() ) ) ) );
			$visible = $site_roles ? array_values( array_intersect( $visible, $site_roles ) ) : $visible;
			// Every role of the site (or none named) = everybody.
			if ( ! $site_roles || ! array_diff( $site_roles, $visible ) ) {
				$visible = array();
			}
			$m->set( 'dashboard-widgets', 'welcome_panel_enabled', true, __( 'Show a custom welcome panel on the dashboard', 'ux-studio' ), $origin . ':welcome_panel' );
			$m->set( 'dashboard-widgets', 'welcome_panel_title', trim( strip_tags( (string) ( $panel['title'] ?? '' ) ) ), __( 'Welcome panel title', 'ux-studio' ), $origin . ':welcome_panel' );
			$m->set( 'dashboard-widgets', 'welcome_panel_content', (string) ( $panel['description'] ?? '' ), __( 'Welcome panel content', 'ux-studio' ), $origin . ':welcome_panel' );
			$m->set( 'dashboard-widgets', 'welcome_panel_roles', $visible, __( 'Show the welcome panel to these roles', 'ux-studio' ), $origin . ':welcome_panel' );
			$this->note_extra_module( $m, 'dashboard-widgets', __( 'Dashboard Widgets', 'ux-studio' ) );
			$m->note( __( 'The welcome panel becomes a box at the top of the dashboard. The text is kept with basic HTML only (scripts and unsafe markup are removed).', 'ux-studio' ) );
			if ( ! empty( $panel['is_fullwidth'] ) ) {
				$lossy[] = __( 'The welcome panel was full width in the plugin; in UX Studio it is a normal dashboard box.', 'ux-studio' );
			}
			if ( ! empty( $panel['dismissible'] ) ) {
				$lossy[] = __( 'The welcome panel could be dismissed in the plugin; in UX Studio it can not.', 'ux-studio' );
			}
			$other += count( $html_panels ) - 1;
		}
		if ( $other ) {
			$lossy[] = sprintf(
				/* translators: %d: number of panels. */
				_n( '%d more welcome panel of the dashboard is not transferred (the module has one panel; panels built from a page, Elementor or Beaver Builder template, inactive and further text panels are left out).', '%d more welcome panels of the dashboard are not transferred (the module has one panel; panels built from a page, Elementor or Beaver Builder template, inactive and further text panels are left out).', $other, 'ux-studio' ),
				$other
			);
		}
	}
}
