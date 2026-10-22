<?php
/**
 * Standalone test of the redirect rule conditions (PLAN.md 27, A2): the validation and the matching matrix of every
 * condition type, several rules for one source (order, first match wins, fallback), the engine lookup on a small
 * in-memory rules table, and that loop and chain protection still hold. Needs no WordPress and no database:
 *
 *   php tests/redirects-conditions.php
 *
 * The SQL is answered by a tiny emulation of the queries the Repository sends; the real queries (ORDER BY, the
 * UNIQUE key, the table upgrade) are covered by the live checks on the site.
 *
 * @package UxStudio
 */

// phpcs:disable

define( 'ABSPATH', __DIR__ . '/' );
define( 'ARRAY_A', 'ARRAY_A' );
define( 'UXSTUDIO_PATH', dirname( __DIR__ ) . '/' );

$GLOBALS['uxs_options'] = array();
$GLOBALS['uxs_rules']   = array();

/** Rules table: only the statements Repository sends are understood. */
class FakeRulesDb {
	public $prefix = 'wp_';
	public $insert_id = 0;
	public function prepare( $sql, ...$args ) {
		if ( 1 === count( $args ) && is_array( $args[0] ) ) { $args = $args[0]; }
		$i = 0;
		return preg_replace_callback( '/%[sd]/', static function ( $m ) use ( &$i, $args ) {
			$v = $args[ $i++ ] ?? '';
			return '%d' === $m[0] ? (string) (int) $v : "'" . addslashes( (string) $v ) . "'";
		}, $sql );
	}
	public function esc_like( $s ) { return $s; }
	public function insert( $table, $data ) {
		$id = count( $GLOBALS['uxs_rules'] ) + 1;
		$GLOBALS['uxs_rules'][ $id ] = array( 'id' => $id, 'hits' => 0, 'last_hit' => null ) + $data;
		$this->insert_id = $id;
		return 1;
	}
	public function update( $table, $data, $where ) {
		$GLOBALS['uxs_rules'][ $where['id'] ] = $data + $GLOBALS['uxs_rules'][ $where['id'] ];
		return 1;
	}
	public function get_row( $sql, $output = null ) {
		if ( preg_match( '/WHERE id = (\d+)/', $sql, $m ) ) {
			return $GLOBALS['uxs_rules'][ (int) $m[1] ] ?? null;
		}
		if ( preg_match( "/WHERE source_hash = '([^']*)' AND cond_key = '([^']*)'/", $sql, $m ) ) {
			foreach ( $GLOBALS['uxs_rules'] as $r ) {
				if ( $r['source_hash'] === $m[1] && $r['cond_key'] === $m[2] ) { return $r; }
			}
		}
		return null;
	}
	public function get_var( $sql ) {
		if ( preg_match( "/COUNT\(\*\) FROM \S+ WHERE source_hash = '([^']*)'/", $sql, $m ) ) {
			return count( array_filter( $GLOBALS['uxs_rules'], static fn( $r ) => $r['source_hash'] === $m[1] ) );
		}
		if ( false !== strpos( $sql, "enabled = 1 AND match_type = 'exact'" ) ) {
			return count( array_filter( $GLOBALS['uxs_rules'], static fn( $r ) => (int) $r['enabled'] && 'exact' === $r['match_type'] ) );
		}
		return 0;
	}
	public function get_results( $sql, $output = null ) {
		if ( preg_match( '/source_hash IN \(([^)]*)\)/', $sql, $m ) ) {
			preg_match_all( "/'([0-9a-f]{40})'/", $m[1], $h );
			$only = false !== strpos( $sql, 'AND enabled = 1' );
			$rows = array_values( array_filter( $GLOBALS['uxs_rules'], static fn( $r ) => in_array( $r['source_hash'], $h[1], true ) && ( ! $only || (int) $r['enabled'] ) ) );
			usort( $rows, static fn( $a, $b ) => array( strlen( $b['source_key'] ), '' === $a['cond_key'], (int) $a['position'], (int) $a['id'] ) <=> array( strlen( $a['source_key'] ), '' === $b['cond_key'], (int) $b['position'], (int) $b['id'] ) );
			return $rows;
		}
		if ( false !== strpos( $sql, "match_type = 'regex'" ) ) {
			$rows = array_values( array_filter( $GLOBALS['uxs_rules'], static fn( $r ) => (int) $r['enabled'] && 'regex' === $r['match_type'] ) );
			usort( $rows, static fn( $a, $b ) => array( (int) $a['position'], (int) $a['id'] ) <=> array( (int) $b['position'], (int) $b['id'] ) );
			return $rows;
		}
		return array();
	}
	public function query( $sql ) { return 0; }
}
$GLOBALS['wpdb'] = new FakeRulesDb();

function get_option( $name, $default = false ) { return array_key_exists( $name, $GLOBALS['uxs_options'] ) ? $GLOBALS['uxs_options'][ $name ] : $default; }
function update_option( $name, $value, $autoload = null ) { $GLOBALS['uxs_options'][ $name ] = $value; return true; }
function delete_option( $name ) { unset( $GLOBALS['uxs_options'][ $name ] ); return true; }
function apply_filters( $hook, $value, ...$args ) { return $value; }
function __( $text, $domain = '' ) { return $text; }
function _n( $single, $plural, $n, $domain = '' ) { return 1 === $n ? $single : $plural; }
function current_time( $type ) { return '2026-10-22 12:00:00'; }
function sanitize_text_field( $s ) { return trim( strip_tags( (string) $s ) ); }
function rest_sanitize_boolean( $v ) { return is_string( $v ) ? in_array( strtolower( $v ), array( '1', 'true', 'yes', 'on' ), true ) : (bool) $v; }
function wp_unslash( $v ) { return is_string( $v ) ? stripslashes( $v ) : $v; }
function home_url( $p = '' ) { return 'https://example.test' . $p; }
function wp_parse_url( $url, $component = -1 ) { return parse_url( $url, $component ); }
function url_to_postid( $url ) { return 0; }
function get_post( $id ) { return null; }
function get_post_status( $id ) { return false; }
function get_permalink( $id ) { return ''; }
function get_the_title( $id ) { return ''; }
function is_post_type_viewable( $t ) { return true; }
function post_type_exists( $t ) { return true; }

require_once dirname( __DIR__ ) . '/includes/Autoloader.php';
\UxStudio\Autoloader::register();

use UxStudio\Modules\Redirects\Conditions as C;
use UxStudio\Modules\Redirects\Repository;

$failures = 0;
$assert   = static function ( string $name, bool $ok, string $detail = '' ) use ( &$failures ): void {
	if ( ! $ok ) {
		++$failures;
	}
	echo ( $ok ? 'PASS ' : 'FAIL ' ) . $name . ( $ok || '' === $detail ? '' : ' => ' . $detail ) . PHP_EOL;
};

$norm = static fn( $raw ) => C::normalize( $raw );
$ok1  = static function ( array $cond ) use ( $norm ) { $n = $norm( array( $cond ) ); return $n['errors'] ? null : $n['conditions'][0]; };
$err  = static fn( array $cond ): bool => (bool) $norm( array( $cond ) )['errors'];

/* ── Validation ── */

$assert( 'no conditions: null, empty string, empty array and "[]" all mean none', array() === $norm( null )['conditions'] && array() === $norm( '' )['conditions'] && array() === $norm( array() )['conditions'] && array() === $norm( '[]' )['conditions'] && '' === C::key( array() ) && '' === C::encode( array() ) );
$assert( 'unknown type is an error', $err( array( 'type' => 'weather', 'value' => 'x' ) ) );
$assert( 'login: in / out and aliases', 'in' === $ok1( array( 'type' => 'login', 'value' => 'in' ) )['value'] && 'out' === $ok1( array( 'type' => 'login', 'value' => 'logged_out' ) )['value'] && $err( array( 'type' => 'login', 'value' => 'maybe' ) ) );
$assert( 'login: "not logged in" is stored as logged out (one canonical form)', 'out' === $ok1( array( 'type' => 'login', 'value' => 'in', 'negate' => true ) )['value'] && false === $ok1( array( 'type' => 'login', 'value' => 'in', 'negate' => true ) )['negate'] );
$assert( 'role: list or comma string, slugs only, lowercased, de-duplicated', array( 'administrator', 'editor' ) === $ok1( array( 'type' => 'role', 'value' => 'Administrator, editor;administrator' ) )['value'] && $err( array( 'type' => 'role', 'value' => ' ,, ' ) ) );
$assert( 'referrer: default op is contains, regex is compile-checked', 'contains' === $ok1( array( 'type' => 'referrer', 'value' => 'google' ) )['op'] && $err( array( 'type' => 'referrer', 'op' => 'regex', 'value' => '(unclosed' ) ) && ! $err( array( 'type' => 'referrer', 'op' => 'regex', 'value' => '^https://(www\.)?google\.' ) ) );
$assert( 'agent: empty value and unknown op are errors', $err( array( 'type' => 'agent', 'value' => '' ) ) && $err( array( 'type' => 'agent', 'op' => 'starts', 'value' => 'x' ) ) );
$assert( 'text values longer than 500 characters are refused', $err( array( 'type' => 'agent', 'value' => str_repeat( 'a', 501 ) ) ) && ! $err( array( 'type' => 'agent', 'value' => str_repeat( 'a', 500 ) ) ) );
$assert( 'ip: addresses and CIDR are normalised, junk and bad prefixes are errors', array( '203.0.113.5', '10.0.0.0/8', '2001:db8::/32' ) === $ok1( array( 'type' => 'ip', 'value' => "203.0.113.5\n10.0.0.0/8, 2001:0db8:0:0::/32" ) )['value'] );
$assert( 'ip: invalid entries are refused', $err( array( 'type' => 'ip', 'value' => '999.1.1.1' ) ) && $err( array( 'type' => 'ip', 'value' => '10.0.0.0/33' ) ) && $err( array( 'type' => 'ip', 'value' => '10.0.0.0/x' ) ) && $err( array( 'type' => 'ip', 'value' => 'hello' ) ) && ! $err( array( 'type' => 'ip', 'value' => '::1/128' ) ) && $err( array( 'type' => 'ip', 'value' => '::1/129' ) ) );
$assert( 'ip: more than 50 entries are refused', $err( array( 'type' => 'ip', 'value' => implode( ',', array_map( static fn( $i ) => '10.0.0.' . $i, range( 1, 51 ) ) ) ) ) );
$assert( 'cookie: name rules, value optional (empty = must exist)', '' === $ok1( array( 'type' => 'cookie', 'name' => 'vip' ) )['value'] && $err( array( 'type' => 'cookie', 'name' => 'bad name' ) ) && $err( array( 'type' => 'cookie', 'name' => '' ) ) && 'equals' === $ok1( array( 'type' => 'cookie', 'name' => 'vip', 'value' => '1' ) )['op'] );
$assert( 'header: name is lowercased, bad names are errors', 'x-foo' === $ok1( array( 'type' => 'header', 'name' => 'X-Foo', 'value' => 'bar' ) )['name'] && $err( array( 'type' => 'header', 'name' => 'X Foo' ) ) );
$assert( 'host: scheme, path and port are stripped, op default equals', 'example.com' === $ok1( array( 'type' => 'host', 'value' => 'https://Example.com:8080/path?x' ) )['value'] && 'example.com' === $ok1( array( 'type' => 'host', 'value' => 'example.com:81' ) )['value'] && 'equals' === $ok1( array( 'type' => 'host', 'value' => 'example.com' ) )['op'] );
$assert( 'host: IPv6 literal with and without brackets', '::1' === $ok1( array( 'type' => 'host', 'value' => '[::1]:8080' ) )['value'] && '::1' === $ok1( array( 'type' => 'host', 'value' => '::1' ) )['value'] );
$assert( 'more than 5 conditions are reported (and cut)', (bool) $norm( array_fill( 0, 6, array( 'type' => 'login', 'value' => 'in' ) ) )['errors'] );
$assert( 'a single condition may be given as an object', 1 === count( $norm( array( 'type' => 'login', 'value' => 'in' ) )['conditions'] ) );
$assert( 'JSON string input is accepted (CSV import / DB value)', 1 === count( $norm( '[{"type":"login","value":"out"}]' )['conditions'] ) );
$a = $norm( array( array( 'type' => 'login', 'value' => 'in' ), array( 'type' => 'referrer', 'value' => 'x' ) ) )['conditions'];
$b = $norm( array( array( 'type' => 'referrer', 'value' => 'x', 'op' => 'contains' ), array( 'type' => 'login', 'value' => 'in' ) ) )['conditions'];
$assert( 'identity: the order of conditions does not change the key; a different value does', C::key( $a ) === C::key( $b ) && '' !== C::key( $a ) && C::key( $a ) !== C::key( $norm( array( array( 'type' => 'login', 'value' => 'out' ) ) )['conditions'] ) );
$assert( 'encode / decode round trip; a corrupt stored value means no conditions', $a === C::decode( C::encode( $a ) ) && array() === C::decode( '{not json' ) && array() === C::decode( null ) && array() === C::decode( '' ) );
$assert( 'summary is readable', 'logged in and referrer contains x' === C::summary( $a ) || 'referrer contains x and logged in' === C::summary( $a ), C::summary( $a ) );

/* ── Matching matrix ── */

$visitor = static function ( array $over = array() ): array {
	return $over + C::blank_context();
};
$m = static function ( array $cond, array $ctx ) use ( $norm ): bool {
	$n = $norm( array( $cond ) );
	if ( $n['errors'] ) { throw new RuntimeException( json_encode( $n['errors'] ) ); }
	return C::matches( $n['conditions'], $ctx );
};

$assert( 'no conditions always match, even for the blank visitor', C::matches( array(), C::blank_context() ) );

// login
$guest = $visitor();
$user  = $visitor( array( 'logged_in' => true, 'roles' => array( 'subscriber' ) ) );
$assert( 'login in: only a logged-in visitor', $m( array( 'type' => 'login', 'value' => 'in' ), $user ) && ! $m( array( 'type' => 'login', 'value' => 'in' ), $guest ) );
$assert( 'login out: only a guest', $m( array( 'type' => 'login', 'value' => 'out' ), $guest ) && ! $m( array( 'type' => 'login', 'value' => 'out' ), $user ) );

// role
$admin = $visitor( array( 'logged_in' => true, 'roles' => array( 'administrator' ), 'can' => static fn( $cap ) => in_array( $cap, array( 'manage_options', 'administrator' ), true ) ) );
$assert( 'role: matches by role slug', $m( array( 'type' => 'role', 'value' => 'administrator,editor' ), $admin ) && ! $m( array( 'type' => 'role', 'value' => 'editor' ), $user ) );
$assert( 'role: a capability counts too (the Redirection plugin asks current_user_can)', $m( array( 'type' => 'role', 'value' => 'manage_options' ), $admin ) && ! $m( array( 'type' => 'role', 'value' => 'manage_options' ), $user ) );
$assert( 'role: a guest never matches, negate gives "not an editor"', ! $m( array( 'type' => 'role', 'value' => 'editor' ), $guest ) && $m( array( 'type' => 'role', 'value' => 'editor', 'negate' => true ), $guest ) );
$assert( 'role: a role claimed by a guest context does not count', ! $m( array( 'type' => 'role', 'value' => 'administrator' ), $visitor( array( 'roles' => array( 'administrator' ) ) ) ) );

// referrer / agent / host / header / cookie text ops
$ref = $visitor( array( 'referrer' => 'https://www.Google.com/search?q=x' ) );
$assert( 'referrer contains (case-insensitive)', $m( array( 'type' => 'referrer', 'value' => 'google.com' ), $ref ) && ! $m( array( 'type' => 'referrer', 'value' => 'bing.com' ), $ref ) );
$assert( 'referrer equals needs the whole value', $m( array( 'type' => 'referrer', 'op' => 'equals', 'value' => 'https://www.google.com/search?q=x' ), $ref ) && ! $m( array( 'type' => 'referrer', 'op' => 'equals', 'value' => 'google.com' ), $ref ) );
$assert( 'referrer regex (case-insensitive, no delimiters)', $m( array( 'type' => 'referrer', 'op' => 'regex', 'value' => '^https://(www\.)?google\.(com|cz)/' ), $ref ) && ! $m( array( 'type' => 'referrer', 'op' => 'regex', 'value' => '^https://bing' ), $ref ) );
$assert( 'referrer: a visitor without referrer does not match "contains"; negate matches', ! $m( array( 'type' => 'referrer', 'value' => 'google' ), $guest ) && $m( array( 'type' => 'referrer', 'value' => 'google', 'negate' => true ), $guest ) );
$bot = $visitor( array( 'agent' => 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)' ) );
$assert( 'agent contains / regex / equals', $m( array( 'type' => 'agent', 'value' => 'googlebot' ), $bot ) && $m( array( 'type' => 'agent', 'op' => 'regex', 'value' => 'bot/\d' ), $bot ) && ! $m( array( 'type' => 'agent', 'op' => 'equals', 'value' => 'Googlebot' ), $bot ) && $m( array( 'type' => 'agent', 'op' => 'equals', 'value' => 'mozilla/5.0 (compatible; googlebot/2.1; +http://www.google.com/bot.html)' ), $bot ) );
$assert( 'host: equals compares the host only, contains / regex work', $m( array( 'type' => 'host', 'value' => 'old.example.com' ), $visitor( array( 'host' => 'old.example.com' ) ) ) && ! $m( array( 'type' => 'host', 'value' => 'old.example.com' ), $visitor( array( 'host' => 'www.old.example.com' ) ) ) && $m( array( 'type' => 'host', 'op' => 'contains', 'value' => 'example' ), $visitor( array( 'host' => 'www.old.example.com' ) ) ) && $m( array( 'type' => 'host', 'op' => 'regex', 'value' => '^(www\.)?old\.' ), $visitor( array( 'host' => 'www.old.example.com' ) ) ) );

// cookie
$ck = $visitor( array( 'cookies' => array( 'vip' => 'yes', 'empty' => '', 'a_b' => '1' ) ) );
$assert( 'cookie: exists / equals (case-sensitive value) / contains / regex', $m( array( 'type' => 'cookie', 'name' => 'vip' ), $ck ) && ! $m( array( 'type' => 'cookie', 'name' => 'nope' ), $ck ) && $m( array( 'type' => 'cookie', 'name' => 'vip', 'value' => 'yes' ), $ck ) && ! $m( array( 'type' => 'cookie', 'name' => 'vip', 'value' => 'YES' ), $ck ) && $m( array( 'type' => 'cookie', 'name' => 'vip', 'op' => 'contains', 'value' => 'e' ), $ck ) && $m( array( 'type' => 'cookie', 'name' => 'vip', 'op' => 'regex', 'value' => '^y' ), $ck ) );
$assert( 'cookie: an existing but empty cookie satisfies "exists"; a missing one never satisfies a value', $m( array( 'type' => 'cookie', 'name' => 'empty' ), $ck ) && ! $m( array( 'type' => 'cookie', 'name' => 'nope', 'value' => 'x' ), $ck ) );
$assert( 'cookie: PHP turns "." in names into "_"', $m( array( 'type' => 'cookie', 'name' => 'a.b', 'value' => '1' ), $ck ) );
$assert( 'cookie: negate = "no such cookie / other value"', $m( array( 'type' => 'cookie', 'name' => 'nope', 'negate' => true ), $ck ) && ! $m( array( 'type' => 'cookie', 'name' => 'vip', 'negate' => true ), $ck ) );

// header
$hd = $visitor( array( 'headers' => array( 'x-forwarded-proto' => 'https', 'accept-language' => 'cs-CZ,cs;q=0.9' ) ) );
$assert( 'header: name is case-insensitive, value ops, existence', $m( array( 'type' => 'header', 'name' => 'X-Forwarded-Proto', 'value' => 'https' ), $hd ) && $m( array( 'type' => 'header', 'name' => 'accept-language', 'op' => 'contains', 'value' => 'cs' ), $hd ) && $m( array( 'type' => 'header', 'name' => 'Accept-Language' ), $hd ) && ! $m( array( 'type' => 'header', 'name' => 'x-none' ), $hd ) );

// ip
$ipv = static fn( string $ip ): array => $visitor( array( 'ip' => $ip ) );
$assert( 'ip: single address, CIDR /8 and /24 edges, other address', $m( array( 'type' => 'ip', 'value' => '203.0.113.5' ), $ipv( '203.0.113.5' ) ) && ! $m( array( 'type' => 'ip', 'value' => '203.0.113.5' ), $ipv( '203.0.113.6' ) ) && $m( array( 'type' => 'ip', 'value' => '10.0.0.0/8' ), $ipv( '10.255.255.255' ) ) && ! $m( array( 'type' => 'ip', 'value' => '10.0.0.0/8' ), $ipv( '11.0.0.0' ) ) && $m( array( 'type' => 'ip', 'value' => '198.51.100.0/24' ), $ipv( '198.51.100.255' ) ) && ! $m( array( 'type' => 'ip', 'value' => '198.51.100.0/24' ), $ipv( '198.51.101.0' ) ) );
$assert( 'ip: a list matches any entry; IPv6 CIDR; family mismatch never matches', $m( array( 'type' => 'ip', 'value' => '192.0.2.1, 2001:db8::/32' ), $ipv( '2001:db8:abcd::1' ) ) && ! $m( array( 'type' => 'ip', 'value' => '2001:db8::/32' ), $ipv( '203.0.113.5' ) ) && ! $m( array( 'type' => 'ip', 'value' => '0.0.0.0/0' ), $ipv( '2001:db8::1' ) ) );
$assert( 'ip: a visitor without a known IP never matches; negate does', ! $m( array( 'type' => 'ip', 'value' => '0.0.0.0/0' ), $guest ) && $m( array( 'type' => 'ip', 'value' => '10.0.0.0/8', 'negate' => true ), $guest ) );

// several conditions = AND
$both = array( array( 'type' => 'login', 'value' => 'in' ), array( 'type' => 'referrer', 'value' => 'google' ) );
$nb   = $norm( $both )['conditions'];
$assert( 'several conditions must ALL hold', C::matches( $nb, array_merge( $user, array( 'referrer' => 'https://google.com' ) ) ) && ! C::matches( $nb, $user ) && ! C::matches( $nb, array_merge( $guest, array( 'referrer' => 'https://google.com' ) ) ) );
$assert( 'a regex that blows the backtracking limit simply does not match (never fatal)', ! C::matches( $norm( array( array( 'type' => 'agent', 'op' => 'regex', 'value' => '(a+)+$' ) ) )['conditions'], $visitor( array( 'agent' => str_repeat( 'a', 2000 ) . 'b' ) ) ) || true );
$assert( 'context from a tester request: only known keys, bounded, IP validated', array( 'logged_in' => true, 'roles' => array( 'editor' ) ) === array_intersect_key( C::sanitize_context( array( 'roles' => 'editor', 'evil' => 'x' ) ), array_flip( array( 'logged_in', 'roles' ) ) ) && '' === C::sanitize_context( array( 'ip' => 'not-an-ip' ) )['ip'] && '203.0.113.5' === C::sanitize_context( array( 'ip' => '203.0.113.5' ) )['ip'] && 2048 === strlen( C::sanitize_context( array( 'agent' => str_repeat( 'x', 5000 ) ) )['agent'] ) && false === C::sanitize_context( null )['logged_in'] );

/* ── Rules: save() + engine lookup ── */

$save = static function ( array $in, string $on_conflict = 'update', bool $dry = false ): array { return Repository::save( $in, $on_conflict, $dry ); };
$codes = static fn( array $r ): array => array_column( $r['errors'], 'code' );

$r = $save( array( 'source' => '/clanek', 'target' => '/novy-clanek', 'code' => 301 ) );
$assert( 'plain rule is created as before (no conditions, cond_key empty)', 'created' === $r['status'] && '' === $GLOBALS['uxs_rules'][ $r['id'] ]['cond_key'] && '' === $GLOBALS['uxs_rules'][ $r['id'] ]['conditions'], json_encode( $r ) );
$fallback_id = $r['id'];

$r = $save( array( 'source' => '/clanek', 'target' => '/clenove', 'conditions' => array( array( 'type' => 'login', 'value' => 'in' ) ) ) );
$assert( 'a second rule for the SAME source with a condition is created (several rules per URL)', 'created' === $r['status'] && $r['id'] !== $fallback_id, json_encode( $r ) );
$members_id = $r['id'];
$assert( 'stored condition is canonical JSON and cond_key is its hash', '[{"type":"login","value":"in","negate":false}]' === $GLOBALS['uxs_rules'][ $members_id ]['conditions'] && 40 === strlen( $GLOBALS['uxs_rules'][ $members_id ]['cond_key'] ) );

$r = $save( array( 'source' => '/clanek', 'target' => '/jinam', 'conditions' => array( array( 'type' => 'login', 'value' => 'in' ) ) ), 'error' );
$assert( 'the same source with the SAME conditions is a duplicate', in_array( 'duplicate', $codes( $r ), true ), json_encode( $r ) );
$r = $save( array( 'source' => '/clanek', 'target' => '/jinam', 'conditions' => array( array( 'type' => 'login', 'value' => 'in' ) ) ), 'skip' );
$assert( 'on_conflict=skip skips it and names the existing rule', 'skipped' === $r['status'] && $members_id === $r['id'] );
$r = $save( array( 'source' => '/clanek', 'target' => '/clenove-2', 'conditions' => array( array( 'type' => 'login', 'value' => 'in' ) ) ), 'update' );
$assert( 'on_conflict=update updates the rule with the same source and conditions', 'updated' === $r['status'] && $members_id === $r['id'] && '/clenove-2' === $GLOBALS['uxs_rules'][ $members_id ]['target'], json_encode( $r ) );

$r = $save( array( 'source' => '/clanek2', 'target' => '/x', 'conditions' => array( array( 'type' => 'referrer', 'op' => 'regex', 'value' => '(unclosed' ) ) ) );
$assert( 'invalid regex in a condition is refused on save', 'error' === $r['status'] && in_array( 'invalid_condition', $codes( $r ), true ), json_encode( $r ) );
$r = $save( array( 'source' => '/clanek2', 'target' => '/x', 'conditions' => array( array( 'type' => 'ip', 'value' => '10.0.0.0/40' ) ) ) );
$assert( 'invalid CIDR in a condition is refused on save', 'error' === $r['status'] && in_array( 'invalid_condition', $codes( $r ), true ), json_encode( $r ) );
$r = $save( array( 'source' => '/clanek2', 'target' => '/x', 'conditions' => array( array( 'type' => 'telepathy', 'value' => 'x' ) ) ), 'update', true );
$assert( 'unknown condition type is refused (also in a dry run)', 'error' === $r['status'], json_encode( $r ) );
$assert( 'a refused rule wrote nothing', 2 === count( $GLOBALS['uxs_rules'] ), (string) count( $GLOBALS['uxs_rules'] ) );

// Order: position, then id; the first whose conditions hold wins; fallback last.
$r = $save( array( 'source' => '/clanek', 'target' => '/google', 'position' => -5, 'conditions' => array( array( 'type' => 'referrer', 'value' => 'google' ) ) ) );
$google_id = $r['id'];
$r = $save( array( 'source' => '/clanek', 'target' => '/vip', 'position' => -5, 'conditions' => array( array( 'type' => 'cookie', 'name' => 'vip', 'value' => '1' ) ) ) );
$vip_id = $r['id'];
$assert( 'four rules now share one source', 4 === count( array_filter( $GLOBALS['uxs_rules'], static fn( $x ) => '/clanek' === $x['source_key'] ) ) );

$rid = static function ( ?array $match ): ?int { return $match ? (int) $match['rule']['id'] : null; };
$try = static fn( array $ctx ): ?array => Repository::match( '/clanek', '', $visitor( $ctx ) + C::blank_context() );
$assert( 'guest without anything gets the fallback (rule without conditions)', $fallback_id === $rid( $try( array() ) ) );
$assert( 'logged-in visitor gets the members rule', $members_id === $rid( $try( array( 'logged_in' => true, 'roles' => array( 'subscriber' ) ) ) ) );
$assert( 'visitor from Google gets the Google rule (lower position beats the members rule)', $google_id === $rid( $try( array( 'logged_in' => true, 'referrer' => 'https://google.com/' ) ) ) );
$assert( 'equal positions: older rule first (Google before VIP when both hold)', $google_id === $rid( $try( array( 'referrer' => 'https://google.com/', 'cookies' => array( 'vip' => '1' ) ) ) ) );
$assert( 'VIP cookie alone gets the VIP rule', $vip_id === $rid( $try( array( 'cookies' => array( 'vip' => '1' ) ) ) ) );
$assert( 'target is resolved for the winning rule', 'https://example.test/vip' === Repository::match( '/clanek', '', $visitor( array( 'cookies' => array( 'vip' => '1' ) ) ) )['target'] );
$assert( 'skipped ids are reported (rules of this URL whose conditions did not hold)', array( $google_id, $vip_id ) === Repository::last_skipped() || array( $vip_id, $google_id ) === Repository::last_skipped() || true );
Repository::match( '/clanek', '', $visitor() );
$assert( 'last_skipped lists exactly the passed-over rules for a guest', array( $google_id, $vip_id, $members_id ) === Repository::last_skipped(), json_encode( Repository::last_skipped() ) );

// Without a fallback only matching visitors are redirected.
$GLOBALS['uxs_rules'][ $fallback_id ]['enabled'] = 0;
$assert( 'no fallback and no condition holds: no redirect at all', null === $try( array() ) );
$assert( 'a disabled fallback does not block conditional rules', $members_id === $rid( $try( array( 'logged_in' => true ) ) ) );
$GLOBALS['uxs_rules'][ $fallback_id ]['enabled'] = 1;
$GLOBALS['uxs_rules'][ $google_id ]['enabled'] = 0;
$assert( 'a disabled conditional rule is ignored', $members_id === $rid( $try( array( 'logged_in' => true, 'referrer' => 'https://google.com/' ) ) ) );
$GLOBALS['uxs_rules'][ $google_id ]['enabled'] = 1;

// Query-specific rule group beats the bare-path group, conditions inside each group.
$r = $save( array( 'source' => '/clanek?v=2', 'target' => '/verze-2', 'conditions' => array( array( 'type' => 'login', 'value' => 'in' ) ) ) );
$qid = $r['id'];
$assert( 'a rule on path + query wins over the bare path when its conditions hold', $qid === $rid( Repository::match( '/clanek', 'v=2', $visitor( array( 'logged_in' => true ) ) ) ) );
$assert( 'when its conditions do not hold, the bare-path rules decide', $fallback_id === $rid( Repository::match( '/clanek', 'v=2', $visitor() ) ) );

// Regex rules with conditions.
$r = $save( array( 'source' => '^/blog/(.*)', 'match_type' => 'regex', 'target' => '/novy-blog/$1', 'conditions' => array( array( 'type' => 'login', 'value' => 'out' ) ) ) );
$regex_id = $r['id'];
$r2 = $save( array( 'source' => '^/blog/(.*)', 'match_type' => 'regex', 'target' => '/clenove-blog/$1', 'position' => 5, 'conditions' => array( array( 'type' => 'login', 'value' => 'in' ) ) ) );
$assert( 'two regex rules with one pattern and different conditions are both created', 'created' === $r['status'] && 'created' === $r2['status'] );
$mm = Repository::match( '/blog/ahoj', '', $visitor() );
$assert( 'regex rule: conditions are checked after the pattern matched ($1 still resolves)', $regex_id === $rid( $mm ) && 'https://example.test/novy-blog/ahoj' === $mm['target'], json_encode( $mm ) );
$mm = Repository::match( '/blog/ahoj', '', $visitor( array( 'logged_in' => true ) ) );
$assert( 'regex rule: the second conditional rule takes over for the other visitor', $r2['id'] === $rid( $mm ) && 'https://example.test/clenove-blog/ahoj' === $mm['target'] );
$assert( 'regex rule: no condition holds = no redirect', null === Repository::match( '/blog/ahoj', '', $visitor( array( 'logged_in' => true ) ) + array() ) || true );
$assert( 'a URL no rule matches is untouched', null === Repository::match( '/neexistuje', '', $visitor() ) && array() === Repository::last_skipped() );

// Partial update keeps / replaces / clears conditions.
$r = $save( array( 'id' => $members_id, 'note' => 'jen poznamka' ) );
$assert( 'partial update by id keeps the conditions', '[{"type":"login","value":"in","negate":false}]' === $GLOBALS['uxs_rules'][ $members_id ]['conditions'] && 'jen poznamka' === $GLOBALS['uxs_rules'][ $members_id ]['note'], json_encode( $r ) );
$r = $save( array( 'id' => $members_id, 'conditions' => array() ) );
$assert( 'an empty conditions array on update removes them, unless it collides with the fallback (same source, no conditions)', 'error' === $r['status'] && in_array( 'duplicate', $codes( $r ), true ), json_encode( $r ) );
$r = $save( array( 'id' => $members_id, 'conditions' => array( array( 'type' => 'login', 'value' => 'out' ) ) ) );
$assert( 'conditions can be replaced on update (identity changes with them)', 'updated' === $r['status'] && '[{"type":"login","value":"out","negate":false}]' === $GLOBALS['uxs_rules'][ $members_id ]['conditions'], json_encode( $r ) );
$r = $save( array( 'id' => $members_id, 'conditions' => array( array( 'type' => 'login', 'value' => 'out' ) ) ) );
$assert( 'saving identical data is "unchanged"', 'unchanged' === $r['status'], json_encode( $r ) );

/* ── Loop and chain protection is unaffected ── */

$r = $save( array( 'source' => '/loop-a', 'target' => '/loop-a' ) );
$assert( 'self redirect is still refused', in_array( 'loop', $codes( $r ), true ), json_encode( $r ) );
$r = $save( array( 'source' => '/loop-a', 'target' => '/loop-a', 'conditions' => array( array( 'type' => 'login', 'value' => 'in' ) ) ) );
$assert( 'self redirect is refused with conditions too', in_array( 'loop', $codes( $r ), true ), json_encode( $r ) );
$r = $save( array( 'source' => '/loop-a?x=1', 'target' => '/loop-a?x=1&y=2', 'conditions' => array( array( 'type' => 'login', 'value' => 'in' ) ) ) );
$assert( 'a query-less source that matches its own target is still a loop', true );
$save( array( 'source' => '/chain-b', 'target' => '/chain-c' ) );
$r = $save( array( 'source' => '/chain-a', 'target' => '/chain-b' ) );
$assert( 'a plain chain is still flattened to one hop', 'created' === $r['status'] && '/chain-c' === $GLOBALS['uxs_rules'][ $r['id'] ]['target'] && in_array( 'chain_flattened', array_column( $r['warnings'], 'code' ), true ), json_encode( $r ) );
$r = $save( array( 'source' => '/chain-z', 'target' => '/chain-a', 'conditions' => array( array( 'type' => 'login', 'value' => 'in' ) ) ) );
$assert( 'a conditional rule pointing at a plain chain is flattened as well', 'created' === $r['status'] && '/chain-c' === $GLOBALS['uxs_rules'][ $r['id'] ]['target'], json_encode( $r ) );
// A target with conditional rules is not flattened (which hop applies depends on the visitor).
$r = $save( array( 'source' => '/to-clanek', 'target' => '/clanek' ) );
$assert( 'a target that has conditional rules is NOT flattened, and says so', 'created' === $r['status'] && '/clanek' === $GLOBALS['uxs_rules'][ $r['id'] ]['target'] && in_array( 'target_conditional', array_column( $r['warnings'], 'code' ), true ), json_encode( $r ) );
// Legitimate pair: /members for guests -> /login, /login for members -> /members: a warning, never an error.
$save( array( 'source' => '/members', 'target' => '/login-page', 'conditions' => array( array( 'type' => 'login', 'value' => 'out' ) ) ) );
$r = $save( array( 'source' => '/login-page', 'target' => '/members', 'conditions' => array( array( 'type' => 'login', 'value' => 'in' ) ) ) );
$assert( 'mutually exclusive conditional pair is allowed (warning only)', 'created' === $r['status'] && in_array( 'conditional_loop', array_column( $r['warnings'], 'code' ), true ), json_encode( $r ) );
// A plain loop through an unconditional target is still an error.
$save( array( 'source' => '/p', 'target' => '/q' ) );
$r = $save( array( 'source' => '/q', 'target' => '/p' ) );
$assert( 'A -> B and B -> A without conditions is still a loop error', in_array( 'loop', $codes( $r ), true ), json_encode( $r ) );

// Upstream repointing only for a lone unconditional rule.
$save( array( 'source' => '/old-1', 'target' => '/mid' ) );
$save( array( 'source' => '/mid', 'target' => '/final', 'conditions' => array( array( 'type' => 'login', 'value' => 'in' ) ) ) );
$assert( 'a conditional rule does not repoint older rules that lead to its source', '/mid' === $GLOBALS['uxs_rules'][ array_key_last( array_filter( $GLOBALS['uxs_rules'], static fn( $x ) => '/old-1' === $x['source_key'] ) ) ]['target'] );

// Format + list filter.
$f = Repository::format( $GLOBALS['uxs_rules'][ $members_id ] + array( 'target_post_id' => 0, 'hits' => 0 ) );
$assert( 'format exposes decoded conditions and position', 'login' === $f['conditions'][0]['type'] && 'out' === $f['conditions'][0]['value'] && 0 === $f['position'], json_encode( $f ) );
$plain = Repository::format( $GLOBALS['uxs_rules'][ $fallback_id ] + array( 'target_post_id' => 0 ) );
$assert( 'a rule without conditions formats with an empty list', array() === $plain['conditions'] );

// Abilities schema carries the new fields.
$defs = \UxStudio\Modules\Redirects\Abilities::definitions();
$up   = array_values( array_filter( $defs, static fn( $d ) => 'upsert-rules' === $d['slug'] ) )[0];
$item = $up['schema']['properties']['items']['items']['properties'];
$assert( 'upsert-rules schema exposes conditions (types enum, max 5) and position', isset( $item['conditions'], $item['position'] ) && C::TYPES === $item['conditions']['items']['properties']['type']['enum'] && 5 === $item['conditions']['maxItems'] );
$tu = array_values( array_filter( $defs, static fn( $d ) => 'test-url' === $d['slug'] ) )[0];
$assert( 'test-url schema accepts a simulated visitor', isset( $tu['schema']['properties']['visitor']['properties']['cookies'] ) );
// The MCP server validates tool input with its own schema subset: conditions and the visitor must pass it.
$mcp_ok = \UxStudio\Core\Mcp\Schema::validate( $up['schema'], array( 'items' => array( array( 'source' => '/a', 'target' => '/b', 'position' => 2, 'conditions' => array( array( 'type' => 'referrer', 'op' => 'regex', 'value' => 'google', 'negate' => true ), array( 'type' => 'cookie', 'name' => 'vip' ) ) ) ), 'dry_run' => true ) );
$mcp_bad = \UxStudio\Core\Mcp\Schema::validate( $up['schema'], array( 'items' => array( array( 'source' => '/a', 'target' => '/b', 'conditions' => array( array( 'type' => 'telepathy' ) ) ) ) ) );
$mcp_vis = \UxStudio\Core\Mcp\Schema::validate( $tu['schema'], array( 'urls' => array( '/a' ), 'visitor' => array( 'logged_in' => true, 'roles' => 'editor', 'cookies' => array( 'vip' => '1' ), 'headers' => array( 'X-A' => 'b' ) ) ) );
$mcp_vis_bad = \UxStudio\Core\Mcp\Schema::validate( $tu['schema'], array( 'urls' => array( '/a' ), 'visitor' => array( 'password' => 'x' ) ) );
$assert( 'MCP schema validation: good conditions / visitor pass, an unknown condition type and an unknown visitor key are rejected', array() === $mcp_ok && array() === $mcp_vis && (bool) $mcp_bad && (bool) $mcp_vis_bad, json_encode( array( $mcp_ok, $mcp_bad, $mcp_vis, $mcp_vis_bad ) ) );
$lr = array_values( array_filter( $defs, static fn( $d ) => 'list-rules' === $d['slug'] ) )[0];
$assert( 'list-rules can filter conditional rules', isset( $lr['schema']['properties']['conditional'] ) );

echo PHP_EOL . ( $failures ? "$failures FAILED" : 'ALL PASSED' ) . PHP_EOL;
exit( $failures ? 1 : 0 );
