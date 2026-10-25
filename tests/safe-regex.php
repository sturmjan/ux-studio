<?php
// Test only: never runs over HTTP (the release zip also excludes tests/).
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { exit; }
/**
 * Standalone test of the ReDoS guard (PLAN.md 28): admin-written regular expressions evaluated on visitor input
 * (redirect conditions and rules, maintenance exceptions, link rules, duplicate meta patterns) and the path
 * normalisation of the maintenance exceptions.
 *
 *   php tests/safe-regex.php
 *
 * @package UxStudio
 */

// phpcs:disable

define( 'ABSPATH', __DIR__ . '/' );
define( 'UXSTUDIO_PATH', dirname( __DIR__ ) . '/' );

function __( $text, $domain = '' ) { return $text; }

require_once dirname( __DIR__ ) . '/includes/Autoloader.php';
\UxStudio\Autoloader::register();

use UxStudio\Core\SafeRegex;
use UxStudio\Modules\DuplicatePost\DuplicateRules;
use UxStudio\Modules\LinkManager\LinkRules;
use UxStudio\Modules\MaintenanceMode\MaintenanceRules as M;
use UxStudio\Modules\Redirects\Conditions;

$failures = 0;
$assert   = static function ( string $name, bool $ok, string $detail = '' ) use ( &$failures ): void {
	if ( ! $ok ) {
		++$failures;
	}
	echo ( $ok ? 'PASS ' : 'FAIL ' ) . $name . ( $ok || '' === $detail ? '' : ' => ' . $detail ) . PHP_EOL;
};

$evil_subject = str_repeat( 'a', 1000 ) . '!';
$limit_before = ini_get( 'pcre.backtrack_limit' );

/* ── SafeRegex::match ── */

$assert( 'a normal pattern matches', SafeRegex::match( '~^/blog/(\d+)-(.*)$~i', '/blog/12-hello', $m ) && '12' === $m[1] && 'hello' === $m[2] );
$assert( 'a normal pattern that does not match is false', ! SafeRegex::match( '~^/blog/~', '/shop/' ) );
$t0 = microtime( true );
$res = SafeRegex::match( '~^(a+)+$~', $evil_subject );
$took = microtime( true ) - $t0;
$assert( 'catastrophic pattern (a+)+ on a 1000 byte subject: no match, no hang', false === $res && $took < 1.0, round( $took, 3 ) . ' s' );
$assert( 'the backtrack limit is restored after the call', ini_get( 'pcre.backtrack_limit' ) === $limit_before );
$assert( 'a pattern that does not compile is no match (and no warning)', ! SafeRegex::match( '~(unclosed~', 'x' ) );
$assert( 'the subject is clipped to 1024 bytes (a pattern anchored at the far end does not see it)', ! SafeRegex::match( '~Z$~', str_repeat( 'a', 2000 ) . 'Z' ) && strlen( SafeRegex::clip( str_repeat( 'a', 5000 ) ) ) === SafeRegex::MAX_SUBJECT );
$clipped = SafeRegex::clip( str_repeat( 'é', 800 ) );
$assert( 'clipping does not split a multi-byte character', 1 === preg_match( '//u', $clipped ) && strlen( $clipped ) <= SafeRegex::MAX_SUBJECT );

/* ── SafeRegex::probe (save time) ── */

foreach ( array( '~^(a+)+$~', '~^(a|aa)+$~', '~^(\w+\s?)*$~', '~(x+x+)+y~', '~^([a-z]+)*[0-9]$~' ) as $bad ) {
	$assert( "probe refuses $bad", ! SafeRegex::probe( $bad ) );
}
foreach ( array( '~^/blog/(\d+)-(.*)$~i', '~^/(en|cs)/~', '~google\.(com|cz)~i', '~^/produkt/[a-z0-9-]+/?$~', '~bot|crawler|spider~i', '~^(?:https?://)?(www\.)?example\.com/~i' ) as $good ) {
	$assert( "probe accepts $good", SafeRegex::probe( $good ) );
}
$assert( 'probe refuses a pattern that does not compile', ! SafeRegex::probe( '~(unclosed~' ) );
$assert( 'probe leaves the backtrack limit alone', ini_get( 'pcre.backtrack_limit' ) === $limit_before );

/* ── Where it is used ── */

$assert( 'redirect condition regex: validated on save (catastrophic refused, sane accepted)', ! Conditions::regex_ok( '^(a+)+$' ) && Conditions::regex_ok( 'google\.(com|cz)' ) );
$assert( 'maintenance regex exception: catastrophic pattern is refused on save', array() === M::parse_paths( 'regex:^(a+)+$' )['rules'] && array( 'regex:^(a+)+$' ) === M::parse_paths( 'regex:^(a+)+$' )['invalid'] );
$ok = M::parse_paths( 'regex:^/en/' );
$assert( 'maintenance regex exception: a sane pattern is kept', 1 === count( $ok['rules'] ) && 'regex' === $ok['rules'][0]['type'] );
// A pattern stored before the guard existed still cannot hang a request.
$t0 = microtime( true );
$hit = M::path_excepted( '/' . str_repeat( 'a', 1000 ) . '!', array( array( 'type' => 'regex', 'value' => '^/(a+)+$' ) ) );
$assert( 'stored catastrophic exception: evaluates quickly to "not excepted"', false === $hit && microtime( true ) - $t0 < 1.0 );
$wild = LinkRules::matches( '*a*a*a*a*a*a*a*a*b', 'x', str_repeat( 'a', 1000 ) );
$assert( 'link rule with many wildcards on a long address does not hang', false === $wild );
$assert( 'link rules still work', LinkRules::matches( '*.example.com', 'shop.example.com', 'shop.example.com/x' ) && ! LinkRules::matches( '*.example.com', 'example.org', 'example.org/x' ) );
$assert( 'duplicate meta patterns still work', DuplicateRules::matches( '_edit_lock', array( '_edit_*' ) ) && ! DuplicateRules::matches( 'title', array( '_edit_*' ) ) );

/* ── Maintenance exceptions: paths with .. or // (finding 10) ── */

$pr = M::parse_paths( "/kontakt\n/blog/*\n/wp-json/*" );
$ex = static fn( string $uri ) => M::path_excepted( M::request_path( $uri, '' ), $pr['rules'] );
$assert( 'exception: the listed address and its children are excepted', $ex( '/kontakt' ) && $ex( '/blog/clanek' ) && $ex( '/blog/' ) );
$assert( 'exception: an address that is not listed is not excepted', ! $ex( '/admin-secret' ) );
$assert( 'exception: /blog/../admin-secret does not ride on /blog/*', ! $ex( '/blog/../admin-secret' ) );
$assert( 'exception: encoded dot segments (%2e%2e) are refused too', ! $ex( '/blog/%2e%2e/admin-secret' ) && ! $ex( '/blog/%2E%2E/admin-secret' ) );
$assert( 'exception: double encoded dots are refused', ! $ex( '/blog/%252e%252e/admin-secret' ) );
$assert( 'exception: a backslash form of the traversal is refused', ! $ex( '/blog/..%5Cadmin-secret' ) );
$assert( 'exception: doubled slashes are normalised, a legitimate path still works', $ex( '/blog//clanek' ) && '/blog/clanek' === M::request_path( '/blog//clanek', '' ) );
$assert( 'exception: path_excepted itself refuses a raw .. path', ! M::path_excepted( '/blog/../x', $pr['rules'] ) && ! M::path_excepted( '/blog//x', $pr['rules'] ) );
$assert( 'exception: a name that merely contains dots is fine', M::path_excepted( '/blog/a.b', $pr['rules'] ) );

$rx_src = (string) file_get_contents( dirname( __DIR__ ) . '/includes/Core/SafeRegex.php' );
$assert( 'safe regex: a failing ini_set is reported once, matching semantics unchanged', 1 === preg_match( '/! \$lowered && ! self::\$ini_warned.*error_log/s', $rx_src ) );

echo PHP_EOL . ( $failures ? "$failures FAILED" : 'ALL PASSED' ) . PHP_EOL;
exit( $failures ? 1 : 0 );
