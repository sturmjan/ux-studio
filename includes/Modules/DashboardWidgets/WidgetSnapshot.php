<?php
/**
 * Stored snapshot of the widgets the wp-admin dashboard registers on this site.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\DashboardWidgets;

defined( 'ABSPATH' ) || exit;

/**
 * Captured on `wp_dashboard_setup` (late, before any hiding), so the settings screen can list every widget even
 * though `$wp_meta_boxes` only exists while the dashboard itself loads. The pure part (merging, stale marking,
 * source of a path) is in WidgetRules.
 */
final class WidgetSnapshot {

	public const OPTION = 'uxstudio_dashboard_widget_snapshot';

	/** Transient set by the "Refresh" button: the next capture is written even when nothing changed. */
	private const FORCE = 'uxstudio_dashboard_widget_force';

	/** A scan is written at least this often (seconds), so "not found" does not lag behind for long. */
	private const MAX_AGE = 6 * HOUR_IN_SECONDS;

	/** Id of the welcome panel of WordPress (an action, not a meta box). */
	public const CORE_WELCOME = 'dashboard_welcome';

	/**
	 * The stored snapshot.
	 *
	 * @return array{scan_at:int,widgets:array<string, array<string, mixed>>}
	 */
	public static function read(): array {
		$snap = get_option( self::OPTION, array() );
		$widgets = is_array( $snap ) && is_array( $snap['widgets'] ?? null ) ? $snap['widgets'] : array();
		foreach ( $widgets as $id => $widget ) {
			// Snapshots written before the titles were normalised may still hold markup.
			if ( is_array( $widget ) ) {
				$widgets[ $id ]['title'] = WidgetRules::clean_title( $widget['title'] ?? '', (string) $id );
			}
		}
		return array(
			'scan_at' => is_array( $snap ) ? (int) ( $snap['scan_at'] ?? 0 ) : 0,
			'widgets' => $widgets,
		);
	}

	/**
	 * Ask for the next capture to be written (the Refresh button).
	 */
	public static function request_refresh(): void {
		set_transient( self::FORCE, 1, 10 * MINUTE_IN_SECONDS );
	}

	/**
	 * Capture the registered dashboard widgets (call before anything is removed). Only for administrators: other users
	 * may not be shown every widget, and a snapshot made from their view would mark the rest as missing.
	 */
	public static function capture(): void {
		global $wp_meta_boxes;
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$seen = array();
		if ( isset( $wp_meta_boxes['dashboard'] ) && is_array( $wp_meta_boxes['dashboard'] ) ) {
			foreach ( $wp_meta_boxes['dashboard'] as $context => $priorities ) {
				foreach ( is_array( $priorities ) ? $priorities : array() as $priority => $boxes ) {
					foreach ( is_array( $boxes ) ? $boxes : array() as $id => $box ) {
						if ( ! is_array( $box ) || RoleWidgets::WELCOME_ID === (string) $id ) {
							continue; // Removed by remove_meta_box() (false), or the welcome panel with its own tab.
						}
						$src   = self::source( $box['callback'] ?? null );
						$seen[ (string) $id ] = array(
							'title'       => WidgetRules::clean_title( $box['title'] ?? '', (string) $id ),
							'context'     => (string) $context,
							'priority'    => (string) $priority,
							'source'      => $src['source'] . ( '' !== $src['folder'] ? ':' . $src['folder'] : '' ),
							'source_name' => $src['name'],
							'guess'       => ! in_array( $src['source'], array( 'core', 'uxstudio' ), true ),
						);
					}
				}
			}
		}
		// The welcome panel of WordPress is not a meta box; it is on the dashboard while its action is hooked.
		if ( has_action( 'welcome_panel', 'wp_welcome_panel' ) ) {
			$seen[ self::CORE_WELCOME ] = array(
				'title'       => __( 'Welcome', 'ux-studio' ),
				'context'     => 'welcome',
				'priority'    => 'high',
				'source'      => 'core',
				'source_name' => '',
				'guess'       => false,
			);
		}

		$old   = self::read();
		$force = (bool) get_transient( self::FORCE );
		$new   = WidgetRules::merge_snapshot( $old, $seen, time() );
		if ( $force || WidgetRules::needs_write( $old, $new, self::MAX_AGE ) ) {
			update_option( self::OPTION, $new, false );
			if ( $force ) {
				delete_transient( self::FORCE );
			}
		}
	}

	/**
	 * Remove one entry from the snapshot.
	 *
	 * @param string $id Widget id.
	 */
	public static function forget( string $id ): void {
		$snap = self::read();
		unset( $snap['widgets'][ $id ] );
		update_option( self::OPTION, $snap, false );
	}

	/**
	 * Where does the callback of a widget come from? A guess from the file that defines it.
	 *
	 * @param mixed $callback The meta box callback.
	 * @return array{source:string,folder:string,name:string}
	 */
	public static function source( $callback ): array {
		$none = array(
			'source' => 'unknown',
			'folder' => '',
			'name'   => '',
		);
		$file = self::callback_file( $callback );
		if ( '' === $file ) {
			return $none;
		}
		$class = WidgetRules::classify_path(
			$file,
			array(
				'plugins'    => WP_PLUGIN_DIR,
				'mu_plugins' => WPMU_PLUGIN_DIR,
				'themes'     => get_theme_root(),
				'core'       => ABSPATH . 'wp-admin|' . ABSPATH . WPINC,
				'self'       => UXSTUDIO_PATH,
			)
		);
		$name  = '';
		if ( 'plugin' === $class['source'] ) {
			$name = self::plugin_name( $class['folder'] );
		} elseif ( 'theme' === $class['source'] ) {
			$theme = wp_get_theme( $class['folder'] );
			$name  = $theme->exists() ? (string) $theme->get( 'Name' ) : '';
		}
		return array(
			'source' => $class['source'],
			'folder' => $class['folder'],
			'name'   => '' !== $name ? $name : $class['folder'],
		);
	}

	/**
	 * Name of the plugin that lives in a folder of the plugins directory.
	 *
	 * @param string $folder Folder (or file name of a single-file plugin).
	 */
	private static function plugin_name( string $folder ): string {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		foreach ( get_plugins() as $file => $data ) {
			$first = false === strpos( $file, '/' ) ? $file : strtok( $file, '/' );
			if ( strtolower( (string) $first ) === strtolower( $folder ) ) {
				return (string) ( $data['Name'] ?? '' );
			}
		}
		return '';
	}

	/**
	 * File that defines a callback ('' when it cannot be told).
	 *
	 * @param mixed $callback A callable in any of its forms.
	 */
	private static function callback_file( $callback ): string {
		try {
			if ( $callback instanceof \Closure || ( is_string( $callback ) && function_exists( $callback ) ) ) {
				return (string) ( new \ReflectionFunction( $callback ) )->getFileName();
			}
			if ( is_array( $callback ) && 2 === count( $callback ) && ( is_object( $callback[0] ) || is_string( $callback[0] ) ) && is_string( $callback[1] ) ) {
				return (string) ( new \ReflectionMethod( $callback[0], $callback[1] ) )->getFileName();
			}
			if ( is_string( $callback ) && false !== strpos( $callback, '::' ) ) {
				return (string) ( new \ReflectionMethod( $callback ) )->getFileName();
			}
			if ( is_object( $callback ) && method_exists( $callback, '__invoke' ) ) {
				return (string) ( new \ReflectionMethod( $callback, '__invoke' ) )->getFileName();
			}
		} catch ( \ReflectionException $e ) {
			return '';
		}
		return '';
	}
}
