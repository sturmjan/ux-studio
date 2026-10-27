<?php
/**
 * Locations of the Opening Hours module (name, address, weekly hours, exceptions, announcements, coordinates).
 *
 * @package UxStudio
 */

namespace UxStudio\Core\Transfer\Providers;

use UxStudio\Modules\OpeningHours\Module;

defined( 'ABSPATH' ) || exit;

/**
 * A location is matched by its name. The temporary "closes later today" override is a live state, not a
 * definition, and is not exported.
 */
final class LocationsProvider extends AbstractProvider {

	private ?Module $module = null;

	/**
	 * @param Module|null $module Live module instance.
	 */
	public function __construct( ?Module $module = null ) {
		$this->module = $module;
	}

	public function module(): string {
		return 'opening-hours';
	}

	public function kind(): string {
		return 'locations';
	}

	public function label(): string {
		return __( 'Locations and opening hours', 'ux-studio' );
	}

	public function blocked_reason(): string {
		return post_type_exists( Module::CPT ) ? '' : __( 'The module has not started yet in this request. Reload the page and try again.', 'ux-studio' );
	}

	public function count(): int {
		return count( $this->current_items() );
	}

	protected function key_of( array $item ): string {
		return (string) ( $item['title'] ?? '' );
	}

	protected function current_items(): array {
		if ( ! $this->module || '' !== $this->blocked_reason() ) {
			return array();
		}
		$out = array();
		foreach ( $this->module->list_locations() as $location ) {
			$out[] = array(
				'ref'  => (int) $location['id'],
				'item' => $location,
			);
		}
		return $out;
	}

	protected function clean_item( $raw ): array {
		$fail = static fn( string $m ): array => array(
			'ok'      => false,
			'item'    => array(),
			'error'   => $m,
			'warning' => '',
		);
		if ( ! is_array( $raw ) ) {
			return $fail( __( 'Not a location.', 'ux-studio' ) );
		}
		$title = self::text( $raw['title'] ?? null, 200 );
		if ( null === $title || '' === trim( $title ) ) {
			return $fail( __( 'The location has no name.', 'ux-studio' ) );
		}
		$hours         = is_array( $raw['hours'] ?? null ) ? Module::sanitize_hours( $raw['hours'] ) : array();
		$exceptions    = is_array( $raw['exceptions'] ?? null ) ? Module::sanitize_exceptions( $raw['exceptions'] ) : array();
		$announcements = is_array( $raw['announcements'] ?? null ) ? Module::sanitize_announcements( $raw['announcements'] ) : array();
		$lat           = Module::sanitize_lat( $raw['lat'] ?? '' );
		$lng           = Module::sanitize_lng( $raw['lng'] ?? '' );
		return array(
			'ok'      => true,
			'item'    => array(
				'title'         => trim( $title ),
				'address'       => (string) self::text( $raw['address'] ?? '', 500 ),
				'phone'         => (string) self::text( $raw['phone'] ?? '', 100 ),
				'hours'         => $hours,
				'exceptions'    => $exceptions,
				'announcements' => $announcements,
				'lat'           => '' === $lat ? null : (float) $lat,
				'lng'           => '' === $lng ? null : (float) $lng,
			),
			'error'   => '',
			'warning' => '',
		);
	}

	protected function create_item( array $item ) {
		$result = $this->module ? $this->module->create_location( $item ) : null;
		return is_array( $result ) ? (int) $result['id'] : null;
	}

	protected function update_item( $ref, array $item ): bool {
		return $this->module && is_array( $this->module->update_location( (int) $ref, $item ) );
	}

	protected function remove_item( $ref ): bool {
		return $this->module ? $this->module->delete_location( (int) $ref ) : false;
	}
}
