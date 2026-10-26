<?php
// Test only: never runs over HTTP (the release zip also excludes tests/).
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { exit; }
/**
 * WordPress-dependent test of the Forms side of the CSP hash allowance (PLAN.md 20.15): the hook-script hash cache is
 * rebuilt when a form is saved or deleted, only ACTIVE forms count, the printed script equals the hashed text byte
 * for byte, and the shared runtime hash equals the printed runtime.
 *
 *   wp --skip-plugins=wp-mail-smtp eval-file tests/forms-csp-hashes.php
 *
 * Creates two throwaway forms and deletes them again (and restores the cache option).
 *
 * @package UxStudio
 */

defined( 'ABSPATH' ) || exit;

$dir = dirname( __DIR__ ) . '/includes/Modules/';
require_once $dir . 'SecurityOptimization/CspInlineScripts.php';
foreach ( array( 'EmailTemplateRenderer', 'AfterSubmit', 'Fields', 'Templates', 'Revisions', 'Submissions', 'PublicRenderer', 'InlineScripts', 'Module' ) as $class ) {
	require_once $dir . 'Forms/' . $class . '.php';
}

use UxStudio\Modules\Forms\AfterSubmit;
use UxStudio\Modules\Forms\InlineScripts;
use UxStudio\Modules\Forms\Module;
use UxStudio\Modules\Forms\PublicRenderer;
use UxStudio\Modules\SecurityOptimization\CspInlineScripts;

$failures = 0;
$assert   = static function ( string $name, bool $ok, string $detail = '' ) use ( &$failures ): void {
	if ( ! $ok ) {
		++$failures;
	}
	echo ( $ok ? 'PASS ' : 'FAIL ' ) . $name . ( $ok || '' === $detail ? '' : ' => ' . $detail ) . PHP_EOL;
};

$saved_cache = get_option( InlineScripts::OPTION, null );
$saved_user  = get_current_user_id();
wp_set_current_user( 1 ); // unfiltered_html: only such a user may write the custom script.

$module = ( new ReflectionClass( Module::class ) )->newInstanceWithoutConstructor();
$ids    = array();

try {
	/* ── the hook text: one function for printing and hashing ── */
	$code = "console.log('a</script><b>');\nvar x = 1;";
	$body = AfterSubmit::hook_script_body( 7, $code );
	$assert( 'hook: the printed element is exactly <script> + body + </script>', '<script>' . $body . "</script>\n" === AfterSubmit::hook_script( 7, $code ) );
	$assert( 'hook: the closing tag inside the code is escaped', false === strpos( $body, '</script' ) );
	$crlf = AfterSubmit::hook_script_body( 7, "a();\r\nb();\rc();" );
	$assert( 'hook: CRLF and lone CR become LF (the HTML parser does that before the browser hashes the text)', false === strpos( $crlf, "\r" ) && false !== strpos( $crlf, "a();\nb();\nc();" ) );
	$assert( 'runtime: no carriage return in the hashed text', false === strpos( PublicRenderer::runtime_body(), "\r" ) );
	$assert( 'hook: an empty script produces nothing', '' === AfterSubmit::hook_script_body( 7, "  \n" ) && '' === AfterSubmit::hook_script( 7, '' ) );

	/* ── cache rebuild on save / status / delete ── */
	$tracking = array( 'tracking' => array( 'custom_js' => $code ) );
	$active   = $module->create_form( array( 'title' => 'CSP hash test A', 'status' => 'active', 'settings' => $tracking, 'fields' => array() ) );
	$draft    = $module->create_form( array( 'title' => 'CSP hash test B', 'status' => 'draft', 'settings' => $tracking, 'fields' => array() ) );
	$ids      = array( (int) $active['id'], (int) $draft['id'] );

	$assert( 'save: the custom script was stored (the user may write it)', $code === ( $active['settings']['tracking']['custom_js'] ?? '' ) );
	$cache = get_option( InlineScripts::OPTION );
	$assert( 'save: the cache is rebuilt with the active form', isset( $cache['hashes'][ $ids[0] ] ) && UXSTUDIO_VERSION === $cache['version'] );
	$assert( 'save: a draft form is not in the cache', ! isset( $cache['hashes'][ $ids[1] ] ) );
	$assert( 'cache hash == hash of the text the renderer prints', CspInlineScripts::hash( AfterSubmit::hook_script_body( $ids[0], $code ) ) === $cache['hashes'][ $ids[0] ] );

	$module->update_form( $ids[1], array( 'status' => 'active' ) );
	$assert( 'status toggle to active: the cache picks the form up', isset( get_option( InlineScripts::OPTION )['hashes'][ $ids[1] ] ) );

	$module->update_form( $ids[0], array( 'settings' => array( 'tracking' => array( 'custom_js' => 'other();' ) ) ) );
	$assert( 'edit: the hash changes with the script', CspInlineScripts::hash( AfterSubmit::hook_script_body( $ids[0], 'other();' ) ) === get_option( InlineScripts::OPTION )['hashes'][ $ids[0] ] );

	$items = InlineScripts::hook_items();
	$want  = CspInlineScripts::hash( AfterSubmit::hook_script_body( $ids[0], 'other();' ) );
	$assert( 'provider: lists the hook hash with a label', 1 === count( array_filter( $items, static fn( array $i ): bool => $i['hash'] === $want && '' !== $i['label'] ) ) );

	/* ── printed == hashed, through the real renderer ── */
	$form = $module->get_form( $ids[0] );
	$html = ( new PublicRenderer() )->render( $form );
	preg_match_all( '#<script>(.*?)</script>#s', $html, $m );
	$printed_hashes = array_map( array( CspInlineScripts::class, 'hash' ), $m[1] );
	$assert( 'render: the hook script printed on the page has the cached hash', in_array( get_option( InlineScripts::OPTION )['hashes'][ $ids[0] ], $printed_hashes, true ) );
	$assert( 'render: the runtime printed on the page has the registered runtime hash', in_array( CspInlineScripts::hash( PublicRenderer::runtime_body() ), $printed_hashes, true ) );
	$assert( 'render: every executable inline script on the page is hashable (hook + runtime only, config is a JSON block)', 2 === count( $m[1] ), (string) count( $m[1] ) );
	$assert( 'render: the form config is a JSON data block', 1 === preg_match( '#<script type="application/json" data-uxs-form-config="[^"]+">\{.*\}</script>#s', $html ) );
	preg_match( '#data-uxs-form-config="[^"]+">(.*?)</script>#s', $html, $cfg );
	$decoded = json_decode( html_entity_decode( (string) ( $cfg[1] ?? '' ) ), true );
	$assert( 'render: the JSON block decodes and carries the sklik consent mode', is_array( $decoded ) && array_key_exists( 'tracking', $decoded ) );

	/* ── delete ── */
	$module->delete_form( $ids[0] );
	$assert( 'delete: the hash disappears from the cache', ! isset( get_option( InlineScripts::OPTION )['hashes'][ $ids[0] ] ) );

	/* ── stale cache after a plugin update ── */
	update_option( InlineScripts::OPTION, array( 'version' => '0.0.0', 'hashes' => array( 999999 => 'sha256-AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=' ) ), true );
	InlineScripts::hook_items();
	$assert( 'version change: the cache is rebuilt, the stale entry is gone', ! isset( get_option( InlineScripts::OPTION )['hashes'][ 999999 ] ) && UXSTUDIO_VERSION === get_option( InlineScripts::OPTION )['version'] );
} finally {
	foreach ( $ids as $id ) {
		$module->delete_form( (int) $id );
	}
	if ( null === $saved_cache ) {
		delete_option( InlineScripts::OPTION );
	} else {
		update_option( InlineScripts::OPTION, $saved_cache, true );
	}
	wp_set_current_user( $saved_user );
}

echo $failures ? "\n$failures FAILED\n" : "\nALL OK\n";
exit( $failures ? 1 : 0 );
