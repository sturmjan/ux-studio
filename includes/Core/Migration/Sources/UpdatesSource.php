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
 * Target module `disable-auto-updates` switches off AUTOMATIC updates of core, plugins, themes and translations, hides
 * the update notices per type and stops the update check per type (PLAN.md 27.10), with an exception that still installs
 * security (minor) core releases. Both plugins do all of that, so it is transferred:
 *
 *  - Disable All WordPress Updates: no settings, everything off while it is active. Its option `osdwp_security_mode`
 *    ("Security Mode": minor / security core releases still come) becomes the module's security exception, and in that
 *    mode the plugin keeps the core notice and check, so the module does too.
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
		// (The notices and checks are written by hide_and_stop().)
		$m->set( self::MODULE, 'disable_core_updates', true, __( 'Disable core updates', 'ux-studio' ), $origin );
		$m->set( self::MODULE, 'core_update_type', 'all', __( 'Core update type', 'ux-studio' ), $origin );
		$m->set( self::MODULE, 'disable_plugin_updates', true, __( 'Disable plugin updates', 'ux-studio' ), $origin );
		$m->set( self::MODULE, 'plugin_update_type', 'all', __( 'Plugin update type', 'ux-studio' ), $origin );
		$m->set( self::MODULE, 'disable_theme_updates', true, __( 'Disable theme updates', 'ux-studio' ), $origin );
		$m->set( self::MODULE, 'theme_update_type', 'all', __( 'Theme update type', 'ux-studio' ), $origin );
		$m->set( self::MODULE, 'disable_translation_updates', true, __( 'Disable translation updates', 'ux-studio' ), $origin );
	}

	/**
	 * Labels of the settings that hide notices / stop checks, per kind.
	 *
	 * @return array<string, array{0:string,1:string}>
	 */
	private function kind_labels(): array {
		return array(
			'core'    => array( __( 'Hide WordPress update notices', 'ux-studio' ), __( 'Stop checking for WordPress updates', 'ux-studio' ) ),
			'plugins' => array( __( 'Hide plugin update notices', 'ux-studio' ), __( 'Stop checking for plugin updates', 'ux-studio' ) ),
			'themes'  => array( __( 'Hide theme update notices', 'ux-studio' ), __( 'Stop checking for theme updates', 'ux-studio' ) ),
		);
	}

	/**
	 * Hide the notices of some kinds and (when `$stop`) stop their checks too.
	 *
	 * @param Mapping  $m      Mapping.
	 * @param string[] $kinds  core | plugins | themes.
	 * @param bool     $stop   Also stop the check (otherwise only the notices are hidden).
	 * @param string   $origin Origin label.
	 */
	private function hide_and_stop( Mapping $m, array $kinds, bool $stop, string $origin ): void {
		$labels = $this->kind_labels();
		foreach ( $kinds as $kind ) {
			$m->set( self::MODULE, 'hide_notices_' . $kind, true, $labels[ $kind ][0], $origin );
			if ( $stop ) {
				$m->set( self::MODULE, 'disable_checks_' . $kind, true, $labels[ $kind ][1], $origin );
			}
		}
		$m->warn( __( 'Hiding update notices and stopping update checks also hides available security fixes. Once the module is on, nobody is told about new versions. Keep the site up to date some other way.', 'ux-studio' ) );
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
		if ( ! empty( $this->opt( 'osdwp_security_mode' ) ) ) {
			// Security Mode: core keeps its check and its notice, so minor / security releases can be found and installed.
			$m->set( self::MODULE, 'allow_security_core', true, __( 'Still install security (minor) core updates', 'ux-studio' ), 'osdwp_security_mode' );
			$this->hide_and_stop( $m, array( 'plugins', 'themes' ), true, 'disable-wordpress-updates' );
		} else {
			$this->hide_and_stop( $m, array( 'core', 'plugins', 'themes' ), true, 'disable-wordpress-updates' );
		}
		$m->lossy( __( 'The indicator in the admin bar and the hidden Site Health tests of the plugin are not transferred.', 'ux-studio' ) );
		return $m;
	}

	/**
	 * Logins of the users Easy Updates Manager excludes from its settings (stored as user ids).
	 *
	 * @param array<string, mixed> $all The MPSUM option.
	 * @return string[]
	 */
	private function excluded_logins( array $all ): array {
		$out = array();
		foreach ( (array) ( $all['advanced']['excluded_users'] ?? array() ) as $id ) {
			$user = (int) $id > 0 && function_exists( 'get_userdata' ) ? get_userdata( (int) $id ) : false;
			if ( $user && '' !== (string) $user->user_login ) {
				$out[] = (string) $user->user_login;
			}
		}
		return array_values( array_unique( $out ) );
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
		$off    = array();

		if ( 'off' === ( $o['all_updates'] ?? '' ) ) {
			$this->disable_everything( $m, $origin . ':all_updates' );
			$off = array( 'core', 'plugins', 'themes' );
		} else {
			$core = (string) ( $o['core_updates'] ?? 'on' );
			$m->set( self::MODULE, 'disable_core_updates', 'automatic' !== $core, __( 'Disable core updates', 'ux-studio' ), $origin . ':core_updates' );
			$m->set( self::MODULE, 'core_update_type', 'automatic_minor' === $core ? 'major' : 'all', __( 'Core update type', 'ux-studio' ), $origin . ':core_updates' );
			if ( 'off' === $core ) {
				$off[] = 'core';
			}

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
				if ( 'off' === $value ) {
					$off[] = $kind . 's';
				}
			}

			$tr = (string) ( $o['translation_updates'] ?? 'on' );
			$m->set( self::MODULE, 'disable_translation_updates', 'automatic' !== $tr, __( 'Disable translation updates', 'ux-studio' ), $origin . ':translation_updates' );
		}

		if ( $off ) {
			// Users the plugin leaves out still see the updates: then the checks go on and only the notices are hidden.
			$excluded = $this->excluded_logins( $all );
			if ( $excluded ) {
				$m->set( self::MODULE, 'updates_visible_to', implode( ', ', $excluded ), __( 'Users who still see updates', 'ux-studio' ), 'MPSUM:advanced:excluded_users' );
				$m->note( __( 'The plugin excludes some users from the disabling, so they can still update. The module keeps the update check running for them and only hides the notices from everybody else.', 'ux-studio' ) );
			}
			$this->hide_and_stop( $m, $off, ! $excluded, $origin );
		}
		$m->lossy( __( 'E-mail notification settings, the update log and updates of development versions of the plugin are not transferred.', 'ux-studio' ) );
		return $m;
	}
}
