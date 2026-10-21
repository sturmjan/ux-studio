<?php
/**
 * Source: plugins without settings that matter - the module simply takes over.
 *
 * @package UxStudio
 */

namespace UxStudio\Core\Migration\Sources;

use UxStudio\Core\Migration\AbstractSource;
use UxStudio\Core\Migration\Mapping;

defined( 'ABSPATH' ) || exit;

/**
 * Covers: classic-widgets, duplicate-menu, enable-media-replace, post-type-switcher,
 * user-switching, wp-rollback, instant-images, reveal-ids-for-wp-admin-25, show-ids,
 * simple-page-ordering. Plan = "nothing to transfer, the module covers it".
 */
final class NoSettingsSource extends AbstractSource {

	protected function map( bool $active ): Mapping {
		$m = new Mapping();
		if ( 'simple-page-ordering' === $this->slug ) {
			$m->note( __( 'No settings to transfer. The page order is stored in WordPress itself (menu_order), so the module continues with the order you already have.', 'ux-studio' ) );
		} else {
			$m->note( __( 'No settings to transfer, the module takes over the job as it is.', 'ux-studio' ) );
		}
		return $m;
	}
}
