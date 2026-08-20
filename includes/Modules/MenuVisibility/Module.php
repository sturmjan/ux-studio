<?php
/**
 * Menu Visibility module - per-item nav menu visibility by login state.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\MenuVisibility;

use UxStudio\Modules\BaseModule;
use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * Adds a visibility control to each navigation menu item (Everyone / Logged In
 * / Logged Out) and filters the frontend menu accordingly. Ported from the
 * legacy module: writes the new `_uxstudio_menu_item_visible` meta and reads
 * both the new and the legacy `_wpext_menu_item_visible` key.
 */
final class Module extends BaseModule {

	/**
	 * Current meta key (written on save).
	 */
	private const META_KEY = '_uxstudio_menu_item_visible';

	/**
	 * Legacy meta key, read as a fallback. Legacy module.
	 */
	private const LEGACY_META_KEY = '_wpext_menu_item_visible';

	/**
	 * Nonce action / field name.
	 */
	private const NONCE_ACTION = 'uxstudio_menu_visibility';
	private const NONCE_FIELD  = 'uxstudio_menu_visibility_nonce';

	/**
	 * Form field base name.
	 */
	private const FIELD_NAME = 'uxstudio_menu_item_visible';

	/**
	 * Visibility options: value => label.
	 *
	 * @var array<string, string>
	 */
	private array $visibility_options = array();

	/**
	 * Register hooks.
	 */
	public function boot(): void {
		$this->visibility_options = array(
			'1' => __( 'Logged In', 'ux-studio' ),
			'2' => __( 'Logged Out', 'ux-studio' ),
			''  => __( 'Everyone', 'ux-studio' ),
		);

		add_action( 'wp_nav_menu_item_custom_fields', array( $this, 'render_fields' ), 10, 4 );
		add_action( 'wp_update_nav_menu_item', array( $this, 'save_fields' ), 10, 3 );
		add_filter( 'wp_get_nav_menu_items', array( $this, 'filter_items' ), 10, 3 );
	}

	/**
	 * Render the visibility radios inside the menu item admin panel.
	 *
	 * @param int       $item_id Menu item ID.
	 * @param WP_Post   $item    Menu item object.
	 * @param int       $depth   Menu item depth.
	 * @param \stdClass $args    Additional arguments.
	 */
	public function render_fields( int $item_id, WP_Post $item, int $depth, \stdClass $args ): void {
		$current = $this->get_visibility( $item_id );
		?>
		<fieldset class="field-uxstudio-menu-visibility nav_menu_logged_in_out_field description-wide">
			<legend class="menu-item-title">
				<?php esc_html_e( 'Menu Item Visibility For', 'ux-studio' ); ?>
			</legend>
			<?php wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD ); ?>
			<?php foreach ( $this->visibility_options as $value => $label ) : ?>
				<label class="menu-item-visibility-option">
					<input
						type="radio"
						class="widefat"
						name="<?php echo esc_attr( sprintf( '%s[%d]', self::FIELD_NAME, $item_id ) ); ?>"
						value="<?php echo esc_attr( $value ); ?>"
						<?php checked( $value, $current ); ?>
						aria-label="<?php echo esc_attr( sprintf( /* translators: %s: visibility option label */ __( 'Set visibility to %s', 'ux-studio' ), $label ) ); ?>" />
					<?php echo esc_html( $label ); ?>
				</label>
			<?php endforeach; ?>
		</fieldset>
		<?php
	}

	/**
	 * Persist the selected visibility for a menu item.
	 *
	 * @param int   $menu_id         Menu ID.
	 * @param int   $menu_item_db_id Menu item DB ID.
	 * @param array $menu_item_args  Menu item arguments.
	 */
	public function save_fields( int $menu_id, int $menu_item_db_id, array $menu_item_args ): void {
		if ( ! current_user_can( 'edit_theme_options' ) ) {
			return;
		}

		if (
			! isset( $_POST[ self::NONCE_FIELD ] ) ||
			! wp_verify_nonce( sanitize_key( wp_unslash( $_POST[ self::NONCE_FIELD ] ) ), self::NONCE_ACTION )
		) {
			return;
		}

		$raw   = $_POST[ self::FIELD_NAME ][ $menu_item_db_id ] ?? '';
		$value = sanitize_text_field( wp_unslash( $raw ) );

		if ( ! array_key_exists( $value, $this->visibility_options ) ) {
			$value = '';
		}

		if ( '' !== $value ) {
			update_post_meta( $menu_item_db_id, self::META_KEY, $value );
			delete_post_meta( $menu_item_db_id, self::LEGACY_META_KEY );
			return;
		}

		delete_post_meta( $menu_item_db_id, self::META_KEY );
		delete_post_meta( $menu_item_db_id, self::LEGACY_META_KEY );
	}

	/**
	 * Filter frontend menu items by the visitor's login state.
	 *
	 * @param array  $items Menu items.
	 * @param object $menu  Menu object.
	 * @param array  $args  Arguments.
	 * @return array
	 */
	public function filter_items( array $items, object $menu, array $args ): array {
		if ( is_admin() ) {
			return $items;
		}

		return array_values( array_filter( $items, array( $this, 'should_display' ) ) );
	}

	/**
	 * Whether a menu item is visible for the current visitor.
	 *
	 * @param WP_Post $item Menu item object.
	 */
	private function should_display( WP_Post $item ): bool {
		$visibility = $this->get_visibility( $item->ID );

		if ( '' === $visibility ) {
			return true;
		}

		$logged_in = is_user_logged_in();

		if ( '1' === $visibility && $logged_in ) {
			return true;
		}

		if ( '2' === $visibility && ! $logged_in ) {
			return true;
		}

		return false;
	}

	/**
	 * Read the stored visibility for a menu item (new key, legacy fallback).
	 *
	 * @param int $item_id Menu item ID.
	 */
	private function get_visibility( int $item_id ): string {
		$value = (string) get_post_meta( $item_id, self::META_KEY, true );

		if ( '' === $value ) {
			$value = (string) get_post_meta( $item_id, self::LEGACY_META_KEY, true );
		}

		return $value;
	}

	/**
	 * Menu visibility is managed inline on the menu screen, not the SPA.
	 */
	public function capability(): string {
		return 'edit_theme_options';
	}
}
