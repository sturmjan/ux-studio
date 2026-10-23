<?php
/**
 * Source: Post Types Order, Intuitive Custom Post Order (and Simple Page Ordering, which has no settings).
 *
 * @package UxStudio
 */

namespace UxStudio\Core\Migration\Sources;

use UxStudio\Core\Migration\AbstractSource;
use UxStudio\Core\Migration\Mapping;

defined( 'ABSPATH' ) || exit;

/**
 * Target module `post-type-order`: setting `post_types` (the post types with drag and drop ordering). The order
 * itself is core data (`menu_order` of the posts), so there is nothing to copy - both plugins and the module read
 * and write the same field.
 *
 *  - Post Types Order: option `cpto_options` { show_reorder_interfaces { post_type: show | hide } }.
 *  - Intuitive Custom Post Order: option `hicpo_options` { objects[] post types, tags[] taxonomies }.
 *
 * Ordering of terms (taxonomies) has no equivalent in the module and is reported.
 * The setting changes how lists are sorted, so with the module on it waits in the staging area.
 */
final class PostTypeOrderSource extends AbstractSource {

	private const MODULE = 'post-type-order';

	protected function option_names(): array {
		return 'post-types-order' === $this->slug ? array( 'cpto_options' ) : array( 'hicpo_options' );
	}

	protected function map( bool $active ): Mapping {
		$m     = new Mapping();
		$types = null;
		if ( 'post-types-order' === $this->slug ) {
			$o = $this->opt( 'cpto_options' );
			if ( is_array( $o ) && isset( $o['show_reorder_interfaces'] ) && is_array( $o['show_reorder_interfaces'] ) ) {
				$types = array();
				foreach ( $o['show_reorder_interfaces'] as $type => $mode ) {
					if ( 'show' === $mode ) {
						$types[] = (string) $type;
					}
				}
			}
			if ( is_array( $o ) && ! empty( $o['archive_drag_drop'] ) ) {
				$m->lossy( __( 'Drag and drop ordering on the archive pages of the front end is not transferred; the module orders in the admin lists.', 'ux-studio' ) );
			}
		} else {
			$o = $this->opt( 'hicpo_options' );
			if ( is_array( $o ) ) {
				$types = array_values( array_map( 'strval', (array) ( $o['objects'] ?? array() ) ) );
				if ( ! empty( $o['tags'] ) ) {
					$m->lossy(
						sprintf(
							/* translators: %s: list of taxonomies. */
							__( 'Ordering of terms (%s) has no equivalent in the module. The term order stays in the database but is not used.', 'ux-studio' ),
							implode( ', ', array_map( 'strval', (array) $o['tags'] ) )
						)
					);
				}
			}
		}
		if ( null === $types ) {
			$m->note( __( 'The plugin has no stored settings, there is nothing to transfer.', 'ux-studio' ) );
			return $m;
		}
		$m->set( self::MODULE, 'post_types', $types, __( 'Post types', 'ux-studio' ), $this->option_names()[0] );
		$m->note( __( 'The order of the posts itself is not copied, because the module uses the same menu order field as the plugin.', 'ux-studio' ) );
		return $m;
	}
}
