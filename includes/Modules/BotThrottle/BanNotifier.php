<?php
/**
 * Ban notifier: emails the admin when Guard::exceeded() newly bans an IP.
 * The ban itself is already logged by Guard (Log::insert), so this only
 * owns the throttled email side.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\BotThrottle;

defined( 'ABSPATH' ) || exit;

/**
 * A distributed flood tripping many different IPs' bans in quick succession
 * must not turn into a mail bomb against the site admin, so the email is
 * throttled to at most one per window regardless of how many IPs get banned.
 */
final class BanNotifier {

	private const THROTTLE_OPTION = 'uxstudio_bot_throttle_ban_notify_last_sent';
	private const THROTTLE_WINDOW = 5 * MINUTE_IN_SECONDS;

	/**
	 * Handles the `ux_studio/bot_throttle/ip_banned` action.
	 *
	 * @param string $context Caller-defined identifier, e.g. 'analytics_hit'.
	 * @param string $ip_hash Salted hash of the banned IP (no raw IP - GDPR).
	 * @param int    $ban_for Ban duration in seconds.
	 */
	public static function notify( string $context, string $ip_hash, int $ban_for ): void {
		$last_sent = (int) get_option( self::THROTTLE_OPTION, 0 );
		if ( ( time() - $last_sent ) < self::THROTTLE_WINDOW ) {
			return;
		}
		update_option( self::THROTTLE_OPTION, time(), false );

		self::send_email( $context, $ip_hash, $ban_for );
	}

	/**
	 * @param string $context Caller-defined identifier.
	 * @param string $ip_hash Salted hash of the banned IP.
	 * @param int    $ban_for Ban duration in seconds.
	 */
	private static function send_email( string $context, string $ip_hash, int $ban_for ): void {
		$to = (string) get_option( 'admin_email' );
		if ( '' === $to ) {
			return;
		}

		$site_name = get_bloginfo( 'name' );
		$subject   = sprintf(
			/* translators: %s: site name */
			__( '[%s] Bot Throttle: an IP was banned for flooding the server', 'ux-studio' ),
			$site_name
		);

		$body  = '<html><body style="font-family:sans-serif;">';
		$body .= '<h2 style="color:#d63638;">' . esc_html__( 'Bot Throttle - IP banned', 'ux-studio' ) . '</h2>';
		$body .= '<p>' . sprintf(
			/* translators: 1: context identifier, 2: ban duration in minutes */
			esc_html__( 'An IP sent far more requests than a normal visitor to "%1$s" and was banned for %2$d minutes. The raw IP is never stored, only a salted hash, so it can be matched against server logs if needed.', 'ux-studio' ),
			esc_html( $context ),
			(int) round( $ban_for / MINUTE_IN_SECONDS )
		) . '</p>';
		$body .= '<p><code>' . esc_html( $ip_hash ) . '</code></p>';
		$body .= '<p>' . esc_html( admin_url( 'admin.php?page=ux-studio#/module?id=bot-throttle' ) ) . '</p>';
		$body .= '<p>' . esc_html__( 'Further bans within the next few minutes will still be logged but won\'t send another email, to avoid flooding this inbox during a sustained attack.', 'ux-studio' ) . '</p>';
		$body .= '</body></html>';

		wp_mail( $to, $subject, $body, array( 'Content-Type: text/html; charset=UTF-8' ) );
	}
}
