<?php
// Test only: never runs over HTTP (the release zip also excludes tests/).
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { exit; }
/**
 * Standalone test (no WordPress) of the dynamic scan (PLAN.md 42, phase F3): sanitizing of what the Central App pushes,
 * the evaluation (violations without consent and after a rejection, unknown cookies, what is NOT a violation), the
 * newness against the previous scan, and the hosts handed to the inventory queue.
 *
 *   php tests/consent-scan.php
 *
 * The signature check of the push is the existing HMAC of the node routes (the content-sync tests cover it); the WordPress
 * side (routes, storage, signature) is in tests/consent-scan-wp.php, a live end-to-end run is described in PLAN.md 42.15.
 *
 * @package UxStudio
 */

// phpcs:disable

define( 'ABSPATH', __DIR__ . '/' );
function apply_filters( $hook, $value ) { return $value; }
function __( $text, $domain = '' ) { return $text; }
function home_url( $path = '' ) { return 'https://shop.test' . $path; }
function wp_parse_url( $url, $component = -1 ) { return parse_url( $url, $component ); }
if ( ! class_exists( 'WP_Error' ) ) { eval( 'class WP_Error { public $code; public function __construct( $code = "", $message = "", $data = array() ) { $this->code = $code; } }' ); }

$root = dirname( __DIR__ );
$dir  = $root . '/includes/Modules/';
require_once $dir . 'SecurityOptimization/CspServices.php';
require_once $dir . 'Consent/Services.php';
require_once $dir . 'Consent/OpenCookieDb.php';
require_once $dir . 'Consent/Cookies.php';
require_once $dir . 'Consent/Inventory.php';
require_once $dir . 'Consent/Scan.php';

use UxStudio\Modules\Consent\Cookies;
use UxStudio\Modules\Consent\Inventory;
use UxStudio\Modules\Consent\Scan;

$failures = 0;
$assert   = static function ( string $name, bool $ok, string $detail = '' ) use ( &$failures ): void {
	if ( ! $ok ) {
		++$failures;
	}
	echo ( $ok ? 'PASS ' : 'FAIL ' ) . $name . ( $ok || '' === $detail ? '' : ' => ' . $detail ) . "\n";
};

$cookie = static fn( string $name, array $extra = array() ): array => $extra + array(
	'name' => $name, 'domain' => 'shop.test', 'path' => '/', 'expires' => 1900000000, 'http_only' => false, 'secure' => true,
	'same_site' => 'Lax', 'third_party' => false, 'phase' => 'load', 'page' => '/', 'via' => 'script', 'by_host' => '',
);
$request = static fn( string $host, array $extra = array() ): array => $extra + array(
	'host' => $host, 'type' => 'script', 'path' => '/x.js', 'third_party' => true, 'n_pre' => 1, 'n_post' => 0,
);
$scenario = static fn( array $cookies = array(), array $requests = array(), array $extra = array() ): array => $extra + array(
	'status' => 'ok', 'banner_visible' => true, 'clicked' => true, 'cookies' => $cookies, 'storage' => array(), 'requests' => $requests,
);
$cookie_class = static function ( string $name ): ?array {
	$m = Cookies::match( $name, 'en' );
	return null === $m ? null : array( 'category' => $m['category'], 'service' => $m['service'], 'purpose' => $m['purpose'], 'duration' => $m['duration'] );
};
$eval = static fn( array $raw, ?array $prev = null, bool $adv = false ): array => Scan::evaluate( Scan::sanitize( $raw ) ?? array(), $cookie_class, array( Scan::class, 'classify_host' ), $prev, $adv );
$find = static function ( array $e, string $key ): bool {
	foreach ( $e['violations'] as $v ) {
		if ( $v['key'] === $key ) { return true; }
	}
	return false;
};

// ---------- sanitize ----------
$assert( 'sanitize: rubbish is no scan', null === Scan::sanitize( 'x' ) && null === Scan::sanitize( array() ) && null === Scan::sanitize( array( 'scenarios' => array( 'evil' => array() ) ) ) );
$s = Scan::sanitize( array(
	'scanned_at' => '2026-11-09T10:00:00Z', 'site_url' => 'https://shop.test/?token=abc', 'pages' => array( 'https://shop.test/a?x=1', 'https://shop.test/b' ),
	'banner' => array( 'kind' => 'uxstudio', 'found' => 1 ),
	'scenarios' => array(
		'none' => $scenario(
			array(
				$cookie( '_ga', array( 'value' => 'SECRET-VALUE', 'extra' => 'x', 'same_site' => 'Weird', 'via' => 'oops', 'phase' => 'zzz', 'domain' => '.Example.COM' ) ),
				$cookie( "bad\x00name\n" ),
				array( 'name' => '' ),
				'string',
			),
			array( $request( 'www.google-analytics.com', array( 'path' => '/g/collect?v=2&cid=123#h', 'type' => 'weird' ) ), $request( '192.168.1.1' ), $request( 'bad host' ) )
		),
		'evil' => $scenario(),
	),
) );
$none = $s['scenarios']['none'];
$assert( 'sanitize: unknown scenario dropped, known kept', array( 'none' ) === array_keys( $s['scenarios'] ) );
$assert( 'sanitize: a cookie value never survives', false === strpos( json_encode( $s ), 'SECRET-VALUE' ) && ! isset( $none['cookies'][0]['value'] ) && ! isset( $none['cookies'][0]['extra'] ) );
$assert( 'sanitize: bad enums fall back, domain is normalised', '' === $none['cookies'][0]['same_site'] && '' === $none['cookies'][0]['via'] && 'load' === $none['cookies'][0]['phase'] && 'example.com' === $none['cookies'][0]['domain'] );
$assert( 'sanitize: control characters out, empty and non-array cookies dropped', 2 === count( $none['cookies'] ) && 'bad name' === $none['cookies'][1]['name'], json_encode( $none['cookies'] ) );
$assert( 'sanitize: request query and fragment are cut', '/g/collect' === $none['requests'][0]['path'] && 'other' === $none['requests'][0]['type'] );
$assert( 'sanitize: IP and invalid hosts dropped', 1 === count( $none['requests'] ) );
$assert( 'sanitize: page and site addresses lose their query', 'https://shop.test/' === $s['site_url'] && 'https://shop.test/a' === $s['pages'][0] );
$assert( 'sanitize: banner kind is whitelisted', 'uxstudio' === $s['banner']['kind'] && true === $s['banner']['found'] );
$big = array_fill( 0, 500, $cookie( 'c' ) );
$assert( 'sanitize: caps per scenario', Scan::MAX_COOKIES === count( Scan::sanitize( array( 'scenarios' => array( 'none' => $scenario( $big ) ) ) )['scenarios']['none']['cookies'] ) );
$assert( 'sanitize: banner kind outside the list is dropped', '' === Scan::sanitize( array( 'banner' => array( 'kind' => '<script>' ), 'scenarios' => array( 'none' => $scenario() ) ) )['banner']['kind'] );

// ---------- evaluate: violations ----------
$raw = array(
	'banner' => array( 'kind' => 'uxstudio', 'found' => true ),
	'scenarios' => array(
		'none' => $scenario(
			array( $cookie( '_ga' ), $cookie( 'uxs_consent' ), $cookie( 'mystery_cookie' ), $cookie( 'wordpress_test_cookie' ) ),
			array( $request( 'www.google-analytics.com' ), $request( 'www.googletagmanager.com', array( 'n_pre' => 2 ) ), $request( 'fonts.gstatic.com' ), $request( 'cdn.unknownvendor.example', array( 'type' => 'xhr' ) ), $request( 'shop.test', array( 'third_party' => false ) ) )
		),
		'accept' => $scenario( array( $cookie( '_ga', array( 'phase' => 'choice' ) ), $cookie( '_fbp', array( 'phase' => 'choice' ) ) ), array( $request( 'connect.facebook.net', array( 'n_pre' => 0, 'n_post' => 3 ) ) ) ),
		'reject' => $scenario(
			array( $cookie( '_ga', array( 'phase' => 'load' ) ), $cookie( '_fbp', array( 'phase' => 'choice' ) ), $cookie( 'uxs_consent', array( 'phase' => 'choice' ) ) ),
			array( $request( 'www.google-analytics.com', array( 'n_pre' => 1, 'n_post' => 0 ) ), $request( 'connect.facebook.net', array( 'n_pre' => 0, 'n_post' => 2 ) ) )
		),
	),
);
$e = $eval( $raw );
$assert( 'no consent: a statistics cookie is a violation', $find( $e, 'none|cookie|_ga|shop.test' ) );
$assert( 'no consent: the banner cookie and the WordPress test cookie are necessary', ! $find( $e, 'none|cookie|uxs_consent|shop.test' ) && ! $find( $e, 'none|cookie|wordpress_test_cookie|shop.test' ) );
$assert( 'no consent: an unknown cookie is NOT a violation, it is queued', ! $find( $e, 'none|cookie|mystery_cookie|shop.test' ) && 'mystery_cookie' === ( $e['unknown_cookies'][0]['name'] ?? '' ) );
$assert( 'no consent: tracking hosts are violations', $find( $e, 'none|request|www.google-analytics.com' ) && $find( $e, 'none|request|www.googletagmanager.com' ), json_encode( array_column( $e['violations'], 'key' ) ) );
$assert( 'no consent: font host and unknown host are no violation', ! $find( $e, 'none|request|fonts.gstatic.com' ) && ! $find( $e, 'none|request|cdn.unknownvendor.example' ) );
$assert( 'no consent: the unknown host is handed to the inventory queue', in_array( 'cdn.unknownvendor.example', $e['unknown_hosts'], true ) && ! in_array( 'fonts.gstatic.com', $e['unknown_hosts'], true ) );
$assert( 'no consent: the own host is never listed', ! in_array( 'shop.test', array_column( $e['hosts'], 'host' ), true ) );
$assert( 'accept all is only a reference, never judged', ! $find( $e, 'accept|cookie|_fbp|shop.test' ) && ! $find( $e, 'accept|request|connect.facebook.net' ) && false === $e['scenarios']['accept']['judged'] );
$assert( 'rejection: a cookie set before the click is not the rejection fault', ! $find( $e, 'reject|cookie|_ga|shop.test' ) );
$assert( 'rejection: a tracking cookie after the click is a violation, the banner cookie is not', $find( $e, 'reject|cookie|_fbp|shop.test' ) && ! $find( $e, 'reject|cookie|uxs_consent|shop.test' ) );
$assert( 'rejection: only requests after the click count', $find( $e, 'reject|request|connect.facebook.net' ) && ! $find( $e, 'reject|request|www.google-analytics.com' ) );
$assert( 'summary counts', $e['summary']['violations'] === count( $e['violations'] ) && 1 === $e['summary']['unknown_cookies'] && 'uxstudio' === $e['summary']['banner'] );
$ga = array_values( array_filter( $e['cookies'], static fn( $c ) => '_ga' === $c['name'] ) )[0];
$assert( 'cookies are merged over the runs and keep where they were seen', 3 === count( $ga['scenarios'] ) && 'none' === $ga['first_seen']['scenario'] );
$assert( 'a found cookie carries the catalogue data', 'statistics' === $ga['category'] && '' !== $ga['service'] && '' !== $ga['purpose'] );

// ---------- evaluate: runs that cannot be judged ----------
$nb = $raw;
$nb['scenarios']['none']['banner_visible'] = false;
$nb['scenarios']['reject']['clicked']      = false;
$e2 = $eval( $nb );
$assert( 'no banner visible: the run without consent is not judged', ! $find( $e2, 'none|cookie|_ga|shop.test' ) && false === $e2['scenarios']['none']['judged'] && 'no_banner' === $e2['scenarios']['none']['note'] );
$assert( 'banner button not found: the run after rejection is not judged', ! $find( $e2, 'reject|cookie|_fbp|shop.test' ) && 'banner_not_found' === $e2['scenarios']['reject']['note'] );
$err = $raw;
$err['scenarios']['none'] = array( 'status' => 'error', 'error' => 'timeout', 'cookies' => array( $cookie( '_ga' ) ) );
$assert( 'a failed run is not judged', ! $find( $eval( $err ), 'none|cookie|_ga|shop.test' ) );

// ---------- Consent Mode advanced ----------
$e3 = $eval( $raw, null, true );
$assert( 'advanced Consent Mode: Google pings before consent are by design, other trackers and cookies still count', ! $find( $e3, 'none|request|www.google-analytics.com' ) && ! $find( $e3, 'none|request|www.googletagmanager.com' ) && $find( $e3, 'none|cookie|_ga|shop.test' ) && $find( $e3, 'reject|request|connect.facebook.net' ) );

// ---------- newness ----------
$assert( 'first scan: every violation is new, unknown cookies are not counted as new', $e['summary']['new_violations'] === $e['summary']['violations'] && 0 === $e['summary']['new_unknown'] );
$e4 = $eval( $raw, $e['keys'] );
$assert( 'same scan again: nothing is new', 0 === $e4['summary']['new_violations'] && 0 === $e4['summary']['new_unknown'] );
$more = $raw;
$more['scenarios']['none']['cookies'][] = $cookie( '_gcl_au' );
$more['scenarios']['none']['cookies'][] = $cookie( 'another_mystery' );
$e5 = $eval( $more, $e['keys'] );
$assert( 'a new tracking cookie and a new unknown cookie are new', 1 === $e5['summary']['new_violations'] && 1 === $e5['summary']['new_unknown'], json_encode( $e5['summary'] ) );
$newflags = array_column( $e5['violations'], 'new', 'name' );
$assert( 'violations carry a "new" flag for the notification', true === ( $newflags['_gcl_au'] ?? null ) && false === ( $newflags['_ga'] ?? null ), json_encode( $newflags ) );
$assert( 'a violation that went away is simply gone', 0 === $eval( array( 'scenarios' => array( 'none' => $scenario() ) ), $e['keys'] )['summary']['violations'] );

// ---------- inventory facts ----------
$facts = Scan::hosts_as_facts( $e['hosts'] );
$assert( 'hosts become inventory facts', isset( $facts['cdn.unknownvendor.example'] ) && array( 'scan' ) === $facts['cdn.unknownvendor.example']['s'] && 'https://cdn.unknownvendor.example/x.js' === $facts['cdn.unknownvendor.example']['u'][0] );
$state = Inventory::evaluate( array( 'hosts' => $facts ), array(), 'shop.test' );
$assert( 'the inventory queues the unknown scan host and classifies the tracker', in_array( 'cdn.unknownvendor.example', array_column( $state['queue'], 'host' ), true ) && count( $state['services'] ) > 0 );

echo $failures ? "\n$failures FAILED\n" : "\nAll passed\n";
exit( $failures ? 1 : 0 );
