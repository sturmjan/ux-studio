<?php
/**
 * Source: Auto Upload Images.
 *
 * @package UxStudio
 */

namespace UxStudio\Core\Migration\Sources;

use UxStudio\Core\Migration\AbstractSource;
use UxStudio\Core\Migration\Mapping;

defined( 'ABSPATH' ) || exit;

/**
 * Plugin option `aui-setting` { base_url, image_name, alt_name, max_width, max_height,
 * exclude_urls }. The module's settings (size limit, notices, safety net, images per
 * save) have nothing in common with them, so nothing is transferred; what the plugin
 * had configured is listed as lossy.
 */
final class AutoImageUploadSource extends AbstractSource {

	protected function option_names(): array {
		return array( 'aui-setting' );
	}

	protected function map( bool $active ): Mapping {
		$m = new Mapping();
		$m->note( __( 'None of the plugin settings has an equivalent in the module, so nothing is transferred. The module downloads external images on save just like the plugin.', 'ux-studio' ) );

		$o = $this->opt( 'aui-setting' );
		if ( ! is_array( $o ) ) {
			return $m;
		}
		$lost = array();
		if ( isset( $o['image_name'] ) && '%filename%' !== $o['image_name'] ) {
			$lost[] = sprintf(
				/* translators: %s: file name pattern. */
				__( 'file name pattern "%s"', 'ux-studio' ),
				(string) $o['image_name']
			);
		}
		if ( isset( $o['alt_name'] ) && '%image_alt%' !== $o['alt_name'] ) {
			$lost[] = sprintf(
				/* translators: %s: alt text pattern. */
				__( 'alt text pattern "%s"', 'ux-studio' ),
				(string) $o['alt_name']
			);
		}
		if ( ! empty( $o['max_width'] ) || ! empty( $o['max_height'] ) ) {
			$lost[] = __( 'maximum image size in pixels', 'ux-studio' );
		}
		if ( ! empty( $o['exclude_urls'] ) ) {
			$lost[] = __( 'list of excluded addresses', 'ux-studio' );
		}
		if ( $lost ) {
			$m->lossy(
				sprintf(
					/* translators: %s: comma-separated list of plugin settings. */
					__( 'Configured in the plugin and not available in the module: %s.', 'ux-studio' ),
					implode( ', ', $lost )
				)
			);
		}
		return $m;
	}
}
