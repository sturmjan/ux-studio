<?php
/**
 * One-time upgrade routines that must see the data of the PREVIOUS release before newer code acts on it.
 *
 * @package UxStudio
 */

namespace UxStudio\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Runs from Plugin::boot() right after the schema upgrade and before any module boots, so the first request
 * served by the new code records how the site looked under the old code. Each routine is idempotent and is
 * remembered in one option, so it never runs twice. Nothing here changes what a site looks like or does.
 *
 * 0.6.0:
 *  - `login_lock`: remembers whether the custom login address was configured BEFORE this release. Only such
 *    sites get the "your login address is in force" notice and e-mail; a site that sets it up later does not.
 *  - `csp_state`: Content-Security-Policy only had a mode setting in 0.5.0. The new mode bookkeeping
 *    (`since`, used by the Enforce gate and by the signed report tag) starts counting now for sites that
 *    already run report-only/audit. A site that already ran Enforce keeps `enforce_since = 0`: no automatic
 *    rollback window applies to a policy that was switched on before the rollback existed.
 */
final class Upgrade {

	/** Autoloaded array: routine => unix time it ran. */
	public const MARKS_OPTION = 'uxstudio_upgrade_marks';

	/** Pre-0.6.0 custom login lock marker: array{configured:bool,time:int}. */
	public const LOGIN_LOCK_OPTION = 'uxstudio_login_lock_pre060';

	private const SECURITY_OPTION = 'uxstudio_security_optimization';

	/**
	 * Run every routine that has not run yet.
	 */
	public static function run(): void {
		if ( \UxStudio\Core\Migration\Preview::is_active() ) {
			return;
		}
		$marks = get_option( self::MARKS_OPTION, array() );
		$marks = is_array( $marks ) ? $marks : array();
		$dirty = false;
		foreach ( array( 'login_lock' => 'record_login_lock', 'csp_state' => 'record_csp_state', 'login_style' => 'pin_login_style' ) as $key => $method ) {
			if ( isset( $marks[ $key ] ) ) {
				continue;
			}
			self::$method();
			$marks[ $key ] = time();
			$dirty         = true;
		}
		if ( $dirty ) {
			update_option( self::MARKS_OPTION, $marks, true );
		}
	}

	/**
	 * Was the custom login address configured before this release?
	 */
	public static function record_login_lock(): void {
		$settings   = (array) get_option( self::SECURITY_OPTION, array() );
		$configured = ! empty( $settings['login_url_enabled'] ) && '' !== trim( (string) ( $settings['login_url'] ?? '' ), "/ \t\n\r" );
		if ( false === get_option( self::LOGIN_LOCK_OPTION, false ) ) {
			update_option(
				self::LOGIN_LOCK_OPTION,
				array(
					'configured' => $configured,
					'time'       => time(),
				),
				false
			);
		}
	}

	/**
	 * Was the custom login address configured before this release (see record_login_lock())?
	 */
	public static function login_lock_was_configured(): bool {
		$marker = get_option( self::LOGIN_LOCK_OPTION, false );
		return is_array( $marker ) && ! empty( $marker['configured'] );
	}

	/**
	 * The default login screen layout changed from classic to split (0.16). A site that already has the login screen
	 * customisation switched on but never saved a layout was showing the classic one: store that choice, so its login
	 * page does not change. Sites that never turned the customisation on, and every site that turns it on later, get
	 * the new default. A layout that is stored is never touched.
	 */
	public static function pin_login_style(): void {
		$settings = get_option( 'uxstudio_admin_customiser', false );
		if ( ! is_array( $settings ) || array_key_exists( 'login_style', $settings ) ) {
			return;
		}
		$on = $settings['login_customization_enabled'] ?? false;
		if ( true !== $on && 1 !== $on && '1' !== $on && 'true' !== $on ) {
			return;
		}
		$settings['login_style'] = 'classic';
		update_option( 'uxstudio_admin_customiser', $settings ); // The autoload flag of the option stays as it is.
	}

	/**
	 * Start the CSP mode bookkeeping for a site that already had a mode switched on in 0.5.0.
	 */
	public static function record_csp_state(): void {
		$settings = (array) get_option( self::SECURITY_OPTION, array() );
		$mode     = (string) ( $settings['csp_mode'] ?? 'off' );
		if ( 'off' === $mode || ! in_array( $mode, array( 'audit', 'report-only', 'enforce' ), true ) ) {
			return;
		}
		$state = (array) get_option( 'uxstudio_csp_state', array() );
		if ( (int) ( $state['since'] ?? 0 ) > 0 && (string) ( $state['mode'] ?? '' ) === $mode ) {
			return;
		}
		update_option(
			'uxstudio_csp_state',
			array(
				'mode'          => $mode,
				'since'         => time(),
				// A policy that was already enforced before this release has no rollback window.
				'enforce_since' => 0,
				'paused_until'  => (int) ( $state['paused_until'] ?? 0 ),
			),
			true
		);
	}
}
