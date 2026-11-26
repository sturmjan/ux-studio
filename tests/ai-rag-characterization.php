<?php
// Test only: never runs over HTTP (the release zip also excludes tests/).
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { exit; }
/**
 * Characterization test of the RAG pieces that moved to the shared AI core (PLAN.md §45): ChunkingEngine and
 * FallbackEmbedding (now vendor/ux1-ai-core, Ux1\AiCore\Rag). It pinned the plugin behaviour before the extraction;
 * the cases that document a defect fixed on purpose (45.5) were flipped deliberately in the same change: the
 * fallback embedding is now batch-independent (query vector == document vector) and has the new model id.
 *
 *   php tests/ai-rag-characterization.php
 *
 * Needs no WordPress: a stub of wp_strip_all_tags(). Exits non-zero when a case fails.
 *
 * @package UxStudio
 */

// phpcs:disable

define( 'ABSPATH', __DIR__ . '/' );
require dirname( __DIR__ ) . '/vendor/ux1-ai-core/autoload.php';
require dirname( __DIR__ ) . '/includes/Modules/AiAssistant/DocumentParser.php';

use Ux1\AiCore\Rag\ChunkingEngine;
use Ux1\AiCore\Rag\FallbackEmbedding;
use UxStudio\Modules\AiAssistant\DocumentParser;

$failed = 0;
function check( string $name, bool $ok, string $detail = '' ): void {
	global $failed;
	echo ( $ok ? 'ok   ' : 'FAIL ' ) . $name . ( $ok || '' === $detail ? '' : ' - ' . $detail ) . PHP_EOL;
	if ( ! $ok ) { ++$failed; }
}
function cosine( array $a, array $b ): float {
	$dot = 0.0; $na = 0.0; $nb = 0.0;
	foreach ( $a as $i => $v ) { $dot += $v * $b[ $i ]; $na += $v * $v; $nb += $b[ $i ] * $b[ $i ]; }
	return ( $na > 0 && $nb > 0 ) ? $dot / ( sqrt( $na ) * sqrt( $nb ) ) : 0.0;
}

// ── Chunking ────────────────────────────────────────────────────────────
$chunker = new ChunkingEngine();
$short   = 'Lázeňský pobyt v Luhačovicích na pět nocí.';
$chunks  = $chunker->chunk( $short );
check( 'chunk: a short text is one chunk', 1 === count( $chunks ) );
check( 'chunk: the short chunk keeps the text', false !== strpos( (string) ( is_array( $chunks[0] ) ? ( $chunks[0]['text'] ?? $chunks[0]['content'] ?? '' ) : $chunks[0] ), 'Luhačovicích' ) );

$sentence = 'Pobyt pro seniory zahrnuje ubytování, polopenzi a deset procedur v lázeňském domě. ';
$long     = str_repeat( $sentence, 60 ); // ~5000 characters.
$many     = $chunker->chunk( $long );
$texts    = array_map( static fn( $c ) => (string) ( is_array( $c ) ? ( $c['text'] ?? $c['content'] ?? '' ) : $c ), $many );
check( 'chunk: a long text splits into several chunks', count( $texts ) > 1, count( $texts ) . ' chunks' );
$max = max( array_map( 'mb_strlen', $texts ) );
check( 'chunk: no chunk is much longer than the 800 character limit', $max <= 1000, "longest {$max}" );
check( 'chunk: chunks are not empty', ! in_array( '', array_map( 'trim', $texts ), true ) );
check( 'chunk: empty input gives no chunks', array() === $chunker->chunk( '   ' ) );

// ── Fallback embedding (tf-idf, 256 dimensions) ─────────────────────────
$emb = new FallbackEmbedding();
check( 'embed: fallback reports 256 dimensions', 256 === $emb->get_dimensions() );
check( 'embed: fallback has the new model id (old vectors are never mixed in)', 'tf-idf-256-v2' === $emb->get_model_name() && in_array( 'tf-idf-256', FallbackEmbedding::LEGACY_MODELS, true ) );
$v1 = $emb->embed( 'lázně Luhačovice pobyt senioři' );
check( 'embed: vector has 256 numbers', 256 === count( $v1 ) );
$norm = sqrt( array_sum( array_map( static fn( $x ) => $x * $x, $v1 ) ) );
check( 'embed: vector is L2-normalised (or zero)', abs( $norm - 1.0 ) < 0.001 || 0.0 === $norm, "norm {$norm}" );
check( 'embed: same text gives the same vector', $v1 === $emb->embed( 'lázně Luhačovice pobyt senioři' ) );

$related   = $emb->embed( 'pobyt v lázních Luhačovice pro seniory' );
$unrelated = $emb->embed( 'oprava střechy a klempířské práce' );
check( 'embed: a related text is closer than an unrelated one', cosine( $v1, $related ) > cosine( $v1, $unrelated ) );

// "pobyt" is in every text, as common words are in real data, so the batch IDF weights terms unequally.
$batch = $emb->embed_batch( array( 'lázně Luhačovice pobyt senioři', 'pobyt wellness víkend Třeboň', 'pobyt dárkový poukaz' ) );
check( 'embed_batch: one vector per text', 3 === count( $batch ) );
// FIXED (45.5): the batch-dependent IDF is gone, so the query vector (embed) equals the document vector
// (embed_batch) of the same text, whatever else is in the batch.
$fresh = ( new FallbackEmbedding() )->embed( 'lázně Luhačovice pobyt senioři' );
check( 'embed: query embed equals batch embed of the same text', $batch[0] === $fresh, 'cosine ' . round( cosine( $batch[0], $fresh ), 6 ) );

// ── One chunker, two parameter sets ─────────────────────────────────────
$doc = array_map( static fn( $i ) => str_repeat( chr( 97 + $i ), 900 ), range( 0, 2 ) );
check( 'document chunker: 1500 chars, paragraphs kept whole, no overlap', array( $doc[0], $doc[1], $doc[2] ) === DocumentParser::chunk( implode( "

", $doc ) ) );
check( 'document chunker: plain text is not HTML-stripped', array( 'a < b and c > d' ) === DocumentParser::chunk( 'a < b and c > d' ) );
check( 'document chunker: empty input gives no chunks', array() === DocumentParser::chunk( "  
 " ) );

echo PHP_EOL . ( $failed ? "{$failed} failed" : 'all passed' ) . PHP_EOL;
exit( $failed ? 1 : 0 );
