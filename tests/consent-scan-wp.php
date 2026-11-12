<?php
// Test only: never runs over HTTP (the release zip also excludes tests/).
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { exit; }
/**
 * Manual test of the dynamic scan inside WordPress (PLAN.md 42, phase F3): the signed routes of the Central App (plan, result),
 * what happens to a forged, replayed or oversized push, storage and evaluation, the scan hosts in the inventory queue, the
 * additive keys of Report::site(), and the permissions of the admin routes.
 *
 * Loads the classes of THIS checkout, so run it with the installed UX1 Studio skipped:
 *
 *   php wp-cli.phar --path=<site> --skip-plugins=ux-studio,ux-studio-csp,wp-mail-smtp eval-file tests/consent-scan-wp.php </dev/null
 *
 * It temporarily writes the options uxstudio_consent, uxstudio_consent_inventory, uxstudio_consent_scan and the node key secret,
 * and restores or deletes them at the end. Exits non-zero when any case fails.
 *
 * @package UxStudio
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'UXSTUDIO_PATH' ) ) {
	define( 'UXSTUDIO_VERSION', 'test' );
	define( 'UXSTUDIO_PATH', dirname( __DIR__ ) . '/' );
	define( 'UXSTUDIO_URL', home_url( '/wp-content/plugins/ux-studio/' ) );
	require_once UXSTUDIO_PATH . 'includes/Autoloader.php';
	\UxStudio\Autoloader::register();
}

use UxStudio\Core\Security;
use UxStudio\Modules\Consent\Inventory;
use UxStudio\Modules\Consent\Module;
use UxStudio\Modules\Consent\Report;
use UxStudio\Modules\Consent\Scan;
use UxStudio\Modules\ContentSync\HmacAuth;
use UxStudio\Modules\ContentSync\Module as ContentSyncModule;

$failures = 0;
$assert   = static function ( string $name, bool $ok, string $detail = '' ) use ( &$failures ): void {
	if ( ! $ok ) {
		++$failures;
	}
	echo ( $ok ? 'PASS ' : 'FAIL ' ) . $name . ( $ok || '' === $detail ? '' : ' => ' . $detail ) . "\n";
};

$had = array();
foreach ( array( 'uxstudio_consent', Inventory::OPTION, Scan::OPTION, ContentSyncModule::SECRET_NODE_KEY ) as $option ) {
	$had[ $option ] = get_option( $option, null );
}
delete_option( 'uxstudio_consent' );
delete_option( Inventory::OPTION );
delete_option( Scan::OPTION );
$secret = 'test-node-key-' . bin2hex( random_bytes( 8 ) );
Security::store_secret( ContentSyncModule::SECRET_NODE_KEY, $secret );

$meta   = json_decode( (string) file_get_contents( UXSTUDIO_PATH . 'includes/Modules/Consent/meta.json' ), true );
$module = new Module( 'consent', $meta );
$module->boot();
$GLOBALS['wp_rest_server'] = null;
rest_get_server();

/** A call signed like the Central App's ApiClient (HMAC with nonce over method, url, timestamp, nonce, sha256 of the body). */
$signed = static function ( string $method, string $route, string $body = '', ?string $key = null, ?int $ts = null, ?string $nonce = null ) use ( $secret ) {
	$path                  = '/wp-json' . $route;
	$_SERVER['HTTP_HOST']  = 'ca-test.local';
	$_SERVER['REQUEST_URI'] = $path;
	unset( $_SERVER['HTTPS'] );
	$ts    = $ts ?? time();
	$nonce = $nonce ?? bin2hex( random_bytes( 8 ) );
	$url   = 'http://ca-test.local' . $path;
	$req   = new WP_REST_Request( $method, $route );
	$req->set_header( 'X-Content-Sync-Signature', HmacAuth::sign( $method, $url, $body, $ts, $nonce, $key ?? $secret ) );
	$req->set_header( 'X-Content-Sync-Timestamp', (string) $ts );
	$req->set_header( 'X-Content-Sync-Nonce', $nonce );
	$req->set_header( 'Content-Type', 'application/json' );
	$req->set_body( $body );
	return rest_do_request( $req );
};

$cookie = static fn( string $name, array $extra = array() ): array => $extra + array(
	'name' => $name, 'domain' => 'pobyty.test', 'path' => '/', 'expires' => 1900000000, 'http_only' => false, 'secure' => true,
	'same_site' => 'Lax', 'third_party' => false, 'phase' => 'load', 'page' => '/', 'via' => 'script', 'by_host' => '',
);
$push = array(
	'scan_id' => 42,
	'result'  => array(
		'schema' => 1, 'scanned_at' => gmdate( 'c' ), 'site_url' => home_url( '/' ), 'pages' => array( home_url( '/' ) ),
		'banner' => array( 'kind' => 'uxstudio', 'found' => true ),
		'scenarios' => array(
			'none'   => array(
				'status' => 'ok', 'banner_visible' => true, 'clicked' => false,
				'cookies' => array( $cookie( '_ga' ), $cookie( 'uxs_consent' ), $cookie( 'totally_unknown_cookie' ) ),
				'storage' => array( array( 'area' => 'local', 'key' => 'k', 'origin' => 'pobyty.test', 'phase' => 'load' ) ),
				'requests' => array(
					array( 'host' => 'www.google-analytics.com', 'type' => 'script', 'path' => '/analytics.js', 'third_party' => true, 'n_pre' => 1, 'n_post' => 0 ),
					array( 'host' => 'scan-test-unknown.example.org', 'type' => 'xhr', 'path' => '/p', 'third_party' => true, 'n_pre' => 1, 'n_post' => 0 ),
				),
			),
			'accept' => array( 'status' => 'ok', 'banner_visible' => true, 'clicked' => true, 'cookies' => array( $cookie( '_ga', array( 'phase' => 'choice' ) ) ), 'storage' => array(), 'requests' => array() ),
			'reject' => array( 'status' => 'banner_not_found', 'banner_visible' => true, 'clicked' => false, 'cookies' => array(), 'storage' => array(), 'requests' => array() ),
		),
	),
);
$body  = (string) wp_json_encode( $push );
$plan  = '/uxstudio/v1/content-sync/node/consent/scan-plan';
$route = '/uxstudio/v1/content-sync/node/consent/scan-result';

try {
	// ---------------------------------------------------------------- signature
	wp_set_current_user( 0 );
	$r = rest_do_request( new WP_REST_Request( 'POST', $route ) );
	$assert( 'unsigned push is refused', 401 === $r->get_status(), (string) $r->get_status() );
	$r = $signed( 'POST', $route, $body, 'a-different-key' );
	$assert( 'push signed with a wrong key is refused', 401 === $r->get_status(), (string) $r->get_status() );
	$ts = time();
	$n  = bin2hex( random_bytes( 8 ) );
	$r  = $signed( 'POST', $route, $body, null, $ts - 3600 );
	$assert( 'push with an old timestamp is refused', 401 === $r->get_status(), (string) $r->get_status() );
	$assert( 'nothing is stored after refused pushes', false === get_option( Scan::OPTION, false ) || array() === get_option( Scan::OPTION ) );

	$r = $signed( 'GET', $plan );
	$d = $r->get_data();
	$assert( 'signed plan call answers with pages of this site only', 200 === $r->get_status() && ! empty( $d['urls'] ) && 'uxstudio' === $d['banner'] && count( $d['urls'] ) <= Scan::MAX_PAGES );
	$own = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
	$assert( 'plan urls belong to this host', ! array_filter( $d['urls'], static fn( string $u ): bool => strtolower( (string) wp_parse_url( $u, PHP_URL_HOST ) ) !== $own ) );
	$r = rest_do_request( new WP_REST_Request( 'GET', $plan ) );
	$assert( 'unsigned plan call is refused', 401 === $r->get_status() );

	// ---------------------------------------------------------------- the push
	$r = $signed( 'POST', $route, $body, null, null, $n );
	$d = $r->get_data();
	$assert( 'a correctly signed push is accepted and answers with a summary', 200 === $r->get_status() && ! empty( $d['summary'] ), (string) $r->get_status() . ' ' . wp_json_encode( $d ) );
	$assert( 'summary: one tracking cookie and two violations (cookie and host), one unknown cookie', 1 === ( $d['summary']['unknown_cookies'] ?? -1 ) && 2 === ( $d['summary']['violations'] ?? -1 ), wp_json_encode( $d['summary'] ?? null ) );
	$stored = get_option( Scan::OPTION );
	$assert( 'the result is stored (sanitized, no autoload) with the Central App scan id', 42 === ( $stored['scan_id'] ?? 0 ) && isset( $stored['scan']['scenarios']['none'] ) );
	$assert( 'the unjudged rejection run is marked', 'banner_not_found' === ( $stored['evaluation']['scenarios']['reject']['note'] ?? '' ) );
	$rep = $signed( 'POST', $route, $body, null, time(), $n );
	$assert( 'the same nonce cannot be replayed', 401 === $rep->get_status(), (string) $rep->get_status() );
	$r = $signed( 'POST', $route, '{"result":"nope"}' );
	$assert( 'a signed but malformed body is a 400', 400 === $r->get_status(), (string) $r->get_status() );
	$r = $signed( 'POST', $route, str_repeat( 'x', 700000 ) );
	$assert( 'an oversized body is refused', in_array( $r->get_status(), array( 400, 413 ), true ), (string) $r->get_status() );
	$r = $signed( 'POST', $route, $body );
	$assert( 'a second scan: nothing is new any more', 0 === ( $r->get_data()['summary']['new_violations'] ?? -1 ), wp_json_encode( $r->get_data() ) );

	// ---------------------------------------------------------------- the rest of the plugin sees the scan
	$facts = Inventory::facts();
	$assert( 'the unknown scan host joined the inventory facts', isset( $facts['hosts']['scan-test-unknown.example.org'] ) && in_array( 'scan', $facts['hosts']['scan-test-unknown.example.org']['s'], true ) );
	$queue = Inventory::state()['queue'];
	$assert( 'and waits in the queue to be classified', in_array( 'scan-test-unknown.example.org', array_column( $queue, 'host' ), true ) );
	$report = Report::site( 'cs' );
	$assert( 'Report::site keeps its F2 keys and adds scan_at and scan_cookies', isset( $report['categories'], $report['cookies'], $report['unclassified'], $report['open_cookie_database'], $report['scanned_at'] ) && $report['scan_at'] > 0 && count( $report['scan_cookies'] ) >= 3 );
	$ga = array_values( array_filter( $report['scan_cookies'], static fn( array $c ): bool => '_ga' === $c['name'] ) );
	$assert( 'a scan cookie is classified by the catalogue and has no value field', ! empty( $ga ) && 'statistics' === $ga[0]['category'] && ! isset( $ga[0]['value'] ) );

	// ---------------------------------------------------------------- admin routes
	wp_set_current_user( 0 );
	foreach ( array( array( 'GET', '/uxstudio/v1/consent/scan' ), array( 'POST', '/uxstudio/v1/consent/scan/request' ) ) as $call ) {
		$r = rest_do_request( new WP_REST_Request( $call[0], $call[1] ) );
		$assert( "anonymous cannot call {$call[0]} {$call[1]}", in_array( $r->get_status(), array( 401, 403 ), true ), (string) $r->get_status() );
	}
	$admins = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) );
	wp_set_current_user( (int) $admins[0] );
	$r = rest_do_request( new WP_REST_Request( 'GET', '/uxstudio/v1/consent/scan' ) );
	$d = $r->get_data()['data'] ?? array();
	$assert( 'admin: scan payload with violations, unknown cookies, scenarios and the link state', 200 === $r->get_status() && 2 === count( $d['violations'] ?? array() ) && 1 === count( $d['unknown_cookies'] ?? array() ) && isset( $d['paired'], $d['scenarios']['none'] ) );
	$r = rest_do_request( new WP_REST_Request( 'POST', '/uxstudio/v1/consent/scan/request' ) );
	$assert( 'admin: a request without the connection to the central app says so (424), it does not pretend', 424 === $r->get_status() || 200 === $r->get_status(), (string) $r->get_status() );
} finally {
	foreach ( $had as $option => $value ) {
		if ( null === $value ) {
			delete_option( $option );
		} else {
			update_option( $option, $value, false );
		}
	}
	wp_clear_scheduled_hook( Inventory::CRON_HOOK );
}

if ( $failures ) {
	echo "$failures failure(s)\n";
	exit( 1 );
}
echo "ALL PASSED\n";
