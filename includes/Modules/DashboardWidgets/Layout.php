<?php
/**
 * Dashboard Widgets - column count and forced widget layout.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\DashboardWidgets;

use UxStudio\Core\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Ported from the legacy dashboard-widgets module:
 *  - dashboard_columns: overrides the per-user "screen layout" column count.
 *  - force_layout: every user gets the widget order / collapsed state that an
 *    admin saved with "Save current layout as default".
 *
 * The saved layout is stored in uxstudio_* options; for sites migrated from the
 * legacy plugin the legacy ux1_dashboard_forced_* options are used as fallback.
 */
final class Layout {

	private const OPTION_ORDER  = 'uxstudio_dashboard_forced_layout';
	private const OPTION_CLOSED = 'uxstudio_dashboard_forced_closed';
	private const LEGACY_ORDER  = 'ux1_dashboard_forced_layout';
	private const LEGACY_CLOSED = 'ux1_dashboard_forced_closed';

	private Settings $settings;

	/**
	 * @param Settings $settings The dashboard-widgets module settings.
	 */
	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Hook the filters according to the settings.
	 */
	public function boot(): void {
		$columns = (int) $this->settings->get( 'dashboard_columns', 0 );
		if ( $columns >= 1 && $columns <= 4 ) {
			add_filter(
				'get_user_option_screen_layout_dashboard',
				static function () use ( $columns ): int {
					return $columns;
				}
			);
		}

		if ( $this->settings->get( 'force_layout', false ) ) {
			add_filter( 'get_user_option_meta-box-order_dashboard', array( $this, 'forced_order' ) );
			add_filter( 'get_user_option_closedpostboxes_dashboard', array( $this, 'forced_closed' ) );
		}
	}

	/**
	 * Replace a user's widget order with the saved one.
	 *
	 * @param mixed $value Current value.
	 * @return mixed
	 */
	public function forced_order( $value ) {
		$forced = $this->saved_order();
		return array() !== $forced ? $forced : $value;
	}

	/**
	 * Replace a user's collapsed-widgets list with the saved one.
	 *
	 * @param mixed $value Current value.
	 * @return mixed
	 */
	public function forced_closed( $value ) {
		$forced = get_option( self::OPTION_CLOSED, get_option( self::LEGACY_CLOSED, null ) );
		return is_array( $forced ) ? $forced : $value;
	}

	/**
	 * Saved widget order (column => comma separated ids).
	 *
	 * @return array<string,string>
	 */
	private function saved_order(): array {
		$order = get_option( self::OPTION_ORDER, get_option( self::LEGACY_ORDER, array() ) );
		return is_array( $order ) ? $order : array();
	}

	/**
	 * Store the CURRENT user's dashboard arrangement as the default for everybody.
	 *
	 * @return array{ok:bool,error?:string,summary?:string}
	 */
	public function save_current(): array {
		$user_id = get_current_user_id();
		$order   = get_user_option( 'meta-box-order_dashboard', $user_id );
		$closed  = get_user_option( 'closedpostboxes_dashboard', $user_id );

		if ( empty( $order ) || ! is_array( $order ) ) {
			return array(
				'ok'    => false,
				'error' => __( 'No layout found. Open the Dashboard first, arrange the widgets and come back.', 'ux-studio' ),
			);
		}

		$clean = array();
		foreach ( $order as $column => $ids ) {
			$ids = is_array( $ids ) ? implode( ',', $ids ) : (string) $ids;
			$ids = implode( ',', array_filter( array_map( 'sanitize_key', explode( ',', $ids ) ) ) );
			$clean[ sanitize_key( (string) $column ) ] = $ids;
		}

		update_option( self::OPTION_ORDER, $clean, false );
		update_option(
			self::OPTION_CLOSED,
			is_array( $closed ) ? array_values( array_map( 'sanitize_key', $closed ) ) : array(),
			false
		);

		return array(
			'ok'      => true,
			'summary' => $this->summary(),
		);
	}

	/**
	 * Human summary of the saved layout, '' when none.
	 */
	public function summary(): string {
		$saved = $this->saved_order();
		if ( empty( $saved ) ) {
			return '';
		}

		$total = 0;
		$parts = array();
		foreach ( $saved as $column => $ids ) {
			$count  = '' !== (string) $ids ? count( array_filter( explode( ',', (string) $ids ) ) ) : 0;
			$total += $count;
			if ( $count > 0 ) {
				$parts[] = $column . ': ' . $count;
			}
		}

		return sprintf(
			/* translators: 1: number of widgets, 2: per-column breakdown */
			__( 'Saved: %1$d widgets (%2$s)', 'ux-studio' ),
			$total,
			implode( ', ', $parts )
		);
	}
}
