<?php
// Test only: never runs over HTTP (the release zip also excludes tests/).
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { exit; }
/**
 * Manual test for the restrict_rest_api() public-route allow-list.
 *
 * Run inside a WordPress install that has UX Studio active (so BaseModule,
 * Settings, ... autoload), loading THIS checkout's Module class:
 *
 *   wp eval-file tests/rest-public-routes.php --skip-plugins=wp-mail-smtp
 *
 * Exits non-zero when any case fails. Nothing is written to the database.
 *
 * @package UxStudio
 */

defined( 'ABSPATH' ) || exit;

require_once dirname( __DIR__ ) . '/includes/Core/RestRoute.php';
require_once dirname( __DIR__ ) . '/includes/Modules/SecurityOptimization/Module.php';

use UxStudio\Core\RestRoute;

// An own public POST-only route, to test method overrides against (registered on the live server).
rest_get_server();
register_rest_route( 'uxstudio/v1', '/test-public-post', array( 'methods' => 'POST', 'callback' => '__return_null', 'permission_callback' => '__return_true' ) );

$ref    = new ReflectionClass( \UxStudio\Modules\SecurityOptimization\Module::class );
$module = $ref->newInstanceWithoutConstructor();

wp_set_current_user( 0 );

/**
 * Run restrict_rest_api() for a request as an anonymous visitor.
 *
 * @param string $uri Request URI (path + query).
 * @return string 'allowed' or the WP_Error code.
 */
$check = static function ( string $uri, string $method = 'GET', array $post = array(), array $headers = array() ) use ( $module ): string {
	$parts                     = wp_parse_url( $uri );
	$_SERVER['REQUEST_URI']    = $uri;
	$_SERVER['REQUEST_METHOD'] = $method;
	unset( $_SERVER['HTTP_X_HTTP_METHOD_OVERRIDE'] );
	$_SERVER = $headers + $_SERVER;
	$_GET    = array();
	$_POST   = $post;
	if ( ! empty( $parts['query'] ) ) {
		parse_str( $parts['query'], $_GET );
	}
	$result = $module->restrict_rest_api( null );
	if ( is_wp_error( $result ) ) {
		$data = $result->get_error_data();
		return $result->get_error_code() . ' (' . ( $data['status'] ?? '?' ) . ')';
	}
	return 'allowed';
};

$fail  = 0;
$expect = static function ( string $label, string $uri, bool $allowed, string $method = 'GET', array $post = array(), array $headers = array() ) use ( $check, &$fail ): void {
	$got  = $check( $uri, $method, $post, $headers );
	$ok   = $allowed ? ( 'allowed' === $got ) : ( 'uxstudio_rest_forbidden (401)' === $got );
	$fail += $ok ? 0 : 1;
	printf( "%s  %-62s -> %s\n", $ok ? 'PASS' : 'FAIL', $label, $got );
};

echo "== defaults, restrict_rest_api on, anonymous ==\n";
$expect( 'GET /wp-json/complianz/v1/banner', '/wp-json/complianz/v1/banner', true );
$expect( 'GET /wp-json/complianz/v1/store_cookies', '/wp-json/complianz/v1/store_cookies', true );
$expect( 'GET ?rest_route=/complianz/v1/banner', '/?rest_route=/complianz/v1/banner', true );
$expect( 'GET /wp-json/wp/v2/users', '/wp-json/wp/v2/users', false );
$expect( 'GET /wp-json/wp/v2/posts', '/wp-json/wp/v2/posts', false );
$expect( 'GET /wp-json/ (index)', '/wp-json/', false );
$expect( 'GET /wp-json/complianz/v1/../wp/v2/users', '/wp-json/complianz/v1/../wp/v2/users', false );
$expect( 'GET /wp-json/complianz/v1/%2e%2e/wp/v2/users', '/wp-json/complianz/v1/%2e%2e/wp/v2/users', false );
$expect( 'GET ?rest_route=/complianz/v1/../wp/v2/users', '/?rest_route=/complianz/v1/../wp/v2/users', false );
$expect( 'GET ?rest_route=/wp/v2/users&x=/complianz/v1/', '/?rest_route=/wp/v2/users&x=/complianz/v1/', false );
$expect( 'GET /wp-json/wp/v2/users?x=/complianz/v1/', '/wp-json/wp/v2/users?x=/complianz/v1/', false );
$expect( 'GET /wp-json/complianzevil/v1/x (no boundary)', '/wp-json/complianzevil/v1/x', false );
$expect( 'GET /wp-json/complianz/v2/banner (other version)', '/wp-json/complianz/v2/banner', false );

echo "\n== WooCommerce Store API (needs WooCommerce active) ==\n";
if ( class_exists( 'WooCommerce' ) ) {
	$expect( 'GET /wp-json/wc/store/v1/cart', '/wp-json/wc/store/v1/cart', true );
	$expect( 'GET /wp-json/wc/store/v1/products?per_page=5', '/wp-json/wc/store/v1/products?per_page=5', true );
	$expect( 'POST /wp-json/wc/store/v1/cart/add-item', '/wp-json/wc/store/v1/cart/add-item', true, 'POST' );
	$expect( 'POST /wp-json/wc/store/v1/checkout', '/wp-json/wc/store/v1/checkout', true, 'POST' );
	$expect( 'POST .../wc/store/v1/checkout + X-HTTP-Method-Override: PUT', '/wp-json/wc/store/v1/checkout', true, 'POST', array(), array( 'HTTP_X_HTTP_METHOD_OVERRIDE' => 'PUT' ) );
	$expect( 'POST /wp-json/wc/store/v1/batch', '/wp-json/wc/store/v1/batch', true, 'POST' );
	$expect( 'GET /wp-json/wc/store/cart (legacy, unversioned)', '/wp-json/wc/store/cart', true );
	$expect( 'GET ?rest_route=/wc/store/v1/cart', '/?rest_route=/wc/store/v1/cart', true );
	$expect( 'GET /wp-json/wc/v3/orders (admin API)', '/wp-json/wc/v3/orders', false );
	$expect( 'GET /wp-json/wc/v3/customers (admin API)', '/wp-json/wc/v3/customers', false );
	$expect( 'GET /wp-json/wc-analytics/reports', '/wp-json/wc-analytics/reports', false );
	$expect( 'GET /wp-json/wc/storex/v1/cart (no boundary)', '/wp-json/wc/storex/v1/cart', false );
	$expect( 'GET /wp-json/wc/store-admin/x (no boundary)', '/wp-json/wc/store-admin/x', false );
	$expect( 'GET /wp-json/wc/store (namespace index, no slash)', '/wp-json/wc/store', false );
	$expect( 'GET /wp-json/wc/store/v1/../../v3/orders', '/wp-json/wc/store/v1/../../v3/orders', false );
	$expect( 'GET /wp-json/wc/store/%2e%2e/v3/orders', '/wp-json/wc/store/%2e%2e/v3/orders', false );
	$expect( 'GET /wp-json/wc/store%2F..%2Fv3/orders', '/wp-json/wc/store%2F..%2Fv3/orders', false );
	$expect( 'POST store route with body rest_route=/wc/v3/orders', '/wp-json/wc/store/v1/cart', false, 'POST', array( 'rest_route' => '/wc/v3/orders' ) );
	$expect( 'GET ?rest_route=/wc/v3/orders&x=/wc/store/v1/', '/?rest_route=/wc/v3/orders&x=/wc/store/v1/', false );
} else {
	echo "SKIP  WooCommerce not active\n";
}

echo "\n== own public endpoints (permission_callback __return_true) ==\n";
rest_get_server();
$expect( 'GET /wp-json/uxstudio/v1/shield/challenge (Shield CAPTCHA puzzle)', '/wp-json/uxstudio/v1/shield/challenge', true );
$expect( 'GET ?rest_route=/uxstudio/v1/shield/challenge', '/?rest_route=/uxstudio/v1/shield/challenge', true );
$expect( 'GET /wp-json/uxstudio/v1/shield/challenge/x (no such route)', '/wp-json/uxstudio/v1/shield/challenge/x', false );
$expect( 'GET /wp-json/uxstudio/v1/shield/other (no such route)', '/wp-json/uxstudio/v1/shield/other', false );
$expect( 'GET /wp-json/uxstudio/v1/shield/challenge/../../wp/v2/users (dot segments)', '/wp-json/uxstudio/v1/shield/challenge/../../wp/v2/users', false );
$expect( 'GET /wp-json/uxstudio/v1/settings/security-optimization (admin route)', '/wp-json/uxstudio/v1/settings/security-optimization', false );

echo "\n== the route and method WordPress really dispatches ==\n";
$expect( 'POST public route with body rest_route=/wp/v2/users (WP prefers POST)', '/wp-json/complianz/v1/banner', false, 'POST', array( 'rest_route' => '/wp/v2/users' ) );
$expect( 'GET ?rest_route=complianz + POST rest_route=/wp/v2/users (mismatch)', '/?rest_route=/complianz/v1/banner', false, 'POST', array( 'rest_route' => '/wp/v2/users' ) );
$expect( 'POST own public POST route', '/wp-json/uxstudio/v1/test-public-post', true, 'POST' );
$expect( 'POST own public POST route + X-HTTP-Method-Override: GET', '/wp-json/uxstudio/v1/test-public-post', false, 'POST', array(), array( 'HTTP_X_HTTP_METHOD_OVERRIDE' => 'GET' ) );
$expect( 'POST own public POST route ?_method=DELETE', '/wp-json/uxstudio/v1/test-public-post?_method=DELETE', false, 'POST' );
$expect( 'U3: override GET + body rest_route=/wp/v2/users', '/wp-json/uxstudio/v1/test-public-post', false, 'POST', array( 'rest_route' => '/wp/v2/users' ), array( 'HTTP_X_HTTP_METHOD_OVERRIDE' => 'GET' ) );
$expect( 'GET own public GET route + override POST', '/wp-json/uxstudio/v1/shield/challenge', false, 'GET', array(), array( 'HTTP_X_HTTP_METHOD_OVERRIDE' => 'POST' ) );

echo "\n== recheck on the dispatched WP_REST_Request (rest_pre_dispatch) ==\n";
$served = static function ( string $uri, WP_REST_Request $request, string $method = 'GET' ) use ( $module, $check ): string {
	RestRoute::reset();
	$early = $check( $uri, $method );
	if ( 'allowed' !== $early ) {
		return 'early ' . $early;
	}
	RestRoute::arm( null );
	RestRoute::capture( null, rest_get_server(), $request );
	$result = $module->recheck_rest_lock( null, rest_get_server(), $request );
	RestRoute::reset();
	return is_wp_error( $result ) ? $result->get_error_code() : 'allowed';
};
$row = static function ( string $label, string $got, string $want ) use ( &$fail ): void {
	$ok    = $want === $got;
	$fail += $ok ? 0 : 1;
	printf( "%s  %-62s -> %s\n", $ok ? 'PASS' : 'FAIL', $label, $got );
};
$same = new WP_REST_Request( 'GET', '/complianz/v1/banner' );
$row( 'served request = the public route', $served( '/wp-json/complianz/v1/banner', $same ), 'allowed' );
$row( 'served request differs from the raw URL -> refused', $served( '/wp-json/complianz/v1/banner', new WP_REST_Request( 'GET', '/wp/v2/users' ) ), 'uxstudio_rest_forbidden' );
if ( class_exists( 'WooCommerce' ) ) {
	$row( 'served Store API checkout POST', $served( '/wp-json/wc/store/v1/checkout', new WP_REST_Request( 'POST', '/wc/store/v1/checkout' ), 'POST' ), 'allowed' );
	$override = new WP_REST_Request( 'POST', '/wc/store/v1/cart/update-customer' );
	$override->set_method( 'PUT' );
	$row( 'served Store API with method overridden to PUT', $served( '/wp-json/wc/store/v1/cart/update-customer', $override, 'POST' ), 'allowed' );
	$row( 'served /wc/v3 behind a Store API URL -> refused', $served( '/wp-json/wc/store/v1/cart', new WP_REST_Request( 'GET', '/wc/v3/orders' ) ), 'uxstudio_rest_forbidden' );

	// Sub-requests of a Store API batch must be public on their own.
	$batch = new WP_REST_Request( 'POST', '/wc/store/v1/batch' );
	RestRoute::reset();
	$check( '/wp-json/wc/store/v1/batch', 'POST' );
	RestRoute::arm( null );
	RestRoute::capture( null, rest_get_server(), $batch );
	$outer   = $module->recheck_rest_lock( null, rest_get_server(), $batch );
	$sub_ok  = $module->recheck_rest_lock( null, rest_get_server(), new WP_REST_Request( 'POST', '/wc/store/v1/cart/add-item' ) );
	$sub_bad = $module->recheck_rest_lock( null, rest_get_server(), new WP_REST_Request( 'GET', '/wc/storex/v1/x' ) );
	$sub_v3  = $module->recheck_rest_lock( null, rest_get_server(), new WP_REST_Request( 'GET', '/wc/v3/orders' ) );
	RestRoute::reset();
	$row( 'served Store API batch', null === $outer ? 'allowed' : 'blocked', 'allowed' );
	$row( 'batch sub-request /wc/store/v1/cart/add-item', null === $sub_ok ? 'allowed' : 'blocked', 'allowed' );
	$row( 'batch sub-request /wc/storex/v1/x -> refused', is_wp_error( $sub_bad ) ? 'blocked' : 'allowed', 'blocked' );
	$row( 'batch sub-request /wc/v3/orders -> refused', is_wp_error( $sub_v3 ) ? 'blocked' : 'allowed', 'blocked' );
	// The next request starts clean (no batch state left over).
	$check( '/wp-json/complianz/v1/banner' );
	$after = $module->recheck_rest_lock( null, rest_get_server(), new WP_REST_Request( 'GET', '/wp/v2/users' ) );
	$row( 'batch state reset by the next request', null === $after ? 'untouched' : 'blocked', 'untouched' );
}
$row( 'served method GET on a POST-only public route -> refused', $served( '/wp-json/uxstudio/v1/test-public-post', new WP_REST_Request( 'GET', '/uxstudio/v1/test-public-post' ), 'POST' ), 'uxstudio_rest_forbidden' );
RestRoute::reset();
$check( '/wp-json/complianz/v1/banner' );
RestRoute::arm( null );
RestRoute::capture( null, rest_get_server(), $same );
$module->recheck_rest_lock( null, rest_get_server(), $same );
$inner = $module->recheck_rest_lock( null, rest_get_server(), new WP_REST_Request( 'GET', '/wp/v2/users' ) );
RestRoute::reset();
$row( 'an internal sub-request after the served one is left alone', null === $inner ? 'untouched' : 'blocked', 'untouched' );
$_POST = array();
unset( $_SERVER['HTTP_X_HTTP_METHOD_OVERRIDE'] );

echo "\n== filter uxstudio_rest_public_routes still works ==\n";
$add = static function ( array $routes ): array {
	$routes[] = '/contact-form-7/v1/';
	return $routes;
};
add_filter( 'uxstudio_rest_public_routes', $add );
$expect( 'filter adds /contact-form-7/v1/ -> allowed', '/wp-json/contact-form-7/v1/contact-forms/1/feedback', true );
$expect( 'filter keeps complianz default', '/wp-json/complianz/v1/banner', true );
$expect( 'filter does not open core', '/wp-json/wp/v2/users', false );
remove_filter( 'uxstudio_rest_public_routes', $add );
$expect( 'filter removed -> cf7 open only by default with CF7 active', '/wp-json/contact-form-7/v1/contact-forms/1/feedback', defined( 'WPCF7_VERSION' ) );
$expect( 'GET /wp-json/oembed/1.0/embed (author data, stays locked)', '/wp-json/oembed/1.0/embed?url=' . rawurlencode( home_url( '/' ) ), false );

if ( class_exists( 'WooCommerce' ) ) {
	$drop_store = static function ( array $routes ): array {
		return array_values( array_diff( $routes, array( '/wc/store/' ) ) );
	};
	add_filter( 'uxstudio_rest_public_routes', $drop_store );
	$expect( 'filter can remove the Store API default', '/wp-json/wc/store/v1/cart', false );
	remove_filter( 'uxstudio_rest_public_routes', $drop_store );
}

$drop = static function ( array $routes ): array {
	return array_values( array_diff( $routes, array( '/complianz/v1/' ) ) );
};
add_filter( 'uxstudio_rest_public_routes', $drop );
$expect( 'filter can remove the complianz default', '/wp-json/complianz/v1/banner', false );
remove_filter( 'uxstudio_rest_public_routes', $drop );

echo $fail ? "\n$fail FAILED\n" : "\nAll passed\n";
exit( $fail ? 1 : 0 );
