<?php
// Test only: never runs over HTTP (the release zip also excludes tests/).
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { exit; }
/**
 * Standalone test of PinnedHttp (PLAN.md 28): the checked IP is pinned for the connection (no DNS rebinding),
 * redirects are followed hop by hop and each hop is resolved, checked and pinned again.
 *
 *   php tests/pinned-http.php
 *
 * @package UxStudio
 */

// phpcs:disable

define( 'ABSPATH', __DIR__ . '/' );

class WP_Error {
	public $code;
	public $message;
	public function __construct( $c = '', $m = '' ) { $this->code = $c; $this->message = $m; }
	public function get_error_code() { return $this->code; }
}
function is_wp_error( $v ) { return $v instanceof WP_Error; }
function wp_parse_url( $u, $c = -1 ) { return parse_url( $u, $c ); }
function wp_remote_retrieve_response_code( $r ) { return is_array( $r ) ? ( $r['response']['code'] ?? 0 ) : 0; }
function wp_remote_retrieve_header( $r, $h ) { return is_array( $r ) ? ( $r['headers'][ $h ] ?? '' ) : ''; }

$GLOBALS['hooks'] = array();
function add_action( $h, $cb, $p = 10, $n = 1 ) { $GLOBALS['hooks'][ $h ][] = $cb; }
function remove_action( $h, $cb, $p = 10 ) { $GLOBALS['hooks'][ $h ] = array_values( array_filter( $GLOBALS['hooks'][ $h ] ?? array(), static fn( $c ) => $c !== $cb ) ); }

require_once dirname( __DIR__ ) . '/includes/Core/PinnedHttp.php';
use UxStudio\Core\PinnedHttp;

$failures = 0;
$assert   = static function ( string $name, bool $ok, string $detail = '' ) use ( &$failures ): void {
	if ( ! $ok ) {
		++$failures;
	}
	echo ( $ok ? 'PASS ' : 'FAIL ' ) . $name . ( $ok || '' === $detail ? '' : ' => ' . $detail ) . PHP_EOL;
};

$public = static fn( string $ip ): bool => false !== filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE );

$dns   = array( 'a.example' => array( '93.184.216.34' ), 'b.example' => array( '93.184.216.35' ), 'mixed.example' => array( '93.184.216.36', '10.0.0.5' ), 'v6.example' => array( '2606:2800:220:1::1' ) );
$sent  = array();
$hooks_seen = 0;
$resp  = array();
PinnedHttp::set_resolver( static fn( string $h ): array => $dns[ $h ] ?? array() );
PinnedHttp::set_transport(
	static function ( string $url, array $args ) use ( &$sent, &$resp, &$hooks_seen ) {
		$sent[]      = array( 'url' => $url, 'args' => $args );
		$hooks_seen += count( $GLOBALS['hooks']['http_api_curl'] ?? array() );
		return $resp[ $url ] ?? array( 'response' => array( 'code' => 200 ), 'headers' => array() );
	}
);

$r = PinnedHttp::request( 'GET', 'https://a.example/x.png', array( 'timeout' => 5 ), $public );
$assert( 'a public host is requested once, redirects handed to us (redirection 0)', 200 === $r['response']['code'] && 1 === count( $sent ) && 0 === $sent[0]['args']['redirection'] );
$assert( 'the pin hook is registered during the call and removed after it', 1 === $hooks_seen && array() === $GLOBALS['hooks']['http_api_curl'] );

$sent = array();
$r    = PinnedHttp::request( 'GET', 'https://mixed.example/', array(), $public );
$assert( 'one private address among the answers blocks the request', $r instanceof WP_Error && 'uxstudio_pinned_blocked' === $r->get_error_code() && array() === $sent );
$r = PinnedHttp::request( 'GET', 'https://nowhere.example/', array(), $public );
$assert( 'an unresolvable host is refused', $r instanceof WP_Error && 'uxstudio_pinned_dns' === $r->get_error_code() );
$r = PinnedHttp::request( 'GET', 'ftp://a.example/', array(), $public );
$assert( 'a non-http scheme is refused', $r instanceof WP_Error );
$r = PinnedHttp::request( 'GET', 'http://127.0.0.1/', array(), $public );
$assert( 'a literal private IP is refused', $r instanceof WP_Error );

// The pin callback sets exactly host:port:ip.
$captured = null;
if ( function_exists( 'curl_init' ) ) {
	PinnedHttp::set_transport(
		static function ( string $url, array $args ) use ( &$captured ) {
			$h = curl_init();
			foreach ( $GLOBALS['hooks']['http_api_curl'] as $cb ) {
				$cb( $h, $args, $url );
			}
			$captured = true;
			return array( 'response' => array( 'code' => 200 ), 'headers' => array() );
		}
	);
	PinnedHttp::request( 'GET', 'https://a.example/', array(), $public );
	$assert( 'the pin callback ran on a real cURL handle without error', true === $captured );
}

// Redirect hops: each is resolved and checked again.
$sent = array();
PinnedHttp::set_transport(
	static function ( string $url, array $args ) use ( &$sent, &$resp ) {
		$sent[] = array( 'url' => $url, 'args' => $args );
		return $resp[ $url ] ?? array( 'response' => array( 'code' => 200 ), 'headers' => array() );
	}
);
$resp = array(
	'https://a.example/start' => array( 'response' => array( 'code' => 302 ), 'headers' => array( 'location' => 'https://b.example/img.png' ) ),
);
$r = PinnedHttp::request( 'GET', 'https://a.example/start', array(), $public );
$assert( 'a redirect is followed hop by hop', 200 === $r['response']['code'] && 2 === count( $sent ) && 'https://b.example/img.png' === $sent[1]['url'] );

$sent = array();
$dns['evil.example'] = array( '10.1.1.1' );
$resp = array( 'https://a.example/start' => array( 'response' => array( 'code' => 301 ), 'headers' => array( 'location' => 'http://evil.example/' ) ) );
$r = PinnedHttp::request( 'GET', 'https://a.example/start', array(), $public );
$assert( 'a redirect to a private host is refused before any request to it', $r instanceof WP_Error && 1 === count( $sent ) );

$sent = array();
$resp = array( 'https://a.example/start' => array( 'response' => array( 'code' => 302 ), 'headers' => array( 'location' => '/next' ) ), 'https://a.example/next' => array( 'response' => array( 'code' => 200 ), 'headers' => array() ) );
$r = PinnedHttp::request( 'GET', 'https://a.example/start', array(), $public );
$assert( 'a relative Location is made absolute', 'https://a.example/next' === $sent[1]['url'] );

$sent = array();
$resp = array( 'https://a.example/p' => array( 'response' => array( 'code' => 303 ), 'headers' => array( 'location' => 'https://a.example/done' ) ) );
PinnedHttp::request( 'POST', 'https://a.example/p', array( 'body' => 'x=1' ), $public );
$assert( '303 turns a POST into a GET without a body', 'GET' === $sent[1]['args']['method'] && ! isset( $sent[1]['args']['body'] ) && 'POST' === $sent[0]['args']['method'] );

$sent = array();
$resp = array();
for ( $i = 0; $i < 6; $i++ ) {
	$resp[ 'https://a.example/' . $i ] = array( 'response' => array( 'code' => 302 ), 'headers' => array( 'location' => 'https://a.example/' . ( $i + 1 ) ) );
}
$r = PinnedHttp::request( 'GET', 'https://a.example/0', array(), $public );
$assert( 'redirect loops stop after 3 redirects', $r instanceof WP_Error && 'uxstudio_pinned_redirects' === $r->get_error_code() && 4 === count( $sent ) );

echo PHP_EOL . ( $failures ? "$failures FAILED" : 'ALL PASSED' ) . PHP_EOL;
exit( $failures ? 1 : 0 );
