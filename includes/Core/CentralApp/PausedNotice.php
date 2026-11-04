<?php
/**
 * Admin notice for a site that is a copy of another one, or was moved away (PLAN.md 40.4).
 *
 * @package UxStudio
 */

namespace UxStudio\Core\CentralApp;

use UxStudio\Modules\ContentSync\Module as ContentSyncModule;
use UxStudio\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * While the link is paused the plugin makes no call to the Central App. The notice says why and offers the one way
 * out: "Connect as a new site" (new site id, new key, then the normal connect step of the wizard).
 */
final class PausedNotice {

	public const ACTION = 'uxstudio_ca_connect_new';

	/**
	 * Hooks, registered from Plugin::boot().
	 */
	public static function register(): void {
		add_action( 'admin_notices', array( self::class, 'render' ) );
		add_action( 'admin_post_' . self::ACTION, array( self::class, 'handle' ) );
	}

	/**
	 * The sentence of the notice for a paused state.
	 *
	 * @param array{state:string,url:string,since:int} $paused Paused state.
	 */
	public static function message( array $paused ): string {
		$url = trim( (string) preg_replace( '#^https?://#i', '', $paused['url'] ), '/' );
		if ( SiteIdentity::STATE_SUPERSEDED === $paused['state'] ) {
			return '' !== $url
				? sprintf(
					/* translators: %s: new address of the site. */
					__( 'This site was moved to %s. The connection with the central app is paused.', 'ux-studio' ),
					$url
				)
				: __( 'This site was moved to another address. The connection with the central app is paused.', 'ux-studio' );
		}
		return '' !== $url
			? sprintf(
				/* translators: %s: address of the original site. */
				__( 'This site is a copy of the site %s. The connection with the central app is paused.', 'ux-studio' ),
				$url
			)
			: __( 'This site is a copy of another site. The connection with the central app is paused.', 'ux-studio' );
	}

	/**
	 * Print the notice.
	 */
	public static function render(): void {
		$paused = SiteIdentity::paused();
		if ( null === $paused || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$action = wp_nonce_url( admin_url( 'admin-post.php?action=' . self::ACTION ), self::ACTION );
		echo '<div id="uxstudio-ca-paused-notice" class="notice notice-warning"><p><strong>' . esc_html( self::message( $paused ) ) . '</strong></p>';
		echo '<p>' . esc_html__( 'Nothing is sent to the central app from this site: no monitoring data, no preview refresh, no CAPTCHA keys. If this really is a separate site, connect it as a new one.', 'ux-studio' ) . '</p>';
		printf(
			'<p><a href="%s" class="button button-primary">%s</a></p></div>',
			esc_url( $action ),
			esc_html__( 'Connect as a new site', 'ux-studio' )
		);
	}

	/**
	 * admin-post: start over as a new site and open the connect step.
	 */
	public static function handle(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to manage this module.', 'ux-studio' ) );
		}
		check_admin_referer( self::ACTION );

		$content_sync = Plugin::instance()->modules->instance( 'content-sync' );
		if ( $content_sync instanceof ContentSyncModule ) {
			Gateway::connect_as_new( $content_sync );
		}
		wp_safe_redirect( admin_url( 'admin.php?page=ux-studio#/wizard?mode=new&step=ca&reconnect=1' ) );
		exit;
	}
}
