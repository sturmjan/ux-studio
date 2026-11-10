<?php
/**
 * Google Consent Mode v2 defaults.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\Consent;

defined( 'ABSPATH' ) || exit;

/**
 * The inline script printed first in `<head>` (before any Google tag, whatever the mode): it sets every consent type
 * to denied, and then, for a returning visitor, to what the stored choice says, so the tags never see a wrong state.
 * The banner script sends the later `update` when the visitor decides.
 *
 * The text depends only on the settings (policy version, wait time), never on the visitor, so a page cache can keep it
 * and the CSP module can hash it (CspInlineScripts: the printing code and the hash use this one function).
 *
 * Choice cookie: `1.<policy version>.<bits>.<unix time>`, bits = 1 preferences, 2 statistics, 4 marketing.
 * Mapping: preferences => functionality_storage + personalization_storage; statistics => analytics_storage;
 * marketing => ad_storage + ad_user_data + ad_personalization; security_storage is always granted.
 */
final class ConsentMode {

	/** Name of the choice cookie (first party, set by the banner script). */
	public const COOKIE = 'uxs_consent';

	/**
	 * @param int $policy_version Version of the consent policy (a stored choice for another version is ignored).
	 * @param int $wait_ms        Milliseconds Google tags wait for an update.
	 */
	public static function body( int $policy_version, int $wait_ms ): string {
		$wait_ms = max( 0, min( 2000, $wait_ms ) );
		$js      = "window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments)}"
			. "gtag('consent','default',{ad_storage:'denied',ad_user_data:'denied',ad_personalization:'denied',analytics_storage:'denied',functionality_storage:'denied',personalization_storage:'denied',security_storage:'granted',wait_for_update:" . $wait_ms . "});"
			. "(function(){var m=/(?:^|; )" . self::COOKIE . "=([^;]*)/.exec(document.cookie);if(!m)return;var p=decodeURIComponent(m[1]).split('.');if(p[0]!=='1'||+p[1]!==" . $policy_version . ")return;"
			. "var b=+p[2]||0;function g(f){return b&f?'granted':'denied'}"
			. "gtag('consent','update',{functionality_storage:g(1),personalization_storage:g(1),analytics_storage:g(2),ad_storage:g(4),ad_user_data:g(4),ad_personalization:g(4)})})();";
		return $js;
	}
}
