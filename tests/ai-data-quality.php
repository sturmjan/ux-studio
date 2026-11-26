<?php
// Test only: never runs over HTTP (the release zip also excludes tests/).
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { exit; }
/**
 * AI Assistant data quality:
 *  1. product text for search / RAG is plain readable text (no \uXXXX JSON escapes), and the one-time upgrade
 *     re-indexes the products and re-vectorizes the product vectors in the background;
 *  2. conversations.session_id UNIQUE: the migration merges duplicate rows first, ConversationStore::ensure_row
 *     works with and without the key.
 *
 *   php tests/ai-data-quality.php
 *
 * Needs no WordPress: an in-memory $wpdb double that understands just the statements these classes issue, plus
 * WP function stubs. Exits non-zero on failure.
 *
 * @package UxStudio
 */

// phpcs:disable

define( 'ABSPATH', __DIR__ . '/' );
define( 'WPINC', 'wp-includes' );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'ARRAY_A', 'ARRAY_A' );
$root = dirname( __DIR__ );
define( 'UXSTUDIO_PATH', $root . '/' );

eval( 'namespace UxStudio\Core; final class Security { public static function get_secret( string $o ): string { return ""; } }' );
eval( 'class WooCommerce {}' );

function wp_json_encode( $v, $flags = 0 ) { return json_encode( $v, $flags ); }
function wp_strip_all_tags( $s ) { return trim( strip_tags( (string) $s ) ); }
function __( $t, $d = null ) { return $t; }
function error_log_stub() {}
$GLOBALS['opts']   = array();
$GLOBALS['events'] = array();
function get_option( $k, $d = false ) { return $GLOBALS['opts'][ $k ] ?? $d; }
function update_option( $k, $v, $autoload = null ) { $GLOBALS['opts'][ $k ] = $v; return true; }
function delete_option( $k ) { unset( $GLOBALS['opts'][ $k ] ); return true; }
function wp_next_scheduled( $hook ) { foreach ( $GLOBALS['events'] as $e ) { if ( $e[1] === $hook ) { return $e[0]; } } return false; }
function wp_schedule_single_event( $ts, $hook ) { $GLOBALS['events'][] = array( $ts, $hook ); return true; }
function clear_events() { $GLOBALS['events'] = array(); }
function events(): array { return array_column( $GLOBALS['events'], 1 ); }
function wc_get_products( $args ) { return $GLOBALS['wc_products'] ?? array(); }

/**
 * In-memory $wpdb double: vectors, product index and conversations tables.
 */
final class FakeDb {
	public string $prefix = 'wp_';
	public int $insert_id = 0;
	public string $last_error = '';
	/** @var array<int, array<string, mixed>> */
	public array $vectors = array();
	/** @var array<int, array<string, mixed>> */
	public array $products = array();
	/** @var array<int, array<string, mixed>> */
	public array $conv = array();
	/** @var array<string, bool> index name => exists */
	public array $indexes = array( 'session_id' => true );
	public bool $unique = false;
	public int $next_vec = 1;
	public int $next_conv = 1;
	public $on_insert = null;
	public array $alter_log = array();

	public function suppress_errors( $b = true ) { return false; }
	public function esc_like( $s ) { return $s; }

	public function prepare( string $q, ...$args ) {
		if ( 1 === count( $args ) && is_array( $args[0] ) ) { $args = $args[0]; }
		if ( str_starts_with( ltrim( $q ), 'INSERT INTO' ) ) { return 'INSERT:' . base64_encode( serialize( $args ) ); }
		$i = 0;
		return (string) preg_replace_callback( '/%[sd]/', function ( $m ) use ( &$i, $args ) {
			$v = $args[ $i++ ];
			return '%d' === $m[0] ? (string) (int) $v : "'" . str_replace( "'", "''", (string) $v ) . "'";
		}, $q );
	}
	private static function q( string $v ): string { return str_replace( "''", "'", $v ); }

	public function insert( $table, $data ) {
		if ( $this->on_insert ) { $hook = $this->on_insert; $this->on_insert = null; $hook( $this ); }
		if ( $this->unique ) {
			foreach ( $this->conv as $r ) {
				if ( $r['session_id'] === $data['session_id'] ) { $this->last_error = 'Duplicate entry'; return false; }
			}
		}
		$row = array_merge( array( 'messages' => '[]', 'handoff_status' => 'ai', 'handoff_assigned_to' => null, 'operator_unread' => 0, 'customer_unread' => 0, 'handoff_requested_at' => null, 'handoff_started_at' => null, 'rating' => 0, 'rating_feedback' => '', 'gdpr_consent' => 0, 'status' => 'active', 'last_message_at' => null, 'visitor_ip' => '', 'visitor_user_agent' => '', 'page_url' => '' ), $data );
		$row['id'] = $this->next_conv++;
		$this->conv[ $row['id'] ] = $row;
		$this->insert_id = $row['id'];
		return 1;
	}
	public function update( $table, $data, $where ) {
		if ( str_contains( $table, 'conversations' ) ) {
			$n = 0;
			foreach ( $this->conv as $id => $row ) {
				foreach ( $where as $k => $v ) { if ( (string) ( $row[ $k ] ?? '' ) !== (string) $v ) { continue 2; } }
				$this->conv[ $id ] = array_merge( $row, $data );
				++$n;
			}
			return $n;
		}
		return 0;
	}
	public function delete( $table, $where ) {
		if ( str_contains( $table, 'conversations' ) ) {
			$n = isset( $this->conv[ $where['id'] ] ) ? 1 : 0;
			unset( $this->conv[ $where['id'] ] );
			return $n;
		}
		if ( str_contains( $table, 'vectors' ) ) {
			$n = 0;
			foreach ( $this->vectors as $k => $v ) {
				foreach ( $where as $c => $val ) { if ( (string) $v[ $c ] !== (string) $val ) { continue 2; } }
				unset( $this->vectors[ $k ] );
				++$n;
			}
			return $n;
		}
		return 0;
	}
	public function query( $sql ) {
		if ( str_starts_with( $sql, 'INSERT:' ) ) {
			$a = unserialize( base64_decode( substr( $sql, 7 ) ) );
			foreach ( array_chunk( $a, 8 ) as $c ) {
				$this->vectors[ $this->next_vec ] = array( 'id' => $this->next_vec, 'chat_target' => $c[0], 'source_type' => $c[1], 'source_id' => (int) $c[2], 'chunk_text' => $c[3], 'chunk_index' => $c[4], 'vector' => $c[5], 'embedding_model' => $c[6], 'metadata' => $c[7] );
				++$this->next_vec;
			}
			return count( $a ) / 8;
		}
		if ( preg_match( "/^DELETE FROM \S+vectors WHERE source_type = '([^']*)' AND source_id = (\d+) AND id <= (\d+)/", $sql, $m ) ) {
			$n = 0;
			foreach ( $this->vectors as $k => $v ) {
				if ( $v['source_type'] === $m[1] && $v['source_id'] === (int) $m[2] && $v['id'] <= (int) $m[3] ) { unset( $this->vectors[ $k ] ); ++$n; }
			}
			return $n;
		}
		if ( str_contains( $sql, 'ADD UNIQUE KEY' ) ) {
			$this->alter_log[] = $sql;
			$seen = array();
			foreach ( $this->conv as $r ) {
				if ( isset( $seen[ $r['session_id'] ] ) ) { $this->last_error = 'Duplicate entry'; return false; }
				$seen[ $r['session_id'] ] = true;
			}
			$this->indexes['session_id_unique'] = true;
			$this->unique = true;
			return 1;
		}
		if ( str_contains( $sql, 'DROP INDEX session_id' ) ) {
			$this->alter_log[] = $sql;
			unset( $this->indexes['session_id'] );
			return 1;
		}
		throw new RuntimeException( 'unsupported query: ' . $sql );
	}

	public function get_col( $sql ) {
		if ( str_contains( $sql, 'HAVING COUNT(*) > 1' ) ) {
			$count = array();
			foreach ( $this->conv as $r ) { $count[ $r['session_id'] ] = ( $count[ $r['session_id'] ] ?? 0 ) + 1; }
			return array_keys( array_filter( $count, fn( $c ) => $c > 1 ) );
		}
		if ( preg_match( "/SELECT DISTINCT source_id FROM \S+vectors WHERE source_type = '([^']*)'/", $sql, $m ) ) {
			$ids = array_unique( array_map( fn( $v ) => $v['source_id'], array_filter( $this->vectors, fn( $v ) => $v['source_type'] === $m[1] ) ) );
			sort( $ids );
			return $ids;
		}
		if ( preg_match( "/SELECT DISTINCT chat_target FROM \S+vectors WHERE source_type = '([^']*)' AND source_id = (\d+)/", $sql, $m ) ) {
			return array_values( array_unique( array_map( fn( $v ) => $v['chat_target'], array_filter( $this->vectors, fn( $v ) => $v['source_type'] === $m[1] && $v['source_id'] === (int) $m[2] ) ) ) );
		}
		return array();
	}
	public function get_var( $sql ) {
		if ( preg_match( "/SELECT MIN\(id\) FROM \S+conversations WHERE session_id = '(.*)'$/s", $sql, $m ) ) {
			$sid = self::q( $m[1] );
			$ids = array_keys( array_filter( $this->conv, fn( $r ) => $r['session_id'] === $sid ) );
			return $ids ? min( $ids ) : null;
		}
		if ( preg_match( "/SHOW INDEX FROM \S+ WHERE Key_name = '([^']*)'/", $sql, $m ) ) { return ! empty( $this->indexes[ $m[1] ] ) ? 'wp_conversations' : null; }
		if ( preg_match( "/SELECT MAX\(id\) FROM \S+vectors WHERE source_type = '([^']*)' AND source_id = (\d+)/", $sql, $m ) ) {
			$ids = array_map( fn( $v ) => $v['id'], array_filter( $this->vectors, fn( $v ) => $v['source_type'] === $m[1] && $v['source_id'] === (int) $m[2] ) );
			return $ids ? max( $ids ) : null;
		}
		throw new RuntimeException( 'unsupported get_var: ' . $sql );
	}
	public function get_results( $sql, $output = null ) {
		if ( preg_match( "/SELECT \* FROM \S+conversations WHERE session_id = '(.*)' ORDER BY id ASC/s", $sql, $m ) ) {
			$sid  = self::q( $m[1] );
			$rows = array_values( array_filter( $this->conv, fn( $r ) => $r['session_id'] === $sid ) );
			usort( $rows, fn( $a, $b ) => $a['id'] <=> $b['id'] );
			return $rows;
		}
		throw new RuntimeException( 'unsupported get_results: ' . $sql );
	}
	public function get_row( $sql, $output = null ) {
		if ( preg_match( '/FROM \S+product_index WHERE product_id = (\d+)/', $sql, $m ) ) { return $this->products[ (int) $m[1] ] ?? null; }
		if ( preg_match( '/FROM \S+conversations WHERE id = (\d+)/', $sql, $m ) ) { return isset( $this->conv[ (int) $m[1] ] ) ? (object) $this->conv[ (int) $m[1] ] : null; }
		throw new RuntimeException( 'unsupported get_row: ' . $sql );
	}
}
$GLOBALS['wpdb'] = new FakeDb();

require $root . '/includes/Autoloader.php';
\UxStudio\Autoloader::register();
require $root . '/vendor/ux1-ai-core/autoload.php';

use Ux1\AiCore\Rag\EmbeddingProvider;
use Ux1\AiCore\Rag\FallbackEmbedding;
use UxStudio\Modules\AiAssistant\ConversationDedupe;
use UxStudio\Modules\AiAssistant\ConversationStore;
use UxStudio\Modules\AiAssistant\KnowledgeBootstrap;
use UxStudio\Modules\AiAssistant\ProductIndexer;
use UxStudio\Modules\AiAssistant\Rag\TrainingSourceManager;

$failed = 0;
function check( string $name, bool $ok, string $detail = '' ): void {
	global $failed;
	echo ( $ok ? 'ok   ' : 'FAIL ' ) . $name . ( $ok || '' === $detail ? '' : ' - ' . $detail ) . PHP_EOL;
	if ( ! $ok ) { ++$failed; }
}
function db(): FakeDb { return $GLOBALS['wpdb']; }
function fresh(): void { $GLOBALS['wpdb'] = new FakeDb(); $GLOBALS['opts'] = array(); $GLOBALS['events'] = array(); }

// ── 1. Product text is plain readable text ──────────────────────────────────────────────────────────
$escaped_attrs = '{"barva":"červená","popis":"sta u nás. Vyberete si ještě dnes!"}';
$escaped_cats  = '["Zahrada","Čaje a káva"]';

check( 'plain_text: strips tags, decodes entities (incl. nbsp), collapses whitespace', 'Sta u nás x' === ProductIndexer::plain_text( "Sta&nbsp;u n&aacute;s

 <b>x</b>" ), ProductIndexer::plain_text( "Sta&nbsp;u n&aacute;s

 <b>x</b>" ) );
check( 'plain_text: decodes quotes and ampersands', 'Tom & "Jerry\'s"' === ProductIndexer::plain_text( 'Tom &amp; &quot;Jerry&#039;s&quot;' ) );

$json = ProductIndexer::encode_json( array( 'popis' => 'sta u nás. Vyberete si ještě dnes!', 'url' => 'https://x.cz/a/b' ) );
check( 'encode_json: no \\uXXXX escapes and no escaped slashes', ! str_contains( $json, '\\u' ) && ! str_contains( $json, '\\/' ) && str_contains( $json, 'ještě' ), $json );
check( 'encode_json: still valid JSON that round-trips', 'sta u nás. Vyberete si ještě dnes!' === json_decode( $json, true )['popis'] );

check( 'readable_column: legacy escaped attribute object becomes "key: value; key: value"', 'barva: červená; popis: sta u nás. Vyberete si ještě dnes!' === ProductIndexer::readable_column( $escaped_attrs ), ProductIndexer::readable_column( $escaped_attrs ) );
check( 'readable_column: category list becomes a comma list', 'Zahrada, Čaje a káva' === ProductIndexer::readable_column( $escaped_cats ) );
check( 'readable_column: plain text, empty and invalid JSON pass through', 'Zahrada' === ProductIndexer::readable_column( 'Zahrada' ) && '' === ProductIndexer::readable_column( '' ) && '[oops' === ProductIndexer::readable_column( '[oops' ) );
check( 'readable_column: nested values and empty values are handled', 'a: x, y' === ProductIndexer::readable_column( '{"a":["x","y"],"b":""}' ) );

$row = array( 'product_id' => 1, 'name' => 'Čaj', 'description_text' => 'Voní &amp; chutná', 'categories' => $escaped_cats, 'attributes' => $escaped_attrs, 'price' => '129.00', 'permalink' => 'https://x.cz/caj' );
$text = TrainingSourceManager::product_text( $row );
check( 'chunk text: no JSON escapes, braces or quotes of raw JSON', ! str_contains( $text, '\\u' ) && ! str_contains( $text, '{' ) && ! str_contains( $text, '"' ), $text );
check( 'chunk text: readable categories, attributes, price, decoded description', str_contains( $text, "Categories: Zahrada, Čaje a káva" ) && str_contains( $text, 'Attributes: barva: červená; popis: sta u nás. Vyberete si ještě dnes!' ) && str_contains( $text, 'Price: 129.00' ) && str_contains( $text, 'Voní & chutná' ), $text );

// ── 1b. One-time upgrade: re-index + re-vectorize, in the background ───────────────────────────────────
fresh();
$fb = new FallbackEmbedding();
// Two products vectorized earlier (one for both chats), with the old escaped text; one stale id without index row.
$old_text = "Čaj\nAttributes: {\"popis\":\"sta u n\\u00e1s\"}";
foreach ( array( array( 1, 'public' ), array( 1, 'internal' ), array( 2, 'public' ), array( 99, 'public' ) ) as $i => $v ) {
	db()->vectors[ db()->next_vec ] = array( 'id' => db()->next_vec, 'chat_target' => $v[1], 'source_type' => 'product', 'source_id' => $v[0], 'chunk_text' => $old_text, 'chunk_index' => 0, 'vector' => json_encode( $fb->embed( $old_text ) ), 'embedding_model' => $fb->get_model_name(), 'metadata' => null );
	++db()->next_vec;
}
db()->vectors[ db()->next_vec ] = array( 'id' => db()->next_vec, 'chat_target' => 'public', 'source_type' => 'faq', 'source_id' => 1, 'chunk_text' => 'faq', 'chunk_index' => 0, 'vector' => '[]', 'embedding_model' => $fb->get_model_name(), 'metadata' => null );
++db()->next_vec;
db()->products[1] = array_merge( $row, array( 'product_id' => 1 ) );
db()->products[2] = array_merge( $row, array( 'product_id' => 2, 'name' => 'Káva' ) );

KnowledgeBootstrap::maybe_schedule_product_text_fix();
check( 'upgrade: scheduled once (one cron event, state "scheduled")', array( KnowledgeBootstrap::CRON_PRODUCT_TEXT_FIX ) === events() && 'scheduled' === get_option( KnowledgeBootstrap::OPTION_PRODUCT_TEXT_FIX ) );
KnowledgeBootstrap::maybe_schedule_product_text_fix();
check( 'upgrade: a second request schedules nothing', 1 === count( events() ) );

clear_events();
$GLOBALS['wc_products'] = array( 1, 2 ); // the re-index queue
// run_product_text_fix() -> queue_reindex() needs real WooCommerce posts; here only the hand-over is checked.
update_option( KnowledgeBootstrap::OPTION_PRODUCT_TEXT_FIX, 'reindexing' );
KnowledgeBootstrap::on_product_reindex_finished();
check( 'upgrade: when the re-index queue is empty the re-vectorization is queued (once, state "done")', 'done' === get_option( KnowledgeBootstrap::OPTION_PRODUCT_TEXT_FIX ) && array( KnowledgeBootstrap::CRON_PRODUCT_VECTORS ) === events() && array( 1, 2, 99 ) === get_option( KnowledgeBootstrap::OPTION_PRODUCT_VECTOR_QUEUE ), json_encode( get_option( KnowledgeBootstrap::OPTION_PRODUCT_VECTOR_QUEUE ) ) );
clear_events();
KnowledgeBootstrap::on_product_reindex_finished();
check( 'upgrade: an ordinary re-index afterwards does not re-queue vectors', array() === events() );

$manager = new TrainingSourceManager();
$manager->revectorize_products_batch();
$by_src = array();
foreach ( db()->vectors as $v ) { $by_src[ $v['source_type'] . ':' . $v['source_id'] ][] = $v; }
check( 'revectorize: product 1 keeps both chat targets', isset( $by_src['product:1'] ) && array( 'internal', 'public' ) === ( function () use ( $by_src ) { $t = array_column( $by_src['product:1'], 'chat_target' ); sort( $t ); return $t; } )(), json_encode( array_column( $by_src['product:1'] ?? array(), 'chat_target' ) ) );
check( 'revectorize: old vectors replaced (exactly one chunk per target, none with escapes)', 2 === count( $by_src['product:1'] ) && ! array_filter( db()->vectors, fn( $v ) => 'product' === $v['source_type'] && str_contains( $v['chunk_text'], '\\u' ) ) );
check( 'revectorize: new chunk text is readable and the vector matches it', str_contains( $by_src['product:2'][0]['chunk_text'], 'Attributes: barva: červená' ) && json_decode( $by_src['product:2'][0]['vector'], true ) == $fb->embed( $by_src['product:2'][0]['chunk_text'] ) );
check( 'revectorize: vectors of a product that left the index are dropped', ! isset( $by_src['product:99'] ) );
check( 'revectorize: other sources are untouched', isset( $by_src['faq:1'] ) && 1 === count( $by_src['faq:1'] ) );
check( 'revectorize: queue finished, no further cron event', false === get_option( KnowledgeBootstrap::OPTION_PRODUCT_VECTOR_QUEUE, false ) && array() === events() );

// Batching: more products than one batch reschedules itself.
fresh();
for ( $p = 1; $p <= 25; $p++ ) {
	db()->products[ $p ] = array_merge( $row, array( 'product_id' => $p ) );
	db()->vectors[ db()->next_vec ] = array( 'id' => db()->next_vec, 'chat_target' => 'public', 'source_type' => 'product', 'source_id' => $p, 'chunk_text' => 'old', 'chunk_index' => 0, 'vector' => '[]', 'embedding_model' => $fb->get_model_name(), 'metadata' => null );
	++db()->next_vec;
}
$manager = new TrainingSourceManager();
$manager->queue_product_revectorize();
clear_events();
$manager->revectorize_products_batch();
check( 'batching: 10 products per run, rest stays queued and a new event is scheduled', 15 === count( get_option( KnowledgeBootstrap::OPTION_PRODUCT_VECTOR_QUEUE ) ) && array( KnowledgeBootstrap::CRON_PRODUCT_VECTORS ) === events() );
$manager->revectorize_products_batch();
$manager->revectorize_products_batch();
check( 'batching: all 25 re-vectorized after three runs', false === get_option( KnowledgeBootstrap::OPTION_PRODUCT_VECTOR_QUEUE, false ) && 25 === count( db()->vectors ) && ! array_filter( db()->vectors, fn( $v ) => 'old' === $v['chunk_text'] ) );

// A failing embedding API must never leave a product without vectors.
fresh();
db()->products[5] = array_merge( $row, array( 'product_id' => 5 ) );
db()->vectors[1] = array( 'id' => 1, 'chat_target' => 'public', 'source_type' => 'product', 'source_id' => 5, 'chunk_text' => 'old', 'chunk_index' => 0, 'vector' => '[]', 'embedding_model' => 'm', 'metadata' => null );
db()->next_vec = 2;
$manager = new TrainingSourceManager();
$prop = new ReflectionProperty( TrainingSourceManager::class, 'embedding' );
$prop->setAccessible( true );
$prop->setValue( $manager, new class() implements EmbeddingProvider {
	public function get_id(): string { return 'x'; }
	public function get_model_name(): string { return 'x'; }
	public function get_dimensions(): int { return 1; }
	public function embed( string $text ): array { throw new RuntimeException( 'API down' ); }
	public function embed_batch( array $texts ): array { throw new RuntimeException( 'API down' ); }
	public function is_available(): bool { return false; }
} );
$manager->queue_product_revectorize();
clear_events();
$prev = ini_set( 'error_log', sys_get_temp_dir() . '/uxs-test-error.log' );
$manager->revectorize_products_batch();
ini_set( 'error_log', (string) $prev );
check( 'failure: old vectors are kept, the product stays queued, a retry is scheduled', 1 === count( db()->vectors ) && 'old' === db()->vectors[1]['chunk_text'] && array( 5 ) === get_option( KnowledgeBootstrap::OPTION_PRODUCT_VECTOR_QUEUE ) && array( KnowledgeBootstrap::CRON_PRODUCT_VECTORS ) === events() );

// ── 2. Migration: merge duplicate conversation rows ─────────────────────────────────────────────────
function conv( string $sid, array $over = array() ): array {
	return array_merge( array( 'session_id' => $sid, 'visitor_ip' => '1.2.3.4', 'page_url' => '/p', 'messages' => '[]', 'status' => 'active', 'gdpr_consent' => 1, 'created_at' => '2026-11-01 10:00:00', 'updated_at' => '2026-11-01 10:00:00' ), $over );
}
fresh();
$a = array( array( 'role' => 'user', 'content' => 'Hello', 'timestamp' => '2026-11-01 10:00:00' ), array( 'role' => 'assistant', 'content' => 'Hi', 'timestamp' => '2026-11-01 10:00:05' ) );
$b = array( array( 'role' => 'system', 'content' => 'Operator requested', 'timestamp' => '2026-11-01 10:00:03' ) );
$c = array( array( 'role' => 'agent', 'content' => 'Welcome', 'timestamp' => '2026-11-01 10:01:00' ) );
db()->insert( 't', conv( 's1', array( 'messages' => json_encode( $a ), 'last_message_at' => '2026-11-01 10:00:05' ) ) );                                                                  // id 1 (ai)
db()->insert( 't', conv( 's1', array( 'messages' => json_encode( $b ), 'handoff_status' => 'requested', 'handoff_requested_at' => '2026-11-01 10:00:03', 'operator_unread' => 1, 'last_message_at' => '2026-11-01 10:00:03' ) ) ); // id 2
db()->insert( 't', conv( 's1', array( 'messages' => json_encode( $c ), 'handoff_status' => 'human', 'handoff_assigned_to' => 7, 'handoff_started_at' => '2026-11-01 10:00:30', 'handoff_requested_at' => '2026-11-01 10:00:03', 'rating' => 4, 'rating_feedback' => 'Good', 'last_message_at' => '2026-11-01 10:01:00', 'updated_at' => '2026-11-01 10:01:00' ) ) ); // id 3
db()->insert( 't', conv( 's2', array( 'messages' => json_encode( $a ) ) ) );                                                                                                     // id 4, no duplicate
db()->insert( 't', conv( 's3', array( 'handoff_status' => 'closed' ) ) );                                                                                                       // id 5
db()->insert( 't', conv( 's3', array( 'handoff_status' => 'ai' ) ) );                                                                                                           // id 6

$removed = ConversationDedupe::merge_all();
check( 'merge: removes exactly the extra rows (2 of s1, 1 of s3)', 3 === $removed && array( 1, 4, 5 ) === array_keys( db()->conv ), json_encode( array_keys( db()->conv ) ) );
$m = json_decode( db()->conv[1]['messages'], true );
check( 'merge: survivor is the lowest id and holds all messages in time order', array( 'user', 'system', 'assistant', 'agent' ) === array_column( $m, 'role' ), json_encode( array_column( $m, 'role' ) ) );
check( 'merge: the most advanced handoff state wins (human, with its operator and start time)', 'human' === db()->conv[1]['handoff_status'] && 7 === db()->conv[1]['handoff_assigned_to'] && '2026-11-01 10:00:30' === db()->conv[1]['handoff_started_at'] && '2026-11-01 10:00:03' === db()->conv[1]['handoff_requested_at'] );
check( 'merge: rating, unread counter and last_message_at are combined', 4 === (int) db()->conv[1]['rating'] && 'Good' === db()->conv[1]['rating_feedback'] && 1 === (int) db()->conv[1]['operator_unread'] && '2026-11-01 10:01:00' === db()->conv[1]['last_message_at'] );
check( 'merge: sessions without duplicates are untouched', 's2' === db()->conv[4]['session_id'] && 2 === count( json_decode( db()->conv[4]['messages'], true ) ) );
check( 'merge: closed beats ai when no row is more advanced', 'closed' === db()->conv[5]['handoff_status'] );
check( 'merge: running again is a no-op', 0 === ConversationDedupe::merge_all() && 3 === count( db()->conv ) );

// Pure merge: messages without timestamps keep their order; undecodable messages JSON is skipped.
$pure = ConversationDedupe::merge_rows( array(
	array( 'id' => 1, 'messages' => json_encode( array( array( 'role' => 'user', 'content' => 'q1' ), array( 'role' => 'assistant', 'content' => 'a1' ) ) ), 'handoff_status' => 'ai' ),
	array( 'id' => 2, 'messages' => 'not json', 'handoff_status' => 'ai' ),
	array( 'id' => 3, 'messages' => json_encode( array( array( 'role' => 'user', 'content' => 'q2' ) ) ), 'handoff_status' => 'ai' ),
) );
check( 'merge_rows: untimed messages stay in order, broken JSON is ignored', array( 'q1', 'a1', 'q2' ) === array_column( json_decode( $pure['messages'], true ), 'content' ) );
check( 'merge_rows: stored messages keep readable UTF-8', ! str_contains( ConversationDedupe::merge_rows( array( array( 'id' => 1, 'messages' => json_encode( array( array( 'content' => 'ještě' ) ), JSON_UNESCAPED_UNICODE ) ), array( 'id' => 2, 'messages' => '[]' ) ) )['messages'], '\\u' ) );

// ensure_unique_key(): adds the key when dbDelta did not, drops the old plain index.
fresh();
db()->insert( 't', conv( 'x' ) );
db()->insert( 't', conv( 'x' ) );
ConversationDedupe::ensure_unique_key();
check( 'ensure_unique_key: merges leftovers, adds the UNIQUE key, drops the old plain index', 1 === count( db()->conv ) && db()->unique && ! empty( db()->indexes['session_id_unique'] ) && empty( db()->indexes['session_id'] ), json_encode( db()->alter_log ) );
$log = count( db()->alter_log );
ConversationDedupe::ensure_unique_key();
check( 'ensure_unique_key: idempotent (no further ALTER)', $log === count( db()->alter_log ) );

// ── 3. ensure_row with and without the UNIQUE key ───────────────────────────────────────────────────
// Without the key (old site mid-upgrade): a racing insert leaves two rows, the lowest id wins, ours is removed.
fresh();
db()->on_insert = function ( FakeDb $w ) { $w->insert( 't', conv( 'r1' ) ); };
$id = ConversationStore::ensure_row( 'r1', conv( 'r1' ) );
check( 'ensure_row (no key): race resolved in code - one row, the first insert wins', 1 === count( db()->conv ) && 1 === $id );
// With the key: the second insert fails, the existing row is returned and nothing is deleted.
fresh();
db()->unique = true;
db()->on_insert = function ( FakeDb $w ) { $w->unique = false; $w->insert( 't', conv( 'r2' ) ); $w->unique = true; };
$id = ConversationStore::ensure_row( 'r2', conv( 'r2' ) );
check( 'ensure_row (unique key): duplicate insert is rejected by the database, existing row returned', 1 === count( db()->conv ) && 1 === $id );
// Plain cases.
$first  = ConversationStore::ensure_row( 'r3', conv( 'r3' ) );
$second = ConversationStore::ensure_row( 'r3', conv( 'r3' ) );
check( 'ensure_row: creates once, then reuses (both modes)', $first === $second && 2 === count( db()->conv ) );
db()->unique = false;
$a1 = ConversationStore::ensure_row( 'r4', conv( 'r4' ) );
$a2 = ConversationStore::ensure_row( 'r4', conv( 'r4' ) );
check( 'ensure_row (no key): creates once, then reuses', $a1 === $a2 && 3 === count( db()->conv ) );

echo PHP_EOL . ( $failed ? "{$failed} failed" : 'all passed' ) . PHP_EOL;
exit( $failed ? 1 : 0 );
