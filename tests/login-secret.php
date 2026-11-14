<?php
// Test only: never runs over HTTP (the release zip also excludes tests/).
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { exit; }
/**
 * Standalone test of the login secret that a connected site fetches from UX1 Network app
 * (Core\CentralApp\LoginSecret) and of the choice between it and a typed-in secret. Needs no WordPress
 * and no database:
 *
 *   php tests/login-secret.php
 *
 * @package UxStudio
 */

// phpcs:disable

define( 'ABSPATH', __DIR__ . '/' );
define( 'HOUR_IN_SECONDS', 3600 );
$root = dirname( __DIR__ );
define( 'UXSTUDIO_PATH', $root . '/' );

$GLOBALS['uxs_options']    = array();
$GLOBALS['uxs_transients'] = array();
$GLOBALS['uxs_requests']   = array();
$GLOBALS['uxs_answers']    = array();
$GLOBALS['uxs_home']       = 'https://dev.example.test/';
$GLOBALS['uxs_log']        = array();

class WP_Error {
	public $code;
	public $message;
	public $data;
	public function __construct( $code = '', $message = '', $data = '' ) { $this->code = $code; $this->message = $message; $this->data = $data; }
	public function get_error_code() { return $this->code; }
	public function get_error_message() { return $this->message; }
	public function get_error_data() { return $this->data; }
}
class FakeWpdb {
	public $prefix = 'wp_';
	public function insert( $table, $row, $formats = null ) { $GLOBALS['uxs_log'][] = $row['action']; return 1; }
}
$wpdb = new FakeWpdb();

function __( $text, $domain = '' ) { return $text; }
function is_wp_error( $v ) { return $v instanceof WP_Error; }
function get_option( $name, $default = false ) { return $GLOBALS['uxs_options'][ $name ] ?? $default; }
function update_option( $name, $value, $autoload = null ) { $GLOBALS['uxs_options'][ $name ] = $value; return true; }
function delete_option( $name ) { unset( $GLOBALS['uxs_options'][ $name ] ); return true; }
function get_transient( $name ) { return $GLOBALS['uxs_transients'][ $name ] ?? false; }
function set_transient( $name, $value, $ttl = 0 ) { $GLOBALS['uxs_transients'][ $name ] = $value; return true; }
function delete_transient( $name ) { unset( $GLOBALS['uxs_transients'][ $name ] ); return true; }
function home_url( $path = '' ) { return rtrim( $GLOBALS['uxs_home'], '/' ) . '/' . ltrim( $path, '/' ); }
function untrailingslashit( $s ) { return rtrim( $s, '/\\' ); }
function esc_url_raw( $u ) { return $u; }
function wp_json_encode( $v ) { return json_encode( $v, JSON_UNESCAPED_SLASHES ); }
function wp_salt( $s = '' ) { return 'test-salt'; }
function get_current_user_id() { return 0; }
function current_time( $t ) { return '2026-11-03 12:00:00'; }
function wp_remote_get( $url, $args ) {
	$GLOBALS['uxs_requests'][] = array( 'url' => $url, 'args' => $args );
	$answer = array_shift( $GLOBALS['uxs_answers'] );
	return $answer ?? new WP_Error( 'no_answer', 'no answer queued' );
}
function wp_remote_post( $url, $args ) { return new WP_Error( 'no_post', 'no POST expected' ); }
function wp_remote_retrieve_response_code( $r ) { return $r['code']; }
function wp_remote_retrieve_body( $r ) { return $r['body']; }

require_once $root . '/includes/Autoloader.php';
\UxStudio\Autoloader::register();

use UxStudio\Core\CentralApp\Gateway;
use UxStudio\Core\CentralApp\LoginSecret;
use UxStudio\Core\CentralApp\SiteIdentity;
use UxStudio\Core\Security;

$failures = 0;
$assert   = static function ( string $name, bool $ok, string $detail = '' ) use ( &$failures ): void {
	if ( ! $ok ) {
		++$failures;
	}
	echo ( $ok ? 'PASS ' : 'FAIL ' ) . $name . ( $ok || '' === $detail ? '' : ' => ' . $detail ) . PHP_EOL;
};
$answer = static function ( int $code, array $body ): void {
	$GLOBALS['uxs_answers'][] = array( 'code' => $code, 'body' => json_encode( $body ) );
};
$node_key = str_repeat( 'ab', 32 );
$reset    = static function () use ( $node_key ): void {
	$GLOBALS['uxs_options']    = array(
		'uxstudio_content_sync'                 => array( 'central_app_url' => 'https://app.example.test/' ),
		'uxstudio_secret_content_sync_node_key' => Security::seal( $node_key ),
		SiteIdentity::KNOWN_URL_OPTION          => 'https://dev.example.test/',
	);
	$GLOBALS['uxs_transients'] = array();
	$GLOBALS['uxs_requests']   = array();
	$GLOBALS['uxs_answers']    = array();
	$GLOBALS['uxs_log']        = array();
	$GLOBALS['uxs_home']       = 'https://dev.example.test/';
};

/* ── The request: URL, signature, headers ── */

$reset();
$answer( 200, array( 'success' => true, 'google_login_enabled' => true, 'google_login_secret' => 'central-secret-1' ) );
$secret = LoginSecret::get();
$req    = $GLOBALS['uxs_requests'][0] ?? array();
$assert( 'fetch returns the secret from UX1 Network app', 'central-secret-1' === $secret, is_string( $secret ) ? $secret : 'WP_Error' );
$assert( 'GET site_config&action=google_secret', 'https://app.example.test/?page=site_config&action=google_secret' === ( $req['url'] ?? '' ), $req['url'] ?? '-' );
$h = $req['args']['headers'] ?? array();
$expected_sig = hash_hmac( 'sha256', "GET\n" . ( $req['url'] ?? '' ) . "\n" . ( $h['X-Timestamp'] ?? '' ) . "\n" . hash( 'sha256', '' ), $node_key );
$assert( 'signed with the node key over METHOD, URL, timestamp, sha256(empty body)', ( $h['X-Signature'] ?? '' ) === $expected_sig );
$assert( 'X-Site-Url and the site id travel along', 'https://dev.example.test/' === ( $h['X-Site-Url'] ?? '' ) && SiteIdentity::site_id() === ( $h['X-UxStudio-Site-Id'] ?? '' ) );
$assert( 'the secret is cached encrypted, not in plain text', LoginSecret::cached() === 'central-secret-1' && 'central-secret-1' !== get_option( LoginSecret::CACHE_OPTION ) );

/* ── Cache and refresh ── */

$assert( 'a second get() uses the cache (no request)', 'central-secret-1' === LoginSecret::get() && 1 === count( $GLOBALS['uxs_requests'] ) );
$answer( 200, array( 'success' => true, 'google_login_secret' => 'central-secret-2' ) );
$assert( 'refresh asks again and picks up a regenerated secret', 'central-secret-2' === LoginSecret::get( true ) && 'central-secret-2' === LoginSecret::cached() && 2 === count( $GLOBALS['uxs_requests'] ) );

/* ── Failures leave the cache alone and say why ── */

$answer( 401, array( 'success' => false, 'error' => 'Bad signature' ) );
$err = LoginSecret::get( true );
$assert( '401 is an error with the server message', $err instanceof WP_Error && 'Bad signature' === $err->get_error_message() && 401 === $err->get_error_data()['status'] );
$assert( 'a failed refresh keeps the previous cached secret', 'central-secret-2' === LoginSecret::cached() );

$reset();
$answer( 200, array( 'success' => true, 'google_login_secret' => '' ) );
$err = LoginSecret::get();
$assert( 'UX1 Network app has no secret yet: clear error, nothing cached', $err instanceof WP_Error && 'uxstudio_login_secret_missing' === $err->get_error_code() && '' === LoginSecret::cached() );

$reset();
$GLOBALS['uxs_answers'][] = new WP_Error( 'http_request_failed', 'timeout' );
$err = LoginSecret::get();
$assert( 'a network error is passed on', $err instanceof WP_Error && 'http_request_failed' === $err->get_error_code() );

$reset();
unset( $GLOBALS['uxs_options']['uxstudio_secret_content_sync_node_key'] );
$err = LoginSecret::get();
$assert( 'a site that is not paired makes no request', $err instanceof WP_Error && 'uxstudio_login_secret_not_paired' === $err->get_error_code() && 0 === count( $GLOBALS['uxs_requests'] ) );

$reset();
SiteIdentity::pause( 'clone', 'https://orig.test/' );
$err = LoginSecret::get();
$assert( 'a paused link (copy of a site) makes no request', $err instanceof WP_Error && 'uxstudio_ca_paused' === $err->get_error_code() && 0 === count( $GLOBALS['uxs_requests'] ) );

echo PHP_EOL . ( 0 === $failures ? 'ALL PASSED' : $failures . ' FAILED' ) . PHP_EOL;
exit( $failures > 0 ? 1 : 0 );
