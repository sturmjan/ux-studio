<?php
/**
 * One-time switch-on of Indexing & Sharing for sites that are already connected to UX1 Network app (PLAN.md 39.3).
 *
 * @package UxStudio
 */

namespace UxStudio\Core\Onboarding;

use UxStudio\Core\ActivityLog;
use UxStudio\Core\CentralApp\Gateway;
use UxStudio\Core\Migration\Overlaps;
use UxStudio\Modules\IndexingNotice\IndexNow;
use UxStudio\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Decision O2: a site that was set up before this release and is already paired with UX1 Network app gets the
 * module `indexing-notice` switched on (through the activation guard, never directly), `social_refresh_enabled`
 * with `social_key_source = ca`, and IndexNow, once, on the first administration request of an administrator
 * after the update. A site that is not connected is left alone: it never gets the Facebook refresh without keys,
 * and a module that is off stays off.
 *
 * "A module the user switched off stays off": the plugin has never kept a history of that, so from this release
 * on a switch-off made on the Modules screen is recorded (Rest::toggle_module, `DISABLED_OPTION`) and respected
 * here. A site that switched the module off BEFORE this release has no such record, so the module is switched on
 * once (and the notice says so, with a link to the settings).
 *
 * Runs once (`MARK_OPTION`), then only the notice is left, until it is dismissed.
 */
final class SharingUpgrade {

	/** Option: array{time:int,result:string,notice:bool}; its presence means "done". */
	public const MARK_OPTION = 'uxstudio_sharing_upgrade';

	/** Option: module id => unix time the administrator switched it off on the Modules screen. */
	public const DISABLED_OPTION = 'uxstudio_modules_disabled';

	public const MODULE = 'indexing-notice';

	public const DISMISS_ACTION = 'uxstudio_sharing_upgrade_dismiss';

	/**
	 * What to do, from three facts.
	 *
	 * @param bool $connected     Paired with UX1 Network app and not paused.
	 * @param bool $enabled       The module is on.
	 * @param bool $user_disabled The administrator switched it off on the Modules screen.
	 * @return string `skip` (nothing) | `configure` (module on already: set the options) | `enable` (switch it on, then set the options)
	 */
	public static function decide( bool $connected, bool $enabled, bool $user_disabled ): string {
		if ( ! $connected ) {
			return 'skip';
		}
		if ( $enabled ) {
			return 'configure';
		}
		return $user_disabled ? 'skip' : 'enable';
	}

	/**
	 * Settings to store: only keys the site has not stored itself.
	 *
	 * @param array<string, mixed> $stored        Stored settings of the module (may be empty).
	 * @param bool                 $indexnow_free No other plugin sends IndexNow already.
	 * @return array<string, mixed>
	 */
	public static function settings_to_store( array $stored, bool $indexnow_free ): array {
		$want = array(
			'social_refresh_enabled' => true,
			'social_key_source'      => 'ca',
		);
		if ( $indexnow_free ) {
			$want['indexnow_enabled'] = true;
		}
		return array_diff_key( $want, $stored );
	}

	/**
	 * Hooks (admin only), registered from Plugin::boot().
	 */
	public static function register(): void {
		add_action( 'admin_init', array( self::class, 'run' ) );
		add_action( 'admin_notices', array( self::class, 'render_notice' ) );
		add_action( 'admin_post_' . self::DISMISS_ACTION, array( self::class, 'handle_dismiss' ) );
	}

	/**
	 * Remember that an administrator switched a module off or on again on the Modules screen.
	 *
	 * @param string $id      Module id.
	 * @param bool   $enabled New state.
	 */
	public static function record_choice( string $id, bool $enabled ): void {
		$list = (array) get_option( self::DISABLED_OPTION, array() );
		if ( $enabled ) {
			unset( $list[ $id ] );
		} else {
			$list[ $id ] = time();
		}
		update_option( self::DISABLED_OPTION, $list, false );
	}

	/**
	 * Did an administrator switch this module off (on the Modules screen)?
	 *
	 * @param string $id Module id.
	 */
	public static function was_disabled_by_user( string $id ): bool {
		$list = (array) get_option( self::DISABLED_OPTION, array() );
		return isset( $list[ $id ] );
	}

	/**
	 * The one-time run.
	 */
	public static function run(): void {
		if ( false !== get_option( self::MARK_OPTION, false ) || ! current_user_can( 'manage_options' ) || wp_doing_ajax() ) {
			return;
		}
		$modules = Plugin::instance()->modules ?? null;
		if ( null === $modules || \UxStudio\Core\Migration\Preview::is_active() ) {
			return;
		}
		$enabled = in_array( self::MODULE, $modules->enabled_ids(), true );
		$action  = self::decide( Gateway::is_active(), $enabled, self::was_disabled_by_user( self::MODULE ) );
		$result  = $action;
		$notice  = false;

		if ( 'enable' === $action ) {
			$state = ( new Applier( $modules ) )->enable( self::MODULE, false );
			if ( ! in_array( $state['status'], array( 'enabled', 'already' ), true ) ) {
				// Blocked by the overlap guard: nothing is forced, and the run is not repeated.
				$result = 'blocked';
				$action = 'skip';
			}
		}
		if ( in_array( $action, array( 'enable', 'configure' ), true ) ) {
			$module = $modules->instance( self::MODULE );
			if ( $module ) {
				$stored = (array) get_option( 'uxstudio_' . str_replace( '-', '_', self::MODULE ), array() );
				$values = self::settings_to_store( $stored, array() === IndexNow::foreign_senders() );
				if ( $values ) {
					$module->save_settings( $values );
					$notice = true;
				}
				$notice = $notice || 'enable' === $action;
			}
		}

		update_option(
			self::MARK_OPTION,
			array(
				'time'   => time(),
				'result' => $result,
				'notice' => $notice,
			),
			true
		);
		Overlaps::flush();
		ActivityLog::log( 'core', 'sharing_upgrade', 'module', 0, array( 'result' => $result, 'notice' => $notice ) );
	}

	/**
	 * The notice, once.
	 */
	public static function render_notice(): void {
		$mark = get_option( self::MARK_OPTION, false );
		if ( ! is_array( $mark ) || empty( $mark['notice'] ) || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$dismiss = wp_nonce_url( admin_url( 'admin-post.php?action=' . self::DISMISS_ACTION ), self::DISMISS_ACTION );
		echo '<div class="notice notice-info"><p><strong>' . esc_html__( 'New: Facebook previews and IndexNow refresh by themselves.', 'ux-studio' ) . '</strong> ';
		echo esc_html__( 'After you change a published page, Facebook fetches its new preview and Bing and Seznam hear about the change. You can turn either off in the settings.', 'ux-studio' ) . '</p>';
		printf(
			'<p><a href="%s" class="button button-primary">%s</a> <a href="%s" class="button">%s</a></p></div>',
			esc_url( admin_url( 'admin.php?page=ux-studio#/module?id=' . self::MODULE ) ),
			esc_html__( 'Open the settings', 'ux-studio' ),
			esc_url( $dismiss ),
			esc_html__( 'Dismiss', 'ux-studio' )
		);
	}

	/**
	 * admin-post: dismiss the notice.
	 */
	public static function handle_dismiss(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to manage this module.', 'ux-studio' ) );
		}
		check_admin_referer( self::DISMISS_ACTION );
		$mark = get_option( self::MARK_OPTION, false );
		if ( is_array( $mark ) ) {
			$mark['notice'] = false;
			update_option( self::MARK_OPTION, $mark, true );
		}
		wp_safe_redirect( wp_get_referer() ? wp_get_referer() : admin_url() );
		exit;
	}
}
