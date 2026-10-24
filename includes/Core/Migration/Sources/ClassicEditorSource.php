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

	public function smoke_checks(): array {
		$settings = $this->mapping()->settings['classic-editor'] ?? null;
		// With the choice left to the users there is no single editor to probe.
		if ( null === $settings || ! empty( $settings['allow_users'] ) ) {
			return array();
		}
		return array(
			array(
				'id'         => 'editor',
				'label'      => __( 'The classic editor replaces the block editor', 'ux-studio' ),
				'type'       => 'block_editor_off',
				'post_types' => array_values( (array) ( $settings['post_types'] ?? array() ) ),
			),
		);
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

		$block = in_array( (string) $replace, array( 'block', 'no-replace' ), true );
		if ( 'allow' === $allow ) {
			$m->set( 'classic-editor', 'allow_users', true, __( 'Users can choose their editor', 'ux-studio' ), 'classic-editor-allow-users' );
			$m->set( 'classic-editor', 'default_editor', $block ? 'block' : 'classic', __( 'Site default editor', 'ux-studio' ), 'classic-editor-replace' );
			if ( ! $block ) {
				$m->set( 'classic-editor', 'post_types', array(), __( 'Post types (none selected = all)', 'ux-studio' ), 'classic-editor-replace' );
			}
			$m->note( __( 'Users choose their editor, as in the plugin. The default editor of each user (user option classic-editor-settings) and the editor remembered for each post (post meta classic-editor-remember) are stored under the same keys, so the module uses them as they are.', 'ux-studio' ) );
			return $m;
		}

		if ( $block ) {
			$m->note( __( 'The plugin keeps the block editor as the default editor. The module would force the classic editor, so nothing is transferred - do not switch the module on if you want to keep the block editor.', 'ux-studio' ) );
		} else {
			$m->set( 'classic-editor', 'post_types', array(), __( 'Post types (none selected = all)', 'ux-studio' ), 'classic-editor-replace' );
			$m->set( 'classic-editor', 'default_editor', 'classic', __( 'Site default editor', 'ux-studio' ), 'classic-editor-replace' );
			$m->set( 'classic-editor', 'allow_users', false, __( 'Users can choose their editor', 'ux-studio' ), 'classic-editor-allow-users' );
			$m->note( __( 'The plugin replaces the block editor everywhere; the module does the same for all post types.', 'ux-studio' ) );
		}
		return $m;
	}
}
