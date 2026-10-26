<?php
/**
 * PHP version check: daily refresh from php.net, the e-mail, and the current result for REST and the notice.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\SecurityOptimization;

use UxStudio\Core\ActivityLog;

defined( 'ABSPATH' ) || exit;

/**
 * The WordPress side of PhpVersionCheck.
 *
 * Part of the plugin core since 0.7.1 (Core\PhpVersion\Bootstrap): the cron, the e-mail, the notice and the admin bar
 * node run with no module enabled, so a freshly installed site is warned too. The cron hook name is unchanged since
 * 0.7.0, so a site that updates with Security on keeps its single schedule.
 *
 * php.net API (checked 2026-10-25, see PLAN.md 29.5):
 *  - GET https://www.php.net/releases/states  -> {"8":{"8.4":{"state":"stable","security_support_end":"2028-12-31T00:00:00+00:00",...}}}
 *  - GET https://www.php.net/releases/?json&version=8.4&max=10 -> {"8.4.27":{"tags":["security"],"date":"21 Oct 2026",...},...}
 */
final class PhpVersionMaintenance {

	public const CRON_HOOK = 'uxstudio_php_version_refresh';

	/** Option: array{fetched_at:int,ok:bool,branches:array<string,array{min:?string,eol:string}>}. Last good remote data. */
	public const OPTION_REMOTE = 'uxstudio_php_version_remote';

	/** Option: array{until:int,version:string}. The "verified with hosting" button. */
	public const OPTION_SNOOZE = 'uxstudio_php_version_snooze';

	/** Option: array{last:int}. When the critical e-mail went out last. */
	public const OPTION_MAIL = 'uxstudio_php_version_mail';

	private const BASE        = 'https://www.php.net/';
	private const TIMEOUT     = 5;
	private const MAX_BYTES   = 131072;
	private const MAX_BRANCHES = 8;

	/** @var callable|null Test seam: replaces wp_mail(). Receives (to, subject, body), returns bool. */
	private static $mailer = null;

	/**
	 * Replace the mail function (tests only).
	 *
	 * @param callable|null $mailer Mailer.
	 */
	public static function set_mailer( ?callable $mailer ): void {
		self::$mailer = $mailer;
	}

	/**
	 * Wire the cron job (plugin core, every request).
	 */
	public static function register(): void {
		add_action( self::CRON_HOOK, array( self::class, 'run' ) );
		self::schedule( 5 * MINUTE_IN_SECONDS );
	}

	/**
	 * Make sure the daily job exists (never a second one).
	 *
	 * @param int $delay Seconds until the first run.
	 */
	public static function schedule( int $delay ): void {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + $delay, 'daily', self::CRON_HOOK );
		}
	}

	/**
	 * Plugin deactivated: stop the cron. Stored data stays.
	 */
	public static function unschedule(): void {
		wp_clear_scheduled_hook( self::CRON_HOOK );
	}

	/**
	 * The table in force: bundled baseline plus the last good remote data.
	 *
	 * @return array
	 */
	public static function table(): array {
		$remote = get_option( self::OPTION_REMOTE, array() );
		return PhpVersionCheck::merge(
			PhpVersionCheck::baseline(),
			is_array( $remote ) && is_array( $remote['branches'] ?? null ) ? $remote['branches'] : array()
		);
	}

	/**
	 * The decision for this server, right now (no HTTP).
	 *
	 * @return array
	 */
	public static function result(): array {
		return PhpVersionCheck::evaluate( PHP_VERSION, self::table(), gmdate( 'Y-m-d' ) );
	}

	/**
	 * Result plus the context REST consumers need.
	 *
	 * @return array
	 */
	public static function payload(): array {
		$remote = get_option( self::OPTION_REMOTE, array() );
		$remote = is_array( $remote ) ? $remote : array();
		$result = self::result();
		return array_merge(
			$result,
			array(
				// The sentence the admin notice shows (translated here, so the wizard says the same thing).
				'message'       => 'ok' === $result['level'] ? '' : PhpVersionNotice::message( $result ),
				'backport_note' => ! empty( $result['distro_build'] ) && 'ok' !== $result['level'] ? PhpVersionNotice::backport_note() : '',
				'php_full'   => PHP_VERSION,
				'checked'    => gmdate( 'Y-m-d' ),
				'source'     => ! empty( $remote['ok'] ) ? 'php.net' : 'bundled',
				'fetched_at' => (int) ( $remote['fetched_at'] ?? 0 ),
				'snoozed'    => PhpVersionCheck::snoozed( get_option( self::OPTION_SNOOZE, array() ), PHP_VERSION, time() ),
			)
		);
	}

	/**
	 * Cron: refresh the remote data, then send the e-mail when it is due.
	 */
	public static function run(): void {
		self::refresh();
		self::maybe_mail();
	}

	/**
	 * Fetch php.net and store the validated answer. On any failure the last good data stays untouched.
	 *
	 * @return bool Whether new data was stored.
	 */
	public static function refresh(): bool {
		$started = microtime( true );
		$states  = self::fetch( 'releases/states' );
		if ( null === $states ) {
			return false;
		}

		$releases = array();
		$count    = 0;
		foreach ( $states as $by_branch ) {
			foreach ( (array) $by_branch as $branch => $info ) {
				$branch = (string) $branch;
				if ( ! preg_match( '/^\d+\.\d+$/', $branch ) || ! is_array( $info ) || 'eol' === ( $info['state'] ?? '' ) ) {
					continue;
				}
				if ( ++$count > self::MAX_BRANCHES || microtime( true ) - $started > 20 ) {
					break 2;
				}
				$list = self::fetch( 'releases/?json&version=' . rawurlencode( $branch ) . '&max=10' );
				if ( null !== $list ) {
					$releases[ $branch ] = $list;
				}
			}
		}

		$branches = PhpVersionCheck::normalize_remote( $states, $releases );
		if ( array() === $branches ) {
			return false;
		}
		update_option(
			self::OPTION_REMOTE,
			array(
				'fetched_at' => time(),
				'ok'         => true,
				'branches'   => $branches,
			),
			false
		);
		return true;
	}

	/**
	 * One small JSON GET. Null on any problem (network, HTTP status, size, JSON).
	 *
	 * @param string $path Path below https://www.php.net/.
	 * @return array|null
	 */
	private static function fetch( string $path ): ?array {
		$response = wp_safe_remote_get(
			self::BASE . $path,
			array(
				'timeout'             => self::TIMEOUT,
				'redirection'         => 2,
				'limit_response_size' => self::MAX_BYTES,
				'user-agent'          => 'UX Studio PHP version check',
				'headers'             => array( 'Accept' => 'application/json' ),
			)
		);
		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return null;
		}
		$body = (string) wp_remote_retrieve_body( $response );
		if ( '' === $body || strlen( $body ) >= self::MAX_BYTES ) {
			return null; // Empty, or cut off at the size limit.
		}
		$data = json_decode( $body, true );
		return is_array( $data ) ? $data : null;
	}

	/**
	 * Send the "critical" e-mail when due; forget the state as soon as the site is no longer critical.
	 *
	 * @return bool Whether an e-mail went out.
	 */
	public static function maybe_mail(): bool {
		$result = self::result();
		$state  = get_option( self::OPTION_MAIL, array() );

		if ( 'critical' !== $result['level'] ) {
			if ( array() !== $state && false !== $state ) {
				delete_option( self::OPTION_MAIL );
			}
			return false;
		}
		if ( ! PhpVersionCheck::mail_due( $state, $result['level'], time() ) ) {
			return false;
		}
		// Written BEFORE the mail goes out: a crash or a parallel cron run can never send it twice.
		update_option( self::OPTION_MAIL, array( 'last' => time() ), false );
		$sent = self::send_mail( $result );
		ActivityLog::log( 'security-optimization', 'php_version_mail', 'setting', 0, array( 'version' => $result['current'], 'reason' => $result['reason'], 'mail' => $sent ? 'sent' : 'failed' ) );
		return $sent;
	}

	/**
	 * The e-mail to the site address.
	 *
	 * @param array $result Result of evaluate().
	 */
	private static function send_mail( array $result ): bool {
		$to = (string) get_option( 'admin_email', '' );
		if ( '' === $to ) {
			return false;
		}
		$subject = sprintf(
			/* translators: %s: site name. */
			__( '[%s] Critical: the PHP version of your website is not safe', 'ux-studio' ),
			wp_specialchars_decode( (string) get_option( 'blogname', '' ), ENT_QUOTES )
		);

		$body  = '<html><body style="font-family:sans-serif;">';
		$body .= '<h2 style="color:#d63638;">' . esc_html__( 'The PHP version of your website is not safe', 'ux-studio' ) . '</h2>';
		$body .= '<p>' . esc_html( PhpVersionNotice::message( $result ) ) . '</p>';
		if ( '' !== $result['advisory_url'] ) {
			$body .= '<p><a href="' . esc_url( $result['advisory_url'] ) . '">' . esc_html__( 'Security advisory on php.net', 'ux-studio' ) . '</a></p>';
		}
		if ( $result['distro_build'] ) {
			$body .= '<p>' . esc_html( PhpVersionNotice::backport_note() ) . '</p>';
		}
		$body .= '<p><a href="' . esc_url( admin_url() ) . '">' . esc_html__( 'Open the administration', 'ux-studio' ) . '</a></p>';
		$body .= '</body></html>';

		if ( null !== self::$mailer ) {
			return (bool) ( self::$mailer )( $to, $subject, $body );
		}
		return (bool) wp_mail( $to, $subject, $body, array( 'Content-Type: text/html; charset=UTF-8' ) );
	}
}
