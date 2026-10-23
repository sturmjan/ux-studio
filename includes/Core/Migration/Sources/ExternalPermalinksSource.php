<?php
/**
 * Source: Page Links To.
 *
 * @package UxStudio
 */

namespace UxStudio\Core\Migration\Sources;

use UxStudio\Core\Migration\AbstractSource;
use UxStudio\Core\Migration\Mapping;

defined( 'ABSPATH' ) || exit;

/**
 * Target module `external-permalinks`. Page Links To keeps the address in the post meta `_links_to`
 * (new-tab flag `_links_to_target`, redirect type `_links_to_type`) - the very same field the module
 * reads. The addresses therefore stay where they are and nothing is copied; what the module still needs
 * is the list of post types it applies to (setting `post_types`), built from the posts that have an address.
 *
 * BEHAVIOUR-CHANGING: the setting turns links into redirects, so with the module already on it only
 * waits in the staging area.
 */
final class ExternalPermalinksSource extends AbstractSource {

	private const MODULE = 'external-permalinks';

	/** @var array{types:array<string, int>,new_tab:int,permanent:int}|null */
	private ?array $found = null;

	protected function has_data(): bool {
		return array() !== $this->scan()['types'];
	}

	/**
	 * Posts with an external address, by post type.
	 *
	 * @return array{types:array<string, int>,new_tab:int,permanent:int}
	 */
	private function scan(): array {
		if ( null !== $this->found ) {
			return $this->found;
		}
		global $wpdb;
		$out  = array(
			'types'     => array(),
			'new_tab'   => 0,
			'permanent' => 0,
		);
		$rows = $wpdb->get_results( "SELECT p.ID, p.post_type FROM {$wpdb->postmeta} m JOIN {$wpdb->posts} p ON p.ID = m.post_id WHERE m.meta_key = '_links_to' AND m.meta_value <> '' AND p.post_status <> 'trash'", ARRAY_A ); // phpcs:ignore WordPress.DB
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$type                  = (string) $row['post_type'];
			$out['types'][ $type ] = ( $out['types'][ $type ] ?? 0 ) + 1;
			if ( '_blank' === (string) get_post_meta( (int) $row['ID'], '_links_to_target', true ) ) {
				++$out['new_tab'];
			}
			if ( 301 === (int) get_post_meta( (int) $row['ID'], '_links_to_type', true ) ) {
				++$out['permanent'];
			}
		}
		$this->found = $out;
		return $out;
	}

	public function forget(): void {
		$this->found = null;
		parent::forget();
	}

	protected function map( bool $active ): Mapping {
		$m = new Mapping();
		$s = $this->scan();
		if ( ! $s['types'] ) {
			$m->note( __( 'No post or page has an external address set by this plugin, there is nothing to transfer.', 'ux-studio' ) );
			return $m;
		}
		$m->set( self::MODULE, 'post_types', array_keys( $s['types'] ), __( 'Post types with external permalinks', 'ux-studio' ), '_links_to' );
		$m->note( __( 'The addresses stay in the posts, because the module reads the same field as the plugin. Only the list of content types is set.', 'ux-studio' ) );
		if ( $s['new_tab'] > 0 ) {
			$m->lossy(
				sprintf(
					/* translators: %d: number of links. */
					__( '%d links were set to open in a new tab. The module has no such option, they open in the same tab.', 'ux-studio' ),
					$s['new_tab']
				)
			);
		}
		if ( $s['permanent'] > 0 ) {
			$m->lossy(
				sprintf(
					/* translators: %d: number of links. */
					__( '%d links redirected permanently (301). The module redirects temporarily (302).', 'ux-studio' ),
					$s['permanent']
				)
			);
		}
		return $m;
	}

	protected function data_plan( array $options ): array {
		$rows = array();
		foreach ( $this->scan()['types'] as $type => $n ) {
			$rows[] = array(
				'key'   => 'type:' . $type,
				'label' => sprintf(
					/* translators: %s: post type name. */
					__( 'Posts with an external address (%s)', 'ux-studio' ),
					$type
				),
				'count' => $n,
			);
		}
		return $rows;
	}
}
