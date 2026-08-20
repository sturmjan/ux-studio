<?php
/**
 * Maintenance Mode module - front a maintenance / coming soon page.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\MaintenanceMode;

use UxStudio\Modules\BaseModule;

defined( 'ABSPATH' ) || exit;

/**
 * Serves a customisable maintenance (503) or coming soon (200) page to visitors
 * while letting privileged users through. Ported from the legacy module with
 * the free and pro features merged (no licence gates). Layouts are PHP
 * templates in layouts/, styles in assets/css/.
 */
final class Module extends BaseModule {

	/**
	 * Query param that forces a preview for privileged users.
	 */
	private const PREVIEW_PARAM = 'uxstudio_maintenance_preview';

	/**
	 * Register hooks.
	 */
	public function boot(): void {
		add_action( 'template_redirect', array( $this, 'maybe_show_maintenance_page' ) );
		add_action( 'admin_init', array( $this, 'maybe_redirect_from_admin' ) );
		add_action( 'admin_bar_menu', array( $this, 'add_admin_bar_notice' ), 1000 );
		add_action( 'admin_head', array( $this, 'admin_bar_styles' ) );
		add_action( 'wp_head', array( $this, 'admin_bar_styles' ) );
	}

	/**
	 * Show the maintenance page on the frontend when applicable.
	 */
	public function maybe_show_maintenance_page(): void {
		if ( ! $this->should_show_maintenance_page() ) {
			return;
		}

		add_filter( 'show_admin_bar', '__return_false' );
		$this->set_page_headers();
		$this->load_template();
		exit;
	}

	/**
	 * Redirect unauthorized users away from wp-admin during maintenance.
	 */
	public function maybe_redirect_from_admin(): void {
		if ( $this->is_preview_request() ) {
			return;
		}
		if ( wp_doing_ajax() || wp_doing_cron() ) {
			return;
		}
		if ( 'disabled' === $this->get_mode() ) {
			return;
		}
		if ( $this->user_has_access() ) {
			return;
		}

		wp_safe_redirect( home_url( '/' ) );
		exit;
	}

	/**
	 * Whether the maintenance page should be shown for the current request.
	 */
	private function should_show_maintenance_page(): bool {
		if ( $this->is_preview_request() ) {
			return true;
		}
		if ( wp_doing_ajax() || is_admin() ) {
			return false;
		}
		if ( 'disabled' === $this->get_mode() ) {
			return false;
		}

		return ! $this->user_has_access();
	}

	/**
	 * Whether the current user may bypass the maintenance page.
	 */
	private function user_has_access(): bool {
		// Administrators (and anyone who can manage the plugin) always bypass.
		if ( current_user_can( 'manage_options' ) ) {
			return true;
		}

		$auth_type = (string) $this->settings->get( 'auth_type', 'logged_in' );

		if ( 'roles' === $auth_type ) {
			if ( ! is_user_logged_in() ) {
				return false;
			}
			$user          = wp_get_current_user();
			$allowed_roles = (array) $this->settings->get( 'access_roles', array() );
			return ! empty( $allowed_roles ) && (bool) array_intersect( $allowed_roles, (array) $user->roles );
		}

		// Default: logged-in users bypass.
		return is_user_logged_in();
	}

	/**
	 * Whether this is a privileged preview request.
	 */
	private function is_preview_request(): bool {
		return isset( $_GET[ self::PREVIEW_PARAM ] ) && '1' === $_GET[ self::PREVIEW_PARAM ] && current_user_can( 'manage_options' );
	}

	/**
	 * Configured mode: maintenance | coming_soon | disabled.
	 */
	private function get_mode(): string {
		$mode = (string) $this->settings->get( 'mode', 'disabled' );
		return in_array( $mode, array( 'maintenance', 'coming_soon', 'disabled' ), true ) ? $mode : 'disabled';
	}

	/**
	 * Preview URL for the admin bar link.
	 */
	private function get_preview_url(): string {
		return add_query_arg( self::PREVIEW_PARAM, '1', home_url( '/' ) );
	}

	/**
	 * Send the response status and cache headers.
	 */
	private function set_page_headers(): void {
		$status = 'coming_soon' === $this->get_mode() ? 200 : 503;
		status_header( $status );

		if ( 503 === $status ) {
			header( 'Retry-After: 3600' );
		}
		header( 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0' );
	}

	/**
	 * Include the correct layout template.
	 */
	private function load_template(): void {
		$data = $this->get_layout_data();

		if ( 'page' === $data['layout']['type'] && ! empty( $data['layout']['existing_page'] ) ) {
			include $this->template_path( 'layouts/existing-page.php' );
			return;
		}

		$layout = $data['layout']['custom_layout'];
		$file   = $this->template_path( 'layouts/' . $layout . '.php' );
		if ( ! is_readable( $file ) ) {
			$file = $this->template_path( 'layouts/layout-1.php' );
		}

		include $file;
	}

	/**
	 * Absolute path to a template within this module.
	 *
	 * @param string $rel Relative path.
	 */
	private function template_path( string $rel ): string {
		return __DIR__ . '/' . ltrim( $rel, '/' );
	}

	/**
	 * URL to a CSS file within this module.
	 *
	 * @param string $filename CSS base filename (without extension).
	 */
	private function css_url( string $filename ): string {
		return plugins_url( 'assets/css/' . $filename . '.css', __FILE__ );
	}

	/**
	 * Build the data structure consumed by the layout templates.
	 *
	 * @return array<string, mixed>
	 */
	public function get_layout_data(): array {
		$layout_type   = 'page' === (string) $this->settings->get( 'layout_type', 'custom' ) ? 'page' : 'custom';
		$custom_layout = (string) $this->settings->get( 'custom_layout', 'layout-1' );
		if ( ! in_array( $custom_layout, array( 'layout-1', 'layout-2', 'layout-3' ), true ) ) {
			$custom_layout = 'layout-1';
		}

		$logo_id       = (int) $this->settings->get( 'logo_image', 0 );
		$logo_enabled  = 'yes' === (string) $this->settings->get( 'enable_logo', 'no' ) && $logo_id > 0;
		$bg_image_id   = (int) $this->settings->get( 'background_image', 0 );
		$bg_image_on   = (bool) $this->settings->get( 'enable_background_image', false ) && $bg_image_id > 0;

		$data = array(
			'mode'   => $this->get_mode(),
			'layout' => array(
				'type'          => $layout_type,
				'custom_layout' => $custom_layout,
				'existing_page' => (int) $this->settings->get( 'existing_page', 0 ),
			),
			'header' => array(
				'enabled' => $logo_enabled,
				'logo'    => array(
					'image'      => $logo_id,
					'dimensions' => array(
						'width' => (int) $this->settings->get( 'logo_width', 180 ),
					),
					'alt'        => get_bloginfo( 'name' ) . ' ' . __( 'Logo', 'ux-studio' ),
				),
			),
			'content' => array(
				'headline' => array(
					'text'  => (string) $this->settings->get( 'headline_text', __( 'Maintenance Mode', 'ux-studio' ) ),
					'color' => (string) $this->settings->get( 'headline_colour', '#1f2937' ),
				),
				'body'     => array(
					'text'  => (string) $this->settings->get( 'body_text', __( 'Site will be available soon. Thank you for your patience!', 'ux-studio' ) ),
					'color' => (string) $this->settings->get( 'body_colour', '#676c76' ),
				),
				'footer'   => array(
					'text'  => (string) $this->settings->get( 'footer_text', sprintf( '© %s %s', get_bloginfo( 'name' ), gmdate( 'Y' ) ) ),
					'color' => (string) $this->settings->get( 'footer_colour', '#676c76' ),
				),
			),
			'background' => array(
				'color'        => (string) $this->settings->get( 'background_colour', '#f5f5f5' ),
				'image'        => $bg_image_on ? (string) wp_get_attachment_url( $bg_image_id ) : '',
				'enable_image' => $bg_image_on,
			),
			'custom_css' => (string) $this->settings->get( 'custom_css', '' ),
			'meta'       => array(
				'title'       => sprintf( '%s - %s', get_bloginfo( 'name' ), __( 'Maintenance Mode', 'ux-studio' ) ),
				'description' => __( 'We are currently performing scheduled maintenance. Please check back soon.', 'ux-studio' ),
			),
		);

		if ( 'custom' === $layout_type ) {
			$data['css_files'] = array(
				'base'   => $this->css_url( 'base' ),
				'layout' => $this->css_url( $custom_layout ),
			);
		}

		return $data;
	}

	/**
	 * Inline styles for the admin bar maintenance notice.
	 */
	public function admin_bar_styles(): void {
		if ( ! $this->should_show_admin_bar_notice() ) {
			return;
		}
		?>
		<style id="uxstudio-maintenance-mode-admin-bar">
			#wp-admin-bar-uxstudio-maintenance-mode a {
				color: #fff !important;
				background: #e0281f !important;
				transition: 0.1s ease-in-out;
			}
			#wp-admin-bar-uxstudio-maintenance-mode a:hover,
			#wp-admin-bar-uxstudio-maintenance-mode a:focus {
				text-decoration: underline;
				background: #bd2b24 !important;
			}
		</style>
		<?php
	}

	/**
	 * Whether to render the admin bar notice.
	 */
	private function should_show_admin_bar_notice(): bool {
		if ( ! current_user_can( 'manage_options' ) || ! is_admin_bar_showing() ) {
			return false;
		}
		return 'disabled' !== $this->get_mode();
	}

	/**
	 * Add the maintenance notice to the admin bar.
	 *
	 * @param \WP_Admin_Bar $wp_admin_bar Admin bar instance.
	 */
	public function add_admin_bar_notice( \WP_Admin_Bar $wp_admin_bar ): void {
		if ( ! $this->should_show_admin_bar_notice() ) {
			return;
		}

		$title = 'coming_soon' === $this->get_mode()
			? __( 'Coming Soon Active', 'ux-studio' )
			: __( 'Maintenance Mode Active', 'ux-studio' );

		$wp_admin_bar->add_node(
			array(
				'id'     => 'uxstudio-maintenance-mode',
				'parent' => 'top-secondary',
				'title'  => $title,
				'href'   => admin_url( 'admin.php?page=ux-studio' ),
				'meta'   => array(
					'title' => __( 'Open preview', 'ux-studio' ),
				),
			)
		);

		$wp_admin_bar->add_node(
			array(
				'id'     => 'uxstudio-maintenance-mode-preview',
				'parent' => 'uxstudio-maintenance-mode',
				'title'  => __( 'Preview', 'ux-studio' ),
				'href'   => $this->get_preview_url(),
				'meta'   => array( 'target' => '_blank' ),
			)
		);
	}

	/**
	 * All available non-admin user roles for the access setting.
	 *
	 * @return array<string, string>
	 */
	private function get_role_options(): array {
		$roles = wp_roles()->get_names();
		unset( $roles['administrator'] );
		return array_map( 'translate_user_role', $roles );
	}

	/**
	 * All pages for the "existing page" select.
	 *
	 * @return array<int, string>
	 */
	private function get_page_options(): array {
		$options = array();
		foreach ( get_posts(
			array(
				'post_type'      => 'page',
				'posts_per_page' => -1,
				'no_found_rows'  => true,
			)
		) as $page ) {
			$options[ $page->ID ] = $page->post_title;
		}
		return $options;
	}

	/**
	 * Settings schema for the generic renderer.
	 */
	public function settings_schema(): array {
		return array(
			array(
				'key'     => 'mode',
				'type'    => 'select',
				'label'   => __( 'Mode', 'ux-studio' ),
				'help'    => __( 'Select the maintenance mode type.', 'ux-studio' ),
				'options' => array(
					'disabled'    => __( 'Disabled', 'ux-studio' ),
					'maintenance' => __( 'Maintenance Mode (503)', 'ux-studio' ),
					'coming_soon' => __( 'Coming Soon (200)', 'ux-studio' ),
				),
				'default' => 'disabled',
			),
			array(
				'key'     => 'layout_type',
				'type'    => 'select',
				'label'   => __( 'Layout type', 'ux-studio' ),
				'help'    => __( 'Choose how to display the maintenance page.', 'ux-studio' ),
				'options' => array(
					'custom' => __( 'Custom Layout', 'ux-studio' ),
					'page'   => __( 'Existing Page', 'ux-studio' ),
				),
				'default' => 'custom',
			),
			array(
				'key'     => 'custom_layout',
				'type'    => 'select',
				'label'   => __( 'Choose layout', 'ux-studio' ),
				'help'    => __( 'Which built-in layout to use for the maintenance page.', 'ux-studio' ),
				'options' => array(
					'layout-1' => __( 'Layout 1 (centered card)', 'ux-studio' ),
					'layout-2' => __( 'Layout 2 (split, image right)', 'ux-studio' ),
					'layout-3' => __( 'Layout 3 (split, image left)', 'ux-studio' ),
				),
				'default' => 'layout-1',
			),
			array(
				'key'     => 'existing_page',
				'type'    => 'select',
				'label'   => __( 'Existing page', 'ux-studio' ),
				'help'    => __( 'Page displayed when the layout type is "Existing Page".', 'ux-studio' ),
				'options' => $this->get_page_options(),
				'default' => '',
			),
			array(
				'key'     => 'auth_type',
				'type'    => 'select',
				'label'   => __( 'Authentication type', 'ux-studio' ),
				'help'    => __( 'Who is allowed to access the site while maintenance mode is active. Administrators always have access.', 'ux-studio' ),
				'options' => array(
					'logged_in' => __( 'Logged In Users', 'ux-studio' ),
					'roles'     => __( 'By User Role', 'ux-studio' ),
				),
				'default' => 'logged_in',
			),
			array(
				'key'     => 'access_roles',
				'type'    => 'multiselect',
				'label'   => __( 'Allowed roles', 'ux-studio' ),
				'help'    => __( 'Roles allowed to access the site when authentication is by role.', 'ux-studio' ),
				'options' => $this->get_role_options(),
				'default' => array(),
			),
			array(
				'key'     => 'headline_text',
				'type'    => 'text',
				'label'   => __( 'Headline text', 'ux-studio' ),
				'default' => __( 'Maintenance Mode', 'ux-studio' ),
			),
			array(
				'key'     => 'headline_colour',
				'type'    => 'color',
				'label'   => __( 'Headline colour', 'ux-studio' ),
				'default' => '#1f2937',
			),
			array(
				'key'     => 'body_text',
				'type'    => 'richtext',
				'label'   => __( 'Body text', 'ux-studio' ),
				'default' => __( 'Site will be available soon. Thank you for your patience!', 'ux-studio' ),
			),
			array(
				'key'     => 'body_colour',
				'type'    => 'color',
				'label'   => __( 'Body colour', 'ux-studio' ),
				'default' => '#676c76',
			),
			array(
				'key'     => 'footer_text',
				'type'    => 'text',
				'label'   => __( 'Footer text', 'ux-studio' ),
				'default' => '',
			),
			array(
				'key'     => 'footer_colour',
				'type'    => 'color',
				'label'   => __( 'Footer colour', 'ux-studio' ),
				'default' => '#676c76',
			),
			array(
				'key'     => 'enable_logo',
				'type'    => 'select',
				'label'   => __( 'Enable logo', 'ux-studio' ),
				'options' => array(
					'no'  => __( 'No', 'ux-studio' ),
					'yes' => __( 'Yes', 'ux-studio' ),
				),
				'default' => 'no',
			),
			array(
				'key'     => 'logo_image',
				'type'    => 'media',
				'label'   => __( 'Logo image', 'ux-studio' ),
				'help'    => __( 'Logo image shown on the maintenance page.', 'ux-studio' ),
				'default' => 0,
			),
			array(
				'key'     => 'logo_width',
				'type'    => 'number',
				'label'   => __( 'Logo width (px)', 'ux-studio' ),
				'default' => 180,
			),
			array(
				'key'     => 'background_colour',
				'type'    => 'color',
				'label'   => __( 'Background colour', 'ux-studio' ),
				'default' => '#f5f5f5',
			),
			array(
				'key'     => 'enable_background_image',
				'type'    => 'toggle',
				'label'   => __( 'Enable background image', 'ux-studio' ),
				'default' => false,
			),
			array(
				'key'     => 'background_image',
				'type'    => 'media',
				'label'   => __( 'Background image', 'ux-studio' ),
				'default' => 0,
			),
			array(
				'key'     => 'custom_css',
				'type'    => 'textarea',
				'label'   => __( 'Custom CSS', 'ux-studio' ),
				'help'    => __( 'Custom CSS for the maintenance page. Useful classes: .uxstudio__card, .uxstudio__headline, .uxstudio__description, .uxstudio__footer, .uxstudio__logo.', 'ux-studio' ),
				'default' => '',
			),
		);
	}
}
