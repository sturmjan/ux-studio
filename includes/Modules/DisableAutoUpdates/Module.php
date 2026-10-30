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
 * either entirely or for a specific selection. On top of that (PLAN.md 27.10):
 *
 *  - hide the update notices per type (core / plugins / themes) from everybody except the listed users. Done by emptying what
 *    `get_site_transient( 'update_*' )` returns to a request that is not cron / WP-CLI, so the admin bar, the menu bubble, the
 *    plugin and theme rows, the Updates page and the footer nag all go quiet at once while the automatic updater still sees the offers;
 *  - switch the update CHECK off per type (`pre_site_transient_update_*`, the cron events, the load-time checks and the requests to
 *    api.wordpress.org), which also stops automatic updates of that type;
 *  - the exception for security (minor) core releases: they are fetched and installed even when core auto-updates are off.
 *
 * Hiding notices and stopping checks hides security fixes, which is why every one of these settings is off by default and the
 * help says so.
 */
final class Module extends BaseModule {

	/**
	 * Register hooks.
	 */
	public function boot(): void {
		$this->boot_notices_and_checks();

		$core         = (bool) $this->settings->get( 'disable_core_updates', true );
		$plugins      = (bool) $this->settings->get( 'disable_plugin_updates', true );
		$themes       = (bool) $this->settings->get( 'disable_theme_updates', true );
		$translations = (bool) $this->settings->get( 'disable_translation_updates', true );

		// Everything disabled: kill the automatic updater and its emails. Not with the security exception: it needs the updater.
		if ( $core && $plugins && $themes && $translations && ! $this->allow_security_core() ) {
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
		if ( $this->allow_security_core() ) {
			// Minor (security) releases only: major and development releases stay off whatever the type says, minor is forced on.
			add_filter( 'allow_major_auto_core_updates', '__return_false', 20 );
			add_filter( 'allow_dev_auto_core_updates', '__return_false', 20 );
			add_filter( 'allow_minor_auto_core_updates', '__return_true', 20 );
			return;
		}

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
		return array_merge( $this->automatic_schema(), $this->notices_schema() );
	}

	/**
	 * Automatic updates (the original settings of the module plus the security exception).
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function automatic_schema(): array {
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
				'key'     => 'allow_security_core',
				'type'    => 'toggle',
				'label'   => __( 'Still install security (minor) core updates', 'ux-studio' ),
				'help'    => __( 'WordPress keeps fetching and installing minor core releases (they carry the security fixes) even when core updates are disabled above; major releases stay off. Recommended whenever core updates are disabled.', 'ux-studio' ),
				'default' => false,
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

	/* ------------------------------------------------------------------ *
	 * Notices and checks (PLAN.md 27.10)
	 * ------------------------------------------------------------------ */

	/** Update kinds and the transient each one keeps its offers in. */
	private const KINDS = array(
		'core'    => 'update_core',
		'plugins' => 'update_plugins',
		'themes'  => 'update_themes',
	);

	/**
	 * Settings for hiding notices and stopping the checks.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function notices_schema(): array {
		$section = __( 'Notices and update checks', 'ux-studio' );
		$warning = __( 'Warning: this hides available security fixes. Outdated plugins and themes are the most common way a site gets hacked. Use it only when somebody else keeps the site up to date.', 'ux-studio' );
		return array(
			array(
				'key'     => 'hide_notices_core',
				'type'    => 'toggle',
				'label'   => __( 'Hide WordPress update notices', 'ux-studio' ),
				'help'    => $warning . ' ' . __( 'Removes the notice, the counter in the menu and the admin bar and the update rows for everybody except the users listed below. Automatic updates still see them.', 'ux-studio' ),
				'default' => false,
				'section' => $section,
			),
			array(
				'key'     => 'hide_notices_plugins',
				'type'    => 'toggle',
				'label'   => __( 'Hide plugin update notices', 'ux-studio' ),
				'default' => false,
				'section' => $section,
			),
			array(
				'key'     => 'hide_notices_themes',
				'type'    => 'toggle',
				'label'   => __( 'Hide theme update notices', 'ux-studio' ),
				'default' => false,
				'section' => $section,
			),
			array(
				'key'     => 'updates_visible_to',
				'type'    => 'text',
				'label'   => __( 'Users who still see updates', 'ux-studio' ),
				'help'    => __( 'Logins separated by commas. These users see the notices and can update as usual. When the field is empty, hidden notices are hidden from administrators too.', 'ux-studio' ),
				'default' => '',
				'section' => $section,
			),
			array(
				'key'     => 'disable_checks_core',
				'type'    => 'toggle',
				'label'   => __( 'Stop checking for WordPress updates', 'ux-studio' ),
				'help'    => $warning . ' ' . __( 'WordPress no longer asks wordpress.org whether a new version exists, so it also installs nothing automatically. With the security exception above, this is ignored for core.', 'ux-studio' ),
				'default' => false,
				'section' => $section,
			),
			array(
				'key'     => 'disable_checks_plugins',
				'type'    => 'toggle',
				'label'   => __( 'Stop checking for plugin updates', 'ux-studio' ),
				'help'    => __( 'No update check means no automatic plugin updates either.', 'ux-studio' ),
				'default' => false,
				'section' => $section,
			),
			array(
				'key'     => 'disable_checks_themes',
				'type'    => 'toggle',
				'label'   => __( 'Stop checking for theme updates', 'ux-studio' ),
				'help'    => __( 'No update check means no automatic theme updates either.', 'ux-studio' ),
				'default' => false,
				'section' => $section,
			),
		);
	}

	/**
	 * Whether the security (minor) exception for core is on.
	 */
	private function allow_security_core(): bool {
		return (bool) $this->settings->get( 'allow_security_core', false );
	}

	/**
	 * Which kinds have a setting switched on.
	 *
	 * @param string $prefix `hide_notices_` or `disable_checks_`.
	 * @return string[] Kinds (core, plugins, themes).
	 */
	private function enabled_kinds( string $prefix ): array {
		$out = array();
		foreach ( array_keys( self::KINDS ) as $kind ) {
			if ( (bool) $this->settings->get( $prefix . $kind, false ) ) {
				// Core checks must go on for the security exception: the offers are where the security releases come from.
				if ( 'disable_checks_' === $prefix && 'core' === $kind && $this->allow_security_core() ) {
					continue;
				}
				$out[] = $kind;
			}
		}
		return $out;
	}

	/**
	 * Hooks for hiding notices and stopping checks.
	 */
	private function boot_notices_and_checks(): void {
		foreach ( $this->enabled_kinds( 'hide_notices_' ) as $kind ) {
			add_filter( 'site_transient_' . self::KINDS[ $kind ], fn( $value ) => $this->hide_offers( $value, $kind ), PHP_INT_MAX - 10 );
		}

		$checks = $this->enabled_kinds( 'disable_checks_' );
		if ( array() === $checks ) {
			return;
		}
		foreach ( $checks as $kind ) {
			add_filter( 'pre_site_transient_' . self::KINDS[ $kind ], fn() => $this->quiet_transient( $kind ) );
			$this->remove_check_actions( $kind );
		}
		add_filter(
			'pre_http_request',
			function ( $pre, $args, $url ) use ( $checks ) {
				return $this->blocks_request( (string) $url, $checks ) ? new \WP_Error( 'uxstudio_updates_check_disabled', __( 'The update check is turned off in UX Studio.', 'ux-studio' ) ) : $pre;
			},
			10,
			3
		);
		// The recurring events would only wake WordPress up for nothing; core adds them back once the setting is off.
		add_action(
			'init',
			static function () use ( $checks ): void {
				$hooks = array(
					'core'    => 'wp_version_check',
					'plugins' => 'wp_update_plugins',
					'themes'  => 'wp_update_themes',
				);
				foreach ( $checks as $kind ) {
					if ( false !== wp_next_scheduled( $hooks[ $kind ] ) ) {
						wp_clear_scheduled_hook( $hooks[ $kind ] );
					}
				}
			},
			99
		);
	}

	/**
	 * Take the checks of one kind off the load-time and cron hooks WordPress registers in default-filters.php.
	 *
	 * @param string $kind core | plugins | themes.
	 */
	private function remove_check_actions( string $kind ): void {
		switch ( $kind ) {
			case 'core':
				remove_action( 'wp_version_check', 'wp_version_check' );
				remove_action( 'admin_init', '_maybe_update_core' );
				remove_action( 'load-update-core.php', 'wp_version_check' );
				break;
			case 'plugins':
				remove_action( 'wp_update_plugins', 'wp_update_plugins' );
				remove_action( 'admin_init', '_maybe_update_plugins' );
				remove_action( 'load-plugins.php', 'wp_update_plugins' );
				remove_action( 'load-update.php', 'wp_update_plugins' );
				remove_action( 'load-update-core.php', 'wp_update_plugins' );
				break;
			case 'themes':
				remove_action( 'wp_update_themes', 'wp_update_themes' );
				remove_action( 'admin_init', '_maybe_update_themes' );
				remove_action( 'load-themes.php', 'wp_update_themes' );
				remove_action( 'load-update.php', 'wp_update_themes' );
				remove_action( 'load-update-core.php', 'wp_update_themes' );
				break;
		}
	}

	/**
	 * What WordPress reads instead of the stored offers while the check of this kind is off: "checked just now, nothing new".
	 *
	 * @param string $kind core | plugins | themes.
	 */
	public function quiet_transient( string $kind ): object {
		global $wp_version;
		$now = time();
		if ( 'core' === $kind ) {
			return (object) array(
				'updates'         => array(),
				'last_checked'    => $now,
				'version_checked' => (string) ( $wp_version ?? '' ),
			);
		}
		return (object) array(
			'last_checked'    => $now,
			'checked'         => array(),
			'response'        => array(),
			'no_update'       => array(),
			'translations'    => array(),
		);
	}

	/**
	 * Whether a request is one of the update checks of a kind that is switched off.
	 *
	 * @param string   $url    Request URL.
	 * @param string[] $checks Kinds with the check off.
	 */
	public function blocks_request( string $url, array $checks ): bool {
		$parts = wp_parse_url( $url );
		$host  = strtolower( (string) ( $parts['host'] ?? '' ) );
		$path  = (string) ( $parts['path'] ?? '' );
		if ( 'api.wordpress.org' !== $host ) {
			return false;
		}
		return ( in_array( 'core', $checks, true ) && false !== strpos( $path, '/core/version-check/' ) )
			|| ( in_array( 'plugins', $checks, true ) && false !== strpos( $path, '/plugins/update-check/' ) )
			|| ( in_array( 'themes', $checks, true ) && false !== strpos( $path, '/themes/update-check/' ) );
	}

	/**
	 * Empty the offers a request sees. Cron and WP-CLI keep the real ones (the automatic updater and `wp plugin update` need
	 * them), and so do the users on the allow list.
	 *
	 * @param mixed  $value Stored transient.
	 * @param string $kind  core | plugins | themes.
	 * @return mixed
	 */
	public function hide_offers( $value, string $kind ) {
		if ( ! is_object( $value ) || self::background_request() || $this->user_sees_updates() ) {
			return $value;
		}
		$hidden = clone $value;
		if ( 'core' === $kind ) {
			$hidden->updates = array();
		} else {
			$hidden->response     = array();
			$hidden->translations = array();
		}
		return $hidden;
	}

	/**
	 * Cron and WP-CLI: nobody looks at a notice there.
	 */
	private static function background_request(): bool {
		return ( function_exists( 'wp_doing_cron' ) && wp_doing_cron() ) || ( defined( 'WP_CLI' ) && WP_CLI );
	}

	/**
	 * Whether the current user is on the allow list.
	 */
	private function user_sees_updates(): bool {
		if ( ! function_exists( 'wp_get_current_user' ) ) {
			return false;
		}
		$logins = array_filter( array_map( 'trim', explode( ',', strtolower( (string) $this->settings->get( 'updates_visible_to', '' ) ) ) ) );
		if ( array() === $logins ) {
			return false;
		}
		$user = wp_get_current_user();
		return $user && $user->exists() && in_array( strtolower( (string) $user->user_login ), $logins, true );
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
