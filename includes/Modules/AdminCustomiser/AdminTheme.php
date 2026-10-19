<?php
/**
 * Admin theming ported from the legacy admin-customiser module (AdminBar.php
 * CSS variables, Sidebar.php, style.css): admin page background, admin bar
 * colors / custom logo / "hide elements" checklist, and the admin sidebar
 * colors, icon visibility and width.
 *
 * Same setting keys as the legacy module, so values copied by the Migrator
 * work as-is. Every rule is only printed for a non-empty setting (an empty
 * color means "leave WordPress alone"), so the class is harmless when nothing
 * is configured and does not depend on any feature toggle.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\AdminCustomiser;

use UxStudio\Core\Settings;

defined( 'ABSPATH' ) || exit;

final class AdminTheme {

	/** WordPress default sidebar width in px (no override needed at this value). */
	private const DEFAULT_SIDEBAR_WIDTH = 160;

	private Settings $settings;

	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/** Register hooks (called unconditionally from MiscBootstrap). */
	public function register(): void {
		add_action( 'admin_bar_menu', array( $this, 'remove_hidden_nodes' ), 100 );
		add_action( 'admin_head', array( $this, 'print_admin_styles' ), 20 );
		add_action( 'admin_head', array( $this, 'print_admin_bar_styles' ), 20 );
		add_action( 'wp_head', array( $this, 'print_admin_bar_styles' ), 20 );
		add_filter( 'admin_body_class', array( $this, 'admin_body_class' ) );
	}

	/** Valid hex color setting or ''. */
	private function color( string $key ): string {
		$value = $this->settings->get( $key, '' );
		if ( ! is_string( $value ) || '' === $value ) {
			return '';
		}
		return (string) sanitize_hex_color( $value );
	}

	/** Legacy "checkboxes" value as a clean list of keys (stored as array, or '' when empty). */
	private function hidden_elements(): array {
		$value = $this->settings->get( 'hide_admin_bar_elements', array() );
		return is_array( $value ) ? array_map( 'strval', $value ) : array();
	}

	/** Legacy toggle (true/'1'/1 = on; '' = off). */
	private function truthy( string $key ): bool {
		$value = $this->settings->get( $key, false );
		return true === $value || 1 === $value || '1' === $value;
	}

	/**
	 * Attachment id from a `media` value: int, numeric string, or the legacy
	 * "file" field array whose first item is the id.
	 *
	 * @param mixed $value Stored value.
	 */
	public static function attachment_id( $value ): int {
		if ( is_array( $value ) ) {
			$value = reset( $value );
		}
		return is_numeric( $value ) ? absint( $value ) : 0;
	}

	/** Make a URL safe for an unquoted-in-attribute CSS url("...") context. */
	private static function css_url( string $url ): string {
		$url = esc_url_raw( $url );
		return str_replace(
			array( '"', "'", '(', ')', '\\', '<', '>', ' ', "\n", "\r" ),
			array( '%22', '%27', '%28', '%29', '%5C', '%3C', '%3E', '%20', '', '' ),
			$url
		);
	}

	/**
	 * Remove the admin bar nodes ticked in `hide_admin_bar_elements`
	 * (wp_logo, updates, customize, comments, new_item).
	 *
	 * @param \WP_Admin_Bar $admin_bar Admin bar instance.
	 */
	public function remove_hidden_nodes( $admin_bar ): void {
		$map = array(
			'wp_logo'   => 'wp-logo',
			'updates'   => 'updates',
			'customize' => 'customize',
			'comments'  => 'comments',
			'new_item'  => 'new-content',
		);
		foreach ( $this->hidden_elements() as $element ) {
			if ( isset( $map[ $element ] ) ) {
				$admin_bar->remove_node( $map[ $element ] );
			}
		}
	}

	/**
	 * Admin bar colors, custom logo and "hide icons" (front end and admin).
	 */
	public function print_admin_bar_styles(): void {
		if ( ! is_admin_bar_showing() ) {
			return;
		}

		$bg       = $this->color( 'admin_bar_bg_color' );
		$dropdown = $this->color( 'admin_bar_bg_dropdown_color' );
		$text     = $this->color( 'admin_bar_text_color' );
		$hover    = $this->color( 'admin_bar_text_hover_color' );

		$css = '';
		if ( '' !== $bg ) {
			$css .= '#wpadminbar{background:' . $bg . '!important}';
		}
		if ( '' !== $text ) {
			$css .= '#wpadminbar .ab-item,#wpadminbar a.ab-item,#wpadminbar>#wp-toolbar span.ab-label,#wpadminbar>#wp-toolbar span.noticon{color:' . $text . '!important}';
			$css .= '#wpadminbar .ab-icon::before,#wpadminbar .ab-item::before{color:' . $text . '!important}';
		}
		if ( '' !== $hover ) {
			$css .= '#wpadminbar .ab-top-menu>li:hover>.ab-item,#wpadminbar .ab-top-menu>li.hover>.ab-item,#wpadminbar .ab-top-menu>li>.ab-item:focus,#wpadminbar .ab-submenu .ab-item:hover,#wpadminbar .ab-submenu .ab-item:focus,#wpadminbar .quicklinks .menupop .ab-sub-wrapper a:hover{color:' . $hover . '!important}';
			$css .= '#wpadminbar .ab-top-menu>li:hover>.ab-item .ab-icon::before,#wpadminbar .ab-top-menu>li.hover>.ab-item .ab-icon::before,#wpadminbar .ab-top-menu>li:hover>.ab-item::before,#wpadminbar .ab-top-menu>li>.ab-item:focus::before{color:' . $hover . '!important}';
		}
		if ( '' !== $dropdown ) {
			$css .= '#wpadminbar .menupop .ab-sub-wrapper,#wpadminbar .shortlink-input{background:' . $dropdown . '!important}';
		}

		// Custom logo replaces the WordPress logo icon (unless the node is hidden).
		$logo_id = self::attachment_id( $this->settings->get( 'admin_bar_logo', 0 ) );
		if ( $logo_id > 0 && ! in_array( 'wp_logo', $this->hidden_elements(), true ) ) {
			$logo_url = wp_get_attachment_image_url( $logo_id, 'full' );
			if ( $logo_url ) {
				$css .= '#wpadminbar #wp-admin-bar-wp-logo>.ab-item .ab-icon::before{content:""!important;display:block!important;background-image:url("' . self::css_url( $logo_url ) . '")!important;background-size:contain;background-position:center;background-repeat:no-repeat;width:20px;height:20px}';
				if ( '' !== $dropdown ) {
					$css .= '#wpadminbar #wp-admin-bar-wp-logo>.ab-item:hover{background:' . $dropdown . '}';
				}
			}
		}

		if ( in_array( 'icons', $this->hidden_elements(), true ) ) {
			$css .= '#wpadminbar .ab-icon::before{display:none!important}';
		}

		if ( '' !== $css ) {
			printf( '<style id="uxstudio-admin-bar-theme">%s</style>', $css ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
	}

	/**
	 * Admin page background and sidebar styling (admin only).
	 */
	public function print_admin_styles(): void {
		$css = '';

		$body_bg = $this->color( 'body_bg_color' );
		if ( '' !== $body_bg ) {
			$css .= 'body.wp-admin{background:' . $body_bg . '!important}';
		}

		$css .= $this->sidebar_css();

		if ( '' !== $css ) {
			printf( '<style id="uxstudio-admin-theme">%s</style>', $css ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
	}

	/** Body class driving the "hide sidebar icons" rules. */
	public function admin_body_class( $classes ) {
		if ( $this->truthy( 'hide_sidebar_icons' ) ) {
			$classes .= ' hide-sidebar-icons';
		}
		return $classes;
	}

	/**
	 * Sidebar rules. Selectors mirror the legacy style.css; each block is
	 * emitted only for a configured value, in the legacy cascade order.
	 */
	private function sidebar_css(): string {
		$bg          = $this->color( 'sidebar_bg_color' );
		$text        = $this->color( 'sidebar_text_color' );
		$text_hover  = $this->color( 'hover_menu_text_color' );
		$bg_hover    = $this->color( 'hover_menu_bg_color' );
		$active_bg   = $this->color( 'selected_menu_bg_color' );
		$active_text = $this->color( 'selected_menu_text_color' );
		$icons       = $this->color( 'sidebar_icons_color' );

		$css = '';

		if ( '' !== $bg ) {
			$css .= '#adminmenu,#adminmenu .wp-submenu,#adminmenuback,#adminmenuwrap{background:' . $bg . '!important}';
		}
		if ( '' !== $text ) {
			$css .= '#adminmenu .wp-submenu a,#adminmenu a,#collapse-button .collapse-button-label{color:' . $text . '!important}';
		}
		if ( '' !== $text_hover ) {
			$css .= '#adminmenu .wp-submenu a:hover,#adminmenu a:hover,#adminmenu li.current a.menu-top:hover,#collapse-button:is(:hover,:focus) .collapse-button-label{color:' . $text_hover . '!important}';
		}

		$active_sel = '#adminmenu .wp-has-current-submenu a.wp-has-current-submenu,#adminmenu a.wp-menu-arrow:focus,#adminmenu a.wp-menu-arrow:hover,#adminmenu a:focus .wp-menu-arrow,#adminmenu li.current a.menu-top,.folded #adminmenu .wp-has-current-submenu .wp-menu-arrow,.folded #adminmenu a.wp-has-current-submenu .wp-menu-arrow,.wp-menu-arrow';
		if ( '' !== $active_bg ) {
			$css .= $active_sel . '{background:' . $active_bg . '!important}';
		}
		if ( '' !== $active_text ) {
			$css .= $active_sel . '{color:' . $active_text . '!important}';
		}
		if ( '' !== $bg_hover ) {
			$css .= '#adminmenu li.current a.menu-top:hover,#adminmenu li.menu-top:hover,#adminmenu li.opensub>a.menu-top,#adminmenu li>a.menu-top:focus{background:' . $bg_hover . '!important}';
		}
		if ( '' !== $icons ) {
			$css .= 'body.wp-admin #adminmenu div.wp-menu-image:before,body.wp-admin #collapse-button .collapse-button-icon{color:' . $icons . '!important}';
		}
		if ( '' !== $active_text ) {
			$css .= '#adminmenu .current div.wp-menu-image:before,#adminmenu .wp-has-current-submenu div.wp-menu-image:before,#adminmenu a.current:hover div.wp-menu-image:before,#adminmenu a.wp-has-current-submenu:hover div.wp-menu-image:before,#adminmenu li.wp-has-current-submenu a:focus div.wp-menu-image:before,#adminmenu li.wp-has-current-submenu.opensub div.wp-menu-image:before,#adminmenu li.wp-has-current-submenu:hover div.wp-menu-image:before,#collapse-button:is(:hover,:focus) .collapse-button-icon{color:' . $active_text . '!important}';
		}
		if ( '' !== $text_hover ) {
			$css .= '#adminmenu li a:focus div.wp-menu-image:before,#adminmenu li.opensub div.wp-menu-image:before,#adminmenu li:hover div.wp-menu-image:before{color:' . $text_hover . '!important}';
		}
		// Legacy quirk kept on purpose: the submenu flyout shares the hover background.
		if ( '' !== $bg_hover ) {
			$css .= '#adminmenu .wp-submenu{background:' . $bg_hover . '!important}';
		}

		if ( $this->truthy( 'hide_sidebar_icons' ) ) {
			$css .= '@media screen and (min-width:961px){'
				. 'body.wp-admin.hide-sidebar-icons:not(.folded) #adminmenu div.wp-menu-image:before,body.wp-admin.hide-sidebar-icons:not(.folded) #collapse-button .collapse-button-icon{display:none!important}'
				. 'body.wp-admin.hide-sidebar-icons:not(.folded) #adminmenu div.wp-menu-image{width:0!important;background:0 0!important}'
				. 'body.wp-admin.hide-sidebar-icons:not(.folded) #adminmenu div.wp-menu-name,body.wp-admin.hide-sidebar-icons:not(.folded) #collapse-button .collapse-button-label{padding:8px!important}'
				. '}';
		}

		$width = (int) $this->settings->get( 'sidebar_width', self::DEFAULT_SIDEBAR_WIDTH );
		if ( $width > 0 && self::DEFAULT_SIDEBAR_WIDTH !== $width ) {
			$w = max( 120, min( 300, $width ) ) . 'px';
			// Not applied to the Menu Organizer horizontal layout (it has no sidebar).
			$b = 'body.wp-admin:not(.folded):not(.uxs-amo-horizontal)';

			$css .= '@media screen and (min-width:961px){'
				. $b . ' #adminmenu,' . $b . ' #adminmenu .wp-submenu,' . $b . ' #adminmenuback,' . $b . ' #adminmenuwrap{width:' . $w . '!important}'
				. $b . ':not(.is-fullscreen-mode) #wpcontent,' . $b . ':not(.is-fullscreen-mode) #wpfooter{margin-left:' . $w . '!important}'
				. $b . ' #adminmenu .wp-not-current-submenu .wp-submenu,' . $b . ':not(.is-fullscreen-mode) .interface-interface-skeleton{left:' . $w . '!important}'
				. $b . ' #adminmenu .wp-has-current-submenu .wp-submenu,' . $b . ' #adminmenu .wp-not-current-submenu .wp-submenu{min-width:' . $w . '!important}'
				. $b . ' #e-admin-top-bar-root,' . $b . ' .woocommerce-layout__header{width:calc(100% - ' . $w . ')}'
				. '}';
		}

		return $css;
	}
}
