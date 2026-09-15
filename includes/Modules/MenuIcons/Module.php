<?php
/**
 * Menu Icons & Item Status module - per-item icon + hidden/disabled state.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\MenuIcons;

use UxStudio\Modules\BaseModule;
use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * Adds an icon picker (SVG paste, Lucide, or Media Library) and a
 * visible/hidden/disabled status to each navigation menu item, independent
 * of the login-state visibility handled by the MenuVisibility module.
 */
final class Module extends BaseModule {

	private const STATUS_META    = '_uxstudio_menu_item_status';
	private const ICON_TYPE_META = '_uxstudio_menu_item_icon_type';
	private const ICON_VAL_META  = '_uxstudio_menu_item_icon_value';
	private const POSITION_META  = '_uxstudio_menu_item_icon_position';

	private const NONCE_ACTION = 'uxstudio_menu_icons';
	private const NONCE_FIELD  = 'uxstudio_menu_icons_nonce';
	private const FIELD_BASE   = 'uxstudio_menu_icons';

	private const STATUSES  = array( 'visible', 'hidden', 'disabled' );
	private const ICON_TYPES = array( 'none', 'lucide', 'svg', 'media' );
	private const POSITIONS = array( 'before', 'after', 'only' );

	/**
	 * Register hooks.
	 */
	public function boot(): void {
		add_action( 'wp_nav_menu_item_custom_fields', array( $this, 'render_fields' ), 10, 4 );
		add_action( 'wp_update_nav_menu_item', array( $this, 'save_fields' ), 10, 2 );

		add_filter( 'wp_get_nav_menu_items', array( $this, 'filter_hidden' ), 10, 1 );
		add_filter( 'wp_nav_menu_objects', array( $this, 'mark_disabled' ), 10, 1 );
		add_filter( 'nav_menu_link_attributes', array( $this, 'filter_link_attributes' ), 10, 2 );
		add_filter( 'nav_menu_css_class', array( $this, 'filter_css_class' ), 10, 2 );
		add_filter( 'nav_menu_item_title', array( $this, 'inject_icon' ), 10, 2 );

		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_frontend_assets' ) );
	}

	/**
	 * Render the icon + status controls inside the menu item admin panel.
	 *
	 * @param int       $item_id Menu item ID.
	 * @param WP_Post   $item    Menu item object.
	 * @param int       $depth   Menu item depth.
	 * @param \stdClass $args    Additional arguments.
	 */
	public function render_fields( int $item_id, WP_Post $item, int $depth, \stdClass $args ): void {
		$status       = $this->get_meta( $item_id, self::STATUS_META, 'visible', self::STATUSES );
		$icon_type    = $this->get_meta( $item_id, self::ICON_TYPE_META, 'none', self::ICON_TYPES );
		$icon_value   = (string) get_post_meta( $item_id, self::ICON_VAL_META, true );
		$position     = $this->get_meta( $item_id, self::POSITION_META, 'before', self::POSITIONS );
		$field        = fn( string $suffix ) => sprintf( '%s[%s][%d]', self::FIELD_BASE, $suffix, $item_id );
		$status_labels = array(
			'visible'  => __( 'Visible', 'ux-studio' ),
			'hidden'   => __( 'Hidden', 'ux-studio' ),
			'disabled' => __( 'Disabled (shown, not clickable)', 'ux-studio' ),
		);
		?>
		<fieldset class="field-uxstudio-menu-icons description-wide" data-uxs-item="<?php echo esc_attr( (string) $item_id ); ?>">
			<?php wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD ); ?>

			<legend class="menu-item-title"><?php esc_html_e( 'Item status', 'ux-studio' ); ?></legend>
			<?php foreach ( $status_labels as $value => $label ) : ?>
				<label class="menu-item-status-option">
					<input type="radio" name="<?php echo esc_attr( $field( 'status' ) ); ?>" value="<?php echo esc_attr( $value ); ?>" <?php checked( $value, $status ); ?> />
					<?php echo esc_html( $label ); ?>
				</label>
			<?php endforeach; ?>

			<p class="description"><label>
				<?php esc_html_e( 'Icon source', 'ux-studio' ); ?><br />
				<select class="widefat uxs-icon-type" name="<?php echo esc_attr( $field( 'icon_type' ) ); ?>">
					<option value="none" <?php selected( 'none', $icon_type ); ?>><?php esc_html_e( 'None', 'ux-studio' ); ?></option>
					<option value="lucide" <?php selected( 'lucide', $icon_type ); ?>><?php esc_html_e( 'Lucide icon', 'ux-studio' ); ?></option>
					<option value="svg" <?php selected( 'svg', $icon_type ); ?>><?php esc_html_e( 'Custom SVG', 'ux-studio' ); ?></option>
					<option value="media" <?php selected( 'media', $icon_type ); ?>><?php esc_html_e( 'Media Library', 'ux-studio' ); ?></option>
				</select>
			</label></p>

			<p class="description uxs-icon-field uxs-icon-field-lucide">
				<label>
					<?php esc_html_e( 'Lucide icon name', 'ux-studio' ); ?><br />
					<select class="widefat" name="<?php echo esc_attr( $field( 'icon_lucide' ) ); ?>">
						<option value=""><?php esc_html_e( '— choose —', 'ux-studio' ); ?></option>
						<?php foreach ( IconLibrary::choices() as $value => $label ) : ?>
							<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $value, 'lucide' === $icon_type ? $icon_value : '' ); ?>><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
				</label>
			</p>

			<p class="description uxs-icon-field uxs-icon-field-svg">
				<label>
					<?php esc_html_e( 'Paste SVG markup', 'ux-studio' ); ?><br />
					<textarea class="widefat code" rows="3" name="<?php echo esc_attr( $field( 'icon_svg' ) ); ?>"><?php echo 'svg' === $icon_type ? esc_textarea( $icon_value ) : ''; ?></textarea>
				</label>
				<span class="description"><?php esc_html_e( 'Sanitized on save; only basic shape elements are kept.', 'ux-studio' ); ?></span>
			</p>

			<p class="description uxs-icon-field uxs-icon-field-media">
				<button type="button" class="button uxs-media-pick"><?php esc_html_e( 'Choose from Media Library', 'ux-studio' ); ?></button>
				<input type="hidden" class="uxs-media-id" name="<?php echo esc_attr( $field( 'icon_media' ) ); ?>" value="<?php echo 'media' === $icon_type ? esc_attr( $icon_value ) : ''; ?>" />
				<span class="uxs-media-preview">
					<?php if ( 'media' === $icon_type && $icon_value ) : ?>
						<?php echo wp_get_attachment_image( (int) $icon_value, array( 24, 24 ) ); ?>
					<?php endif; ?>
				</span>
			</p>

			<p class="description">
				<label>
					<?php esc_html_e( 'Icon position', 'ux-studio' ); ?><br />
					<select class="widefat" name="<?php echo esc_attr( $field( 'position' ) ); ?>">
						<option value="before" <?php selected( 'before', $position ); ?>><?php esc_html_e( 'Before label', 'ux-studio' ); ?></option>
						<option value="after" <?php selected( 'after', $position ); ?>><?php esc_html_e( 'After label', 'ux-studio' ); ?></option>
						<option value="only" <?php selected( 'only', $position ); ?>><?php esc_html_e( 'Icon only (label kept for screen readers)', 'ux-studio' ); ?></option>
					</select>
				</label>
			</p>
		</fieldset>
		<?php
	}

	/**
	 * Persist the submitted fields for one menu item.
	 *
	 * @param int $menu_id         Menu ID.
	 * @param int $menu_item_db_id Menu item DB ID.
	 */
	public function save_fields( int $menu_id, int $menu_item_db_id ): void {
		if ( ! current_user_can( 'edit_theme_options' ) ) {
			return;
		}

		if (
			! isset( $_POST[ self::NONCE_FIELD ] ) ||
			! wp_verify_nonce( sanitize_key( wp_unslash( $_POST[ self::NONCE_FIELD ] ) ), self::NONCE_ACTION )
		) {
			return;
		}

		$get = static function ( string $suffix ) use ( $menu_item_db_id ) {
			$raw = $_POST[ self::FIELD_BASE ][ $suffix ][ $menu_item_db_id ] ?? '';
			return is_string( $raw ) ? wp_unslash( $raw ) : '';
		};

		$status = sanitize_key( $get( 'status' ) );
		if ( ! in_array( $status, self::STATUSES, true ) ) {
			$status = 'visible';
		}
		if ( 'visible' === $status ) {
			delete_post_meta( $menu_item_db_id, self::STATUS_META );
		} else {
			update_post_meta( $menu_item_db_id, self::STATUS_META, $status );
		}

		$icon_type = sanitize_key( $get( 'icon_type' ) );
		if ( ! in_array( $icon_type, self::ICON_TYPES, true ) ) {
			$icon_type = 'none';
		}

		$icon_value = '';
		switch ( $icon_type ) {
			case 'lucide':
				$candidate = sanitize_key( $get( 'icon_lucide' ) );
				$icon_value = IconLibrary::exists( $candidate ) ? $candidate : '';
				break;
			case 'svg':
				$icon_value = SvgSanitizer::sanitize( (string) $get( 'icon_svg' ) );
				break;
			case 'media':
				$id = absint( $get( 'icon_media' ) );
				$icon_value = ( $id && get_post( $id ) ) ? (string) $id : '';
				break;
		}

		if ( '' === $icon_value ) {
			$icon_type = 'none';
		}

		if ( 'none' === $icon_type ) {
			delete_post_meta( $menu_item_db_id, self::ICON_TYPE_META );
			delete_post_meta( $menu_item_db_id, self::ICON_VAL_META );
		} else {
			update_post_meta( $menu_item_db_id, self::ICON_TYPE_META, $icon_type );
			update_post_meta( $menu_item_db_id, self::ICON_VAL_META, $icon_value );
		}

		$position = sanitize_key( $get( 'position' ) );
		if ( ! in_array( $position, self::POSITIONS, true ) ) {
			$position = 'before';
		}
		if ( 'before' === $position ) {
			delete_post_meta( $menu_item_db_id, self::POSITION_META );
		} else {
			update_post_meta( $menu_item_db_id, self::POSITION_META, $position );
		}
	}

	/**
	 * Drop items marked "hidden" from the frontend menu output.
	 *
	 * @param WP_Post[] $items Menu items.
	 * @return WP_Post[]
	 */
	public function filter_hidden( array $items ): array {
		if ( is_admin() ) {
			return $items;
		}

		return array_values(
			array_filter(
				$items,
				fn( WP_Post $item ) => 'hidden' !== $this->get_meta( $item->ID, self::STATUS_META, 'visible', self::STATUSES )
			)
		);
	}

	/**
	 * Strip the target URL from items marked "disabled" so they render inert.
	 *
	 * @param WP_Post[] $items Walker-ready menu item objects.
	 * @return WP_Post[]
	 */
	public function mark_disabled( array $items ): array {
		if ( is_admin() ) {
			return $items;
		}

		foreach ( $items as $item ) {
			if ( 'disabled' === $this->get_meta( $item->ID, self::STATUS_META, 'visible', self::STATUSES ) ) {
				$item->url = '';
			}
		}

		return $items;
	}

	/**
	 * Remove the href and mark aria-disabled on disabled items' anchor tags.
	 *
	 * @param array   $atts Link attributes.
	 * @param WP_Post $item Menu item.
	 * @return array
	 */
	public function filter_link_attributes( array $atts, $item ): array {
		if ( ! $item instanceof WP_Post || is_admin() ) {
			return $atts;
		}

		if ( 'disabled' === $this->get_meta( $item->ID, self::STATUS_META, 'visible', self::STATUSES ) ) {
			unset( $atts['href'] );
			$atts['aria-disabled'] = 'true';
			$atts['tabindex']      = '-1';
		}

		return $atts;
	}

	/**
	 * Add utility classes for status + icon position.
	 *
	 * @param string[] $classes CSS classes.
	 * @param WP_Post  $item    Menu item.
	 * @return string[]
	 */
	public function filter_css_class( array $classes, $item ): array {
		if ( ! $item instanceof WP_Post || is_admin() ) {
			return $classes;
		}

		$status = $this->get_meta( $item->ID, self::STATUS_META, 'visible', self::STATUSES );
		if ( 'disabled' === $status ) {
			$classes[] = 'uxs-menu-disabled';
		}

		$icon_type = $this->get_meta( $item->ID, self::ICON_TYPE_META, 'none', self::ICON_TYPES );
		if ( 'none' !== $icon_type ) {
			$classes[] = 'uxs-menu-has-icon';
			$classes[] = 'uxs-menu-icon-' . $this->get_meta( $item->ID, self::POSITION_META, 'before', self::POSITIONS );
		}

		return $classes;
	}

	/**
	 * Prepend/append the configured icon to the rendered item title.
	 *
	 * @param string  $title Item title (already escaped by WP core).
	 * @param WP_Post $item  Menu item.
	 * @return string
	 */
	public function inject_icon( string $title, $item ): string {
		if ( ! $item instanceof WP_Post || is_admin() ) {
			return $title;
		}

		$icon_type = $this->get_meta( $item->ID, self::ICON_TYPE_META, 'none', self::ICON_TYPES );
		if ( 'none' === $icon_type ) {
			return $title;
		}

		$icon_html = $this->render_icon( $item->ID, $icon_type );
		if ( '' === $icon_html ) {
			return $title;
		}

		$position = $this->get_meta( $item->ID, self::POSITION_META, 'before', self::POSITIONS );

		$label = 'only' === $position
			? '<span class="screen-reader-text">' . $title . '</span>'
			: '<span class="uxs-menu-item-text">' . $title . '</span>';

		return 'after' === $position ? $label . $icon_html : $icon_html . $label;
	}

	/**
	 * Build the icon markup for one menu item from its stored type/value.
	 *
	 * @param int    $item_id   Menu item ID.
	 * @param string $icon_type One of self::ICON_TYPES.
	 */
	private function render_icon( int $item_id, string $icon_type ): string {
		$size  = (int) $this->settings->get( 'icon_size', 20 );
		$value = (string) get_post_meta( $item_id, self::ICON_VAL_META, true );

		switch ( $icon_type ) {
			case 'lucide':
				return IconLibrary::exists( $value ) ? IconLibrary::svg_markup( $value, $size ) : '';

			case 'svg':
				// Re-sanitize on every render: defence in depth if postmeta was ever
				// touched outside save_fields() (import, direct DB edit, etc).
				$clean = SvgSanitizer::sanitize( $value );
				return '' !== $clean ? '<span class="uxs-menu-icon uxs-menu-icon-svg" style="width:' . $size . 'px;height:' . $size . 'px">' . $clean . '</span>' : '';

			case 'media':
				$id = absint( $value );
				if ( ! $id ) {
					return '';
				}
				$img = wp_get_attachment_image( $id, array( $size, $size ), false, array( 'class' => 'uxs-menu-icon uxs-menu-icon-media' ) );
				return is_string( $img ) ? $img : '';
		}

		return '';
	}

	/**
	 * Read + validate a postmeta value against an allowed set.
	 *
	 * @param int      $item_id Menu item ID.
	 * @param string   $key     Meta key.
	 * @param string   $default Fallback when empty/invalid.
	 * @param string[] $allowed Allowed values.
	 */
	private function get_meta( int $item_id, string $key, string $default, array $allowed ): string {
		$value = (string) get_post_meta( $item_id, $key, true );
		return in_array( $value, $allowed, true ) ? $value : $default;
	}

	/**
	 * Enqueue the nav-menus.php admin helper script/style.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function enqueue_admin_assets( string $hook ): void {
		if ( 'nav-menus.php' !== $hook ) {
			return;
		}

		$version = defined( 'UXSTUDIO_VERSION' ) ? UXSTUDIO_VERSION : false;

		wp_enqueue_media();

		wp_enqueue_style(
			'uxstudio-menu-icons-admin',
			plugins_url( 'assets/css/menu-icons-admin.css', __FILE__ ),
			array(),
			$version
		);

		wp_enqueue_script(
			'uxstudio-menu-icons-admin',
			plugins_url( 'assets/js/menu-icons-admin.js', __FILE__ ),
			array( 'jquery' ),
			$version,
			true
		);
	}

	/**
	 * Enqueue the tiny frontend stylesheet (icon sizing + disabled state).
	 */
	public function enqueue_frontend_assets(): void {
		wp_enqueue_style(
			'uxstudio-menu-icons',
			plugins_url( 'assets/css/menu-icons.css', __FILE__ ),
			array(),
			defined( 'UXSTUDIO_VERSION' ) ? UXSTUDIO_VERSION : false
		);
	}

	/**
	 * Settings schema for the generic SPA settings renderer.
	 */
	public function settings_schema(): array {
		return array(
			array(
				'key'     => 'icon_size',
				'type'    => 'number',
				'label'   => __( 'Icon size (px)', 'ux-studio' ),
				'help'    => __( 'Default rendered size for menu item icons.', 'ux-studio' ),
				'default' => 20,
			),
		);
	}

	/**
	 * Managed inline on the menu screen; same capability as MenuVisibility.
	 */
	public function capability(): string {
		return 'edit_theme_options';
	}
}
