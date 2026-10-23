<?php
/**
 * Source: Hide Admin Bar.
 *
 * @package UxStudio
 */

namespace UxStudio\Core\Migration\Sources;

use UxStudio\Core\Migration\AbstractSource;
use UxStudio\Core\Migration\Mapping;

defined( 'ABSPATH' ) || exit;

/**
 * Option `hab_admin_bar_settings` { remove_by_roles: string[] } where `all` means every
 * role. Without a stored value the plugin hides the bar for everyone while it is active.
 */
final class HideAdminBarSource extends AbstractSource {

	protected function option_names(): array {
		return array( 'hab_admin_bar_settings' );
	}

	protected function map( bool $active ): Mapping {
		$m = new Mapping();
		$o = $this->opt( 'hab_admin_bar_settings' );

		if ( is_array( $o ) ) {
			$roles = isset( $o['remove_by_roles'] ) && is_array( $o['remove_by_roles'] ) ? array_map( 'strval', $o['remove_by_roles'] ) : array();
		} elseif ( $active ) {
			$roles = array( 'all' ); // The plugin's default.
		} else {
			$m->note( __( 'The plugin has no stored settings, there is nothing to transfer.', 'ux-studio' ) );
			return $m;
		}

		if ( in_array( 'all', $roles, true ) ) {
			$roles = $this->role_ids();
		}
		$m->set( 'hide-admin-bar', 'roles', $roles, __( 'Hide for roles', 'ux-studio' ), 'hab_admin_bar_settings:remove_by_roles' );
		return $m;
	}
}
