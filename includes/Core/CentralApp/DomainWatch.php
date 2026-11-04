<?php
/**
 * Watches the address of the site and tells the Central App when it changes (PLAN.md 40.3).
 *
 * @package UxStudio
 */

namespace UxStudio\Core\CentralApp;

use UxStudio\Core\ActivityLog;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * The Central App finds a site by its id, not by the address, so a moved site only has to say where it lives now:
 * the signed `?page=site_moved` with `{site_id, old_url, new_url}`. Run from Gateway::guard() before every call
 * to the Central App (at most once an hour while it fails or waits for a decision) and from a daily cron.
 *
 * Answers: `moved` (the Central App changed its record: the new address is stored here and logged), 202
 * `pending_decision` (both addresses answer, a person decides there: nothing changes here, the next run asks
 * again), `clone` (this site is a copy: the link is paused).
 */
final class DomainWatch {

	public const CRON_HOOK = 'uxstudio_ca_domain_watch';

	/** Transient: the last attempt to report a move (so a waiting or failing report is not repeated on every call). */
	private const THROTTLE = 'uxstudio_ca_move_try';

	/** Guard against calling itself (site_moved answered with 409). */
	private static bool $busy = false;

	/**
	 * Hooks and the daily cron, registered from Plugin::boot().
	 */
	public static function register(): void {
		add_action( self::CRON_HOOK, array( self::class, 'cron' ) );
		add_action( 'init', array( self::class, 'schedule' ) );
	}

	/**
	 * Schedule the daily check once the site is paired with the Central App.
	 */
	public static function schedule(): void {
		if ( Gateway::is_paired() && ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON_HOOK );
		}
	}

	/**
	 * Stop the cron (plugin deactivation).
	 */
	public static function unschedule(): void {
		wp_clear_scheduled_hook( self::CRON_HOOK );
	}

	/**
	 * Daily run.
	 */
	public static function cron(): void {
		if ( SiteIdentity::is_paused() ) {
			self::recheck();
			return;
		}
		self::check( true );
	}

	/**
	 * A paused link asks once a day whether it still is paused. Without this a site the Central App took back
	 * (operator chose "Move" or "Revert" after the pause) would stay paused for good, and the only way out,
	 * "Connect as a new site", would create a second record for the same site.
	 *
	 * @return string `resumed` | `clone` | `pending` | `error` | `none`
	 */
	public static function recheck(): string {
		$paused = SiteIdentity::paused();
		if ( null === $paused || self::$busy || ! Gateway::is_paired() ) {
			return 'none';
		}
		$current = home_url( '/' );
		$known   = SiteIdentity::known_url();

		self::$busy = true;
		$result     = Gateway::request(
			'site_moved',
			array(
				'old_url'  => '' !== $known ? $known : $current,
				'new_url'  => $current,
				'site_url' => $current,
			),
			array(),
			false
		);
		self::$busy = false;

		if ( $result instanceof WP_Error ) {
			return 'error';
		}

		$verdict = SiteIdentity::classify_move_response( $result['code'], $result['body'] );
		switch ( $verdict['kind'] ) {
			case 'moved':
				SiteIdentity::resume();
				SiteIdentity::set_known_url( $current );
				ActivityLog::log( 'content-sync', 'ca_link_resumed', 'setting', 0, array( 'url' => $current, 'was' => $paused['state'] ) );
				return 'resumed';
			case 'clone':
			case SiteIdentity::STATE_SUPERSEDED:
				SiteIdentity::pause( $verdict['kind'], '' !== $verdict['url'] ? $verdict['url'] : $paused['url'] );
				return 'clone';
			case 'pending':
				return 'pending';
		}
		// 410 / 423 keep (or refresh) the pause; anything else leaves it as it is.
		Gateway::inspect( $result['code'], $result['body'] );
		return 'error';
	}

	/**
	 * Compare the stored address with home_url() and report a move.
	 *
	 * @param bool $force Ignore the hourly throttle (the cron).
	 * @return string `off` | `init` | `none` | `throttled` | `moved` | `pending` | `clone` | `error`
	 */
	public static function check( bool $force ): string {
		if ( ! Gateway::is_paired() ) {
			return 'off';
		}
		$current  = home_url( '/' );
		$decision = SiteIdentity::move_decision( SiteIdentity::known_url(), $current, SiteIdentity::paused() );
		if ( 'init' === $decision ) {
			SiteIdentity::set_known_url( $current );
			return 'init';
		}
		if ( 'send' !== $decision ) {
			return 'none';
		}
		return self::report( SiteIdentity::known_url(), $current, $force );
	}

	/**
	 * Send `site_moved`.
	 *
	 * @param string $old_url Address the Central App knows.
	 * @param string $new_url Address now.
	 * @param bool   $force   Ignore the hourly throttle.
	 * @return string See check().
	 */
	public static function report( string $old_url, string $new_url, bool $force = true ): string {
		if ( self::$busy || SiteIdentity::is_paused() || '' === $new_url ) {
			return 'none';
		}
		if ( ! $force && false !== get_transient( self::THROTTLE ) ) {
			return 'throttled';
		}
		set_transient( self::THROTTLE, 1, HOUR_IN_SECONDS );

		self::$busy = true;
		$result     = Gateway::request(
			'site_moved',
			array(
				'old_url'  => $old_url,
				'new_url'  => $new_url,
				'site_url' => $new_url,
			),
			array(),
			false
		);
		self::$busy = false;

		if ( $result instanceof WP_Error ) {
			ActivityLog::log( 'content-sync', 'ca_site_moved_failed', 'setting', 0, array( 'error' => $result->get_error_message() ) );
			return 'error';
		}

		$verdict = SiteIdentity::classify_move_response( $result['code'], $result['body'] );
		switch ( $verdict['kind'] ) {
			case 'moved':
				SiteIdentity::set_known_url( $new_url );
				delete_transient( self::THROTTLE );
				ActivityLog::log( 'content-sync', 'ca_site_moved', 'setting', 0, array( 'from' => $old_url, 'to' => $new_url ) );
				return 'moved';
			case 'pending':
				ActivityLog::log( 'content-sync', 'ca_site_moved_pending', 'setting', 0, array( 'from' => $old_url, 'to' => $new_url ) );
				return 'pending';
			case 'clone':
				SiteIdentity::pause( SiteIdentity::STATE_CLONE, $verdict['url'] );
				ActivityLog::log( 'content-sync', 'ca_link_paused', 'setting', 0, array( 'state' => SiteIdentity::STATE_CLONE, 'url' => $verdict['url'] ) );
				return 'clone';
		}

		// The error answers 410 and 423 pause the link like on any other call.
		Gateway::inspect( $result['code'], $result['body'] );
		ActivityLog::log( 'content-sync', 'ca_site_moved_failed', 'setting', 0, array( 'http' => $result['code'] ) );
		return SiteIdentity::is_paused() ? 'clone' : 'error';
	}
}
