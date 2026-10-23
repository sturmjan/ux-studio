<?php
/**
 * Source: WP External Links.
 *
 * @package UxStudio
 */

namespace UxStudio\Core\Migration\Sources;

use UxStudio\Core\Migration\AbstractSource;
use UxStudio\Core\Migration\Mapping;

defined( 'ABSPATH' ) || exit;

/**
 * Plugin options `wpel-external-link-settings` { icon_type, target, rel_*, ... } and
 * `wpel-exceptions-settings` { ignore_classes, ... }. The module always opens external links
 * in a new tab with rel noopener noreferrer nofollow and an arrow icon; it has
 * `exclude_classes` and `disable_arrow`.
 */
final class ExternalLinksSource extends AbstractSource {

	private const MODULE = 'link-manager';

	protected function option_names(): array {
		return array( 'wpel-external-link-settings', 'wpel-exceptions-settings' );
	}

	protected function map( bool $active ): Mapping {
		$m   = new Mapping();
		$ext = $this->opt( 'wpel-external-link-settings' );
		$exc = $this->opt( 'wpel-exceptions-settings' );
		if ( ! is_array( $ext ) && ! is_array( $exc ) ) {
			$m->note( __( 'The plugin has no stored settings, there is nothing to transfer.', 'ux-studio' ) );
			return $m;
		}

		if ( is_array( $exc ) && '' !== trim( (string) ( $exc['ignore_classes'] ?? '' ) ) ) {
			$m->set( self::MODULE, 'exclude_classes', trim( (string) $exc['ignore_classes'] ), __( 'Exclude classes', 'ux-studio' ), 'wpel-exceptions-settings:ignore_classes' );
		}
		if ( is_array( $ext ) && array_key_exists( 'icon_type', $ext ) ) {
			$m->set( self::MODULE, 'disable_arrow', '' === (string) $ext['icon_type'], __( 'Disable external link arrow', 'ux-studio' ), 'wpel-external-link-settings:icon_type' );
			if ( '' !== (string) $ext['icon_type'] ) {
				$m->lossy( __( 'The plugin icon (image, Dashicon, Font Awesome) is replaced by the module arrow.', 'ux-studio' ) );
			}
		}
		$m->lossy( __( 'The module always opens external links in a new tab and sets rel to noopener noreferrer nofollow. The plugin rules for target, rel, link titles and classes, the settings for internal links, URL exceptions and exit confirmation are not transferred.', 'ux-studio' ) );
		return $m;
	}
}
