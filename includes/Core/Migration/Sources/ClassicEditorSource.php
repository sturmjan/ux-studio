<?php
/**
 * Source: Classic Editor.
 *
 * @package UxStudio
 */

namespace UxStudio\Core\Migration\Sources;

use UxStudio\Core\Migration\AbstractSource;
use UxStudio\Core\Migration\Mapping;

defined( 'ABSPATH' ) || exit;

/**
 * Plugin options `classic-editor-replace` (`classic` | `block` | `no-replace`, plus the old
 * `replace`) and `classic-editor-allow-users` (`allow` | `disallow`).
 * The module only forces the classic editor (empty post type list = everywhere).
 */
final class ClassicEditorSource extends AbstractSource {

	protected function option_names(): array {
		return array( 'classic-editor-replace', 'classic-editor-allow-users' );
	}

	protected function map( bool $active ): Mapping {
		$m       = new Mapping();
		$replace = $this->opt( 'classic-editor-replace' );
		$allow   = $this->opt( 'classic-editor-allow-users' );

		if ( null === $replace ) {
			if ( ! $active ) {
				$m->note( __( 'The plugin has no stored settings, there is nothing to transfer.', 'ux-studio' ) );
				return $m;
			}
			$replace = 'classic'; // The plugin's own default on activation.
		}

		if ( in_array( (string) $replace, array( 'block', 'no-replace' ), true ) ) {
			$m->note( __( 'The plugin keeps the block editor as the default editor. The module would force the classic editor, so nothing is transferred - do not switch the module on if you want to keep the block editor.', 'ux-studio' ) );
		} else {
			$m->set( 'classic-editor', 'post_types', array(), __( 'Post types (none selected = all)', 'ux-studio' ), 'classic-editor-replace' );
			$m->note( __( 'The plugin replaces the block editor everywhere; the module does the same for all post types.', 'ux-studio' ) );
		}

		if ( 'allow' === $allow ) {
			$m->lossy( __( 'The plugin lets each user choose their editor (and remembers the choice per post). The module has no per-user choice.', 'ux-studio' ) );
		}
		return $m;
	}
}
