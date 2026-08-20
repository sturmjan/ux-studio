<?php
/**
 * Disable Auto Updates module - stop automatic updates (ported from legacy module).
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\DisableAutoUpdates;

use UxStudio\Modules\BaseModule;

defined( 'ABSPATH' ) || exit;

/**
 * Disables automatic updates for core, plugins, themes and translations,
 * either entirely or for a specific selection.
 */
final class Module extends BaseModule {

	/**
	 * Register hooks.
	 */
	public function boot(): void {
		$core         = (bool) $this->settings->get( 'disable_core_updates', true );
		$plugins      = (bool) $this->settings->get( 'disable_plugin_updates', true );
		$themes       = (bool) $this->settings->get( 'disable_theme_updates', true );
		$translations = (bool) $this->settings->get( 'disable_translation_updates', true );

		// Everything disabled: kill the automatic updater and its emails.
		if ( $core && $plugins && $themes && $translations ) {
			add_filter( 'automatic_updater_disabled', '__return_true' );
			add_filter( 'auto_core_update_send_email', '__return_false' );
		}

		if ( $core ) {
			$this->disable_core_updates();
		}

		if ( $plugins ) {
			$this->disable_plugin_updates();
		}

		if ( $themes ) {
			$this->disable_theme_updates();
		}

		if ( $translations ) {
			add_filter( 'auto_update_translation', '__return_false' );
		}
	}

	/**
	 * Disable core updates by configured scope.
	 */
	private function disable_core_updates(): void {
		switch ( (string) $this->settings->get( 'core_update_type', 'all' ) ) {
			case 'major':
				add_filter( 'allow_major_auto_core_updates', '__return_false' );
				break;
			case 'minor':
				add_filter( 'allow_minor_auto_core_updates', '__return_false' );
				break;
			case 'all':
			default:
				add_filter( 'auto_update_core', '__return_false' );
				break;
		}
	}

	/**
	 * Disable plugin updates for all plugins or a specific selection.
	 */
	private function disable_plugin_updates(): void {
		if ( 'specific' !== (string) $this->settings->get( 'plugin_update_type', 'all' ) ) {
			add_filter( 'auto_update_plugin', '__return_false' );
			return;
		}

		$disabled = (array) $this->settings->get( 'disabled_plugins', array() );
		if ( array() === $disabled ) {
			return;
		}

		add_filter(
			'auto_update_plugin',
			static function ( $update, $item ) use ( $disabled ) {
				return in_array( $item->slug ?? '', $disabled, true ) ? false : $update;
			},
			10,
			2
		);
	}

	/**
	 * Disable theme updates for all themes or a specific selection.
	 */
	private function disable_theme_updates(): void {
		if ( 'specific' !== (string) $this->settings->get( 'theme_update_type', 'all' ) ) {
			add_filter( 'auto_update_theme', '__return_false' );
			return;
		}

		$disabled = (array) $this->settings->get( 'disabled_themes', array() );
		if ( array() === $disabled ) {
			return;
		}

		add_filter(
			'auto_update_theme',
			static function ( $update, $item ) use ( $disabled ) {
				$stylesheet = $item->theme ?? ( $item->slug ?? '' );
				return in_array( $stylesheet, $disabled, true ) ? false : $update;
			},
			10,
			2
		);
	}

	/**
	 * Settings schema for the generic renderer.
	 */
	public function settings_schema(): array {
		return array(
			array(
				'key'     => 'disable_core_updates',
				'type'    => 'toggle',
				'label'   => __( 'Disable core updates', 'ux-studio' ),
				'help'    => __( 'Disable automatic updates for WordPress core.', 'ux-studio' ),
				'default' => true,
			),
			array(
				'key'     => 'core_update_type',
				'type'    => 'select',
				'label'   => __( 'Core update type', 'ux-studio' ),
				'help'    => __( 'Select which type of core updates to disable.', 'ux-studio' ),
				'options' => array(
					'all'   => __( 'Disable all updates', 'ux-studio' ),
					'major' => __( 'Disable major updates only', 'ux-studio' ),
					'minor' => __( 'Disable minor updates only', 'ux-studio' ),
				),
				'default' => 'all',
			),
			array(
				'key'     => 'disable_plugin_updates',
				'type'    => 'toggle',
				'label'   => __( 'Disable plugin updates', 'ux-studio' ),
				'help'    => __( 'Disable automatic updates for plugins.', 'ux-studio' ),
				'default' => true,
			),
			array(
				'key'     => 'plugin_update_type',
				'type'    => 'select',
				'label'   => __( 'Plugin update type', 'ux-studio' ),
				'help'    => __( 'Select how to handle plugin updates.', 'ux-studio' ),
				'options' => array(
					'all'      => __( 'Disable for all plugins', 'ux-studio' ),
					'specific' => __( 'Disable for specific plugins only', 'ux-studio' ),
				),
				'default' => 'all',
			),
			array(
				'key'     => 'disabled_plugins',
				'type'    => 'multiselect',
				'label'   => __( 'Plugins', 'ux-studio' ),
				'help'    => __( 'Choose which plugins to disable auto-updates for.', 'ux-studio' ),
				'options' => $this->get_plugin_options(),
				'default' => array(),
			),
			array(
				'key'     => 'disable_theme_updates',
				'type'    => 'toggle',
				'label'   => __( 'Disable theme updates', 'ux-studio' ),
				'help'    => __( 'Disable automatic updates for themes.', 'ux-studio' ),
				'default' => true,
			),
			array(
				'key'     => 'theme_update_type',
				'type'    => 'select',
				'label'   => __( 'Theme update type', 'ux-studio' ),
				'help'    => __( 'Select how to handle theme updates.', 'ux-studio' ),
				'options' => array(
					'all'      => __( 'Disable for all themes', 'ux-studio' ),
					'specific' => __( 'Disable for specific themes only', 'ux-studio' ),
				),
				'default' => 'all',
			),
			array(
				'key'     => 'disabled_themes',
				'type'    => 'multiselect',
				'label'   => __( 'Themes', 'ux-studio' ),
				'help'    => __( 'Choose which themes to disable auto-updates for.', 'ux-studio' ),
				'options' => $this->get_theme_options(),
				'default' => array(),
			),
			array(
				'key'     => 'disable_translation_updates',
				'type'    => 'toggle',
				'label'   => __( 'Disable translation updates', 'ux-studio' ),
				'help'    => __( 'Disable automatic updates for translations.', 'ux-studio' ),
				'default' => true,
			),
		);
	}

	/**
	 * Installed plugins keyed by slug (plugin folder name).
	 *
	 * @return array<string, string>
	 */
	private function get_plugin_options(): array {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$options = array();

		foreach ( get_plugins() as $plugin_file => $plugin_data ) {
			$options[ dirname( $plugin_file ) ] = $plugin_data['Name'];
		}

		return $options;
	}

	/**
	 * Installed themes keyed by stylesheet slug.
	 *
	 * @return array<string, string>
	 */
	private function get_theme_options(): array {
		$options = array();

		foreach ( wp_get_themes() as $slug => $theme ) {
			$options[ $slug ] = $theme->get( 'Name' );
		}

		return $options;
	}
}
