<?php
/**
 * Language per user: REST requests of the plugin are answered in the language of the logged-in user.
 *
 * @package UxStudio
 */

namespace UxStudio\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Pages of wp-admin already use the language of the user (WordPress asks for it), but REST requests are answered in
 * the language of the SITE: an English administrator on a Czech site saw module names, setting labels and help
 * texts half in Czech. This class makes every request of `uxstudio/v1` run in the locale of the current user:
 *
 *   rest_pre_dispatch   switch_to_user_locale()  (WordPress reloads the translations of every loaded text domain,
 *                       so `ux-studio` and the module names from meta.json follow; our own check below makes
 *                       sure of it for the plugin's own text domain)
 *   rest_post_dispatch  restore_previous_locale()
 *
 * The two calls are paired by a stack, so batch requests and requests made from PHP (rest_do_request) stay
 * balanced. Requests of other namespaces, anonymous requests and users whose locale equals the current one are
 * left alone.
 */
final class Locale {

	public const NAMESPACE_PREFIX = '/uxstudio/v1';

	/**
	 * One entry per dispatched request: true when this class switched the locale for it.
	 *
	 * @var bool[]
	 */
	private static array $stack = array();

	/**
	 * Hook the filters (called once from Plugin::boot()).
	 */
	public static function register(): void {
		add_filter( 'rest_pre_dispatch', array( self::class, 'on_pre_dispatch' ), 1, 3 );
		add_filter( 'rest_post_dispatch', array( self::class, 'on_post_dispatch' ), 9999, 3 );
	}

	/**
	 * Switch to the locale of the user before a request of our namespace is handled.
	 *
	 * @param mixed            $result  Pre-dispatch result (passed through untouched).
	 * @param \WP_REST_Server  $server  Server.
	 * @param \WP_REST_Request $request Request.
	 * @return mixed
	 */
	public static function on_pre_dispatch( $result, $server, $request ) {
		$switched = false;
		if ( $request instanceof \WP_REST_Request && 0 === strpos( $request->get_route(), self::NAMESPACE_PREFIX ) ) {
			$switched = self::switch_for_current_user();
		}
		self::$stack[] = $switched;
		return $result;
	}

	/**
	 * Undo the switch once the response is ready.
	 *
	 * @param mixed $response Response (passed through untouched).
	 * @return mixed
	 */
	public static function on_post_dispatch( $response ) {
		if ( array() !== self::$stack && array_pop( self::$stack ) ) {
			restore_previous_locale();
			self::ensure_plugin_textdomain();
		}
		return $response;
	}

	/**
	 * Switch to the locale of the current user when it differs from the active one.
	 *
	 * @return bool Whether the locale was switched (and has to be restored).
	 */
	public static function switch_for_current_user(): bool {
		$user_id = get_current_user_id();
		if ( $user_id <= 0 ) {
			return false;
		}
		$target = get_user_locale( $user_id );
		if ( '' === $target || $target === get_locale() ) {
			return false;
		}
		if ( ! switch_to_user_locale( $user_id ) ) {
			return false;
		}
		self::ensure_plugin_textdomain();
		return true;
	}

	/**
	 * After a switch the plugin's text domain must be the one of the new locale. WordPress reloads loaded domains
	 * from the path it knows; when that did not find the plugin's own .mo (loaded from a custom path), load it here.
	 */
	private static function ensure_plugin_textdomain(): void {
		$locale = get_locale();
		$mo     = UXSTUDIO_PATH . 'languages/ux-studio-' . $locale . '.mo';
		if ( ! is_file( $mo ) ) {
			// No translation for this locale (English): drop what the previous locale left behind.
			unload_textdomain( 'ux-studio', true );
			return;
		}
		global $l10n;
		$loaded = isset( $l10n['ux-studio'] ) && $l10n['ux-studio'] instanceof \Translations && 'NOOP_Translations' !== get_class( $l10n['ux-studio'] );
		if ( ! $loaded ) {
			unload_textdomain( 'ux-studio', true );
			load_textdomain( 'ux-studio', $mo, $locale );
		}
	}
}
