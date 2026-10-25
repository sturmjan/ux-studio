<?php
// Test only: never runs over HTTP (the release zip also excludes tests/).
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { exit; }
/**
 * Standalone test of the pure parts of the Central App connection (PLAN.md 29, phase F4): the address check,
 * the signature (against a value computed independently with Python's hmac), the origin check and the form fields.
 * Needs no WordPress and no database:
 *
 *   php tests/onboarding-central-app.php
 *
 * @package UxStudio
 */

// phpcs:disable

define( 'ABSPATH', __DIR__ . '/' );
$root = dirname( __DIR__ );
define( 'UXSTUDIO_PATH', $root . '/' );

function __( $text, $domain = '' ) { return $text; }

require_once $root . '/includes/Autoloader.php';
\UxStudio\Autoloader::register();

use UxStudio\Core\Onboarding\CentralApp;

$failures = 0;
$assert   = static function ( string $name, bool $ok, string $detail = '' ) use ( &$failures ): void {
	if ( ! $ok ) {
		++$failures;
	}
	echo ( $ok ? 'PASS ' : 'FAIL ' ) . $name . ( $ok || '' === $detail ? '' : ' => ' . $detail ) . PHP_EOL;
};

/* ── Address ── */

$norm = static fn( string $u ): ?string => CentralApp::normalize_base_url( $u );
$assert( 'empty input is the production address', 'https://app.ux1.cz/' === $norm( '' ) && 'https://app.ux1.cz/' === $norm( '   ' ) );
$assert( 'https address gets a trailing slash and a lower case host', 'https://app.ux1.cz/' === $norm( 'HTTPS://App.UX1.cz' ) );
$assert( 'a sub folder is kept', 'https://example.com/central/' === $norm( 'https://example.com/central' ) && 'https://example.com/central/' === $norm( 'https://example.com/central/' ) );
$assert( 'a port is kept', 'https://example.com:8443/' === $norm( 'https://example.com:8443' ) );
$assert( 'http works on localhost, 127.0.0.1 and [::1]', 'http://localhost/centralni-aplikace/' === $norm( 'http://localhost/centralni-aplikace' ) && 'http://127.0.0.1:8080/' === $norm( 'http://127.0.0.1:8080' ) && 'http://[::1]/' === $norm( 'http://[::1]' ) );
$assert( 'http on a real host is refused', null === $norm( 'http://app.ux1.cz' ) && null === $norm( 'http://example.com/' ) );
$assert( 'other schemes are refused', null === $norm( 'ftp://example.com' ) && null === $norm( 'javascript:alert(1)' ) && null === $norm( 'file:///etc/passwd' ) && null === $norm( '//example.com' ) );
$assert( 'credentials, query and fragment are refused', null === $norm( 'https://user:pw@example.com' ) && null === $norm( 'https://example.com/?a=1' ) && null === $norm( 'https://example.com/#x' ) );
$assert( 'a path with dots, spaces or backslashes is refused', null === $norm( 'https://example.com/../x' ) && null === $norm( 'https://example.com/a b' ) && null === $norm( 'https://example.com\\evil.test' ) );
$assert( 'a host that only looks like localhost is refused over http', null === $norm( 'http://localhost.evil.test/' ) && null === $norm( 'http://127.0.0.1.evil.test/' ) );
$assert( 'a very long address is refused', null === $norm( 'https://example.com/' . str_repeat( 'a', 300 ) ) );

/* ── Signature ── */

$key = '0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef';
$assert(
	'signature with site_type equals the independently computed HMAC (site_url|site_name|timestamp|site_type)',
	'0b77aa873e0dee2e233deb61dd9b084a29a67a81b105f3e94dfe5ccce0abba73' === CentralApp::signature( 'https://example.com', 'Můj web', 1790000000, 'wordpress', $key )
);
$assert(
	'signature without site_type is the older three part form',
	'e681508cb44d8bef0c575e9504c207f6b263bf3f83fa82446d4177d2250bedbc' === CentralApp::signature( 'https://example.com', 'Můj web', 1790000000, '', $key )
);
$assert( 'a different key gives a different signature', CentralApp::signature( 'https://example.com', 'Můj web', 1790000000, 'wordpress', $key ) !== CentralApp::signature( 'https://example.com', 'Můj web', 1790000000, 'wordpress', strrev( $key ) ) );
$assert( 'a different name gives a different signature', CentralApp::signature( 'https://example.com', 'A', 1, 'wordpress', $key ) !== CentralApp::signature( 'https://example.com', 'B', 1, 'wordpress', $key ) );

/* ── Origin ── */

$assert( 'same origin: scheme, host and port equal', CentralApp::same_origin( 'https://example.com/wp-admin/admin.php?page=ux-studio#/wizard', 'https://example.com' ) );
$assert( 'same origin ignores host case and the default port', CentralApp::same_origin( 'https://EXAMPLE.com:443/x', 'https://example.com' ) );
$assert( 'different scheme is a different origin', ! CentralApp::same_origin( 'http://example.com/x', 'https://example.com' ) );
$assert( 'different host (www, admin sub domain) is a different origin', ! CentralApp::same_origin( 'https://www.example.com/x', 'https://example.com' ) && ! CentralApp::same_origin( 'https://example.com.evil.test/x', 'https://example.com' ) );
$assert( 'different port is a different origin', ! CentralApp::same_origin( 'http://localhost:8080/x', 'http://localhost/' ) );
$assert( 'junk is not an origin', ! CentralApp::same_origin( 'javascript:alert(1)', 'https://example.com' ) && ! CentralApp::same_origin( '', '' ) );

/* ── Form ── */

$form = CentralApp::connect_form( 'https://app.ux1.cz/', 'https://example.com', 'Můj web', $key, 'https://example.com/wp-admin/admin.php?page=ux-studio#/wizard?mode=new&step=ca&ca=done', 1790000000 );
$assert( 'form posts to the connect page of the Central App', 'https://app.ux1.cz/?page=connect_site' === $form['action'] );
$assert( 'form has exactly the fields of the protocol', array( 'site_url', 'site_name', 'api_key', 'timestamp', 'site_type', 'return_url', 'signature' ) === array_keys( $form['fields'] ) );
$assert( 'form site_type is wordpress, key is at least 32 characters, timestamp is a string of digits', 'wordpress' === $form['fields']['site_type'] && strlen( $form['fields']['api_key'] ) >= 32 && '1790000000' === $form['fields']['timestamp'] );
$assert( 'form signature verifies the way the Central App checks it', hash_equals( hash_hmac( 'sha256', 'https://example.com|Můj web|1790000000|wordpress', $key ), $form['fields']['signature'] ) );

/* ── Local site ── */

$assert( 'local sites are recognised', CentralApp::is_local_site( 'http://localhost/pobyty' ) && CentralApp::is_local_site( 'http://127.0.0.1:8080' ) && CentralApp::is_local_site( 'https://shop.test' ) && CentralApp::is_local_site( 'http://[::1]/' ) );
$assert( 'public sites are not local', ! CentralApp::is_local_site( 'https://example.com' ) && ! CentralApp::is_local_site( 'https://localhost.example.com' ) );

echo PHP_EOL . ( 0 === $failures ? 'ALL PASSED' : $failures . ' FAILED' ) . PHP_EOL;
exit( 0 === $failures ? 0 : 1 );
