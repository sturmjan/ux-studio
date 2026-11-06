<?php
// Test only: never runs over HTTP (the release zip also excludes tests/).
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { exit; }
/**
 * Manual test of the "REST only for logged-in users" lock (public-route allow-list, the check of
 * the dispatched request, batch sub-requests) and of the MCP token route binding.
 *
 * Run inside a WordPress install with THIS checkout's classes loaded (autoloader), e.g.:
 *
 *   wp eval-file tests/rest-public-routes.php --skip-plugins=wp-mail-smtp
 *
 * Every request goes through the real REST server: WP_REST_Server::check_authentication() (all
 * rest_authentication_errors filters) and dispatch(). Filters like the ones of ACF (rest_pre_dispatch
 * returning null on every request) and of the WooCommerce Store API (rest_authentication_errors
 * returning true for /wc/store/) are registered, so a check they can undo fails here.
 *
 * Exits non-zero when any case fails. Nothing is written to the database.
 *
 * @package UxStudio
 */

defined( 'ABSPATH' ) || exit;

use UxStudio\Core\Mcp\Auth;
use UxStudio\Core\RestRoute;

if ( ! defined( 'REST_REQUEST' ) ) {
	define( 'REST_REQUEST', true ); // The lock and the token binding act inside REST requests only.
}

$server = rest_get_server();
// An own public POST-only route, to test method overrides against.
register_rest_route( 'uxstudio/v1', '/test-public-post', array( 'methods' => 'POST', 'callback' => '__return_null', 'permission_callback' => '__return_true' ) );
// Test routes: /open/ is made public by the filter below, /private only the lock protects
// (its permission callback lets everybody in).
register_rest_route( 'uxs-test/v1', '/open/item', array( 'methods' => 'GET', 'callback' => static fn() => 'item', 'permission_callback' => '__return_true' ) );
register_rest_route( 'uxs-test/v1', '/private', array( 'methods' => array( 'GET', 'POST' ), 'callback' => static fn() => 'secret', 'permission_callback' => '__return_true' ) );
register_rest_route(
	'uxs-test/v1',
	'/open/batch',
	array(
		'methods'             => 'POST',
		'permission_callback' => '__return_true',
		// Like the Store API batch: every path runs as its own dispatched request.
		'callback'            => static function ( WP_REST_Request $request ) {
			$out = array();
			foreach ( (array) $request->get_param( 'paths' ) as $path ) {
				$response = rest_do_request( new WP_REST_Request( 'GET', (string) $path ) );
				$out[]    = $response->is_error() ? $response->as_error()->get_error_code() : $response->get_data();
			}
			return $out;
		},
	)
);
register_rest_route( 'uxs-test/v1', '/open/inner', array( 'methods' => 'GET', 'permission_callback' => '__return_true', 'callback' => static fn() => rest_do_request( new WP_REST_Request( 'GET', '/uxs-test/v1/private' ) )->get_data() ) );
// POST is an own public endpoint, GET is not (its permission callback is not __return_true).
register_rest_route(
	'uxstudio/v1',
	'/test-mixed',
	array(
		array( 'methods' => 'POST', 'callback' => static fn() => 'post', 'permission_callback' => '__return_true' ),
		array( 'methods' => 'GET', 'callback' => static fn() => 'get', 'permission_callback' => static fn() => true ),
	)
);
// Stand-in for the MCP endpoint (the module is not booted here): an ability runs internal requests.
register_rest_route( 'uxstudio/v1', '/mcp', array( 'methods' => 'GET', 'callback' => static fn() => 'mcp:' . rest_do_request( new WP_REST_Request( 'GET', '/uxs-test/v1/private' ) )->get_data(), 'permission_callback' => '__return_true' ), true );
$open_test = static function ( array $routes ): array {
	$routes[] = '/uxs-test/v1/open/';
	return $routes;
};
add_filter( 'uxstudio_rest_public_routes', $open_test );

$ref    = new ReflectionClass( \UxStudio\Modules\SecurityOptimization\Module::class );
$module = $ref->newInstanceWithoutConstructor();
$module->register_rest_lock();

// ACF: ACF_Rest_Api::initialize() on rest_pre_dispatch (10) returns null for every request.
$acf_like = static function ( $result ) {
	return null;
};
add_filter( 'rest_pre_dispatch', $acf_like, 10 );
// WooCommerce Store API: Authentication::check_authentication() (10) answers true for /wc/store/.
$store_like = static function ( $result ) {
	$route = RestRoute::current();
	if ( 0 === strpos( $route, '/wc/store/' ) || 0 === strpos( $route, '/uxs-test/v1/open/' ) ) {
		return ! empty( $result ) ? $result : true;
	}
	return $result;
};
add_filter( 'rest_authentication_errors', $store_like, 10 );

wp_set_current_user( 0 );

/**
 * Set up the raw request of a visitor.
 */
$raw = static function ( string $uri, string $method = 'GET', array $post = array(), array $headers = array(), bool $pretty = true ): void {
	RestRoute::reset();
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
	// Inside a REST request the server is handed the rest_route query var (rest_api_loaded()).
	unset( $GLOBALS['wp']->query_vars['rest_route'] );
	if ( $pretty ) {
		$route = RestRoute::requested();
		if ( '' !== $route ) {
			$GLOBALS['wp']->query_vars['rest_route'] = $route;
		}
	}
};

/**
 * WP_REST_Server::check_authentication() for a raw request as an anonymous visitor.
 *
 * @return string 'allowed' or the WP_Error code and status.
 */
$check = static function ( string $uri, string $method = 'GET', array $post = array(), array $headers = array() ) use ( $raw ): string {
	$raw( $uri, $method, $post, $headers );
	$result = rest_get_server()->check_authentication();
	RestRoute::reset();
	if ( is_wp_error( $result ) ) {
		$data = $result->get_error_data();
		return $result->get_error_code() . ' (' . ( $data['status'] ?? '?' ) . ')';
	}
	return 'allowed';
};

$fail   = 0;
$expect = static function ( string $label, string $uri, bool $allowed, string $method = 'GET', array $post = array(), array $headers = array() ) use ( $check, &$fail ): void {
	$got  = $check( $uri, $method, $post, $headers );
	$ok   = $allowed ? ( 'allowed' === $got ) : ( 'uxstudio_rest_forbidden (401)' === $got );
	$fail += $ok ? 0 : 1;
	printf( "%s  %-66s -> %s\n", $ok ? 'PASS' : 'FAIL', $label, $got );
};
$row = static function ( string $label, string $got, string $want ) use ( &$fail ): void {
	$ok    = $want === $got;
	$fail += $ok ? 0 : 1;
	printf( "%s  %-66s -> %s\n", $ok ? 'PASS' : 'FAIL', $label, $got );
};

/**
 * Serve a request like WP_REST_Server::serve_request(): authentication, then dispatch() of the
 * WP_REST_Request (by default the one the raw request names).
 *
 * @return string 'auth:<code>' (refused before dispatch), 'error:<code>' or 'ran:<json data>'.
 */
$serve = static function ( string $uri, ?WP_REST_Request $dispatched = null, string $method = 'GET' ) use ( $raw ): string {
	$raw( $uri, $method );
	$server = rest_get_server();
	$auth   = $server->check_authentication();
	if ( is_wp_error( $auth ) ) {
		$out = 'auth:' . $auth->get_error_code();
	} else {
		$request  = $dispatched ?? new WP_REST_Request( $method, RestRoute::current() );
		$response = $server->dispatch( $request );
		$out      = $response->is_error() ? 'error:' . $response->as_error()->get_error_code() : 'ran:' . wp_json_encode( $response->get_data() );
	}
	unset( $GLOBALS['wp']->query_vars['rest_route'] );
	RestRoute::reset();
	return $out;
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

echo "\n== served through the REST server (ACF-like and Store-API-like filters active) ==\n";
$row( 'public route runs', $serve( '/wp-json/uxs-test/v1/open/item' ), 'ran:"item"' );
$row( 'private route refused before dispatch', $serve( '/wp-json/uxs-test/v1/private' ), 'auth:uxstudio_rest_forbidden' );
$row( 'dispatched request differs from the raw URL -> refused', $serve( '/wp-json/uxs-test/v1/open/item', new WP_REST_Request( 'GET', '/uxs-test/v1/private' ) ), 'error:uxstudio_rest_forbidden' );
$row( 'own route, public POST', $serve( '/wp-json/uxstudio/v1/test-mixed', null, 'POST' ), 'ran:"post"' );
$override = new WP_REST_Request( 'POST', '/uxstudio/v1/test-mixed' );
$override->set_method( 'GET' );
$row( 'raw POST, dispatched with method GET (not public) -> refused', $serve( '/wp-json/uxstudio/v1/test-mixed', $override, 'POST' ), 'error:uxstudio_rest_forbidden' );
$batch = new WP_REST_Request( 'POST', '/uxs-test/v1/open/batch' );
$batch->set_param( 'paths', array( '/uxs-test/v1/open/item', '/uxs-test/v1/private' ) );
$row( 'public batch (auth said true): public sub-request runs, private refused', $serve( '/wp-json/uxs-test/v1/open/batch', $batch, 'POST' ), 'ran:["item","uxstudio_rest_forbidden"]' );
$row( 'a public endpoint calling rest_do_request() internally is left alone', $serve( '/wp-json/uxs-test/v1/open/inner' ), 'ran:"secret"' );
$row( 'no batch state is left for the next request', $serve( '/wp-json/uxs-test/v1/open/inner' ), 'ran:"secret"' );
if ( class_exists( 'WooCommerce' ) ) {
	$row( '/wc/v3 dispatched behind a Store API URL -> refused', $serve( '/wp-json/wc/store/v1/cart', new WP_REST_Request( 'GET', '/wc/v3/orders' ) ), 'error:uxstudio_rest_forbidden' );
	$sub = new WP_REST_Request( 'POST', '/uxs-test/v1/open/batch' );
	$sub->set_param( 'paths', array( '/wc/v3/orders' ) );
	$row( 'batch sub-request to /wc/v3 -> refused', $serve( '/wp-json/uxs-test/v1/open/batch', $sub, 'POST' ), 'ran:["uxstudio_rest_forbidden"]' );
}
$admins = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) );
$admin  = (int) ( $admins[0] ?? 0 );
if ( $admin ) {
	wp_set_current_user( $admin );
	$row( 'a logged-in user is not locked', $serve( '/wp-json/uxs-test/v1/private' ), 'ran:"secret"' );
	wp_set_current_user( 0 );
}

echo "\n== MCP token bound to the MCP route (ACF-like filter active) ==\n";
remove_filter( 'rest_authentication_errors', array( $module, 'restrict_rest_api' ), 99 );
remove_filter( 'rest_pre_dispatch', array( $module, 'recheck_rest_lock' ), -100 );
remove_filter( 'rest_request_before_callbacks', array( $module, 'enforce_rest_lock' ), PHP_INT_MAX );
add_filter( 'rest_pre_dispatch', array( Auth::class, 'bind' ), -1000, 3 );
add_filter( 'rest_request_before_callbacks', array( Auth::class, 'bind_callbacks' ), PHP_INT_MAX, 3 );
$token = static fn(): array => array( 'state' => 'ok', 'code' => 'ok', 'kind' => 'token', 'id' => 't1', 'label' => 'Test', 'scopes' => array( 'write' ), 'user_id' => $admin, 'ip' => '203.0.113.5', 'route_kind' => Auth::KIND_ENDPOINT );
if ( $admin ) {
	Auth::reset( $token() );
	wp_set_current_user( $admin );
	$row( 'token on the MCP route runs it, its internal requests pass', $serve( '/wp-json/uxstudio/v1/mcp', new WP_REST_Request( 'GET', '/uxstudio/v1/mcp' ) ), 'ran:"mcp:secret"' );
	Auth::reset( $token() );
	wp_set_current_user( $admin );
	$row( 'token, MCP URL but another route dispatched -> callbacks never run', $serve( '/wp-json/uxstudio/v1/mcp', new WP_REST_Request( 'GET', '/uxs-test/v1/private' ) ), 'error:uxstudio_mcp_route' );
	$row( '... the token context is denied, the user logged out', ( 'denied' === ( Auth::context()['state'] ?? '' ) && 0 === get_current_user_id() ) ? 'yes' : 'no', 'yes' );
	Auth::reset( null );
	wp_set_current_user( 0 );
} else {
	echo "SKIP  no administrator\n";
}
remove_filter( 'rest_pre_dispatch', array( Auth::class, 'bind' ), -1000 );
remove_filter( 'rest_request_before_callbacks', array( Auth::class, 'bind_callbacks' ), PHP_INT_MAX );
$module->register_rest_lock();
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
