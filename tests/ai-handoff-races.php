<?php
// Test only: never runs over HTTP (the release zip also excludes tests/).
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { exit; }
/**
 * Race conditions around the AI chat operator handoff (server side):
 *  - the chat save must APPEND to the stored messages, not overwrite them (lost update of handoff system messages);
 *  - one conversation row per session even when the chat save and the handoff request insert at the same time;
 *  - an operator can take over only a request that is still "requested" (not one the visitor cancelled).
 *
 *   php tests/ai-handoff-races.php
 *
 * Needs no WordPress: an in-memory $wpdb double (with hooks that inject a "concurrent request" at the exact
 * moment the real race happens) and a few WP function doubles. Exits non-zero when a case fails.
 *
 * @package UxStudio
 */

// phpcs:disable

use UxStudio\Modules\AiAssistant\ConversationStore;
use UxStudio\Modules\AiAssistant\HandoffManager;
use UxStudio\Modules\AiAssistant\HandoffRestController;

define( 'ABSPATH', __DIR__ . '/' );

eval( 'namespace UxStudio\Core; final class Settings { public static array $values = array(); public function __construct( string $option ) {} public function all(): array { return self::$values; } public function get( string $key, $default = null ) { return array_key_exists( $key, self::$values ) ? self::$values[ $key ] : $default; } }' );
eval( 'namespace UxStudio\Core; final class ClientIp { public static function get( string $mode = "auto" ): string { return "127.0.0.1"; } }' );
eval( 'namespace UxStudio\Rest; abstract class Controller {}' );
class WP_Error { public function __construct( public string $code = '', public string $message = '', public array $data = array() ) {} }
class WP_REST_Request {}
class WP_REST_Response {}

function __( $text, $domain = null ) { return $text; }
function current_time( $type ) { return '2026-11-26 10:00:00'; }
function wp_json_encode( $value ) { return json_encode( $value, JSON_UNESCAPED_UNICODE ); }
function get_userdata( $id ) { return (object) array( 'display_name' => 'Operator ' . $id ); }
function get_option( $name ) { return ''; }
function wp_mail() { return true; }

/**
 * In-memory $wpdb double. prepare() inlines the values (quoted), so get_row/get_var/query can parse the few
 * statements the code under test issues. $on_update / $on_insert are hooks run right BEFORE the write is
 * applied - that is where a test plays the "other request" that wins the race.
 */
final class UxsFakeWpdb {
	public string $prefix = 'wp_';
	public int $insert_id = 0;
	/** @var array<int,array<string,mixed>> */
	public array $rows = array();
	private int $next_id = 1;
	public $on_update = null;
	public $on_insert = null;

	public function prepare( $sql, ...$args ) {
		$i = 0;
		return preg_replace_callback( '/%[sd]/', static function ( $m ) use ( &$i, $args ) {
			$v = $args[ $i++ ];
			return '%d' === $m[0] ? (string) (int) $v : "'" . str_replace( "'", "''", (string) $v ) . "'";
		}, $sql );
	}
	private function unq( string $v ) { return str_replace( "''", "'", trim( $v, "'" ) ); }

	public function insert( $table, $data ) {
		if ( $this->on_insert ) { $hook = $this->on_insert; $this->on_insert = null; $hook( $this ); }
		$row = array_merge( array( 'messages' => '[]', 'handoff_status' => 'ai', 'handoff_assigned_to' => null, 'operator_unread' => 0, 'customer_unread' => 0, 'handoff_requested_at' => null ), $data );
		$row['id'] = $this->next_id++;
		$this->rows[ $row['id'] ] = $row;
		$this->insert_id = $row['id'];
		return 1;
	}
	public function delete( $table, $where ) { unset( $this->rows[ $where['id'] ] ); return 1; }

	public function update( $table, $data, $where ) {
		if ( $this->on_update ) { $hook = $this->on_update; $this->on_update = null; $hook( $this ); }
		$n = 0;
		foreach ( $this->rows as $id => $row ) {
			foreach ( $where as $k => $v ) { if ( (string) ( $row[ $k ] ?? '' ) !== (string) $v ) { continue 2; } }
			$this->rows[ $id ] = array_merge( $row, $data );
			++$n;
		}
		return $n;
	}

	public function query( $sql ) {
		// UPDATE ... SET handoff_status = 'requested', handoff_requested_at = '..', ... WHERE id = N AND handoff_status NOT IN ('a', 'b')
		if ( ! preg_match( "/SET handoff_status = '([^']*)', handoff_requested_at = '([^']*)'.*WHERE id = (\d+) AND handoff_status NOT IN \('([^']*)', '([^']*)'\)/s", $sql, $m ) ) { throw new RuntimeException( 'unsupported query: ' . $sql ); }
		$row = $this->rows[ (int) $m[3] ] ?? null;
		if ( ! $row || in_array( $row['handoff_status'], array( $m[4], $m[5] ), true ) ) { return 0; }
		$this->rows[ (int) $m[3] ]['handoff_status']       = $m[1];
		$this->rows[ (int) $m[3] ]['handoff_requested_at'] = $m[2];
		$this->rows[ (int) $m[3] ]['operator_unread']      = 1;
		return 1;
	}

	public function get_var( $sql ) {
		if ( preg_match( "/SELECT MIN\(id\) FROM \S+ WHERE session_id = '(.*)'$/s", $sql, $m ) ) {
			$ids = array_keys( array_filter( $this->rows, fn( $r ) => $r['session_id'] === $this->unq( "'" . $m[1] . "'" ) ) );
			return $ids ? min( $ids ) : null;
		}
		if ( preg_match( '/SELECT handoff_status FROM \S+ WHERE id = (\d+)/', $sql, $m ) ) { return $this->rows[ (int) $m[1] ]['handoff_status'] ?? null; }
		if ( preg_match( '/SELECT session_id FROM \S+ WHERE id = (\d+)/', $sql, $m ) ) { return $this->rows[ (int) $m[1] ]['session_id'] ?? null; }
		throw new RuntimeException( 'unsupported get_var: ' . $sql );
	}

	public function get_row( $sql ) {
		if ( preg_match( '/WHERE id = (\d+)/', $sql, $m ) ) { return isset( $this->rows[ (int) $m[1] ] ) ? (object) $this->rows[ (int) $m[1] ] : null; }
		if ( preg_match( "/WHERE session_id = '(.*)'$/s", $sql, $m ) ) {
			foreach ( $this->rows as $r ) { if ( $r['session_id'] === $this->unq( "'" . $m[1] . "'" ) ) { return (object) $r; } }
			return null;
		}
		throw new RuntimeException( 'unsupported get_row: ' . $sql );
	}
}

$GLOBALS['wpdb'] = new UxsFakeWpdb();
\UxStudio\Core\Settings::$values = array( 'operator_notifications_enabled' => false );

$dir = dirname( __DIR__ ) . '/includes/Modules/AiAssistant/';
spl_autoload_register( static function ( $class ) use ( $dir ) {
	$prefix = 'UxStudio\\Modules\\AiAssistant\\';
	if ( str_starts_with( $class, $prefix ) ) {
		$file = $dir . str_replace( '\\', '/', substr( $class, strlen( $prefix ) ) ) . '.php';
		if ( is_file( $file ) ) { require $file; }
	}
} );

$failed = 0;
function check( string $name, bool $ok, string $detail = '' ): void {
	global $failed;
	echo ( $ok ? 'ok   ' : 'FAIL ' ) . $name . ( $ok || '' === $detail ? '' : ' - ' . $detail ) . PHP_EOL;
	if ( ! $ok ) { ++$failed; }
}
function db(): UxsFakeWpdb { return $GLOBALS['wpdb']; }
function fresh(): void { $GLOBALS['wpdb'] = new UxsFakeWpdb(); }
function msgs( int $id ): array { return json_decode( db()->rows[ $id ]['messages'], true ); }
function roles( int $id ): string { return implode( ',', array_map( fn( $m ) => $m['role'], msgs( $id ) ) ); }
function chat_row( string $session ): array {
	return array( 'session_id' => $session, 'visitor_ip' => '127.0.0.1', 'page_url' => '/', 'messages' => '[]', 'status' => 'active', 'gdpr_consent' => 1 );
}

/** The exact steps ChatEngine::process_message() runs at the end of a turn. */
function chat_save( string $session, string $answer, string $page = '/p' ): void {
	$id = ConversationStore::ensure_row( $session, chat_row( $session ) );
	ConversationStore::append_messages( $id, array( array( 'role' => 'user', 'content' => 'Question' ), array( 'role' => 'assistant', 'content' => $answer ) ), array( 'page_url' => $page ) );
}

// ── 1. The chat save keeps messages added by the handoff code meanwhile ─────────────────────────────
fresh();
$m = new HandoffManager();
$id = ConversationStore::ensure_row( 's1', chat_row( 's1' ) ); // chat read the (empty) conversation here, then streams for 40+ s
$m->request_handoff( 's1', 'Customer pressed the button.' ); // visitor clicks "Call an operator" meanwhile
chat_save( 's1', 'Late answer' ); // the stream finishes and saves
check( 'chat save: the handoff system message survives the late chat save', 'system,user,assistant' === roles( $id ), roles( $id ) );
check( 'chat save: the handoff state is untouched by the chat save', 'requested' === db()->rows[ $id ]['handoff_status'] && 1 === (int) db()->rows[ $id ]['operator_unread'] );
check( 'chat save: page_url of the chat turn is stored', '/p' === db()->rows[ $id ]['page_url'] );

// cancel and operator messages arriving during a stream are kept too
$m->cancel_request( 's1' );
chat_save( 's1', 'Another answer' );
check( 'chat save: cancel system message and both turns are all kept', 'system,user,assistant,system,user,assistant' === roles( $id ), roles( $id ) );

// ── 2. Compare-and-swap: an append that loses the race is retried on the fresh value ──────────────────
fresh();
$id = ConversationStore::ensure_row( 's2', chat_row( 's2' ) );
db()->on_update = function ( UxsFakeWpdb $w ) use ( $id ) { // another request appends between our read and our write
	$w->rows[ $id ]['messages'] = json_encode( array( array( 'role' => 'agent', 'content' => 'Hello from the operator' ) ) );
};
$written = ConversationStore::append_messages( $id, array( array( 'role' => 'user', 'content' => 'Hi' ) ) );
check( 'append: a concurrent append between read and write is merged, not overwritten', 'agent,user' === roles( $id ) && 2 === count( $written ), roles( $id ) );

// operator message + customer message through the manager keep the counters and order
$m->request_handoff( 's2', 'x' );
$m->assign_operator( $id, 7 );
$sent = $m->send_operator_message( $id, 7, 'Welcome' );
$m->send_customer_message( 's2', 'Thanks', '/page' );
check( 'append: operator and customer messages are appended in order', 'agent,user,system,system,agent,user' === roles( $id ), roles( $id ) );
check( 'append: send_operator_message reports the new total_count', is_array( $sent ) && 5 === $sent['total_count'], json_encode( $sent ) );
check( 'append: unread counters are bumped from the fresh row', 2 === (int) db()->rows[ $id ]['customer_unread'] && 1 === (int) db()->rows[ $id ]['operator_unread'], db()->rows[ $id ]['customer_unread'] . '/' . db()->rows[ $id ]['operator_unread'] );

// ── 3. One row per session ──────────────────────────────────────────────────────────────────────────
// 3a. the chat inserts its row between the handoff's "no row yet" check and its own insert
fresh();
db()->on_insert = function ( UxsFakeWpdb $w ) { $w->insert( 'x', chat_row( 's3' ) ); }; // competing insert wins the lower id
$m = new HandoffManager();
$result = $m->request_handoff( 's3', 'help' );
check( 'duplicates: a racing insert leaves exactly one row for the session', 1 === count( db()->rows ), (string) count( db()->rows ) );
$only = array_key_first( db()->rows );
check( 'duplicates: the surviving row carries the handoff request (state + system message)', 'requested' === db()->rows[ $only ]['handoff_status'] && 'system' === roles( $only ) && true === $result['requested'] );

// 3b. the handoff row exists first, the chat then saves into it (no second row)
fresh();
$m = new HandoffManager();
$m->request_handoff( 's4', 'help' );
chat_save( 's4', 'Answer' );
check( 'duplicates: chat save after a handoff request reuses the row', 1 === count( db()->rows ) && 'system,user,assistant' === roles( array_key_first( db()->rows ) ) );

// 3c. a repeated request does not add a second system message or flip the state
$second = $m->request_handoff( 's4', 'help again' );
check( 'duplicates: a repeated request is a no-op (requested=false, one system message)', false === $second['requested'] && 'requested' === $second['status'] && 'system,user,assistant' === roles( array_key_first( db()->rows ) ) );

// 3d. GDPR consent backfill uses the same single row
fresh();
$engine = new \ReflectionClass( \UxStudio\Modules\AiAssistant\ChatEngine::class );
// record_gdpr_consent() needs only ensure_row + update; call it through a bare instance (constructor not needed).
$chat = $engine->newInstanceWithoutConstructor();
$chat->record_gdpr_consent( 's5' );
chat_save( 's5', 'A' );
$chat->record_gdpr_consent( 's5' );
check( 'duplicates: consent + chat save + consent -> one row', 1 === count( db()->rows ) );

// ── 4. Assign only from "requested" ──────────────────────────────────────────────────────────────────
fresh();
$m = new HandoffManager();
$m->request_handoff( 's6', 'help' );
$id = array_key_first( db()->rows );
$m->cancel_request( 's6' ); // the visitor's widget cancelled (timeout / button)
$msgs_before = roles( $id );
check( 'assign: refused after the visitor cancelled (status ai)', false === $m->assign_operator( $id, 7 ) );
check( 'assign: a refused take-over changes nothing (status, assignee, messages)', 'ai' === db()->rows[ $id ]['handoff_status'] && null === db()->rows[ $id ]['handoff_assigned_to'] && $msgs_before === roles( $id ) );

$m->request_handoff( 's6', 'help again' );
check( 'assign: succeeds from requested', true === $m->assign_operator( $id, 7 ) && 'human' === db()->rows[ $id ]['handoff_status'] && 7 === (int) db()->rows[ $id ]['handoff_assigned_to'] );
$count = count( msgs( $id ) );
check( 'assign: the same operator again (double click) is a no-op success', true === $m->assign_operator( $id, 7 ) && $count === count( msgs( $id ) ) );
check( 'assign: another operator cannot steal an active conversation', false === $m->assign_operator( $id, 8 ) && 7 === (int) db()->rows[ $id ]['handoff_assigned_to'] );
check( 'cancel: the visitor cannot cancel once an operator joined', false === $m->cancel_request( 's6' ) && 'human' === db()->rows[ $id ]['handoff_status'] );

// a cancel that lands between the admin list and the click: atomic guard, not just a pre-check
fresh();
$m = new HandoffManager();
$m->request_handoff( 's7', 'help' );
$id = array_key_first( db()->rows );
db()->on_update = function ( UxsFakeWpdb $w ) use ( $id ) { $w->rows[ $id ]['handoff_status'] = 'ai'; }; // cancel wins right before our UPDATE
check( 'assign: a cancel racing with the take-over wins (UPDATE is conditional)', false === $m->assign_operator( $id, 7 ) && 'ai' === db()->rows[ $id ]['handoff_status'] );

// ── 5. REST: clear error codes the admin UI prints ───────────────────────────────────────────────────
$rest   = ( new \ReflectionClass( HandoffRestController::class ) )->newInstanceWithoutConstructor();
$method = new \ReflectionMethod( HandoffRestController::class, 'not_takeable_error' );
$method->setAccessible( true );
$err = fn( int $id ) => $method->invoke( $rest, new HandoffManager(), $id );
$e = $err( $id );
check( 'rest: cancelled request -> 409 uxstudio_handoff_request_cancelled with a readable message', 'uxstudio_handoff_request_cancelled' === $e->code && 409 === $e->data['status'] && str_contains( $e->message, 'cancelled' ) );
db()->rows[ $id ]['handoff_status'] = 'human';
check( 'rest: taken by someone else -> 409 uxstudio_handoff_already_taken', 'uxstudio_handoff_already_taken' === $err( $id )->code );
db()->rows[ $id ]['handoff_status'] = 'closed';
check( 'rest: closed -> 409 uxstudio_handoff_closed', 'uxstudio_handoff_closed' === $err( $id )->code );
check( 'rest: unknown id -> 404', 404 === $err( 999 )->data['status'] );

echo PHP_EOL . ( $failed ? "FAILED: {$failed}" : 'All checks passed.' ) . PHP_EOL;
exit( $failed ? 1 : 0 );
