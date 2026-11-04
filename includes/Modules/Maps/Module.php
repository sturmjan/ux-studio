<?php
/**
 * Maps module - simple maps with one or more pins, embedded by shortcode.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\Maps;

use UxStudio\Core\ActivityLog;
use UxStudio\Core\Security;
use UxStudio\Modules\BaseModule;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Brings back the map from the legacy UX1 opening-hours module as a
 * standalone module (PLAN §41): a map is a private CPT (uxstudio_map) with
 * its whole configuration - provider, view and pins - as one JSON post meta.
 *
 * Providers: OpenStreetMap (no key), Mapy.com, Mapbox (both through the
 * bundled Leaflet) and Google Maps (Maps JavaScript API). The pin positions
 * are plain lat/lng, so a map can switch provider without touching the pins.
 * Provider keys are stored encrypted; they are public-by-design browser keys
 * (they end up in tile/script URLs on the front end), so restrict them by
 * referrer/URL in the provider's console.
 */
final class Module extends BaseModule {

	public const CPT        = 'uxstudio_map';
	public const META       = '_uxstudio_map';
	public const LEGACY_META = '_uxstudio_map_legacy_id';

	public const PROVIDERS = array( 'osm', 'mapy', 'mapbox', 'google' );

	/** Styles per provider; the first one is the default. */
	public const STYLES = array(
		'osm'    => array( 'standard' ),
		'mapy'   => array( 'basic', 'outdoor', 'aerial', 'winter' ),
		'mapbox' => array( 'mapbox/streets-v12', 'mapbox/outdoors-v12', 'mapbox/light-v11', 'mapbox/dark-v11', 'mapbox/satellite-streets-v12' ),
		'google' => array( 'roadmap', 'satellite', 'hybrid', 'terrain' ),
	);

	private const MAX_MARKERS = 200;

	private ?Frontend $frontend = null;

	private const SECRET_MAPY   = 'uxstudio_secret_maps_mapy';
	private const SECRET_MAPBOX = 'uxstudio_secret_maps_mapbox_token';
	private const SECRET_GOOGLE = 'uxstudio_secret_maps_google';

	/** Keys already entered in opening-hours are reused as a fallback. */
	private const SECRET_FALLBACK = array(
		self::SECRET_MAPY   => 'uxstudio_secret_opening_hours_mapy_cz',
		self::SECRET_GOOGLE => 'uxstudio_secret_opening_hours_google_maps',
	);

	private const SECRET_FIELDS = array(
		'mapy_api_key'   => self::SECRET_MAPY,
		'mapbox_token'   => self::SECRET_MAPBOX,
		'google_api_key' => self::SECRET_GOOGLE,
	);

	/**
	 * Register hooks.
	 */
	public function boot(): void {
		add_action( 'init', array( $this, 'register_post_type' ) );
		add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );
		add_filter( 'uxstudio_csp_directives', array( $this, 'csp_directives' ) );
		$this->frontend = new Frontend( $this );
		$this->frontend->register();
		GutenbergBlock::register( $this );
		// Only ever fires when Elementor itself calls it, so the widget class
		// (which extends \Elementor\Widget_Base) is never loaded without Elementor.
		add_action(
			'elementor/widgets/register',
			function ( $widgets_manager ): void {
				ElementorWidget::register_with( $this, $widgets_manager );
			}
		);
		add_action( 'elementor/preview/enqueue_scripts', array( $this, 'enqueue_frontend_assets' ) );
	}

	/**
	 * Render a map exactly like the shortcode (shared by the Elementor widget and the Gutenberg block).
	 *
	 * @param int $id     Map ID.
	 * @param int $height Height override in px, 0 = the map's own height.
	 */
	public function render_map( int $id, int $height = 0 ): string {
		if ( null === $this->frontend ) {
			return '';
		}
		$atts = array( 'id' => $id );
		if ( $height > 0 ) {
			$atts['height'] = $height;
		}
		return $this->frontend->render_shortcode( $atts );
	}

	/**
	 * Enqueue the front-end assets up front (Elementor editor preview, where the widget HTML is injected later).
	 */
	public function enqueue_frontend_assets(): void {
		if ( null === $this->frontend ) {
			return;
		}
		$this->frontend->register_assets();
		wp_enqueue_style( 'uxstudio-leaflet' );
		wp_enqueue_script( 'uxstudio-leaflet' );
		wp_enqueue_style( 'uxstudio-maps' );
		wp_enqueue_script( 'uxstudio-maps' );
	}

	/**
	 * Private CPT holding the maps.
	 */
	public function register_post_type(): void {
		register_post_type(
			self::CPT,
			array(
				'public'          => false,
				'show_ui'         => false,
				'show_in_rest'    => false,
				'capability_type' => 'post',
				'supports'        => array( 'title' ),
				'label'           => __( 'Maps', 'ux-studio' ),
			)
		);
	}

	/**
	 * Register the module REST controller.
	 */
	public function register_rest_routes(): void {
		( new RestController( $this ) )->register_routes();
	}

	/**
	 * REST controller class.
	 */
	public function rest_controller(): ?string {
		return RestController::class;
	}

	/* ------------------------------------------------------------------ *
	 * Settings
	 * ------------------------------------------------------------------ */

	/**
	 * Settings schema: default provider, provider keys (secrets) and defaults for new maps.
	 */
	public function settings_schema(): array {
		$keep = __( 'Stored encrypted. Leave blank to keep the current key.', 'ux-studio' );
		return array(
			array(
				'key'     => 'default_provider',
				'type'    => 'select',
				'label'   => __( 'Default map provider', 'ux-studio' ),
				'help'    => __( 'Used by maps set to "Default". A provider without a key falls back to OpenStreetMap.', 'ux-studio' ),
				'default' => 'osm',
				'options' => $this->provider_options(),
			),
			array(
				'key'     => 'mapy_api_key',
				'type'    => 'text',
				'label'   => __( 'Mapy.com API key', 'ux-studio' ),
				'help'    => $keep . ' ' . __( 'Get it at developer.mapy.com. If empty, the key from Opening Hours is used.', 'ux-studio' ),
				'default' => '',
			),
			array(
				'key'     => 'mapbox_token',
				'type'    => 'text',
				'label'   => __( 'Mapbox access token', 'ux-studio' ),
				'help'    => $keep . ' ' . __( 'A public token (pk.…) restricted to this site\'s URL in your Mapbox account.', 'ux-studio' ),
				'default' => '',
			),
			array(
				'key'     => 'google_api_key',
				'type'    => 'text',
				'label'   => __( 'Google Maps API key', 'ux-studio' ),
				'help'    => $keep . ' ' . __( 'Needs the Maps JavaScript API enabled; restrict it to this site\'s referrer. If empty, the key from Opening Hours is used.', 'ux-studio' ),
				'default' => '',
			),
			array(
				'key'     => 'google_map_id',
				'type'    => 'text',
				'label'   => __( 'Google Map ID', 'ux-studio' ),
				'help'    => __( 'Optional. With a Map ID Google draws modern colored pins and applies the cloud style of that ID.', 'ux-studio' ),
				'default' => '',
			),
			array(
				'key'     => 'default_color',
				'type'    => 'color',
				'label'   => __( 'Default pin color', 'ux-studio' ),
				'default' => '#d63638',
			),
			array(
				'key'     => 'default_height',
				'type'    => 'number',
				'label'   => __( 'Default map height (px)', 'ux-studio' ),
				'default' => 450,
			),
		);
	}

	/**
	 * Intercept the secret fields before they reach the plain settings option.
	 *
	 * @param array $input Raw input.
	 */
	public function save_settings( array $input ): array {
		foreach ( self::SECRET_FIELDS as $field => $secret ) {
			if ( array_key_exists( $field, $input ) && '' !== trim( (string) $input[ $field ] ) ) {
				Security::store_secret( $secret, trim( (string) $input[ $field ] ) );
			}
			unset( $input[ $field ] );
		}
		return parent::save_settings( $input );
	}

	/**
	 * Never send the secrets back; expose only whether they are set.
	 */
	public function settings_values(): array {
		$values = parent::settings_values();
		foreach ( self::SECRET_FIELDS as $field => $secret ) {
			$values[ $field ]          = '';
			$values[ 'has_' . $field ] = '' !== $this->secret( $secret );
		}
		return $values;
	}

	/**
	 * Provider select options (value => label).
	 *
	 * @return array<string, string>
	 */
	public function provider_options(): array {
		return array(
			'osm'    => __( 'OpenStreetMap (no key)', 'ux-studio' ),
			'mapy'   => 'Mapy.com',
			'mapbox' => 'Mapbox',
			'google' => 'Google Maps',
		);
	}

	/**
	 * Secret with the opening-hours fallback.
	 *
	 * @param string $name Secret option name.
	 */
	private function secret( string $name ): string {
		$value = Security::get_secret( $name );
		if ( '' === $value && isset( self::SECRET_FALLBACK[ $name ] ) ) {
			$value = Security::get_secret( self::SECRET_FALLBACK[ $name ] );
		}
		return $value;
	}

	/**
	 * Browser key for a provider ('' when none / not needed).
	 *
	 * @param string $provider Provider id.
	 */
	public function provider_key( string $provider ): string {
		switch ( $provider ) {
			case 'mapy':
				return $this->secret( self::SECRET_MAPY );
			case 'mapbox':
				return $this->secret( self::SECRET_MAPBOX );
			case 'google':
				return $this->secret( self::SECRET_GOOGLE );
		}
		return '';
	}

	/**
	 * Store a provider key from code (the migration script).
	 *
	 * @param string $provider Provider id.
	 * @param string $key      Key/token.
	 */
	public function store_provider_key( string $provider, string $key ): void {
		$map = array( 'mapy' => self::SECRET_MAPY, 'mapbox' => self::SECRET_MAPBOX, 'google' => self::SECRET_GOOGLE );
		if ( isset( $map[ $provider ] ) && '' !== $key && '' === Security::get_secret( $map[ $provider ] ) ) {
			Security::store_secret( $map[ $provider ], $key );
		}
	}

	/**
	 * Which providers can actually render (have a key or need none).
	 *
	 * @return array<string, bool>
	 */
	public function available_providers(): array {
		$out = array();
		foreach ( self::PROVIDERS as $provider ) {
			$out[ $provider ] = 'osm' === $provider || '' !== $this->provider_key( $provider );
		}
		return $out;
	}

	/**
	 * Provider a map really renders with: "default" resolved, missing key -> OSM.
	 *
	 * @param string $provider Stored provider ("default" or an id).
	 */
	public function resolve_provider( string $provider ): string {
		if ( 'default' === $provider || ! in_array( $provider, self::PROVIDERS, true ) ) {
			$provider = (string) $this->settings->get( 'default_provider', 'osm' );
		}
		if ( ! in_array( $provider, self::PROVIDERS, true ) || ! $this->available_providers()[ $provider ] ) {
			return 'osm';
		}
		return $provider;
	}

	/** Google Map ID setting. */
	public function google_map_id(): string {
		return sanitize_text_field( (string) $this->settings->get( 'google_map_id', '' ) );
	}

	/* ------------------------------------------------------------------ *
	 * Maps CRUD
	 * ------------------------------------------------------------------ */

	/**
	 * Configuration for a brand new map.
	 *
	 * @return array<string, mixed>
	 */
	public function default_config(): array {
		return array(
			'provider'      => 'default',
			'style'         => '',
			'height'        => max( 150, (int) $this->settings->get( 'default_height', 450 ) ),
			'zoom'          => 13,
			'center'        => array( 'lat' => 49.8175, 'lng' => 15.473 ),
			'fit_markers'   => true,
			'scroll_zoom'   => false,
			'route_link'    => true,
			'click_to_load' => false,
			'markers'       => array(),
		);
	}

	/**
	 * Normalize and validate a map configuration.
	 *
	 * @param array $input Raw configuration.
	 * @return array<string, mixed>
	 */
	public function sanitize_config( array $input ): array {
		$config   = $this->default_config();
		$provider = (string) ( $input['provider'] ?? 'default' );
		$config['provider'] = in_array( $provider, self::PROVIDERS, true ) ? $provider : 'default';

		$style = (string) ( $input['style'] ?? '' );
		if ( 'mapbox' === $config['provider'] ) {
			// Accept "mapbox://styles/user/id" as pasted from Mapbox Studio.
			$style = (string) preg_replace( '#^mapbox://styles/#', '', $style );
			$config['style'] = preg_match( '#^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$#', $style ) ? $style : self::STYLES['mapbox'][0];
		} elseif ( 'default' !== $config['provider'] ) {
			$config['style'] = in_array( $style, self::STYLES[ $config['provider'] ], true ) ? $style : self::STYLES[ $config['provider'] ][0];
		}

		$config['height']        = min( 2000, max( 150, (int) ( $input['height'] ?? $config['height'] ) ) );
		$config['zoom']          = min( 20, max( 1, (int) round( (float) ( $input['zoom'] ?? $config['zoom'] ) ) ) );
		$config['center']        = $this->sanitize_point( (array) ( $input['center'] ?? array() ) ) ?? $config['center'];
		$config['fit_markers']   = ! empty( $input['fit_markers'] );
		$config['scroll_zoom']   = ! empty( $input['scroll_zoom'] );
		$config['route_link']    = ! empty( $input['route_link'] );
		$config['click_to_load'] = ! empty( $input['click_to_load'] );

		$default_color = sanitize_hex_color( (string) $this->settings->get( 'default_color', '#d63638' ) ) ?: '#d63638';
		$markers       = array();
		foreach ( array_slice( (array) ( $input['markers'] ?? array() ), 0, self::MAX_MARKERS ) as $marker ) {
			$marker = (array) $marker;
			$point  = $this->sanitize_point( $marker );
			if ( null === $point ) {
				continue;
			}
			$markers[] = array(
				'lat'   => $point['lat'],
				'lng'   => $point['lng'],
				'title' => sanitize_text_field( (string) ( $marker['title'] ?? '' ) ),
				'text'  => $this->sanitize_popup_text( (string) ( $marker['text'] ?? '' ) ),
				'color' => sanitize_hex_color( (string) ( $marker['color'] ?? '' ) ) ?: $default_color,
				'url'   => esc_url_raw( (string) ( $marker['url'] ?? '' ), array( 'http', 'https', 'mailto', 'tel' ) ),
			);
		}
		$config['markers'] = $markers;

		return $config;
	}

	/**
	 * @param array $point Array with lat/lng.
	 * @return array{lat:float,lng:float}|null
	 */
	private function sanitize_point( array $point ): ?array {
		if ( ! isset( $point['lat'], $point['lng'] ) || ! is_numeric( $point['lat'] ) || ! is_numeric( $point['lng'] ) ) {
			return null;
		}
		$lat = (float) $point['lat'];
		$lng = (float) $point['lng'];
		if ( $lat < -90 || $lat > 90 || $lng < -180 || $lng > 180 ) {
			return null;
		}
		return array( 'lat' => round( $lat, 7 ), 'lng' => round( $lng, 7 ) );
	}

	/**
	 * Popup text: plain lines plus a few inline tags.
	 *
	 * @param string $text Raw text.
	 */
	private function sanitize_popup_text( string $text ): string {
		$allowed = array(
			'br'     => array(),
			'strong' => array(),
			'b'      => array(),
			'em'     => array(),
			'i'      => array(),
			'a'      => array( 'href' => array(), 'target' => array(), 'rel' => array() ),
		);
		return trim( wp_kses( $text, $allowed ) );
	}

	/**
	 * Map as returned to the admin SPA.
	 *
	 * @param \WP_Post $post Map post.
	 * @return array<string, mixed>
	 */
	private function to_array( \WP_Post $post ): array {
		$stored = json_decode( (string) get_post_meta( $post->ID, self::META, true ), true );
		$config = $this->sanitize_config( is_array( $stored ) ? $stored : array() );
		return array(
			'id'        => $post->ID,
			'title'     => $post->post_title,
			'config'    => $config,
			'legacy_id' => (int) get_post_meta( $post->ID, self::LEGACY_META, true ),
			'modified'  => $post->post_modified_gmt,
		);
	}

	/**
	 * All maps, newest first.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function list_maps(): array {
		$posts = get_posts(
			array(
				'post_type'      => self::CPT,
				'post_status'    => 'publish',
				'posts_per_page' => 500,
				'orderby'        => 'date',
				'order'          => 'DESC',
			)
		);
		return array_map( array( $this, 'to_array' ), $posts );
	}

	/**
	 * One map.
	 *
	 * @param int $id Map ID.
	 * @return array<string, mixed>|null
	 */
	public function get_map( int $id ): ?array {
		$post = get_post( $id );
		return $post && self::CPT === $post->post_type && 'publish' === $post->post_status ? $this->to_array( $post ) : null;
	}

	/**
	 * Create (id 0) or update a map.
	 *
	 * @param int   $id    Map ID or 0.
	 * @param array $input {title, config}.
	 * @return array<string, mixed>|WP_Error
	 */
	public function save_map( int $id, array $input ) {
		if ( $id && null === $this->get_map( $id ) ) {
			return new WP_Error( 'uxstudio_map_not_found', __( 'Map not found.', 'ux-studio' ), array( 'status' => 404 ) );
		}

		$title  = sanitize_text_field( (string) ( $input['title'] ?? '' ) );
		$config = $this->sanitize_config( (array) ( $input['config'] ?? array() ) );
		$postarr = array(
			'post_type'   => self::CPT,
			'post_status' => 'publish',
			'post_title'  => '' !== $title ? $title : __( 'Map', 'ux-studio' ),
		);
		if ( $id ) {
			$postarr['ID'] = $id;
		}

		$saved = $id ? wp_update_post( $postarr, true ) : wp_insert_post( $postarr, true );
		if ( is_wp_error( $saved ) ) {
			return $saved;
		}
		update_post_meta( (int) $saved, self::META, wp_slash( (string) wp_json_encode( $config, JSON_UNESCAPED_UNICODE ) ) );
		if ( ! empty( $input['legacy_id'] ) ) {
			update_post_meta( (int) $saved, self::LEGACY_META, (int) $input['legacy_id'] );
		}

		ActivityLog::log( 'maps', $id ? 'update' : 'create', 'map', (int) $saved, array( 'title' => $postarr['post_title'] ) );
		return (array) $this->get_map( (int) $saved );
	}

	/**
	 * Delete a map.
	 *
	 * @param int $id Map ID.
	 * @return true|WP_Error
	 */
	public function delete_map( int $id ) {
		$map = $this->get_map( $id );
		if ( null === $map ) {
			return new WP_Error( 'uxstudio_map_not_found', __( 'Map not found.', 'ux-studio' ), array( 'status' => 404 ) );
		}
		wp_delete_post( $id, true );
		ActivityLog::log( 'maps', 'delete', 'map', $id, array( 'title' => (string) $map['title'] ) );
		return true;
	}

	/**
	 * Map ID created from a legacy plugin's ID (Mapster), or 0.
	 *
	 * @param int $legacy_id Legacy ID.
	 */
	public function find_by_legacy_id( int $legacy_id ): int {
		$found = get_posts(
			array(
				'post_type'      => self::CPT,
				'post_status'    => 'publish',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'meta_key'       => self::LEGACY_META, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'     => (string) $legacy_id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			)
		);
		return $found ? (int) $found[0] : 0;
	}

	/**
	 * Pins built from Opening Hours locations that have coordinates (the way
	 * the legacy UX1 map worked): title, address, phone.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function location_markers(): array {
		$posts = get_posts(
			array(
				'post_type'      => 'uxstudio_location',
				'post_status'    => 'any',
				'posts_per_page' => self::MAX_MARKERS,
				'orderby'        => 'title',
				'order'          => 'ASC',
			)
		);
		$markers = array();
		foreach ( $posts as $post ) {
			$lat = get_post_meta( $post->ID, '_uxstudio_oh_lat', true );
			$lng = get_post_meta( $post->ID, '_uxstudio_oh_lng', true );
			if ( ! is_numeric( $lat ) || ! is_numeric( $lng ) ) {
				continue;
			}
			$lines = array_filter(
				array(
					esc_html( (string) get_post_meta( $post->ID, '_uxstudio_oh_address', true ) ),
					esc_html( (string) get_post_meta( $post->ID, '_uxstudio_oh_phone', true ) ),
				)
			);
			$markers[] = array(
				'lat'   => (float) $lat,
				'lng'   => (float) $lng,
				'title' => $post->post_title,
				'text'  => implode( '<br>', $lines ),
			);
		}
		return $markers;
	}

	/* ------------------------------------------------------------------ *
	 * Geocoding (address search in the editor)
	 * ------------------------------------------------------------------ */

	/**
	 * Address -> up to 5 candidates. Mapy.com, then Google, then OSM Nominatim.
	 *
	 * @param string $query Free-text address.
	 * @return array<int, array{lat:float,lng:float,label:string}>|WP_Error
	 */
	public function geocode( string $query ) {
		$query = trim( $query );
		if ( '' === $query ) {
			return new WP_Error( 'uxstudio_map_invalid_query', __( 'Enter an address to search for.', 'ux-studio' ), array( 'status' => 400 ) );
		}

		$mapy = $this->provider_key( 'mapy' );
		if ( '' !== $mapy ) {
			$body = $this->fetch_json( add_query_arg( array( 'query' => rawurlencode( $query ), 'lang' => 'cs', 'limit' => 5, 'apikey' => rawurlencode( $mapy ) ), 'https://api.mapy.com/v1/geocode' ) );
			if ( is_array( $body ) && isset( $body['items'] ) ) {
				return array_map(
					static fn ( $item ): array => array(
						'lat'   => (float) ( $item['position']['lat'] ?? 0 ),
						'lng'   => (float) ( $item['position']['lon'] ?? 0 ),
						'label' => trim( ( $item['name'] ?? '' ) . ', ' . ( $item['location'] ?? '' ), ', ' ),
					),
					(array) $body['items']
				);
			}
		}

		$google = $this->provider_key( 'google' );
		if ( '' !== $google ) {
			$body = $this->fetch_json( add_query_arg( array( 'address' => rawurlencode( $query ), 'key' => rawurlencode( $google ) ), 'https://maps.googleapis.com/maps/api/geocode/json' ) );
			if ( is_array( $body ) && 'OK' === ( $body['status'] ?? '' ) ) {
				return array_map(
					static fn ( $item ): array => array(
						'lat'   => (float) ( $item['geometry']['location']['lat'] ?? 0 ),
						'lng'   => (float) ( $item['geometry']['location']['lng'] ?? 0 ),
						'label' => (string) ( $item['formatted_address'] ?? '' ),
					),
					array_slice( (array) $body['results'], 0, 5 )
				);
			}
		}

		// Nominatim usage policy: identify the app, max 1 request/second (admin-only, typed searches).
		$body = $this->fetch_json(
			add_query_arg( array( 'q' => rawurlencode( $query ), 'format' => 'jsonv2', 'limit' => 5, 'accept-language' => 'cs' ), 'https://nominatim.openstreetmap.org/search' ),
			array( 'User-Agent' => 'UX Studio WordPress plugin (' . home_url() . ')' )
		);
		if ( ! is_array( $body ) ) {
			return new WP_Error( 'uxstudio_map_geocode_failed', __( 'Address search is not available right now.', 'ux-studio' ), array( 'status' => 502 ) );
		}
		return array_map(
			static fn ( $item ): array => array(
				'lat'   => (float) ( $item['lat'] ?? 0 ),
				'lng'   => (float) ( $item['lon'] ?? 0 ),
				'label' => (string) ( $item['display_name'] ?? '' ),
			),
			$body
		);
	}

	/**
	 * @param string               $url     URL.
	 * @param array<string,string> $headers Extra headers.
	 * @return array|null Decoded JSON or null on failure.
	 */
	private function fetch_json( string $url, array $headers = array() ): ?array {
		$response = wp_remote_get( $url, array( 'timeout' => 8, 'headers' => $headers ) );
		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return null;
		}
		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		return is_array( $body ) ? $body : null;
	}

	/* ------------------------------------------------------------------ *
	 * CSP
	 * ------------------------------------------------------------------ */

	/**
	 * Allow the provider hosts in the Security module's CSP. Tiles are
	 * images (img-src already allows https:); Google also needs script and
	 * connect access.
	 *
	 * @param array<string, array<int, string>> $directives Directive => sources.
	 * @return array<string, array<int, string>>
	 */
	public function csp_directives( $directives ) {
		if ( ! is_array( $directives ) || '' === $this->provider_key( 'google' ) ) {
			return $directives;
		}
		$google = array( 'https://maps.googleapis.com', 'https://maps.gstatic.com' );
		foreach ( array( 'script-src', 'connect-src' ) as $directive ) {
			$directives[ $directive ] = array_values( array_unique( array_merge( $directives[ $directive ] ?? array(), $google ) ) );
		}
		$directives['style-src'] = array_values( array_unique( array_merge( $directives['style-src'] ?? array(), array( 'https://fonts.googleapis.com' ) ) ) );
		return $directives;
	}
}
