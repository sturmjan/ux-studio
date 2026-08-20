<?php
/**
 * Classic Widgets module - disable the block-based widgets editor (ported from legacy module).
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\ClassicWidgets;

use UxStudio\Modules\BaseModule;

defined( 'ABSPATH' ) || exit;

/**
 * Restores the classic widgets screen and customizer widgets panel.
 */
final class Module extends BaseModule {

	/**
	 * Register hooks.
	 */
	public function boot(): void {
		add_filter( 'gutenberg_use_widgets_block_editor', '__return_false' );
		add_filter( 'use_widgets_block_editor', '__return_false' );
	}
}
