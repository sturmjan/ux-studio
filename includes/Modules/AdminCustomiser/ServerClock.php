<?php
/**
 * Server clock in the admin bar: either a standalone "pill" node in
 * top-secondary with a dropdown (date/timezone), or - when the admin bar
 * "+" consolidation menu exists (AdminBar::register()) - the last row inside
 * that dropdown.
 *
 * Ported from the legacy admin-customiser module (ServerClock.php), same
 * dual placement logic; `server_clock_position` (admin-bar-pill|plus-menu)
 * replaces the legacy auto-detection (which always preferred the "+" menu
 * when the "consolidate_plugin_actions" toggle was on).
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\AdminCustomiser;

defined( 'ABSPATH' ) || exit;

final class ServerClock {

	private string $position;

	/**
	 * @param string $position 'admin-bar-pill' or 'plus-menu'.
	 */
	public function __construct( string $position ) {
		$this->position = $position;
	}

	/**
	 * Register hooks. Only instantiated when server_clock_enabled=true.
	 */
	public function register(): void {
		// Run AFTER AdminBar::reorganize_late() (PHP_INT_MAX - 100) so the
		// "+" menu and its quick-action children already exist when in plus-menu mode.
		add_action( 'wp_before_admin_bar_render', array( $this, 'add_clock_node' ), PHP_INT_MAX );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	/**
	 * Add the server clock node(s) to the admin bar.
	 */
	public function add_clock_node(): void {
		if ( ! is_admin_bar_showing() ) {
			return;
		}

		global $wp_admin_bar;
		if ( ! $wp_admin_bar instanceof \WP_Admin_Bar ) {
			return;
		}

		$wp_admin_bar->remove_node( 'uxstudio-server-clock' );
		$wp_admin_bar->remove_node( 'uxstudio-server-clock-info' );

		$server_time = current_time( 'H:i:s' );
		$server_date = current_time( 'j. n. Y' );
		$timezone    = wp_timezone_string();

		$plus_node = $wp_admin_bar->get_node( 'uxstudio-plus-menu' );
		$use_plus  = ( 'plus-menu' === $this->position ) && null !== $plus_node;

		if ( $use_plus ) {
			$title  = '<span class="uxstudio-plus-clock">';
			$title .= '<span class="uxstudio-plus-clock__row">';
			$title .= '<span class="uxstudio-plus-clock__label">' . esc_html__( 'Server time', 'ux-studio' ) . '</span>';
			$title .= '<span class="uxstudio-plus-clock__time" id="uxstudio-server-clock-time">' . esc_html( $server_time ) . '</span>';
			$title .= '</span>';
			$title .= '<span class="uxstudio-plus-clock__row uxstudio-plus-clock__row--sub">';
			$title .= '<span class="uxstudio-plus-clock__date" id="uxstudio-server-clock-date">' . esc_html( $server_date ) . '</span>';
			$title .= '<span class="uxstudio-plus-clock__tz">' . esc_html( $timezone ) . '</span>';
			$title .= '</span>';
			$title .= '</span>';

			$wp_admin_bar->add_node(
				array(
					'id'     => 'uxstudio-server-clock',
					'parent' => 'uxstudio-plus-menu',
					'title'  => $title,
					'href'   => false,
					'meta'   => array( 'class' => 'uxstudio-plus-clock-item' ),
				)
			);

			return;
		}

		// Fallback / explicit pill: standalone node in top-secondary.
		$wp_admin_bar->add_node(
			array(
				'id'     => 'uxstudio-server-clock',
				'parent' => 'top-secondary',
				'title'  => '<span class="uxstudio-clock">'
							. '<span class="uxstudio-clock__icon"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" width="16" height="16"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm.75-13a.75.75 0 00-1.5 0v5c0 .414.336.75.75.75h4a.75.75 0 000-1.5h-3.25V5z" clip-rule="evenodd"/></svg></span>'
							. '<span class="uxstudio-clock__time" id="uxstudio-server-clock-time">' . esc_html( $server_time ) . '</span>'
							. '</span>',
				'meta'   => array( 'class' => 'uxstudio-server-clock-node' ),
			)
		);

		$wp_admin_bar->add_node(
			array(
				'id'     => 'uxstudio-server-clock-info',
				'parent' => 'uxstudio-server-clock',
				'title'  => '<span class="uxstudio-clock-dropdown">'
							. '<span class="uxstudio-clock-dropdown__label">' . esc_html__( 'Server time', 'ux-studio' ) . '</span>'
							. '<span class="uxstudio-clock-dropdown__date" id="uxstudio-server-clock-date">' . esc_html( $server_date ) . '</span>'
							. '<span class="uxstudio-clock-dropdown__time" id="uxstudio-server-clock-time-lg">' . esc_html( $server_time ) . '</span>'
							. '<span class="uxstudio-clock-dropdown__tz">' . esc_html( $timezone ) . '</span>'
							. '</span>',
				'meta'   => array( 'class' => 'uxstudio-server-clock-dropdown' ),
			)
		);
	}

	/**
	 * Enqueue the ticking-clock CSS/JS.
	 */
	public function enqueue_assets(): void {
		if ( ! is_admin_bar_showing() ) {
			return;
		}

		$css_rel = 'assets/css/admin-customiser-clock.css';
		$js_rel  = 'assets/js/admin-customiser-clock.js';
		$css_path = UXSTUDIO_PATH . $css_rel;
		$js_path  = UXSTUDIO_PATH . $js_rel;
		$version  = (string) max(
			file_exists( $css_path ) ? filemtime( $css_path ) : 0,
			file_exists( $js_path ) ? filemtime( $js_path ) : 0
		);
		if ( '' === $version || '0' === $version ) {
			$version = defined( 'UXSTUDIO_VERSION' ) ? UXSTUDIO_VERSION : '1.0.0';
		}

		wp_enqueue_style( 'uxstudio-admin-customiser-clock', UXSTUDIO_URL . $css_rel, array(), $version );
		wp_enqueue_script( 'uxstudio-admin-customiser-clock', UXSTUDIO_URL . $js_rel, array(), $version, true );

		wp_localize_script(
			'uxstudio-admin-customiser-clock',
			'uxstudioServerClock',
			array( 'timestamp' => (int) current_time( 'timestamp' ) ) // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp.Requested
		);
	}
}
