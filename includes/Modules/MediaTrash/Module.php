<?php
/**
 * Media Trash module.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\MediaTrash;

use UxStudio\Modules\BaseModule;

defined( 'ABSPATH' ) || exit;

/**
 * Enables the trash for media attachments instead of permanent deletion.
 *
 * The legacy module wrote the MEDIA_TRASH constant into wp-config.php; here
 * we define it at boot time instead (plugins_loaded runs before wp-admin
 * reads the constant when handling attachment delete actions).
 */
final class Module extends BaseModule {

	/**
	 * Register hooks.
	 */
	public function boot(): void {
		if ( ! defined( 'MEDIA_TRASH' ) ) {
			define( 'MEDIA_TRASH', true );
		}
	}
}
