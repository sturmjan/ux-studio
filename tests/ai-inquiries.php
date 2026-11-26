<?php
// Test only: never runs over HTTP (the release zip also excludes tests/).
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { exit; }
/**
 * AI Assistant inquiries inbox (REST): list with pagination and type/status filters, conversation link, mark
 * handled/new (single + bulk), delete (single + bulk), badge count, permissions.
 *
 *   php tests/ai-inquiries.php
 *
 * Needs no WordPress: an in-memory $wpdb double for the few statements the controller issues, and WP stubs that
 * capture register_rest_route() so the permission callbacks are tested too. Exits non-zero on failure.
 *
 * @package UxStudio
 */

// phpcs:disable

define( 'ABSPATH', __DIR__ . '/' );
$root = dirname( __DIR__ );
define( 'UXSTUDIO_PATH', $root . '/' );
define( 'ARRAY_A', 'ARRAY_A' );

eval( 'namespace UxStudio\Core; final class Security { public static bool $allow_write = true; public static function check_write_rate_limit(): bool { return self::$allow_write; } }' );
class WP_Error { public function __construct( public string $code = '', public string $message = '', public array $data = array() ) {} }
class WP_REST_Response { public function __construct( public $data = null ) {} }
class WP_REST_Request {
	public function __construct( private array $params = array() ) {}
	public function get_param( string $k ) { return $this->params[ $k ] ?? null; }
}
function __( $t, $d = null ) { return $t; }
function absint( $v ) { return abs( (int) $v ); }
$GLOBALS['caps']   = array( 'manage_options' => true );
$GLOBALS['routes'] = array();
function current_user_can( $cap ) { return ! empty( $GLOBALS['caps'][ $cap ] ); }
function register_rest_route( $ns, $path, $args ) { $GLOBALS['routes'][] = array( $path, $args ); }

final class InqDb {
	public string $prefix = 'wp_';
	/** @var array<int, array<string, mixed>> */
	public array $inq = array();
	/** @var array<int, array<string, mixed>> */
	public array $conv = array();

	public function prepare( string $q, ...$args ) {
		if ( 1 === count( $args ) && is_array( $args[0] ) ) { $args = $args[0]; }
		$i = 0;
		return (string) preg_replace_callback( '/%[sd]/', function ( $m ) use ( &$i, $args ) {
			$v = $args[ $i++ ];
			return '%d' === $m[0] ? (string) (int) $v : "'" . str_replace( "'", "''", (string) $v ) . "'";
		}, $q );
	}
	/** Rows matching the i.type / i.status / status conditions found in the SQL. */
	private function filtered( string $sql ): array {
		$rows = $this->inq;
		if ( preg_match( "/i\.type = '([^']*)'/", $sql, $m ) ) { $rows = array_filter( $rows, fn( $r ) => $r['type'] === $m[1] ); }
		if ( preg_match( "/(?:i\.)?status = '([^']*)'/", $sql, $m ) && ! str_contains( $sql, 'SET status' ) ) { $rows = array_filter( $rows, fn( $r ) => $r['status'] === $m[1] ); }
		return array_values( $rows );
	}
	public function get_var( $sql ) {
		if ( preg_match( '/SELECT COUNT\(\*\) FROM \S+inquiries WHERE id = (\d+)/', $sql, $m ) ) { return isset( $this->inq[ (int) $m[1] ] ) ? 1 : 0; }
		if ( str_starts_with( $sql, 'SELECT COUNT(*)' ) ) { return count( $this->filtered( $sql ) ); }
		throw new RuntimeException( 'unsupported get_var: ' . $sql );
	}
	public function get_results( $sql, $output = null ) {
		if ( ! preg_match( '/LIMIT (\d+) OFFSET (\d+)/', $sql, $m ) ) { throw new RuntimeException( 'unsupported get_results: ' . $sql ); }
		$rows = $this->filtered( $sql );
		usort( $rows, fn( $a, $b ) => array( $b['created_at'], $b['id'] ) <=> array( $a['created_at'], $a['id'] ) );
		$rows = array_slice( $rows, (int) $m[2], (int) $m[1] );
		foreach ( $rows as &$r ) {
			$ids = array_keys( array_filter( $this->conv, fn( $c ) => '' !== $r['session_id'] && $c['session_id'] === $r['session_id'] ) );
			$r['conversation_id'] = $ids ? min( $ids ) : null;
		}
		return $rows;
	}
	public function update( $table, $data, $where ) {
		if ( ! isset( $this->inq[ $where['id'] ] ) ) { return 0; }
		$this->inq[ $where['id'] ] = array_merge( $this->inq[ $where['id'] ], $data );
		return 1;
	}
	public function delete( $table, $where ) {
		$n = isset( $this->inq[ $where['id'] ] ) ? 1 : 0;
		unset( $this->inq[ $where['id'] ] );
		return $n;
	}
	public function query( $sql ) {
		if ( preg_match( "/^UPDATE \S+ SET status = '([^']*)' WHERE id IN \(([\d,]+)\)$/", $sql, $m ) ) {
			$n = 0;
			foreach ( explode( ',', $m[2] ) as $id ) { if ( isset( $this->inq[ (int) $id ] ) ) { $this->inq[ (int) $id ]['status'] = $m[1]; ++$n; } }
			return $n;
		}
		if ( preg_match( '/^DELETE FROM \S+ WHERE id IN \(([\d,]+)\)$/', $sql, $m ) ) {
			$n = 0;
			foreach ( explode( ',', $m[1] ) as $id ) { if ( isset( $this->inq[ (int) $id ] ) ) { unset( $this->inq[ (int) $id ] ); ++$n; } }
			return $n;
		}
		throw new RuntimeException( 'unsupported query: ' . $sql );
	}
}

$GLOBALS['wpdb'] = new InqDb();

require $root . '/includes/Autoloader.php';
\UxStudio\Autoloader::register();

use UxStudio\Modules\AiAssistant\InquiryRestController;

$failed = 0;
function check( string $name, bool $ok, string $detail = '' ): void {
	global $failed;
	echo ( $ok ? 'ok   ' : 'FAIL ' ) . $name . ( $ok || '' === $detail ? '' : ' - ' . $detail ) . PHP_EOL;
	if ( ! $ok ) { ++$failed; }
}
function db(): InqDb { return $GLOBALS['wpdb']; }
function req( array $p = array() ): WP_REST_Request { return new WP_REST_Request( $p ); }
function data( $r ) { return $r instanceof WP_REST_Response ? $r->data['data'] : null; }

function seed(): void {
	$db = db();
	$db->inq  = array();
	$db->conv = array( 10 => array( 'session_id' => 'sess-a' ), 11 => array( 'session_id' => 'sess-a' ), 12 => array( 'session_id' => 'sess-b' ) );
	$rows = array(
		array( 1, 'email', 'sess-a', 'Jan', 'jan@x.cz', '', 'Question about stay', '/pobyt', 'new', '2026-11-20 10:00:00' ),
		array( 2, 'callback', 'sess-b', '', '', '+420777111222', 'Call me', '/', 'new', '2026-11-21 10:00:00' ),
		array( 3, 'email', 'sess-gone', 'Eva', 'eva@x.cz', '', 'Hi', '/', 'handled', '2026-11-22 10:00:00' ),
		array( 4, 'callback', '', '', '', '+420777333444', '', '/', 'handled', '2026-11-23 10:00:00' ),
		array( 5, 'email', 'sess-a', 'Petr', 'petr@x.cz', '', 'Another', '/', 'new', '2026-11-24 10:00:00' ),
	);
	foreach ( $rows as $r ) {
		$db->inq[ $r[0] ] = array( 'id' => $r[0], 'type' => $r[1], 'session_id' => $r[2], 'name' => $r[3], 'email' => $r[4], 'phone' => $r[5], 'message' => $r[6], 'page_url' => $r[7], 'status' => $r[8], 'created_at' => $r[9] );
	}
}

$c = new InquiryRestController();

// ── Routes and permissions ──────────────────────────────────────────────────────────────────────────
$c->register_routes();
$paths = array_map( fn( $r ) => $r[1]['methods'] . ' ' . $r[0], $GLOBALS['routes'] );
check( 'routes: list, status, bulk-status, delete, bulk-delete are registered', 5 === count( $paths ) && in_array( 'GET /ai-assistant/inquiries', $paths, true ) && in_array( 'POST /ai-assistant/inquiries/bulk-delete', $paths, true ) && in_array( 'DELETE /ai-assistant/inquiries/(?P<id>\d+)', $paths, true ), json_encode( $paths ) );
$all_admin = true;
foreach ( $GLOBALS['routes'] as $r ) {
	$GLOBALS['caps'] = array( 'manage_options' => true );
	$yes = ( $r[1]['permission_callback'] )();
	$GLOBALS['caps'] = array( 'edit_posts' => true );
	$no = ( $r[1]['permission_callback'] )();
	$all_admin = $all_admin && true === $yes && false === $no;
}
$GLOBALS['caps'] = array( 'manage_options' => true );
check( 'permissions: every route needs manage_options (denied for an editor)', $all_admin );

// A write route is rate limited by the base controller (and not executed).
$write = null;
foreach ( $GLOBALS['routes'] as $r ) { if ( 'DELETE' === $r[1]['methods'] ) { $write = $r[1]['callback']; } }
\UxStudio\Core\Security::$allow_write = false;
seed();
$limited = $write( req( array( 'id' => 1 ) ) );
\UxStudio\Core\Security::$allow_write = true;
check( 'rate limit: a write over the budget is refused with 429 and changes nothing', $limited instanceof WP_Error && 429 === $limited->data['status'] && isset( db()->inq[1] ) );

// ── List ────────────────────────────────────────────────────────────────────────────────────────────
seed();
$d = data( $c->list_inquiries( req() ) );
check( 'list: newest first, with totals and the new count', array( 5, 4, 3, 2, 1 ) === array_column( $d['items'], 'id' ) && 5 === $d['total'] && 1 === $d['pages'] && 3 === $d['new_count'], json_encode( array_column( $d['items'], 'id' ) ) );
check( 'list: row shape (type contact, no session id leaked)', 'contact' === $d['items'][0]['type'] && 'callback' === $d['items'][1]['type'] && ! array_key_exists( 'session_id', $d['items'][0] ) && 'Petr' === $d['items'][0]['name'] );
check( 'list: conversation link = lowest conversation id of the session, null when none', 10 === $d['items'][0]['conversation_id'] && 12 === $d['items'][3]['conversation_id'] && null === $d['items'][2]['conversation_id'] && null === $d['items'][1]['conversation_id'] );

$d = data( $c->list_inquiries( req( array( 'type' => 'contact' ) ) ) );
check( 'filter: type=contact', array( 5, 3, 1 ) === array_column( $d['items'], 'id' ) && 3 === $d['total'] );
$d = data( $c->list_inquiries( req( array( 'type' => 'callback' ) ) ) );
check( 'filter: type=callback', array( 4, 2 ) === array_column( $d['items'], 'id' ) );
$d = data( $c->list_inquiries( req( array( 'status' => 'new' ) ) ) );
check( 'filter: status=new', array( 5, 2, 1 ) === array_column( $d['items'], 'id' ) );
$d = data( $c->list_inquiries( req( array( 'type' => 'contact', 'status' => 'handled' ) ) ) );
check( 'filter: type and status combine', array( 3 ) === array_column( $d['items'], 'id' ) && 1 === $d['total'] );
$d = data( $c->list_inquiries( req( array( 'status' => 'new', 'type' => 'callback' ) ) ) );
check( 'filter: new callbacks only', array( 2 ) === array_column( $d['items'], 'id' ) );

$d = data( $c->list_inquiries( req( array( 'per_page' => 2, 'page' => 2 ) ) ) );
check( 'pagination: page 2 of 3 with per_page 2', array( 3, 2 ) === array_column( $d['items'], 'id' ) && 3 === $d['pages'] && 5 === $d['total'] && 2 === $d['page'] );
$d = data( $c->list_inquiries( req( array( 'per_page' => 2, 'page' => 9 ) ) ) );
check( 'pagination: a page past the end is empty, totals stay', array() === $d['items'] && 5 === $d['total'] );
$d = data( $c->list_inquiries( req( array( 'per_page' => 5000 ) ) ) );
check( 'pagination: per_page is capped at 100', 100 === $d['per_page'] );

$e = $c->list_inquiries( req( array( 'type' => 'bogus' ) ) );
check( 'filter: unknown type -> 400', $e instanceof WP_Error && 400 === $e->data['status'] );
$e = $c->list_inquiries( req( array( 'status' => "new' OR 1=1 --" ) ) );
check( 'filter: unknown status (SQL attempt) -> 400', $e instanceof WP_Error && 400 === $e->data['status'] );

// ── Status change ───────────────────────────────────────────────────────────────────────────────────
seed();
$d = data( $c->set_status( req( array( 'id' => 1, 'status' => 'handled' ) ) ) );
check( 'status: mark handled, response carries the new badge count', 'handled' === db()->inq[1]['status'] && 'handled' === $d['status'] && 2 === $d['new_count'] );
$d = data( $c->set_status( req( array( 'id' => 1, 'status' => 'new' ) ) ) );
check( 'status: mark new again', 'new' === db()->inq[1]['status'] && 3 === $d['new_count'] );
$e = $c->set_status( req( array( 'id' => 1, 'status' => 'spam' ) ) );
check( 'status: invalid value -> 400, row unchanged', $e instanceof WP_Error && 400 === $e->data['status'] && 'new' === db()->inq[1]['status'] );
$e = $c->set_status( req( array( 'id' => 999, 'status' => 'handled' ) ) );
check( 'status: unknown id -> 404', $e instanceof WP_Error && 404 === $e->data['status'] );

$d = data( $c->bulk_set_status( req( array( 'ids' => array( 1, 2, 5, 777, '2', 'x' ), 'status' => 'handled' ) ) ) );
check( 'bulk status: updates the existing ids (ids sanitised and de-duplicated)', 3 === $d['updated'] && 0 === $d['new_count'] && 'handled' === db()->inq[5]['status'] );
$e = $c->bulk_set_status( req( array( 'ids' => array( 1 ), 'status' => 'nope' ) ) );
check( 'bulk status: invalid status -> 400', $e instanceof WP_Error && 400 === $e->data['status'] );
$d = data( $c->bulk_set_status( req( array( 'ids' => array(), 'status' => 'new' ) ) ) );
check( 'bulk status: empty id list is a no-op', 0 === $d['updated'] );

// ── Delete ──────────────────────────────────────────────────────────────────────────────────────────
seed();
$d = data( $c->delete_inquiry( req( array( 'id' => 2 ) ) ) );
check( 'delete: removes one inquiry, response carries the badge count', ! isset( db()->inq[2] ) && true === $d['deleted'] && 2 === $d['new_count'] && 4 === count( db()->inq ) );
$e = $c->delete_inquiry( req( array( 'id' => 2 ) ) );
check( 'delete: already gone -> 404', $e instanceof WP_Error && 404 === $e->data['status'] );
$d = data( $c->bulk_delete( req( array( 'ids' => array( 1, 3, 4, 1, 0, -5 ) ) ) ) );
check( 'bulk delete: removes the listed ids (0, negatives, text and duplicates ignored)', 3 === $d['deleted'] && array( 5 ) === array_keys( db()->inq ), json_encode( array_keys( db()->inq ) ) );
$d = data( $c->bulk_delete( req( array( 'ids' => 'nonsense' ) ) ) );
check( 'bulk delete: garbage input deletes nothing', 0 === $d['deleted'] && 1 === count( db()->inq ) );
check( 'count_new: counts only new inquiries', 1 === InquiryRestController::count_new() );

echo PHP_EOL . ( $failed ? "{$failed} failed" : 'all passed' ) . PHP_EOL;
exit( $failed ? 1 : 0 );
