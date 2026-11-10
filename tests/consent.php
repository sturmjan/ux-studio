<?php
// Test only: never runs over HTTP (the release zip also excludes tests/).
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { exit; }
/**
 * Standalone test (no WordPress) of the Cookie Consent module logic (PLAN.md 42, F1): URL classification, rewriting of
 * script and iframe tags, the Consent Mode defaults script, the region check and the catalog entry; F2: the cookie
 * catalogue, pattern matching, the Open Cookie Database, the static inventory and its queue.
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
require_once $dir . 'Consent/OpenCookieDb.php';
require_once $dir . 'Consent/Cookies.php';
require_once $dir . 'Consent/Inventory.php';
if ( ! class_exists( 'WP_Error' ) ) { eval( 'class WP_Error {}' ); }
// Geo and Gate need classes of the plugin (REST controller, Module); only the pure parts are used here.
if ( ! class_exists( 'UxStudio\Rest\Controller' ) ) {
	eval( 'namespace UxStudio\Rest; abstract class Controller { public const NS = "uxstudio/v1"; }' );
	eval( 'class WP_REST_Request {} class WP_REST_Response {}' );
}
require_once $dir . 'Consent/Geo.php';
require_once $dir . 'Consent/Templates.php';

use UxStudio\Modules\Consent\Blocker;
use UxStudio\Modules\Consent\ConsentMode;
use UxStudio\Modules\Consent\Cookies;
use UxStudio\Modules\Consent\Geo;
use UxStudio\Modules\Consent\Inventory;
use UxStudio\Modules\Consent\OpenCookieDb;
use UxStudio\Modules\SecurityOptimization\CspServices;
use UxStudio\Modules\Consent\Services;
use UxStudio\Modules\Consent\Templates;

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

// --- F2: cookie catalogue (one shared catalogue, CspServices::consent_services()) ---
$services = CspServices::consent_services();
$labels   = array_unique( array_map( static fn( array $e ): string => $e['label'], CspServices::consent_catalogue() ) );
$missing  = array_values( array_filter( $labels, static fn( string $l ): bool => ! isset( $services[ $l ] ) ) );
$assert( 'every service of the consent host catalogue has an entry', array() === $missing, implode( ', ', $missing ) );
$empty = array_keys( array_filter( $services, static fn( array $s ): bool => array() === $s['cookies'] && array() === $s['ocd'] && array() === $s['note'] ) );
$assert( 'no service is empty (cookies, OCD platform or a note)', array() === $empty, implode( ', ', $empty ) );
$badrow = array();
foreach ( $services as $label => $svc ) {
	if ( ! in_array( $svc['category'], array( 'necessary', 'preferences', 'statistics', 'marketing' ), true ) ) {
		$badrow[] = "$label category";
	}
	foreach ( $svc['cookies'] as $row ) {
		if ( 4 !== count( $row ) || '' === $row[0] || '' === $row[1] || '' === $row[2] || ( Cookies::duration( $row[3], 'en' ) === $row[3] && 'session' !== $row[3] ) ) {
			$badrow[] = "$label {$row[0]}";
		}
	}
	if ( '' !== $svc['transfer'] && ( '' === $svc['country'] || '' === $svc['processor'] ) ) {
		$badrow[] = "$label transfer";
	}
}
$assert( 'catalogue rows are complete (4 fields, readable duration, provider for a transfer)', array() === $badrow, implode( '; ', $badrow ) );
foreach ( array( 'Google Analytics', 'Google Ads', 'Google Tag Manager', 'Facebook', 'Sklik (Seznam)', 'Heureka', 'Hotjar', 'Microsoft Clarity', 'YouTube', 'Google Maps', 'Mapy.cz', 'Smartsupp', 'Tawk.to', 'WordPress', 'WooCommerce', 'Cookie consent' ) as $must ) {
	$assert( "catalogue covers $must", isset( $services[ $must ] ) );
}
$assert( 'necessary services carry their own category', 'necessary' === $services['WordPress']['category'] && 'necessary' === $services['Cookie consent']['category'] );
$assert( 'host services take the category of their host', 'statistics' === $services['Hotjar']['category'] && 'marketing' === $services['Facebook']['category'] && 'preferences' === $services['Smartsupp']['category'] );
$assert( 'US services name the transfer, Czech ones stay in the EEA', 'us' === $services['Google Analytics']['transfer'] && 'eea' === $services['Heureka']['transfer'] );

// pattern matching
$assert( 'pattern: wildcard _ga_*', Cookies::pattern_matches( '_ga_*', '_ga_9REVSBTW6T' ) && ! Cookies::pattern_matches( '_ga_*', '_gat' ) && ! Cookies::pattern_matches( '_ga_*', '_ga' ) );
$assert( 'pattern: exact and case sensitive', Cookies::pattern_matches( '_ga', '_ga' ) && ! Cookies::pattern_matches( '_ga', '_GA' ) && ! Cookies::pattern_matches( '_ga', '_ga2' ) );
$assert( 'pattern: wildcard in the middle, special characters are literal', Cookies::pattern_matches( 'wp-settings-time-*', 'wp-settings-time-7' ) && ! Cookies::pattern_matches( 'a.b*', 'axb1' ) );
$assert( 'pattern: regex with ~', Cookies::pattern_matches( '~_hj(Session|SessionUser)_[0-9]+', '_hjSessionUser_123' ) && ! Cookies::pattern_matches( '~_hj[0-9]+', '_hj12x' ) );
$assert( 'pattern: empty never matches', ! Cookies::pattern_matches( '', 'x' ) && ! Cookies::pattern_matches( '*', '' ) );

// match(): catalogue first, then the Open Cookie Database
$m = Cookies::match( '_ga_9REVSBTW6T', 'cs' );
$assert( 'match: GA4 cookie => Google Analytics, statistics, from the catalogue', null !== $m && 'Google Analytics' === $m['service'] && 'statistics' === $m['category'] && 'catalogue' === $m['source'] && '2 roky' === $m['duration'], json_encode( $m, JSON_UNESCAPED_UNICODE ) );
$m = Cookies::match( 'wp_woocommerce_session_abc123', 'en' );
$assert( 'match: WooCommerce session is necessary', null !== $m && 'necessary' === $m['category'] && '2 days' === $m['duration'], json_encode( $m ) );
$m = Cookies::match( 'uxs_consent', 'en' );
$assert( 'match: our own consent cookie', null !== $m && 'Cookie consent' === $m['service'] && 'necessary' === $m['category'] );
$m = Cookies::match( 'tawk_abc', 'en' );
$assert( 'match: falls back to the Open Cookie Database (wildcard prefix)', null !== $m && 'ocd' === $m['source'] && 'Tawk.to' === $m['service'], json_encode( $m ) );
$assert( 'match: unknown cookie is null', null === Cookies::match( 'zz_totally_unknown_cookie', 'en' ) );

// Open Cookie Database
$assert( 'OCD: data file present with Apache-2.0 provenance', OpenCookieDb::available() && 'Apache-2.0' === OpenCookieDb::meta()['license'] && ( OpenCookieDb::meta()['cookies'] ?? 0 ) > 2000 );
$assert( 'OCD: licence text is bundled', is_file( $dir . 'Consent/data/OPEN-COOKIE-DATABASE-LICENSE.txt' ) && false !== strpos( (string) file_get_contents( $dir . 'Consent/data/OPEN-COOKIE-DATABASE-LICENSE.txt' ), 'Apache License' ) );
$o = OpenCookieDb::lookup( '_fbp' );
$assert( 'OCD: exact name', null !== $o && 'Facebook' === $o['platform'] && 'marketing' === $o['category'] );
$o = OpenCookieDb::lookup( '_gac_UA-1234' );
$assert( 'OCD: wildcard entries match by prefix', null !== $o && $o['wildcard'] );
$assert( 'OCD: unknown name', null === OpenCookieDb::lookup( 'zz_totally_unknown_cookie' ) );
$assert( 'OCD: platform rows', count( OpenCookieDb::platform_cookies( array( 'Instagram' ) ) ) > 3 && array() === OpenCookieDb::platform_cookies( array( 'No Such Platform' ) ) );
OpenCookieDb::use_file( __DIR__ . '/does-not-exist.json' );
$assert( 'OCD: missing file degrades to nothing, the catalogue still works', ! OpenCookieDb::available() && null === OpenCookieDb::lookup( '_fbp' ) && null !== Cookies::match( '_fbp', 'en' ) );
OpenCookieDb::use_file( null );

// durations
$assert( 'duration: Czech plurals', '1 rok' === Cookies::duration( '1y', 'cs' ) && '2 roky' === Cookies::duration( '2y', 'cs' ) && '5 let' === Cookies::duration( '5y', 'cs' ) && '13 měsíců' === Cookies::duration( '13mo', 'cs' ) && '3 měsíce' === Cookies::duration( '3mo', 'cs' ) && '1 den' === Cookies::duration( '1d', 'cs' ) && '30 minut' === Cookies::duration( '30min', 'cs' ) && 'do konce relace' === Cookies::duration( 'session', 'cs' ) );
$assert( 'duration: English', '1 year' === Cookies::duration( '1y', 'en' ) && '13 months' === Cookies::duration( '13mo', 'en' ) && 'session' === Cookies::duration( 'session', 'en' ) && 'permanent' === Cookies::duration( 'permanent', 'en' ) );
$assert( 'lang: Czech locale only', 'cs' === Cookies::lang( 'cs_CZ' ) && 'en' === Cookies::lang( 'sk_SK' ) && 'en' === Cookies::lang( 'en_US' ) );

// services for display
$svc = Cookies::service( 'Google Analytics', 'cs' );
$assert( 'service: Czech texts, country and transfer', null !== $svc && 'USA' === $svc['country_name'] && '' !== $svc['transfer_text'] && 'Rozlišuje návštěvníky pro počítání návštěv a návštěvníků.' === $svc['cookies'][0]['purpose'] );
$svc = Cookies::service( 'Instagram', 'en' );
$assert( 'service: no own cookies => Open Cookie Database rows (max 12)', null !== $svc && count( $svc['cookies'] ) > 3 && count( $svc['cookies'] ) <= 12 && 'ocd' === $svc['cookies'][0]['source'] );
$svc = Cookies::service( 'Google Tag Manager', 'cs' );
$assert( 'service: a service with a note and no cookies', null !== $svc && array() === $svc['cookies'] && '' !== $svc['note'] );
$assert( 'service: unknown label is null', null === Cookies::service( 'Unknown Vendor', 'en' ) );
$by = Cookies::services_by_category( array( 'necessary' => array( 'WordPress', 'WordPress' ), 'statistics' => array( 'Hotjar', 'My own tool' ) ), 'en' );
$assert( 'by category: dedupes, keeps hand-written names with an empty list, all four categories present', 1 === count( $by['necessary'] ) && 2 === count( $by['statistics'] ) && 'My own tool' === $by['statistics'][1]['label'] && array() === $by['statistics'][1]['cookies'] && array() === $by['marketing'] );

// --- F2: inventory (pure parts) ---
$html = '<html><head><link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Roboto&display=swap">'
	. '<link rel="stylesheet" href="https://example.cz/wp-content/style.css"><link rel="icon" href="https://icons.example-icons.com/i.png">'
	. '<script src="https://www.googletagmanager.com/gtag/js?id=G-ABC123&amp;l=dl"></script>'
	. '<script type="text/plain" data-uxc="marketing" src="https://connect.facebook.net/en_US/fbevents.js"></script>'
	. '<script src="//cdn.unknownvendor.io/widget.js?token=SECRET"></script>'
	. '<script>fbq( "init", "123" );</script><script>var plain = 1;</script><script type="application/ld+json">{"a":"https://schema.org"}</script>'
	. '</head><body><iframe data-uxc="marketing" data-uxc-src="https://www.youtube-nocookie.com/embed/abc" src=""></iframe>'
	. '<img src="https://www.facebook.com/tr?id=1&ev=PageView"><img src="https://example.cz/own.png"><img src="/relative.png"></body></html>';
$found = Inventory::scan_html( $html, 'example.cz' );
$hosts = array_unique( array_column( $found['refs'], 'host' ) );
sort( $hosts );
$assert( 'scan_html: external hosts only, no own host, no relative address, no JSON-LD', $hosts === array( 'cdn.unknownvendor.io', 'connect.facebook.net', 'fonts.googleapis.com', 'www.facebook.com', 'www.googletagmanager.com', 'www.youtube-nocookie.com' ), implode( ',', $hosts ) );
$assert( 'analytics.google.com is decided by the catalogue, not by the google.com path rule', 'statistics' === $cat( $c( 'https://region1.analytics.google.com/g/collect' ) ) && null === $c( 'https://www.google.com/recaptcha/api.js' ) && 'marketing' === $cat( $c( 'https://www.google.com/maps/embed?pb=1' ) ) );
$assert( 'gtag.js labels: GA4 id => Google Analytics, Ads id => Google Ads, container => Google Tag Manager', 'Google Analytics' === ( $c( 'https://www.googletagmanager.com/gtag/js?id=G-ABC123' )['label'] ?? '' ) && 'Google Ads' === ( $c( 'https://www.googletagmanager.com/gtag/js?id=AW-123' )['label'] ?? '' ) && 'Google Tag Manager' === ( $c( 'https://www.googletagmanager.com/gtm.js?id=GTM-AB' )['label'] ?? '' ) );
$assert( 'scan_html: known inline snippet is recognised', array( 'Facebook' => 'marketing' ) === $found['inline'], json_encode( $found['inline'] ) );
$gtag = array_values( array_filter( $found['refs'], static fn( array $r ): bool => 'www.googletagmanager.com' === $r['host'] ) );
$unk  = array_values( array_filter( $found['refs'], static fn( array $r ): bool => 'cdn.unknownvendor.io' === $r['host'] ) );
$assert( 'scan_html: the Google tag id survives, other query strings (tokens) do not', 'https://www.googletagmanager.com/gtag/js?id=G-ABC123' === $gtag[0]['url'] && 'https://cdn.unknownvendor.io/widget.js' === $unk[0]['url'], $gtag[0]['url'] . ' ' . $unk[0]['url'] );
$assert( 'example_url drops fragments and unknown queries', 'https://a.example.org/x.js' === Inventory::example_url( '//a.example.org/x.js?k=1#f' ) );
$assert( 'valid_host', Inventory::valid_host( 'cdn.example.io' ) && ! Inventory::valid_host( 'localhost' ) && ! Inventory::valid_host( '10.0.0.1' ) && ! Inventory::valid_host( 'a b.com' ) && ! Inventory::valid_host( 'inline' ) );

$facts = Inventory::add_page( array(), $found );
$facts = Inventory::add_page( $facts, $found );
$facts = Inventory::add_csp(
	$facts,
	array(
		array( 'host' => 'tracker.csponly.net', 'hits' => 7, 'url' => 'https://tracker.csponly.net/t.js' ),
		array( 'host' => 'inline', 'hits' => 9, 'url' => '' ),
		array( 'host' => 'example.cz', 'hits' => 3, 'url' => 'https://example.cz/a.js' ),
	),
	'example.cz'
);
$assert( 'add_page: counts pages per host', 2 === $facts['hosts']['cdn.unknownvendor.io']['p'] && 'html' === $facts['hosts']['cdn.unknownvendor.io']['s'][0] );
$assert( 'add_csp: CSP-only host added, keywords and own host skipped', isset( $facts['hosts']['tracker.csponly.net'] ) && 7 === $facts['hosts']['tracker.csponly.net']['h'] && ! isset( $facts['hosts']['inline'] ) && ! isset( $facts['hosts']['example.cz'] ) );

$state = Inventory::evaluate( $facts, array(), 'example.cz' );
$queue = array_column( $state['queue'], 'host' );
$sorted = $queue;
sort( $sorted );
$assert( 'evaluate: unknown hosts go to the queue', $sorted === array( 'cdn.unknownvendor.io', 'tracker.csponly.net' ), implode( ',', $queue ) );
$assert( 'evaluate: busiest unknown host first', 'cdn.unknownvendor.io' === $state['queue'][0]['host'] );
$assert( 'evaluate: GA4 tag => Google Analytics statistics', 'statistics' === ( $state['services']['Google Analytics']['category'] ?? '' ) );
$assert( 'evaluate: iframe and pixel => marketing services', 'marketing' === ( $state['services']['YouTube']['category'] ?? '' ) && 'marketing' === ( $state['services']['Facebook']['category'] ?? '' ) );
$assert( 'evaluate: fonts are known and need no consent, not queued', in_array( 'fonts.googleapis.com', array_column( $state['known'], 'host' ), true ) && ! in_array( 'fonts.googleapis.com', $queue, true ) );

$rules_text = Inventory::rules_with( "# my notes\nold.example.org marketing", 'cdn.unknownvendor.io', 'marketing' );
$assert( 'rules_with: appends, keeps comments and other lines', "# my notes\nold.example.org marketing\ncdn.unknownvendor.io marketing" === $rules_text, $rules_text );
$rules_text = Inventory::rules_with( $rules_text, 'cdn.unknownvendor.io', 'necessary' );
$assert( 'rules_with: a second decision replaces the first', 1 === substr_count( $rules_text, 'cdn.unknownvendor.io' ) && str_ends_with( $rules_text, 'cdn.unknownvendor.io necessary' ), $rules_text );
$assert( 'rules_with: remove forgets the host', false === strpos( Inventory::rules_with( $rules_text, 'cdn.unknownvendor.io', 'remove' ), 'unknownvendor' ) );

$rules = Services::parse_rules( Inventory::rules_with( '', 'cdn.unknownvendor.io', 'marketing' ) );
$state = Inventory::evaluate( $facts, $rules, 'example.cz' );
$assert( 'evaluate: a classified host leaves the queue and becomes a service in that category', ! in_array( 'cdn.unknownvendor.io', array_column( $state['queue'], 'host' ), true ) && 'marketing' === ( $state['services']['cdn.unknownvendor.io']['category'] ?? '' ) && 'cdn.unknownvendor.io' === $state['decided'][0]['host'] );
$assert( 'the Blocker uses the same decision (own rule)', 'marketing' === $cat( Services::classify( 'https://cdn.unknownvendor.io/widget.js', 'example.cz', $rules ) ) );
$rules = Services::parse_rules( Inventory::rules_with( '', 'cdn.unknownvendor.io', 'necessary' ) );
$state = Inventory::evaluate( $facts, $rules, 'example.cz' );
$assert( 'evaluate: necessary decision leaves the queue and is not a consent service', ! isset( $state['services']['cdn.unknownvendor.io'] ) && 'necessary' === $state['decided'][0]['category'] && null === Services::classify( 'https://cdn.unknownvendor.io/widget.js', 'example.cz', $rules ) );
$assert( 'rule_for_host: parent domain rule covers a subdomain, a path rule does not', null !== Services::rule_for_host( 'a.b.example.org', Services::parse_rules( 'example.org marketing' ) ) && null === Services::rule_for_host( 'example.org', Services::parse_rules( 'example.org/ads marketing' ) ) );

// ---------- banner templates (PLAN 42.20) ----------
$assert( 'templates: the four known ones, classic first', array( 'classic', 'cookiebot', 'cookie', 'slim' ) === Templates::ALL );
$assert( 'templates: an unknown or empty value is classic', 'classic' === Templates::normalize( 'nonsense' ) && 'classic' === Templates::normalize( '' ) && 'classic' === Templates::normalize( 'SLIM' ) && 'slim' === Templates::normalize( 'slim' ) );
$assert( 'templates: classic loads no extra style, the others load consent-<name>.min.css only', '' === Templates::style_file( 'classic', '.min' ) && 'consent-slim.min.css' === Templates::style_file( 'slim', '.min' ) && 'consent-cookie.css' === Templates::style_file( 'cookie', '' ) && '' === Templates::style_file( 'x/../y', '.min' ) );
foreach ( array( 'cookiebot', 'cookie', 'slim' ) as $t ) {
	$f = Templates::style_file( $t, '.min' );
	$assert( "templates: $t has its style file built", is_file( $root . '/assets/consent/' . $f ) && is_file( $root . '/assets/consent/consent-' . $t . '.css' ) );
}
$def = array( 'color_bg' => '#ffffff', 'color_text' => '#1d2327', 'color_accent' => '#1d4ed8', 'color_accent_text' => '#ffffff' );
$assert( 'templates: default colours add nothing in classic, cookiebot and slim (existing sites do not change)', '' === Templates::variables( 'classic', $def ) && '' === Templates::variables( 'cookiebot', $def ) && '' === Templates::variables( 'slim', $def ) );
$assert( 'templates: own colours are emitted in every template', '--uxc-bg:#112233;' === Templates::variables( 'classic', array( 'color_bg' => '#112233' ) + $def ) );
$assert( 'templates: the cookie card is light by default (no colour overrides, like the other templates)', '' === Templates::variables( 'cookie', $def ) );
$assert( 'templates: own colours are emitted in the cookie card too', '--uxc-bg:#111111;--uxc-fg:#eeeeee;' === Templates::variables( 'cookie', array( 'color_bg' => '#111111', 'color_text' => '#eeeeee' ) + $def ) );
// The settings schema and the script know the same names.
$module_src = file_get_contents( $dir . 'Consent/Module.php' );
$js_src     = file_get_contents( $root . '/assets/consent/consent.js' );
foreach ( Templates::ALL as $t ) {
	$assert( "templates: $t is offered in the settings and known to consent.js", false !== strpos( $module_src, "'$t'" ) && ( 'classic' === $t || false !== strpos( $js_src, "'$t'" ) ) );
}
$assert( 'templates: the config carries the template and the settings hide layout outside classic', false !== strpos( $module_src, "'tpl'    => \$this->template()" ) && 1 === preg_match( "/'key'\s*=>\s*'layout'.*?'show_if'\s*=>\s*array\(\s*'key'\s*=>\s*'template',\s*'equals'\s*=>\s*'classic'/s", $module_src ) );
$assert( 'templates: the Complianz migration maps layout only, so migrated sites stay on the default (classic) template', false === strpos( file_get_contents( $dir . 'Consent/ComplianzMap.php' ), "'template'" ) );

$all_php = implode( '', array_map( 'file_get_contents', array_merge( glob( $dir . 'Consent/*.php' ), array( $dir . 'SecurityOptimization/data/consent-services.php', $root . '/bin/import-open-cookie-database.php' ) ) ) );
$assert( 'no em or en dash in the F2 sources and data', 0 === preg_match( '/[\x{2013}\x{2014}]/u', $all_php . file_get_contents( $root . '/assets/consent/consent.js' ) ) );

if ( $failures ) { echo "$failures failure(s)\n"; exit( 1 ); }
echo "ALL PASSED\n";
