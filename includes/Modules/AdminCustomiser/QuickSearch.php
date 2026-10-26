<?php
/**
 * Quick Search: an admin-bar search field with cross-content fuzzy search
 * (posts, terms, users, media, UX Studio settings, plus a few third-party
 * plugin integrations). This class only renders the admin-bar input and
 * enqueues the frontend widget; the actual search runs behind the REST
 * endpoint in MiscRestController, backed by QuickSearch\Integrations and
 * QuickSearch\ThirdPartyIntegrations.
 *
 * Ported from the legacy admin-customiser module (QuickSearch.php).
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\AdminCustomiser;

defined( 'ABSPATH' ) || exit;

final class QuickSearch {

	/**
	 * Register hooks. Only instantiated when quick_search_enabled=true.
	 */
	public function register(): void {
		add_action( 'admin_bar_menu', array( $this, 'add_search_input' ), 1000 );
		// One search control, not two: core's command palette button (WordPress 7.0+) goes, the palette itself
		// stays reachable from the last entry of our dropdown. Only while this class is registered, i.e. while
		// the quick search is switched on; with it off core is untouched.
		add_action( 'admin_bar_menu', array( $this, 'remove_core_palette_button' ), 999 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	/**
	 * Inject the search field markup into the admin bar (top-secondary).
	 *
	 * @param \WP_Admin_Bar $wp_admin_bar Admin bar instance.
	 */
	public function add_search_input( $wp_admin_bar ): void {
		if ( ! is_admin_bar_showing() ) {
			return;
		}

		$label = esc_attr__( 'Search', 'ux-studio' );

		$search_input = sprintf(
			'<div class="uxstudio-qs" data-collapsed="true">
				<button type="button" class="uxstudio-qs__toggle" aria-label="%1$s" aria-expanded="false" aria-controls="uxstudio-qs-field">
					<svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true" focusable="false"><path fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" d="M11 4a7 7 0 1 0 4.546 12.32l3.567 3.567a1 1 0 0 0 1.414-1.414l-3.567-3.567A7 7 0 0 0 11 4z"/></svg>
				</button>
				<div class="uxstudio-qs__field" id="uxstudio-qs-field">
					<label for="uxstudio-qs-input" class="screen-reader-text">%1$s</label>
					<input class="uxstudio-qs__input" id="uxstudio-qs-input" type="text" role="combobox" aria-autocomplete="list" aria-haspopup="listbox" aria-expanded="false" aria-controls="uxstudio-qs-results" autocomplete="off" spellcheck="false" placeholder="%2$s">
					<span class="uxstudio-qs__spinner" aria-hidden="true" hidden></span>
				</div>
				<div class="uxstudio-qs__panel-container">
					<ul class="uxstudio-qs__panel" id="uxstudio-qs-results" role="listbox" aria-label="%3$s"></ul>
					<span aria-live="polite" class="uxstudio-qs__message screen-reader-text" data-message-type=""></span>
				</div>
			</div>',
			$label,
			esc_attr__( 'Search the site and settings...', 'ux-studio' ),
			esc_attr__( 'Search results', 'ux-studio' )
		);

		$wp_admin_bar->add_node(
			array(
				'id'     => 'uxstudio-admin-bar-search',
				'parent' => 'top-secondary',
				'title'  => $search_input,
			)
		);
	}

	/**
	 * Remove core's command palette button from the admin bar (see register()).
	 *
	 * @param \WP_Admin_Bar $wp_admin_bar Admin bar instance.
	 */
	public function remove_core_palette_button( $wp_admin_bar ): void {
		$wp_admin_bar->remove_node( 'command-palette' );
	}

	/**
	 * Enqueue the quick-search JS/CSS. Only runs when the admin bar is showing.
	 */
	public function enqueue_assets(): void {
		if ( ! is_admin_bar_showing() ) {
			return;
		}

		$css_rel = 'assets/css/admin-customiser-quick-search.css';
		$js_rel  = 'assets/js/admin-customiser-quick-search.js';
		$css_path = UXSTUDIO_PATH . $css_rel;
		$js_path  = UXSTUDIO_PATH . $js_rel;
		$version  = (string) max(
			file_exists( $css_path ) ? filemtime( $css_path ) : 0,
			file_exists( $js_path ) ? filemtime( $js_path ) : 0
		);
		if ( '' === $version || '0' === $version ) {
			$version = defined( 'UXSTUDIO_VERSION' ) ? UXSTUDIO_VERSION : '1.0.0';
		}

		wp_enqueue_style( 'uxstudio-admin-customiser-quick-search', UXSTUDIO_URL . $css_rel, array(), $version );
		wp_enqueue_script( 'uxstudio-admin-customiser-quick-search', UXSTUDIO_URL . $js_rel, array(), $version, true );

		wp_localize_script(
			'uxstudio-admin-customiser-quick-search',
			'uxstudioQuickSearch',
			array(
				'restUrl' => esc_url_raw( rest_url( 'uxstudio/v1/admin-customiser/quick-search' ) ),
				'nonce'   => wp_create_nonce( 'wp_rest' ),
				// Ctrl+K / Cmd+K is taken over in wp-admin only (the front end has no core palette to replace).
				'isAdmin' => is_admin() ? '1' : '',
			)
		);

		wp_localize_script(
			'uxstudio-admin-customiser-quick-search',
			'uxstudioQuickSearchI18n',
			array(
				'search'    => __( 'Search', 'ux-studio' ),
				'noResults' => __( 'No results.', 'ux-studio' ),
				'error'     => __( 'Search failed.', 'ux-studio' ),
				'commands'  => __( 'WordPress commands...', 'ux-studio' ),
			)
		);
	}
}
