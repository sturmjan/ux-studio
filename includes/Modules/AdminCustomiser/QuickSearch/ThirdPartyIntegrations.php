<?php
/**
 * Third-party plugin integrations for Quick Search (WooCommerce, ACF).
 * Ported from the legacy admin-customiser module
 * (includes/quick-search/ThirdPartyIntegrations.php); Fluent Forms was
 * dropped from the port (not in wide use across UX Studio sites) but new
 * integrations can be added the same way.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\AdminCustomiser\QuickSearch;

defined( 'ABSPATH' ) || exit;

final class ThirdPartyIntegrations {

	/**
	 * Run every active third-party integration and merge their results.
	 *
	 * @param string $search_term Search query.
	 */
	public function search( string $search_term ): array {
		$results = array();

		if ( class_exists( 'WooCommerce' ) ) {
			$results = array_merge( $results, $this->search_woocommerce( $search_term ) );
		}
		if ( class_exists( 'ACF' ) ) {
			$results = array_merge( $results, $this->search_acf( $search_term ) );
		}

		return apply_filters( 'uxstudio/admin_customiser/quick_search_third_party_results', $results, $search_term );
	}

	/**
	 * WooCommerce shortcuts (always shown, not filtered by search term - matches
	 * legacy behaviour of surfacing quick links for common WC screens).
	 *
	 * @param string $search_term Search query.
	 */
	private function search_woocommerce( string $search_term ): array {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return array();
		}

		return array(
			__( 'WooCommerce', 'ux-studio' ) => array(
				array( 'label' => __( 'Products', 'ux-studio' ), 'links' => array( array( 'label' => __( 'Open', 'ux-studio' ), 'url' => admin_url( 'edit.php?post_type=product' ) ) ) ),
				array( 'label' => __( 'Categories', 'ux-studio' ), 'links' => array( array( 'label' => __( 'Open', 'ux-studio' ), 'url' => admin_url( 'edit-tags.php?taxonomy=product_cat' ) ) ) ),
			),
		);
	}

	/**
	 * Advanced Custom Fields shortcuts.
	 *
	 * @param string $search_term Search query.
	 */
	private function search_acf( string $search_term ): array {
		if ( ! current_user_can( 'manage_options' ) ) {
			return array();
		}

		return array(
			__( 'Advanced Custom Fields', 'ux-studio' ) => array(
				array( 'label' => __( 'Field Groups', 'ux-studio' ), 'links' => array( array( 'label' => __( 'Open', 'ux-studio' ), 'url' => admin_url( 'edit.php?post_type=acf-field-group' ) ) ) ),
				array( 'label' => __( 'Post Types', 'ux-studio' ), 'links' => array( array( 'label' => __( 'Open', 'ux-studio' ), 'url' => admin_url( 'edit.php?post_type=acf-post-type' ) ) ) ),
				array( 'label' => __( 'Taxonomies', 'ux-studio' ), 'links' => array( array( 'label' => __( 'Open', 'ux-studio' ), 'url' => admin_url( 'edit.php?post_type=acf-taxonomy' ) ) ) ),
			),
		);
	}
}
