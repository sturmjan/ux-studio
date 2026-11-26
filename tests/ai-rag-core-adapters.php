<?php
// Test only: never runs over HTTP (the release zip also excludes tests/).
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { exit; }
/**
 * The plugin side of the core RAG (PLAN.md 45.5): the $wpdb VectorStore filters candidates by embedding_model,
 * search ignores vectors of another model, and legacy fallback vectors (tf-idf-256) are re-embedded in place by a
 * one-time cron instead of silently breaking search.
 *
 *   php tests/ai-rag-core-adapters.php
 *
 * Needs no WordPress: a fake $wpdb that understands just the queries these classes issue, plus stubs of the WP
 * option/cron functions. Exits non-zero on failure.
 *
 * @package UxStudio
 */

// phpcs:disable

define( 'ABSPATH', __DIR__ . '/' );
define( 'WPINC', 'wp-includes' );
define( 'HOUR_IN_SECONDS', 3600 );
$root = dirname( __DIR__ );
define( 'UXSTUDIO_PATH', $root . '/' );

eval( 'namespace UxStudio\Core; final class Security { public static array $secrets = array(); public static function get_secret( string $o ): string { return self::$secrets[ $o ] ?? ""; } }' );

define( 'ARRAY_A', 'ARRAY_A' );
function wp_json_encode( $v ) { return json_encode( $v ); }
$GLOBALS['opts']   = array();
$GLOBALS['events'] = array();
function get_option( $k, $d = false ) { return $GLOBALS['opts'][ $k ] ?? $d; }
function update_option( $k, $v, $autoload = null ) { $GLOBALS['opts'][ $k ] = $v; return true; }
function delete_option( $k ) { unset( $GLOBALS['opts'][ $k ] ); return true; }
function wp_next_scheduled( $hook ) { foreach ( $GLOBALS['events'] as $e ) { if ( $e[1] === $hook ) { return $e[0]; } } return false; }
function wp_schedule_single_event( $ts, $hook ) { $GLOBALS['events'][] = array( $ts, $hook ); return true; }
function error_log_stub() {}

/** Fake $wpdb over an in-memory vectors table. */
final class FakeWpdb {
	public string $prefix = 'wp_';
	/** @var array<int, array<string, mixed>> */
	public array $vectors = array();
	/** @var array<int, string> */
	public array $sql = array();

	public function prepare( string $q, ...$args ): string {
		if ( 1 === count( $args ) && is_array( $args[0] ) ) { $args = $args[0]; }
		$i = 0;
		return (string) preg_replace_callback( '/%[sd]/', function ( $m ) use ( &$i, $args ) {
			$v = $args[ $i++ ];
			return '%d' === $m[0] ? (string) (int) $v : "'" . addslashes( (string) $v ) . "'";
		}, $q );
	}
	private function models( string $q ): array { preg_match( '/embedding_model IN \(([^)]*)\)/', $q, $m ); return array_map( fn( $x ) => trim( $x, "' " ), explode( ',', $m[1] ) ); }
	public function get_results( string $q, $output = null ) {
		$this->sql[] = $q;
		$rows = $this->vectors;
		if ( str_contains( $q, 'embedding_model IN' ) ) {
			$models = $this->models( $q );
			$rows   = array_values( array_filter( $rows, fn( $r ) => in_array( $r['embedding_model'], $models, true ) ) );
		} elseif ( preg_match( "/chat_target = '([^']*)' AND embedding_model = '([^']*)'/", $q, $m ) ) {
			$rows = array_values( array_filter( $rows, fn( $r ) => $r['chat_target'] === $m[1] && $r['embedding_model'] === $m[2] ) );
		}
		if ( preg_match( '/LIMIT (\d+)(?: OFFSET (\d+))?/', $q, $m ) ) { $rows = array_slice( $rows, (int) ( $m[2] ?? 0 ), (int) $m[1] ); }
		return $rows;
	}
	public function get_var( string $q ) {
		$this->sql[] = $q;
		$rows = $this->vectors;
		if ( str_contains( $q, 'embedding_model IN' ) ) {
			$models = $this->models( $q );
			return count( array_filter( $rows, fn( $r ) => in_array( $r['embedding_model'], $models, true ) ) );
		}
		if ( preg_match( "/chat_target = '([^']*)' AND embedding_model = '([^']*)'/", $q, $m ) ) {
			return count( array_filter( $rows, fn( $r ) => $r['chat_target'] === $m[1] && $r['embedding_model'] === $m[2] ) );
		}
		if ( str_starts_with( $q, 'SHOW TABLES' ) ) { return $this->prefix . 'uxstudio_ai_assistant_vectors'; }
		return count( $rows );
	}
	public function update( string $table, array $data, array $where, $f = null, $wf = null ) {
		foreach ( $this->vectors as &$r ) { if ( (int) $r['id'] === (int) $where['id'] ) { $r = array_merge( $r, $data ); } }
		return 1;
	}
	public function add( int $id, string $target, string $model, array $vector, string $text ): void {
		$this->vectors[] = array( 'id' => $id, 'chat_target' => $target, 'source_type' => 'faq', 'source_id' => $id, 'chunk_text' => $text, 'vector' => json_encode( $vector ), 'embedding_model' => $model, 'metadata' => null );
	}
}
$GLOBALS['wpdb'] = new FakeWpdb();

require $root . '/includes/Autoloader.php';
\UxStudio\Autoloader::register();
require $root . '/vendor/ux1-ai-core/autoload.php';

use Ux1\AiCore\Rag\FallbackEmbedding;
use UxStudio\Modules\AiAssistant\KnowledgeBootstrap;
use UxStudio\Modules\AiAssistant\Rag\TrainingSourceManager;
use UxStudio\Modules\AiAssistant\Rag\VectorSearch;
use UxStudio\Modules\AiAssistant\Rag\VectorStore;

$failed = 0;
function check( string $name, bool $ok, string $detail = '' ): void {
	global $failed;
	echo ( $ok ? 'ok   ' : 'FAIL ' ) . $name . ( $ok || '' === $detail ? '' : ' - ' . $detail ) . PHP_EOL;
	if ( ! $ok ) { ++$failed; }
}

$fb    = new FallbackEmbedding();
$model = $fb->get_model_name();
$db    = $GLOBALS['wpdb'];

// ── Model filter in SQL ─────────────────────────────────────────────────
$db->add( 1, 'public', 'tf-idf-256', $fb->embed( 'lázně Luhačovice' ), 'Lázně Luhačovice (old vector)' );
$db->add( 2, 'public', $model, $fb->embed( 'lázně Luhačovice' ), 'Lázně Luhačovice' );
$db->add( 3, 'internal', $model, $fb->embed( 'lázně Luhačovice' ), 'Lázně Luhačovice (internal)' );
$db->add( 4, 'public', 'text-embedding-3-small', array_fill( 0, 1536, 0.1 ), 'OpenAI vector' );

$store = new VectorStore();
$ids   = array();
foreach ( $store->candidate_batches( 'public', $model, null, 200 ) as $batch ) { foreach ( $batch as $row ) { $ids[] = $row['id']; } }
check( 'store: candidate_batches returns only the requested model and target', array( 2 ) === $ids, json_encode( $ids ) );
check( 'store: the SQL filters by embedding_model', (bool) array_filter( $db->sql, fn( $q ) => str_contains( $q, "embedding_model = '{$model}'" ) ) );
check( 'store: has_vectors is per model', $store->has_vectors( 'public', $model ) && ! $store->has_vectors( 'internal', 'tf-idf-256' ) );

// ── Search ignores other models ─────────────────────────────────────────
$hits = ( new VectorSearch( 'public' ) )->search( 'lázně Luhačovice', 10 );
check( 'search: only the current-model vector is returned (no stale, no foreign model, no other target)', array( 2 ) === array_column( $hits, 'id' ), json_encode( array_column( $hits, 'id' ) ) );
check( 'search: query and stored vector of the same text match (cosine ~1)', ! empty( $hits ) && $hits[0]['similarity'] > 0.999 );
check( 'is_available: true when current-model vectors exist', VectorSearch::is_available( 'public' ) );

// ── Legacy-only site: search is unavailable, then re-vectorization repairs it ──
$db->vectors = array();
$db->add( 10, 'public', 'tf-idf-256', array_fill( 0, 256, 0.05 ), 'Pobyt pro seniory' );
$db->add( 11, 'internal', 'tf-idf-256', array_fill( 0, 256, 0.05 ), 'Wellness víkend' );
check( 'legacy-only: is_available is false (callers fall back to the FULLTEXT/knowledge path)', ! VectorSearch::is_available( 'public' ) );

$manager = new TrainingSourceManager();
check( 'legacy: has_legacy_vectors detects them', $manager->has_legacy_vectors() );

KnowledgeBootstrap::maybe_schedule_revectorize();
check( 'upgrade: one cron event is scheduled', 1 === count( $GLOBALS['events'] ) && KnowledgeBootstrap::CRON_REVECTORIZE === $GLOBALS['events'][0][1] );
KnowledgeBootstrap::maybe_schedule_revectorize();
check( 'upgrade: the check runs only once per site', 1 === count( $GLOBALS['events'] ) );

$manager->revectorize_legacy_batch();
$by = array_column( $db->vectors, null, 'id' );
check( 'revectorize: rows get the new model id in place', $model === $by[10]['embedding_model'] && $model === $by[11]['embedding_model'] );
check( 'revectorize: vector equals the new embedding of the chunk text', json_decode( $by[10]['vector'], true ) == $fb->embed( 'Pobyt pro seniory' ) );
check( 'revectorize: chat_target and chunk text are untouched', 'internal' === $by[11]['chat_target'] && 'Wellness víkend' === $by[11]['chunk_text'] );
check( 'revectorize: nothing left, no further cron event', ! $manager->has_legacy_vectors() && 1 === count( $GLOBALS['events'] ) );
check( 'revectorize: search works again', VectorSearch::is_available( 'public' ) && VectorSearch::is_available( 'internal' ) );

// A fresh site without legacy vectors schedules nothing.
$GLOBALS['opts'] = array();
$GLOBALS['events'] = array();
KnowledgeBootstrap::maybe_schedule_revectorize();
check( 'upgrade: no legacy vectors, no cron event', array() === $GLOBALS['events'] && ! empty( $GLOBALS['opts'][ KnowledgeBootstrap::OPTION_REVECTORIZE_CHECKED ] ) );

echo PHP_EOL . ( $failed ? "{$failed} failed" : 'all passed' ) . PHP_EOL;
exit( $failed ? 1 : 0 );
