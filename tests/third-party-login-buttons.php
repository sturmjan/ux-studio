<?php
// Test only: never runs over HTTP (the release zip also excludes tests/).
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { exit; }
/**
 * Standalone test of the Third-Party Login buttons on the login screen: markup, escaping, logos, styles.
 *
 *   php tests/third-party-login-buttons.php
 *
 * @package UxStudio
 */

// phpcs:disable

define( 'ABSPATH', __DIR__ . '/' );
eval( 'namespace UxStudio\Modules { abstract class BaseModule {} } namespace { class WP_User {} }' );

function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' ); }
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' ); }
function esc_url( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' ); }
function sanitize_key( $s ) { return strtolower( preg_replace( '/[^a-zA-Z0-9_\-]/', '', (string) $s ) ); }

require_once __DIR__ . '/../includes/Modules/ThirdPartyLogin/Module.php';

use UxStudio\Modules\ThirdPartyLogin\Module;

$fail = 0;
function check( $label, $ok ) { global $fail; echo ( $ok ? 'ok   ' : 'FAIL ' ) . $label . "\n"; if ( ! $ok ) { ++$fail; } }

$html = Module::button_html( 'google', 'Google', 'Sign in with Google', 'https://x.test/?uxstudio_tpl=login&provider=google' );
check( 'a link with the provider class', false !== strpos( $html, '<a class="uxstudio-tpl__btn uxstudio-tpl__btn--google"' ) );
check( 'the URL is escaped (& becomes &amp;)', false !== strpos( $html, 'href="https://x.test/?uxstudio_tpl=login&amp;provider=google"' ) );
check( 'the visible text is only the provider name', false !== strpos( $html, '<span>Google</span>' ) );
check( 'the full sentence is the accessible name', false !== strpos( $html, 'aria-label="Sign in with Google"' ) );
check( 'the logo is inline SVG and hidden from screen readers', false !== strpos( $html, '<svg class="uxstudio-tpl__icon"' ) && false !== strpos( $html, 'aria-hidden="true"' ) );
check( 'no inline style attribute (a strict CSP can block it)', false === strpos( $html, 'style=' ) );

$evil = Module::button_html( 'goo"><script>', '<img src=x onerror=alert(1)>', '"><script>alert(1)</script>', 'javascript:alert(1)' );
check( 'a hostile label cannot add markup', false === strpos( $evil, '<img' ) && false === strpos( $evil, '<script' ) );
check( 'a hostile provider id cannot leave the class attribute', false !== strpos( $evil, 'uxstudio-tpl__btn--gooscript"' ) );

foreach ( Module::PROVIDERS as $provider ) {
	$icon = Module::provider_icon( $provider );
	check( "$provider has a logo", '' !== $icon && 0 === strpos( $icon, '<svg' ) && '</svg>' === substr( $icon, -6 ) );
}
check( 'an unknown provider has no logo (no fatal)', '' === Module::provider_icon( 'myspace' ) );

// The Google logo must be the one UX1 Network app ships, not a hand-typed copy.
$ca = 'D:/xampp/htdocs/centrani-app/public/tpl/images/svgs/google-icon.svg';
if ( is_readable( $ca ) ) {
	preg_match_all( '/ d="([^"]+)"/', (string) file_get_contents( $ca ), $m );
	$same = ! empty( $m[1] );
	foreach ( $m[1] as $d ) {
		$same = $same && false !== strpos( Module::provider_icon( 'google' ), $d );
	}
	check( 'the Google logo has exactly the paths of the UX1 Network app one', $same );
}

$css = Module::button_css();
check( 'two columns, 44 px high, 10 px radius (as the Split login inputs)', false !== strpos( $css, 'repeat(2,minmax(0,1fr))' ) && false !== strpos( $css, 'height:44px' ) && false !== strpos( $css, 'border-radius:10px' ) );
check( 'styles are scoped to the login screen', 0 === strpos( $css, '.login ' ) );
check( 'braces balance', substr_count( $css, '{' ) === substr_count( $css, '}' ) );

exit( $fail > 0 ? 1 : 0 );
