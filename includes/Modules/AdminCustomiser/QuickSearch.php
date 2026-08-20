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

		$search_input = sprintf(
			'<div class="uxstudio-qs" data-collapsed="true">
				<button type="button" class="uxstudio-qs__toggle" aria-label="%1$s" aria-expanded="false" aria-controls="uxstudio-qs-input">
					<svg viewBox="0 0 24 24" width="16" height="16" aria-hidden="true" focusable="false"><path fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" d="M11 4a7 7 0 1 0 4.546 12.32l3.567 3.567a1 1 0 0 0 1.414-1.414l-3.567-3.567A7 7 0 0 0 11 4z"/></svg>
				</button>
				<div class="uxstudio-qs__wrapper" data-expanded="false">
					<label id="uxstudio-qs-label" for="uxstudio-qs-input" class="screen-reader-text">%1$s</label>
					<input class="uxstudio-qs__input" id="uxstudio-qs-input" type="text" autocomplete="off" placeholder="%1$s">
					<span class="uxstudio-qs__spinner" aria-hidden="true" hidden></span>
				</div>
				<div class="uxstudio-qs__panel-container" role="dialog" aria-labelledby="uxstudio-qs-input">
					<ul class="uxstudio-qs__panel" id="uxstudio-qs-results"></ul>
					<span aria-live="polite" class="uxstudio-qs__message screen-reader-text" data-message-type=""></span>
				</div>
			</div>',
			esc_attr__( 'Search...', 'ux-studio' )
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
			)
		);
	}
}
