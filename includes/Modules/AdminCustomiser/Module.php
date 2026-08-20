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

		return array(
			array(
				'key'     => 'primary_color',
				'type'    => 'color',
				'label'   => __( 'Primary color', 'ux-studio' ),
				'default' => '',
			),
			array(
				'key'     => 'login_logo',
				'type'    => 'media',
				'label'   => __( 'Login screen logo', 'ux-studio' ),
				'default' => '',
			),
			array(
				'key'     => 'login_background_color',
				'type'    => 'color',
				'label'   => __( 'Login screen background color', 'ux-studio' ),
				'default' => '',
			),
			array(
				'key'     => 'admin_footer_text',
				'type'    => 'text',
				'label'   => __( 'Admin footer text', 'ux-studio' ),
				'default' => '',
			),
			array(
				'key'     => 'hide_screen_options',
				'type'    => 'toggle',
				'label'   => __( 'Hide "Screen Options" tab', 'ux-studio' ),
				'default' => false,
			),
			array(
				'key'     => 'hide_help_tab',
				'type'    => 'toggle',
				'label'   => __( 'Hide "Help" tab', 'ux-studio' ),
				'default' => false,
			),
			array(
				'key'     => 'menu_organizer_enabled',
				'type'    => 'toggle',
				'label'   => __( 'Enable menu organizer', 'ux-studio' ),
				'help'    => __( 'Reorganizes the WordPress admin menu into custom categories. Configure categories, item order and overrides in the Menu Organizer tab.', 'ux-studio' ),
				'default' => false,
			),
			array(
				'key'     => 'admin_bar_enabled',
				'type'    => 'toggle',
				'label'   => __( 'Enable admin bar customization', 'ux-studio' ),
				'default' => false,
			),
			array(
				'key'     => 'quick_search_enabled',
				'type'    => 'toggle',
				'label'   => __( 'Enable quick search', 'ux-studio' ),
				'default' => true,
			),
			array(
				'key'     => 'quick_search_post_types',
				'type'    => 'multiselect',
				'label'   => __( 'Quick search post types', 'ux-studio' ),
				'options' => $post_type_options,
				'default' => array_keys( $post_type_options ),
			),
			array(
				'key'     => 'icon_replacer_enabled',
				'type'    => 'toggle',
				'label'   => __( 'Enable icon replacer', 'ux-studio' ),
				'default' => false,
			),
			array(
				'key'     => 'hide_notices_enabled',
				'type'    => 'toggle',
				'label'   => __( 'Hide admin notices', 'ux-studio' ),
				'default' => false,
			),
			array(
				'key'     => 'hide_notices_relocate',
				'type'    => 'toggle',
				'label'   => __( 'Relocate instead of hiding', 'ux-studio' ),
				'help'    => __( 'Move notices to a tray instead of hiding them completely', 'ux-studio' ),
				'default' => true,
			),
			array(
				'key'     => 'server_clock_enabled',
				'type'    => 'toggle',
				'label'   => __( 'Enable server clock', 'ux-studio' ),
				'default' => false,
			),
			array(
				'key'     => 'server_clock_position',
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
				'type'    => 'toggle',
				'label'   => __( 'Enable login screen customization', 'ux-studio' ),
				'default' => false,
			),
		);
	}
}
