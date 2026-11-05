<?php
/**
 * Conversion of Mapster WP Maps data into maps of this module.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\Maps;

defined( 'ABSPATH' ) || exit;

/**
 * One implementation shared by the migration wizard (Core\Migration\Sources\MapsterSource) and
 * bin/migrate-plugins.php, so both convert exactly the same way. Mapster keeps a map as the post type
 * `mapster-wp-map` (settings in post meta `map_type_*`, `view_*`, `layout_*`, a list of location post ids
 * in `locations`) and a location as `mapster-wp-location`. Only point locations are converted; lines and
 * polygons are reported. Reads only; writing goes through Module::save_map() so the config is validated
 * exactly like in the editor.
 */
final class MapsterImport {

	public const MAP_TYPE      = 'mapster-wp-map';
	public const SETTINGS_TYPE = 'mapster-wp-settings';

	private Module $module;

	/**
	 * @param Module $module Maps module (writer and validator).
	 */
	public function __construct( Module $module ) {
		$this->module = $module;
	}

	/**
	 * Ids of the published Mapster maps, oldest first.
	 *
	 * @return int[]
	 */
	public static function map_ids(): array {
		$posts = get_posts(
			array(
				'post_type'      => self::MAP_TYPE,
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'orderby'        => 'ID',
				'order'          => 'ASC',
				'fields'         => 'ids',
			)
		);
		return array_map( 'intval', (array) $posts );
	}

	/**
	 * Default Mapbox token from the Mapster settings post ('' when there is none).
	 */
	public static function default_token(): string {
		$posts = get_posts( array( 'post_type' => self::SETTINGS_TYPE, 'post_status' => 'any', 'posts_per_page' => 1, 'fields' => 'ids' ) );
		return $posts ? (string) get_post_meta( (int) $posts[0], 'default_access_token', true ) : '';
	}

	/**
	 * Read one Mapster map and convert it, writing nothing.
	 *
	 * @param int  $map_id        Mapster map post id.
	 * @param bool $switch_to_osm Draw a Mapbox map with OpenStreetMap instead (no token needed).
	 * @return array{id:int,title:string,from:string,provider:string,style:string,token:string,pins:int,skipped:string[],config:array<string, mixed>}|null
	 */
	public static function read( int $map_id, bool $switch_to_osm = false ): ?array {
		$map = get_post( $map_id );
		if ( ! $map || self::MAP_TYPE !== $map->post_type ) {
			return null;
		}
		$m = static fn ( string $key ) => get_post_meta( $map_id, $key, true );

		$from  = (string) $m( 'map_type_map_provider' );
		$token = (string) $m( 'map_type_access_token' );
		if ( '' === $token ) {
			$token = self::default_token();
		}
		$style = (string) preg_replace( '#^mapbox://styles/#', '', (string) $m( 'map_type_custom_mapbox_style' ) );
		if ( '' === $style ) {
			$tile  = (string) $m( 'map_type_map_tile_style_access_token' );
			$style = 'mapbox/' . ( '' !== $tile ? $tile : 'streets' ) . '-v12';
		}
		// Mapster also offers Google; anything else falls back to OpenStreetMap.
		$target = array( 'mapbox' => 'mapbox', 'google-maps' => 'google', 'google' => 'google' )[ $from ] ?? 'osm';
		if ( $switch_to_osm && 'mapbox' === $target ) {
			$target = 'osm';
		}

		$markers = array();
		$skipped = array();
		foreach ( array_filter( array_map( 'intval', (array) maybe_unserialize( $m( 'locations' ) ) ) ) as $loc_id ) {
			$loc = get_post( $loc_id );
			if ( ! $loc ) {
				continue;
			}
			$geo   = json_decode( (string) get_post_meta( $loc_id, 'location', true ), true );
			$point = $geo['features'][0]['geometry'] ?? null;
			if ( ! is_array( $point ) || 'Point' !== ( $point['type'] ?? '' ) ) {
				$skipped[] = $loc->post_title;
				continue;
			}
			$popup     = '1' === (string) get_post_meta( $loc_id, 'enable_popup', true );
			$header    = (string) get_post_meta( $loc_id, 'popup_header_text', true );
			$markers[] = array(
				'lat'   => (float) $point['coordinates'][1],
				'lng'   => (float) $point['coordinates'][0],
				'title' => $popup && '' !== $header ? $header : $loc->post_title,
				'text'  => $popup ? trim( (string) get_post_meta( $loc_id, 'popup_body_text', true ) ) : '',
				'color' => (string) get_post_meta( $loc_id, 'marker_color', true ),
				'url'   => '',
			);
		}
		foreach ( array( 'lines', 'polygons' ) as $kind ) {
			if ( array_filter( (array) maybe_unserialize( $m( $kind ) ) ) ) {
				$skipped[] = 'all ' . $kind;
			}
		}

		$config = array(
			'provider'      => $target,
			'style'         => 'mapbox' === $target ? $style : '',
			'height'        => 'px' === ( (string) $m( 'layout_height_units' ) ?: 'px' ) ? (int) $m( 'layout_height' ) : 450,
			'zoom'          => (float) $m( 'view_manual_zoom' ) ?: 13,
			'center'        => array( 'lat' => (float) $m( 'view_manual_latitude' ), 'lng' => (float) $m( 'view_manual_longitude' ) ),
			'fit_markers'   => 'manual' !== (string) $m( 'view_initial_load' ),
			'scroll_zoom'   => '1' === (string) $m( 'zoom_on_scroll' ),
			'route_link'    => false,
			'click_to_load' => false,
			'markers'       => $markers,
		);

		return array(
			'id'       => $map_id,
			'title'    => (string) $map->post_title,
			'from'     => $from,
			'provider' => $target,
			'style'    => (string) $config['style'],
			'token'    => 'mapbox' === $target ? $token : '',
			'pins'     => count( $markers ),
			'skipped'  => $skipped,
			'config'   => $config,
		);
	}

	/**
	 * Create the map in this module. Idempotent by the Mapster id: a map that was imported before is left alone.
	 *
	 * @param int  $map_id        Mapster map post id.
	 * @param bool $switch_to_osm Draw a Mapbox map with OpenStreetMap instead.
	 * @return array{status:string,id?:int,message?:string,data?:array<string, mixed>} status: created | unchanged | error.
	 */
	public function import( int $map_id, bool $switch_to_osm = false ): array {
		$existing = $this->module->find_by_legacy_id( $map_id );
		if ( $existing ) {
			return array( 'status' => 'unchanged', 'id' => $existing );
		}
		$data = self::read( $map_id, $switch_to_osm );
		if ( null === $data ) {
			return array( 'status' => 'error', 'message' => __( 'The Mapster map does not exist.', 'ux-studio' ) );
		}
		if ( 'mapbox' === $data['provider'] && '' !== $data['token'] ) {
			// Never overwrites a token the module already has.
			$this->module->store_provider_key( 'mapbox', $data['token'] );
		}
		$saved = $this->module->save_map( 0, array( 'title' => $data['title'], 'config' => $data['config'], 'legacy_id' => $map_id ) );
		if ( is_wp_error( $saved ) ) {
			return array( 'status' => 'error', 'message' => $saved->get_error_message() );
		}
		return array( 'status' => 'created', 'id' => (int) $saved['id'], 'data' => $data );
	}
}
