<?php
/**
 * Admin-bar page-load indicator for logged-in admins (frontend + wp-admin).
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\PageLoad;

use WP_Admin_Bar;

defined( 'ABSPATH' ) || exit;

/**
 * Ported from the legacy page-load admin-bar node. Shows the current request's
 * generation time (colour-coded) plus a dropdown with the DB query count and
 * peak memory. Only rendered for users who can manage_options.
 */
final class AdminBar {

	/**
	 * Register the admin-bar node and its inline styles (both contexts).
	 */
	public function register(): void {
		add_action( 'admin_bar_menu', array( $this, 'add_node' ), 999 );
		add_action( 'wp_head', array( $this, 'print_styles' ) );
		add_action( 'admin_head', array( $this, 'print_styles' ) );
	}

	/**
	 * Whether the indicator should render for the current user/context.
	 */
	private function should_render(): bool {
		return is_admin_bar_showing() && current_user_can( 'manage_options' );
	}

	/**
	 * Add the load-time node (and its metrics dropdown) to the admin bar.
	 *
	 * @param WP_Admin_Bar $bar Admin bar instance.
	 */
	public function add_node( WP_Admin_Bar $bar ): void {
		if ( ! $this->should_render() ) {
			return;
		}

		$start     = isset( $_SERVER['REQUEST_TIME_FLOAT'] ) ? (float) $_SERVER['REQUEST_TIME_FLOAT'] : microtime( true );
		$seconds   = max( 0.0, microtime( true ) - $start );
		$formatted = number_format( $seconds, 3, '.', '' ) . 's';
		$color     = $this->color_for( $seconds );

		$queries = function_exists( 'get_num_queries' ) ? (int) get_num_queries() : 0;
		$memory  = number_format( memory_get_peak_usage( true ) / 1048576, 1 );

		$icon = '<span class="uxs-pl__icon"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" width="16" height="16"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm.75-13a.75.75 0 00-1.5 0v5c0 .414.336.75.75.75h4a.75.75 0 000-1.5h-3.25V5z" clip-rule="evenodd"/></svg></span>';

		$bar->add_node(
			array(
				'id'     => 'uxstudio-page-load',
				'parent' => 'top-secondary',
				'title'  => '<span class="uxs-pl">' . $icon
					. '<span class="uxs-pl__time" style="color:' . esc_attr( $color ) . ';">' . esc_html( $formatted ) . '</span></span>',
				'meta'   => array(
					'class' => 'uxstudio-page-load-node',
					'title' => sprintf(
						/* translators: %s: page generation time. */
						__( 'Page generated in %s', 'ux-studio' ),
						$formatted
					),
				),
			)
		);

		$bar->add_node(
			array(
				'id'     => 'uxstudio-page-load-info',
				'parent' => 'uxstudio-page-load',
				'title'  => '<span class="uxs-pl-drop">'
					. '<span class="uxs-pl-drop__label">' . esc_html__( 'Load time', 'ux-studio' ) . '</span>'
					. '<span class="uxs-pl-drop__time" style="color:' . esc_attr( $color ) . ';">' . esc_html( $formatted ) . '</span>'
					. '<span class="uxs-pl-drop__row"><span class="uxs-pl-drop__key">' . esc_html__( 'DB queries', 'ux-studio' ) . '</span>'
					. '<span class="uxs-pl-drop__val">' . esc_html( (string) $queries ) . '</span></span>'
					. '<span class="uxs-pl-drop__row"><span class="uxs-pl-drop__key">' . esc_html__( 'Peak memory', 'ux-studio' ) . '</span>'
					. '<span class="uxs-pl-drop__val">' . esc_html( $memory . ' MB' ) . '</span></span>'
					. '</span>',
				'meta'   => array( 'class' => 'uxstudio-page-load-dropdown' ),
			)
		);
	}

	/**
	 * Colour for a generation time: green < 0.5s, amber <= 1.5s, red above.
	 *
	 * @param float $seconds Generation time in seconds.
	 */
	private function color_for( float $seconds ): string {
		if ( $seconds < 0.5 ) {
			return '#00a32a';
		}
		if ( $seconds <= 1.5 ) {
			return '#dba617';
		}
		return '#d63638';
	}

	/**
	 * Print the (once-per-request) inline styles for the admin-bar node.
	 */
	public function print_styles(): void {
		if ( ! $this->should_render() ) {
			return;
		}
		static $printed = false;
		if ( $printed ) {
			return;
		}
		$printed = true;
		?>
<style id="uxstudio-page-load-css">
#wp-admin-bar-uxstudio-page-load > .ab-item { padding: 0 8px !important; height: 32px; }
.uxs-pl { display: inline-flex !important; align-items: center; gap: 6px; background: rgba(255,255,255,.08); border-radius: 4px; padding: 0 10px; height: 26px; transition: background .15s; }
#wp-admin-bar-uxstudio-page-load:hover .uxs-pl { background: rgba(255,255,255,.18); }
.uxs-pl__icon { display: flex; align-items: center; opacity: .7; }
.uxs-pl__icon svg { width: 14px; height: 14px; }
.uxs-pl__time { font-size: 13px; font-weight: 500; font-variant-numeric: tabular-nums; letter-spacing: .03em; line-height: 26px; }
#wp-admin-bar-uxstudio-page-load .ab-sub-wrapper { min-width: 190px !important; }
#wp-admin-bar-uxstudio-page-load-info > .ab-item { padding: 0 !important; line-height: 1 !important; height: auto !important; }
#wp-admin-bar-uxstudio-page-load-info > .ab-item:hover { background: none !important; color: inherit !important; }
.uxs-pl-drop { display: flex; flex-direction: column; align-items: center; padding: 14px 16px; text-align: center; }
.uxs-pl-drop__label { font-size: 10px; font-weight: 600; text-transform: uppercase; letter-spacing: .08em; color: rgba(255,255,255,.5); margin-bottom: 8px; }
.uxs-pl-drop__time { font-size: 28px; font-weight: 600; font-variant-numeric: tabular-nums; letter-spacing: .04em; line-height: 1; margin-bottom: 10px; }
.uxs-pl-drop__row { display: flex; justify-content: space-between; width: 100%; padding: 4px 0; border-top: 1px solid rgba(255,255,255,.08); font-size: 12px; }
.uxs-pl-drop__key { color: rgba(255,255,255,.5); }
.uxs-pl-drop__val { color: rgba(255,255,255,.85); font-variant-numeric: tabular-nums; }
</style>
		<?php
	}
}
