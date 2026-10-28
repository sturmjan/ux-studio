<?php
/**
 * Global quick-nav overlay: clicking the UX Studio top-level admin menu item
 * from ANY wp-admin screen opens a categorized panel of active, configurable
 * modules instead of navigating away. Mirrors the legacy plugin's megamenu
 * (admin/assets/js/megamenu.js) - reimplemented framework-free (plain JS/CSS,
 * no React) since it must load cheaply on every admin page, not just inside
 * the SPA.
 *
 * Every module is drawn with its lucide icon (same set as the SPA's Modules list,
 * inlined from launcher-icons.php) and the panel can show the modules as cards
 * or as compact rows; the choice is remembered by assets/js/megamenu.js.
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
		<div id="uxstudio-megamenu" class="uxstudio-megamenu" data-view="grid" aria-hidden="true" role="dialog" aria-label="<?php esc_attr_e( 'UX Studio quick navigation', 'ux-studio' ); ?>">
			<div class="uxstudio-megamenu__backdrop"></div>
			<div class="uxstudio-megamenu__panel">
				<div class="uxstudio-megamenu__header">
					<span class="uxstudio-megamenu__title"><?php esc_html_e( 'UX Studio', 'ux-studio' ); ?></span>
					<div class="uxstudio-megamenu__views" role="radiogroup" aria-label="<?php esc_attr_e( 'Display', 'ux-studio' ); ?>">
						<button type="button" class="uxstudio-megamenu__view" role="radio" aria-checked="true" data-view="grid" aria-label="<?php esc_attr_e( 'Cards', 'ux-studio' ); ?>" title="<?php esc_attr_e( 'Cards', 'ux-studio' ); ?>">
							<?php echo $this->icon( 'layout-grid' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG from launcher-icons.php. ?>
							<span class="uxstudio-megamenu__view-label"><?php esc_html_e( 'Cards', 'ux-studio' ); ?></span>
						</button>
						<button type="button" class="uxstudio-megamenu__view" role="radio" aria-checked="false" data-view="list" aria-label="<?php esc_attr_e( 'List', 'ux-studio' ); ?>" title="<?php esc_attr_e( 'List', 'ux-studio' ); ?>">
							<?php echo $this->icon( 'list' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG from launcher-icons.php. ?>
							<span class="uxstudio-megamenu__view-label"><?php esc_html_e( 'List', 'ux-studio' ); ?></span>
						</button>
					</div>
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
									<?php if ( ! empty( $module['children'] ) ) : ?>
										<div class="uxstudio-megamenu__subgroup">
											<a href="<?php echo esc_url( $base_url . '#/module?id=' . $module['id'] ); ?>" class="uxstudio-megamenu__subgroup-head">
												<?php echo $this->icon( $module['icon'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG from launcher-icons.php. ?>
												<span><?php echo esc_html( $module['name'] ); ?></span>
											</a>
											<div class="uxstudio-megamenu__grid">
												<?php foreach ( $module['children'] as $child ) : ?>
													<?php $this->render_card( $child, $base_url . '#/module?id=' . $module['id'] . '&feature=' . $child['id'] ); ?>
												<?php endforeach; ?>
											</div>
										</div>
									<?php else : ?>
										<?php $this->render_card( $module, $base_url . '#/module?id=' . $module['id'] ); ?>
									<?php endif; ?>
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
	 * One module card / row: icon, name, description, chevron (shown in the list view).
	 *
	 * @param array{id:string,name:string,description:string,icon:string} $module Module data.
	 * @param string                                                      $url    Destination.
	 */
	private function render_card( array $module, string $url ): void {
		?>
		<a href="<?php echo esc_url( $url ); ?>" class="uxstudio-megamenu__card">
			<span class="uxstudio-megamenu__card-icon"><?php echo $this->icon( $module['icon'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG from launcher-icons.php. ?></span>
			<span class="uxstudio-megamenu__card-text">
				<span class="uxstudio-megamenu__card-name"><?php echo esc_html( $module['name'] ); ?></span>
				<span class="uxstudio-megamenu__card-desc"><?php echo esc_html( $module['description'] ); ?></span>
			</span>
			<span class="uxstudio-megamenu__card-chevron"><?php echo $this->icon( 'chevron-right' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG from launcher-icons.php. ?></span>
		</a>
		<?php
	}

	/**
	 * Inline SVG of a lucide icon by its meta.json name (the same set the SPA's
	 * Modules list draws, see bin/gen-launcher-icons.mjs); Puzzle when unknown,
	 * like the SPA's <ModuleIcon>.
	 *
	 * @param string $name Icon name from meta.json.
	 */
	private function icon( string $name ): string {
		static $icons = null;
		if ( null === $icons ) {
			$icons = require __DIR__ . '/launcher-icons.php';
		}
		$inner = $icons[ $name ] ?? $icons['puzzle'] ?? '';
		return '<svg class="uxstudio-megamenu__icon" xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' . $inner . '</svg>';
	}

	/**
	 * Active + configurable modules, keyed by meta.json `group`, in
	 * registry-discovery order. A virtual group (e.g. "Dashboard") becomes one
	 * entry carrying its active features as `children`; the features themselves
	 * are not listed a second time, and a group with no active feature is left out.
	 *
	 * @return array<string, array<int, array<string, mixed>>>
	 */
	private function grouped_modules(): array {
		$enabled = $this->modules->enabled_ids();
		$all     = $this->modules->all();
		$grouped = array();

		foreach ( $all as $id => $meta ) {
			if ( isset( $meta['parent'] ) ) {
				continue; // Listed under its group.
			}
			$category = (string) ( $meta['group'] ?? 'other' );

			if ( Modules::is_group( $meta ) ) {
				$children = array();
				foreach ( (array) $meta['children'] as $child_id ) {
					$child_id = (string) $child_id;
					$child    = $all[ $child_id ] ?? null;
					if ( ! $child || ! in_array( $child_id, $enabled, true ) || empty( $child['settings'] ) ) {
						continue;
					}
					$children[] = $this->entry( $child_id, $child );
				}
				if ( $children ) {
					$grouped[ $category ][] = $this->entry( $id, $meta ) + array( 'children' => $children );
				}
				continue;
			}

			if ( ! in_array( $id, $enabled, true ) || empty( $meta['settings'] ) ) {
				continue;
			}
			$grouped[ $category ][] = $this->entry( $id, $meta );
		}

		return $grouped;
	}

	/**
	 * @param string               $id   Module id.
	 * @param array<string, mixed> $meta meta.json contents.
	 * @return array{id:string,name:string,description:string,icon:string}
	 */
	private function entry( string $id, array $meta ): array {
		return array(
			'id'          => $id,
			'name'        => $this->translate_meta( (string) ( $meta['name'] ?? $id ) ),
			'description' => $this->translate_meta( (string) ( $meta['description'] ?? '' ) ),
			'icon'        => (string) ( $meta['icon'] ?? 'puzzle' ),
		);
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
