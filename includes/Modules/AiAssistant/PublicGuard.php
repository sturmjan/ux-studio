<?php
/**
 * Abuse guards shared by the public (unauthenticated) chat endpoints.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\AiAssistant;

use UxStudio\Core\ClientIp;
use UxStudio\Core\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Everything here is keyed by a salted hash of the client IP (never the raw
 * IP, never the client-supplied session id - a visitor can rotate that on
 * every request). The e-mail cap is global on top of that, so even a botnet
 * rotating IPs cannot turn the widget into a mail bomb against the admin
 * inbox: requests above the cap are still stored, just not e-mailed.
 */
final class PublicGuard {

	/** Default for the `notification_email_hourly_limit` setting. */
	public const DEFAULT_EMAILS_PER_HOUR = 20;

	/** Notification e-mails a single IP may trigger per hour. */
	private const EMAILS_PER_IP_PER_HOUR = 3;

	/**
	 * Salted hash of the client IP (resolved via Core\ClientIp, so it is the
	 * real visitor behind Cloudflare/trusted proxies, not the proxy).
	 */
	public static function ip_hash(): string {
		$ip = ClientIp::get( 'auto' );
		return md5( ( '' !== $ip ? $ip : 'unknown' ) . wp_salt() );
	}

	/**
	 * Fixed-window counter per scope + client IP. Counts the hit and returns
	 * false once the limit for the current window is exceeded.
	 *
	 * @param string $scope  Short scope name (becomes part of the transient key).
	 * @param int    $limit  Allowed hits per window.
	 * @param int    $window Window length in seconds.
	 */
	public static function hit( string $scope, int $limit, int $window ): bool {
		$bucket = (int) floor( time() / max( 1, $window ) );
		$key    = 'uxstudio_ais_rl_' . $scope . '_' . md5( self::ip_hash() . '|' . $bucket );
		$count  = (int) get_transient( $key );
		if ( $count >= $limit ) {
			return false;
		}
		set_transient( $key, $count + 1, $window );
		return true;
	}

	/**
	 * Whether a public-chat notification e-mail may go out now: max
	 * EMAILS_PER_IP_PER_HOUR per client IP and the site-wide hourly cap from
	 * the settings. Consumes one slot of each when it returns true.
	 */
	public static function allow_notification_email(): bool {
		$global_limit = max( 0, (int) ( new Settings( 'uxstudio_ai_assistant' ) )->get( 'notification_email_hourly_limit', self::DEFAULT_EMAILS_PER_HOUR ) );
		if ( 0 === $global_limit ) {
			return false;
		}

		$global_key   = 'uxstudio_ais_mail_' . gmdate( 'YmdH' );
		$global_count = (int) get_transient( $global_key );
		if ( $global_count >= $global_limit ) {
			return false;
		}
		if ( ! self::hit( 'mail', self::EMAILS_PER_IP_PER_HOUR, HOUR_IN_SECONDS ) ) {
			return false;
		}

		set_transient( $global_key, $global_count + 1, 2 * HOUR_IN_SECONDS );
		return true;
	}

	/**
	 * A visitor-supplied address safe to put into a Reply-To header, or ''
	 * when it isn't one. Only the bare address is used (no display name), so
	 * nothing visitor-controlled but a validated e-mail reaches the header.
	 */
	public static function reply_to_address( string $email ): string {
		$email = trim( $email );
		if ( '' === $email || preg_match( '/[\r\n,;<>"]/', $email ) || ! is_email( $email ) ) {
			return '';
		}
		return $email;
	}
}
