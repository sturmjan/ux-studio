<?php
// Test only: never runs over HTTP (the release zip also excludes tests/).
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { exit; }
/**
 * Standalone test (no WordPress) of the consent records (PLAN.md 42, F5): validation of what the public endpoint
 * receives, the normalisation of a consent id, the retention clamp, the CSV (header, rows, paging) and that the
 * schema stores no IP address and no user agent.
 *
 *   php tests/consent-records.php
 *
 * @package UxStudio
 */

// phpcs:disable

define( 'ABSPATH', __DIR__ . '/' );
define( 'ARRAY_A', 'ARRAY_A' );
define( 'DAY_IN_SECONDS', 86400 );
function __( $text, $domain = '' ) { return $text; }

/** Fake $wpdb: just enough for Records::stream_csv() (paging by "id > N ... LIMIT M") and table_exists(). */
class FakeWpdb {
	public string $prefix = 'wp_';
	/** @var array<int, array<string, mixed>> */
	public array $rows = array();
	public array $queries = array();
	public function esc_like( $s ) { return addcslashes( $s, '_%\\' ); }
	public function prepare( $sql, ...$args ) {
		if ( 1 === count( $args ) && is_array( $args[0] ) ) { $args = $args[0]; }
		$i = 0;
		return preg_replace_callback( '/%[ds]/', function ( $m ) use ( &$i, $args ) {
			$v = $args[ $i++ ];
			return '%d' === $m[0] ? (string) (int) $v : "'" . addslashes( (string) $v ) . "'";
		}, $sql );
	}
	public function get_var( $sql ) { return false !== strpos( $sql, 'SHOW TABLES' ) ? 'wp_uxstudio_consent_log' : null; }
	public function get_results( $sql, $mode = null ) {
		$this->queries[] = $sql;
		preg_match( '/id > (\d+)/', $sql, $m );
		preg_match( '/LIMIT (\d+)/', $sql, $l );
		$from = array_filter( $this->rows, static fn( $r ) => $r['id'] > (int) $m[1] );
		return array_slice( array_values( $from ), 0, (int) $l[1] );
	}
}
$GLOBALS['wpdb'] = new FakeWpdb();

$root = dirname( __DIR__ );
require_once $root . '/includes/Core/Csv.php';
require_once $root . '/includes/Modules/Consent/Records.php';

use UxStudio\Modules\Consent\Records;

$failures = 0;
$assert   = static function ( string $name, bool $ok, string $detail = '' ) use ( &$failures ): void {
	if ( ! $ok ) {
		++$failures;
	}
	echo ( $ok ? 'PASS ' : 'FAIL ' ) . $name . ( $ok || '' === $detail ? '' : ' => ' . $detail ) . "\n";
};

$id   = 'a1b2c3d4e5f60718293a4b5c6d7e8f90';
$good = array( 'id' => $id, 'v' => 3, 'c' => 7, 'a' => 'accept_all', 'p' => '/kontakt/' );

// --- clean(): the accepted shapes ---
$row = Records::clean( $good );
$assert( 'a valid record is accepted', null !== $row && $row['consent_id'] === $id && 3 === $row['policy_version'] && 7 === $row['categories'] && 'accept_all' === $row['action'] && '/kontakt/' === $row['page_path'] );
$assert( 'form-encoded strings are accepted (sendBeacon sends URLSearchParams)', null !== Records::clean( array( 'id' => $id, 'v' => '3', 'c' => '2', 'a' => 'custom', 'p' => '/' ) ) );
$noPath = $good;
unset( $noPath['p'] );
$r2 = Records::clean( $noPath );
$assert( 'a record without a path is stored with a null path', null !== $r2 && null === $r2['page_path'] );
$assert( 'reject all with 0 bits', null !== Records::clean( array( 'id' => $id, 'v' => 1, 'c' => 0, 'a' => 'reject_all' ) ) );
$assert( 'withdraw with any bits', null !== Records::clean( array( 'id' => $id, 'v' => 1, 'c' => 0, 'a' => 'withdraw' ) ) && null !== Records::clean( array( 'id' => $id, 'v' => 1, 'c' => 5, 'a' => 'withdraw' ) ) );

// --- clean(): everything else is refused ---
$bad = static fn( array $patch ): bool => null === Records::clean( array_merge( $good, $patch ) );
$assert( 'id: too short', $bad( array( 'id' => substr( $id, 0, 31 ) ) ) );
$assert( 'id: too long', $bad( array( 'id' => $id . '0' ) ) );
$assert( 'id: upper case is refused (the browser makes lower case)', $bad( array( 'id' => strtoupper( $id ) ) ) );
$assert( 'id: non-hex', $bad( array( 'id' => str_repeat( 'g', 32 ) ) ) );
$assert( 'id: SQL-looking text', $bad( array( 'id' => "' OR 1=1 -- aaaaaaaaaaaaaaaaaaaa" ) ) );
$assert( 'id: array', $bad( array( 'id' => array( $id ) ) ) );
$assert( 'version: 0', $bad( array( 'v' => 0 ) ) );
$assert( 'version: 10000', $bad( array( 'v' => 10000 ) ) );
$assert( 'version: text', $bad( array( 'v' => 'abc' ) ) );
$assert( 'version: float', $bad( array( 'v' => 1.5 ) ) );
$assert( 'version: negative', $bad( array( 'v' => '-1' ) ) );
$assert( 'bits: 8', $bad( array( 'c' => 8, 'a' => 'custom' ) ) );
$assert( 'bits: negative', $bad( array( 'c' => -1, 'a' => 'custom' ) ) );
$assert( 'bits: text', $bad( array( 'c' => 'x', 'a' => 'custom' ) ) );
$assert( 'action: unknown', $bad( array( 'a' => 'region' ) ) );
$assert( 'action: empty', $bad( array( 'a' => '' ) ) );
$assert( 'action: array', $bad( array( 'a' => array( 'custom' ) ) ) );
$assert( 'accept all must mean all bits', $bad( array( 'a' => 'accept_all', 'c' => 3 ) ) );
$assert( 'reject all must mean no bits', $bad( array( 'a' => 'reject_all', 'c' => 1 ) ) );
$assert( 'missing id', null === Records::clean( array( 'v' => 1, 'c' => 0, 'a' => 'reject_all' ) ) );

// --- clean_path(): query string, host, odd characters ---
$assert( 'path: query string is cut off', '/a/b' === Records::clean_path( '/a/b?utm_source=x&email=me@example.com' ) );
$assert( 'path: fragment is cut off', '/a/b' === Records::clean_path( '/a/b#top' ) );
$assert( 'path: a full URL is refused', null === Records::clean_path( 'https://evil.example/x' ) );
$assert( 'path: protocol-relative is refused', null === Records::clean_path( '//evil.example/x' ) );
$assert( 'path: spaces and angle brackets drop the value', null === Records::clean_path( '/a b' ) && null === Records::clean_path( '/<script>' ) );
$assert( 'path: control characters drop the value', null === Records::clean_path( "/a\nb" ) && null === Records::clean_path( "/a\0b" ) );
$assert( 'path: percent-encoded text is kept', '/caf%C3%A9/' === Records::clean_path( '/caf%C3%A9/' ) );
$assert( 'path: cut to 255 characters', 255 === strlen( (string) Records::clean_path( '/' . str_repeat( 'a', 400 ) ) ) );
$assert( 'path: absurdly long input is refused', null === Records::clean_path( '/' . str_repeat( 'a', 3000 ) ) );
$assert( 'path: non-string', null === Records::clean_path( array( '/a' ) ) && null === Records::clean_path( null ) );
$assert( 'path: the root with a query is just the root', '/' === Records::clean_path( '/?x=1' ) );

// --- normalize_id(): what an administrator types ---
$assert( 'id from the banner as typed: upper case and dashes', $id === Records::normalize_id( strtoupper( implode( '-', str_split( $id, 8 ) ) ) ) );
$assert( 'id with spaces around', $id === Records::normalize_id( "  $id \n" ) );
$assert( 'id of the wrong length is empty', '' === Records::normalize_id( substr( $id, 1 ) ) );
$assert( 'id with other characters is empty', '' === Records::normalize_id( str_repeat( 'z', 32 ) ) );

// --- retention ---
$assert( 'retention: default is 3 years', 1095 === Records::DEFAULT_DAYS && 1095 === Records::retention_days( 1095 ) );
$assert( 'retention: 0 is not "forever", it is the default', 1095 === Records::retention_days( 0 ) );
$assert( 'retention: junk is the default', 1095 === Records::retention_days( 'abc' ) && 1095 === Records::retention_days( null ) && 1095 === Records::retention_days( -5 ) );
$assert( 'retention: clamped to 30 days at the lowest', 30 === Records::retention_days( 1 ) );
$assert( 'retention: clamped to 3650 days at the highest', 3650 === Records::retention_days( 999999 ) );
$assert( 'retention: a normal value passes', 365 === Records::retention_days( '365' ) );

// --- shape + CSV ---
$db = array( 'id' => '7', 'consent_id' => $id, 'created_at' => '2026-11-09 10:00:00', 'policy_version' => '3', 'categories' => '5', 'action' => 'custom', 'page_path' => '/kontakt/' );
$s  = Records::shape( $db );
$assert( 'shape: bits become booleans', true === $s['preferences'] && false === $s['statistics'] && true === $s['marketing'] );
$assert( 'csv row', array( '7', $id, '2026-11-09 10:00:00', '3', 'yes', 'no', 'yes', 'custom', '/kontakt/' ) === Records::csv_row( $db ) );
$assert( 'csv header has as many columns as a row', count( Records::csv_header() ) === count( Records::csv_row( $db ) ) );
$evil = Records::csv_row( array_merge( $db, array( 'page_path' => '=HYPERLINK("x")', 'action' => '+cmd' ) ) );
$assert( 'csv: formula text is neutralised', "'=HYPERLINK(\"x\")" === $evil[8] && "'+cmd" === $evil[7] );

// --- stream_csv(): paging through 2500 rows in pages of 1000 ---
for ( $i = 1; $i <= 2500; $i++ ) {
	$GLOBALS['wpdb']->rows[] = array_merge( $db, array( 'id' => (string) $i ) );
}
$out   = fopen( 'php://memory', 'w+' );
$count = Records::stream_csv( $out, '2026-11-01', '2026-11-30', $id );
rewind( $out );
$lines = array();
while ( false !== ( $line = fgetcsv( $out ) ) ) { $lines[] = $line; }
$assert( 'export: all rows plus the header, none twice', 2500 === $count && 2501 === count( $lines ) && '1' === $lines[1][0] && '2500' === $lines[2500][0], (string) $count . '/' . count( $lines ) );
$assert( 'export: read in three pages', 3 === count( $GLOBALS['wpdb']->queries ), (string) count( $GLOBALS['wpdb']->queries ) );
$q = $GLOBALS['wpdb']->queries[0];
$assert( 'export: filters are bound in the query', false !== strpos( $q, "created_at >= '2026-11-01 00:00:00'" ) && false !== strpos( $q, "created_at <= '2026-11-30 23:59:59'" ) && false !== strpos( $q, "consent_id = '$id'" ), $q );
$GLOBALS['wpdb']->queries = array();
$out = fopen( 'php://memory', 'w+' );
Records::stream_csv( $out, "2026-11-01'; DROP TABLE x;--", 'nonsense', '' );
$assert( 'export: a malformed date is ignored, not put into SQL', false === strpos( $GLOBALS['wpdb']->queries[0], 'DROP' ) && false !== strpos( $GLOBALS['wpdb']->queries[0], 'WHERE id > 0 ORDER BY' ), $GLOBALS['wpdb']->queries[0] );

// --- privacy of the schema ---
$src = (string) file_get_contents( $root . '/includes/Modules/Consent/Records.php' );
preg_match( '/CREATE TABLE.*?\) \{\$charset\}/s', $src, $m );
$assert( 'schema: no IP address, no user agent', isset( $m[0] ) && ! preg_match( '/\b(ip|ip_hash|ip_address|user_agent|ua)\b/i', $m[0] ), $m[0] ?? 'schema not found' );

echo 0 === $failures ? "ALL OK\n" : "$failures FAILED\n";
exit( 0 === $failures ? 0 : 1 );
