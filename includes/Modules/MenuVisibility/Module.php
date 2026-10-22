<?php
/**
 * Menu Visibility module - per-item nav menu visibility by login state and role.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\MenuVisibility;

use UxStudio\Modules\BaseModule;
use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * Adds visibility rules to each navigation menu item (logged in, logged out, has one of the roles,
 * has none of the roles; all of them must hold, see Rules) and filters the front end menu accordingly.
 * Ported from the legacy module: reads the new `_uxstudio_menu_item_visible` meta (`1` logged in,
 * `2` logged out), the legacy `_wpext_menu_item_visible` key, and the new `_uxstudio_menu_item_rules`
 * meta for everything else.
 *
 * Scope: classic menus (`wp_nav_menu`, `wp_get_nav_menu_items`). The block based navigation
 * (`wp_navigation` posts) does not use menu items and is not filtered.
 */
final class Module extends BaseModule {

	/**
	 * Current meta key for the single logged in / out case (written on save).
	 */
	public const META_KEY = '_uxstudio_menu_item_visible';

	/**
	 * Meta key with the JSON list of rules (several rules, roles).
	 */
	public const RULES_KEY = '_uxstudio_menu_item_rules';

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
	 * Form field base names.
	 */
	private const FIELD_NAME  = 'uxstudio_menu_item_visible';
	private const RULES_FIELD = 'uxstudio_menu_item_rules';

	/**
	 * Register hooks.
	 */
	public function boot(): void {
		add_action( 'wp_nav_menu_item_custom_fields', array( $this, 'render_fields' ), 10, 4 );
		add_action( 'wp_update_nav_menu_item', array( $this, 'save_fields' ), 10, 3 );
		add_filter( 'wp_get_nav_menu_items', array( $this, 'filter_items' ), 10, 3 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
	}

	/**
	 * Roles of the site: id => translated name.
	 *
	 * @return array<string, string>
	 */
	private function role_names(): array {
		$out = array();
		foreach ( (array) wp_roles()->roles as $id => $role ) {
			$out[ (string) $id ] = translate_user_role( (string) ( $role['name'] ?? $id ) );
		}
		return $out;
	}

	/**
	 * Script and style of the rule editor (nav-menus.php only).
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function enqueue_admin_assets( string $hook ): void {
		if ( 'nav-menus.php' !== $hook ) {
			return;
		}
		$version = defined( 'UXSTUDIO_VERSION' ) ? UXSTUDIO_VERSION : false;

		wp_enqueue_style( 'uxstudio-menu-visibility', plugins_url( 'assets/menu-visibility.css', __FILE__ ), array(), $version );
		wp_enqueue_script( 'uxstudio-menu-visibility', plugins_url( 'assets/menu-visibility.js', __FILE__ ), array(), $version, true );
		wp_localize_script(
			'uxstudio-menu-visibility',
			'uxsMenuVisibility',
			array(
				'roles' => $this->role_names(),
				'types' => array(
					'in'       => __( 'Visitor is logged in', 'ux-studio' ),
					'out'      => __( 'Visitor is logged out', 'ux-studio' ),
					'role'     => __( 'Visitor has one of the roles', 'ux-studio' ),
					'not_role' => __( 'Visitor has none of the roles', 'ux-studio' ),
				),
				'i18n'  => array(
					'add'      => __( 'Add condition', 'ux-studio' ),
					'remove'   => __( 'Remove', 'ux-studio' ),
					'condition' => __( 'Condition', 'ux-studio' ),
					'roles'    => __( 'Roles', 'ux-studio' ),
				),
			)
		);
	}

	/**
	 * Render the rule editor inside the menu item admin panel. The list of rules lives in a hidden
	 * field as JSON; the script builds the rows from it and writes it back.
	 *
	 * @param int       $item_id Menu item ID.
	 * @param WP_Post   $item    Menu item object.
	 * @param int       $depth   Menu item depth.
	 * @param \stdClass $args    Additional arguments.
	 */
	public function render_fields( int $item_id, WP_Post $item, int $depth, \stdClass $args ): void {
		$json = $this->rules_json( $this->get_rules( $item_id ) );
		?>
		<fieldset class="field-uxstudio-menu-visibility uxs-mv description-wide">
			<legend class="menu-item-title">
				<?php esc_html_e( 'Menu Item Visibility For', 'ux-studio' ); ?>
			</legend>
			<?php wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD ); ?>
			<input type="hidden" class="uxs-mv-value" name="<?php echo esc_attr( sprintf( '%s[%d]', self::RULES_FIELD, $item_id ) ); ?>" value="<?php echo esc_attr( $json ); ?>" />
			<p class="uxs-mv-empty description"><?php esc_html_e( 'Everyone (no condition).', 'ux-studio' ); ?></p>
			<div class="uxs-mv-rows"></div>
			<p class="uxs-mv-actions">
				<button type="button" class="button-link uxs-mv-add"><?php esc_html_e( 'Add condition', 'ux-studio' ); ?></button>
			</p>
			<p class="description uxs-mv-hint"><?php esc_html_e( 'The item is shown only when all conditions are met.', 'ux-studio' ); ?></p>
		</fieldset>
		<?php
	}

	/**
	 * JSON for the hidden field (also for a single logged in / out rule).
	 *
	 * @param array<int, array{type:string,roles:string[]}> $rules Rules.
	 */
	private function rules_json( array $rules ): string {
		$list = array();
		foreach ( $rules as $rule ) {
			$row = array( 't' => $rule['type'] );
			if ( $rule['roles'] ) {
				$row['r'] = $rule['roles'];
			}
			$list[] = $row;
		}
		return (string) wp_json_encode( $list );
	}

	/**
	 * Persist the rules for a menu item.
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

		$valid = array_keys( $this->role_names() );
		if ( isset( $_POST[ self::RULES_FIELD ][ $menu_item_db_id ] ) && is_string( $_POST[ self::RULES_FIELD ][ $menu_item_db_id ] ) ) {
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- decoded and rebuilt by Rules::normalize().
			$decoded = json_decode( (string) wp_unslash( $_POST[ self::RULES_FIELD ][ $menu_item_db_id ] ), true );
			$rules   = Rules::normalize( $decoded, $valid );
		} elseif ( isset( $_POST[ self::FIELD_NAME ][ $menu_item_db_id ] ) ) {
			// Form of the first version of the module (radio buttons).
			$value = sanitize_text_field( wp_unslash( (string) $_POST[ self::FIELD_NAME ][ $menu_item_db_id ] ) );
			$rules = Rules::from_meta( $value, '' );
		} else {
			return;
		}

		$this->store_rules( $menu_item_db_id, $rules );
	}

	/**
	 * Write the rules of an item in the shortest compatible form.
	 *
	 * @param int                                           $item_id Menu item id.
	 * @param array<int, array{type:string,roles:string[]}> $rules   Normalized rules.
	 */
	private function store_rules( int $item_id, array $rules ): void {
		$meta = Rules::to_meta( $rules );

		delete_post_meta( $item_id, self::LEGACY_META_KEY );
		if ( '' !== $meta['visible'] ) {
			update_post_meta( $item_id, self::META_KEY, $meta['visible'] );
		} else {
			delete_post_meta( $item_id, self::META_KEY );
		}
		if ( '' !== $meta['rules'] ) {
			update_post_meta( $item_id, self::RULES_KEY, wp_slash( $meta['rules'] ) );
		} else {
			delete_post_meta( $item_id, self::RULES_KEY );
		}
	}

	/**
	 * Filter frontend menu items by the visitor's login state and roles. Children of a hidden item go with it.
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

		$logged_in = is_user_logged_in();
		$roles     = $logged_in ? array_map( 'strval', (array) wp_get_current_user()->roles ) : array();

		$visible = array();
		$flat    = array();
		foreach ( $items as $item ) {
			if ( ! $item instanceof WP_Post ) {
				continue;
			}
			$id           = (int) $item->ID;
			$flat[]       = array(
				'id'     => $id,
				'parent' => (int) ( $item->menu_item_parent ?? 0 ),
			);
			$visible[ $id ] = Rules::passes( $this->get_rules( $id ), $logged_in, $roles );
		}
		$keep = array_flip( Rules::keep( $flat, $visible ) );

		return array_values(
			array_filter(
				$items,
				static function ( $item ) use ( $keep ): bool {
					return ! $item instanceof WP_Post || isset( $keep[ (int) $item->ID ] );
				}
			)
		);
	}

	/**
	 * Rules of a menu item (rules meta, the new `1` / `2` key, the legacy key).
	 *
	 * @param int $item_id Menu item ID.
	 * @return array<int, array{type:string,roles:string[]}>
	 */
	private function get_rules( int $item_id ): array {
		$json = (string) get_post_meta( $item_id, self::RULES_KEY, true );
		$flag = (string) get_post_meta( $item_id, self::META_KEY, true );

		if ( '' === $flag ) {
			$flag = (string) get_post_meta( $item_id, self::LEGACY_META_KEY, true );
		}

		return Rules::from_meta( $flag, $json );
	}

	/**
	 * Menu visibility is managed inline on the menu screen, not the SPA.
	 */
	public function capability(): string {
		return 'edit_theme_options';
	}
}
