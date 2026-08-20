<?php
/**
 * Hides WordPress admin notices, optionally relocating them into a hidden
 * tray accessible via a bell button in the admin bar instead of dropping
 * them entirely.
 *
 * Ported from the legacy admin-customiser module (HideAdminNotices.php).
 * The legacy version exposed a "notice_type" select (default/hidden_tray/none)
 * plus a per-update-type (core/theme/plugin) checkbox list to silence update
 * nags; the new settings contract collapses this to two toggles:
 *   - hide_notices_enabled:   master on/off switch.
 *   - hide_notices_relocate:  when on, notices are moved to the tray (legacy
 *                              "hidden_tray"); when off, they are dropped
 *                              entirely (legacy "none").
 * Per-update-type suppression is dropped (not in the contract) - notices are
 * only hidden/relocated visually, update checks themselves are untouched.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\AdminCustomiser;

defined( 'ABSPATH' ) || exit;

final class HideAdminNotices {

	private bool $relocate;

	/**
	 * @param bool $relocate Whether to move notices into the tray (true) or hide them entirely (false).
	 */
	public function __construct( bool $relocate ) {
		$this->relocate = $relocate;
	}

	/**
	 * Register hooks. Only instantiated when hide_notices_enabled=true and the
	 * current user is logged in.
	 */
	public function register(): void {
		if ( ! is_user_logged_in() ) {
			return;
		}

		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ), 110 );

		if ( $this->relocate ) {
			add_action( 'admin_notices', array( $this, 'render_panel' ), 1 );
			add_action( 'admin_bar_menu', array( $this, 'add_admin_bar_button' ), 100 );
		}
	}

	/**
	 * Add the notifications bell to the admin bar (tray mode only).
	 *
	 * @param \WP_Admin_Bar $admin_bar Admin bar instance.
	 */
	public function add_admin_bar_button( $admin_bar ): void {
		$bell = '<svg class="uxstudio-bell" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9"/><path d="M10.3 21a1.94 1.94 0 0 0 3.4 0"/></svg>';

		$admin_bar->add_node(
			array(
				'id'     => 'uxstudio-notifications',
				'title'  => '<span class="ab-icon uxstudio-bell-icon" aria-hidden="true">' . $bell . '</span><span class="ab-label screen-reader-text">' . esc_html__( 'Notifications', 'ux-studio' ) . '</span><span class="uxstudio-notif-count" aria-hidden="true">0</span>',
				'href'   => '#',
				'meta'   => array(
					'class' => 'uxstudio-notifications-button',
					'title' => __( 'Notifications', 'ux-studio' ),
				),
				'parent' => 'top-secondary',
			)
		);
	}

	/**
	 * Enqueue CSS/JS. When not relocating, only a tiny inline rule that hides
	 * every notice outright is added (no separate asset needed).
	 */
	public function enqueue_assets(): void {
		if ( ! $this->relocate ) {
			wp_add_inline_style( 'wp-admin', 'body.wp-admin .notice { display: none !important; }' );
			return;
		}

		$css_rel = 'assets/css/admin-customiser-notices.css';
		$js_rel  = 'assets/js/admin-customiser-notices.js';
		$version = $this->asset_version( $css_rel, $js_rel );

		wp_enqueue_style( 'uxstudio-admin-customiser-notices', UXSTUDIO_URL . $css_rel, array(), $version );
		wp_enqueue_script( 'uxstudio-admin-customiser-notices', UXSTUDIO_URL . $js_rel, array(), $version, true );
	}

	/**
	 * Version string from filemtime, falling back to the plugin version.
	 *
	 * @param string $css_rel Relative CSS path.
	 * @param string $js_rel  Relative JS path.
	 */
	private function asset_version( string $css_rel, string $js_rel ): string {
		$css_path = UXSTUDIO_PATH . $css_rel;
		$js_path  = UXSTUDIO_PATH . $js_rel;
		$version  = (string) max(
			file_exists( $css_path ) ? filemtime( $css_path ) : 0,
			file_exists( $js_path ) ? filemtime( $js_path ) : 0
		);
		if ( '' === $version || '0' === $version ) {
			$version = defined( 'UXSTUDIO_VERSION' ) ? UXSTUDIO_VERSION : '1.0.0';
		}
		return $version;
	}

	/**
	 * Empty panel markup - populated client-side by admin-customiser-notices.js,
	 * which moves live `.notice` elements into it.
	 */
	public function render_panel(): void {
		?>
		<div id="uxstudio-notices__panel-wrap" class="uxstudio-notices">
			<div id="uxstudio-notices__panel" class="hidden" tabindex="-1" aria-label="<?php echo esc_attr__( 'Notifications', 'ux-studio' ); ?>"></div>
		</div>
		<?php
	}
}
