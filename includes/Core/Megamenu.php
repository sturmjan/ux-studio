<?php
/**
 * Global quick-nav overlay: clicking the UX Studio top-level admin menu item
 * from ANY wp-admin screen opens a categorized panel of active, configurable
 * modules instead of navigating away. Mirrors the legacy plugin's megamenu
 * (admin/assets/js/megamenu.js) - reimplemented framework-free (plain JS/CSS,
 * no React) since it must load cheaply on every admin page, not just inside
 * the SPA.
 *
 * @package UxStudio
 */

namespace UxStudio\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Renders + enqueues the overlay. Only ever shown to manage_options users,
 * and only lists modules that are both enabled and have a real settings/
 * detail destination (same `settings` gate as the SPA grid - no dead links).
 */
final class Megamenu {

	private const CATEGORY_ICONS = array(
		'content'     => 'dashicons-admin-page',
		'admin'       => 'dashicons-admin-tools',
		'media'       => 'dashicons-format-image',
		'security'    => 'dashicons-shield',
		'performance' => 'dashicons-performance',
		'developer'   => 'dashicons-editor-code',
	);

	private Modules $modules;

	public function __construct( Modules $modules ) {
		$this->modules = $modules;
	}

	/**
	 * Hook enqueue + render on every admin page.
	 */
	public function register(): void {
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'admin_footer', array( $this, 'render' ) );
	}

	/**
	 * Enqueue the standalone JS/CSS (not part of the SPA bundle).
	 */
	public function enqueue_assets(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$css_rel  = 'assets/css/megamenu.css';
		$js_rel   = 'assets/js/megamenu.js';
		$css_path = UXSTUDIO_PATH . $css_rel;
		$js_path  = UXSTUDIO_PATH . $js_rel;
		$version  = (string) max(
			file_exists( $css_path ) ? filemtime( $css_path ) : 0,
			file_exists( $js_path ) ? filemtime( $js_path ) : 0
		);
		if ( '' === $version || '0' === $version ) {
			$version = UXSTUDIO_VERSION;
		}

		wp_enqueue_style( 'uxstudio-megamenu', UXSTUDIO_URL . $css_rel, array( 'dashicons' ), $version );
		wp_enqueue_script( 'uxstudio-megamenu', UXSTUDIO_URL . $js_rel, array(), $version, true );
	}

	/**
	 * Render the overlay markup in the admin footer.
	 */
	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$grouped = $this->grouped_modules();
		if ( empty( $grouped ) ) {
			return;
		}

		$base_url = admin_url( 'admin.php?page=ux-studio' );
		?>
		<div id="uxstudio-megamenu" class="uxstudio-megamenu" aria-hidden="true" role="dialog" aria-label="<?php esc_attr_e( 'UX Studio quick navigation', 'ux-studio' ); ?>">
			<div class="uxstudio-megamenu__backdrop"></div>
			<div class="uxstudio-megamenu__panel">
				<div class="uxstudio-megamenu__header">
					<span class="uxstudio-megamenu__title"><?php esc_html_e( 'UX Studio', 'ux-studio' ); ?></span>
					<a href="<?php echo esc_url( $base_url ); ?>" class="uxstudio-megamenu__all-link">
						<span class="dashicons dashicons-screenoptions"></span>
						<?php esc_html_e( 'All modules', 'ux-studio' ); ?>
					</a>
					<button type="button" class="uxstudio-megamenu__close" aria-label="<?php esc_attr_e( 'Close', 'ux-studio' ); ?>">
						<span class="dashicons dashicons-no-alt"></span>
					</button>
				</div>
				<div class="uxstudio-megamenu__body">
					<?php foreach ( $grouped as $group => $items ) : ?>
						<div class="uxstudio-megamenu__category">
							<h3 class="uxstudio-megamenu__category-title">
								<span class="dashicons <?php echo esc_attr( self::CATEGORY_ICONS[ $group ] ?? 'dashicons-admin-generic' ); ?>"></span>
								<?php echo esc_html( $this->category_label( $group ) ); ?>
							</h3>
							<div class="uxstudio-megamenu__grid">
								<?php foreach ( $items as $module ) : ?>
									<a href="<?php echo esc_url( $base_url . '#/module?id=' . $module['id'] ); ?>" class="uxstudio-megamenu__card">
										<span class="uxstudio-megamenu__card-name"><?php echo esc_html( $module['name'] ); ?></span>
										<span class="uxstudio-megamenu__card-desc"><?php echo esc_html( $module['description'] ); ?></span>
									</a>
								<?php endforeach; ?>
							</div>
						</div>
					<?php endforeach; ?>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Active + configurable modules, keyed by meta.json `group`, in
	 * registry-discovery order.
	 *
	 * @return array<string, array<int, array{id:string,name:string,description:string}>>
	 */
	private function grouped_modules(): array {
		$enabled = $this->modules->enabled_ids();
		$grouped = array();

		foreach ( $this->modules->all() as $id => $meta ) {
			if ( ! in_array( $id, $enabled, true ) || empty( $meta['settings'] ) ) {
				continue;
			}
			$group             = (string) ( $meta['group'] ?? 'other' );
			$grouped[ $group ][] = array(
				'id'          => $id,
				'name'        => $this->translate_meta( $meta['name'] ?? $id ),
				'description' => $this->translate_meta( $meta['description'] ?? '' ),
			);
		}

		return $grouped;
	}

	/**
	 * Translate a module meta string the same way Core\Rest does for the SPA
	 * grid, so the megamenu shows the same localized names/descriptions.
	 *
	 * @param string $text English source string from meta.json.
	 */
	private function translate_meta( string $text ): string {
		if ( '' === $text ) {
			return '';
		}
		return translate( $text, 'ux-studio' );
	}

	/**
	 * @param string $group meta.json group slug.
	 */
	private function category_label( string $group ): string {
		switch ( $group ) {
			case 'content':
				return __( 'Content', 'ux-studio' );
			case 'admin':
				return __( 'Admin Tools', 'ux-studio' );
			case 'media':
				return __( 'Media', 'ux-studio' );
			case 'security':
				return __( 'Security', 'ux-studio' );
			case 'performance':
				return __( 'Performance', 'ux-studio' );
			case 'developer':
				return __( 'Developer', 'ux-studio' );
			default:
				return __( 'Other', 'ux-studio' );
		}
	}
}
