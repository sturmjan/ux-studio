<?php
/**
 * Guards against two things doing the same job: refuses to enable a module while
 * a foreign plugin that fully replaces it is active, and tells the admin when
 * such a plugin is activated next to an enabled module. Never deactivates anything.
 *
 * @package UxStudio
 */

namespace UxStudio\Core\Migration;

use UxStudio\Core\ActivityLog;

defined( 'ABSPATH' ) || exit;

/**
 * PLAN.md 26.5.
 *
 * Programmatic switch-off: the refusal lives in the REST route
 * (POST uxstudio/v1/modules/{id}), so WP-CLI, `Modules::set_enabled()` and code
 * of other plugins are not affected. To disable the check on the REST route too:
 *
 *     add_filter( 'uxstudio_overlap_guard_enabled', '__return_false' );
 *
 * The filter receives `( bool $enabled, string[] $module_ids )`, so it can also
 * exempt a single module.
 */
final class ActivationGuard {

	public const ERROR_CODE = 'uxstudio_plugin_overlap';

	private const FRESH_TRANSIENT = 'uxstudio_overlap_activated';
	private const DISMISS_ACTION  = 'uxstudio_overlap_ignore';

	/**
	 * Hook the notices and the activated_plugin watcher.
	 */
	public static function register(): void {
		add_action( 'activated_plugin', array( self::class, 'on_activated' ), 10, 1 );
		add_action( 'admin_notices', array( self::class, 'render_notice' ) );
		add_action( 'admin_post_' . self::DISMISS_ACTION, array( self::class, 'handle_dismiss' ) );
	}

	/**
	 * Decide whether the given modules may be switched on.
	 *
	 * @param string[] $module_ids Module ids about to be enabled.
	 * @param bool     $confirmed  Whether the user confirmed a partial overlap (`confirm_overlap`).
	 * @return array{result:string,blocking:array,confirm:array}
	 *         result: `allow` | `allow_override` (confirmed partial overlap) | `blocked` | `confirm`.
	 */
	public static function decide( array $module_ids, bool $confirmed = false ): array {
		$none = array(
			'result'   => 'allow',
			'blocking' => array(),
			'confirm'  => array(),
		);
		/**
		 * Whether the plugin-overlap guard is active for this REST enable request.
		 * Default true; return false to allow enabling modules next to overlapping plugins.
		 *
		 * @param bool     $enabled    Guard on/off.
		 * @param string[] $module_ids Module ids being enabled.
		 */
		if ( ! apply_filters( 'uxstudio_overlap_guard_enabled', true, $module_ids ) ) {
			return $none;
		}

		$blocking = array();
		$confirm  = array();
		foreach ( $module_ids as $id ) {
			foreach ( Overlaps::for_module( (string) $id ) as $row ) {
				if ( Overlaps::GROUP_FULL === $row['group'] ) {
					$blocking[ $row['key'] . '|' . $id ] = $row;
				} elseif ( Overlaps::GROUP_CUSTOMISER === $row['group'] ) {
					$confirm[ $row['key'] . '|' . $id ] = $row;
				}
				// Group B (partial, info only) never blocks.
			}
		}
		$blocking = array_values( $blocking );
		$confirm  = array_values( $confirm );

		if ( $blocking ) {
			return array(
				'result'   => 'blocked',
				'blocking' => $blocking,
				'confirm'  => $confirm,
			);
		}
		if ( $confirm ) {
			return array(
				'result'   => $confirmed ? 'allow_override' : 'confirm',
				'blocking' => array(),
				'confirm'  => $confirm,
			);
		}
		return $none;
	}

	/**
	 * The 409 error for a `blocked` / `confirm` decision, or null when allowed.
	 *
	 * @param array{result:string,blocking:array,confirm:array} $decision From decide().
	 * @return \WP_Error|null
	 */
	public static function error_for( array $decision ) {
		if ( 'blocked' !== $decision['result'] && 'confirm' !== $decision['result'] ) {
			return null;
		}
		$rows = array_merge( $decision['blocking'], $decision['confirm'] );
		$list = array();
		foreach ( $rows as $row ) {
			$list[] = array(
				'name'        => $row['name'],
				'basename'    => $row['basename'],
				'slug'        => $row['slug'],
				'migratable'  => null !== SourceRegistry::get( (string) $row['slug'] ),
				'type'        => $row['type'],
				'module'      => $row['module'],
				'module_name' => self::module_name( (string) $row['module'] ),
			);
		}
		$names = implode( ', ', array_unique( array_column( $list, 'name' ) ) );

		if ( 'blocked' === $decision['result'] ) {
			$message = sprintf(
				/* translators: %s: comma-separated plugin names. */
				__( 'The module was not enabled: an active plugin already does the same job (%s). Deactivate it first, otherwise both would run at once and conflict.', 'ux-studio' ),
				$names
			);
		} else {
			$message = sprintf(
				/* translators: %s: comma-separated plugin names. */
				__( 'This module partly overlaps with an active plugin (%s). Enable it only if you want both to run.', 'ux-studio' ),
				$names
			);
		}

		return new \WP_Error(
			self::ERROR_CODE,
			$message,
			array(
				'status'                => 409,
				'plugins'               => $list,
				'requires_confirmation' => 'confirm' === $decision['result'],
			)
		);
	}

	/**
	 * Convenience for callers that only need the error.
	 *
	 * @param string[] $module_ids Module ids about to be enabled.
	 * @param bool     $confirmed  Partial overlap confirmed by the user.
	 * @return \WP_Error|null
	 */
	public static function check( array $module_ids, bool $confirmed = false ) {
		return self::error_for( self::decide( $module_ids, $confirmed ) );
	}

	/**
	 * Write the guard outcome to the Activity Log (refusal or confirmed override).
	 *
	 * @param string[] $module_ids Module ids.
	 * @param array    $decision   From decide().
	 */
	public static function log_decision( array $module_ids, array $decision ): void {
		// Asking for a confirmation is not an event worth recording; the outcome is.
		$action = array(
			'blocked'        => 'overlap_blocked',
			'allow_override' => 'overlap_override',
		)[ $decision['result'] ] ?? '';
		if ( '' === $action ) {
			return;
		}
		$plugins = array_merge( $decision['blocking'], $decision['confirm'] );
		ActivityLog::log(
			'core',
			$action,
			'module',
			0,
			array(
				'modules' => array_values( $module_ids ),
				'plugins' => array_values( array_unique( array_column( $plugins, 'basename' ) ) ),
			)
		);
	}

	/**
	 * Translated module name; the wildcard means "any module".
	 *
	 * @param string $module_id Module id or `*`.
	 */
	public static function module_name( string $module_id ): string {
		if ( Catalog::ANY_MODULE === $module_id ) {
			return __( 'UX Studio modules', 'ux-studio' );
		}
		if ( class_exists( '\UxStudio\Plugin' ) && isset( \UxStudio\Plugin::instance()->modules ) ) {
			$meta = \UxStudio\Plugin::instance()->modules->all();
			if ( ! empty( $meta[ $module_id ]['name'] ) ) {
				// phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText -- module names are msgids registered via meta.json.
				return translate( (string) $meta[ $module_id ]['name'], 'ux-studio' );
			}
		}
		return $module_id;
	}

	/**
	 * A plugin was activated: if it overlaps an enabled module, remember it for one
	 * notice. Nothing is deactivated.
	 *
	 * @param string $plugin Plugin basename.
	 */
	public static function on_activated( $plugin ): void {
		Overlaps::flush();
		$hits = array();
		foreach ( Overlaps::current() as $row ) {
			if ( $row['basename'] === $plugin && Overlaps::GROUP_PARTIAL !== $row['group'] && ! in_array( $row['key'], Overlaps::ignored_keys(), true ) ) {
				$hits[] = array(
					'key'    => $row['key'],
					'name'   => $row['name'],
					'module' => $row['module'],
				);
			}
		}
		if ( ! $hits ) {
			return;
		}
		$stored = (array) get_transient( self::FRESH_TRANSIENT );
		set_transient( self::FRESH_TRANSIENT, array_merge( $stored, $hits ), 10 * MINUTE_IN_SECONDS );
		ActivityLog::log( 'core', 'overlap_plugin_activated', 'plugin', 0, array( 'plugin' => $plugin, 'modules' => array_column( $hits, 'module' ) ) );
	}

	/**
	 * wp-admin notice: plugins that duplicate an enabled module (full matches
	 * only) plus a one-off line for a plugin that was just activated. Shown
	 * outside the UX Studio screen - the Modules screen has its own panel.
	 */
	public static function render_notice(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( $screen && 'toplevel_page_ux-studio' === $screen->id ) {
			return;
		}

		$fresh = array_values( array_filter( (array) get_transient( self::FRESH_TRANSIENT ), 'is_array' ) );
		if ( $fresh ) {
			delete_transient( self::FRESH_TRANSIENT );
		}
		$ignored = Overlaps::ignored_keys();
		$rows    = array_values(
			array_filter(
				Overlaps::current(),
				static fn( $r ) => Overlaps::GROUP_FULL === $r['group'] && ! in_array( $r['key'], $ignored, true )
			)
		);
		if ( ! $rows && ! $fresh ) {
			return;
		}

		echo '<div class="notice notice-warning"><p><strong>' . esc_html__( 'UX Studio: two plugins doing the same job', 'ux-studio' ) . '</strong></p>';
		foreach ( $fresh as $hit ) {
			echo '<p>' . esc_html(
				sprintf(
					/* translators: 1: plugin name, 2: UX Studio module name. */
					__( 'Just activated: %1$s overlaps with the UX Studio module "%2$s". Nothing was deactivated - decide which of them you want to keep.', 'ux-studio' ),
					(string) ( $hit['name'] ?? '' ),
					self::module_name( (string) ( $hit['module'] ?? '' ) )
				)
			) . '</p>';
		}
		if ( $rows ) {
			echo '<ul style="list-style:disc;margin-left:1.5em">';
			foreach ( $rows as $row ) {
				echo '<li>' . esc_html(
					sprintf(
						/* translators: 1: plugin name, 2: UX Studio module name. */
						__( '%1$s is active and the UX Studio module "%2$s" does the same.', 'ux-studio' ),
						$row['name'],
						self::module_name( (string) $row['module'] )
					)
				) . '</li>';
			}
			echo '</ul>';
			$dismiss = wp_nonce_url( admin_url( 'admin-post.php?action=' . self::DISMISS_ACTION ), self::DISMISS_ACTION );
			printf(
				'<p><a href="%s" class="button button-primary">%s</a> <a href="%s" class="button">%s</a></p>',
				esc_url( admin_url( 'admin.php?page=ux-studio' ) ),
				esc_html__( 'Open UX Studio modules', 'ux-studio' ),
				esc_url( $dismiss ),
				esc_html__( 'Ignore these overlaps', 'ux-studio' )
			);
		}
		echo '</div>';
	}

	/**
	 * admin-post: put every currently listed full overlap on the ignore list.
	 */
	public static function handle_dismiss(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to manage this module.', 'ux-studio' ) );
		}
		check_admin_referer( self::DISMISS_ACTION );

		$keys = array();
		foreach ( Overlaps::current() as $row ) {
			if ( Overlaps::GROUP_FULL === $row['group'] ) {
				$keys[] = $row['key'];
			}
		}
		$added = Overlaps::ignore( $keys );
		if ( $added ) {
			ActivityLog::log( 'core', 'overlap_ignored', 'module', 0, array( 'pairs' => $added ) );
		}
		wp_safe_redirect( wp_get_referer() ? wp_get_referer() : admin_url() );
		exit;
	}
}
