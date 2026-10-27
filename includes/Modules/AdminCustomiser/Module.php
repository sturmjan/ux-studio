<?php
/**
 * Admin Customiser module: WordPress admin menu reorganization (Menu
 * Organizer), plus shared settings for a set of sibling admin-UX features
 * (admin bar, quick search, login screen, icon replacer, hide-notices,
 * server clock, general theming) owned by a different concurrent module port.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\AdminCustomiser;

use UxStudio\Core\Migration\MenuLockOrigin;
use UxStudio\Modules\BaseModule;

defined( 'ABSPATH' ) || exit;

/**
 * This file (Module.php) plus meta.json are the shared contract for the
 * whole Admin Customiser module: the settings schema below is relied upon by
 * a concurrent agent porting the AdminBar/QuickSearch/Login/IconReplacer/
 * HideAdminNotices/ServerClock/theming domains, even though that agent does
 * not touch this file. This agent (menu-organizer wave) owns MenuOrganizer.php,
 * MenuOrganizerRestController.php and MenuOrganizerBootstrap.php only.
 *
 * No DB tables are needed: the Menu Organizer config is a single JSON-shaped
 * WP option (see MenuOrganizer::CONFIG_OPTION), not relational data.
 */
final class Module extends BaseModule {

	/**
	 * Register hooks. Always wires the Menu Organizer hooks (it self-gates on
	 * `menu_organizer_enabled` internally, matching the legacy module's
	 * always-hooked design so admin_menu/parent_file/submenu_file priority
	 * races with other menu-editor plugins stay consistent).
	 *
	 * MenuOrganizerBootstrap::register() already hooks its own REST routes on
	 * `rest_api_init` internally, so this module does not additionally
	 * register a `rest_api_init` callback itself (that would double-register
	 * the same routes).
	 */
	public function boot(): void {
		MenuOrganizerBootstrap::register();
		MenuLock::register();
		MiscBootstrap::register();
	}

	/**
	 * REST controller class, exposed for introspection. The Menu Organizer
	 * routes are actually registered by MenuOrganizerBootstrap::register()
	 * (called from boot() above), which is where the shared MenuOrganizer
	 * instance/snapshot lives - not re-instantiated here to avoid double
	 * route registration.
	 */
	public function rest_controller(): ?string {
		return MenuOrganizerRestController::class;
	}

	/**
	 * Normalize values the legacy framework stored in other shapes so the
	 * generic settings form shows (and re-saves) them correctly: `file` fields
	 * are arrays of attachment IDs, empty checklists / toggles are ''.
	 */
	public function settings_values(): array {
		$values = parent::settings_values();

		foreach ( array( 'login_logo', 'admin_bar_logo' ) as $key ) {
			if ( isset( $values[ $key ] ) ) {
				$values[ $key ] = AdminTheme::attachment_id( $values[ $key ] );
			}
		}
		foreach ( array( 'hide_admin_bar_elements', 'global__update_notifications', 'form_options' ) as $key ) {
			if ( isset( $values[ $key ] ) && ! is_array( $values[ $key ] ) ) {
				$values[ $key ] = array();
			}
		}
		if ( isset( $values['hide_sidebar_icons'] ) ) {
			$values['hide_sidebar_icons'] = true === $values['hide_sidebar_icons'] || 1 === $values['hide_sidebar_icons'] || '1' === $values['hide_sidebar_icons'];
		}

		return $values;
	}

	/**
	 * A hand-made change of "Block access to hidden pages" ends the note that a migration switched it on.
	 *
	 * @param array $input Raw input.
	 * @return array<string, mixed>
	 */
	public function save_settings( array $input ): array {
		$before = (bool) $this->settings->get( 'menu_lock_enabled', false );
		$values = parent::save_settings( $input );
		$after  = ! empty( $values['menu_lock_enabled'] );
		if ( $before !== $after || ! $after ) {
			MenuLockOrigin::clear();
		}
		return $values;
	}

	/**
	 * Settings schema shared by every Admin Customiser sub-feature. Fields
	 * belonging to domains ported by the concurrent agent (admin bar, quick
	 * search, login, icon replacer, hide notices, server clock, theming) are
	 * declared here so their settings tab/REST plumbing (generic `settings/
	 * {id}` route, SettingsFields renderer) works without that agent having
	 * to create this file.
	 */
	public function settings_schema(): array {
		$post_type_options = array();
		foreach ( get_post_types( array( 'public' => true ), 'objects' ) as $post_type ) {
			$post_type_options[ $post_type->name ] = $post_type->labels->name ?? $post_type->name;
		}

		$schema = array(
			array(
				'key'     => 'primary_color',
				'section' => __( 'Admin screens', 'ux-studio' ),
				'type'    => 'color',
				'label'   => __( 'Primary color', 'ux-studio' ),
				'default' => '',
			),
			array(
				'key'     => 'login_logo',
				'section' => __( 'Login page', 'ux-studio' ),
				'type'    => 'media',
				'label'   => __( 'Login screen logo', 'ux-studio' ),
				'default' => '',
			),
			array(
				'key'     => 'login_background_color',
				'section' => __( 'Login page', 'ux-studio' ),
				'type'    => 'color',
				'label'   => __( 'Login screen background color', 'ux-studio' ),
				'default' => '',
			),
			array(
				'key'     => 'login_text_color',
				'section' => __( 'Login page', 'ux-studio' ),
				'type'    => 'color',
				'label'   => __( 'Login screen text color', 'ux-studio' ),
				'default' => '',
			),
			array(
				'key'     => 'login_form_bg_color',
				'section' => __( 'Login page', 'ux-studio' ),
				'type'    => 'color',
				'label'   => __( 'Login form background color', 'ux-studio' ),
				'default' => '',
			),
			array(
				'key'     => 'login_form_text_color',
				'section' => __( 'Login page', 'ux-studio' ),
				'type'    => 'color',
				'label'   => __( 'Login form text color', 'ux-studio' ),
				'default' => '',
			),
			array(
				'key'     => 'login_form_border_color',
				'section' => __( 'Login page', 'ux-studio' ),
				'type'    => 'color',
				'label'   => __( 'Login form border color', 'ux-studio' ),
				'default' => '',
			),
			array(
				'key'     => 'login_form_border_radius',
				'section' => __( 'Login page', 'ux-studio' ),
				'type'    => 'number',
				'label'   => __( 'Login form corner radius (px)', 'ux-studio' ),
				'default' => 0,
			),
			array(
				'key'     => 'login_input_bg_color',
				'section' => __( 'Login page', 'ux-studio' ),
				'type'    => 'color',
				'label'   => __( 'Login field background color', 'ux-studio' ),
				'default' => '',
			),
			array(
				'key'     => 'login_input_text_color',
				'section' => __( 'Login page', 'ux-studio' ),
				'type'    => 'color',
				'label'   => __( 'Login field text color', 'ux-studio' ),
				'default' => '',
			),
			array(
				'key'     => 'login_input_border_color',
				'section' => __( 'Login page', 'ux-studio' ),
				'type'    => 'color',
				'label'   => __( 'Login field border color', 'ux-studio' ),
				'default' => '',
			),
			array(
				'key'     => 'login_button_bg_color',
				'section' => __( 'Login page', 'ux-studio' ),
				'type'    => 'color',
				'label'   => __( 'Login button background color', 'ux-studio' ),
				'default' => '',
			),
			array(
				'key'     => 'login_button_text_color',
				'section' => __( 'Login page', 'ux-studio' ),
				'type'    => 'color',
				'label'   => __( 'Login button text color', 'ux-studio' ),
				'default' => '',
			),
			array(
				'key'     => 'login_remember_me_default',
				'section' => __( 'Login page', 'ux-studio' ),
				'type'    => 'toggle',
				'label'   => __( '"Remember me" checked by default', 'ux-studio' ),
				'default' => false,
			),
			array(
				'key'     => 'login_message',
				'section' => __( 'Login page', 'ux-studio' ),
				'type'    => 'textarea',
				'label'   => __( 'Custom message above the login form', 'ux-studio' ),
				'help'    => __( 'Plain text/basic HTML shown above the username/password fields.', 'ux-studio' ),
				'default' => '',
			),
			array(
				'key'     => 'login_style',
				'section' => __( 'Login page', 'ux-studio' ),
				'type'    => 'select',
				'label'   => __( 'Login screen layout', 'ux-studio' ),
				'help'    => __( '"Split" reproduces the Centrální aplikace login screen: a full-height media panel (video/image/YouTube) on one side and the form on the other.', 'ux-studio' ),
				'options' => array(
					'classic' => __( 'Classic (centered form, colors above)', 'ux-studio' ),
					'split'   => __( 'Split (media panel + form, like Centrální aplikace)', 'ux-studio' ),
				),
				'default' => 'classic',
			),
			array(
				'key'     => 'login_split_media_type',
				'section' => __( 'Login page', 'ux-studio' ),
				'type'    => 'select',
				'label'   => __( 'Split layout: media panel type', 'ux-studio' ),
				'options' => array(
					'video'   => __( 'Video (MP4 URL)', 'ux-studio' ),
					'image'   => __( 'Static image', 'ux-studio' ),
					'youtube' => __( 'YouTube', 'ux-studio' ),
				),
				'default' => 'video',
			),
			array(
				'key'     => 'login_split_media_image',
				'section' => __( 'Login page', 'ux-studio' ),
				'type'    => 'media',
				'label'   => __( 'Split layout: image / video poster', 'ux-studio' ),
				'help'    => __( 'Used directly when the media type is "Static image", and as the poster/fallback frame for "Video" and "YouTube". Left empty, "Video" falls back to the same background clip used on the Centrální aplikace login screen.', 'ux-studio' ),
				'default' => '',
			),
			array(
				'key'     => 'login_split_media_video_url',
				'section' => __( 'Login page', 'ux-studio' ),
				'type'    => 'text',
				'label'   => __( 'Split layout: video URL (MP4)', 'ux-studio' ),
				'help'    => __( 'Link to an .mp4 file - an external URL or a link copied from the media library. Used only when the media type is "Video". Left empty, it loads the same background clip used on the Centrální aplikace login screen (not bundled with the plugin, fetched from ux1.cz).', 'ux-studio' ),
				'default' => '',
			),
			array(
				'key'     => 'login_split_media_youtube_url',
				'section' => __( 'Login page', 'ux-studio' ),
				'type'    => 'text',
				'label'   => __( 'Split layout: YouTube URL or video ID', 'ux-studio' ),
				'default' => '',
			),
			array(
				'key'     => 'login_split_show_logo',
				'section' => __( 'Login page', 'ux-studio' ),
				'type'    => 'toggle',
				'label'   => __( 'Split layout: show logo over the media panel', 'ux-studio' ),
				'default' => true,
			),
			array(
				'key'     => 'admin_footer_text',
				'section' => __( 'Admin screens', 'ux-studio' ),
				'type'    => 'text',
				'label'   => __( 'Admin footer text', 'ux-studio' ),
				'default' => '',
			),
			array(
				'key'     => 'hide_footer_version',
				'section' => __( 'Admin screens', 'ux-studio' ),
				'type'    => 'toggle',
				'label'   => __( 'Hide the WordPress version in the admin footer', 'ux-studio' ),
				'default' => false,
			),
			array(
				'key'     => 'hide_screen_options',
				'section' => __( 'Admin screens', 'ux-studio' ),
				'type'    => 'toggle',
				'label'   => __( 'Hide "Screen Options" tab', 'ux-studio' ),
				'default' => false,
			),
			array(
				'key'     => 'hide_help_tab',
				'section' => __( 'Admin screens', 'ux-studio' ),
				'type'    => 'toggle',
				'label'   => __( 'Hide "Help" tab', 'ux-studio' ),
				'default' => false,
			),
			array(
				'key'     => 'hide_rocket_insights',
				'section' => __( 'Admin screens', 'ux-studio' ),
				'type'    => 'toggle',
				'label'   => __( 'Hide Rocket Insights (WP Rocket)', 'ux-studio' ),
				'help'    => __( 'Turns off Rocket Insights through the official WP Rocket switch: the tab on the WP Rocket settings page, the score and recommendations in its sidebar and dashboard, the column in the post lists, its notices and the scheduled performance tests all disappear. Caching and the rest of WP Rocket keep working. Switch this off to get it all back. Without WP Rocket the option does nothing.', 'ux-studio' ),
				'default' => false,
			),
			array(
				'key'     => 'menu_organizer_enabled',
				'section' => __( 'Sidebar menu', 'ux-studio' ),
				'type'    => 'toggle',
				'label'   => __( 'Enable menu organizer', 'ux-studio' ),
				'help'    => __( 'Reorganizes the WordPress admin menu into custom categories. Configure categories, item order and overrides in the Menu Organizer tab.', 'ux-studio' ),
				'default' => false,
			),
			array(
				'key'     => 'menu_lock_enabled',
				'section' => __( 'Sidebar menu', 'ux-studio' ),
				'type'    => 'toggle',
				'label'   => __( 'Block access to hidden pages', 'ux-studio' ),
				'help'    => __( 'Off: the Menu Organizer only hides menu items, a hidden page still opens when its address is typed in. On: a page that is hidden from a user (limited to other roles, or hidden for everybody) is refused with "Sorry, you are not allowed to access this page" for users who cannot manage options. Sub-items follow their parent item, except a sub-item moved to the top level that the user can see. Never blocked: users who can manage options, the dashboard, the profile, UX Studio, Plugins, Users and Settings, AJAX and REST. Needs the menu organizer to be on. Rescue switch: define UXSTUDIO_DISABLE_MENU_LOCK as true in wp-config.php to switch the blocking off for everybody.', 'ux-studio' ),
				'default' => false,
			),
			array(
				'key'     => 'admin_bar_enabled',
				'section' => __( 'Admin bar', 'ux-studio' ),
				'type'    => 'toggle',
				'label'   => __( 'Enable admin bar customization', 'ux-studio' ),
				'default' => false,
			),
			array(
				'key'     => 'body_bg_color',
				'section' => __( 'Admin screens', 'ux-studio' ),
				'type'    => 'color',
				'label'   => __( 'Admin page background color', 'ux-studio' ),
				'default' => '',
			),
			array(
				'key'     => 'admin_bar_logo',
				'section' => __( 'Admin bar', 'ux-studio' ),
				'type'    => 'media',
				'label'   => __( 'Admin bar logo', 'ux-studio' ),
				'help'    => __( 'Replaces the WordPress logo in the admin bar.', 'ux-studio' ),
				'default' => 0,
			),
			array(
				'key'     => 'admin_bar_bg_color',
				'section' => __( 'Admin bar', 'ux-studio' ),
				'type'    => 'color',
				'label'   => __( 'Admin bar background color', 'ux-studio' ),
				'default' => '',
			),
			array(
				'key'     => 'admin_bar_bg_dropdown_color',
				'section' => __( 'Admin bar', 'ux-studio' ),
				'type'    => 'color',
				'label'   => __( 'Admin bar dropdown background color', 'ux-studio' ),
				'default' => '',
			),
			array(
				'key'     => 'admin_bar_text_color',
				'section' => __( 'Admin bar', 'ux-studio' ),
				'type'    => 'color',
				'label'   => __( 'Admin bar text color', 'ux-studio' ),
				'default' => '',
			),
			array(
				'key'     => 'admin_bar_text_hover_color',
				'section' => __( 'Admin bar', 'ux-studio' ),
				'type'    => 'color',
				'label'   => __( 'Admin bar text hover color', 'ux-studio' ),
				'default' => '',
			),
			array(
				'key'     => 'hide_admin_bar_elements',
				'section' => __( 'Admin bar', 'ux-studio' ),
				'type'    => 'multiselect',
				'label'   => __( 'Hide admin bar elements', 'ux-studio' ),
				'options' => array(
					'wp_logo'   => __( 'WordPress logo', 'ux-studio' ),
					'icons'     => __( 'Icons', 'ux-studio' ),
					'updates'   => __( 'Updates item', 'ux-studio' ),
					'customize' => __( 'Customize item', 'ux-studio' ),
					'comments'  => __( 'Comments item', 'ux-studio' ),
					'new_item'  => __( 'New item', 'ux-studio' ),
				),
				'default' => array(),
			),
			array(
				'key'     => 'hide_howdy_name',
				'section' => __( 'Admin bar', 'ux-studio' ),
				'type'    => 'toggle',
				'label'   => __( 'Admin bar: show only the avatar', 'ux-studio' ),
				'help'    => __( 'The user name stays in the dropdown menu. Applies when admin bar customization is enabled.', 'ux-studio' ),
				'default' => true,
			),
			array(
				'key'     => 'move_updates_right',
				'section' => __( 'Admin bar', 'ux-studio' ),
				'type'    => 'toggle',
				'label'   => __( 'Admin bar: move updates to the right', 'ux-studio' ),
				'default' => true,
			),
			array(
				'key'     => 'consolidate_plugin_actions',
				'section' => __( 'Admin bar', 'ux-studio' ),
				'type'    => 'toggle',
				'label'   => __( 'Admin bar: collapse plugin items under "+"', 'ux-studio' ),
				'default' => true,
			),
			array(
				'key'     => 'keep_on_bar_ids',
				'section' => __( 'Admin bar', 'ux-studio' ),
				'type'    => 'textarea',
				'label'   => __( 'Admin bar: keep these items on the bar', 'ux-studio' ),
				'help'    => __( 'Admin bar item IDs (one per line or comma separated) that must NOT be collapsed under "+". Full IDs (e.g. wp-admin-bar-notifications) and prefixes both work. Tip: right-click an icon in the admin bar and choose Inspect to find its ID.', 'ux-studio' ),
				'default' => '',
			),
			array(
				'key'     => 'move_site_link_right',
				'section' => __( 'Admin bar', 'ux-studio' ),
				'type'    => 'toggle',
				'label'   => __( 'Admin bar: site name as a link icon on the right', 'ux-studio' ),
				'default' => true,
			),
			array(
				'key'     => 'horizontal_menu_inline',
				'section' => __( 'Sidebar menu', 'ux-studio' ),
				'type'    => 'toggle',
				'label'   => __( 'Horizontal menu on the same row as the admin bar', 'ux-studio' ),
				'help'    => __( 'Merges the horizontal menu and the admin bar into one row. Applies only when the Menu Organizer uses the horizontal layout.', 'ux-studio' ),
				'default' => false,
			),
			array(
				'key'     => 'sidebar_bg_color',
				'section' => __( 'Sidebar menu', 'ux-studio' ),
				'type'    => 'color',
				'label'   => __( 'Sidebar background color', 'ux-studio' ),
				'default' => '',
			),
			array(
				'key'     => 'sidebar_text_color',
				'section' => __( 'Sidebar menu', 'ux-studio' ),
				'type'    => 'color',
				'label'   => __( 'Sidebar text color', 'ux-studio' ),
				'default' => '',
			),
			array(
				'key'     => 'selected_menu_bg_color',
				'section' => __( 'Sidebar menu', 'ux-studio' ),
				'type'    => 'color',
				'label'   => __( 'Sidebar active item background', 'ux-studio' ),
				'default' => '',
			),
			array(
				'key'     => 'selected_menu_text_color',
				'section' => __( 'Sidebar menu', 'ux-studio' ),
				'type'    => 'color',
				'label'   => __( 'Sidebar active item text', 'ux-studio' ),
				'default' => '',
			),
			array(
				'key'     => 'hover_menu_bg_color',
				'section' => __( 'Sidebar menu', 'ux-studio' ),
				'type'    => 'color',
				'label'   => __( 'Sidebar hover item background', 'ux-studio' ),
				'default' => '',
			),
			array(
				'key'     => 'hover_menu_text_color',
				'section' => __( 'Sidebar menu', 'ux-studio' ),
				'type'    => 'color',
				'label'   => __( 'Sidebar hover item text', 'ux-studio' ),
				'default' => '',
			),
			array(
				'key'     => 'sidebar_icons_color',
				'section' => __( 'Sidebar menu', 'ux-studio' ),
				'type'    => 'color',
				'label'   => __( 'Sidebar icons color', 'ux-studio' ),
				'default' => '',
			),
			array(
				'key'     => 'hide_sidebar_icons',
				'section' => __( 'Sidebar menu', 'ux-studio' ),
				'type'    => 'toggle',
				'label'   => __( 'Hide sidebar icons', 'ux-studio' ),
				'default' => false,
			),
			array(
				'key'     => 'sidebar_width',
				'section' => __( 'Sidebar menu', 'ux-studio' ),
				'type'    => 'number',
				'label'   => __( 'Sidebar width (px, 120-300)', 'ux-studio' ),
				'help'    => __( 'WordPress default is 160.', 'ux-studio' ),
				'default' => 160,
			),
			array(
				'key'     => 'global__update_notifications',
				'section' => __( 'Admin screens', 'ux-studio' ),
				'type'    => 'multiselect',
				'label'   => __( 'Suppress update notifications', 'ux-studio' ),
				'help'    => __( 'The selected update checks report "nothing to update", so the update nags and admin bar badges disappear. Applies when hiding admin notices is enabled.', 'ux-studio' ),
				'options' => array(
					'core'   => __( 'Core', 'ux-studio' ),
					'theme'  => __( 'Themes', 'ux-studio' ),
					'plugin' => __( 'Plugins', 'ux-studio' ),
				),
				'default' => array(),
			),
			array(
				'key'     => 'form_options',
				'section' => __( 'Login page', 'ux-studio' ),
				'type'    => 'multiselect',
				'label'   => __( 'Login form options', 'ux-studio' ),
				'options' => array(
					'hide_login_logo' => __( 'Hide login logo', 'ux-studio' ),
				),
				'default' => array(),
			),
			array(
				'key'     => 'login_form_text_hover_color',
				'section' => __( 'Login page', 'ux-studio' ),
				'type'    => 'color',
				'label'   => __( 'Login form text hover color', 'ux-studio' ),
				'default' => '',
			),
			array(
				'key'     => 'quick_search_enabled',
				'section' => __( 'Admin screens', 'ux-studio' ),
				'type'    => 'toggle',
				'label'   => __( 'Enable quick search', 'ux-studio' ),
				'default' => true,
			),
			array(
				'key'     => 'quick_search_post_types',
				'section' => __( 'Admin screens', 'ux-studio' ),
				'type'    => 'multiselect',
				'label'   => __( 'Quick search post types', 'ux-studio' ),
				'options' => $post_type_options,
				'default' => array_keys( $post_type_options ),
			),
			array(
				'key'     => 'icon_replacer_enabled',
				'section' => __( 'Sidebar menu', 'ux-studio' ),
				'type'    => 'toggle',
				'label'   => __( 'Enable icon replacer', 'ux-studio' ),
				'default' => false,
			),
			array(
				'key'     => 'hide_notices_enabled',
				'section' => __( 'Admin screens', 'ux-studio' ),
				'type'    => 'toggle',
				'label'   => __( 'Hide admin notices', 'ux-studio' ),
				'default' => false,
			),
			array(
				'key'     => 'hide_notices_relocate',
				'section' => __( 'Admin screens', 'ux-studio' ),
				'type'    => 'toggle',
				'label'   => __( 'Relocate instead of hiding', 'ux-studio' ),
				'help'    => __( 'Move notices to a tray instead of hiding them completely', 'ux-studio' ),
				'default' => true,
			),
			array(
				'key'     => 'server_clock_enabled',
				'section' => __( 'Admin bar', 'ux-studio' ),
				'type'    => 'toggle',
				'label'   => __( 'Enable server clock', 'ux-studio' ),
				'default' => false,
			),
			array(
				'key'     => 'server_clock_position',
				'section' => __( 'Admin bar', 'ux-studio' ),
				'type'    => 'select',
				'label'   => __( 'Server clock position', 'ux-studio' ),
				'options' => array(
					'admin-bar-pill' => __( 'Admin bar pill', 'ux-studio' ),
					'plus-menu'      => __( 'Plus menu', 'ux-studio' ),
				),
				'default' => 'admin-bar-pill',
			),
			array(
				'key'     => 'login_customization_enabled',
				'section' => __( 'Login page', 'ux-studio' ),
				'type'    => 'toggle',
				'label'   => __( 'Enable login screen customization', 'ux-studio' ),
				'default' => false,
			),
			array(
				'key'     => 'login_background_image',
				'section' => __( 'Login page', 'ux-studio' ),
				'type'    => 'media',
				'label'   => __( 'Login screen background image', 'ux-studio' ),
				'help'    => __( 'Shown behind the classic login layout (the split layout has its own media panel). The background color above shows where the image does not reach.', 'ux-studio' ),
				'default' => 0,
			),
			array(
				'key'     => 'login_background_style',
				'section' => __( 'Login page', 'ux-studio' ),
				'type'    => 'select',
				'label'   => __( 'Login screen background image: fit', 'ux-studio' ),
				'options' => array(
					'cover'   => __( 'Cover the whole screen', 'ux-studio' ),
					'contain' => __( 'Fit inside the screen', 'ux-studio' ),
					'center'  => __( 'Original size, centered', 'ux-studio' ),
					'repeat'  => __( 'Repeat (tile)', 'ux-studio' ),
				),
				'default' => 'cover',
			),
			array(
				'key'     => 'login_button_hover_bg_color',
				'section' => __( 'Login page', 'ux-studio' ),
				'type'    => 'color',
				'label'   => __( 'Login button background color on hover', 'ux-studio' ),
				'default' => '',
			),
			array(
				'key'     => 'login_button_hover_text_color',
				'section' => __( 'Login page', 'ux-studio' ),
				'type'    => 'color',
				'label'   => __( 'Login button text color on hover', 'ux-studio' ),
				'default' => '',
			),
			array(
				'key'     => 'login_link_hover_color',
				'section' => __( 'Login page', 'ux-studio' ),
				'type'    => 'color',
				'label'   => __( 'Login screen link color on hover', 'ux-studio' ),
				'help'    => __( 'The links under the form ("Lost your password?", "Back to the site").', 'ux-studio' ),
				'default' => '',
			),
			array(
				'key'     => 'login_custom_css',
				'section' => __( 'Custom CSS', 'ux-studio' ),
				'type'    => 'css',
				'label'   => __( 'Custom CSS of the login page', 'ux-studio' ),
				'help'    => __( 'Printed in the head of the login, lost-password and registration screens, also when login screen customization above is off. Plain CSS only: HTML tags are stripped and the size is limited to 50 KB. It is never printed on the front end of the site.', 'ux-studio' ),
				'default' => '',
			),
			array(
				'key'     => 'admin_custom_css',
				'section' => __( 'Custom CSS', 'ux-studio' ),
				'type'    => 'css',
				'label'   => __( 'Custom CSS of the admin area', 'ux-studio' ),
				'help'    => __( 'Printed in the head of every admin screen, after the other admin styles. Plain CSS only: HTML tags are stripped and the size is limited to 50 KB. It is never printed on the front end of the site. Own JavaScript for the admin is not supported on purpose.', 'ux-studio' ),
				'default' => '',
			),
		);

		// B1: meta boxes hidden per role on the edit screens (one multi-select per role).
		return SettingsLayout::apply( array_merge( $schema, MetaBoxRules::schema() ) );
	}
}
