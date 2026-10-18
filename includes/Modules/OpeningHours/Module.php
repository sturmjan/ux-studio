<?php
/**
 * Opening Hours module - branch locations, weekly hours, exceptions, status API.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\OpeningHours;

use UxStudio\Core\ActivityLog;
use UxStudio\Core\Security;
use UxStudio\Modules\BaseModule;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Ported (redesigned) from the legacy opening-hours module: location/hours
 * data model, the "is it open now" resolver, the frontend display layer
 * (see Frontend.php: [opening_hours] / [opening_hours_status] shortcodes +
 * LocalBusiness Schema.org JSON-LD) and Czech public-holiday handling
 * (see Holidays.php). Deliberately NOT ported: the decorative widget zoo
 * (analog/digital clocks, photo cards, 4-provider map embeds) - low marginal
 * value; the core hours table, open-now status and SEO structured data cover
 * the real use case.
 *
 * Locations are stored on a private CPT (uxstudio_location) with all
 * structured data (weekly hours, exceptions, coordinates) as JSON-encoded
 * post meta - no custom DB table is needed for this module.
 */
final class Module extends BaseModule {

	public const CPT = 'uxstudio_location';

	private const SECRET_GOOGLE_MAPS = 'uxstudio_secret_opening_hours_google_maps';
	private const SECRET_MAPY_CZ     = 'uxstudio_secret_opening_hours_mapy_cz';

	private const DAYS = array( 'mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun' );

	/**
	 * Register hooks.
	 */
	public function boot(): void {
		add_action( 'init', array( $this, 'register_post_type' ) );
		add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );
		( new Frontend( $this ) )->register();
	}

	/** Weekday keys mon..sun (for the frontend renderer). */
	public function day_keys(): array {
		return self::DAYS;
	}

	/** Current settings accessor for the frontend renderer. */
	public function setting( string $key, $default = null ) {
		return $this->settings->get( $key, $default );
	}

	/**
	 * Register the private uxstudio_location CPT + its JSON post meta fields.
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
				'label'           => __( 'Opening Hours Locations', 'ux-studio' ),
			)
		);

		$string_meta = array(
			'_uxstudio_oh_address'    => '',
			'_uxstudio_oh_hours'      => '{}',
			'_uxstudio_oh_exceptions' => '[]',
			'_uxstudio_oh_lat'        => '',
			'_uxstudio_oh_lng'        => '',
			'_uxstudio_oh_phone'      => '',
			'_uxstudio_oh_announcements' => '[]',
			'_uxstudio_oh_override'   => '',
		);
		foreach ( $string_meta as $key => $default ) {
			register_post_meta(
				self::CPT,
				$key,
				array(
					'type'         => 'string',
					'single'       => true,
					'show_in_rest' => false,
					'default'      => $default,
				)
			);
		}
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
	 * Settings schema: map provider API keys (both secrets).
	 */
	public function settings_schema(): array {
		return array(
			array(
				'key'     => 'holidays_closed',
				'type'    => 'toggle',
				'label'   => __( 'Treat Czech public holidays as closed', 'ux-studio' ),
				'help'    => __( 'Public holidays count as closed unless a specific exception for that date says otherwise.', 'ux-studio' ),
				'default' => true,
			),
			array(
				'key'     => 'schema_enabled',
				'type'    => 'toggle',
				'label'   => __( 'Output Schema.org JSON-LD (SEO)', 'ux-studio' ),
				'help'    => __( 'Emits LocalBusiness + openingHoursSpecification structured data in the page head.', 'ux-studio' ),
				'default' => false,
			),
			array(
				'key'     => 'schema_on_homepage',
				'type'    => 'toggle',
				'label'   => __( 'Include JSON-LD on the homepage', 'ux-studio' ),
				'default' => true,
			),
			array(
				'key'     => 'schema_location_ids',
				'type'    => 'text',
				'label'   => __( 'JSON-LD location IDs (comma-separated, blank = all)', 'ux-studio' ),
				'default' => '',
			),
			array(
				'key'     => 'show_location_dropdown',
				'type'    => 'toggle',
				'label'   => __( 'Location dropdown', 'ux-studio' ),
				'help'    => __( 'When a shortcode shows several locations, display a dropdown to switch between them.', 'ux-studio' ),
				'default' => false,
			),
			array(
				'key'     => 'banner_color',
				'type'    => 'color',
				'label'   => __( 'Default banner color', 'ux-studio' ),
				'help'    => __( 'Fallback color of the [oh_banner] announcement; severity colors below take precedence.', 'ux-studio' ),
				'default' => '#1d3998',
			),
			array(
				'key'     => 'severity_info_color',
				'type'    => 'color',
				'label'   => __( 'Banner color: Info', 'ux-studio' ),
				'default' => '#2271b1',
			),
			array(
				'key'     => 'severity_warning_color',
				'type'    => 'color',
				'label'   => __( 'Banner color: Warning', 'ux-studio' ),
				'default' => '#dba617',
			),
			array(
				'key'     => 'severity_critical_color',
				'type'    => 'color',
				'label'   => __( 'Banner color: Critical', 'ux-studio' ),
				'default' => '#d63638',
			),
			array(
				'key'     => 'google_maps_api_key',
				'type'    => 'text',
				'label'   => __( 'Google Maps API key', 'ux-studio' ),
				'help'    => __( 'Stored encrypted. Leave blank to keep the current key. Used for geocoding addresses.', 'ux-studio' ),
				'default' => '',
			),
			array(
				'key'     => 'mapy_cz_api_key',
				'type'    => 'text',
				'label'   => __( 'Mapy.cz API key', 'ux-studio' ),
				'help'    => __( 'Stored encrypted. Leave blank to keep the current key. Used as a fallback geocoder when no Google Maps key is set.', 'ux-studio' ),
				'default' => '',
			),
		);
	}

	/**
	 * Intercept the two secret fields before they reach the plain settings
	 * option; everything else goes through the normal schema-based save.
	 *
	 * @param array $input Raw input.
	 */
	public function save_settings( array $input ): array {
		if ( array_key_exists( 'google_maps_api_key', $input ) && '' !== (string) $input['google_maps_api_key'] ) {
			Security::store_secret( self::SECRET_GOOGLE_MAPS, (string) $input['google_maps_api_key'] );
		}
		unset( $input['google_maps_api_key'] );

		if ( array_key_exists( 'mapy_cz_api_key', $input ) && '' !== (string) $input['mapy_cz_api_key'] ) {
			Security::store_secret( self::SECRET_MAPY_CZ, (string) $input['mapy_cz_api_key'] );
		}
		unset( $input['mapy_cz_api_key'] );

		return parent::save_settings( $input );
	}

	/**
	 * Never leak the secrets back to the client; expose only whether they're set.
	 */
	public function settings_values(): array {
		$values                         = parent::settings_values();
		$values['google_maps_api_key']  = '';
		$values['mapy_cz_api_key']      = '';
		$values['has_google_maps_api_key'] = '' !== Security::get_secret( self::SECRET_GOOGLE_MAPS );
		$values['has_mapy_cz_api_key']      = '' !== Security::get_secret( self::SECRET_MAPY_CZ );
		return $values;
	}

	/* ------------------------------------------------------------------ *
	 * Location CRUD
	 * ------------------------------------------------------------------ */

	/**
	 * All locations, newest first.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function list_locations(): array {
		$posts = get_posts(
			array(
				'post_type'      => self::CPT,
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'orderby'        => 'title',
				'order'          => 'ASC',
			)
		);

		return array_map( array( $this, 'to_array' ), $posts );
	}

	/**
	 * A single location, or null if it doesn't exist.
	 *
	 * @param int $id Post id.
	 * @return array<string, mixed>|null
	 */
	public function get_location( int $id ): ?array {
		$post = get_post( $id );
		if ( ! $post || self::CPT !== $post->post_type ) {
			return null;
		}
		return $this->to_array( $post );
	}

	/**
	 * Create a location.
	 *
	 * @param array $data Raw input (title, address, hours, exceptions, lat, lng).
	 * @return array<string, mixed>|WP_Error
	 */
	public function create_location( array $data ) {
		$title = sanitize_text_field( (string) ( $data['title'] ?? '' ) );
		if ( '' === $title ) {
			return new WP_Error( 'uxstudio_oh_invalid_title', __( 'A location name is required.', 'ux-studio' ), array( 'status' => 400 ) );
		}

		$id = wp_insert_post(
			array(
				'post_type'   => self::CPT,
				'post_status' => 'publish',
				'post_title'  => $title,
			),
			true
		);
		if ( is_wp_error( $id ) ) {
			return $id;
		}

		$this->write_meta( $id, $data );
		ActivityLog::log( 'opening-hours', 'create', 'location', $id, array( 'title' => $title ) );

		return $this->get_location( $id );
	}

	/**
	 * Update a location.
	 *
	 * @param int   $id   Post id.
	 * @param array $data Raw input.
	 * @return array<string, mixed>|WP_Error
	 */
	public function update_location( int $id, array $data ) {
		$post = get_post( $id );
		if ( ! $post || self::CPT !== $post->post_type ) {
			return new WP_Error( 'uxstudio_oh_not_found', __( 'Location not found.', 'ux-studio' ), array( 'status' => 404 ) );
		}

		if ( array_key_exists( 'title', $data ) ) {
			$title = sanitize_text_field( (string) $data['title'] );
			if ( '' === $title ) {
				return new WP_Error( 'uxstudio_oh_invalid_title', __( 'A location name is required.', 'ux-studio' ), array( 'status' => 400 ) );
			}
			wp_update_post(
				array(
					'ID'         => $id,
					'post_title' => $title,
				)
			);
		}

		$this->write_meta( $id, $data );
		ActivityLog::log( 'opening-hours', 'update', 'location', $id );

		return $this->get_location( $id );
	}

	/**
	 * Delete a location.
	 *
	 * @param int $id Post id.
	 */
	public function delete_location( int $id ): bool {
		$post = get_post( $id );
		if ( ! $post || self::CPT !== $post->post_type ) {
			return false;
		}
		$deleted = wp_delete_post( $id, true );
		if ( $deleted ) {
			ActivityLog::log( 'opening-hours', 'delete', 'location', $id );
		}
		return (bool) $deleted;
	}

	/**
	 * Persist whichever of the meta fields are present in $data, sanitized.
	 *
	 * @param int   $id   Post id.
	 * @param array $data Raw input.
	 */
	private function write_meta( int $id, array $data ): void {
		if ( array_key_exists( 'address', $data ) ) {
			update_post_meta( $id, '_uxstudio_oh_address', sanitize_text_field( (string) $data['address'] ) );
		}
		if ( array_key_exists( 'hours', $data ) ) {
			$hours = is_array( $data['hours'] ) ? $data['hours'] : array();
			update_post_meta( $id, '_uxstudio_oh_hours', wp_json_encode( self::sanitize_hours( $hours ) ) );
		}
		if ( array_key_exists( 'exceptions', $data ) ) {
			$exceptions = is_array( $data['exceptions'] ) ? $data['exceptions'] : array();
			update_post_meta( $id, '_uxstudio_oh_exceptions', wp_json_encode( self::sanitize_exceptions( $exceptions ) ) );
		}
		if ( array_key_exists( 'phone', $data ) ) {
			update_post_meta( $id, '_uxstudio_oh_phone', sanitize_text_field( (string) $data['phone'] ) );
		}
		if ( array_key_exists( 'announcements', $data ) ) {
			$announcements = is_array( $data['announcements'] ) ? $data['announcements'] : array();
			update_post_meta( $id, '_uxstudio_oh_announcements', wp_json_encode( self::sanitize_announcements( $announcements ), JSON_UNESCAPED_UNICODE ) );
		}
		if ( array_key_exists( 'lat', $data ) ) {
			update_post_meta( $id, '_uxstudio_oh_lat', self::sanitize_lat( $data['lat'] ) );
		}
		if ( array_key_exists( 'lng', $data ) ) {
			update_post_meta( $id, '_uxstudio_oh_lng', self::sanitize_lng( $data['lng'] ) );
		}
	}

	/**
	 * Convert a WP_Post into the shape the SPA expects.
	 *
	 * @param \WP_Post $post Post.
	 * @return array<string, mixed>
	 */
	private function to_array( \WP_Post $post ): array {
		return array(
			'id'         => $post->ID,
			'title'      => $post->post_title,
			'address'    => (string) get_post_meta( $post->ID, '_uxstudio_oh_address', true ),
			'hours'      => self::decode_json_meta( $post->ID, '_uxstudio_oh_hours', array() ),
			'exceptions' => self::decode_json_meta( $post->ID, '_uxstudio_oh_exceptions', array() ),
			'phone'         => (string) get_post_meta( $post->ID, '_uxstudio_oh_phone', true ),
			'announcements' => self::decode_json_meta( $post->ID, '_uxstudio_oh_announcements', array() ),
			'override'      => self::active_override( $post->ID ),
			'lat'       => '' === get_post_meta( $post->ID, '_uxstudio_oh_lat', true ) ? null : (float) get_post_meta( $post->ID, '_uxstudio_oh_lat', true ),
			'lng'        => '' === get_post_meta( $post->ID, '_uxstudio_oh_lng', true ) ? null : (float) get_post_meta( $post->ID, '_uxstudio_oh_lng', true ),
		);
	}

	/**
	 * @param int    $id      Post id.
	 * @param string $key     Meta key.
	 * @param mixed  $default Fallback if the stored value isn't valid JSON.
	 * @return mixed
	 */
	private static function decode_json_meta( int $id, string $key, $default ) {
		$raw = (string) get_post_meta( $id, $key, true );
		if ( '' === $raw ) {
			return $default;
		}
		$decoded = json_decode( $raw, true );
		return is_array( $decoded ) ? $decoded : $default;
	}

	/* ------------------------------------------------------------------ *
	 * Sanitization
	 * ------------------------------------------------------------------ */

	/**
	 * Whitelist mon..sun keys, validate each range's HH:MM open/close times.
	 * Empty array (or absent day) = closed that day.
	 *
	 * @param array $hours Raw weekly schedule.
	 * @return array<string, array<int, array{open:string,close:string}>>
	 */
	public static function sanitize_hours( array $hours ): array {
		$clean = array();
		foreach ( self::DAYS as $day ) {
			$ranges = is_array( $hours[ $day ] ?? null ) ? $hours[ $day ] : array();
			$clean[ $day ] = array();
			foreach ( $ranges as $range ) {
				if ( ! is_array( $range ) ) {
					continue;
				}
				$open  = (string) ( $range['open'] ?? '' );
				$close = (string) ( $range['close'] ?? '' );
				if ( self::is_valid_time( $open ) && self::is_valid_time( $close ) ) {
					$clean[ $day ][] = array(
						'open'  => $open,
						'close' => $close,
					);
				}
			}
		}
		return $clean;
	}

	/**
	 * Validate exceptions: array of {date:Y-m-d, closed:bool, hours?:[{open,close}], label?:string}.
	 *
	 * @param array $exceptions Raw exceptions.
	 * @return array<int, array<string, mixed>>
	 */
	public static function sanitize_exceptions( array $exceptions ): array {
		$clean = array();
		foreach ( $exceptions as $exception ) {
			if ( ! is_array( $exception ) ) {
				continue;
			}
			$date = (string) ( $exception['date'] ?? '' );
			if ( ! self::is_valid_date( $date ) ) {
				continue;
			}

			$entry = array(
				'date'   => $date,
				'closed' => (bool) ( $exception['closed'] ?? false ),
				'label'  => sanitize_text_field( (string) ( $exception['label'] ?? '' ) ),
			);

			$ranges = is_array( $exception['hours'] ?? null ) ? $exception['hours'] : array();
			$hours  = array();
			foreach ( $ranges as $range ) {
				if ( ! is_array( $range ) ) {
					continue;
				}
				$open  = (string) ( $range['open'] ?? '' );
				$close = (string) ( $range['close'] ?? '' );
				if ( self::is_valid_time( $open ) && self::is_valid_time( $close ) ) {
					$hours[] = array(
						'open'  => $open,
						'close' => $close,
					);
				}
			}
			$entry['hours'] = $hours;

			$clean[] = $entry;
		}
		return $clean;
	}

	/**
	 * Validate announcements: array of {text, from?:Y-m-d, to?:Y-m-d, severity:info|warning|critical}.
	 * Same shape as the legacy `_oh_announcements` meta.
	 *
	 * @param array $announcements Raw announcements.
	 * @return array<int, array{text:string,from:string,to:string,severity:string}>
	 */
	public static function sanitize_announcements( array $announcements ): array {
		$clean = array();
		foreach ( $announcements as $announcement ) {
			if ( ! is_array( $announcement ) ) {
				continue;
			}
			$text = sanitize_text_field( (string) ( $announcement['text'] ?? '' ) );
			if ( '' === $text ) {
				continue;
			}
			$from     = (string) ( $announcement['from'] ?? '' );
			$to       = (string) ( $announcement['to'] ?? '' );
			$severity = (string) ( $announcement['severity'] ?? 'info' );

			$clean[] = array(
				'text'     => $text,
				'from'     => self::is_valid_date( $from ) ? $from : '',
				'to'       => self::is_valid_date( $to ) ? $to : '',
				'severity' => in_array( $severity, array( 'info', 'warning', 'critical' ), true ) ? $severity : 'info',
			);
		}
		return $clean;
	}

	/**
	 * Highest-severity announcement active on a Y-m-d date, or null.
	 *
	 * @param array  $announcements Stored announcements.
	 * @param string $date          Y-m-d.
	 * @return array{text:string,from:string,to:string,severity:string}|null
	 */
	public static function active_announcement( array $announcements, string $date ): ?array {
		$best     = null;
		$best_sev = -1;
		$sev_map  = array(
			'info'     => 0,
			'warning'  => 1,
			'critical' => 2,
		);
		foreach ( $announcements as $announcement ) {
			if ( ! is_array( $announcement ) || '' === (string) ( $announcement['text'] ?? '' ) ) {
				continue;
			}
			$from = (string) ( $announcement['from'] ?? '' );
			$to   = (string) ( $announcement['to'] ?? '' );
			if ( '' !== $from && $date < $from ) {
				continue;
			}
			if ( '' !== $to && $date > $to ) {
				continue;
			}
			$sev = $sev_map[ $announcement['severity'] ?? 'info' ] ?? 0;
			if ( $sev > $best_sev ) {
				$best     = $announcement;
				$best_sev = $sev;
			}
		}
		return $best;
	}

	/* ------------------------------------------------------------------ *
	 * Quick temporary closure (override)
	 * ------------------------------------------------------------------ */

	/**
	 * Active "closed for X minutes" override for a location, or null. Expired
	 * overrides are removed lazily. Same shape as the legacy `_oh_override`.
	 *
	 * @param int $id Location id.
	 * @return array{until:int,reason:string,kind:string}|null
	 */
	public static function active_override( int $id ): ?array {
		$raw = (string) get_post_meta( $id, '_uxstudio_oh_override', true );
		if ( '' === $raw ) {
			return null;
		}
		$data = json_decode( $raw, true );
		if ( ! is_array( $data ) || empty( $data['until'] ) ) {
			return null;
		}
		if ( (int) $data['until'] <= time() ) {
			delete_post_meta( $id, '_uxstudio_oh_override' );
			return null;
		}
		return array(
			'until'  => (int) $data['until'],
			'reason' => (string) ( $data['reason'] ?? '' ),
			'kind'   => 'closed',
		);
	}

	/**
	 * Close a location for N minutes (0 clears the override).
	 *
	 * @param int    $id      Location id.
	 * @param int    $minutes Duration, 1..10080 (a week).
	 * @param string $reason  Optional public reason.
	 * @return array<string, mixed>|WP_Error Updated location.
	 */
	public function set_override( int $id, int $minutes, string $reason = '' ) {
		$post = get_post( $id );
		if ( ! $post || self::CPT !== $post->post_type ) {
			return new WP_Error( 'uxstudio_oh_not_found', __( 'Location not found.', 'ux-studio' ), array( 'status' => 404 ) );
		}
		if ( $minutes <= 0 ) {
			delete_post_meta( $id, '_uxstudio_oh_override' );
			ActivityLog::log( 'opening-hours', 'override_clear', 'location', $id );
			return $this->get_location( $id );
		}
		$minutes = min( $minutes, 10080 );
		update_post_meta(
			$id,
			'_uxstudio_oh_override',
			wp_json_encode(
				array(
					'until'  => time() + $minutes * 60,
					'reason' => sanitize_text_field( $reason ),
					'kind'   => 'closed',
				),
				JSON_UNESCAPED_UNICODE
			)
		);
		ActivityLog::log( 'opening-hours', 'override_set', 'location', $id, array( 'minutes' => $minutes ) );
		return $this->get_location( $id );
	}

	/**
	 * @param mixed $value Raw latitude.
	 */
	public static function sanitize_lat( $value ): string {
		if ( '' === $value || null === $value ) {
			return '';
		}
		$lat = max( -90.0, min( 90.0, (float) $value ) );
		return (string) $lat;
	}

	/**
	 * @param mixed $value Raw longitude.
	 */
	public static function sanitize_lng( $value ): string {
		if ( '' === $value || null === $value ) {
			return '';
		}
		$lng = max( -180.0, min( 180.0, (float) $value ) );
		return (string) $lng;
	}

	/**
	 * @param string $time Candidate HH:MM string.
	 */
	private static function is_valid_time( string $time ): bool {
		return (bool) preg_match( '/^([01]\d|2[0-3]):[0-5]\d$/', $time );
	}

	/**
	 * @param string $date Candidate Y-m-d string.
	 */
	private static function is_valid_date( string $date ): bool {
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
			return false;
		}
		$parts = explode( '-', $date );
		return checkdate( (int) $parts[1], (int) $parts[2], (int) $parts[0] );
	}

	/* ------------------------------------------------------------------ *
	 * Status computation
	 * ------------------------------------------------------------------ */

	/**
	 * Compute whether a location is open right now, in the site's timezone.
	 * Exceptions for today's date override the weekly schedule; otherwise the
	 * weekly schedule for today's weekday is used. Same-day ranges are
	 * checked directly; ranges where close <= open are treated as crossing
	 * into the next day and also checked against "yesterday's" overnight tail.
	 *
	 * @param int $location_id Post id.
	 * @return array{open:bool,location_id:int,checked_at:string,next_change:?string}|WP_Error
	 */
	public function compute_status( int $location_id ) {
		$location = $this->get_location( $location_id );
		if ( null === $location ) {
			return new WP_Error( 'uxstudio_oh_not_found', __( 'Location not found.', 'ux-studio' ), array( 'status' => 404 ) );
		}

		$now      = current_datetime(); // DateTimeImmutable in wp_timezone().
		$tz       = wp_timezone();
		$today    = $now->format( 'Y-m-d' );
		$yesterday = $now->modify( '-1 day' )->format( 'Y-m-d' );

		$hours      = is_array( $location['hours'] ) ? $location['hours'] : array();
		$exceptions = is_array( $location['exceptions'] ) ? $location['exceptions'] : array();
		$holidays   = (bool) $this->settings->get( 'holidays_closed', true );

		$today_ranges     = self::ranges_for_date( $today, $hours, $exceptions, $tz, $holidays );
		$yesterday_ranges = self::ranges_for_date( $yesterday, $hours, $exceptions, $tz, $holidays );

		// Expand each day's ranges into absolute start/end timestamps, folding
		// overnight ranges (close <= open) into the following day.
		$intervals = array();
		foreach ( $yesterday_ranges as $range ) {
			$interval = self::range_to_interval( $yesterday, $range, $tz );
			if ( null !== $interval ) {
				$intervals[] = $interval;
			}
		}
		foreach ( $today_ranges as $range ) {
			$interval = self::range_to_interval( $today, $range, $tz );
			if ( null !== $interval ) {
				$intervals[] = $interval;
			}
		}
		usort( $intervals, static fn ( $a, $b ) => $a['start'] <=> $b['start'] );

		$now_ts = $now->getTimestamp();
		$open   = false;
		$next_change = null;

		foreach ( $intervals as $interval ) {
			if ( $now_ts >= $interval['start'] && $now_ts < $interval['end'] ) {
				$open        = true;
				$next_change = gmdate( 'c', $interval['end'] + ( $tz->getOffset( $now ) ) - ( $tz->getOffset( $now ) ) );
				$next_change = ( new \DateTimeImmutable( '@' . $interval['end'] ) )->setTimezone( $tz )->format( DATE_ATOM );
				break;
			}
		}

		if ( ! $open ) {
			foreach ( $intervals as $interval ) {
				if ( $interval['start'] > $now_ts ) {
					$next_change = ( new \DateTimeImmutable( '@' . $interval['start'] ) )->setTimezone( $tz )->format( DATE_ATOM );
					break;
				}
			}
		}

		// A manual "closed for X minutes" override beats everything else.
		$override = self::active_override( $location_id );
		if ( null !== $override ) {
			$open        = false;
			$next_change = ( new \DateTimeImmutable( '@' . $override['until'] ) )->setTimezone( $tz )->format( DATE_ATOM );
		}

		return array(
			'open'        => $open,
			'location_id' => $location_id,
			'checked_at'  => $now->format( DATE_ATOM ),
			'next_change' => $next_change,
			'override'    => $override,
		);
	}

	/**
	 * Timestamp of the next opening (a range start) after now, looking up to
	 * 14 days ahead; null when nothing is scheduled. Honors an active override
	 * (never earlier than its end) and the exceptions/holidays rules.
	 *
	 * @param int $location_id Location id.
	 */
	public function next_open_timestamp( int $location_id ): ?int {
		$location = $this->get_location( $location_id );
		if ( null === $location ) {
			return null;
		}
		$tz         = wp_timezone();
		$now        = current_datetime();
		$now_ts     = $now->getTimestamp();
		$hours      = is_array( $location['hours'] ) ? $location['hours'] : array();
		$exceptions = is_array( $location['exceptions'] ) ? $location['exceptions'] : array();
		$holidays   = (bool) $this->settings->get( 'holidays_closed', true );
		$floor      = $now_ts;

		if ( is_array( $location['override'] ) ) {
			$floor = max( $floor, (int) $location['override']['until'] );
		}

		$best = null;
		for ( $i = -1; $i < 14; $i++ ) {
			$date = $now->modify( ( $i >= 0 ? '+' : '' ) . $i . ' day' )->format( 'Y-m-d' );
			foreach ( self::ranges_for_date( $date, $hours, $exceptions, $tz, $holidays ) as $range ) {
				$interval = self::range_to_interval( $date, $range, $tz );
				if ( null === $interval || $interval['end'] <= $floor ) {
					continue;
				}
				$candidate = max( $interval['start'], $floor );
				if ( $candidate > $now_ts && ( null === $best || $candidate < $best ) ) {
					$best = $candidate;
				}
			}
		}
		return $best;
	}

	/**
	 * Public wrapper: effective {open,close} ranges of a location on a date
	 * (exceptions > holidays > weekly schedule). The temporary override is NOT
	 * applied here, it only affects "right now".
	 *
	 * @param array  $location Location array (see get_location()).
	 * @param string $date     Y-m-d.
	 * @return array<int, array{open:string,close:string}>
	 */
	public function day_ranges( array $location, string $date ): array {
		$hours      = is_array( $location['hours'] ?? null ) ? $location['hours'] : array();
		$exceptions = is_array( $location['exceptions'] ?? null ) ? $location['exceptions'] : array();
		return self::ranges_for_date( $date, $hours, $exceptions, wp_timezone(), (bool) $this->settings->get( 'holidays_closed', true ) );
	}

	/**
	 * Exception label (or public-holiday name) for a date, or '' if none.
	 *
	 * @param array  $location Location array.
	 * @param string $date     Y-m-d.
	 */
	public function day_note( array $location, string $date ): string {
		foreach ( (array) ( $location['exceptions'] ?? array() ) as $exception ) {
			if ( ( $exception['date'] ?? '' ) === $date && '' !== (string) ( $exception['label'] ?? '' ) ) {
				return (string) $exception['label'];
			}
		}
		if ( (bool) $this->settings->get( 'holidays_closed', true ) ) {
			$holiday = Holidays::label_for( $date );
			if ( null !== $holiday ) {
				return $holiday;
			}
		}
		return '';
	}

	/**
	 * Ranges applicable to one date: an exception for that date overrides the
	 * weekly schedule entirely (closed => no ranges, hours => those ranges);
	 * otherwise fall back to the weekly schedule for that weekday.
	 *
	 * @param string             $date            Y-m-d.
	 * @param array              $hours           Weekly schedule (mon..sun).
	 * @param array              $exceptions      Exceptions list.
	 * @param \DateTimeZone      $tz              Site timezone.
	 * @param bool               $holidays_closed Treat Czech public holidays as closed.
	 * @return array<int, array{open:string,close:string}>
	 */
	private static function ranges_for_date( string $date, array $hours, array $exceptions, \DateTimeZone $tz, bool $holidays_closed = false ): array {
		foreach ( $exceptions as $exception ) {
			if ( ( $exception['date'] ?? '' ) === $date ) {
				if ( ! empty( $exception['closed'] ) ) {
					return array();
				}
				return is_array( $exception['hours'] ?? null ) ? $exception['hours'] : array();
			}
		}

		// A public holiday with no explicit exception counts as closed.
		if ( $holidays_closed && null !== Holidays::label_for( $date ) ) {
			return array();
		}

		$day_index = (int) ( new \DateTimeImmutable( $date, $tz ) )->format( 'N' ) - 1; // Mon=0..Sun=6.
		$day_key   = self::DAYS[ $day_index ] ?? 'mon';
		return is_array( $hours[ $day_key ] ?? null ) ? $hours[ $day_key ] : array();
	}

	/**
	 * Turn one {open,close} range anchored on $date into an absolute
	 * [start,end] timestamp interval, rolling the end into the next day when
	 * close <= open (an overnight range).
	 *
	 * @param string        $date  Y-m-d the range is anchored on.
	 * @param array         $range {open,close}.
	 * @param \DateTimeZone $tz    Site timezone.
	 * @return array{start:int,end:int}|null
	 */
	private static function range_to_interval( string $date, array $range, \DateTimeZone $tz ): ?array {
		$open  = (string) ( $range['open'] ?? '' );
		$close = (string) ( $range['close'] ?? '' );
		if ( ! self::is_valid_time( $open ) || ! self::is_valid_time( $close ) ) {
			return null;
		}

		try {
			$start = new \DateTimeImmutable( $date . ' ' . $open . ':00', $tz );
			$end   = new \DateTimeImmutable( $date . ' ' . $close . ':00', $tz );
		} catch ( \Exception $e ) {
			return null;
		}

		if ( $end <= $start ) {
			$end = $end->modify( '+1 day' );
		}

		return array(
			'start' => $start->getTimestamp(),
			'end'   => $end->getTimestamp(),
		);
	}

	/* ------------------------------------------------------------------ *
	 * Geocoding
	 * ------------------------------------------------------------------ */

	/**
	 * Geocode a free-text address via whichever provider has a key configured
	 * (Google Maps preferred, Mapy.cz as fallback). The API key never reaches
	 * the caller - only {lat, lng, formatted_address} or a WP_Error.
	 *
	 * @param string $address Free-text address.
	 * @return array{lat:float,lng:float,formatted_address:string}|WP_Error
	 */
	public function geocode( string $address ) {
		$address = trim( $address );
		if ( '' === $address ) {
			return new WP_Error( 'uxstudio_oh_invalid_address', __( 'An address is required.', 'ux-studio' ), array( 'status' => 400 ) );
		}

		$google_key = Security::get_secret( self::SECRET_GOOGLE_MAPS );
		if ( '' !== $google_key ) {
			return $this->geocode_google( $address, $google_key );
		}

		$mapy_key = Security::get_secret( self::SECRET_MAPY_CZ );
		if ( '' !== $mapy_key ) {
			return $this->geocode_mapy_cz( $address, $mapy_key );
		}

		return new WP_Error(
			'uxstudio_oh_no_geocoder',
			__( 'No geocoding provider is configured. Add a Google Maps or Mapy.cz API key in Settings.', 'ux-studio' ),
			array( 'status' => 424 )
		);
	}

	/**
	 * @param string $address Free-text address.
	 * @param string $api_key Google Maps API key.
	 * @return array{lat:float,lng:float,formatted_address:string}|WP_Error
	 */
	private function geocode_google( string $address, string $api_key ) {
		$url = add_query_arg(
			array(
				'address' => rawurlencode( $address ),
				'key'     => $api_key,
			),
			'https://maps.googleapis.com/maps/api/geocode/json'
		);

		$response = wp_remote_get( $url, array( 'timeout' => 10 ) );
		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'uxstudio_oh_geocode_failed', $response->get_error_message(), array( 'status' => 502 ) );
		}

		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $body ) || 'OK' !== ( $body['status'] ?? '' ) || empty( $body['results'][0] ) ) {
			return new WP_Error( 'uxstudio_oh_geocode_failed', __( 'Google Maps could not resolve this address.', 'ux-studio' ), array( 'status' => 502 ) );
		}

		$result = $body['results'][0];
		return array(
			'lat'                => (float) ( $result['geometry']['location']['lat'] ?? 0 ),
			'lng'                => (float) ( $result['geometry']['location']['lng'] ?? 0 ),
			'formatted_address'  => (string) ( $result['formatted_address'] ?? $address ),
		);
	}

	/**
	 * Mapy.cz Geocoding API v1. Endpoint/param names assumed from current
	 * public docs at the time of writing (query + apikey); adjust if their
	 * API changes.
	 *
	 * @param string $address Free-text address.
	 * @param string $api_key Mapy.cz API key.
	 * @return array{lat:float,lng:float,formatted_address:string}|WP_Error
	 */
	private function geocode_mapy_cz( string $address, string $api_key ) {
		$url = add_query_arg(
			array(
				'query'  => rawurlencode( $address ),
				'lang'   => 'cs',
				'limit'  => 1,
				'apikey' => $api_key,
			),
			'https://api.mapy.cz/v1/geocode'
		);

		$response = wp_remote_get( $url, array( 'timeout' => 10 ) );
		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'uxstudio_oh_geocode_failed', $response->get_error_message(), array( 'status' => 502 ) );
		}

		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		$item = $body['items'][0] ?? null;
		if ( ! is_array( $item ) || ! isset( $item['position']['lat'], $item['position']['lon'] ) ) {
			return new WP_Error( 'uxstudio_oh_geocode_failed', __( 'Mapy.cz could not resolve this address.', 'ux-studio' ), array( 'status' => 502 ) );
		}

		return array(
			'lat'               => (float) $item['position']['lat'],
			'lng'               => (float) $item['position']['lon'],
			'formatted_address' => (string) ( $item['name'] ?? $address ),
		);
	}
}
