<?php
/**
 * Frontend display layer for opening-hours: shortcodes + Schema.org JSON-LD.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\OpeningHours;

defined( 'ABSPATH' ) || exit;

/**
 * Renders the weekly hours table with a live "open now" badge via the
 * [opening_hours] / [opening_hours_status] shortcodes, and emits LocalBusiness
 * openingHoursSpecification JSON-LD in the page head when enabled.
 */
final class Frontend {

	private Module $module;

	/** Whether the scoped stylesheet has already been printed this request. */
	private static bool $printed_css = false;

	private const SCHEMA_DAYS = array(
		'mon' => 'Monday',
		'tue' => 'Tuesday',
		'wed' => 'Wednesday',
		'thu' => 'Thursday',
		'fri' => 'Friday',
		'sat' => 'Saturday',
		'sun' => 'Sunday',
	);

	/**
	 * @param Module $module Owning module.
	 */
	public function __construct( Module $module ) {
		$this->module = $module;
	}

	/**
	 * Hook shortcodes + JSON-LD output.
	 */
	public function register(): void {
		add_shortcode( 'opening_hours', array( $this, 'render_card' ) );
		add_shortcode( 'opening_hours_status', array( $this, 'render_status' ) );
		add_action( 'wp_head', array( $this, 'json_ld' ), 20 );
	}

	/**
	 * [opening_hours id="123"] - full card with the weekly table + status.
	 *
	 * @param array|string $atts Shortcode attributes.
	 */
	public function render_card( $atts ): string {
		$atts     = shortcode_atts( array( 'id' => 0 ), (array) $atts, 'opening_hours' );
		$location = $this->resolve_location( (int) $atts['id'] );
		if ( null === $location ) {
			return '';
		}

		$status    = $this->module->compute_status( (int) $location['id'] );
		$is_open   = is_array( $status ) ? (bool) $status['open'] : false;
		$today_key = $this->module->day_keys()[ (int) current_datetime()->format( 'N' ) - 1 ] ?? 'mon';
		$today     = current_datetime()->format( 'Y-m-d' );
		$note      = $this->today_note( $location, $today );

		$html  = $this->css();
		$html .= '<div class="uxs-oh">';
		$html .= '<div class="uxs-oh__head">';
		$html .= '<span class="uxs-oh__title">' . esc_html( (string) $location['title'] ) . '</span>';
		$html .= '<span class="uxs-oh__badge ' . ( $is_open ? 'is-open' : 'is-closed' ) . '">'
			. ( $is_open ? esc_html__( 'Open now', 'ux-studio' ) : esc_html__( 'Closed now', 'ux-studio' ) )
			. '</span>';
		$html .= '</div>';

		if ( '' !== (string) $location['address'] ) {
			$html .= '<div class="uxs-oh__address">' . esc_html( (string) $location['address'] ) . '</div>';
		}
		if ( '' !== $note ) {
			$html .= '<div class="uxs-oh__note">' . esc_html( $note ) . '</div>';
		}

		$html         .= '<table class="uxs-oh__table"><tbody>';
		$weekly        = is_array( $location['hours'] ) ? $location['hours'] : array();
		$weekday_names = $this->weekday_names();
		foreach ( $this->module->day_keys() as $day ) {
			$ranges = is_array( $weekly[ $day ] ?? null ) ? $weekly[ $day ] : array();
			$label  = $weekday_names[ $day ] ?? $day;
			$value  = empty( $ranges )
				? esc_html__( 'Closed', 'ux-studio' )
				: esc_html( $this->format_ranges( $ranges ) );
			$row_cls = $day === $today_key ? ' class="is-today"' : '';
			$html   .= '<tr' . $row_cls . '><th>' . esc_html( $label ) . '</th><td>' . $value . '</td></tr>';
		}
		$html .= '</tbody></table></div>';

		return $html;
	}

	/**
	 * [opening_hours_status id="123"] - inline open/closed badge only.
	 *
	 * @param array|string $atts Shortcode attributes.
	 */
	public function render_status( $atts ): string {
		$atts     = shortcode_atts( array( 'id' => 0 ), (array) $atts, 'opening_hours_status' );
		$location = $this->resolve_location( (int) $atts['id'] );
		if ( null === $location ) {
			return '';
		}
		$status  = $this->module->compute_status( (int) $location['id'] );
		$is_open = is_array( $status ) ? (bool) $status['open'] : false;

		return $this->css()
			. '<span class="uxs-oh__badge ' . ( $is_open ? 'is-open' : 'is-closed' ) . '">'
			. ( $is_open ? esc_html__( 'Open now', 'ux-studio' ) : esc_html__( 'Closed now', 'ux-studio' ) )
			. '</span>';
	}

	/**
	 * Emit Schema.org LocalBusiness JSON-LD for the resolved locations.
	 */
	public function json_ld(): void {
		if ( ! (bool) $this->module->setting( 'schema_enabled', false ) ) {
			return;
		}

		$ids = $this->schema_location_ids();
		if ( empty( $ids ) ) {
			return;
		}

		$items = array();
		foreach ( $ids as $id ) {
			$item = $this->build_schema( (int) $id );
			if ( null !== $item ) {
				$items[] = $item;
			}
		}
		if ( empty( $items ) ) {
			return;
		}

		$payload = 1 === count( $items ) ? $items[0] : array( '@graph' => $items );
		echo "\n<script type=\"application/ld+json\">";
		echo wp_json_encode( $payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		echo "</script>\n";
	}

	/* ------------------------------------------------------------------ */

	/**
	 * Resolve the location to render: explicit id, else the first location.
	 *
	 * @param int $id Requested id (0 = first available).
	 * @return array<string,mixed>|null
	 */
	private function resolve_location( int $id ): ?array {
		if ( $id > 0 ) {
			return $this->module->get_location( $id );
		}
		$all = $this->module->list_locations();
		return $all[0] ?? null;
	}

	/**
	 * Today's exception/holiday note for a location, or '' if none.
	 *
	 * @param array  $location Location array.
	 * @param string $today    Y-m-d.
	 */
	private function today_note( array $location, string $today ): string {
		foreach ( (array) ( $location['exceptions'] ?? array() ) as $exception ) {
			if ( ( $exception['date'] ?? '' ) === $today && '' !== (string) ( $exception['label'] ?? '' ) ) {
				return (string) $exception['label'];
			}
		}
		if ( (bool) $this->module->setting( 'holidays_closed', true ) ) {
			$holiday = Holidays::label_for( $today );
			if ( null !== $holiday ) {
				return $holiday;
			}
		}
		return '';
	}

	/**
	 * Location IDs for which JSON-LD should be emitted on the current view.
	 *
	 * @return int[]
	 */
	private function schema_location_ids(): array {
		if ( is_singular( Module::CPT ) ) {
			return array( (int) get_queried_object_id() );
		}

		$on_home = (bool) $this->module->setting( 'schema_on_homepage', true );
		if ( ! ( $on_home && ( is_front_page() || is_home() ) ) ) {
			return array();
		}

		$configured = array_filter( array_map( 'intval', preg_split( '/[\s,]+/', (string) $this->module->setting( 'schema_location_ids', '' ) ) ?: array() ) );
		if ( ! empty( $configured ) ) {
			return $configured;
		}
		return array_map( static fn ( $l ) => (int) $l['id'], $this->module->list_locations() );
	}

	/**
	 * Build one LocalBusiness schema array for a location.
	 *
	 * @param int $id Location id.
	 * @return array<string,mixed>|null
	 */
	private function build_schema( int $id ): ?array {
		$location = $this->module->get_location( $id );
		if ( null === $location ) {
			return null;
		}

		$data = array(
			'@context' => 'https://schema.org',
			'@type'    => 'LocalBusiness',
			'@id'      => home_url( '/#uxstudio-oh-' . $id ),
			'name'     => (string) $location['title'],
		);
		if ( '' !== (string) $location['address'] ) {
			$data['address'] = array(
				'@type'         => 'PostalAddress',
				'streetAddress' => (string) $location['address'],
			);
		}
		if ( null !== $location['lat'] && null !== $location['lng'] ) {
			$data['geo'] = array(
				'@type'     => 'GeoCoordinates',
				'latitude'  => (float) $location['lat'],
				'longitude' => (float) $location['lng'],
			);
		}

		$spec = array();
		foreach ( self::SCHEMA_DAYS as $key => $day ) {
			foreach ( (array) ( $location['hours'][ $key ] ?? array() ) as $range ) {
				$open  = (string) ( $range['open'] ?? '' );
				$close = (string) ( $range['close'] ?? '' );
				if ( '' !== $open && '' !== $close ) {
					$spec[] = array(
						'@type'     => 'OpeningHoursSpecification',
						'dayOfWeek' => $day,
						'opens'     => $open,
						'closes'    => $close,
					);
				}
			}
		}
		if ( ! empty( $spec ) ) {
			$data['openingHoursSpecification'] = $spec;
		}

		return $data;
	}

	/**
	 * Localized weekday names keyed by mon..sun.
	 *
	 * @return array<string,string>
	 */
	private function weekday_names(): array {
		$names = array();
		// 2024-01-01 was a Monday; walk seven days for localized long names.
		$base = new \DateTimeImmutable( '2024-01-01', wp_timezone() );
		foreach ( $this->module->day_keys() as $i => $day ) {
			$names[ $day ] = wp_date( 'l', $base->modify( '+' . $i . ' day' )->getTimestamp() );
		}
		return $names;
	}

	/**
	 * Render a day's ranges as "09:00–17:00, 18:00–22:00".
	 *
	 * @param array $ranges List of {open,close}.
	 */
	private function format_ranges( array $ranges ): string {
		$parts = array();
		foreach ( $ranges as $range ) {
			$open  = (string) ( $range['open'] ?? '' );
			$close = (string) ( $range['close'] ?? '' );
			if ( '' !== $open && '' !== $close ) {
				$parts[] = $open . '–' . $close;
			}
		}
		return implode( ', ', $parts );
	}

	/**
	 * Scoped stylesheet, printed at most once per request.
	 */
	private function css(): string {
		if ( self::$printed_css ) {
			return '';
		}
		self::$printed_css = true;

		return '<style>'
			. '.uxs-oh{max-width:360px;border:1px solid #e3e6ea;border-radius:10px;padding:16px;font-size:14px;line-height:1.5}'
			. '.uxs-oh__head{display:flex;align-items:center;justify-content:space-between;gap:8px;margin-bottom:6px}'
			. '.uxs-oh__title{font-weight:600}'
			. '.uxs-oh__badge{display:inline-block;padding:2px 10px;border-radius:999px;font-size:12px;font-weight:600}'
			. '.uxs-oh__badge.is-open{background:#dcfce7;color:#166534}'
			. '.uxs-oh__badge.is-closed{background:#fee2e2;color:#991b1b}'
			. '.uxs-oh__address{color:#5b6674;margin-bottom:6px}'
			. '.uxs-oh__note{color:#b45309;font-size:13px;margin-bottom:8px}'
			. '.uxs-oh__table{width:100%;border-collapse:collapse}'
			. '.uxs-oh__table th{text-align:left;font-weight:400;color:#5b6674;padding:3px 0}'
			. '.uxs-oh__table td{text-align:right;padding:3px 0}'
			. '.uxs-oh__table tr.is-today th,.uxs-oh__table tr.is-today td{font-weight:700;color:#1c2430}'
			. '</style>';
	}
}
