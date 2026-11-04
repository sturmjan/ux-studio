<?php
/**
 * Maps front end: shortcode, legacy alias and assets.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\Maps;

defined( 'ABSPATH' ) || exit;

/**
 * [uxstudio_map id="12"] renders a placeholder <div> with the map config as
 * JSON; assets/js/maps.js builds the map when it scrolls into view (or on
 * click with "click to load"). Leaflet is bundled locally and only enqueued
 * on pages that actually contain a Leaflet map; Google's script is loaded by
 * maps.js on demand.
 *
 * [mapster_wp_map id="N"] is answered too while Mapster WP Maps is inactive,
 * for maps migrated from it (bin/migrate-plugins.php), so pages keep working
 * without editing their content or Elementor data.
 */
final class Frontend {

	private Module $module;

	/**
	 * @param Module $module Owning module.
	 */
	public function __construct( Module $module ) {
		$this->module = $module;
	}

	/**
	 * Register shortcodes and assets.
	 */
	public function register(): void {
		add_shortcode( 'uxstudio_map', array( $this, 'render_shortcode' ) );
		add_action( 'init', array( $this, 'register_legacy_shortcode' ), 20 );
		add_action( 'wp_enqueue_scripts', array( $this, 'register_assets' ) );
	}

	/**
	 * Mapster alias, only while Mapster itself is not active.
	 */
	public function register_legacy_shortcode(): void {
		if ( ! shortcode_exists( 'mapster_wp_map' ) ) {
			add_shortcode( 'mapster_wp_map', array( $this, 'render_legacy_shortcode' ) );
		}
	}

	/**
	 * Register (not enqueue) the assets; the shortcode enqueues what it needs.
	 */
	public function register_assets(): void {
		$base = UXSTUDIO_URL . 'assets/';
		wp_register_style( 'uxstudio-leaflet', $base . 'vendor/leaflet/leaflet.css', array(), '1.9.4' );
		wp_register_script( 'uxstudio-leaflet', $base . 'vendor/leaflet/leaflet.js', array(), '1.9.4', true );
		wp_register_style( 'uxstudio-maps', $base . 'css/maps.css', array(), $this->asset_version( 'css/maps.css' ) );
		wp_register_script( 'uxstudio-maps', $base . 'js/maps.js', array(), $this->asset_version( 'js/maps.js' ), true );
	}

	/**
	 * File modification time as the version, so an update busts the cache.
	 *
	 * @param string $relative Path inside assets/.
	 */
	private function asset_version( string $relative ): string {
		$time = @filemtime( UXSTUDIO_PATH . 'assets/' . $relative ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		return $time ? (string) $time : UXSTUDIO_VERSION;
	}

	/**
	 * @param array|string $atts Shortcode attributes.
	 */
	public function render_legacy_shortcode( $atts ): string {
		$atts = shortcode_atts( array( 'id' => 0 ), (array) $atts, 'mapster_wp_map' );
		$id   = $this->module->find_by_legacy_id( (int) $atts['id'] );
		return $id ? $this->render_map( $id, array() ) : '';
	}

	/**
	 * @param array|string $atts Shortcode attributes: id, height (px).
	 */
	public function render_shortcode( $atts ): string {
		$atts = shortcode_atts( array( 'id' => 0, 'height' => '' ), (array) $atts, 'uxstudio_map' );
		$override = array();
		if ( '' !== (string) $atts['height'] ) {
			$override['height'] = (int) $atts['height'];
		}
		return $this->render_map( (int) $atts['id'], $override );
	}

	/**
	 * @param int   $id       Map ID.
	 * @param array $override Config overrides from shortcode attributes.
	 */
	private function render_map( int $id, array $override ): string {
		$map = $this->module->get_map( $id );
		if ( null === $map ) {
			return current_user_can( 'edit_posts' ) ? '<!-- UX Studio: map ' . (int) $id . ' not found -->' : '';
		}

		$config   = array_merge( $map['config'], array_filter( $override ) );
		$provider = $this->module->resolve_provider( (string) $config['provider'] );
		$style    = (string) $config['style'];
		if ( $provider !== $config['provider'] || '' === $style ) {
			// "Default" or a provider without a key: use that provider's default style.
			$style = Module::STYLES[ $provider ][0];
		}
		if ( 'mapbox' === $provider && ! str_contains( $style, '/' ) ) {
			$style = Module::STYLES['mapbox'][0];
		}

		$client = array(
			'provider'    => $provider,
			'style'       => $style,
			'key'         => $this->module->provider_key( $provider ),
			'mapId'       => 'google' === $provider ? $this->module->google_map_id() : '',
			'zoom'        => (int) $config['zoom'],
			'center'      => $config['center'],
			'fit'         => (bool) $config['fit_markers'],
			'scrollZoom'  => (bool) $config['scroll_zoom'],
			'routeLink'   => (bool) $config['route_link'],
			'clickToLoad' => (bool) $config['click_to_load'],
			'markers'     => $config['markers'],
			'i18n'        => array(
				'route' => __( 'Get directions', 'ux-studio' ),
				'more'  => __( 'More', 'ux-studio' ),
				'load'  => __( 'Show map', 'ux-studio' ),
				'note'  => $this->load_note( $provider ),
				'error' => __( 'The map could not be loaded.', 'ux-studio' ),
			),
		);

		if ( 'google' !== $provider ) {
			wp_enqueue_style( 'uxstudio-leaflet' );
			wp_enqueue_script( 'uxstudio-leaflet' );
		}
		wp_enqueue_style( 'uxstudio-maps' );
		wp_enqueue_script( 'uxstudio-maps' );

		return sprintf(
			'<div class="uxs-map" style="height:%dpx" data-uxs-map="%s" role="region" aria-label="%s"></div>',
			(int) $config['height'],
			esc_attr( (string) wp_json_encode( $client ) ),
			esc_attr( $map['title'] )
		);
	}

	/**
	 * Text on the "click to load" placeholder: which service the map comes from.
	 *
	 * @param string $provider Provider id.
	 */
	private function load_note( string $provider ): string {
		$names = array( 'osm' => 'OpenStreetMap', 'mapy' => 'Mapy.com', 'mapbox' => 'Mapbox', 'google' => 'Google Maps' );
		/* translators: %s: map service name, e.g. Google Maps */
		return sprintf( __( 'The map is loaded from %s.', 'ux-studio' ), $names[ $provider ] ?? $provider );
	}
}
