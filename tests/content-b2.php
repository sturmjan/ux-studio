<?php
/**
 * Standalone test of PLAN.md 27, B2 ("Obsah I"): the redirect hit counter and the per-rule options (letter case, query
 * string handling) with their interplay with conditions, CSV round trip, ability schemas; the code snippet run
 * conditions (validation and the matching matrix); the Dashicons set of the menu icons; the comment exceptions of the
 * Security module (roles, comment types, closing old posts). Needs no WordPress and no database:
 *
 *   php tests/content-b2.php
 *
 * The SQL of the rules table is answered by a tiny emulation of the statements the Repository sends; the real queries,
 * the table upgrade and the front end are covered by the live checks on the site.
 *
 * @package UxStudio
 */

// phpcs:disable

define( 'ABSPATH', __DIR__ . '/' );
define( 'ARRAY_A', 'ARRAY_A' );
define( 'UXSTUDIO_PATH', dirname( __DIR__ ) . '/' );
define( 'DAY_IN_SECONDS', 86400 );
define( 'HOUR_IN_SECONDS', 3600 );

$GLOBALS['uxs_options'] = array();
$GLOBALS['uxs_rules']   = array();
$GLOBALS['uxs_actions'] = array();
$GLOBALS['uxs_writes']  = array(); // Every update_option / delete_option call: the comment rules must not write core options.
$GLOBALS['uxs_posts']   = array();
$GLOBALS['uxs_user']    = array( 'id' => 0, 'roles' => array(), 'super' => false );

class FakeRulesDb {
	public $prefix = 'wp_';
	public $insert_id = 0;
	public $queries = array();
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
		$GLOBALS['uxs_rules'][ $id ] = $data + array( 'id' => $id, 'hits' => 0, 'last_hit' => null );
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
		if ( false !== strpos( $sql, 'ORDER BY id ASC' ) ) {
			$rows = array_values( $GLOBALS['uxs_rules'] );
			usort( $rows, static fn( $a, $b ) => (int) $a['id'] <=> (int) $b['id'] );
			return $rows;
		}
		return array();
	}
	public function query( $sql ) {
		$this->queries[] = $sql;
		if ( preg_match( "/SET hits = hits \+ (\d+), last_hit = '([^']*)' WHERE id = (\d+)/", $sql, $m ) ) {
			$GLOBALS['uxs_rules'][ (int) $m[3] ]['hits']    = (int) $GLOBALS['uxs_rules'][ (int) $m[3] ]['hits'] + (int) $m[1];
			$GLOBALS['uxs_rules'][ (int) $m[3] ]['last_hit'] = $m[2];
			return 1;
		}
		if ( preg_match( '/SET hits = 0, last_hit = NULL WHERE id IN \(([^)]*)\)/', $sql, $m ) ) {
			$n = 0;
			foreach ( array_map( 'intval', explode( ',', $m[1] ) ) as $id ) {
				if ( isset( $GLOBALS['uxs_rules'][ $id ] ) ) { $GLOBALS['uxs_rules'][ $id ]['hits'] = 0; $GLOBALS['uxs_rules'][ $id ]['last_hit'] = null; ++$n; }
			}
			return $n;
		}
		if ( false !== strpos( $sql, 'SET hits = 0, last_hit = NULL WHERE hits > 0' ) ) {
			$n = 0;
			foreach ( $GLOBALS['uxs_rules'] as $id => $r ) {
				if ( (int) $r['hits'] > 0 || null !== $r['last_hit'] ) { $GLOBALS['uxs_rules'][ $id ]['hits'] = 0; $GLOBALS['uxs_rules'][ $id ]['last_hit'] = null; ++$n; }
			}
			return $n;
		}
		return 0;
	}
}
$GLOBALS['wpdb'] = new FakeRulesDb();

function get_option( $name, $default = false ) { return array_key_exists( $name, $GLOBALS['uxs_options'] ) ? $GLOBALS['uxs_options'][ $name ] : $default; }
function update_option( $name, $value, $autoload = null ) { $GLOBALS['uxs_options'][ $name ] = $value; if ( 'uxstudio_redirects_state' !== $name ) { $GLOBALS['uxs_writes'][] = $name; } return true; }
function delete_option( $name ) { unset( $GLOBALS['uxs_options'][ $name ] ); $GLOBALS['uxs_writes'][] = $name; return true; }
function add_action( $hook, $callback, $priority = 10, $args = 1 ) { $GLOBALS['uxs_actions'][ $hook ][] = array( $callback, $priority ); return true; }
function add_filter( $hook, $callback, $priority = 10, $args = 1 ) { return add_action( $hook, $callback, $priority, $args ); }
function apply_filters( $hook, $value, ...$args ) { return $value; }
function __( $text, $domain = '' ) { return $text; }
function esc_html__( $text, $domain = '' ) { return $text; }
function _n( $single, $plural, $n, $domain = '' ) { return 1 === $n ? $single : $plural; }
function current_time( $type ) { return '2026-10-22 12:00:00'; }
function sanitize_text_field( $s ) { return trim( strip_tags( (string) $s ) ); }
function sanitize_key( $s ) { return strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', (string) $s ) ); }
function sanitize_html_class( $s ) { return preg_replace( '/[^A-Za-z0-9_-]/', '', (string) $s ); }
function wp_json_encode( $v, $flags = 0 ) { return json_encode( $v, $flags ); }
function rest_sanitize_boolean( $v ) { return is_string( $v ) ? in_array( strtolower( $v ), array( '1', 'true', 'yes', 'on' ), true ) : (bool) $v; }
function wp_unslash( $v ) { return is_string( $v ) ? stripslashes( $v ) : $v; }
function home_url( $p = '' ) { return 'https://example.test' . $p; }
function wp_parse_url( $url, $component = -1 ) { return parse_url( $url, $component ); }
function url_to_postid( $url ) { return 0; }
function get_the_title( $id ) { return ''; }
function is_post_type_viewable( $t ) { return true; }
function post_type_exists( $t ) { return true; }
function get_post_status( $id ) { return isset( $GLOBALS['uxs_posts'][ $id ] ) ? $GLOBALS['uxs_posts'][ $id ]->post_status : false; }
function get_permalink( $id ) { return ''; }
function get_post( $id ) { $id = is_object( $id ) ? $id->ID : (int) $id; return $GLOBALS['uxs_posts'][ $id ] ?? null; }
function get_post_type( $id ) { return isset( $GLOBALS['uxs_posts'][ (int) $id ] ) ? $GLOBALS['uxs_posts'][ (int) $id ]->post_type : false; }
function get_post_time( $format, $gmt, $post ) { return $post->time; }
function is_user_logged_in() { return $GLOBALS['uxs_user']['id'] > 0; }
function wp_get_current_user() { return (object) array( 'ID' => $GLOBALS['uxs_user']['id'], 'roles' => $GLOBALS['uxs_user']['roles'] ); }
function is_super_admin() { return $GLOBALS['uxs_user']['super']; }
function wp_roles() { return new class { public function get_names() { return array( 'administrator' => 'Administrator', 'editor' => 'Editor', 'subscriber' => 'Subscriber' ); } }; }
function translate_user_role( $n ) { return $n; }
class UxsDie extends RuntimeException {}
function wp_die( $message = '', $title = '', $args = array() ) { throw new UxsDie( (string) ( $args['response'] ?? '' ) ); }
function is_admin() { return false; }
function wp_is_mobile() { return false; }
function did_action( $h ) { return 0; }
function get_posts( $args = array() ) { return array(); }
function get_pages( $args = array() ) { return array(); }
function get_post_types( $args = array(), $output = 'names' ) { return 'objects' === $output ? array( 'post' => (object) array( 'name' => 'post', 'labels' => (object) array( 'name' => 'Posts' ) ), 'page' => (object) array( 'name' => 'page', 'labels' => (object) array( 'name' => 'Pages' ) ) ) : array( 'post' => 'post', 'page' => 'page' ); }

require_once dirname( __DIR__ ) . '/includes/Autoloader.php';
\UxStudio\Autoloader::register();

use UxStudio\Modules\CodeSnippets\SnippetConditions as SC;
use UxStudio\Modules\MenuIcons\IconLibrary;
use UxStudio\Modules\Redirects\Engine;
use UxStudio\Modules\Redirects\Importer;
use UxStudio\Modules\Redirects\Repository;
use UxStudio\Modules\Redirects\Url;
use UxStudio\Modules\SecurityOptimization\Module as SecurityModule;

$failures = 0;
$assert   = static function ( string $name, bool $ok, string $detail = '' ) use ( &$failures ): void {
	if ( ! $ok ) {
		++$failures;
	}
	echo ( $ok ? 'PASS ' : 'FAIL ' ) . $name . ( $ok || '' === $detail ? '' : ' => ' . $detail ) . PHP_EOL;
};
$save  = static fn( array $in, string $c = 'update', bool $d = false ): array => Repository::save( $in, $c, $d );
$codes = static fn( array $r ): array => array_column( $r['errors'], 'code' );
$wcodes = static fn( array $r ): array => array_column( $r['warnings'], 'code' );
$rid   = static fn( ?array $match ): ?int => $match ? (int) $match['rule']['id'] : null;
$visitor = static fn( array $over = array() ): array => $over + \UxStudio\Modules\Redirects\Conditions::blank_context();

/* ═══ 1 Redirects: the hit counter ═══ */

$r = $save( array( 'source' => '/stary-clanek', 'target' => '/novy-clanek' ) );
$hit_id = $r['id'];
$GLOBALS['wpdb']->queries = array();
Repository::queue_hit( $hit_id );
Repository::queue_hit( $hit_id );
Repository::queue_hit( 0 );
$assert( 'hit counter: counting writes nothing during the request (the redirect is never delayed)', 0 === (int) $GLOBALS['uxs_rules'][ $hit_id ]['hits'] && array() === $GLOBALS['wpdb']->queries );
$assert( 'hit counter: the write is hooked once, at the very end of the request (shutdown, last priority)', 1 === count( $GLOBALS['uxs_actions']['shutdown'] ?? array() ) && PHP_INT_MAX === $GLOBALS['uxs_actions']['shutdown'][0][1] );
Repository::flush_hits();
$assert( 'hit counter: two hits of one request are ONE update, last hit time set', 2 === (int) $GLOBALS['uxs_rules'][ $hit_id ]['hits'] && '2026-10-22 12:00:00' === $GLOBALS['uxs_rules'][ $hit_id ]['last_hit'] && 1 === count( $GLOBALS['wpdb']->queries ), json_encode( $GLOBALS['wpdb']->queries ) );
Repository::flush_hits();
$assert( 'hit counter: a second flush has nothing to write', 1 === count( $GLOBALS['wpdb']->queries ) );
Repository::queue_hit( $hit_id );
Repository::flush_hits();
$assert( 'hit counter: later requests add up; the shutdown hook is not registered twice', 3 === (int) $GLOBALS['uxs_rules'][ $hit_id ]['hits'] && 1 === count( $GLOBALS['uxs_actions']['shutdown'] ) );
$r2 = $save( array( 'source' => '/jiny', 'target' => '/jina-stranka' ) );
Repository::record_hit( $r2['id'] );
$assert( 'hit counter: record_hit() counts right away (tools)', 1 === (int) $GLOBALS['uxs_rules'][ $r2['id'] ]['hits'] );
$f = Repository::format( $GLOBALS['uxs_rules'][ $hit_id ] + array( 'target_post_id' => 0 ) );
$assert( 'hit counter: the list data carries hits and last_hit', 3 === $f['hits'] && '2026-10-22 12:00:00' === $f['last_hit'] );
$assert( 'hit counter: reset one rule zeroes counter and time, leaves the others', 1 === Repository::reset_hits( array( $hit_id ) ) && 0 === (int) $GLOBALS['uxs_rules'][ $hit_id ]['hits'] && null === $GLOBALS['uxs_rules'][ $hit_id ]['last_hit'] && 1 === (int) $GLOBALS['uxs_rules'][ $r2['id'] ]['hits'] );
$assert( 'hit counter: reset with no ids resets nothing, null resets every rule that has a counter', 0 === Repository::reset_hits( array() ) && 1 === Repository::reset_hits( null ) && 0 === (int) $GLOBALS['uxs_rules'][ $r2['id'] ]['hits'] );

/* ═══ 2 Redirects: letter case ═══ */

$GLOBALS['uxs_rules'] = array();
$r = $save( array( 'source' => '/About', 'target' => '/o-nas', 'case_sensitive' => true ) );
$cs_id = $r['id'];
$assert( 'case: a case sensitive rule keeps the letters as typed next to the lower-case key', 'created' === $r['status'] && '/about' === $GLOBALS['uxs_rules'][ $cs_id ]['source_key'] && '/About' === $GLOBALS['uxs_rules'][ $cs_id ]['source_cs'] && 1 === (int) $GLOBALS['uxs_rules'][ $cs_id ]['case_sensitive'], json_encode( $r ) );
$r = $save( array( 'source' => '/Contact', 'target' => '/kontakt' ) );
$ci_id = $r['id'];
$assert( 'case: an ordinary rule stores no case information (nothing changes for existing rules)', '' === $GLOBALS['uxs_rules'][ $ci_id ]['source_cs'] && 0 === (int) $GLOBALS['uxs_rules'][ $ci_id ]['case_sensitive'] && '' === $GLOBALS['uxs_rules'][ $ci_id ]['query_mode'] );
$assert( 'case: the case sensitive rule matches the letters it was written with', $cs_id === $rid( Repository::match( '/about', '', null, '/About' ) ) );
$assert( 'case: ... and not another spelling, nor a request whose spelling is unknown', null === Repository::match( '/about', '', null, '/about' ) && null === Repository::match( '/about', '', null, '/ABOUT' ) && null === Repository::match( '/about', '' ) );
$assert( 'case: the ordinary rule still matches any spelling', $ci_id === $rid( Repository::match( '/contact', '', null, '/CONTACT' ) ) && $ci_id === $rid( Repository::match( '/contact', '' ) ) );
$_SERVER['REQUEST_URI'] = '/About/?utm=1';
$req = Url::request();
$assert( 'case: the request keeps its letters in path_cs (slash and query normalised)', '/about' === $req['path'] && '/About' === $req['path_cs'] && 'utm=1' === $req['query'] );
$assert( 'case: the request of a real visitor matches the case sensitive rule (query passes through)', $cs_id === $rid( Repository::match( $req['path'], $req['query'], null, $req['path_cs'] ) ) );
$p = Url::parse_source( 'https://old.example/ABOUT/x/?B=1&a=2' );
$assert( 'case: parse_source returns both forms, the query keys are lower case in either', '/about/x' === $p['path'] && '/ABOUT/x' === $p['path_cs'] && 'a=2&b=1' === $p['query'] );

// A case sensitive rule may canonicalise the letters; the same thing without the flag is a loop.
$r = $save( array( 'source' => '/Foo', 'target' => '/foo', 'case_sensitive' => true ) );
$assert( 'case: /Foo -> /foo is allowed for a case sensitive rule (no loop)', 'created' === $r['status'], json_encode( $r ) );
$assert( 'case: ... and the request for /Foo is answered, /foo itself is left alone', null !== Repository::match( '/foo', '', null, '/Foo' ) && null === Repository::match( '/foo', '', null, '/foo' ) );
$r = $save( array( 'source' => '/Bar', 'target' => '/bar' ) );
$assert( 'case: the same rule without the flag is a loop', 'error' === $r['status'] && in_array( 'loop', $codes( $r ), true ), json_encode( $r ) );
$r = $save( array( 'source' => '/Baz', 'target' => '/Baz', 'case_sensitive' => true ) );
$assert( 'case: a case sensitive rule to itself is still a loop', in_array( 'loop', $codes( $r ), true ), json_encode( $r ) );

// Regular expressions.
$r  = $save( array( 'source' => '^/Blog/(\d+)$', 'match_type' => 'regex', 'target' => '/clanek/$1', 'case_sensitive' => true ) );
$rx = $r['id'];
$r  = $save( array( 'source' => '^/Novinky/(\d+)$', 'match_type' => 'regex', 'target' => '/zpravy/$1' ) );
$ry = $r['id'];
$assert( 'case: a case sensitive regex needs the letters of the pattern', $rx === $rid( Repository::match( '/blog/12', '', null, '/Blog/12' ) ) && null === Repository::match( '/blog/12', '', null, '/blog/12' ) && null === Repository::match( '/blog/12', '' ) );
$assert( 'case: an ordinary regex is case insensitive as before', $ry === $rid( Repository::match( '/novinky/5', '' ) ) && $ry === $rid( Repository::match( '/novinky/5', '', null, '/NOVINKY/5' ) ) );
$assert( 'case: delimit() adds the i flag only for insensitive patterns', '@^/a@iu' === Repository::delimit( '^/a' ) && '@^/a@u' === Repository::delimit( '^/a', true ) );

// Two spellings in two rules on one address and the A2 conditions.
$r  = $save( array( 'source' => '/Mix2', 'target' => '/x-clen', 'case_sensitive' => true, 'conditions' => array( array( 'type' => 'login', 'value' => 'in' ) ) ) );
$m1 = $r['id'];
$r  = $save( array( 'source' => '/mix2', 'target' => '/y-host' ) );
$m2 = $r['id'];
$guest = $visitor();
$user  = $visitor( array( 'logged_in' => true, 'roles' => array( 'subscriber' ) ) );
$assert( 'case x conditions: /Mix2 for a logged-in visitor goes to the case sensitive conditional rule', $m1 === $rid( Repository::match( '/mix2', '', $user, '/Mix2' ) ) );
$assert( 'case x conditions: a guest, or another spelling, falls through to the ordinary fallback', $m2 === $rid( Repository::match( '/mix2', '', $guest, '/Mix2' ) ) && $m2 === $rid( Repository::match( '/mix2', '', $user, '/mix2' ) ) );
$assert( 'case x conditions: a rule dropped because of its letters is not reported as "conditions did not hold"', array() === Repository::last_skipped() || array( $m1 ) === Repository::last_skipped() );

/* ═══ 3 Redirects: query string handling ═══ */

$GLOBALS['uxs_rules'] = array();
$ids = array();
foreach ( array(
	'def'    => array( '/q-def', 'default' ),
	'exact'  => array( '/q-exact', 'exact' ),
	'ignore' => array( '/q-ignore', 'ignore' ),
	'pass'   => array( '/q-pass', 'pass' ),
) as $key => $def ) {
	$r = $save( array( 'source' => $def[0], 'target' => '/t-' . $key, 'query_mode' => $def[1] ) );
	$ids[ $key ] = $r['id'];
}
$assert( 'query: the mode is stored (default as empty, so old rows and new rows agree)', '' === $GLOBALS['uxs_rules'][ $ids['def'] ]['query_mode'] && 'exact' === $GLOBALS['uxs_rules'][ $ids['exact'] ]['query_mode'] && 'pass' === $GLOBALS['uxs_rules'][ $ids['pass'] ]['query_mode'] );
$assert( 'query: default keeps the old behaviour (a source without query matches any query)', $ids['def'] === $rid( Repository::match( '/q-def', '' ) ) && $ids['def'] === $rid( Repository::match( '/q-def', 'utm=1' ) ) );
$assert( 'query: exact matches the bare address and refuses a request with any other parameter (Redirection\'s exact match)', $ids['exact'] === $rid( Repository::match( '/q-exact', '' ) ) && null === Repository::match( '/q-exact', 'utm=1' ) );
$assert( 'query: ignore and pass match the address whatever the query', $ids['ignore'] === $rid( Repository::match( '/q-ignore', 'utm=1&a=2' ) ) && $ids['pass'] === $rid( Repository::match( '/q-pass', 'utm=1' ) ) && $ids['pass'] === $rid( Repository::match( '/q-pass', '' ) ) );
$assert( 'query: what is passed on to the target (rule mode wins over the module setting)', Engine::passes_query( '', true ) && ! Engine::passes_query( '', false ) && ! Engine::passes_query( 'exact', true ) && ! Engine::passes_query( 'ignore', true ) && Engine::passes_query( 'pass', false ) && Engine::passes_query( 'pass', true ) );

// A source that names parameters.
$r = $save( array( 'source' => '/q-with?a=1', 'target' => '/t-with', 'query_mode' => 'exact' ) );
$with = $r['id'];
$assert( 'query: exact with a parameter in the source matches exactly that query (any order of several)', $with === $rid( Repository::match( '/q-with', 'a=1' ) ) && null === Repository::match( '/q-with', 'a=2' ) && null === Repository::match( '/q-with', '' ) && null === Repository::match( '/q-with', 'a=1&b=2' ) );
$r = $save( array( 'source' => '/q-bad?a=1', 'target' => '/t', 'query_mode' => 'ignore' ) );
$assert( 'query: ignore / pass with parameters in the source is refused with a reason', 'error' === $r['status'] && in_array( 'query_mode_source_query', $codes( $r ), true ), json_encode( $r ) );
$r = $save( array( 'source' => '/q-bad2', 'target' => '/t', 'query_mode' => 'sideways' ) );
$assert( 'query: an unknown mode is refused', 'error' === $r['status'] && in_array( 'invalid_query_mode', $codes( $r ), true ), json_encode( $r ) );
$r = $save( array( 'source' => '^/q-rx/(.*)', 'match_type' => 'regex', 'target' => '/t/$1', 'query_mode' => 'pass' ) );
$assert( 'query: a regex ignores the mode and says so', 'created' === $r['status'] && '' === $GLOBALS['uxs_rules'][ $r['id'] ]['query_mode'] && in_array( 'query_mode_regex', $wcodes( $r ), true ), json_encode( $r ) );
$r = $save( array( 'source' => '/q-self', 'target' => '/q-self?utm=1', 'query_mode' => 'exact' ) );
$assert( 'query: an exact rule whose target adds a parameter is not a loop (the target is not matched by the rule)', 'created' === $r['status'], json_encode( $r ) );
$r = $save( array( 'source' => '/q-self2', 'target' => '/q-self2?utm=1' ) );
$assert( 'query: the same with the default mode IS a loop (a source without query matches any query)', in_array( 'loop', $codes( $r ), true ), json_encode( $r ) );

// Partial updates keep the options, and they can be changed.
$r = $save( array( 'id' => $ids['exact'], 'note' => 'poznamka' ) );
$assert( 'options: a partial update by id keeps the mode and the case flag', 'exact' === $GLOBALS['uxs_rules'][ $ids['exact'] ]['query_mode'], json_encode( $r ) );
$r = $save( array( 'id' => $ids['exact'], 'query_mode' => 'ignore' ) );
$assert( 'options: the mode can be changed by id', 'updated' === $r['status'] && 'ignore' === $GLOBALS['uxs_rules'][ $ids['exact'] ]['query_mode'], json_encode( $r ) );
$r = $save( array( 'id' => $ids['exact'], 'query_mode' => 'ignore' ) );
$assert( 'options: saving the same data again is unchanged', 'unchanged' === $r['status'], json_encode( $r ) );
$f = Repository::format( $GLOBALS['uxs_rules'][ $ids['def'] ] + array( 'target_post_id' => 0 ) );
$assert( 'options: the list data shows default / case_sensitive in the API form', 'default' === $f['query_mode'] && false === $f['case_sensitive'] );

// Interplay with conditions on one address.
$r  = $save( array( 'source' => '/mix', 'target' => '/clenove', 'query_mode' => 'exact', 'conditions' => array( array( 'type' => 'login', 'value' => 'in' ) ) ) );
$ma = $r['id'];
$r  = $save( array( 'source' => '/mix', 'target' => '/vsem' ) );
$mb = $r['id'];
$assert( 'query x conditions: a logged-in visitor of the bare address gets the exact conditional rule, a guest the fallback', $ma === $rid( Repository::match( '/mix', '', $user ) ) && $mb === $rid( Repository::match( '/mix', '', $guest ) ) );
$assert( 'query x conditions: with a parameter the exact rule is out, the fallback (default mode) takes the request', $mb === $rid( Repository::match( '/mix', 'utm=1', $user ) ) );
$assert( 'query x conditions: ... and the exact rule is not reported as skipped because of its conditions', array() === Repository::last_skipped() );
$assert( 'query x conditions: a tester call without visitor info finds the fallback and lists the skipped conditional rule', $mb === $rid( Repository::match( '/mix', '', $guest ) ) && array( $ma ) === Repository::last_skipped() );

/* ═══ 4 Redirects: CSV and import ═══ */

$GLOBALS['uxs_rules'] = array();
$csv = "source,target,code,note,case_sensitive,query_mode,hits,last_hit\n" .
	"/Stara,/nova,301,test,1,pass,41,2026-03-17 08:18:11\n" .
	"/druha,/nova2,301,,0,exact,0,\n" .
	"/treti,/nova3,301,,0,ignore,5,not-a-date\n";
$res = Importer::csv( $csv, 'update', false );
$assert( 'csv: three rules created', 3 === ( $res['summary']['created'] ?? 0 ), json_encode( $res ) );
$rows = array_values( $GLOBALS['uxs_rules'] );
$assert( 'csv: case flag, query mode and counters are read', 1 === (int) $rows[0]['case_sensitive'] && '/Stara' === $rows[0]['source_cs'] && 'pass' === $rows[0]['query_mode'] && 41 === (int) $rows[0]['hits'] && '2026-03-17 08:18:11' === $rows[0]['last_hit'] && 'exact' === $rows[1]['query_mode'], json_encode( $rows[0] ) );
$assert( 'csv: a bad last_hit is ignored, the counter is kept', 5 === (int) $rows[2]['hits'] && null === $rows[2]['last_hit'] );
$res = Importer::csv( "source,target,hits,last_hit\n/druha,/nova2,999,2026-10-01 10:00:00\n", 'update', false );
$assert( 'csv: importing a rule that exists never overwrites its live counters', 0 === (int) $GLOBALS['uxs_rules'][ $rows[1]['id'] ]['hits'] && 'exact' === $GLOBALS['uxs_rules'][ $rows[1]['id'] ]['query_mode'], json_encode( $res ) );
$out = Importer::export_csv();
$lines = preg_split( '/\r?\n/', trim( $out ) );
$head  = str_getcsv( $lines[0], ',', '"', '' );
$assert( 'csv: the export has the new columns after the old ones', array( 'source', 'target', 'code', 'note', 'match_type', 'enabled', 'hits', 'conditions', 'position', 'case_sensitive', 'query_mode', 'last_hit' ) === $head, $lines[0] );
$first = str_getcsv( $lines[1], ',', '"', '' );
$assert( 'csv: the export row carries case flag, mode, counter and last hit', '1' === $first[9] && 'pass' === $first[10] && '41' === $first[6] && '2026-03-17 08:18:11' === $first[11], $lines[1] );
// Round trip into an empty store.
$before = array_values( $GLOBALS['uxs_rules'] );
$GLOBALS['uxs_rules'] = array();
$res = Importer::csv( $out, 'update', false );
$after = array_values( $GLOBALS['uxs_rules'] );
$same  = true;
foreach ( $before as $i => $row ) {
	foreach ( array( 'source', 'source_key', 'source_cs', 'case_sensitive', 'query_mode', 'hits', 'last_hit', 'code', 'cond_key' ) as $k ) {
		if ( (string) $row[ $k ] !== (string) ( $after[ $i ][ $k ] ?? '' ) ) { $same = false; echo "  differs: $i $k {$row[$k]} / " . ( $after[ $i ][ $k ] ?? '-' ) . PHP_EOL; }
	}
}
$assert( 'csv: export and import again give the same rules (options and counters survive)', $same && count( $before ) === count( $after ) );

/* ═══ 5 Redirects: abilities and MCP schemas ═══ */

$defs = \UxStudio\Modules\Redirects\Abilities::definitions();
$by   = array_column( $defs, null, 'slug' );
$item = $by['upsert-rules']['schema']['properties']['items']['items']['properties'];
$assert( 'abilities: upsert-rules exposes case_sensitive (boolean) and query_mode (enum)', 'boolean' === $item['case_sensitive']['type'] && \UxStudio\Modules\Redirects\Repository::QUERY_MODES === $item['query_mode']['enum'] );
$ok  = \UxStudio\Core\Mcp\Schema::validate( $by['upsert-rules']['schema'], array( 'items' => array( array( 'source' => '/a', 'target' => '/b', 'case_sensitive' => true, 'query_mode' => 'pass' ) ), 'dry_run' => true ) );
$bad = \UxStudio\Core\Mcp\Schema::validate( $by['upsert-rules']['schema'], array( 'items' => array( array( 'source' => '/a', 'target' => '/b', 'query_mode' => 'sideways' ) ) ) );
$assert( 'abilities: the MCP validator accepts the new fields and rejects an unknown mode', array() === $ok && (bool) $bad, json_encode( array( $ok, $bad ) ) );
$assert( 'abilities: reset-hits exists, takes ids or all, is marked destructive (the MCP asks for confirmation)', isset( $by['reset-hits'] ) && isset( $by['reset-hits']['schema']['properties']['ids'], $by['reset-hits']['schema']['properties']['all'] ) && ! empty( $by['reset-hits']['annotations']['destructive'] ) && empty( $by['reset-hits']['annotations']['readonly'] ) );
$assert( 'abilities: list-rules can order by hits and last hit', in_array( 'hits', $by['list-rules']['schema']['properties']['orderby']['enum'], true ) && in_array( 'last_hit', $by['list-rules']['schema']['properties']['orderby']['enum'], true ) );

/* ═══ 6 Code snippets: run conditions ═══ */

$ctx = static fn( array $o = array() ): array => $o + SC::blank_context();
$ok1 = static function ( array $c ) { $n = SC::normalize( array( $c ) ); return $n['errors'] ? null : $n['conditions'][0]; };
$err = static fn( array $c ): bool => (bool) SC::normalize( array( $c ) )['errors'];
$m   = static function ( array $c, array $context ): bool { $n = SC::normalize( array( $c ) ); if ( $n['errors'] ) { throw new RuntimeException( json_encode( $n['errors'] ) ); } return SC::matches( $n['conditions'], $context ); };

$assert( 'snippet conditions: none (null, empty string, empty array, "[]") mean no restriction', array() === SC::normalize( null )['conditions'] && array() === SC::normalize( '' )['conditions'] && array() === SC::normalize( array() )['conditions'] && array() === SC::normalize( '[]' )['conditions'] && '' === SC::encode( array() ) && SC::matches( array(), SC::blank_context() ) );
$assert( 'snippet conditions: unknown type, empty lists and bad values are refused', $err( array( 'type' => 'weather' ) ) && $err( array( 'type' => 'page', 'value' => array() ) ) && $err( array( 'type' => 'page', 'value' => array( 'moon' ) ) ) && $err( array( 'type' => 'role', 'value' => '' ) ) && $err( array( 'type' => 'post', 'value' => 'abc' ) ) && $err( array( 'type' => 'login', 'value' => 'maybe' ) ) && $err( array( 'type' => 'device', 'value' => 'watch' ) ) && $err( array( 'type' => 'post_type', 'value' => array() ) ) );
$assert( 'snippet conditions: login and device are canonical (negation is folded into the value)', 'out' === $ok1( array( 'type' => 'login', 'value' => 'in', 'negate' => true ) )['value'] && 'desktop' === $ok1( array( 'type' => 'device', 'value' => 'mobile', 'negate' => true ) )['value'] && false === $ok1( array( 'type' => 'login', 'value' => 'in', 'negate' => true ) )['negate'] );
$assert( 'snippet conditions: lists accept an array or a comma string, are cleaned, sorted and de-duplicated; ids are integers', array( 'administrator', 'editor' ) === $ok1( array( 'type' => 'role', 'value' => 'Editor, administrator; editor' ) )['value'] && array( 5, 12 ) === $ok1( array( 'type' => 'post', 'value' => '12, 5, 12, 0, x' ) )['value'] && array( 'front_page', 'search' ) === $ok1( array( 'type' => 'page', 'value' => array( 'search', 'front_page' ) ) )['value'] );
$assert( 'snippet conditions: post type scope defaults to single, a wrong scope falls back to single', 'single' === $ok1( array( 'type' => 'post_type', 'value' => 'product' ) )['scope'] && 'single' === $ok1( array( 'type' => 'post_type', 'value' => 'product', 'scope' => 'nowhere' ) )['scope'] && 'both' === $ok1( array( 'type' => 'post_type', 'value' => 'product', 'scope' => 'both' ) )['scope'] );
$assert( 'snippet conditions: the time window needs a real date, from may not be after to, either end may be missing', $err( array( 'type' => 'schedule' ) ) && $err( array( 'type' => 'schedule', 'from' => '2026-02-30 10:00' ) ) && $err( array( 'type' => 'schedule', 'from' => '2026-10-02', 'to' => '2026-10-01' ) ) && $err( array( 'type' => 'schedule', 'from' => '2026-10-01 25:00' ) ) && '2026-10-01 08:00:00' === $ok1( array( 'type' => 'schedule', 'from' => '2026-10-01T08:00' ) )['from'] && '' === $ok1( array( 'type' => 'schedule', 'from' => '2026-10-01' ) )['to'] );
$seven = array_fill( 0, 7, array( 'type' => 'login', 'value' => 'in' ) );
$assert( 'snippet conditions: at most six; a JSON string and a single object are accepted', (bool) SC::normalize( $seven )['errors'] && 1 === count( SC::normalize( '[{"type":"login","value":"out"}]' )['conditions'] ) && 1 === count( SC::normalize( array( 'type' => 'login', 'value' => 'in' ) )['conditions'] ) );
$n1 = SC::normalize( array( array( 'type' => 'page', 'value' => array( 'singular' ) ), array( 'type' => 'role', 'value' => array( 'editor' ), 'negate' => true ) ) )['conditions'];
$assert( 'snippet conditions: encode / decode round trip; a corrupt stored value means no conditions (never a guess)', $n1 === SC::decode( SC::encode( $n1 ) ) && array() === SC::decode( '{not json' ) && array() === SC::decode( null ) && array() === SC::decode( '' ) );
$assert( 'snippet conditions: only page, post type and post conditions need the parsed request', SC::needs_query( $n1 ) && ! SC::needs_query( SC::normalize( array( array( 'type' => 'login', 'value' => 'in' ), array( 'type' => 'device', 'value' => 'mobile' ), array( 'type' => 'schedule', 'from' => '2026-10-01' ) ) )['conditions'] ) && ! SC::needs_query( array() ) );

// Matching matrix.
$front = $ctx( array( 'kind' => 'front_page' ) );
$post  = $ctx( array( 'kind' => 'singular', 'single_type' => 'product', 'post_id' => 42 ) );
$arch  = $ctx( array( 'kind' => 'archive', 'archive_type' => 'product' ) );
$cases = array(
	'front page' => array( array( 'type' => 'page', 'value' => array( 'front_page' ) ), array( 'front_page' => true, 'singular' => false, 'archive' => false ) ),
	'singular'   => array( array( 'type' => 'page', 'value' => array( 'singular' ) ), array( 'front_page' => false, 'singular' => true, 'archive' => false ) ),
	'archive'    => array( array( 'type' => 'page', 'value' => array( 'archive', 'author' ) ), array( 'front_page' => false, 'singular' => false, 'archive' => true ) ),
);
foreach ( $cases as $name => $case ) {
	$got = array( 'front_page' => $m( $case[0], $front ), 'singular' => $m( $case[0], $post ), 'archive' => $m( $case[0], $arch ) );
	$assert( "snippet conditions: page kind ($name)", $case[1] === $got, json_encode( $got ) );
}
$assert( 'snippet conditions: the kinds are exclusive like WPCode\'s "type of page": a static front page is the front page, not a single post', ! $m( array( 'type' => 'page', 'value' => array( 'singular' ) ), $front ) );
$assert( 'snippet conditions: search, 404, author, posts page and wp-admin kinds', $m( array( 'type' => 'page', 'value' => array( 'search' ) ), $ctx( array( 'kind' => 'search' ) ) ) && $m( array( 'type' => 'page', 'value' => array( '404' ) ), $ctx( array( 'kind' => '404' ) ) ) && $m( array( 'type' => 'page', 'value' => array( 'author' ) ), $ctx( array( 'kind' => 'author' ) ) ) && $m( array( 'type' => 'page', 'value' => array( 'blog_home' ) ), $ctx( array( 'kind' => 'blog_home' ) ) ) && $m( array( 'type' => 'page', 'value' => array( 'admin' ) ), $ctx( array( 'kind' => 'admin' ) ) ) && ! $m( array( 'type' => 'page', 'value' => array( 'search' ) ), $ctx( array( 'kind' => '' ) ) ) );
$assert( 'snippet conditions: negate ("not on the front page")', $m( array( 'type' => 'page', 'value' => array( 'front_page' ), 'negate' => true ), $post ) && ! $m( array( 'type' => 'page', 'value' => array( 'front_page' ), 'negate' => true ), $front ) );
$assert( 'snippet conditions: before the request is parsed (kind null) no page condition matches, not even a negated one', ! $m( array( 'type' => 'page', 'value' => array( 'front_page' ) ), $ctx() ) && ! $m( array( 'type' => 'page', 'value' => array( 'front_page' ), 'negate' => true ), $ctx() ) && ! $m( array( 'type' => 'post', 'value' => array( 1 ), 'negate' => true ), $ctx() ) );
$assert( 'snippet conditions: post type, scope single / archive / both', $m( array( 'type' => 'post_type', 'value' => array( 'product' ) ), $post ) && ! $m( array( 'type' => 'post_type', 'value' => array( 'product' ) ), $arch ) && $m( array( 'type' => 'post_type', 'value' => array( 'product' ), 'scope' => 'archive' ), $arch ) && ! $m( array( 'type' => 'post_type', 'value' => array( 'product' ), 'scope' => 'archive' ), $post ) && $m( array( 'type' => 'post_type', 'value' => array( 'product' ), 'scope' => 'both' ), $arch ) && $m( array( 'type' => 'post_type', 'value' => array( 'product' ), 'scope' => 'both' ), $post ) && ! $m( array( 'type' => 'post_type', 'value' => array( 'page' ), 'scope' => 'both' ), $post ) );
$assert( 'snippet conditions: specific posts by id (also negated)', $m( array( 'type' => 'post', 'value' => array( 7, 42 ) ), $post ) && ! $m( array( 'type' => 'post', 'value' => array( 7 ) ), $post ) && $m( array( 'type' => 'post', 'value' => array( 7 ), 'negate' => true ), $post ) && ! $m( array( 'type' => 'post', 'value' => array( 42 ) ), $front ) );
$admin = $ctx( array( 'logged_in' => true, 'roles' => array( 'administrator' ) ) );
$guest = $ctx();
$assert( 'snippet conditions: logged in / out', $m( array( 'type' => 'login', 'value' => 'in' ), $admin ) && ! $m( array( 'type' => 'login', 'value' => 'in' ), $guest ) && $m( array( 'type' => 'login', 'value' => 'out' ), $guest ) && ! $m( array( 'type' => 'login', 'value' => 'out' ), $admin ) );
$assert( 'snippet conditions: role (any of the roles), a guest has none, a claimed role of a guest does not count, negate', $m( array( 'type' => 'role', 'value' => array( 'editor', 'administrator' ) ), $admin ) && ! $m( array( 'type' => 'role', 'value' => array( 'editor' ) ), $admin ) && ! $m( array( 'type' => 'role', 'value' => array( 'administrator' ) ), $guest ) && ! $m( array( 'type' => 'role', 'value' => array( 'administrator' ) ), $ctx( array( 'roles' => array( 'administrator' ) ) ) ) && $m( array( 'type' => 'role', 'value' => array( 'editor' ), 'negate' => true ), $admin ) && $m( array( 'type' => 'role', 'value' => array( 'editor' ), 'negate' => true ), $guest ) );
$assert( 'snippet conditions: device', $m( array( 'type' => 'device', 'value' => 'mobile' ), $ctx( array( 'mobile' => true ) ) ) && ! $m( array( 'type' => 'device', 'value' => 'mobile' ), $ctx() ) && $m( array( 'type' => 'device', 'value' => 'desktop' ), $ctx() ) && ! $m( array( 'type' => 'device', 'value' => 'desktop' ), $ctx( array( 'mobile' => true ) ) ) );
$win = array( 'type' => 'schedule', 'from' => '2026-10-01 00:00', 'to' => '2026-10-31 23:59' );
$assert( 'snippet conditions: time window (both ends inclusive), open-ended windows, outside the window with negate', $m( $win, $ctx( array( 'now' => '2026-10-01 00:00:00' ) ) ) && $m( $win, $ctx( array( 'now' => '2026-10-15 12:00:00' ) ) ) && ! $m( $win, $ctx( array( 'now' => '2026-09-30 23:59:59' ) ) ) && ! $m( $win, $ctx( array( 'now' => '2026-11-01 00:00:00' ) ) ) && $m( array( 'type' => 'schedule', 'from' => '2026-10-01' ), $ctx( array( 'now' => '2030-01-01 00:00:00' ) ) ) && $m( array( 'type' => 'schedule', 'to' => '2026-10-31' ), $ctx( array( 'now' => '2020-01-01 00:00:00' ) ) ) && $m( $win + array( 'negate' => true ), $ctx( array( 'now' => '2026-12-01 00:00:00' ) ) ) );
$all = SC::normalize( array( array( 'type' => 'page', 'value' => array( 'front_page' ) ), array( 'type' => 'login', 'value' => 'in' ), array( 'type' => 'device', 'value' => 'desktop' ) ) )['conditions'];
$assert( 'snippet conditions: all conditions must hold (AND)', SC::matches( $all, $ctx( array( 'kind' => 'front_page', 'logged_in' => true ) ) ) && ! SC::matches( $all, $ctx( array( 'kind' => 'front_page' ) ) ) && ! SC::matches( $all, $ctx( array( 'kind' => 'singular', 'logged_in' => true ) ) ) && ! SC::matches( $all, $ctx( array( 'kind' => 'front_page', 'logged_in' => true, 'mobile' => true ) ) ) );
$assert( 'snippet conditions: a condition of an unknown type (a tampered row) never lets a snippet run', ! SC::matches( array( array( 'type' => 'eval', 'value' => 'x' ) ), $admin ) );
$assert( 'snippet conditions: the summary reads well', 'single post or page, not editor' === SC::summary( $n1 ), SC::summary( $n1 ) );

/* ═══ 7 Menu icons: Dashicons ═══ */

$dash = IconLibrary::dashicons();
$assert( 'dashicons: the whole core set is available (hundreds of icons), each as a plain safe class name', count( $dash ) >= 300 && count( $dash ) === count( array_unique( $dash ) ) && array() === array_filter( $dash, static fn( $c ) => 1 !== preg_match( '/^dashicons-[a-z0-9]+(-[a-z0-9]+)*$/', $c ) ), (string) count( $dash ) );
$assert( 'dashicons: known icons exist', IconLibrary::dashicon_exists( 'dashicons-admin-home' ) && IconLibrary::dashicon_exists( 'dashicons-phone' ) && IconLibrary::dashicon_exists( 'dashicons-email-alt' ) );
$assert( 'dashicons: anything else is refused (prefix, case, unknown name, attribute injection)', ! IconLibrary::dashicon_exists( 'admin-home' ) && ! IconLibrary::dashicon_exists( 'dashicons-' ) && ! IconLibrary::dashicon_exists( 'dashicons-nope' ) && ! IconLibrary::dashicon_exists( 'Dashicons-admin-home' ) && ! IconLibrary::dashicon_exists( 'dashicons-admin-home" onclick="x' ) && ! IconLibrary::dashicon_exists( 'fa fa-home' ) && ! IconLibrary::dashicon_exists( '' ) );
$assert( 'dashicons: the picker list and the whitelist agree', array_keys( IconLibrary::dashicon_choices() ) === $dash );
$legacy = array_filter( array_keys( \UxStudio\Core\Migration\Sources\MenuIconsSource::DASHICONS ), static fn( $k ) => ! IconLibrary::dashicon_exists( 'dashicons-' . $k ) );
$assert( 'dashicons: every Dashicon the old Menu Icons mapping names really exists in the set', array() === $legacy, implode( ',', $legacy ) );
$assert( 'dashicons: Lucide stays as it was', IconLibrary::exists( 'house' ) && ! IconLibrary::exists( 'dashicons-admin-home' ) );

/* ═══ 8 Security: comment exceptions ═══ */

$set = static function ( array $values ): SecurityModule {
	$GLOBALS['uxs_options']['uxstudio_security_optimization'] = $values;
	return new SecurityModule( 'security-optimization', array() );
};
$call = static function ( SecurityModule $mod, string $method, ...$args ) {
	$ref = new ReflectionMethod( $mod, $method );
	$ref->setAccessible( true );
	return $ref->invoke( $mod, ...$args );
};
$as = static function ( int $id, array $roles = array(), bool $super = false ): void { $GLOBALS['uxs_user'] = array( 'id' => $id, 'roles' => $roles, 'super' => $super ); };
$GLOBALS['uxs_posts'] = array(
	1 => (object) array( 'ID' => 1, 'post_type' => 'post', 'post_status' => 'publish', 'time' => time() - 10 * DAY_IN_SECONDS ),
	2 => (object) array( 'ID' => 2, 'post_type' => 'post', 'post_status' => 'publish', 'time' => time() - 40 * DAY_IN_SECONDS ),
	3 => (object) array( 'ID' => 3, 'post_type' => 'page', 'post_status' => 'draft', 'time' => time() - 400 * DAY_IN_SECONDS ),
	4 => (object) array( 'ID' => 4, 'post_type' => 'page', 'post_status' => 'publish', 'time' => time() - 400 * DAY_IN_SECONDS ),
);
$GLOBALS['uxs_writes'] = array();

// Comments disabled everywhere; editors and logged-out visitors may still comment; pingbacks stay allowed.
$mod = $set( array( 'disable_comments' => true, 'disable_comments_scope' => 'all', 'disable_comments_allowed_roles' => array( 'editor' ), 'disable_comments_allowed_types' => array( 'pingback' ) ) );
$as( 0 );
$assert( 'comments: disabled everywhere, a guest cannot comment', false === $mod->filter_comments_open( true, 1 ) );
$as( 5, array( 'subscriber' ) );
$assert( 'comments: ... a subscriber cannot either', false === $mod->filter_comments_open( true, 1 ) );
$as( 6, array( 'editor' ) );
$assert( 'comments: an editor may (the post\'s own setting is kept: closed stays closed)', true === $mod->filter_comments_open( true, 1 ) && false === $mod->filter_comments_open( false, 1 ) );
$assert( 'comments: an editor sees the comments, the counter, and keeps the admin tools; the module hides them from others', array( 'a' ) === $mod->filter_comments_array( array( 'a' ), 1 ) && 7 === $mod->filter_comments_number( 7, 1 ) && 'keep' === $mod->zero_comment_count( 'keep', 0 ) );
$as( 5, array( 'subscriber' ) );
$assert( 'comments: others get no comments, zero counter', array() === $mod->filter_comments_array( array( 'a' ), 1 ) && 0 === $mod->filter_comments_number( 7, 1 ) && 0 === $mod->zero_comment_count( 'keep', 0 )->all );
$as( 6, array( 'editor' ) );
$endpoints = array( '/wp/v2/comments' => 1, '/wp/v2/posts' => 1 );
$assert( 'comments: the REST comment routes stay for an editor and go for the others', $endpoints === $mod->filter_comment_rest_endpoints( $endpoints ) && array( '/wp/v2/posts' => 1 ) === ( function () use ( $mod, $as, $endpoints ) { $as( 5, array( 'subscriber' ) ); return $mod->filter_comment_rest_endpoints( $endpoints ); } )() );
$as( 6, array( 'editor' ) );
$assert( 'comments: an editor may submit a comment, a subscriber is refused with 403', null === $mod->block_comment_submission( 1 ) );
$as( 5, array( 'subscriber' ) );
try { $mod->block_comment_submission( 1 ); $refused = false; } catch ( UxsDie $e ) { $refused = '403' === $e->getMessage(); }
$assert( 'comments: ... a subscriber is refused with 403', $refused );
$as( 0 );
$mod_lo = $set( array( 'disable_comments' => true, 'disable_comments_scope' => 'all', 'disable_comments_allowed_roles' => array( 'logged-out-users' ) ) );
$assert( 'comments: "visitors who are not logged in" can be the exception', true === $mod_lo->filter_comments_open( true, 1 ) );
$as( 5, array( 'subscriber' ) );
$assert( 'comments: ... which does not free logged-in subscribers', false === $mod_lo->filter_comments_open( true, 1 ) );
$as( 9, array( 'administrator' ), true );
$mod_adm = $set( array( 'disable_comments' => true, 'disable_comments_scope' => 'all', 'disable_comments_allowed_roles' => array( 'administrator' ) ) );
$assert( 'comments: a super admin counts as administrator', true === $mod_adm->filter_comments_open( true, 1 ) );
$as( 0 );
$mod_none = $set( array( 'disable_comments' => true, 'disable_comments_scope' => 'all' ) );
$assert( 'comments: no exceptions chosen = nothing changes (default)', false === $mod_none->filter_comments_open( true, 1 ) && false === $call( $mod_none, 'comments_exempt' ) );

// Ping types.
$as( 0 );
$assert( 'ping types: one allowed ping type keeps pings open while comments are off', true === $mod->filter_pings_open( true, 1 ) );
$assert( 'ping types: none allowed = pings closed as before', false === $mod_none->filter_pings_open( true, 1 ) );
$assert( 'ping types: with only pingbacks allowed, trackbacks are refused when they arrive, pingbacks and comments are not', in_array( 'trackback', $call( $mod, 'effective_blocked_types' ), true ) && ! in_array( 'pingback', $call( $mod, 'effective_blocked_types' ), true ) );
try { $mod->refuse_blocked_types( array( 'comment_type' => 'trackback' ) ); $r1 = false; } catch ( UxsDie $e ) { $r1 = '403' === $e->getMessage(); }
$assert( 'ping types: a trackback is refused (403)', $r1 );
$assert( 'ping types: a pingback and an ordinary comment pass through untouched', array( 'comment_type' => 'pingback' ) === $mod->refuse_blocked_types( array( 'comment_type' => 'pingback' ) ) && array( 'comment_type' => '' ) === $mod->refuse_blocked_types( array( 'comment_type' => '' ) ) );
$assert( 'ping types: disabled comments without allowed types refuse both ping types', array( 'pingback', 'trackback' ) === $call( $mod_none, 'effective_blocked_types' ) );

// Refused types without disabling comments, and closing old posts.
$mod_r = $set( array( 'comments_blocked_types' => array( 'review', 'pingback' ), 'comments_close_after_days' => 30 ) );
$as( 0 );
try { $mod_r->refuse_blocked_types( array( 'comment_type' => 'review' ) ); $r2 = false; } catch ( UxsDie $e ) { $r2 = true; }
$assert( 'refused types: a product review is refused where comments are open, a normal comment is not', $r2 && array( 'comment_type' => '' ) === $mod_r->refuse_blocked_types( array( 'comment_type' => '' ) ) );
$assert( 'refused types: no disable_comments setting needed; ping types alone do not close the whole door unless both are chosen', array( 'review', 'pingback' ) === $call( $mod_r, 'effective_blocked_types' ) );
$assert( 'old posts: a post younger than the limit stays open, an older one closes (comments and pings use the same filter)', true === $mod_r->filter_old_posts_open( true, 1 ) && false === $mod_r->filter_old_posts_open( true, 2 ) );
$assert( 'old posts: a post that is already closed stays closed; a draft is not judged', false === $mod_r->filter_old_posts_open( false, 1 ) && true === $mod_r->filter_old_posts_open( true, 3 ) && false === $mod_r->filter_old_posts_open( true, 4 ) && true === $mod_r->filter_old_posts_open( true, 999 ) );
$as( 6, array( 'editor' ) );
$mod_r2 = $set( array( 'comments_close_after_days' => 30, 'disable_comments_allowed_roles' => array( 'editor' ) ) );
$assert( 'old posts: a role that may still comment is not affected by the closing either', true === $mod_r2->filter_old_posts_open( true, 2 ) );
$as( 0 );
$assert( 'old posts: the same setting closes it for a guest', false === $mod_r2->filter_old_posts_open( true, 2 ) );
$mod_z = $set( array( 'comments_close_after_days' => 0 ) );
$assert( 'old posts: 0 days (the default) closes nothing', true === $mod_z->filter_old_posts_open( true, 4 ) );
$mod_neg = $set( array( 'comments_close_after_days' => -5 ) );
$mod_big = $set( array( 'comments_close_after_days' => 999999 ) );
$assert( 'old posts: a negative number means off, a huge one is capped at ten years', true === $mod_neg->filter_old_posts_open( true, 4 ) && true === $mod_big->filter_old_posts_open( true, 4 ) );

// Hook registration: the rules only hook what is switched on.
$GLOBALS['uxs_actions'] = array();
$call( $set( array() ), 'init_comment_rules' );
$assert( 'hooks: with nothing chosen the comment rules register no hook at all', array() === $GLOBALS['uxs_actions'] );
$GLOBALS['uxs_actions'] = array();
$call( $set( array( 'comments_close_after_days' => 30, 'comments_blocked_types' => array( 'pingback', 'trackback' ) ) ), 'init_comment_rules' );
$assert( 'hooks: closing old posts hooks comments_open + pings_open, refusing types hooks preprocess_comment, both ping types also close pings_open early', isset( $GLOBALS['uxs_actions']['comments_open'], $GLOBALS['uxs_actions']['pings_open'], $GLOBALS['uxs_actions']['preprocess_comment'] ) && 2 === count( $GLOBALS['uxs_actions']['pings_open'] ) && '__return_false' === $GLOBALS['uxs_actions']['pings_open'][1][0], json_encode( array_map( 'count', $GLOBALS['uxs_actions'] ) ) );
$assert( 'core options: nothing the comment rules do writes a WordPress option (close_comments_for_old_posts / close_comments_days_old are never touched)', array() === $GLOBALS['uxs_writes'], json_encode( $GLOBALS['uxs_writes'] ) );

// The settings schema: new fields, roles from the site.
$schema = array();
foreach ( $set( array() )->settings_schema() as $field ) { $schema[ $field['key'] ] = $field; }
$assert( 'schema: roles, allowed types, refused types and closing days are settings of the Security module', 'multiselect' === $schema['disable_comments_allowed_roles']['type'] && 'multiselect' === $schema['disable_comments_allowed_types']['type'] && 'multiselect' === $schema['comments_blocked_types']['type'] && 'number' === $schema['comments_close_after_days']['type'] && 0 === $schema['comments_close_after_days']['default'] && array() === $schema['disable_comments_allowed_roles']['default'] );
$assert( 'schema: the role list is the site\'s roles plus visitors who are not logged in; the refusable types are pingback, trackback, review', array( 'administrator', 'editor', 'subscriber', 'logged-out-users' ) === array_keys( $schema['disable_comments_allowed_roles']['options'] ) && array( 'pingback', 'trackback' ) === array_keys( $schema['disable_comments_allowed_types']['options'] ) && array( 'pingback', 'trackback', 'review' ) === array_keys( $schema['comments_blocked_types']['options'] ) );
$clean = \UxStudio\Core\Settings::clean( array( 'disable_comments_allowed_roles' => array( 'editor', 'ghost', 'logged-out-users' ), 'comments_blocked_types' => array( 'review', 'evil' ) ), array_values( $schema ) );
$assert( 'schema: the sanitizer keeps only known roles and types', array( 'editor', 'logged-out-users' ) === $clean['disable_comments_allowed_roles'] && array( 'review' ) === $clean['comments_blocked_types'], json_encode( $clean ) );

echo PHP_EOL . ( $failures ? "$failures FAILED" : 'ALL PASSED' ) . PHP_EOL;
exit( $failures ? 1 : 0 );
