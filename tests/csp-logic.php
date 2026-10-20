<?php
/**
 * Standalone test of the CSP logic that needs no database: risk rating,
 * policy serialisation, service catalogue, URL privacy, report parsing.
 *
 *   php tests/csp-logic.php
 *
 * WordPress functions used by those classes are stubbed below. Exits
 * non-zero when any case fails.
 *
 * @package UxStudio
 */

// phpcs:disable

define( 'ABSPATH', __DIR__ . '/' );
define( 'DAY_IN_SECONDS', 86400 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'MINUTE_IN_SECONDS', 60 );

$GLOBALS['uxs_options'] = array();

function wp_parse_url( $url, $component = -1 ) { return parse_url( $url, $component ); }
function home_url( $path = '' ) { return 'https://www.pobyty.cz' . $path; }
function wp_salt( $scheme = 'auth' ) { return 'test-salt'; }
function apply_filters( $hook, $value ) { return $value; }
function get_option( $name, $default = false ) { return $GLOBALS['uxs_options'][ $name ] ?? $default; }
function update_option( $name, $value, $autoload = null ) { $GLOBALS['uxs_options'][ $name ] = $value; return true; }
function rest_url( $path ) { return 'https://www.pobyty.cz/wp-json/' . $path; }
function add_query_arg( $args, $url ) { return $url . ( str_contains( $url, '?' ) ? '&' : '?' ) . http_build_query( $args ); }
function esc_url_raw( $url ) { return $url; }
function __( $text, $domain = '' ) { return $text; }
function sanitize_key( $key ) { return strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', (string) $key ) ); }

$dir = dirname( __DIR__ ) . '/includes/Modules/SecurityOptimization/';
require_once $dir . 'CspServices.php';
require_once $dir . 'CspPolicy.php';
require_once $dir . 'CspRisk.php';
require_once $dir . 'CspLogger.php';

use UxStudio\Modules\SecurityOptimization\CspLogger;
use UxStudio\Modules\SecurityOptimization\CspPolicy;
use UxStudio\Modules\SecurityOptimization\CspRisk;
use UxStudio\Modules\SecurityOptimization\CspServices;

$failures = 0;
$assert   = static function ( string $name, bool $ok, string $detail = '' ) use ( &$failures ): void {
	if ( ! $ok ) {
		++$failures;
	}
	echo ( $ok ? 'PASS ' : 'FAIL ' ) . $name . ( $ok || '' === $detail ? '' : ' => ' . $detail ) . PHP_EOL;
};

$site = 'www.pobyty.cz';
$rate = static fn( string $uri, string $dir = 'script-src' ) => CspRisk::rate( $uri, $dir, $site );

/* ── Risk rating ── */

$r = $rate( 'https://kjhg.lol/js/jquery-3.7.1.migrate.js' );
$assert( 'seco-traktory injection (kjhg.lol) is high', 'high' === $r['level'], json_encode( $r ) );

$r = $rate( 'https://www.googletagmanager.com/gtag/js' );
$assert( 'GTM is a known service', 'known' === $r['level'] && 'Google Tag Manager' === $r['service'], json_encode( $r ) );

$r = $rate( 'https://cdn.pobyty.cz/app.js' );
$assert( 'own subdomain is self', 'self' === $r['level'], json_encode( $r ) );

$r = $rate( 'https://pobyty.cz/app.js' );
$assert( 'apex of own site is self', 'self' === $r['level'], json_encode( $r ) );

$r = $rate( 'https://gooogletagmanager.com/gtm.js' );
$assert( 'typo of googletagmanager is high', 'high' === $r['level'] && in_array( 'typo:googletagmanager.com', $r['reasons'], true ), json_encode( $r ) );

$r = $rate( 'https://google-analytics.org/x.js' );
$assert( 'same brand other TLD is at least medium', in_array( $r['level'], array( 'medium', 'high' ), true ) && in_array( 'brand:google-analytics.com', $r['reasons'], true ), json_encode( $r ) );

$r = $rate( 'https://www.google.de/recaptcha/api.js' );
$assert( 'google.de is not high (country version)', 'high' !== $r['level'], json_encode( $r ) );

$r = $rate( 'http://185.12.34.56/x.js' );
$assert( 'IP + http is high', 'high' === $r['level'], json_encode( $r ) );

$r = $rate( 'https://abc.trycloudflare.com/skim.js', 'connect-src' );
$assert( 'tunnel host is medium+', in_array( $r['level'], array( 'medium', 'high' ), true ), json_encode( $r ) );

$r = $rate( 'https://kjhg.lol/pixel.gif', 'img-src' );
$assert( 'risky image weighs less than risky script', $r['score'] < $rate( 'https://kjhg.lol/x.js' )['score'], json_encode( $r ) );

$r = $rate( 'https://widget.booking-partner-example.com/w.js' );
$assert( 'unknown plain host stays unknown (no false alarm)', 'unknown' === $r['level'] && 0 === $r['score'], json_encode( $r ) );

$r = $rate( 'https://xn--pypal-4ve.com/x.js' );
$assert( 'punycode is flagged', in_array( 'punycode', $r['reasons'], true ), json_encode( $r ) );

$r = $rate( 'inline', 'script-src' );
$assert( 'inline without sample is unknown', 'unknown' === $r['level'], json_encode( $r ) );

$r = CspRisk::rate( 'inline', 'script-src', $site, 'eval(atob("ZG9jdW1lbn' );
$assert( 'obfuscated inline sample is flagged', in_array( 'obfuscated_inline', $r['reasons'], true ), json_encode( $r ) );

$r = CspRisk::with_domain_age( $rate( 'https://fresh-shop-cdn.com/x.js' ), time() - 5 * DAY_IN_SECONDS, 'script-src' );
$assert( 'domain registered 5 days ago becomes medium+', in_array( $r['level'], array( 'medium', 'high' ), true ) && in_array( 'new_domain', $r['reasons'], true ), json_encode( $r ) );

$r = CspRisk::with_domain_age( $rate( 'https://www.googletagmanager.com/gtm.js' ), time() - 5 * DAY_IN_SECONDS, 'script-src' );
$assert( 'domain age never downgrades a known service', 'known' === $r['level'], json_encode( $r ) );

$assert( 'registrable domain co.uk', 'example.co.uk' === CspRisk::registrable_domain( 'cdn.example.co.uk' ) );
$assert( 'registrable domain .cz', 'pobyty.cz' === CspRisk::registrable_domain( 'www.pobyty.cz' ) );
$assert( 'random name detection', CspRisk::looks_random( 'xkqzvbt.com' ) && ! CspRisk::looks_random( 'smartsupp.com' ) );

/* ── Catalogue ── */

$assert( 'catalogue subdomain match', 'Smartsupp' === CspServices::label_for_host( 'widget-v3.smartsupp.com' ) );
$assert( 'catalogue does not match suffix-only', '' === CspServices::label_for_host( 'evilgoogle.com' ) );
$assert( 'wildcard source matches subdomain', CspServices::source_matches_host( 'https://*.smartsupp.com', 'x.smartsupp.com' ) );
$assert( 'wildcard source does not match apex-lookalike', ! CspServices::source_matches_host( 'https://*.smartsupp.com', 'evilsmartsupp.com' ) );
$assert( 'preset lookup', 'google_fonts' === CspServices::preset_for( 'fonts.gstatic.com', 'font-src' ) );

/* ── Policy ── */

$settings = array(
	'csp_mode'             => 'report-only',
	'csp_level'            => 'basic',
	'csp_presets'          => array( 'google_fonts' ),
	'csp_custom_allowlist' => "https://cdn.example.com\n# comment\njavascript:alert(1)",
);
$policy = new CspPolicy( static fn( string $k, $d = null ) => $settings[ $k ] ?? $d );

$audit = $policy->audit_policy();
$assert( 'audit policy disallows foreign scripts', str_contains( $audit, "script-src 'self' 'unsafe-inline' 'unsafe-eval' blob:" ) && ! str_contains( $audit, 'https:' ), $audit );
$assert( 'audit policy covers connect/frame/form-action', str_contains( $audit, "connect-src 'self'" ) && str_contains( $audit, "frame-src 'self'" ) && str_contains( $audit, "form-action 'self'" ), $audit );

CspPolicy::approve_source( 'script-src-elem', 'https://widget.packeta.com' );
CspPolicy::approve_source( 'media-src', 'https://media.example.net' );
$assert( 'approving twice reports false', false === CspPolicy::approve_source( 'script-src', 'https://widget.packeta.com' ) );
$assert( 'approving unknown directive refused', false === CspPolicy::approve_source( 'evil-src', 'https://x.com' ) );

$candidate = $policy->candidate_policy();
$assert( 'candidate has preset font', str_contains( $candidate, 'https://fonts.gstatic.com' ), $candidate );
$assert( 'candidate has custom allowlist', str_contains( $candidate, 'https://cdn.example.com' ), $candidate );
$assert( 'custom allowlist rejects javascript: scheme', ! str_contains( $candidate, 'javascript:' ), $candidate );
$assert( 'approved script source only in script-src', (bool) preg_match( "/script-src [^;]*https:\/\/widget\.packeta\.com/", $candidate ) && ! preg_match( "/connect-src [^;]*packeta/", $candidate ), $candidate );
$assert( 'new directive starts from self', str_contains( $candidate, "media-src 'self' https://media.example.net" ), $candidate );
$assert( 'candidate_allows approved host', $policy->candidate_allows( 'script-src-elem', 'widget.packeta.com' ) );
$assert( 'candidate does not allow other host', ! $policy->candidate_allows( 'script-src', 'kjhg.lol' ) );
$assert( 'img https: allows any image host', $policy->candidate_allows( 'img-src', 'kjhg.lol' ) );

$assert( 'base directive', 'script-src' === CspPolicy::base_directive( 'script-src-elem' ) && 'style-src' === CspPolicy::base_directive( 'style-src-attr' ) && 'img-src' === CspPolicy::base_directive( 'img-src' ) );
$assert( 'report url carries token and tag', str_contains( CspPolicy::report_url( 't' ), 'k=' . CspPolicy::report_token() ) && str_contains( CspPolicy::report_url( 't' ), 'p=t' ) );
$assert( 'probe token differs from report token', CspPolicy::probe_token() !== CspPolicy::report_token() );

/* ── Privacy ── */

$assert( 'query string stripped', 'https://www.pobyty.cz/kosik/' === CspLogger::strip_query( 'https://www.pobyty.cz/kosik/?email=a@b.cz#x' ) );
$assert( 'uri_to_source', 'https://kjhg.lol' === CspLogger::uri_to_source( 'https://kjhg.lol/js/x.js' ) && '' === CspLogger::uri_to_source( 'inline' ) );

echo PHP_EOL . ( 0 === $failures ? 'ALL PASSED' : $failures . ' FAILED' ) . PHP_EOL;
exit( 0 === $failures ? 0 : 1 );
