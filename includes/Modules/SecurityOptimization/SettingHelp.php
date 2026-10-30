<?php
/**
 * Plain-language explanation of the Security settings (what it does, what can go wrong, when to switch it off).
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\SecurityOptimization;

defined( 'ABSPATH' ) || exit;

/**
 * ONE place for the wording, used by the Security settings screen (the "Details" under an option) and by the
 * onboarding wizard (the list "What will be set"), so the two cannot drift apart.
 *
 * Every entry has the same parts: `does`, `risk`, `off` and, for the settings of the starter pack, `skip` (the
 * condition under which the pack leaves the setting out).
 */
final class SettingHelp {

	/**
	 * Settings of the starter pack that are only numbers or modes of another setting, so they have no text of their own.
	 */
	public const PARAMETERS = array(
		'revisions_count',
		'max_attempts',
		'lockout_time',
		'lockout_progressive',
		'ip_firewall_proxy',
		'disable_comments_scope',
		'disable_comments_post_types',
		'captcha_placement',
		'captcha_forms',
	);

	/**
	 * Keys that have an explanation.
	 *
	 * @return string[]
	 */
	public static function keys(): array {
		return array_keys( self::all() );
	}

	/**
	 * The explanation of one setting, or null.
	 *
	 * @param string $key       Setting key.
	 * @param bool   $with_skip Include the starter pack's skip condition.
	 * @return array{does:string,risk:string,off:string,skip?:string}|null
	 */
	public static function for_key( string $key, bool $with_skip = false ): ?array {
		$all = self::all();
		if ( ! isset( $all[ $key ] ) ) {
			return null;
		}
		$entry = $all[ $key ];
		if ( ! $with_skip ) {
			unset( $entry['skip'] );
		}
		return $entry;
	}

	/**
	 * All explanations (translated at the time of the call).
	 *
	 * @return array<string, array{does:string,risk:string,off:string,skip?:string}>
	 */
	public static function all(): array {
		return require __DIR__ . '/help.php';
	}
}
