<?php
/**
 * Wires up every admin-customiser feature EXCEPT Menu Organizer (owned by a
 * different concurrent port - see Module.php): admin bar customisation,
 * quick search, login screen, icon replacer, hide admin notices, server
 * clock, and the generic theming/settings bits (primary color CSS variable,
 * admin footer text, screen-options/help-tab visibility).
 *
 * Not auto-registered - called explicitly from Module::boot() once both
 * halves of this module have landed (same pattern as AiAssistant's
 * ChatBootstrap/KnowledgeBootstrap/etc., see Module.php there).
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\AdminCustomiser;

use UxStudio\Core\Settings;

defined( 'ABSPATH' ) || exit;

final class MiscBootstrap {

	/**
	 * Register every hook gated by its settings toggle, plus the always-on
	 * theming bits (primary color, footer text, screen-options/help-tab).
	 */
	public static function register(): void {
		$settings = new Settings( 'uxstudio_admin_customiser' );

		// Quick Search REST endpoint is always registered - editors/admins may
		// call it even if the admin-bar widget itself is currently disabled.
		add_action( 'rest_api_init', array( self::class, 'register_rest_routes' ) );

		// Always-on theming: CSS variables, footer text, screen-options/help-tab.
		add_action( 'wp_head', array( self::class, 'print_css_variables' ), 10 );
		add_action( 'admin_head', array( self::class, 'print_css_variables' ), 10 );
		add_action( 'login_head', array( self::class, 'print_css_variables' ), 10 );

		add_action( 'admin_head', array( self::class, 'handle_admin_features_visibility' ) );
		add_filter( 'admin_footer_text', array( self::class, 'custom_admin_footer_text' ), 11 );

		if ( $settings->get( 'admin_bar_enabled', false ) ) {
			( new AdminBar() )->register();
		}

		if ( $settings->get( 'quick_search_enabled', true ) ) {
			( new QuickSearch() )->register();
		}

		if ( $settings->get( 'icon_replacer_enabled', false ) ) {
			( new IconReplacer() )->register();
		}

		if ( $settings->get( 'hide_notices_enabled', false ) ) {
			$relocate = (bool) $settings->get( 'hide_notices_relocate', false );
			( new HideAdminNotices( $relocate ) )->register();
		}

		if ( $settings->get( 'server_clock_enabled', false ) ) {
			$position = (string) $settings->get( 'server_clock_position', 'admin-bar-pill' );
			( new ServerClock( $position ) )->register();
		}

		if ( $settings->get( 'login_customization_enabled', false ) ) {
			( new Login( $settings ) )->register();
		}
	}

	/**
	 * Register the "misc" REST controller (Quick Search).
	 */
	public static function register_rest_routes(): void {
		( new MiscRestController() )->register_routes();
	}

	/**
	 * Print `--uxs-admin-primary`/`--uxs-admin-primary-hover` CSS
	 * custom properties from the `primary_color` setting. Consumed by the
	 * admin bar "+" dropdown, quick search panel and login button styling.
	 * No-op when the setting is empty (nothing to override).
	 */
	public static function print_css_variables(): void {
		$primary = (string) ( new Settings( 'uxstudio_admin_customiser' ) )->get( 'primary_color', '' );
		if ( '' === $primary || ! preg_match( '/^#[0-9a-fA-F]{3,8}$/', $primary ) ) {
			return;
		}

		$css = ':root{--uxs-admin-primary:' . esc_attr( $primary ) . ';}';
		$css .= '#wpadminbar .uxstudio-ab-plus:hover, #wpadminbar .uxstudio-ab-sitelink:hover { color: var(--uxs-admin-primary) !important; }';
		$css .= 'body.login #wp-submit { background: var(--uxs-admin-primary) !important; border-color: var(--uxs-admin-primary) !important; }';

		printf( '<style id="uxstudio-admin-customiser-vars">%s</style>', $css ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	/**
	 * Hide the Help tab and/or Screen Options tab per the
	 * `hide_help_tab`/`hide_screen_options` toggles. Applies on every admin
	 * screen, independent of any other admin-customiser feature toggle.
	 */
	public static function handle_admin_features_visibility(): void {
		$settings = new Settings( 'uxstudio_admin_customiser' );

		if ( $settings->get( 'hide_help_tab', false ) ) {
			$screen = get_current_screen();
			if ( $screen ) {
				$screen->remove_help_tabs();
			}
		}

		if ( $settings->get( 'hide_screen_options', false ) ) {
			echo '<style>#screen-options-link-wrap { display: none !important; }</style>';
			add_filter( 'screen_options_show_screen', '__return_false' );
		}
	}

	/**
	 * Override the admin footer text with `admin_footer_text`, when set.
	 *
	 * @param string $text Default admin footer text.
	 */
	public static function custom_admin_footer_text( $text ) {
		$custom = (string) ( new Settings( 'uxstudio_admin_customiser' ) )->get( 'admin_footer_text', '' );
		return '' !== trim( $custom ) ? wp_kses_post( $custom ) : $text;
	}
}
