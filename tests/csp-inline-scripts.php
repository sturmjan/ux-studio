<?php
// Test only: never runs over HTTP (the release zip also excludes tests/).
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { exit; }
/**
 * Standalone test (no WordPress) of the CSP hash allowance for UX Studio's own inline scripts: hash vector, registry,
 * bound, and that the policy builder adds hashes only to a strict script-src.
 *
 *   php tests/csp-inline-scripts.php
 *
 * @package UxStudio
 */

// phpcs:disable

define( 'ABSPATH', __DIR__ . '/' );

$GLOBALS['uxs_options'] = array();
$GLOBALS['uxs_filters'] = array();

function wp_salt( $scheme = 'auth' ) { return 'test-salt'; }
function apply_filters( $hook, $value ) {
	foreach ( $GLOBALS['uxs_filters'][ $hook ] ?? array() as $fn ) {
		$value = $fn( $value );
	}
	return $value;
}
function get_option( $name, $default = false ) { return $GLOBALS['uxs_options'][ $name ] ?? $default; }
function update_option( $name, $value, $autoload = null ) { $GLOBALS['uxs_options'][ $name ] = $value; return true; }
function __( $text, $domain = '' ) { return $text; }

$dir = dirname( __DIR__ ) . '/includes/Modules/SecurityOptimization/';
require_once $dir . 'CspServices.php';
require_once $dir . 'CspInlineScripts.php';
require_once $dir . 'CspPolicy.php';

use UxStudio\Modules\SecurityOptimization\CspInlineScripts;
use UxStudio\Modules\SecurityOptimization\CspPolicy;

$failures = 0;
$assert   = static function ( string $name, bool $ok, string $detail = '' ) use ( &$failures ): void {
	if ( ! $ok ) {
		++$failures;
	}
	echo ( $ok ? 'PASS ' : 'FAIL ' ) . $name . ( $ok || '' === $detail ? '' : ' => ' . $detail ) . PHP_EOL;
};

$policy = static fn( array $settings = array() ): CspPolicy => new CspPolicy( static fn( string $k, $d = null ) => $settings[ $k ] ?? $d );

/* ── hash computation ── */

$assert( 'hash: known vector sha256("alert(1)")', 'sha256-bhHHL3z2vDgxUt0W3dWQOrprscmda2Y5pLsLg4GF+pI=' === CspInlineScripts::hash( 'alert(1)' ), CspInlineScripts::hash( 'alert(1)' ) );
$assert( 'hash: whitespace is part of the hash', CspInlineScripts::hash( "alert(1)\n" ) !== CspInlineScripts::hash( 'alert(1)' ) );

/* ── registry ── */

CspInlineScripts::reset();
CspInlineScripts::register( 'a', 'Module A', static fn(): array => array( 'alert(1)', array( 'body' => 'x=1', 'label' => 'Named' ), '' ) );
CspInlineScripts::register( 'b', 'Module B', static fn(): array => array( array( 'hash' => 'sha256-bhHHL3z2vDgxUt0W3dWQOrprscmda2Y5pLsLg4GF+pI=' ), array( 'hash' => 'not-a-hash' ) ) );
CspInlineScripts::register( 'broken', 'Broken', static function (): array { throw new RuntimeException( 'boom' ); } );
$c = CspInlineScripts::collect();
$assert( 'registry: bodies and precomputed hashes are collected, duplicates and invalid items dropped, a throwing provider skipped', 2 === $c['total'] && false === $c['capped'], json_encode( $c ) );
$assert( 'registry: sources are quoted', "'sha256-bhHHL3z2vDgxUt0W3dWQOrprscmda2Y5pLsLg4GF+pI='" === CspInlineScripts::sources()[0] );
$assert( 'registry: a custom label survives', 'Named' === $c['entries'][1]['label'] );

CspInlineScripts::reset();
CspInlineScripts::register( 'many', 'Many', static fn(): array => array_map( static fn( int $i ): string => 'var a' . $i . '=1;', range( 1, CspInlineScripts::MAX + 25 ) ) );
$c = CspInlineScripts::collect();
$assert( 'registry: the list is bounded and reports the overflow', CspInlineScripts::MAX === count( $c['entries'] ) && CspInlineScripts::MAX + 25 === $c['total'] && true === $c['capped'] );

/* ── builder: strict vs lenient ── */

CspInlineScripts::reset();
CspInlineScripts::register( 'x', 'X', static fn(): array => array( 'alert(1)' ) );
$h = "'sha256-bhHHL3z2vDgxUt0W3dWQOrprscmda2Y5pLsLg4GF+pI='";

$lenient = $policy()->candidate_policy();
$assert( 'builder: the default policy has unsafe-inline and gets NO hash', str_contains( $lenient, "'unsafe-inline'" ) && ! str_contains( $lenient, 'sha256-' ), $lenient );

$GLOBALS['uxs_filters']['uxstudio_csp_script_src'][] = static fn( array $s ): array => array_values( array_diff( $s, array( "'unsafe-inline'" ) ) );
$strict = $policy( array( 'csp_level' => 'strict' ) )->candidate_directives();
$assert( 'builder: a strict script-src (no unsafe-inline) gets the hash', in_array( $h, $strict['script-src'], true ), json_encode( $strict['script-src'] ) );
$assert( 'builder: strict detection says yes for the narrowed policy', CspPolicy::is_strict_script_policy( $strict ) );
$assert( 'builder: style-src keeps its unsafe-inline and gets no hash', in_array( "'unsafe-inline'", $strict['style-src'], true ) && ! in_array( $h, $strict['style-src'], true ) );
$assert( 'builder: serialized strict policy contains the hash once', 1 === substr_count( $policy( array( 'csp_level' => 'strict' ) )->candidate_policy(), 'sha256-bhHHL3z2vDgxUt0W3dWQOrprscmda2Y5pLsLg4GF+pI=' ) );
$GLOBALS['uxs_filters'] = array();
$assert( 'builder: strict detection says no for the default policy', ! CspPolicy::is_strict_script_policy( $policy()->candidate_directives() ) );

// script-src missing: judged by default-src.
$only_default = CspPolicy::with_inline_hashes( array( 'default-src' => array( "'self'" ) ), array( CspInlineScripts::class, 'sources' ) );
$assert( 'builder: without script-src a strict default-src is copied into script-src with the hash', array( "'self'", $h ) === ( $only_default['script-src'] ?? null ) );
$lenient_default = CspPolicy::with_inline_hashes( array( 'default-src' => array( "'self'", "'unsafe-inline'" ) ), array( CspInlineScripts::class, 'sources' ) );
$assert( 'builder: a lenient default-src is left alone', ! isset( $lenient_default['script-src'] ) );
$assert( 'builder: no policy at all stays empty', array() === CspPolicy::with_inline_hashes( array(), array( CspInlineScripts::class, 'sources' ) ) );

// script-src-elem is judged on its own.
$mixed = CspPolicy::with_inline_hashes(
	array( 'script-src' => array( "'self'", "'unsafe-inline'" ), 'script-src-elem' => array( "'self'" ) ),
	array( CspInlineScripts::class, 'sources' )
);
$assert( 'builder: strict script-src-elem gets the hash while a lenient script-src does not', in_array( $h, $mixed['script-src-elem'], true ) && ! in_array( $h, $mixed['script-src'], true ) );
$lenient_elem = CspPolicy::with_inline_hashes(
	array( 'script-src' => array( "'self'" ), 'script-src-elem' => array( "'self'", "'unsafe-inline'" ) ),
	array( CspInlineScripts::class, 'sources' )
);
$assert( 'builder: lenient script-src-elem stays untouched', ! in_array( $h, $lenient_elem['script-src-elem'], true ) && in_array( $h, $lenient_elem['script-src'], true ) );

// Nothing registered: nothing to add.
CspInlineScripts::reset();
$assert( 'builder: an empty registry changes nothing', array( 'script-src' => array( "'self'" ) ) === CspPolicy::with_inline_hashes( array( 'script-src' => array( "'self'" ) ), array( CspInlineScripts::class, 'sources' ) ) );

// The audit policy is inventory-only and keeps inline code allowed; it never carries hashes.
$assert( 'audit policy: unsafe-inline, no hash', str_contains( $policy()->audit_policy(), "'unsafe-inline'" ) && ! str_contains( $policy()->audit_policy(), 'sha256-' ) );

echo $failures ? "\n$failures FAILED\n" : "\nALL OK\n";
exit( $failures ? 1 : 0 );
