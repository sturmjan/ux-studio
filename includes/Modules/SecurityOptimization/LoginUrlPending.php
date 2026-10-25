<?php
/**
 * "Set, but not yet enforced" state of the custom login address (onboarding safety net).
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\SecurityOptimization;

defined( 'ABSPATH' ) || exit;

/**
 * The onboarding starter pack sets the custom login address in two steps so that nobody is locked out of a site
 * they have just configured:
 *
 *  A. The address is saved and WORKS (the login form is served there), but wp-login.php stays reachable.
 *     This is the pending state kept in one option: the slug and the time it was set.
 *  B. After the administrator opened the new address and confirmed it, the pending state is cleared and the
 *     normal blocking of wp-login.php starts.
 *
 * Pending never expires into blocking: after 24 hours it is only reported as `expired` (so the UI can remind),
 * wp-login.php stays open until somebody confirms. Pending belongs to ONE slug; when the administrator
 * changes the slug by hand it no longer applies and the normal rules count.
 */
final class LoginUrlPending {

	public const OPTION = 'uxstudio_login_url_pending';

	/** After this long the UI reminds the administrator; the state itself does not change. */
	public const REMIND_AFTER = DAY_IN_SECONDS;

	/**
	 * Pure: state for a stored record and the slug in force.
	 *
	 * @param mixed  $record Stored option value.
	 * @param string $slug   Current login slug.
	 * @param int    $now    Current timestamp.
	 * @return string `none`, `pending` or `expired`.
	 */
	public static function decide( $record, string $slug, int $now ): string {
		if ( ! is_array( $record ) || '' === $slug || (string) ( $record['slug'] ?? '' ) !== $slug ) {
			return 'none';
		}
		$since = (int) ( $record['since'] ?? 0 );
		return ( $now - $since ) >= self::REMIND_AFTER ? 'expired' : 'pending';
	}

	/**
	 * State for the slug in force right now.
	 *
	 * @param string $slug Current login slug.
	 * @return string `none`, `pending` or `expired`.
	 */
	public static function state( string $slug ): string {
		return self::decide( get_option( self::OPTION, null ), $slug, time() );
	}

	/**
	 * Is wp-login.php being held open for this slug?
	 *
	 * @param string $slug Current login slug.
	 */
	public static function is_pending( string $slug ): bool {
		return 'none' !== self::state( $slug );
	}

	/**
	 * Start the pending state (call BEFORE the setting is saved, so there is no moment of enforcement).
	 *
	 * @param string $slug Login slug.
	 */
	public static function start( string $slug ): void {
		update_option(
			self::OPTION,
			array(
				'slug'  => $slug,
				'since' => time(),
			),
			false
		);
	}

	/**
	 * Leave the pending state (confirmed, or reverted).
	 */
	public static function clear(): void {
		delete_option( self::OPTION );
	}

	/**
	 * The stored record.
	 *
	 * @return array{slug:string,since:int}|null
	 */
	public static function record(): ?array {
		$record = get_option( self::OPTION, null );
		return is_array( $record ) ? array(
			'slug'  => (string) ( $record['slug'] ?? '' ),
			'since' => (int) ( $record['since'] ?? 0 ),
		) : null;
	}
}
