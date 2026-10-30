<?php
// Test only: never runs over HTTP (the release zip also excludes tests/).
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { exit; }
/**
 * Standalone test of Bot Throttle: reverse-DNS verification result is used, the blocklist gets the strict plan.
 *
 *   php tests/bot-throttle-detector.php
 *
 * @package UxStudio
 */

// phpcs:disable

define( 'ABSPATH', __DIR__ . '/' );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'DAY_IN_SECONDS', 86400 );
$GLOBALS['t'] = array();
function get_transient( $k ) { return $GLOBALS['t'][ $k ] ?? false; }
function set_transient( $k, $v, $ttl ) { $GLOBALS['t'][ $k ] = $v; $GLOBALS['ttl'][ $k ] = $ttl; return true; }
function __( $s ) { return $s; }
function wp_rand( $a, $b ) { return $a; }

require_once __DIR__ . '/../includes/Modules/BotThrottle/Signatures.php';
require_once __DIR__ . '/../includes/Modules/BotThrottle/Detector.php';
require_once __DIR__ . '/../includes/Modules/BotThrottle/Throttler.php';

use UxStudio\Modules\BotThrottle\Detector;
use UxStudio\Modules\BotThrottle\Throttler;

$fail = 0;
function check( $label, $ok ) { global $fail; echo ( $ok ? 'ok   ' : 'FAIL ' ) . $label . "\n"; if ( ! $ok ) { ++$fail; } }

$ua_google = 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)';

function detector( array $cfg ) {
	$GLOBALS['t'] = array();
	return new Detector( $cfg + array( 'verify_rdns' => true ) );
}

// 1. Real Googlebot: PTR in googlebot.com and it resolves back to the same address.
$d = detector( array(
	'reverse_lookup' => static fn( $ip ) => 'crawl-66-249-66-1.googlebot.com',
	'forward_lookup' => static fn( $host, $v6 ) => array( '66.249.66.1' ),
) );
$r = $d->detect( $ua_google, '66.249.66.1' );
check( 'real Googlebot stays a verified search engine', $r && 'search_engines' === $r['category'] && true === $r['verified'] && 'Googlebot' === $r['name'] );

// 2. Spoof: claims Googlebot, the address belongs to somebody else.
$d = detector( array(
	'reverse_lookup' => static fn( $ip ) => 'vps123.cheap-hosting.example',
	'forward_lookup' => static fn( $host, $v6 ) => array( '203.0.113.9' ),
) );
$r = $d->detect( $ua_google, '203.0.113.9' );
check( 'spoofed Googlebot is filed under generic crawlers, not verified', $r && 'generic_crawlers' === $r['category'] && false === $r['verified'] );
$plan = ( new Throttler() )->plan( $r['category'], 'RED' );
check( 'a spoofed bot is blocked under RED load, a real engine never', 'block' === $plan['action'] && 'microcache' === ( new Throttler() )->plan( 'search_engines', 'RED' )['action'] );

// 3. Right domain but the name does not resolve back (forged PTR).
$d = detector( array(
	'reverse_lookup' => static fn( $ip ) => 'crawl-1.googlebot.com',
	'forward_lookup' => static fn( $host, $v6 ) => array( '198.51.100.7' ),
) );
$r = $d->detect( $ua_google, '203.0.113.9' );
check( 'forged PTR (forward lookup differs) is unverified', $r && 'generic_crawlers' === $r['category'] );

// 4. No answer at all: benefit of the doubt, cached only briefly.
$d = detector( array( 'reverse_lookup' => static fn( $ip ) => $ip ) );
$r = $d->detect( $ua_google, '66.249.66.1' );
check( 'no PTR answer keeps the engine (fail open)', $r && 'search_engines' === $r['category'] && true === $r['verified'] );
$key = array_key_first( $GLOBALS['ttl'] );
check( '... and is remembered for ten minutes only', 600 === $GLOBALS['ttl'][ $key ] );

// 5. Forward lookup without any answer (DNS trouble) is not a spoof either.
$d = detector( array(
	'reverse_lookup' => static fn( $ip ) => 'crawl-1.googlebot.com',
	'forward_lookup' => static fn( $host, $v6 ) => array(),
) );
$r = $d->detect( $ua_google, '66.249.66.1' );
check( 'forward lookup without answer keeps the engine', $r && 'search_engines' === $r['category'] );

// 6. IPv6 notation does not matter.
check( 'same_ip compares IPv6 in packed form', Detector::same_ip( '2001:4860:4801:10::1', array( '2001:4860:4801:0010:0:0:0:1' ) ) );
check( 'same_ip rejects another address', ! Detector::same_ip( '66.249.66.1', array( '66.249.66.2' ) ) );

// 7. Switched off: nothing is looked up, the engine passes as before.
$d = new Detector( array( 'verify_rdns' => false, 'reverse_lookup' => static function () { throw new RuntimeException( 'must not be called' ); } ) );
$r = $d->detect( $ua_google, '203.0.113.9' );
check( 'verification off: no lookup, search engine', $r && 'search_engines' === $r['category'] );

// 8. The blocklist gets the strict plan, an own rule still wins.
$d = new Detector( array( 'blacklist_ua' => array( 'AhrefsBot' ) ) );
$r = $d->detect( 'Mozilla/5.0 (compatible; AhrefsBot/7.0)', '203.0.113.9' );
check( 'blocklisted UA is category blacklist', $r && 'blacklist' === $r['category'] );
$t = new Throttler();
check( 'blocklist: aggressive delay at GREEN (not a pass)', 'delay' === $t->plan( 'blacklist', 'GREEN' )['action'] );
check( 'blocklist: 429 on a cache miss at ORANGE', 429 === $t->plan( 'blacklist', 'ORANGE' )['status'] );
check( 'blocklist: blocked at RED', 'block' === $t->plan( 'blacklist', 'RED' )['action'] );
check( 'an explicit rule for the category overrides the default', 'pass' === ( new Throttler( array( 'blacklist' => array( 'action' => 'pass' ) ) ) )->plan( 'blacklist', 'RED' )['action'] );

exit( $fail > 0 ? 1 : 0 );
