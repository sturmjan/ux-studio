<?php
/**
 * Hide Admin Bar module - reference implementation of a group-A module.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\HideAdminBar;

use UxStudio\Modules\BaseModule;

defined( 'ABSPATH' ) || exit;

/**
 * Hides the frontend admin bar for the configured roles.
 */
final class Module extends BaseModule {

	/**
	 * Register hooks.
	 */
	public function boot(): void {
		add_filter( 'show_admin_bar', array( $this, 'maybe_hide' ) );
	}

	/**
	 * Filter callback.
	 *
	 * @param bool $show Current visibility.
	 */
	public function maybe_hide( bool $show ): bool {
		if ( ! is_user_logged_in() ) {
			return $show;
		}
		$roles  = (array) $this->settings->get( 'roles', array() );
		if ( array() === $roles ) {
			return false; // No role restriction = hide for everyone.
		}
		$user = wp_get_current_user();
		return array_intersect( $roles, $user->roles ) ? false : $show;
	}

	/**
	 * Settings schema for the generic renderer.
	 */
	public function settings_schema(): array {
		return array(
			array(
				'key'     => 'roles',
				'type'    => 'multiselect',
				'label'   => __( 'Hide for roles', 'ux-studio' ),
				'help'    => __( 'Leave empty to hide the admin bar for all users.', 'ux-studio' ),
				'options' => array_map(
					static fn ( $role ) => translate_user_role( $role['name'] ),
					wp_roles()->roles
				),
				'default' => array(),
			),
		);
	}
}
