<?php
// Test only: never runs over HTTP (the release zip also excludes tests/).
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { exit; }
/**
 * Consent records (PLAN.md 42, F5) against a real WordPress: the table, the public endpoint behind the REST lock,
 * validation, the limits, the admin routes, the CSV and the retention purge.
 *
 *   cd D:\xampp\htdocs\pobyty
 *   php wp-cli.phar --path=D:\xampp\htdocs\pobyty --skip-plugins=wp-mail-smtp eval-file <this file> </dev/null
 *
 * The classes of the module are loaded from THIS checkout (the installed plugin may be another branch); the core
 * classes (DB, Retention, Settings, Csv, Guard) come from the installed plugin. The test creates the table, uses it
 * and DROPS it again, deletes the transients it caused, and refuses to run when the table already exists. It does not
 * touch any option.
 *
 * @package UxStudio
 */

defined( 'ABSPATH' ) || exit;

use UxStudio\Core\RestRoute;
use UxStudio\Modules\Consent\Records;
use UxStudio\Modules\Consent\RecordsRest;

$wt = dirname( __DIR__ ) . '/includes/Modules/Consent/';
foreach ( array( 'Records', 'RecordsRest', 'RecordsWidget', 'Geo' ) as $file ) {
	require_once $wt . $file . '.php';
}

global $wpdb;
$table = Records::table();
if ( Records::table_exists() ) {
	WP_CLI::error( "Table $table already exists: refusing to run (it would be dropped)." );
}

$failures = 0;
$assert   = static function ( string $name, bool $ok, string $detail = '' ) use ( &$failures ): void {
	if ( ! $ok ) {
		++$failures;
	}
	WP_CLI::log( ( $ok ? 'PASS ' : 'FAIL ' ) . $name . ( $ok || '' === $detail ? '' : ' => ' . $detail ) );
};

$cleanup = static function () use ( $wpdb, $table ): void {
	$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s", '\_transient\_uxstudio\_cr\_%', '\_transient\_timeout\_uxstudio\_cr\_%', '\_transient\_uxstudio\_bt\_guard\_%', '\_transient\_timeout\_uxstudio\_bt\_guard\_%' ) ); // phpcs:ignore
	wp_cache_flush();
};
register_shutdown_function( $cleanup );

try {
	Records::migrate( 0 );
	$assert( 'table created by migrate()', Records::table_exists() );
	$columns = $wpdb->get_col( "SHOW COLUMNS FROM {$table}", 0 ); // phpcs:ignore
	$assert( 'columns are exactly the intended ones', array( 'id', 'consent_id', 'created_at', 'policy_version', 'categories', 'action', 'page_path' ) === $columns, implode( ',', $columns ) );
	$assert( 'no IP and no user agent column', ! preg_grep( '/ip|agent|ua$/i', $columns ) );

	// --- the REST lock: the route is public, the admin routes are not ---
	add_action( 'rest_api_init', array( RecordsRest::class, 'register' ) );
	add_action( 'rest_api_init', array( \UxStudio\Modules\Consent\Geo::class, 'register_routes' ) );
	$lock = ( new ReflectionClass( \UxStudio\Modules\SecurityOptimization\Module::class ) )->newInstanceWithoutConstructor();
	if ( method_exists( $lock, 'register_rest_lock' ) ) {
		$lock->register_rest_lock();
	} else {
		add_filter( 'rest_authentication_errors', array( $lock, 'restrict_rest_api' ), 99 ); // The installed plugin is an older branch: same filter, older wiring.
	}
	wp_set_current_user( 0 );
	$server = rest_get_server();
	if ( ! isset( $server->get_routes( 'uxstudio/v1' )['/uxstudio/v1/consent/record'] ) ) {
		// The server was built before the hooks above (another plugin asked for it): register straight away.
		RecordsRest::register();
		\UxStudio\Modules\Consent\Geo::register_routes();
	}

	$has_route = class_exists( RestRoute::class );
	$auth      = static function ( string $uri, string $method ) use ( $server, $has_route ): string {
		if ( $has_route ) {
			RestRoute::reset();
		}
		$_SERVER['REQUEST_URI']    = $uri;
		$_SERVER['REQUEST_METHOD'] = $method;
		$_GET                      = array();
		unset( $GLOBALS['wp']->query_vars['rest_route'] );
		$route = $has_route ? RestRoute::requested() : preg_replace( '#^/wp-json#', '', $uri );
		if ( '' !== $route ) {
			$GLOBALS['wp']->query_vars['rest_route'] = $route;
		}
		$result = $server->check_authentication();
		if ( $has_route ) {
			RestRoute::reset();
		}
		return is_wp_error( $result ) ? $result->get_error_code() : 'allowed';
	};
	$assert( 'lock: POST consent/record passes for a visitor', 'allowed' === $auth( '/wp-json/uxstudio/v1/consent/record', 'POST' ), $auth( '/wp-json/uxstudio/v1/consent/record', 'POST' ) );
	$assert( 'lock: GET consent/region passes (the F1 endpoint, same mechanism)', 'allowed' === $auth( '/wp-json/uxstudio/v1/consent/region', 'GET' ) );
	$assert( 'lock: GET consent/records/summary is refused for a visitor', 'allowed' !== $auth( '/wp-json/uxstudio/v1/consent/records/summary', 'GET' ), $auth( '/wp-json/uxstudio/v1/consent/records/summary', 'GET' ) );
	$assert( 'lock: GET consent/records/find is refused for a visitor', 'allowed' !== $auth( '/wp-json/uxstudio/v1/consent/records/find', 'GET' ) );

	// --- the public endpoint ---
	$id   = bin2hex( random_bytes( 16 ) );
	$post = static function ( array $params, ?string $body = null ) use ( $server ): WP_REST_Response {
		$request = new WP_REST_Request( 'POST', '/uxstudio/v1/consent/record' );
		$request->set_body_params( $params );
		if ( null !== $body ) {
			$request->set_body( $body );
		}
		return rest_ensure_response( $server->dispatch( $request ) );
	};
	$good = array( 'id' => $id, 'v' => '2', 'c' => '7', 'a' => 'accept_all', 'p' => '/kontakt/?utm=zzz' );
	$res  = $post( $good );
	$assert( 'POST: 204 and no body', 204 === $res->get_status() && null === $res->get_data(), (string) $res->get_status() );
	$rows = $wpdb->get_results( "SELECT * FROM {$table}", ARRAY_A ); // phpcs:ignore
	$assert( 'POST: one row with the clean values', 1 === count( $rows ) && $rows[0]['consent_id'] === $id && '2' === $rows[0]['policy_version'] && '7' === $rows[0]['categories'] && 'accept_all' === $rows[0]['action'] && '/kontakt/' === $rows[0]['page_path'], wp_json_encode( $rows ) );
	$assert( 'POST: the time is UTC and current', isset( $rows[0] ) && abs( strtotime( $rows[0]['created_at'] . ' UTC' ) - time() ) < 10, (string) ( $rows[0]['created_at'] ?? '' ) );
	$assert( 'POST: nothing of the query string was stored', false === strpos( wp_json_encode( $rows ), 'utm' ) && false === strpos( wp_json_encode( $rows ), 'zzz' ) );
	$post( $good );
	$assert( 'POST: the same decision again within minutes is stored once', 1 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ) ); // phpcs:ignore
	$res = $post( array( 'id' => $id, 'v' => '2', 'c' => '0', 'a' => 'withdraw', 'p' => '/' ) );
	$assert( 'POST: a later change of the same id adds a row', 204 === $res->get_status() && 2 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ) ); // phpcs:ignore

	$bad = static fn( array $patch ): int => $post( array_merge( $good, array( 'id' => bin2hex( random_bytes( 16 ) ) ), $patch ) )->get_status();
	$assert( 'POST: bad id -> 400', 400 === $bad( array( 'id' => 'nope' ) ) );
	$assert( 'POST: unknown action -> 400', 400 === $bad( array( 'a' => 'region' ) ) );
	$assert( 'POST: bits out of range -> 400', 400 === $bad( array( 'c' => '9', 'a' => 'custom' ) ) );
	$assert( 'POST: version 0 -> 400', 400 === $bad( array( 'v' => '0' ) ) );
	$assert( 'POST: accept all with 3 bits -> 400', 400 === $bad( array( 'c' => '3' ) ) );
	$assert( 'POST: missing action -> 400', 400 === $post( array( 'id' => bin2hex( random_bytes( 16 ) ), 'v' => 1, 'c' => 0 ) )->get_status() );
	$assert( 'POST: oversized body -> 413', 413 === $post( array_merge( $good, array( 'id' => bin2hex( random_bytes( 16 ) ) ) ), str_repeat( 'x', 2000 ) )->get_status() );
	$assert( 'POST: nothing invalid was stored', 2 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ) ); // phpcs:ignore
	$assert( 'POST: a full URL as path is dropped, record kept without path', 204 === $post( array( 'id' => bin2hex( random_bytes( 16 ) ), 'v' => 1, 'c' => 0, 'a' => 'reject_all', 'p' => 'https://evil.example/' ) )->get_status() && null === $wpdb->get_var( "SELECT page_path FROM {$table} ORDER BY id DESC LIMIT 1" ) ); // phpcs:ignore

	// --- limits ---
	$many = bin2hex( random_bytes( 16 ) );
	$codes = array();
	for ( $i = 0; $i < 10; $i++ ) {
		$codes[] = $post( array( 'id' => $many, 'v' => 1, 'c' => $i % 8, 'a' => 'custom' ) )->get_status();
	}
	$assert( 'limit per id: the first 8 pass, the rest get 429', array_slice( $codes, 0, 8 ) === array_fill( 0, 8, 204 ) && 429 === $codes[8] && 429 === $codes[9], implode( ',', $codes ) );
	$assert( 'limit per id: refused ones are not stored', 8 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE consent_id = %s", $many ) ) ); // phpcs:ignore

	delete_transient( 'uxstudio_cr_site' );
	add_filter( 'uxstudio_consent_record_cap', static fn() => 10 );
	$codes = array();
	for ( $i = 0; $i < 14; $i++ ) {
		$codes[] = $post( array( 'id' => bin2hex( random_bytes( 16 ) ), 'v' => 1, 'c' => 0, 'a' => 'reject_all' ) )->get_status();
	}
	$assert( 'site cap per minute: after the cap new ids get 429', in_array( 429, $codes, true ) && 204 === $codes[0], implode( ',', $codes ) );
	remove_all_filters( 'uxstudio_consent_record_cap' );

	// --- admin routes ---
	$admins = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) );
	wp_set_current_user( (int) $admins[0] );
	$get = static function ( string $path, array $params = array() ) use ( $server ): WP_REST_Response {
		$request = new WP_REST_Request( 'GET', $path );
		$request->set_query_params( $params );
		return rest_ensure_response( $server->dispatch( $request ) );
	};
	$res = $get( '/uxstudio/v1/consent/records/summary', array( 'days' => 30 ) );
	$sum = $res->get_data()['data'] ?? array();
	$assert( 'summary: counts per action and the total add up', 200 === $res->get_status() && $sum['total'] === array_sum( $sum['actions'] ) && $sum['total'] >= 12 && $sum['actions']['withdraw'] >= 1 && $sum['visitors'] >= 2, wp_json_encode( $sum ) );
	$assert( 'summary: retention shown is the default 3 years', 1095 === $sum['retention'] );
	$res = $get( '/uxstudio/v1/consent/records/find', array( 'id' => strtoupper( implode( '-', str_split( $id, 8 ) ) ) ) );
	$list = $res->get_data()['data'] ?? array();
	$assert( 'find: an id typed with dashes and capitals finds both records, oldest first', 2 === count( $list ) && 'accept_all' === $list[0]['action'] && 'withdraw' === $list[1]['action'] && true === $list[0]['marketing'] && false === $list[1]['marketing'], wp_json_encode( $list ) );
	$assert( 'find: a bad id -> 400', 400 === $get( '/uxstudio/v1/consent/records/find', array( 'id' => 'abc' ) )->get_status() );
	$assert( 'find: an unknown id -> empty list', array() === ( $get( '/uxstudio/v1/consent/records/find', array( 'id' => str_repeat( '0', 32 ) ) )->get_data()['data'] ?? null ) );
	$res = $get( '/uxstudio/v1/consent/records/export-url', array( 'from' => '2026-01-01', 'to' => 'junk', 'id' => $id ) );
	$url = (string) ( $res->get_data()['data']['url'] ?? '' );
	$assert( 'export-url: nonce-signed admin-post link, a bad date is left out', false !== strpos( $url, 'action=uxstudio_consent_export' ) && false !== strpos( $url, '_wpnonce=' ) && false !== strpos( $url, 'from=2026-01-01' ) && false === strpos( $url, 'junk' ) && false !== strpos( $url, 'id=' . $id ), $url );
	$assert( 'export-url: a bad id -> 400', 400 === $get( '/uxstudio/v1/consent/records/export-url', array( 'id' => 'zz' ) )->get_status() );

	// --- CSV ---
	$out = fopen( 'php://memory', 'w+' );
	$n   = Records::stream_csv( $out, '', '', $id );
	rewind( $out );
	$lines = array();
	while ( false !== ( $line = fgetcsv( $out ) ) ) { $lines[] = $line; }
	$assert( 'csv: header plus the two records of that id', 2 === $n && 3 === count( $lines ) && $id === $lines[1][1] && 'yes' === $lines[1][6] && 'no' === $lines[2][6], wp_json_encode( $lines ) );

	// --- retention ---
	$wpdb->insert( $table, array( 'consent_id' => str_repeat( 'a', 32 ), 'created_at' => gmdate( 'Y-m-d H:i:s', time() - 1100 * DAY_IN_SECONDS ), 'policy_version' => 1, 'categories' => 0, 'action' => 'reject_all' ) );
	$wpdb->insert( $table, array( 'consent_id' => str_repeat( 'b', 32 ), 'created_at' => gmdate( 'Y-m-d H:i:s', time() - 1000 * DAY_IN_SECONDS ), 'policy_version' => 1, 'categories' => 0, 'action' => 'reject_all' ) );
	$deleted = Records::purge();
	$assert( 'purge: deletes only the record older than 3 years', 1 === $deleted && 0 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE consent_id = '" . str_repeat( 'a', 32 ) . "'" ) && 1 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE consent_id = '" . str_repeat( 'b', 32 ) . "'" ), (string) $deleted ); // phpcs:ignore
} finally {
	$cleanup();
}

$assert( 'cleanup: the table is gone', ! Records::table_exists() );
if ( $failures > 0 ) {
	WP_CLI::error( $failures . ' FAILED' );
}
WP_CLI::success( 'ALL OK' );
