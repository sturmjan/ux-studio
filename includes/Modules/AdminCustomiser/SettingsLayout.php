<?php
/**
 * How the Admin Customiser settings are laid out in the app: which cards (groups) each tab has, in which order, and
 * which field only matters when another one is set (`show_if`). Pure presentation: keys, defaults, sanitization,
 * export and import are never touched, a field that is not drawn keeps its stored value.
 *
 * `plan()` is the single place to read. A group is `title`, `help` (one line), optional `show_if` (applies to every
 * field of the group) and `fields`: a key, or `key => show_if` for a condition of that one field. A condition is
 * `array( 'key' => ..., 'equals' | 'in' | 'truthy' => ... )` or a list of them (all must hold); see src/app/showIf.ts.
 * A field that is not in the plan keeps its place after the planned ones, without a group.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\AdminCustomiser;

defined( 'ABSPATH' ) || exit;

final class SettingsLayout {

	/**
	 * Groups in display order (the tab of a card is the `section` of its fields).
	 *
	 * @return array<int, array{title: string, help: string, show_if?: array, fields: array}>
	 */
	public static function plan(): array {
		$login_on = array(
			'key'    => 'login_customization_enabled',
			'truthy' => true,
		);
		$classic  = array(
			'key'    => 'login_style',
			'equals' => 'classic',
		);
		$split    = array(
			'key'    => 'login_style',
			'equals' => 'split',
		);
		$bar_on   = array(
			'key'    => 'admin_bar_enabled',
			'truthy' => true,
		);
		$organizer_on = array(
			'key'    => 'menu_organizer_enabled',
			'truthy' => true,
		);

		return array(
			// Admin screens.
			array(
				'title'  => __( 'Colors', 'ux-studio' ),
				'help'   => __( 'The main color and the page background of every admin screen.', 'ux-studio' ),
				'fields' => array( 'primary_color', 'body_bg_color' ),
			),
			array(
				'title'  => __( 'Footer', 'ux-studio' ),
				'help'   => __( 'The text at the bottom of every admin screen.', 'ux-studio' ),
				'fields' => array( 'admin_footer_text', 'hide_footer_version' ),
			),
			array(
				'title'  => __( 'Hidden parts of the screens', 'ux-studio' ),
				'help'   => __( 'Switch off small parts of the admin screens that you do not use.', 'ux-studio' ),
				'fields' => array( 'hide_screen_options', 'hide_help_tab', 'hide_rocket_insights' ),
			),
			array(
				'title'  => __( 'Quick search', 'ux-studio' ),
				'help'   => __( 'A search field that finds content from any admin screen.', 'ux-studio' ),
				'fields' => array(
					'quick_search_enabled',
					'quick_search_post_types' => array(
						'key'    => 'quick_search_enabled',
						'truthy' => true,
					),
				),
			),
			array(
				'title'  => __( 'Admin notices', 'ux-studio' ),
				'help'   => __( 'Tidy up the notices that plugins and WordPress show at the top of the screens.', 'ux-studio' ),
				'fields' => array(
					'hide_notices_enabled',
					'hide_notices_relocate'        => array(
						'key'    => 'hide_notices_enabled',
						'truthy' => true,
					),
					'global__update_notifications' => array(
						'key'    => 'hide_notices_enabled',
						'truthy' => true,
					),
				),
			),

			// Login page.
			array(
				'title'  => __( 'Login page customization', 'ux-studio' ),
				'help'   => __( 'Switch it on to use the settings below. Off, WordPress shows its own login page.', 'ux-studio' ),
				'fields' => array( 'login_customization_enabled' ),
			),
			array(
				'title'   => __( 'Look and texts', 'ux-studio' ),
				'help'    => __( 'Layout of the page, the logo and the text above the form.', 'ux-studio' ),
				'show_if' => $login_on,
				'fields'  => array( 'login_style', 'login_logo', 'form_options', 'login_message', 'login_remember_me_default' ),
			),
			array(
				'title'   => __( 'Split layout', 'ux-studio' ),
				'help'    => __( 'The panel next to the form: a picture, an MP4 video or a YouTube video.', 'ux-studio' ),
				'show_if' => array( $login_on, $split ),
				'fields'  => array(
					'login_split_media_type',
					'login_split_media_image'       => array(
						'key' => 'login_split_media_type',
						'in'  => array( 'image', 'video' ),
					),
					'login_split_media_video_url'   => array(
						'key'    => 'login_split_media_type',
						'equals' => 'video',
					),
					'login_split_media_youtube_url' => array(
						'key'    => 'login_split_media_type',
						'equals' => 'youtube',
					),
					'login_split_show_logo',
				),
			),
			array(
				'title'   => __( 'Page colors', 'ux-studio' ),
				'help'    => __( 'Colors of the page around the form and of the links under it.', 'ux-studio' ),
				'show_if' => $login_on,
				'fields'  => array( 'login_background_color', 'login_text_color', 'login_link_hover_color' ),
			),
			array(
				'title'   => __( 'Background image', 'ux-studio' ),
				'help'    => __( 'A picture behind the classic layout. The split layout has its own media panel.', 'ux-studio' ),
				'show_if' => array( $login_on, $classic ),
				'fields'  => array( 'login_background_image', 'login_background_style' ),
			),
			array(
				'title'   => __( 'Form box', 'ux-studio' ),
				'help'    => __( 'The box around the login fields: background, text, border and corners.', 'ux-studio' ),
				'show_if' => $login_on,
				'fields'  => array( 'login_form_bg_color', 'login_form_text_color', 'login_form_text_hover_color', 'login_form_border_color', 'login_form_border_radius' ),
			),
			array(
				'title'   => __( 'Input fields', 'ux-studio' ),
				'help'    => __( 'The user name and password fields.', 'ux-studio' ),
				'show_if' => $login_on,
				'fields'  => array( 'login_input_bg_color', 'login_input_text_color', 'login_input_border_color' ),
			),
			array(
				'title'   => __( 'Login button', 'ux-studio' ),
				'help'    => __( 'Colors of the button, also when the pointer is over it.', 'ux-studio' ),
				'show_if' => $login_on,
				'fields'  => array( 'login_button_bg_color', 'login_button_text_color', 'login_button_hover_bg_color', 'login_button_hover_text_color' ),
			),

			// Sidebar menu.
			array(
				'title'  => __( 'Menu organizer', 'ux-studio' ),
				'help'   => __( 'Sorts the admin menu into your own categories. The categories themselves are set in the Menu organizer tab.', 'ux-studio' ),
				'fields' => array(
					'menu_organizer_enabled',
					'menu_lock_enabled'      => $organizer_on,
					'horizontal_menu_inline' => $organizer_on,
				),
			),
			array(
				'title'  => __( 'Sidebar colors', 'ux-studio' ),
				'help'   => __( 'Colors of the menu in its normal, active and hover state.', 'ux-studio' ),
				'fields' => array( 'sidebar_bg_color', 'sidebar_text_color', 'selected_menu_bg_color', 'selected_menu_text_color', 'hover_menu_bg_color', 'hover_menu_text_color' ),
			),
			array(
				'title'  => __( 'Icons and width', 'ux-studio' ),
				'help'   => __( 'The icons next to menu items and how wide the sidebar is.', 'ux-studio' ),
				'fields' => array(
					'icon_replacer_enabled',
					'hide_sidebar_icons',
					'sidebar_icons_color' => array(
						'key'    => 'hide_sidebar_icons',
						'truthy' => false,
					),
					'sidebar_width',
				),
			),

			// Admin bar.
			array(
				'title'  => __( 'Look of the bar', 'ux-studio' ),
				'help'   => __( 'The logo and the colors of the bar at the top of the screen.', 'ux-studio' ),
				'fields' => array( 'admin_bar_logo', 'admin_bar_bg_color', 'admin_bar_bg_dropdown_color', 'admin_bar_text_color', 'admin_bar_text_hover_color', 'hide_admin_bar_elements' ),
			),
			array(
				'title'  => __( 'Admin bar tidy-up', 'ux-studio' ),
				'help'   => __( 'A tidier bar: shorter user name, updates on the right and plugin items collapsed under a plus.', 'ux-studio' ),
				'fields' => array(
					'admin_bar_enabled',
					'hide_howdy_name'            => $bar_on,
					'move_updates_right'         => $bar_on,
					'move_site_link_right'       => $bar_on,
					'consolidate_plugin_actions' => $bar_on,
					'keep_on_bar_ids'            => array(
						$bar_on,
						array(
							'key'    => 'consolidate_plugin_actions',
							'truthy' => true,
						),
					),
				),
			),
			array(
				'title'  => __( 'Server clock', 'ux-studio' ),
				'help'   => __( 'Shows the time of the server in the bar or in the plus menu.', 'ux-studio' ),
				'fields' => array(
					'server_clock_enabled',
					'server_clock_position' => array(
						'key'    => 'server_clock_enabled',
						'truthy' => true,
					),
				),
			),

			// Custom CSS.
			array(
				'title'  => __( 'Login page', 'ux-studio' ),
				'help'   => __( 'Own styles for the login, lost password and registration screens.', 'ux-studio' ),
				'fields' => array( 'login_custom_css' ),
			),
			array(
				'title'  => __( 'Admin screens', 'ux-studio' ),
				'help'   => __( 'Own styles for every admin screen.', 'ux-studio' ),
				'fields' => array( 'admin_custom_css' ),
			),
		);
	}

	/**
	 * Put the fields of a schema in the order of the plan and give them their card and condition.
	 *
	 * @param array<int, array<string, mixed>> $schema Settings schema.
	 * @return array<int, array<string, mixed>>
	 */
	public static function apply( array $schema ): array {
		$by_key = array();
		foreach ( $schema as $field ) {
			$by_key[ (string) $field['key'] ] = $field;
		}
		$out = array();
		foreach ( self::plan() as $group ) {
			$group_cond = self::as_list( $group['show_if'] ?? array() );
			foreach ( $group['fields'] as $k => $v ) {
				$key  = is_int( $k ) ? (string) $v : (string) $k;
				$cond = is_int( $k ) ? array() : self::as_list( $v );
				if ( ! isset( $by_key[ $key ] ) ) {
					continue;
				}
				$field          = $by_key[ $key ];
				$field['group'] = $group['title'];
				if ( ! isset( $field['group_help'] ) ) {
					$field['group_help'] = $group['help'];
				}
				$all = array_merge( $group_cond, $cond );
				if ( $all ) {
					$field['show_if'] = $all;
				}
				$out[]  = $field;
				unset( $by_key[ $key ] );
			}
		}
		return array_merge( $out, array_values( $by_key ) );
	}

	/**
	 * One condition or a list of them as a list.
	 *
	 * @param array $cond Condition or list of conditions.
	 * @return array<int, array<string, mixed>>
	 */
	private static function as_list( array $cond ): array {
		if ( ! $cond ) {
			return array();
		}
		return isset( $cond['key'] ) ? array( $cond ) : array_values( $cond );
	}
}
