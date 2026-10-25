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

require_once dirname( __DIR__ ) . '/includes/Modules/SecurityOptimization/Module.php';

$ref    = new ReflectionClass( \UxStudio\Modules\SecurityOptimization\Module::class );
$module = $ref->newInstanceWithoutConstructor();

wp_set_current_user( 0 );

/**
 * Run restrict_rest_api() for a request as an anonymous visitor.
 *
 * @param string $uri Request URI (path + query).
 * @return string 'allowed' or the WP_Error code.
 */
$check = static function ( string $uri ) use ( $module ): string {
	$parts                  = wp_parse_url( $uri );
	$_SERVER['REQUEST_URI'] = $uri;
	$_SERVER['REQUEST_METHOD'] = 'GET';
	$_GET                   = array();
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
$expect = static function ( string $label, string $uri, bool $allowed ) use ( $check, &$fail ): void {
	$got  = $check( $uri );
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
$expect( 'filter removed -> cf7 closed again', '/wp-json/contact-form-7/v1/contact-forms/1/feedback', false );

$drop = static function ( array $routes ): array {
	return array_values( array_diff( $routes, array( '/complianz/v1/' ) ) );
};
add_filter( 'uxstudio_rest_public_routes', $drop );
$expect( 'filter can remove the complianz default', '/wp-json/complianz/v1/banner', false );
remove_filter( 'uxstudio_rest_public_routes', $drop );

echo $fail ? "\n$fail FAILED\n" : "\nAll passed\n";
exit( $fail ? 1 : 0 );
