<?php
// Test only: never runs over HTTP (the release zip also excludes tests/).
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { exit; }
/**
 * Standalone test of the stable site id, the paused state, the domain watch and the way signed calls to the
 * Central App carry the site id (PLAN.md 40). Needs no WordPress and no database:
 *
 *   php tests/central-app-identity.php
 *
 * WordPress functions are stubbed below; wp_remote_post() records the request and answers from a queue.
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
function wp_remote_post( $url, $args ) {
	$GLOBALS['uxs_requests'][] = array( 'url' => $url, 'args' => $args );
	$answer = array_shift( $GLOBALS['uxs_answers'] );
	return $answer ?? new WP_Error( 'no_answer', 'no answer queued' );
}
function wp_remote_retrieve_response_code( $r ) { return $r['code']; }
function wp_remote_retrieve_body( $r ) { return $r['body']; }

require_once $root . '/includes/Autoloader.php';
\UxStudio\Autoloader::register();

use UxStudio\Core\CentralApp\DomainWatch;
use UxStudio\Core\CentralApp\Gateway;
use UxStudio\Core\CentralApp\PausedNotice;
use UxStudio\Core\CentralApp\SiteIdentity;
use UxStudio\Modules\ContentSync\HmacAuth;

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
$reset = static function (): void {
	$GLOBALS['uxs_options']    = array(
		'uxstudio_content_sync' => array( 'central_app_url' => 'https://app.example.test/' ),
		'uxstudio_secret_content_sync_node_key' => str_repeat( 'ab', 32 ),
	);
	$GLOBALS['uxs_transients'] = array();
	$GLOBALS['uxs_requests']   = array();
	$GLOBALS['uxs_answers']    = array();
	$GLOBALS['uxs_log']        = array();
	$GLOBALS['uxs_home']       = 'https://dev.example.test/';
};

/* ── UUID ── */

$id = SiteIdentity::generate_uuid4();
$assert( 'a generated id is a UUID v4', SiteIdentity::is_uuid4( $id ), $id );
$assert( 'ids differ', SiteIdentity::generate_uuid4() !== SiteIdentity::generate_uuid4() );
$assert( 'junk and other versions are not accepted', ! SiteIdentity::is_uuid4( '' ) && ! SiteIdentity::is_uuid4( 'abc' ) && ! SiteIdentity::is_uuid4( '3f2c6a1e-8b4d-1c1a-9e57-2d6b0a9c1f34' ) && ! SiteIdentity::is_uuid4( strtoupper( $id ) ) );

/* ── Address comparison and the move decision ── */

$assert( 'normalize: case, trailing slash, default port', 'https://example.com' === SiteIdentity::normalize_url( 'HTTPS://Example.com:443/' ) && 'http://example.com' === SiteIdentity::normalize_url( 'http://example.com:80' ) );
$assert( 'normalize keeps a path and a real port', 'http://localhost:8080/pobyty' === SiteIdentity::normalize_url( 'http://localhost:8080/pobyty/' ) );
$assert( 'nothing stored: init', 'init' === SiteIdentity::move_decision( '', 'https://a.test/', null ) );
$assert( 'same address (slash or case differs): none', 'none' === SiteIdentity::move_decision( 'https://A.test', 'https://a.test/', null ) );
$assert( 'other host: send', 'send' === SiteIdentity::move_decision( 'https://a.test/', 'https://b.test/', null ) );
$assert( 'http to https counts as a move', 'send' === SiteIdentity::move_decision( 'http://a.test/', 'https://a.test/', null ) );
$assert( 'a paused link never reports a move', 'none' === SiteIdentity::move_decision( 'https://a.test/', 'https://b.test/', array( 'state' => 'clone', 'url' => '', 'since' => 1 ) ) );

/* ── Classification of answers ── */

$assert( '409 url_mismatch', array( 'kind' => 'url_mismatch', 'url' => 'https://k.test/' ) === SiteIdentity::classify_response( 409, array( 'code' => 'url_mismatch', 'known_url' => 'https://k.test/' ) ) );
$assert( '410 superseded', array( 'kind' => 'superseded', 'url' => 'https://n.test/' ) === SiteIdentity::classify_response( 410, array( 'code' => 'superseded', 'new_url' => 'https://n.test/' ) ) );
$assert( '423 clone', array( 'kind' => 'clone', 'url' => 'https://o.test/' ) === SiteIdentity::classify_response( 423, array( 'code' => 'clone', 'original_url' => 'https://o.test/' ) ) );
$assert( 'the same code with another status is nothing', 'none' === SiteIdentity::classify_response( 200, array( 'code' => 'clone' ) )['kind'] && 'none' === SiteIdentity::classify_response( 409, array( 'code' => 'other' ) )['kind'] );
$assert( 'site_moved: moved / pending / clone / error', 'moved' === SiteIdentity::classify_move_response( 200, array( 'status' => 'moved', 'url' => 'u' ) )['kind'] && 'pending' === SiteIdentity::classify_move_response( 202, array( 'status' => 'pending_decision' ) )['kind'] && 'clone' === SiteIdentity::classify_move_response( 200, array( 'status' => 'clone', 'original_url' => 'o' ) )['kind'] && 'error' === SiteIdentity::classify_move_response( 403, array() )['kind'] && 'error' === SiteIdentity::classify_move_response( 200, array( 'status' => 'whatever' ) )['kind'] );
$assert( 'site_moved: superseded carries the new address', 'superseded' === SiteIdentity::classify_move_response( 200, array( 'status' => 'superseded', 'new_url' => 'n' ) )['kind'] && 'n' === SiteIdentity::classify_move_response( 200, array( 'status' => 'superseded', 'new_url' => 'n' ) )['url'] );

/* ── Site id in the database ── */

$reset();
$first = SiteIdentity::site_id();
$assert( 'the id is made on first use and stays', SiteIdentity::is_uuid4( $first ) && SiteIdentity::site_id() === $first && get_option( SiteIdentity::ID_OPTION ) === $first );
$assert( 'a stored id that is not a UUID v4 is replaced', ( function () use ( $first ) { update_option( SiteIdentity::ID_OPTION, 'nonsense' ); return SiteIdentity::site_id() !== 'nonsense'; } )() );
$assert( 'headers and body carry the id', array( 'X-UxStudio-Site-Id' => SiteIdentity::site_id() ) === SiteIdentity::headers() && SiteIdentity::site_id() === SiteIdentity::with_site_id( array( 'a' => 1 ) )['site_id'] );
$new = SiteIdentity::regenerate();
$assert( 'regenerate gives another id', SiteIdentity::is_uuid4( $new ) && $new !== $first );

/* ── Paused state ── */

$reset();
$assert( 'not paused by default', ! SiteIdentity::is_paused() && null === SiteIdentity::paused() );
SiteIdentity::pause( 'bogus', 'x' );
$assert( 'an unknown state is not stored', ! SiteIdentity::is_paused() );
SiteIdentity::pause( 'clone', 'https://orig.test/' );
$assert( 'clone is stored with its address', SiteIdentity::is_paused() && 'clone' === SiteIdentity::paused()['state'] && 'https://orig.test/' === SiteIdentity::paused()['url'] );
$assert( 'the notice names the original', str_contains( PausedNotice::message( SiteIdentity::paused() ), 'orig.test' ) && str_contains( PausedNotice::message( SiteIdentity::paused() ), 'copy' ) );
SiteIdentity::pause( 'superseded', 'https://new.test/' );
$assert( 'the superseded notice says moved', str_contains( PausedNotice::message( SiteIdentity::paused() ), 'moved to new.test' ) );
SiteIdentity::resume();
$assert( 'resume lifts it', ! SiteIdentity::is_paused() );

/* ── Signed call: headers, body, signature ── */

$reset();
$answer( 200, array( 'ok' => true ) );
$res = Gateway::post( 'social_refresh', array( 'site_url' => 'https://dev.example.test/', 'urls' => array( 'https://dev.example.test/a/' ) ) );
$req = $GLOBALS['uxs_requests'][0] ?? null;
$sid = SiteIdentity::site_id();
$assert( 'the call went to the page with the query built once', null !== $req && 'https://app.example.test/?page=social_refresh' === $req['url'] );
$assert( 'header X-UxStudio-Site-Id is sent', $sid === ( $req['args']['headers']['X-UxStudio-Site-Id'] ?? '' ) );
$body = json_decode( (string) ( $req['args']['body'] ?? '' ), true );
$assert( 'body has site_id and keeps site_url', $sid === ( $body['site_id'] ?? '' ) && 'https://dev.example.test/' === ( $body['site_url'] ?? '' ) );
$h = $req['args']['headers'];
$assert(
	'signature is the HmacAuth one over the raw body',
	hash_equals( HmacAuth::sign( 'POST', $req['url'], $req['args']['body'], (int) $h['X-Content-Sync-Timestamp'], $h['X-Content-Sync-Nonce'], str_repeat( 'ab', 32 ) ), $h['X-Content-Sync-Signature'] )
);
$assert( 'a plain 200 returns code and body', is_array( $res ) && 200 === $res['code'] && true === $res['body']['ok'] );

/* ── Answers 410 / 423 pause the link, and a paused link makes no call ── */

$reset();
SiteIdentity::set_known_url( 'https://dev.example.test/' );
$answer( 423, array( 'code' => 'clone', 'original_url' => 'https://prod.example.test/' ) );
Gateway::post( 'social_refresh', array() );
$assert( '423 clone pauses the link', SiteIdentity::is_paused() && 'clone' === SiteIdentity::paused()['state'] && 'https://prod.example.test/' === SiteIdentity::paused()['url'] );
$before = count( $GLOBALS['uxs_requests'] );
$again  = Gateway::post( 'social_refresh', array() );
$assert( 'then no request is made and the error says paused', $before === count( $GLOBALS['uxs_requests'] ) && $again instanceof WP_Error && 'uxstudio_ca_paused' === $again->get_error_code() );
$assert( 'the pause is logged', in_array( 'ca_link_paused', $GLOBALS['uxs_log'], true ) );
$assert( 'a paused site is not "active" but still "paired"', ! Gateway::is_active() && Gateway::is_paired() );

$reset();
SiteIdentity::set_known_url( 'https://dev.example.test/' );
$answer( 410, array( 'code' => 'superseded', 'new_url' => 'https://prod.example.test/' ) );
Gateway::post( 'social_refresh', array() );
$assert( '410 superseded pauses the link', 'superseded' === ( SiteIdentity::paused()['state'] ?? '' ) );

/* ── Domain watch ── */

$reset();
$assert( 'not paired: off', ( function () { $GLOBALS['uxs_options'] = array(); return 'off' === DomainWatch::check( true ); } )() );

$reset();
$assert( 'nothing stored yet: the current address is remembered, no call', 'init' === DomainWatch::check( true ) && 'https://dev.example.test/' === SiteIdentity::known_url() && 0 === count( $GLOBALS['uxs_requests'] ) );
$assert( 'same address: nothing happens', 'none' === DomainWatch::check( true ) && 0 === count( $GLOBALS['uxs_requests'] ) );

$GLOBALS['uxs_home'] = 'https://www.example.com/';
$answer( 200, array( 'status' => 'moved', 'url' => 'https://www.example.com/' ) );
$assert( 'new address: site_moved is sent and answered moved', 'moved' === DomainWatch::check( true ) );
$req  = $GLOBALS['uxs_requests'][0];
$body = json_decode( $req['args']['body'], true );
$assert( 'site_moved page, body old_url, new_url, site_url and site_id', 'https://app.example.test/?page=site_moved' === $req['url'] && 'https://dev.example.test/' === $body['old_url'] && 'https://www.example.com/' === $body['new_url'] && 'https://www.example.com/' === $body['site_url'] && SiteIdentity::site_id() === $body['site_id'] );
$assert( 'moved: the new address is stored and the move is logged', 'https://www.example.com/' === SiteIdentity::known_url() && in_array( 'ca_site_moved', $GLOBALS['uxs_log'], true ) );
$assert( 'afterwards nothing is sent', 'none' === DomainWatch::check( true ) && 1 === count( $GLOBALS['uxs_requests'] ) );

$reset();
SiteIdentity::set_known_url( 'https://dev.example.test/' );
$GLOBALS['uxs_home'] = 'https://www.example.com/';
$answer( 202, array( 'status' => 'pending_decision' ) );
$assert( 'pending: nothing is stored, waits for a decision', 'pending' === DomainWatch::check( true ) && 'https://dev.example.test/' === SiteIdentity::known_url() );
$assert( 'pending: a call from the guard is throttled for an hour', 'throttled' === DomainWatch::check( false ) && 1 === count( $GLOBALS['uxs_requests'] ) );
$answer( 200, array( 'status' => 'moved' ) );
$assert( 'pending: the daily cron asks again and the move goes through', 'moved' === DomainWatch::check( true ) && 'https://www.example.com/' === SiteIdentity::known_url() );

$reset();
SiteIdentity::set_known_url( 'https://dev.example.test/' );
$GLOBALS['uxs_home'] = 'https://www.example.com/';
$answer( 200, array( 'status' => 'clone', 'original_url' => 'https://dev.example.test/' ) );
$assert( 'clone answer pauses the link', 'clone' === DomainWatch::check( true ) && SiteIdentity::is_paused() && 'https://dev.example.test/' === SiteIdentity::known_url() );

$reset();
SiteIdentity::set_known_url( 'https://dev.example.test/' );
$GLOBALS['uxs_home'] = 'https://www.example.com/';
$answer( 403, array( 'error' => 'no' ) );
$assert( 'an error answer changes nothing and is logged', 'error' === DomainWatch::check( true ) && 'https://dev.example.test/' === SiteIdentity::known_url() && in_array( 'ca_site_moved_failed', $GLOBALS['uxs_log'], true ) );

/* ── The guard reports a moved address before the real call ── */

$reset();
SiteIdentity::set_known_url( 'https://dev.example.test/' );
$GLOBALS['uxs_home'] = 'https://www.example.com/';
$answer( 200, array( 'status' => 'moved' ) );
$answer( 200, array( 'success' => true ) );
$res = Gateway::post( 'social_refresh', array( 'urls' => array() ) );
$pages = array_map( static fn( $r ) => $r['url'], $GLOBALS['uxs_requests'] );
$assert( 'site_moved goes first, then the call itself', array( 'https://app.example.test/?page=site_moved', 'https://app.example.test/?page=social_refresh' ) === $pages );

/* ── 409 url_mismatch on a normal call sends site_moved with the address the Central App knows ── */

$reset();
SiteIdentity::set_known_url( 'https://dev.example.test/' );
$answer( 409, array( 'code' => 'url_mismatch', 'known_url' => 'https://old.example.test/' ) );
$answer( 200, array( 'status' => 'moved' ) );
Gateway::post( 'social_refresh', array() );
$moved = $GLOBALS['uxs_requests'][1] ?? null;
$body  = null === $moved ? array() : json_decode( $moved['args']['body'], true );
$assert( '409 url_mismatch: site_moved with old_url from the answer and the current address as new_url', null !== $moved && str_contains( $moved['url'], 'page=site_moved' ) && 'https://old.example.test/' === ( $body['old_url'] ?? '' ) && 'https://dev.example.test/' === ( $body['new_url'] ?? '' ) );

echo PHP_EOL . ( 0 === $failures ? 'ALL PASSED' : $failures . ' FAILED' ) . PHP_EOL;
exit( 0 === $failures ? 0 : 1 );
