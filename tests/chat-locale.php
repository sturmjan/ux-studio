<?php
// Test only: never runs over HTTP (the release zip also excludes tests/).
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { exit; }
/**
 * Standalone test of the chat language of the visitor widget (AiAssistant\ChatLocale + the widget dictionary).
 *
 *   php tests/chat-locale.php
 *
 * Needs no WordPress and no database: small doubles of the gettext filters; the .mo catalog is read with the
 * pomo classes of WordPress (set UXS_WP_PATH to a WordPress folder, default: the local pobyty site). The case is
 * skipped (exit 0) when the classes cannot be found. Exits non-zero when a case fails.
 *
 * @package UxStudio
 */

// phpcs:disable

$wp = getenv( 'UXS_WP_PATH' ) ?: 'D:/xampp/htdocs/pobyty';
if ( ! is_file( $wp . '/wp-includes/pomo/mo.php' ) ) {
	echo "SKIP: pomo classes not found in {$wp}\n";
	exit( 0 );
}

define( 'ABSPATH', rtrim( $wp, '/' ) . '/' );
define( 'WPINC', 'wp-includes' );
define( 'WP_LANG_DIR', sys_get_temp_dir() . '/uxs-no-such-lang-dir' );
define( 'UXSTUDIO_PATH', dirname( __DIR__ ) . '/' );

// WordPress polyfills these in wp-includes/compat.php; pomo needs it.
if ( ! function_exists( 'array_last' ) ) { function array_last( array $a ) { return $a ? $a[ array_key_last( $a ) ] : null; } }

$GLOBALS['uxs_filters'] = array();
function get_locale() { return $GLOBALS['uxs_site_locale'] ?? 'en_US'; }
function add_filter( $tag, $cb, $prio = 10, $args = 1 ) { $GLOBALS['uxs_filters'][ $tag ][ $prio ][ md5( serialize( $cb ) ) ] = $cb; return true; }
function remove_filter( $tag, $cb, $prio = 10 ) { unset( $GLOBALS['uxs_filters'][ $tag ][ $prio ][ md5( serialize( $cb ) ) ] ); return true; }
function uxs_apply( $tag, $value, ...$args ) {
	$by = $GLOBALS['uxs_filters'][ $tag ] ?? array();
	ksort( $by );
	foreach ( $by as $cbs ) {
		foreach ( $cbs as $cb ) {
			$value = $cb( $value, ...$args );
		}
	}
	return $value;
}
// The "request locale" is pretend German: every text gets a prefix, so the test sees whether the chat filter overrides it.
function __( $text, $domain = 'default' ) { return uxs_apply( 'gettext', 'DE:' . $text, $text, $domain ); }
function _x( $text, $context, $domain = 'default' ) { return uxs_apply( 'gettext_with_context', 'DE:' . $text, $text, $context, $domain ); }

require_once __DIR__ . '/../includes/Modules/AiAssistant/ChatLocale.php';

use UxStudio\Modules\AiAssistant\ChatLocale;

$fail = 0;
function check( $label, $ok ) { global $fail; echo ( $ok ? 'ok   ' : 'FAIL ' ) . $label . "\n"; if ( ! $ok ) { ++$fail; } }

check( 'outside run() the request locale wins', __( 'Cancel', 'ux-studio' ) === 'DE:Cancel' );
check( 'cs: translated from the plugin .mo', ChatLocale::run( 'cs', static fn() => __( 'Cancel', 'ux-studio' ) ) === 'Zrušit' );
check( 'en: the English source text, not the request locale', ChatLocale::run( 'en', static fn() => __( 'Cancel', 'ux-studio' ) ) === 'Cancel' );
check( 'cs: text without translation stays English', ChatLocale::run( 'cs', static fn() => __( 'No such text anywhere', 'ux-studio' ) ) === 'No such text anywhere' );
check( 'other text domains are left alone', ChatLocale::run( 'cs', static fn() => __( 'Cancel', 'other' ) ) === 'DE:Cancel' );
$GLOBALS['uxs_site_locale'] = 'cs_CZ';
check( 'auto on a Czech site is cs', ChatLocale::normalize( 'auto' ) === 'cs' );
$GLOBALS['uxs_site_locale'] = 'de_DE';
check( 'auto on any other site is en', ChatLocale::normalize( 'auto' ) === 'en' );
check( 'auto runs the callback in the site language', ChatLocale::run( 'auto', static fn() => __( 'Cancel', 'ux-studio' ) ) === 'Cancel' );

check( 'unknown language falls back to cs', ChatLocale::run( 'xx', static fn() => __( 'Cancel', 'ux-studio' ) ) === 'Zrušit' );
check( 'normalize()', ChatLocale::normalize( 'en' ) === 'en' && ChatLocale::normalize( 'xx' ) === 'cs' && ChatLocale::normalize( null ) === 'cs' );
check( 'filters are gone after run()', __( 'Cancel', 'ux-studio' ) === 'DE:Cancel' );

$nested = ChatLocale::run(
	'cs',
	static function () {
		$inner = ChatLocale::run( 'en', static fn() => __( 'Cancel', 'ux-studio' ) );
		return $inner . '/' . __( 'Cancel', 'ux-studio' );
	}
);
check( 'nested run() restores the outer language', 'Cancel/Zrušit' === $nested );

try {
	ChatLocale::run( 'cs', static function () { throw new RuntimeException( 'boom' ); } );
} catch ( RuntimeException $e ) {
}
check( 'an exception still removes the filters', __( 'Cancel', 'ux-studio' ) === 'DE:Cancel' );

// REST callbacks of the public chat routes run in the chat language (ChatLocale::wrap).
$cb_cs = ChatLocale::wrap( static fn( $x ) => $x . ':' . __( 'Cancel', 'ux-studio' ), static fn() => 'cs' );
$cb_en = ChatLocale::wrap( static fn( $x ) => $x . ':' . __( 'Cancel', 'ux-studio' ), static fn() => 'en' );
check( 'wrap(): cs route answers in Czech and passes its arguments', 'req:Zrušit' === $cb_cs( 'req' ) );
check( 'wrap(): en route answers in English', 'req:Cancel' === $cb_en( 'req' ) );
check( 'wrap(): the request locale is back afterwards', __( 'Cancel', 'ux-studio' ) === 'DE:Cancel' );

exit( $fail > 0 ? 1 : 0 );
