<?php
/**
 * Source: Mapster WP Maps.
 *
 * @package UxStudio
 */

namespace UxStudio\Core\Migration\Sources;

use UxStudio\Core\Migration\AbstractSource;
use UxStudio\Core\Migration\Mapping;
use UxStudio\Modules\Maps\MapsterImport;
use UxStudio\Modules\Maps\Module as MapsModule;

defined( 'ABSPATH' ) || exit;

/**
 * Target module `maps`: every published `mapster-wp-map` becomes a map of the module (private post type
 * `uxstudio_map`), converted by MapsterImport - the same code bin/migrate-plugins.php uses. Markers, popups,
 * view, height and the Mapbox style are kept; the Mapbox token (the map's own, or the default one from the
 * Mapster settings) goes into the module's encrypted token, unless the module already has one.
 *
 * Not transferred: lines and polygons (the module draws points only). With the option "use OpenStreetMap" a
 * Mapbox map is drawn with OpenStreetMap, which needs no token.
 *
 * Pages keep working: they embed `[mapster_wp_map id="N"]`, which the Maps module answers by itself (alias
 * registered on `init` while Mapster is not active, so from the request after the wizard deactivated Mapster),
 * looked up by the Mapster id kept on each imported map. Maps are private, inert data - nothing changes on the site
 * until the plugin is off - so they are written directly, never staged.
 */
final class MapsterSource extends AbstractSource {

	/** @var array<int, array<string, mixed>>|null Maps read from Mapster (without the choice of provider). */
	private ?array $found = null;

	/** @var array<int, string[]>|null Titles of the pages embedding each Mapster map. */
	private ?array $pages = null;

	protected function has_data(): bool {
		return array() !== MapsterImport::map_ids();
	}

	public function forget(): void {
		$this->found = null;
		$this->pages = null;
		parent::forget();
	}

	protected function data_kind(): string {
		return 'map';
	}

	protected function data_label(): string {
		return __( 'Maps', 'ux-studio' );
	}

	public function options(): array {
		return array(
			array(
				'key'     => 'switch_to_osm',
				'label'   => __( 'Draw Mapbox maps with OpenStreetMap instead (no token needed)', 'ux-studio' ),
				'help'    => __( 'Off: Mapbox maps stay Mapbox and the token is transferred. On: they use OpenStreetMap, the style and the token are not used.', 'ux-studio' ),
				'default' => false,
			),
		);
	}

	protected function changes_behavior(): bool {
		return false;
	}

	protected function extra_target_options(): array {
		return array( MapsModule::SECRET_MAPBOX );
	}

	/**
	 * Every Mapster map, read once (with Mapbox kept as it is).
	 *
	 * @return array<int, array<string, mixed>> Mapster id => converted map.
	 */
	private function maps(): array {
		if ( null === $this->found ) {
			$this->found = array();
			foreach ( MapsterImport::map_ids() as $id ) {
				$data = MapsterImport::read( $id );
				if ( null !== $data ) {
					$this->found[ $id ] = $data;
				}
			}
		}
		return $this->found;
	}

	/**
	 * Titles of the published pages that embed each Mapster map (shortcode in the content or in Elementor data).
	 *
	 * @return array<int, string[]> Mapster id => page titles.
	 */
	private function pages(): array {
		if ( null !== $this->pages ) {
			return $this->pages;
		}
		global $wpdb;
		$this->pages = array();
		$rows        = $wpdb->get_results( "SELECT p.post_title, p.post_content AS body FROM {$wpdb->posts} p WHERE p.post_status = 'publish' AND p.post_type <> 'revision' AND p.post_content LIKE '%mapster_wp_map%'", ARRAY_A ); // phpcs:ignore WordPress.DB
		$rows        = array_merge(
			is_array( $rows ) ? $rows : array(),
			(array) $wpdb->get_results( "SELECT p.post_title, m.meta_value AS body FROM {$wpdb->posts} p JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_elementor_data' WHERE p.post_status = 'publish' AND m.meta_value LIKE '%mapster_wp_map%'", ARRAY_A ) // phpcs:ignore WordPress.DB
		);
		foreach ( $rows as $row ) {
			if ( preg_match_all( '/mapster_wp_map[^\]]*?\bid=\\\\*["\']?(\d+)/', (string) $row['body'], $found ) ) {
				foreach ( array_unique( array_map( 'intval', $found[1] ) ) as $id ) {
					$this->pages[ $id ][] = (string) $row['post_title'];
				}
			}
		}
		return $this->pages;
	}

	protected function map( bool $active ): Mapping {
		$m    = new Mapping();
		$maps = $this->maps();
		if ( ! $maps ) {
			$m->note( __( 'There is no Mapster map, so there is nothing to transfer.', 'ux-studio' ) );
			return $m;
		}
		$lines    = array();
		$no_token = array();
		foreach ( $maps as $data ) {
			if ( $data['skipped'] ) {
				$lines[] = $data['title'] . ' (' . implode( ', ', $data['skipped'] ) . ')';
			}
			if ( 'mapbox' === $data['provider'] && '' === $data['token'] ) {
				$no_token[] = $data['title'];
			}
		}
		if ( $lines ) {
			$m->lossy(
				sprintf(
					/* translators: %s: list of maps with what is left out. */
					__( 'The module draws points only. These items are not transferred: %s.', 'ux-studio' ),
					implode( '; ', $lines )
				)
			);
		}
		if ( $no_token ) {
			$m->warn(
				sprintf(
					/* translators: %s: list of map names. */
					__( 'No Mapbox token was found for: %s. These maps show OpenStreetMap until a token is set in the module settings.', 'ux-studio' ),
					implode( ', ', $no_token )
				)
			);
		}
		$m->note( __( 'Pages with the Mapster shortcode keep working: the module answers it by itself once Mapster is deactivated.', 'ux-studio' ) );
		return $m;
	}

	protected function data_plan( array $options ): array {
		$rows  = array();
		$pages = $this->pages();
		foreach ( $this->maps() as $id => $data ) {
			$provider = ucfirst( 'osm' === $data['provider'] ? 'OpenStreetMap' : $data['provider'] );
			if ( 'mapbox' === $data['provider'] && ! empty( $options['switch_to_osm'] ) ) {
				$provider = 'OpenStreetMap';
			}
			$label = sprintf(
				/* translators: 1: map title, 2: map service, 3: number of pins. */
				_n( '%1$s (%2$s, %3$d pin)', '%1$s (%2$s, %3$d pins)', $data['pins'], 'ux-studio' ),
				'' !== $data['title'] ? $data['title'] : '#' . $id,
				$provider,
				$data['pins']
			);
			if ( ! empty( $pages[ $id ] ) ) {
				$label .= ' - ' . sprintf(
					/* translators: %s: page titles. */
					__( 'used on: %s', 'ux-studio' ),
					implode( ', ', array_slice( $pages[ $id ], 0, 5 ) )
				);
			}
			$rows[] = array(
				'key'   => 'map:' . $id,
				'label' => $label,
				'count' => 1,
			);
		}
		return $rows;
	}

	protected function data_total( array $options ): int {
		return count( $this->maps() );
	}

	protected function read_batch( $position, int $limit, array $options ): array {
		$all    = array();
		foreach ( $this->maps() as $id => $data ) {
			$all[] = array(
				'key'   => 'mapster:' . $id,
				'label' => '' !== $data['title'] ? $data['title'] : '#' . $id,
				'id'    => $id,
			);
		}
		$offset = (int) $position;
		return array(
			'items' => array_slice( $all, $offset, $limit ),
			'next'  => $offset + $limit,
			'done'  => $offset + $limit >= count( $all ),
		);
	}

	/**
	 * The Maps module (also while it is turned off: the maps are plain data until then).
	 */
	private function maps_module(): ?MapsModule {
		$plugin = class_exists( '\UxStudio\Plugin' ) ? \UxStudio\Plugin::instance() : null;
		$module = $plugin && isset( $plugin->modules ) ? $plugin->modules->instance( 'maps' ) : null;
		return $module instanceof MapsModule ? $module : null;
	}

	protected function preview_item( array $item, array $ctx ): array {
		$module = $this->maps_module();
		if ( null !== $module && $module->find_by_legacy_id( (int) $item['id'] ) ) {
			return array( 'status' => 'unchanged' );
		}
		return array( 'status' => 'created' );
	}

	protected function apply_item( array $item, array $ctx ): array {
		$module = $this->maps_module();
		if ( null === $module ) {
			return array(
				'status'  => 'error',
				'message' => __( 'The Maps module is not available.', 'ux-studio' ),
			);
		}
		$res = $module->import_mapster( (int) $item['id'], ! empty( $ctx['options']['switch_to_osm'] ) );
		if ( 'created' === $res['status'] ) {
			return array(
				'status'   => 'created',
				'map_type' => 'uxstudio_map',
				'map_id'   => (string) $res['id'],
			);
		}
		return 'unchanged' === $res['status']
			? array( 'status' => 'unchanged' )
			: array(
				'status'  => 'error',
				'message' => (string) ( $res['message'] ?? '' ),
			);
	}

	protected function verify_item( array $item ): array {
		$module = $this->maps_module();
		if ( null !== $module && $module->find_by_legacy_id( (int) $item['id'] ) ) {
			return array(
				'ok'     => true,
				'detail' => '',
			);
		}
		return array(
			'ok'     => false,
			'detail' => __( 'The map is not in UX Studio.', 'ux-studio' ),
		);
	}
}
