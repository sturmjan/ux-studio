<?php
// Test only: never runs over HTTP (the release zip also excludes tests/).
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { exit; }
/**
 * Standalone test (no WordPress) of the Cookie Consent module logic (PLAN.md 42, F1): URL classification, rewriting of
 * script and iframe tags, the Consent Mode defaults script, the region check and the catalog entry.
 *
 *   php tests/consent.php
 *
 * @package UxStudio
 */

// phpcs:disable

define( 'ABSPATH', __DIR__ . '/' );
$GLOBALS['uxs_filters'] = array();
function apply_filters( $hook, $value ) {
	foreach ( $GLOBALS['uxs_filters'][ $hook ] ?? array() as $fn ) {
		$value = $fn( $value );
	}
	return $value;
}
function __( $text, $domain = '' ) { return $text; }

$root = dirname( __DIR__ );
$dir  = $root . '/includes/Modules/';
require_once $dir . 'SecurityOptimization/CspServices.php';
require_once $dir . 'Consent/Services.php';
require_once $dir . 'Consent/Blocker.php';
require_once $dir . 'Consent/ConsentMode.php';
// Geo and Gate need classes of the plugin (REST controller, Module); only the pure parts are used here.
if ( ! class_exists( 'UxStudio\Rest\Controller' ) ) {
	eval( 'namespace UxStudio\Rest; abstract class Controller { public const NS = "uxstudio/v1"; }' );
	eval( 'class WP_REST_Request {} class WP_REST_Response {}' );
}
require_once $dir . 'Consent/Geo.php';

use UxStudio\Modules\Consent\Blocker;
use UxStudio\Modules\Consent\ConsentMode;
use UxStudio\Modules\Consent\Geo;
use UxStudio\Modules\Consent\Services;

$failures = 0;
$assert   = static function ( string $name, bool $ok, string $detail = '' ) use ( &$failures ): void {
	if ( ! $ok ) {
		++$failures;
	}
	echo ( $ok ? 'PASS ' : 'FAIL ' ) . $name . ( $ok || '' === $detail ? '' : ' => ' . $detail ) . "\n";
};
$cat = static fn( ?array $r ): string => null === $r ? 'none' : $r['category'];

// --- classify ---
$site = 'example.cz';
$c    = static fn( string $url, array $rules = array() ): ?array => Services::classify( $url, $site, $rules );

$assert( 'gtag.js with a GA4 id is statistics', 'statistics' === $cat( $c( 'https://www.googletagmanager.com/gtag/js?id=G-ABC123' ) ) );
$assert( 'gtag.js with an Ads id is marketing', 'marketing' === $cat( $c( 'https://www.googletagmanager.com/gtag/js?id=AW-123456' ) ) );
$assert( 'gtag.js with &#038; encoded query still reads the id', 'statistics' === $cat( $c( 'https://www.googletagmanager.com/gtag/js?l=dl&#038;id=G-ABC123' ) ) );
$assert( 'gtm.js container is "statistics marketing" (any of)', 'statistics marketing' === $cat( $c( 'https://www.googletagmanager.com/gtm.js?id=GTM-ABCD' ) ) );
$assert( 'Google tags are flagged as reading Consent Mode', true === $c( 'https://www.googletagmanager.com/gtm.js?id=GTM-ABCD' )['gcm'] );
$assert( 'Facebook pixel is marketing and not gcm', 'marketing' === $cat( $c( 'https://connect.facebook.net/en_US/fbevents.js' ) ) && false === $c( 'https://connect.facebook.net/en_US/fbevents.js' )['gcm'] );
$assert( 'Hotjar subdomain is statistics', 'statistics' === $cat( $c( 'https://static.hotjar.com/c/hotjar-1.js' ) ) );
$assert( 'chat widget is preferences', 'preferences' === $cat( $c( 'https://www.smartsuppchat.com/loader.js' ) ) );
$assert( 'protocol-relative URL is classified', 'marketing' === $cat( $c( '//platform.twitter.com/widgets.js' ) ) );
$assert( 'longest key wins (maps.googleapis.com is marketing, fonts.googleapis.com is not in the list)', 'marketing' === $cat( $c( 'https://maps.googleapis.com/maps/api/js?key=x' ) ) && 'none' === $cat( $c( 'https://fonts.googleapis.com/css?family=Roboto' ) ) );
$assert( 'google.com/maps embed is marketing', 'marketing' === $cat( $c( 'https://www.google.com/maps/embed?pb=!1m18' ) ) );
$assert( 'google.com reCAPTCHA is necessary (not blocked)', 'none' === $cat( $c( 'https://www.google.com/recaptcha/api.js' ) ) );
$assert( 'YouTube embed is marketing', 'marketing' === $cat( $c( 'https://www.youtube.com/embed/abc' ) ) );
$assert( 'first party is never classified', 'none' === $cat( $c( 'https://example.cz/wp-content/x.js' ) ) && 'none' === $cat( $c( 'https://cdn.example.cz/x.js' ) ) );
$assert( 'relative URL is first party', 'none' === $cat( $c( '/wp-includes/js/jquery/jquery.min.js' ) ) );
$assert( 'look-alike host is not matched', 'none' === $cat( $c( 'https://evilgoogletagmanager.com/gtm.js' ) ) && 'none' === $cat( $c( 'https://googletagmanager.com.evil.example/gtm.js' ) ) );
$assert( 'payment and CDN hosts are not blocked', 'none' === $cat( $c( 'https://js.stripe.com/v3/' ) ) && 'none' === $cat( $c( 'https://cdn.jsdelivr.net/npm/x.js' ) ) );

// --- own rules ---
$rules = Services::parse_rules( "# comment\nstatic.example-stats.com statistics\n/wp-content/plugins/tracker/ marketing\ncdn.hotjar.com necessary\nbroken\nfoo.com nonsense\nBAD HOST!! marketing\n" );
$assert( 'parse_rules keeps the three good lines, drops the rest', 3 === count( $rules ), (string) count( $rules ) );
$assert( 'rule by host (and subdomain)', 'statistics' === $cat( $c( 'https://a.static.example-stats.com/x.js', $rules ) ) );
$assert( 'rule by path names a first-party script', 'marketing' === $cat( $c( 'https://example.cz/wp-content/plugins/tracker/t.js', $rules ) ) );
$assert( 'rule "necessary" exempts a catalogue host', 'none' === $cat( $c( 'https://cdn.hotjar.com/x.js', $rules ) ) );
$assert( 'rules win over the catalogue', 'marketing' === $cat( $c( 'https://static.hotjar.com/a.js', Services::parse_rules( 'hotjar.com marketing' ) ) ) );

// --- script_loader_tag rewriting ---
Blocker::configure( array( 'site_host' => $site, 'rules' => array(), 'gcm_loads_early' => false, 'nocookie' => true ) );
$tag = "<script id=\"wp-i18n-js\">/* t */</script>\n<script src=\"https://www.googletagmanager.com/gtag/js?id=G-X\" id=\"ga-js\" async></script>\n<script id=\"ga-js-after\">gtag('config','G-X')</script>\n";
$out = Blocker::filter_script_tag( $tag, 'https://www.googletagmanager.com/gtag/js?id=G-X' );
$assert( 'every script of a registered handle is held back', 3 === substr_count( $out, 'type="text/plain" data-uxc="statistics"' ), $out );
$assert( 'src is kept (the banner script re-creates the tag)', false !== strpos( $out, 'src="https://www.googletagmanager.com/gtag/js?id=G-X"' ) && false !== strpos( $out, ' async' ) );
$assert( 'first-party script is left alone', Blocker::filter_script_tag( '<script src="https://example.cz/a.js"></script>', 'https://example.cz/a.js' ) === '<script src="https://example.cz/a.js"></script>' );
$mod = Blocker::filter_script_tag( '<script type="module" src="https://static.hotjar.com/m.js"></script>', 'https://static.hotjar.com/m.js' );
$assert( 'module type is remembered', false !== strpos( $mod, 'data-uxc-type="module"' ) && false !== strpos( $mod, 'type="text/plain"' ) && false === strpos( $mod, ' type="module"' ), $mod );
$json = '<script type="application/ld+json">{"a":1}</script>';
$assert( 'JSON data blocks are not touched', Blocker::gate_scripts( $json, 'marketing' ) === $json );
$twice = Blocker::gate_scripts( $out, 'marketing' );
$assert( 'an already gated script is not gated twice', $twice === $out );
$assert( 'script opted out with data-no-consent is left alone', Blocker::gate_scripts( '<script data-no-consent src="x"></script>', 'marketing' ) === '<script data-no-consent src="x"></script>' );

Blocker::configure( array( 'gcm_loads_early' => true ) );
$assert( 'advanced mode: a Google tag loads at once', Blocker::filter_script_tag( $tag, 'https://www.googletagmanager.com/gtag/js?id=G-X' ) === $tag );
$assert( 'advanced mode: Facebook is still held back', false !== strpos( Blocker::filter_script_tag( '<script src="https://connect.facebook.net/x.js"></script>', 'https://connect.facebook.net/x.js' ), 'data-uxc="marketing"' ) );
Blocker::configure( array( 'gcm_loads_early' => false ) );

// --- whole-page safety net ---
$page = '<html><head><script id="uxc-consent-default">gtag("consent","default",{})</script>'
	. '<script>fbq(\'init\', \'12345\'); fbq(\'track\',\'PageView\');</script>'
	. '<script>!function(){console.log("mine")}()</script>'
	. '<script src="https://static.hotjar.com/c/hotjar-1.js"></script>'
	. '<script type="application/json" id="uxc-config">{}</script>'
	. '</head><body><iframe width="560" height="315" src="https://www.youtube.com/embed/abc?rel=0&amp;x=1" title="v"></iframe>'
	. '<iframe src=\'https://www.google.com/maps/embed?pb=1\'></iframe><iframe src="https://example.cz/form"></iframe>'
	. str_repeat( '<p>filler</p>', 20 ) . '</body></html>';
$out = Blocker::filter_page( $page );
$assert( 'page: inline Facebook snippet is held back', false !== strpos( $out, '<script type="text/plain" data-uxc="marketing">fbq(' ) );
$assert( 'page: own inline code is untouched', false !== strpos( $out, '<script>!function(){console.log("mine")}()</script>' ) );
$assert( 'page: external Hotjar is held back as statistics', false !== strpos( $out, 'data-uxc="statistics" src="https://static.hotjar.com' ) );
$assert( 'page: the banner scripts are exempt', false !== strpos( $out, '<script id="uxc-consent-default">' ) && false !== strpos( $out, '<script type="application/json" id="uxc-config">' ) );
$assert( 'page: YouTube iframe loses src and uses the nocookie host', false !== strpos( $out, 'data-uxc-src="https://www.youtube-nocookie.com/embed/abc?rel=0&amp;x=1"' ) && false === strpos( $out, ' src="https://www.youtube' ), $out );
$assert( 'page: iframe keeps its size and title', false !== strpos( $out, 'width="560" height="315"' ) && false !== strpos( $out, 'title="v"' ) );
$assert( 'page: single-quoted maps iframe is held back too', false !== strpos( $out, 'data-uxc-service="Google Maps"' ) );
$assert( 'page: first-party iframe is untouched', false !== strpos( $out, '<iframe src="https://example.cz/form">' ) );
$assert( 'page: services met are collected for the banner', in_array( 'Facebook', Blocker::seen()['marketing'] ?? array(), true ) && in_array( 'Hotjar', Blocker::seen()['statistics'] ?? array(), true ) );
$assert( 'page: running it twice changes nothing more', Blocker::filter_page( $out ) === $out );
$assert( 'tiny or empty input is returned as is', '' === Blocker::filter_page( '' ) && 'x' === Blocker::filter_page( 'x' ) );
Blocker::configure( array( 'nocookie' => false ) );
$assert( 'nocookie off keeps the YouTube host', false !== strpos( Blocker::gate_iframes( '<iframe src="https://www.youtube.com/embed/a"></iframe>' ), 'data-uxc-src="https://www.youtube.com/embed/a"' ) );

// --- Consent Mode defaults script ---
$body = ConsentMode::body( 3, 500 );
$assert( 'defaults deny everything except security_storage', false !== strpos( $body, "ad_storage:'denied'" ) && false !== strpos( $body, "analytics_storage:'denied'" ) && false !== strpos( $body, "security_storage:'granted'" ) );
$assert( 'defaults carry the policy version and wait time', false !== strpos( $body, '+p[1]!==3' ) && false !== strpos( $body, 'wait_for_update:500' ) );
$assert( 'wait time is clamped', false !== strpos( ConsentMode::body( 1, 999999 ), 'wait_for_update:2000' ) && false !== strpos( ConsentMode::body( 1, -5 ), 'wait_for_update:0' ) );
$assert( 'body never contains a closing script tag', false === stripos( $body, '</script' ) );

// The script has to be valid JavaScript and behave: run it in Node with a stub document when Node is there.
$node = trim( (string) @shell_exec( 'node --version 2>&1' ) );
if ( 0 === strpos( $node, 'v' ) ) {
	$tmp = __DIR__ . '/.consent-body-test.js';
	$run = static function ( string $cookie ) use ( $body, $tmp ): array {
		$harness = "document={cookie:" . json_encode( $cookie ) . "};window=global;\n"
			. $body . "\nconsole.log(JSON.stringify(window.dataLayer.map(function(a){return Array.prototype.slice.call(a)})));";
		file_put_contents( $tmp, $harness );
		$out = (string) shell_exec( 'node ' . escapeshellarg( $tmp ) . ' 2>&1' );
		return (array) json_decode( trim( $out ), true );
	};
	$none = $run( '' );
	$assert( 'defaults: no cookie => only the default call', 1 === count( $none ) && 'default' === $none[0][1] && 'denied' === $none[0][2]['ad_storage'], json_encode( $none ) );
	$ok = $run( 'a=b; uxs_consent=1.3.6.1731000000; c=d' );
	$assert( 'defaults: stored choice 6 (statistics + marketing) => update', 2 === count( $ok ) && 'granted' === $ok[1][2]['analytics_storage'] && 'granted' === $ok[1][2]['ad_storage'] && 'denied' === $ok[1][2]['functionality_storage'], json_encode( $ok ) );
	$old = $run( 'uxs_consent=1.2.7.1731000000' );
	$assert( 'defaults: a choice for another policy version is ignored', 1 === count( $old ), json_encode( $old ) );
	$junk = $run( 'uxs_consent=garbage' );
	$assert( 'defaults: a broken cookie is ignored', 1 === count( $junk ), json_encode( $junk ) );
	@unlink( $tmp );
} else {
	echo "SKIP Node not available, Consent Mode script not executed\n";
}

// --- region check ---
$assert( 'EU country needs the banner', Geo::needs_banner( 'CZ' ) && Geo::needs_banner( 'de' ) && Geo::needs_banner( 'GB' ) && Geo::needs_banner( 'CH' ) && Geo::needs_banner( 'NO' ) );
$assert( 'US and JP do not', ! Geo::needs_banner( 'US' ) && ! Geo::needs_banner( 'JP' ) );
$assert( 'missing, malformed or unknown country => show the banner', Geo::needs_banner( '' ) && Geo::needs_banner( 'XX' ) && Geo::needs_banner( 'T1' ) && Geo::needs_banner( 'CZE' ) && Geo::needs_banner( '<s>' ) );
$GLOBALS['uxs_filters']['uxstudio_consent_regions'][] = static fn( $l ) => array( 'US' );
$assert( 'filter can change the regions', Geo::needs_banner( 'US' ) && ! Geo::needs_banner( 'CZ' ) );

// --- module wiring ---
$meta = json_decode( (string) file_get_contents( $dir . 'Consent/meta.json' ), true );
$assert( 'meta.json is valid', is_array( $meta ) && 'consent' === $meta['id'] && ! empty( $meta['name'] ) );
$catalog = (string) file_get_contents( $root . '/includes/Core/Migration/Catalog.php' );
$assert( 'Complianz, Cookie Notice, CookieYes and Cookiebot are full conflicts of consent', 1 === preg_match( "/full\(\s*'consent',\s*array\([^)]*complianz-gdpr[^)]*cookie-notice[^)]*cookie-law-info[^)]*cookiebot/s", $catalog ) );
$assets = array( 'consent.js', 'consent.min.js', 'consent.css', 'consent.min.css' );
foreach ( $assets as $a ) {
	$assert( "asset $a exists", is_file( $root . '/assets/consent/' . $a ) );
}
$assert( 'no em or en dash in the module sources', 0 === preg_match( '/[\x{2013}\x{2014}]/u', implode( '', array_map( 'file_get_contents', glob( $dir . 'Consent/*.php' ) ) ) . file_get_contents( $root . '/assets/consent/consent.js' ) ) );

if ( $failures ) { echo "$failures failure(s)\n"; exit( 1 ); }
echo "ALL PASSED\n";
