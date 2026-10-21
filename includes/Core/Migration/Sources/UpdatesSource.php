<?php
/**
 * Source: Disable All WordPress Updates, Easy Updates Manager.
 *
 * @package UxStudio
 */

namespace UxStudio\Core\Migration\Sources;

use UxStudio\Core\Migration\AbstractSource;
use UxStudio\Core\Migration\Mapping;

defined( 'ABSPATH' ) || exit;

/**
 * Target module `disable-auto-updates` switches off AUTOMATIC updates of core, plugins,
 * themes and translations. Both plugins go further (they also hide update notices and
 * stop the update check) - that part is reported as lossy.
 *
 *  - Disable All WordPress Updates: no settings, everything off while it is active.
 *  - Easy Updates Manager (`stops-core-theme-and-plugin-updates`): option `MPSUM`['core']
 *    { all_updates: 'off' = everything disabled, core_updates / plugin_updates /
 *    theme_updates: 'off' | 'on' (manual) | 'automatic' | 'automatic_minor' | 'individual',
 *    translation_updates: 'off' | 'on' | 'automatic' }.
 */
final class UpdatesSource extends AbstractSource {

	private const MODULE = 'disable-auto-updates';

	protected function option_names(): array {
		return 'stops-core-theme-and-plugin-updates' === $this->slug ? array( 'MPSUM' ) : array( 'osdwp_security_mode' );
	}

	protected function has_data(): bool {
		// Disable All WordPress Updates keeps a single flag; "has data" means a real setting.
		return 'stops-core-theme-and-plugin-updates' === $this->slug ? parent::has_data() : false;
	}

	protected function map( bool $active ): Mapping {
		return 'stops-core-theme-and-plugin-updates' === $this->slug ? $this->map_mpsum() : $this->map_disable_all( $active );
	}

	/**
	 * Set every "disable" switch to its fully-off state.
	 *
	 * @param Mapping $m      Mapping.
	 * @param string  $origin Origin label.
	 */
	private function disable_everything( Mapping $m, string $origin ): void {
		$m->set( self::MODULE, 'disable_core_updates', true, __( 'Disable core updates', 'ux-studio' ), $origin );
		$m->set( self::MODULE, 'core_update_type', 'all', __( 'Core update type', 'ux-studio' ), $origin );
		$m->set( self::MODULE, 'disable_plugin_updates', true, __( 'Disable plugin updates', 'ux-studio' ), $origin );
		$m->set( self::MODULE, 'plugin_update_type', 'all', __( 'Plugin update type', 'ux-studio' ), $origin );
		$m->set( self::MODULE, 'disable_theme_updates', true, __( 'Disable theme updates', 'ux-studio' ), $origin );
		$m->set( self::MODULE, 'theme_update_type', 'all', __( 'Theme update type', 'ux-studio' ), $origin );
		$m->set( self::MODULE, 'disable_translation_updates', true, __( 'Disable translation updates', 'ux-studio' ), $origin );
	}

	/**
	 * Disable All WordPress Updates.
	 *
	 * @param bool $active Plugin active.
	 */
	private function map_disable_all( bool $active ): Mapping {
		$m = new Mapping();
		if ( ! $active ) {
			$m->note( __( 'The plugin has no settings and is not active, so there is nothing to transfer.', 'ux-studio' ) );
			return $m;
		}
		$this->disable_everything( $m, 'disable-wordpress-updates' );
		$m->lossy( __( 'The plugin also hides update notices and stops the update check completely. The module only switches off automatic updates; updating by hand and the notices stay.', 'ux-studio' ) );
		if ( ! empty( $this->opt( 'osdwp_security_mode' ) ) ) {
			$m->lossy( __( 'The plugin lets security updates through ("Security Mode"); the module has no such exception.', 'ux-studio' ) );
		}
		return $m;
	}

	/**
	 * Easy Updates Manager.
	 */
	private function map_mpsum(): Mapping {
		$m   = new Mapping();
		$all = $this->opt( 'MPSUM' );
		if ( ! is_array( $all ) || ! isset( $all['core'] ) || ! is_array( $all['core'] ) ) {
			$m->note( __( 'The plugin has no stored settings, there is nothing to transfer.', 'ux-studio' ) );
			return $m;
		}
		$o      = $all['core'];
		$origin = 'MPSUM:core';
		$hides  = false;

		if ( 'off' === ( $o['all_updates'] ?? '' ) ) {
			$this->disable_everything( $m, $origin . ':all_updates' );
			$hides = true;
		} else {
			$core = (string) ( $o['core_updates'] ?? 'on' );
			$m->set( self::MODULE, 'disable_core_updates', 'automatic' !== $core, __( 'Disable core updates', 'ux-studio' ), $origin . ':core_updates' );
			$m->set( self::MODULE, 'core_update_type', 'automatic_minor' === $core ? 'major' : 'all', __( 'Core update type', 'ux-studio' ), $origin . ':core_updates' );
			$hides = $hides || 'off' === $core;

			foreach ( array(
				'plugin' => array( __( 'Disable plugin updates', 'ux-studio' ), __( 'Plugin update type', 'ux-studio' ) ),
				'theme'  => array( __( 'Disable theme updates', 'ux-studio' ), __( 'Theme update type', 'ux-studio' ) ),
			) as $kind => $labels ) {
				$value = (string) ( $o[ $kind . '_updates' ] ?? 'on' );
				if ( 'individual' === $value ) {
					$m->lossy(
						sprintf(
							/* translators: %s: plugins or themes. */
							__( 'Choosing automatic updates one by one (%s) is not transferred, the module keeps its own setting.', 'ux-studio' ),
							'plugin' === $kind ? __( 'plugins', 'ux-studio' ) : __( 'themes', 'ux-studio' )
						)
					);
					continue;
				}
				$m->set( self::MODULE, 'disable_' . $kind . '_updates', 'automatic' !== $value, $labels[0], $origin . ':' . $kind . '_updates' );
				$m->set( self::MODULE, $kind . '_update_type', 'all', $labels[1], $origin . ':' . $kind . '_updates' );
				$hides = $hides || 'off' === $value;
			}

			$tr = (string) ( $o['translation_updates'] ?? 'on' );
			$m->set( self::MODULE, 'disable_translation_updates', 'automatic' !== $tr, __( 'Disable translation updates', 'ux-studio' ), $origin . ':translation_updates' );
			$hides = $hides || 'off' === $tr;
		}

		if ( $hides ) {
			$m->lossy( __( 'Where the plugin has updates "disabled" it also hides update notices and stops the update check. The module only switches off automatic updates; updating by hand and the notices stay.', 'ux-studio' ) );
		}
		$m->lossy( __( 'E-mail notification settings, the update log and updates of development versions of the plugin are not transferred.', 'ux-studio' ) );
		return $m;
	}
}
