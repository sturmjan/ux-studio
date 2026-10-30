<?php
/**
 * Source: All 404 Redirect to Homepage, Redirect 404 to Homepage.
 *
 * @package UxStudio
 */

namespace UxStudio\Core\Migration\Sources;

use UxStudio\Core\Migration\AbstractSource;
use UxStudio\Core\Migration\Mapping;

defined( 'ABSPATH' ) || exit;

/**
 * The module has no settings (it always redirects a 404 to the home page with a 301),
 * so this source only reports where the plugin differs from that.
 * Plugin option `options-404-redirect-group` { p404_status: '1' on, p404_redirect_to: URL }.
 */
final class Redirect404Source extends AbstractSource {

	protected function option_names(): array {
		return 'all-404-redirect-to-homepage' === $this->slug ? array( 'options-404-redirect-group' ) : array();
	}

	protected function map( bool $active ): Mapping {
		$m = new Mapping();
		$m->note( __( 'The module has no settings: it sends every 404 page to the home page with a 301 redirect.', 'ux-studio' ) );

		$o = $this->opt( 'options-404-redirect-group' );
		if ( 'all-404-redirect-to-homepage' !== $this->slug || ! is_array( $o ) ) {
			return $m;
		}
		if ( isset( $o['p404_status'] ) && '1' !== (string) $o['p404_status'] ) {
			$m->warn( __( 'The redirect is turned off in the plugin, but the module will always redirect once it is on.', 'ux-studio' ) );
		}
		$target = trim( (string) ( $o['p404_redirect_to'] ?? '' ) );
		if ( '' !== $target ) {
			$home = array( untrailingslashit( home_url() ), untrailingslashit( site_url() ) );
			if ( ! in_array( untrailingslashit( $target ), $home, true ) ) {
				$m->lossy(
					sprintf(
						/* translators: %s: URL. */
						__( 'The plugin redirects to %s, the module always redirects to the home page.', 'ux-studio' ),
						$target
					)
				);
			}
		}
		$m->lossy( __( 'The log of redirected links and the e-mail notifications of the plugin do not exist in the module.', 'ux-studio' ) );
		return $m;
	}
}
