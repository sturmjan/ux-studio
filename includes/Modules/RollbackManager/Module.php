<?php
/**
 * Rollback Manager module - roll an installed plugin/theme back to an older
 * wordpress.org version.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\RollbackManager;

use UxStudio\Modules\BaseModule;

defined( 'ABSPATH' ) || exit;

/**
 * No settings screen and no own DB table: this module only exposes REST
 * endpoints consumed by its custom SPA page (src/modules/rollback-manager).
 */
final class Module extends BaseModule {

	/**
	 * Register hooks.
	 */
	public function boot(): void {
		add_action(
			'rest_api_init',
			function (): void {
				( new RestController( $this ) )->register_routes();
			}
		);

		// "Rollback" quick link in the Plugins and Themes screens.
		add_filter( 'plugin_action_links', array( $this, 'plugin_action_link' ), 20, 4 );
		add_filter( 'theme_action_links', array( $this, 'theme_action_link' ), 20, 3 );
	}

	/**
	 * Module page URL preselecting an item (read by the SPA from the hash).
	 *
	 * @param string $type 'plugin'|'theme'.
	 * @param string $slug Plugin folder / theme stylesheet.
	 */
	private function page_url( string $type, string $slug ): string {
		return admin_url( 'admin.php?page=ux-studio' ) . '#/module?' . http_build_query(
			array(
				'id'   => 'rollback-manager',
				'type' => $type,
				'name' => $slug,
			)
		);
	}

	/**
	 * Add "Rollback" to a plugin row. Only plugins that WordPress itself tracks
	 * as wordpress.org-hosted (update_plugins transient, id "w.org/...") get
	 * the link - no remote lookup while the Plugins screen renders.
	 *
	 * @param mixed  $actions     Row actions.
	 * @param string $plugin_file Plugin file.
	 * @param mixed  $plugin_data Plugin header data.
	 * @param string $context     Screen context.
	 * @return mixed
	 */
	public function plugin_action_link( $actions, $plugin_file, $plugin_data = array(), $context = '' ) {
		if ( ! is_array( $actions ) || ! current_user_can( $this->capability() ) || ! current_user_can( 'install_plugins' ) ) {
			return $actions;
		}

		$update = get_site_transient( 'update_plugins' );
		$entry  = null;
		if ( is_object( $update ) ) {
			$entry = $update->response[ $plugin_file ] ?? $update->no_update[ $plugin_file ] ?? null;
		}
		if ( ! is_object( $entry ) || 0 !== strpos( (string) ( $entry->id ?? '' ), 'w.org/' ) ) {
			return $actions;
		}

		$slug = ! empty( $entry->slug ) ? (string) $entry->slug : dirname( (string) $plugin_file );
		if ( '' === $slug || '.' === $slug ) {
			return $actions;
		}

		$actions['uxstudio_rollback'] = sprintf(
			'<a href="%s">%s</a>',
			esc_url( $this->page_url( 'plugin', $slug ) ),
			esc_html__( 'Rollback', 'ux-studio' )
		);
		return $actions;
	}

	/**
	 * Add "Rollback" to a theme (Themes screen). Same wordpress.org rule as
	 * for plugins, via the update_themes transient.
	 *
	 * @param mixed $actions Theme actions.
	 * @param mixed $theme   WP_Theme object.
	 * @param mixed $context Context.
	 * @return mixed
	 */
	public function theme_action_link( $actions, $theme = null, $context = '' ) {
		if ( ! is_array( $actions ) || ! $theme instanceof \WP_Theme || ! current_user_can( $this->capability() ) || ! current_user_can( 'install_themes' ) ) {
			return $actions;
		}

		$stylesheet = $theme->get_stylesheet();
		$update     = get_site_transient( 'update_themes' );
		$entry      = null;
		if ( is_object( $update ) ) {
			$entry = $update->response[ $stylesheet ] ?? $update->no_update[ $stylesheet ] ?? null;
		}
		$url = '';
		if ( is_array( $entry ) ) {
			$url = (string) ( $entry['url'] ?? '' );
		}
		if ( false === strpos( $url, 'wordpress.org' ) ) {
			return $actions;
		}

		$actions['uxstudio_rollback'] = sprintf(
			'<a href="%s">%s</a>',
			esc_url( $this->page_url( 'theme', $stylesheet ) ),
			esc_html__( 'Rollback', 'ux-studio' )
		);
		return $actions;
	}

	/**
	 * REST controller class.
	 */
	public function rest_controller(): ?string {
		return RestController::class;
	}

	/**
	 * No settings for this module.
	 */
	public function settings_schema(): array {
		return array();
	}

	/**
	 * Rolling back is replacing plugin/theme code: update_plugins is denied by
	 * core under DISALLOW_FILE_MODS and limited to super admins on multisite.
	 * The rollback route itself checks update_* + install_* per item type.
	 */
	public function capability(): string {
		return 'update_plugins';
	}
}
