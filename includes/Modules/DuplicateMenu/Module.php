<?php
/**
 * Duplicate Menu module - clone navigation menus (ported from legacy module).
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\DuplicateMenu;

use UxStudio\Modules\BaseModule;

defined( 'ABSPATH' ) || exit;

/**
 * Duplicates a navigation menu with all of its items and parent/child
 * relationships. The SPA drives this through the module REST endpoint.
 */
final class Module extends BaseModule {

	/**
	 * Register hooks.
	 */
	public function boot(): void {
		add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );
	}

	/**
	 * Register the module REST controller.
	 */
	public function register_rest_routes(): void {
		( new RestController( $this ) )->register_routes();
	}

	/**
	 * REST controller class.
	 */
	public function rest_controller(): ?string {
		return RestController::class;
	}

	/**
	 * Capability required to manage menus.
	 */
	public function capability(): string {
		return 'edit_theme_options';
	}

	/**
	 * Duplicate a menu.
	 *
	 * @param int    $id   ID of the menu to duplicate.
	 * @param string $name Name for the new menu.
	 * @return int|false The new menu ID on success, false on failure.
	 */
	public function duplicate_menu( int $id, string $name ) {
		$name = sanitize_text_field( $name );

		if ( ! $id || '' === $name ) {
			return false;
		}

		if ( $this->menu_name_exists( $name ) ) {
			return false;
		}

		$new_menu_id = wp_create_nav_menu( $name );
		if ( ! $new_menu_id || is_wp_error( $new_menu_id ) ) {
			return false;
		}

		$this->duplicate_menu_items( $id, (int) $new_menu_id );

		return (int) $new_menu_id;
	}

	/**
	 * Whether a menu with the given name (slug) already exists.
	 *
	 * @param string $name Menu name to check.
	 */
	private function menu_name_exists( string $name ): bool {
		$menus = wp_get_nav_menus();
		if ( empty( $menus ) ) {
			return false;
		}

		$name_slug = sanitize_title( $name );
		foreach ( $menus as $menu ) {
			if ( $name_slug === $menu->slug ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Copy all items from one menu to another, preserving hierarchy.
	 *
	 * @param int $source_id   Source menu ID.
	 * @param int $new_menu_id New menu ID.
	 */
	private function duplicate_menu_items( int $source_id, int $new_menu_id ): void {
		$source_items = wp_get_nav_menu_items( $source_id );
		if ( empty( $source_items ) ) {
			return;
		}

		// Map original item IDs to new IDs for parent relationships.
		$id_mappings = array();

		foreach ( $source_items as $index => $menu_item ) {
			$args = array(
				'menu-item-db-id'       => $menu_item->db_id,
				'menu-item-object-id'   => $menu_item->object_id,
				'menu-item-object'      => $menu_item->object,
				'menu-item-position'    => $index + 1,
				'menu-item-type'        => $menu_item->type,
				'menu-item-title'       => $menu_item->title,
				'menu-item-url'         => $menu_item->url,
				'menu-item-description' => $menu_item->description,
				'menu-item-attr-title'  => $menu_item->attr_title,
				'menu-item-target'      => $menu_item->target,
				'menu-item-classes'     => implode( ' ', (array) $menu_item->classes ),
				'menu-item-xfn'         => $menu_item->xfn,
				'menu-item-status'      => $menu_item->post_status,
			);

			$new_item_id                       = wp_update_nav_menu_item( $new_menu_id, 0, $args );
			$id_mappings[ $menu_item->db_id ]  = $new_item_id;

			if ( $menu_item->menu_item_parent && isset( $id_mappings[ $menu_item->menu_item_parent ] ) ) {
				$args['menu-item-parent-id'] = $id_mappings[ $menu_item->menu_item_parent ];
				wp_update_nav_menu_item( $new_menu_id, (int) $new_item_id, $args );
			}

			/**
			 * Fires after a single menu item has been duplicated.
			 *
			 * @param object $menu_item Source menu item.
			 * @param array  $args      Arguments used to create the copy.
			 */
			do_action( 'ux_studio/duplicate_menu/duplicate_menu_item', $menu_item, $args );
		}
	}
}
