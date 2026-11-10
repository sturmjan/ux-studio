<?php
// Test only: never runs over HTTP (the release zip also excludes tests/).
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { exit; }
/**
 * Manual test of the consent module inside WordPress (PLAN.md 42, F2): the inventory run against the real site, the REST
 * routes and their permissions, the own-rule round trip, the banner config size and the public report.
 *
 * Loads the classes of THIS checkout (not the installed plugin), so run it with the installed UX Studio skipped:
 *
 *   php wp-cli.phar --path=<site> --skip-plugins=ux-studio,wp-mail-smtp eval-file tests/consent-wp.php </dev/null
 *
 * It reads the site's own pages (a few GET requests to the site itself) and temporarily writes two options
 * (uxstudio_consent, uxstudio_consent_inventory), which are restored or deleted at the end.
 * Exits non-zero when any case fails.
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

use UxStudio\Modules\Consent\Cookies;
use UxStudio\Modules\Consent\Inventory;
use UxStudio\Modules\Consent\Module;
use UxStudio\Modules\Consent\Report;

$failures = 0;
$assert   = static function ( string $name, bool $ok, string $detail = '' ) use ( &$failures ): void {
	if ( ! $ok ) {
		++$failures;
	}
	echo ( $ok ? 'PASS ' : 'FAIL ' ) . $name . ( $ok || '' === $detail ? '' : ' => ' . $detail ) . "\n";
};

$had_settings  = get_option( 'uxstudio_consent', null );
$had_inventory = get_option( Inventory::OPTION, null );
delete_option( 'uxstudio_consent' );
delete_option( Inventory::OPTION );

$meta   = json_decode( (string) file_get_contents( UXSTUDIO_PATH . 'includes/Modules/Consent/meta.json' ), true );
$module = new Module( 'consent', $meta );
$module->boot();
// Build the REST server again so rest_api_init runs now, after the module registered its routes.
$GLOBALS['wp_rest_server'] = null;
$server = rest_get_server();

try {
	// ---------------------------------------------------------------- run
	$sample = Inventory::sample_urls();
	$assert( 'sample pages: home first, only this site, at most 10', ! empty( $sample ) && trailingslashit( home_url() ) === trailingslashit( $sample[0] ) && count( $sample ) <= 10 );
	$t     = microtime( true );
	$facts = Inventory::run();
	echo sprintf( "INFO run: %d page(s) read in %.1f s, %d external host(s)\n", count( $facts['pages'] ), microtime( true ) - $t, count( $facts['hosts'] ) );
	$assert( 'run: pages were read and the facts are stored', count( $facts['pages'] ) > 0 && is_array( get_option( Inventory::OPTION ) ) );
	$state = Inventory::state();
	echo 'INFO services: ' . implode( ', ', array_keys( $state['services'] ) ) . "\n";
	echo 'INFO queue: ' . implode( ', ', array_column( $state['queue'], 'host' ) ) . "\n";
	echo 'INFO known (no consent): ' . implode( ', ', array_column( $state['known'], 'host' ) ) . "\n";
	$assert( 'run: no host of the site itself is listed', ! isset( $facts['hosts'][ strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) ) ] ) );

	// ---------------------------------------------------------------- permissions
	wp_set_current_user( 0 );
	foreach ( array( array( 'GET', '/uxstudio/v1/consent/inventory' ), array( 'POST', '/uxstudio/v1/consent/inventory/scan' ), array( 'POST', '/uxstudio/v1/consent/inventory/assign' ) ) as $call ) {
		$request = new WP_REST_Request( $call[0], $call[1] );
		$request->set_body_params( array( 'host' => 'cdn.example.org', 'category' => 'marketing' ) );
		$r = rest_do_request( $request );
		$assert( "anonymous cannot call {$call[0]} {$call[1]}", in_array( $r->get_status(), array( 401, 403 ), true ), (string) $r->get_status() );
	}
	$editor = get_users( array( 'role' => 'editor', 'number' => 1, 'fields' => 'ID' ) );
	if ( $editor ) {
		wp_set_current_user( (int) $editor[0] );
		$r = rest_do_request( new WP_REST_Request( 'GET', '/uxstudio/v1/consent/inventory' ) );
		$assert( 'an editor cannot read the inventory', 403 === $r->get_status(), (string) $r->get_status() );
	}

	// ---------------------------------------------------------------- public cookie route
	wp_set_current_user( 0 );
	$req = new WP_REST_Request( 'GET', '/uxstudio/v1/consent/cookies' );
	$req->set_query_params( array( 's' => 'Google Analytics|Hotjar|<script>|No Such Service|Instagram' ) );
	$r = rest_do_request( $req );
	$d = $r->get_data();
	$assert( 'public cookies: answers anonymously, only catalogue services', 200 === $r->get_status() && array( 'Google Analytics', 'Hotjar', 'Instagram' ) === array_keys( $d ), (string) $r->get_status() . ' ' . implode( ',', array_keys( (array) $d ) ) );
	$assert( 'public cookies: compact rows [name, purpose, duration]', ! empty( $d['Google Analytics']['k'][0] ) && 3 === count( $d['Google Analytics']['k'][0] ) && '_ga' === $d['Google Analytics']['k'][0][0] );
	$assert( 'public cookies: an OCD-backed service has rows too', ! empty( $d['Instagram']['k'] ) );
	$assert( 'public cookies: cacheable header', false !== strpos( (string) ( $r->get_headers()['Cache-Control'] ?? '' ), 'public' ) );
	$req = new WP_REST_Request( 'GET', '/uxstudio/v1/consent/cookies' );
	$r   = rest_do_request( $req );
	$assert( 'public cookies: the s parameter is required', 400 === $r->get_status(), (string) $r->get_status() );
	$many = new WP_REST_Request( 'GET', '/uxstudio/v1/consent/cookies' );
	$many->set_query_params( array( 's' => implode( '|', array_fill( 0, 200, 'Hotjar' ) ) . '|YouTube' ) );
	$assert( 'public cookies: duplicate and excess labels are limited', count( rest_do_request( $many )->get_data() ) <= 40 );

	// ---------------------------------------------------------------- admin routes and the rule round trip
	$admins = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) );
	wp_set_current_user( (int) $admins[0] );
	$r = rest_do_request( new WP_REST_Request( 'GET', '/uxstudio/v1/consent/inventory' ) );
	$d = $r->get_data()['data'] ?? array();
	$assert( 'admin inventory: payload has services, queue, decisions, OCD provenance', 200 === $r->get_status() && isset( $d['services'], $d['queue'], $d['decided'], $d['ocd']['license'] ) && 'Apache-2.0' === $d['ocd']['license'] );
	$necessary = array_column( array_filter( $d['services'], static fn( array $s ): bool => 'necessary' === $s['category'] ), 'label' );
	$assert( 'admin inventory: own necessary services are listed', in_array( 'WordPress', $necessary, true ) && in_array( 'Cookie consent', $necessary, true ) );

	$bad = new WP_REST_Request( 'POST', '/uxstudio/v1/consent/inventory/assign' );
	$bad->set_body_params( array( 'host' => 'evil.example.org', 'category' => 'marketing' ) );
	$assert( 'assign: a host that is not in the inventory is refused', 404 === rest_do_request( $bad )->get_status() );
	$bad = new WP_REST_Request( 'POST', '/uxstudio/v1/consent/inventory/assign' );
	$bad->set_body_params( array( 'host' => 'x.example.org', 'category' => 'everything' ) );
	$assert( 'assign: an unknown category is refused', 400 === rest_do_request( $bad )->get_status() );

	if ( ! empty( $d['queue'] ) ) {
		$host = $d['queue'][0]['host'];
		$req  = new WP_REST_Request( 'POST', '/uxstudio/v1/consent/inventory/assign' );
		$req->set_body_params( array( 'host' => $host, 'category' => 'marketing' ) );
		$r = rest_do_request( $req );
		$d = $r->get_data()['data'] ?? array();
		$assert( "assign: $host leaves the queue", 200 === $r->get_status() && ! in_array( $host, array_column( $d['queue'], 'host' ), true ) );
		$assert( 'assign: it is stored as an own rule that the Blocker reads', false !== strpos( (string) get_option( 'uxstudio_consent' )['custom_rules'], $host . ' marketing' ) );
		$blocked = \UxStudio\Modules\Consent\Services::classify( 'https://' . $host . '/x.js', 'example.test', \UxStudio\Modules\Consent\Services::parse_rules( (string) get_option( 'uxstudio_consent' )['custom_rules'] ) );
		$assert( 'assign: Services::classify now says marketing for that host', null !== $blocked && 'marketing' === $blocked['category'] );
		$cfg = $module->frontend_config();
		$assert( 'assign: the banner lists it under marketing', in_array( $host, $cfg['c'][3]['s'], true ), json_encode( $cfg['c'][3]['s'] ) );
		$req = new WP_REST_Request( 'POST', '/uxstudio/v1/consent/inventory/assign' );
		$req->set_body_params( array( 'host' => $host, 'category' => 'remove' ) );
		$r = rest_do_request( $req );
		$d = $r->get_data()['data'] ?? array();
		$assert( 'assign: remove puts it back in the queue', in_array( $host, array_column( $d['queue'], 'host' ), true ) && false === strpos( (string) ( get_option( 'uxstudio_consent' )['custom_rules'] ?? '' ), $host ) );
	} else {
		echo "SKIP queue is empty on this site, the rule round trip was not run\n";
	}

	// ---------------------------------------------------------------- banner config stays small
	$cfg  = $module->frontend_config();
	$json = (string) wp_json_encode( $cfg, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
	echo 'INFO banner config: ' . strlen( $json ) . " bytes\n";
	$assert( 'banner config carries service names only, no cookie rows', false === strpos( $json, '_ga_' ) && false === strpos( $json, 'wordpress_logged_in' ) && ! empty( $cfg['ck'] ) );
	$assert( 'banner config: necessary category lists the site\'s own cookies as services', in_array( 'WordPress', $cfg['c'][0]['s'], true ) && in_array( 'Cookie consent', $cfg['c'][0]['s'], true ) );
	$assert( 'banner config stays under 8 kB', strlen( $json ) < 8192 );

	// ---------------------------------------------------------------- public report (for F3 and F4)
	$report = Report::site( 'cs' );
	$assert( 'report: four categories, Czech, with the necessary cookies', array( 'necessary', 'preferences', 'statistics', 'marketing' ) === array_keys( $report['categories'] ) && 'cs' === $report['language'] && ! empty( array_filter( $report['cookies'], static fn( array $c ): bool => 'uxs_consent' === $c['name'] ) ) );
	$assert( 'report: flat cookie list matches the services', count( $report['cookies'] ) === array_sum( array_map( static fn( array $list ): int => array_sum( array_map( static fn( array $s ): int => count( $s['cookies'] ), $list ) ), $report['categories'] ) ) );
	echo 'INFO report: ' . count( $report['cookies'] ) . " cookies, unclassified: " . implode( ', ', $report['unclassified'] ) . "\n";
} finally {
	// Put the options back as they were.
	if ( null === $had_settings ) {
		delete_option( 'uxstudio_consent' );
	} else {
		update_option( 'uxstudio_consent', $had_settings );
	}
	if ( null === $had_inventory ) {
		delete_option( Inventory::OPTION );
	} else {
		update_option( Inventory::OPTION, $had_inventory, false );
	}
	wp_clear_scheduled_hook( Inventory::CRON_HOOK );
}

if ( $failures ) {
	echo "$failures failure(s)\n";
	exit( 1 );
}
echo "ALL PASSED\n";
