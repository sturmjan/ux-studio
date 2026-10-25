<?php
/**
 * Silences the "success" e-mails of automatic updates, while the updates themselves keep running.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\SecurityOptimization;

defined( 'ABSPATH' ) || exit;

/**
 * WordPress mails the site admin after every automatic update. On a managed site that is noise that hides the
 * messages that matter, so with `suppress_update_emails` on:
 *
 *  - plugin and theme update e-mails are never sent,
 *  - the core e-mail is dropped only for the type `success`; `fail`, `critical` and `manual` still arrive,
 *    because those need a human.
 *
 * Nothing about WHETHER updates run is touched (that is the Disable auto-updates module's job).
 */
final class UpdateEmails {

	/** Core e-mail types that carry no action for a human. */
	private const QUIET_CORE_TYPES = array( 'success' );

	/**
	 * Wire the filters.
	 */
	public static function register(): void {
		add_filter( 'auto_plugin_update_send_email', array( self::class, 'filter_plugin_theme' ) );
		add_filter( 'auto_theme_update_send_email', array( self::class, 'filter_plugin_theme' ) );
		add_filter( 'auto_core_update_send_email', array( self::class, 'filter_core' ), 10, 2 );
	}

	/**
	 * Plugin / theme update e-mail: never.
	 *
	 * @param mixed $send Whether WordPress wants to send it.
	 */
	public static function filter_plugin_theme( $send ): bool {
		return self::decide_plugin_theme( (bool) $send );
	}

	/**
	 * Core update e-mail: only the success one is dropped.
	 *
	 * @param mixed  $send Whether WordPress wants to send it.
	 * @param string $type `success`, `fail`, `manual` or `critical`.
	 */
	public static function filter_core( $send, $type = '' ): bool {
		return self::decide_core( (bool) $send, (string) $type );
	}

	/**
	 * Pure decision for plugins and themes.
	 *
	 * @param bool $send What WordPress wanted.
	 */
	public static function decide_plugin_theme( bool $send ): bool {
		unset( $send );
		return false;
	}

	/**
	 * Pure decision for core.
	 *
	 * @param bool   $send What WordPress wanted.
	 * @param string $type E-mail type.
	 */
	public static function decide_core( bool $send, string $type ): bool {
		return in_array( $type, self::QUIET_CORE_TYPES, true ) ? false : $send;
	}
}
