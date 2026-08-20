<?php
/**
 * Stock Photos module - search and import images from stock photo providers.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\StockPhotos;

use UxStudio\Core\ActivityLog;
use UxStudio\Core\Security;
use UxStudio\Modules\BaseModule;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Seven providers: six need an API key (Pexels, Pixabay, Unsplash, Flickr,
 * Mapillary, Giphy), Openverse is a free/open aggregator with no key.
 *
 * Every keyed provider's API key follows the exact SmtpEmail secret pattern:
 * stored via Security::store_secret() in its own uxstudio_secret_stock_* option,
 * intercepted in save_settings() before it can reach the plain settings option,
 * and never echoed back in settings_values() (only a has_x_key boolean is).
 *
 * Import is SSRF-hardened the same way as AutoImageUpload\Processor: scheme
 * allowlist, private/reserved/loopback IP blocking on the resolved host,
 * wp_safe_remote_get() with reject_unsafe_urls, a response size cap and an
 * extension/mime allowlist before the file ever reaches media_handle_sideload().
 */
final class Module extends BaseModule {

	private const SECRET_PEXELS    = 'uxstudio_secret_stock_pexels';
	private const SECRET_PIXABAY   = 'uxstudio_secret_stock_pixabay';
	private const SECRET_UNSPLASH  = 'uxstudio_secret_stock_unsplash';
	private const SECRET_FLICKR    = 'uxstudio_secret_stock_flickr';
	private const SECRET_MAPILLARY = 'uxstudio_secret_stock_mapillary';
	private const SECRET_GIPHY     = 'uxstudio_secret_stock_giphy';

	/**
	 * Keyed providers: id => [ field, secret option, display name ].
	 *
	 * @var array<string, array{field:string,secret:string,name:string}>
	 */
	private const KEYED_PROVIDERS = array(
		'pexels'    => array(
			'field'  => 'pexels_api_key',
			'secret' => self::SECRET_PEXELS,
			'name'   => 'Pexels',
		),
		'pixabay'   => array(
			'field'  => 'pixabay_api_key',
			'secret' => self::SECRET_PIXABAY,
			'name'   => 'Pixabay',
		),
		'unsplash'  => array(
			'field'  => 'unsplash_api_key',
			'secret' => self::SECRET_UNSPLASH,
			'name'   => 'Unsplash',
		),
		'flickr'    => array(
			'field'  => 'flickr_api_key',
			'secret' => self::SECRET_FLICKR,
			'name'   => 'Flickr',
		),
		'mapillary' => array(
			'field'  => 'mapillary_api_key',
			'secret' => self::SECRET_MAPILLARY,
			'name'   => 'Mapillary',
		),
		'giphy'     => array(
			'field'  => 'giphy_api_key',
			'secret' => self::SECRET_GIPHY,
			'name'   => 'Giphy',
		),
	);

	/** Allowed downloaded image extensions (no SVG - avoids XSS-via-SVG). */
	private const ALLOWED_EXT = array( 'jpg', 'jpeg', 'png', 'gif', 'webp', 'avif' );

	/** Max import size in bytes (15 MB). */
	private const MAX_BYTES = 15728640;

	/**
	 * Register hooks.
	 */
	public function boot(): void {
		add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );
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

	/**
	 * Settings schema: one text field per keyed provider.
	 */
	public function settings_schema(): array {
		$schema = array();
		foreach ( self::KEYED_PROVIDERS as $provider ) {
			$schema[] = array(
				'key'     => $provider['field'],
				'type'    => 'text',
				/* translators: %s: provider name, e.g. Pexels */
				'label'   => sprintf( __( '%s API key', 'ux-studio' ), $provider['name'] ),
				'help'    => __( 'Stored encrypted. Leave blank to keep the current key.', 'ux-studio' ),
				'default' => '',
			);
		}
		return $schema;
	}

	/**
	 * Intercept the six secret fields before they reach the plain settings
	 * option; everything else goes through the normal schema-based save.
	 *
	 * @param array $input Raw input.
	 */
	public function save_settings( array $input ): array {
		foreach ( self::KEYED_PROVIDERS as $provider ) {
			$field = $provider['field'];
			if ( array_key_exists( $field, $input ) && '' !== (string) $input[ $field ] ) {
				Security::store_secret( $provider['secret'], (string) $input[ $field ] );
			}
			unset( $input[ $field ] );
		}

		return parent::save_settings( $input );
	}

	/**
	 * Never leak the raw keys back to the client; expose only whether each is set.
	 */
	public function settings_values(): array {
		$values = parent::settings_values();
		foreach ( self::KEYED_PROVIDERS as $id => $provider ) {
			$values[ $provider['field'] ]    = '';
			$values[ 'has_' . $id . '_key' ] = '' !== Security::get_secret( $provider['secret'] );
		}
		return $values;
	}

	/**
	 * All 7 providers with id, name, needs_key and (for keyed providers) has_key.
	 *
	 * @return array<int, array{id:string,name:string,needs_key:bool,has_key:bool}>
	 */
	public function providers(): array {
		$list = array();
		foreach ( self::KEYED_PROVIDERS as $id => $provider ) {
			$list[] = array(
				'id'        => $id,
				'name'      => $provider['name'],
				'needs_key' => true,
				'has_key'   => '' !== Security::get_secret( $provider['secret'] ),
			);
		}
		$list[] = array(
			'id'        => 'openverse',
			'name'      => 'Openverse',
			'needs_key' => false,
			'has_key'   => true,
		);
		return $list;
	}

	/**
	 * Search a provider and normalize results to a common shape.
	 *
	 * @param string $provider Provider id.
	 * @param string $query    Search query.
	 * @param int    $page     Page number (1-based).
	 * @return array{results:array<int,array<string,mixed>>,total:int,page:int}|WP_Error
	 */
	public function search( string $provider, string $query, int $page = 1 ) {
		$query = trim( $query );
		$page  = max( 1, $page );

		if ( '' === $query ) {
			return new WP_Error( 'empty_query', __( 'Please enter a search term.', 'ux-studio' ), array( 'status' => 400 ) );
		}

		switch ( $provider ) {
			case 'pexels':
				return $this->search_pexels( $query, $page );
			case 'pixabay':
				return $this->search_pixabay( $query, $page );
			case 'unsplash':
				return $this->search_unsplash( $query, $page );
			case 'flickr':
				return $this->search_flickr( $query, $page );
			case 'mapillary':
				return $this->search_mapillary( $query, $page );
			case 'giphy':
				return $this->search_giphy( $query, $page );
			case 'openverse':
				return $this->search_openverse( $query, $page );
			default:
				return new WP_Error( 'unknown_provider', __( 'Unknown provider.', 'ux-studio' ), array( 'status' => 400 ) );
		}
	}

	/**
	 * @param string $query Search query.
	 * @param int    $page  Page.
	 * @return array|WP_Error
	 */
	private function search_pexels( string $query, int $page ) {
		$key = Security::get_secret( self::SECRET_PEXELS );
		if ( '' === $key ) {
			return $this->missing_key_error( 'Pexels' );
		}

		$url  = add_query_arg(
			array(
				'query'    => $query,
				'page'     => $page,
				'per_page' => 24,
			),
			'https://api.pexels.com/v1/search'
		);
		$data = $this->http_get_json( $url, array( 'Authorization' => $key ) );
		if ( is_wp_error( $data ) ) {
			return $data;
		}

		$results = array();
		foreach ( (array) ( $data['photos'] ?? array() ) as $photo ) {
			$results[] = array(
				'id'              => (string) ( $photo['id'] ?? '' ),
				'thumb_url'       => (string) ( $photo['src']['medium'] ?? '' ),
				'full_url'        => (string) ( $photo['src']['original'] ?? ( $photo['src']['large'] ?? '' ) ),
				'width'           => (int) ( $photo['width'] ?? 0 ),
				'height'          => (int) ( $photo['height'] ?? 0 ),
				'author'          => (string) ( $photo['photographer'] ?? '' ),
				'source_page_url' => (string) ( $photo['url'] ?? '' ),
			);
		}

		return array(
			'results' => $results,
			'total'   => (int) ( $data['total_results'] ?? count( $results ) ),
			'page'    => $page,
		);
	}

	/**
	 * @param string $query Search query.
	 * @param int    $page  Page.
	 * @return array|WP_Error
	 */
	private function search_pixabay( string $query, int $page ) {
		$key = Security::get_secret( self::SECRET_PIXABAY );
		if ( '' === $key ) {
			return $this->missing_key_error( 'Pixabay' );
		}

		$url  = add_query_arg(
			array(
				'key'        => $key,
				'q'          => $query,
				'page'       => $page,
				'per_page'   => 24,
				'image_type' => 'photo',
				'safesearch' => 'true',
			),
			'https://pixabay.com/api/'
		);
		$data = $this->http_get_json( $url );
		if ( is_wp_error( $data ) ) {
			return $data;
		}

		$results = array();
		foreach ( (array) ( $data['hits'] ?? array() ) as $hit ) {
			$results[] = array(
				'id'              => (string) ( $hit['id'] ?? '' ),
				'thumb_url'       => (string) ( $hit['webformatURL'] ?? '' ),
				'full_url'        => (string) ( $hit['largeImageURL'] ?? ( $hit['webformatURL'] ?? '' ) ),
				'width'           => (int) ( $hit['imageWidth'] ?? 0 ),
				'height'          => (int) ( $hit['imageHeight'] ?? 0 ),
				'author'          => (string) ( $hit['user'] ?? '' ),
				'source_page_url' => (string) ( $hit['pageURL'] ?? '' ),
			);
		}

		return array(
			'results' => $results,
			'total'   => (int) ( $data['totalHits'] ?? count( $results ) ),
			'page'    => $page,
		);
	}

	/**
	 * @param string $query Search query.
	 * @param int    $page  Page.
	 * @return array|WP_Error
	 */
	private function search_unsplash( string $query, int $page ) {
		$key = Security::get_secret( self::SECRET_UNSPLASH );
		if ( '' === $key ) {
			return $this->missing_key_error( 'Unsplash' );
		}

		$url  = add_query_arg(
			array(
				'query'    => $query,
				'page'     => $page,
				'per_page' => 24,
			),
			'https://api.unsplash.com/search/photos'
		);
		$data = $this->http_get_json(
			$url,
			array(
				'Authorization'   => 'Client-ID ' . $key,
				'Accept-Version'  => 'v1',
			)
		);
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		if ( isset( $data['errors'] ) ) {
			return new WP_Error( 'provider_error', implode( ', ', (array) $data['errors'] ), array( 'status' => 502 ) );
		}

		$results = array();
		foreach ( (array) ( $data['results'] ?? array() ) as $photo ) {
			$results[] = array(
				'id'              => (string) ( $photo['id'] ?? '' ),
				'thumb_url'       => (string) ( $photo['urls']['small'] ?? '' ),
				'full_url'        => (string) ( $photo['urls']['full'] ?? ( $photo['urls']['regular'] ?? '' ) ),
				'width'           => (int) ( $photo['width'] ?? 0 ),
				'height'          => (int) ( $photo['height'] ?? 0 ),
				'author'          => (string) ( $photo['user']['name'] ?? '' ),
				'source_page_url' => (string) ( $photo['links']['html'] ?? '' ),
			);
		}

		return array(
			'results' => $results,
			'total'   => (int) ( $data['total'] ?? count( $results ) ),
			'page'    => $page,
		);
	}

	/**
	 * @param string $query Search query.
	 * @param int    $page  Page.
	 * @return array|WP_Error
	 */
	private function search_flickr( string $query, int $page ) {
		$key = Security::get_secret( self::SECRET_FLICKR );
		if ( '' === $key ) {
			return $this->missing_key_error( 'Flickr' );
		}

		$url  = add_query_arg(
			array(
				'method'         => 'flickr.photos.search',
				'api_key'        => $key,
				'text'           => $query,
				'page'           => $page,
				'per_page'       => 24,
				'format'         => 'json',
				'nojsoncallback' => 1,
				'extras'         => 'url_m,url_l,url_o,owner_name',
				'license'        => '1,2,3,4,5,6,7,9,10',
				'safe_search'    => 1,
				'content_type'   => 1,
				'sort'           => 'relevance',
			),
			'https://www.flickr.com/services/rest/'
		);
		$data = $this->http_get_json( $url );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		if ( 'ok' !== ( $data['stat'] ?? '' ) ) {
			return new WP_Error( 'provider_error', (string) ( $data['message'] ?? 'Flickr API error' ), array( 'status' => 502 ) );
		}

		$results = array();
		foreach ( (array) ( $data['photos']['photo'] ?? array() ) as $photo ) {
			$results[] = array(
				'id'              => (string) ( $photo['id'] ?? '' ),
				'thumb_url'       => (string) ( $photo['url_m'] ?? '' ),
				'full_url'        => (string) ( $photo['url_o'] ?? ( $photo['url_l'] ?? ( $photo['url_m'] ?? '' ) ) ),
				'width'           => (int) ( $photo['width_o'] ?? 0 ),
				'height'          => (int) ( $photo['height_o'] ?? 0 ),
				'author'          => (string) ( $photo['ownername'] ?? '' ),
				'source_page_url' => 'https://www.flickr.com/photos/' . rawurlencode( (string) ( $photo['owner'] ?? '' ) ) . '/' . rawurlencode( (string) ( $photo['id'] ?? '' ) ),
			);
		}

		return array(
			'results' => $results,
			'total'   => (int) ( $data['photos']['total'] ?? count( $results ) ),
			'page'    => $page,
		);
	}

	/**
	 * Mapillary has no free-text search; the query is geocoded to a bounding
	 * box via the public Nominatim API first, mirroring the legacy module.
	 *
	 * @param string $query Search query (place name).
	 * @param int    $page  Page (unused - Graph API is cursor based, we return one page).
	 * @return array|WP_Error
	 */
	private function search_mapillary( string $query, int $page ) {
		$key = Security::get_secret( self::SECRET_MAPILLARY );
		if ( '' === $key ) {
			return $this->missing_key_error( 'Mapillary' );
		}

		$coords = $this->geocode( $query );
		if ( null === $coords ) {
			return array(
				'results' => array(),
				'total'   => 0,
				'page'    => $page,
			);
		}

		$delta = 0.01;
		$bbox  = sprintf(
			'%F,%F,%F,%F',
			$coords['lon'] - $delta,
			$coords['lat'] - $delta,
			$coords['lon'] + $delta,
			$coords['lat'] + $delta
		);

		$url  = add_query_arg(
			array(
				'access_token' => $key,
				'fields'       => 'id,thumb_256_url,thumb_2048_url,creator',
				'bbox'         => $bbox,
				'limit'        => 24,
			),
			'https://graph.mapillary.com/images'
		);
		$data = $this->http_get_json( $url );
		if ( is_wp_error( $data ) ) {
			return $data;
		}

		$results = array();
		foreach ( (array) ( $data['data'] ?? array() ) as $image ) {
			$id        = (string) ( $image['id'] ?? '' );
			$results[] = array(
				'id'              => $id,
				'thumb_url'       => (string) ( $image['thumb_256_url'] ?? '' ),
				'full_url'        => (string) ( $image['thumb_2048_url'] ?? ( $image['thumb_256_url'] ?? '' ) ),
				'width'           => 0,
				'height'          => 0,
				'author'          => (string) ( $image['creator']['username'] ?? '' ),
				'source_page_url' => 'https://www.mapillary.com/app/?pKey=' . rawurlencode( $id ),
			);
		}

		return array(
			'results' => $results,
			'total'   => count( $results ),
			'page'    => $page,
		);
	}

	/**
	 * @param string $query Search query.
	 * @param int    $page  Page.
	 * @return array|WP_Error
	 */
	private function search_giphy( string $query, int $page ) {
		$key = Security::get_secret( self::SECRET_GIPHY );
		if ( '' === $key ) {
			return $this->missing_key_error( 'Giphy' );
		}

		$url  = add_query_arg(
			array(
				'api_key' => $key,
				'q'       => $query,
				'limit'   => 24,
				'offset'  => ( $page - 1 ) * 24,
				'rating'  => 'g',
			),
			'https://api.giphy.com/v1/gifs/search'
		);
		$data = $this->http_get_json( $url );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		if ( isset( $data['meta']['status'] ) && 200 !== (int) $data['meta']['status'] ) {
			return new WP_Error( 'provider_error', (string) ( $data['meta']['msg'] ?? 'Giphy API error' ), array( 'status' => 502 ) );
		}

		$results = array();
		foreach ( (array) ( $data['data'] ?? array() ) as $gif ) {
			$images   = (array) ( $gif['images'] ?? array() );
			$original = (array) ( $images['original'] ?? array() );
			$fixed    = (array) ( $images['fixed_height'] ?? array() );

			$results[] = array(
				'id'              => (string) ( $gif['id'] ?? '' ),
				'thumb_url'       => (string) ( $fixed['url'] ?? '' ),
				'full_url'        => (string) ( $original['url'] ?? ( $fixed['url'] ?? '' ) ),
				'width'           => (int) ( $original['width'] ?? 0 ),
				'height'          => (int) ( $original['height'] ?? 0 ),
				'author'          => (string) ( $gif['username'] ?? ( $gif['source_tld'] ?? '' ) ),
				'source_page_url' => (string) ( $gif['url'] ?? '' ),
			);
		}

		return array(
			'results' => $results,
			'total'   => (int) ( $data['pagination']['total_count'] ?? count( $results ) ),
			'page'    => $page,
		);
	}

	/**
	 * Openverse needs no API key - free/open aggregator.
	 *
	 * @param string $query Search query.
	 * @param int    $page  Page.
	 * @return array|WP_Error
	 */
	private function search_openverse( string $query, int $page ) {
		$url  = add_query_arg(
			array(
				'q'         => $query,
				'page'      => $page,
				'page_size' => 24,
			),
			'https://api.openverse.org/v1/images/'
		);
		$data = $this->http_get_json( $url );
		if ( is_wp_error( $data ) ) {
			return $data;
		}

		$results = array();
		foreach ( (array) ( $data['results'] ?? array() ) as $item ) {
			$results[] = array(
				'id'              => (string) ( $item['id'] ?? '' ),
				'thumb_url'       => (string) ( $item['thumbnail'] ?? '' ),
				'full_url'        => (string) ( $item['url'] ?? ( $item['thumbnail'] ?? '' ) ),
				'width'           => (int) ( $item['width'] ?? 0 ),
				'height'          => (int) ( $item['height'] ?? 0 ),
				'author'          => (string) ( $item['creator'] ?? '' ),
				'source_page_url' => (string) ( $item['foreign_landing_url'] ?? '' ),
			);
		}

		return array(
			'results' => $results,
			'total'   => (int) ( $data['result_count'] ?? count( $results ) ),
			'page'    => $page,
		);
	}

	/**
	 * Geocode a free-text place name via the public Nominatim API (no key needed).
	 *
	 * @param string $query Place name.
	 * @return array{lat:float,lon:float}|null
	 */
	private function geocode( string $query ): ?array {
		$url  = add_query_arg(
			array(
				'q'      => $query,
				'format' => 'json',
				'limit'  => 1,
			),
			'https://nominatim.openstreetmap.org/search'
		);
		$data = $this->http_get_json( $url, array( 'User-Agent' => 'UX-Studio-WordPress-Plugin/1.0' ) );
		if ( is_wp_error( $data ) || empty( $data[0] ) ) {
			return null;
		}
		return array(
			'lat' => (float) $data[0]['lat'],
			'lon' => (float) $data[0]['lon'],
		);
	}

	/**
	 * Perform a GET request and decode the JSON body, or return a WP_Error.
	 *
	 * @param string $url     Request URL.
	 * @param array  $headers Extra request headers.
	 * @return array|WP_Error
	 */
	private function http_get_json( string $url, array $headers = array() ) {
		$response = wp_remote_get(
			$url,
			array(
				'timeout' => 15,
				'headers' => $headers,
			)
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );
		if ( $code < 200 || $code >= 300 ) {
			return new WP_Error(
				'provider_http_error',
				sprintf(
					/* translators: 1: HTTP status code */
					__( 'The provider returned an error (HTTP %d).', 'ux-studio' ),
					$code
				),
				array( 'status' => 502 )
			);
		}

		$data = json_decode( $body, true );
		if ( ! is_array( $data ) ) {
			return new WP_Error( 'invalid_response', __( 'The provider returned an invalid response.', 'ux-studio' ), array( 'status' => 502 ) );
		}
		return $data;
	}

	/**
	 * @param string $name Provider display name.
	 */
	private function missing_key_error( string $name ): WP_Error {
		return new WP_Error(
			'missing_api_key',
			sprintf(
				/* translators: %s: provider name */
				__( 'No %s API key is configured yet.', 'ux-studio' ),
				$name
			),
			array( 'status' => 400 )
		);
	}

	/**
	 * SSRF-hardened import: fetch a provider-returned image URL and sideload it
	 * into the media library. The URL is treated as untrusted client input.
	 *
	 * @param string $provider  Provider id (for logging/attribution only).
	 * @param string $image_url Full-size image URL to import.
	 * @param string $title     Attachment title.
	 * @return array{id:int,url:string}|WP_Error
	 */
	public function import( string $provider, string $image_url, string $title ) {
		$image_url = trim( $image_url );
		if ( '' === $image_url ) {
			return new WP_Error( 'empty_url', __( 'No image URL was provided.', 'ux-studio' ), array( 'status' => 400 ) );
		}

		$scheme = strtolower( (string) wp_parse_url( $image_url, PHP_URL_SCHEME ) );
		if ( ! in_array( $scheme, array( 'http', 'https' ), true ) ) {
			return new WP_Error( 'unsupported_scheme', __( 'Unsupported URL scheme.', 'ux-studio' ), array( 'status' => 400 ) );
		}

		$host = strtolower( (string) wp_parse_url( $image_url, PHP_URL_HOST ) );
		if ( '' === $host || $this->is_blocked_host( $host ) ) {
			return new WP_Error( 'blocked_host', __( 'This target is not allowed.', 'ux-studio' ), array( 'status' => 400 ) );
		}

		$response = wp_safe_remote_get(
			$image_url,
			array(
				'timeout'             => 20,
				'redirection'         => 3,
				'reject_unsafe_urls'  => true,
				'limit_response_size' => self::MAX_BYTES,
			)
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		if ( 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return new WP_Error( 'fetch_failed', __( 'Downloading the image failed.', 'ux-studio' ), array( 'status' => 502 ) );
		}

		$body = wp_remote_retrieve_body( $response );
		if ( '' === $body || strlen( $body ) > self::MAX_BYTES ) {
			return new WP_Error( 'too_large', __( 'The image is empty or exceeds the maximum allowed size.', 'ux-studio' ), array( 'status' => 400 ) );
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';

		$tmp_file = wp_tempnam( $image_url );
		if ( ! $tmp_file || false === file_put_contents( $tmp_file, $body ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			if ( $tmp_file ) {
				@unlink( $tmp_file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			}
			return new WP_Error( 'write_failed', __( 'Could not write the temporary file.', 'ux-studio' ), array( 'status' => 500 ) );
		}

		$path = (string) wp_parse_url( $image_url, PHP_URL_PATH );
		$base = $path ? wp_basename( $path ) : '';
		$ext  = $base ? strtolower( pathinfo( $base, PATHINFO_EXTENSION ) ) : '';

		if ( ! $ext || ! in_array( $ext, self::ALLOWED_EXT, true ) ) {
			$mime = function_exists( 'mime_content_type' ) ? (string) mime_content_type( $tmp_file ) : '';
			$ext  = $this->ext_from_mime( $mime );
			if ( '' === $ext ) {
				@unlink( $tmp_file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				return new WP_Error( 'invalid_type', __( 'The file is not an allowed image format.', 'ux-studio' ), array( 'status' => 400 ) );
			}
			$base = 'stock-photo-' . gmdate( 'Ymd-His' ) . '-' . wp_generate_password( 6, false, false ) . '.' . $ext;
		}

		$filetype = wp_check_filetype( $base );
		if ( empty( $filetype['ext'] ) || ! in_array( strtolower( $filetype['ext'] ), self::ALLOWED_EXT, true ) ) {
			@unlink( $tmp_file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			return new WP_Error( 'invalid_type', __( 'The file is not an allowed image format.', 'ux-studio' ), array( 'status' => 400 ) );
		}

		$file_array = array(
			'name'     => sanitize_file_name( $base ),
			'tmp_name' => $tmp_file,
		);

		$title         = sanitize_text_field( $title );
		$attachment_id = media_handle_sideload( $file_array, 0, '' !== $title ? $title : null );
		if ( is_wp_error( $attachment_id ) ) {
			@unlink( $tmp_file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			return $attachment_id;
		}

		if ( '' !== $title ) {
			wp_update_post(
				array(
					'ID'         => $attachment_id,
					'post_title' => $title,
				)
			);
		}

		update_post_meta( $attachment_id, '_uxstudio_stock_photo_provider', sanitize_text_field( $provider ) );
		update_post_meta( $attachment_id, '_uxstudio_stock_photo_source_url', esc_url_raw( $image_url ) );

		ActivityLog::log( 'stock-photos', 'import', 'attachment', (int) $attachment_id, array( 'provider' => $provider ) );

		return array(
			'id'  => (int) $attachment_id,
			'url' => (string) wp_get_attachment_url( $attachment_id ),
		);
	}

	/**
	 * Map an image mime type to a file extension (allowlist only).
	 *
	 * @param string $mime Mime type.
	 */
	private function ext_from_mime( string $mime ): string {
		$map = array(
			'image/jpeg' => 'jpg',
			'image/jpg'  => 'jpg',
			'image/png'  => 'png',
			'image/gif'  => 'gif',
			'image/webp' => 'webp',
			'image/avif' => 'avif',
		);
		return $map[ strtolower( $mime ) ] ?? '';
	}

	/**
	 * SSRF denylist. Returns true (block) when the host resolves to an internal/
	 * private/reserved target, or cannot be resolved at all. Fail-closed - mirrors
	 * AutoImageUpload\Processor::is_blocked_host().
	 *
	 * @param string $host Hostname or IP.
	 */
	private function is_blocked_host( string $host ): bool {
		if ( '' === $host || 'localhost' === $host ) {
			return true;
		}

		$ips = array();
		if ( filter_var( $host, FILTER_VALIDATE_IP ) ) {
			$ips[] = $host;
		} else {
			$a = @gethostbynamel( $host ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			if ( is_array( $a ) ) {
				$ips = array_merge( $ips, $a );
			}
			if ( function_exists( 'dns_get_record' ) ) {
				$aaaa = @dns_get_record( $host, DNS_AAAA ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				if ( is_array( $aaaa ) ) {
					foreach ( $aaaa as $rec ) {
						if ( ! empty( $rec['ipv6'] ) ) {
							$ips[] = $rec['ipv6'];
						}
					}
				}
			}
		}

		if ( empty( $ips ) ) {
			return true; // Cannot verify -> block.
		}

		foreach ( $ips as $ip ) {
			if ( ! filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
				return true;
			}
		}

		return false;
	}
}
