<?php
/**
 * Redirect 404 to Homepage module.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\Redirect404ToHomepage;

use UxStudio\Modules\BaseModule;

defined( 'ABSPATH' ) || exit;

/**
 * Redirects 404 pages to the homepage (301) unless a known redirection
 * plugin already handled the request. Ported from the legacy module.
 */
final class Module extends BaseModule {

	/**
	 * Register hooks.
	 */
	public function boot(): void {
		add_action( 'template_redirect', array( $this, 'redirect_404_to_homepage' ), 9999 );
	}

	/**
	 * Redirect 404 pages to the homepage.
	 *
	 * Skips admin, cron and XML-RPC contexts, and requests already handled
	 * by a supported redirection plugin.
	 */
	public function redirect_404_to_homepage(): void {
		if ( ! is_404() || is_admin() || ( defined( 'DOING_CRON' ) && DOING_CRON ) || ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST ) ) {
			return;
		}

		if ( $this->is_redirected_by_other_plugins() ) {
			return;
		}

		wp_safe_redirect( home_url(), 301 );
		exit;
	}

	/**
	 * Whether another redirection plugin already redirected this request.
	 */
	private function is_redirected_by_other_plugins(): bool {
		foreach ( $this->get_supported_plugins() as $plugin ) {
			if ( $plugin['active']() ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Detection callbacks for supported redirection plugins.
	 *
	 * @return array<int, array{class:string, active:callable}>
	 */
	public function get_supported_plugins(): array {
		return array(
			// Redirection plugin.
			array(
				'class'  => 'Redirection',
				'active' => static function (): bool {
					return class_exists( 'Redirection' )
						&& method_exists( 'Redirection', 'get_instance' )
						&& \Redirection::get_instance()->is_redirected();
				},
			),
			// Rank Math SEO.
			array(
				'class'  => 'RankMath',
				'active' => static function (): bool {
					return function_exists( 'rank_math_redirection' )
						&& rank_math_redirection()->redirect->is_redirected();
				},
			),
			// Yoast SEO.
			array(
				'class'  => 'WPSEO_Redirect_Manager',
				'active' => static function (): bool {
					if ( ! defined( 'WPSEO_VERSION' ) || ! class_exists( 'WPSEO_Redirect_Manager' ) ) {
						return false;
					}
					$redirect_manager = new \WPSEO_Redirect_Manager();
					return method_exists( $redirect_manager, 'is_redirected' )
						&& $redirect_manager->is_redirected();
				},
			),
			// All in One SEO Pack.
			array(
				'class'  => 'AIOSEOP_Redirection',
				'active' => static function (): bool {
					return class_exists( 'AIOSEOP_Redirection' )
						&& method_exists( 'AIOSEOP_Redirection', 'is_redirected' )
						&& \AIOSEOP_Redirection::is_redirected();
				},
			),
			// SEO Ultimate.
			array(
				'class'  => 'SEO_Ultimate_Module',
				'active' => static function (): bool {
					return class_exists( 'SEO_Ultimate_Module' )
						&& method_exists( 'SEO_Ultimate_Module', 'is_redirected' )
						&& \SEO_Ultimate_Module::is_redirected();
				},
			),
			// Simple 301 Redirects.
			array(
				'class'  => 'Simple301Redirects',
				'active' => static function (): bool {
					return class_exists( 'Simple301Redirects' )
						&& method_exists( 'Simple301Redirects', 'is_redirected' )
						&& \Simple301Redirects::is_redirected();
				},
			),
		);
	}
}
