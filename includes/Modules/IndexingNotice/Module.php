<?php
/**
 * Indexing Notice module.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\IndexingNotice;

use UxStudio\Modules\BaseModule;

defined( 'ABSPATH' ) || exit;

/**
 * Adds a prominent admin bar warning while "Discourage search engines from
 * indexing this site" is enabled in Settings > Reading. Ported from the
 * legacy module.
 */
final class Module extends BaseModule {

	/**
	 * Register hooks.
	 */
	public function boot(): void {
		add_action( 'admin_bar_menu', array( $this, 'add_notice' ), 1000 );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_styles' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_styles' ) );
	}

	/**
	 * Whether the notice should be shown for the current request/user.
	 */
	private function should_run(): bool {
		if ( ! current_user_can( 'manage_options' ) ) {
			return false;
		}
		if ( ! is_admin_bar_showing() ) {
			return false;
		}
		return 0 === (int) get_option( 'blog_public' );
	}

	/**
	 * Add the warning node to the admin bar.
	 *
	 * @param \WP_Admin_Bar $wp_admin_bar Admin bar instance.
	 */
	public function add_notice( \WP_Admin_Bar $wp_admin_bar ): void {
		if ( ! $this->should_run() ) {
			return;
		}

		$wp_admin_bar->add_node(
			array(
				'id'     => 'uxstudio-indexing-notice',
				'parent' => 'top-secondary',
				'title'  => __( 'Search Engines Discouraged', 'ux-studio' ),
				'href'   => admin_url( 'options-reading.php' ),
			)
		);
	}

	/**
	 * Attach the highlight styling to the admin bar stylesheet.
	 */
	public function enqueue_styles(): void {
		if ( ! $this->should_run() ) {
			return;
		}

		$css = '#wp-admin-bar-uxstudio-indexing-notice a{color:#fff!important;background:#e0281f!important;transition:.1s ease-in-out}'
			. '#wp-admin-bar-uxstudio-indexing-notice a:focus,#wp-admin-bar-uxstudio-indexing-notice a:hover{text-decoration:underline;background:#bd2b24!important}';

		wp_add_inline_style( 'admin-bar', $css );
	}
}
