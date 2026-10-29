<?php
// Test only: never runs over HTTP (the release zip also excludes tests/).
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { exit; }
/**
 * Quick search result de-duplication (0.9.3 hotfix: media rows showed twice). Needs no WordPress:
 *
 *   php tests/quick-search-dedupe.php
 *
 * @package UxStudio
 */

// phpcs:disable

define( 'ABSPATH', __DIR__ . '/' );
require dirname( __DIR__ ) . '/includes/Modules/AdminCustomiser/QuickSearch/Integrations.php';

use UxStudio\Modules\AdminCustomiser\QuickSearch\Integrations;

$fail = 0;
function check( string $name, bool $ok, string $detail = '' ): void {
	global $fail;
	if ( ! $ok ) { $fail++; }
	echo ( $ok ? 'PASS ' : 'FAIL ' ) . $name . ( $ok || '' === $detail ? '' : ' => ' . $detail ) . "\n";
}

$a = array( 'object' => 'attachment', 'id' => 10, 'label' => 'IMG_1', 'kind' => 'media' );
$b = array( 'object' => 'attachment', 'id' => 11, 'label' => 'IMG_1', 'kind' => 'media' ); // Same title, another file.
$post10 = array( 'object' => 'post', 'id' => 10, 'label' => 'Post', 'kind' => 'post' );

$out = Integrations::dedupe_rows( array( $a, $b, $a, $post10, $post10 ) );
check( 'same object+ID is kept once', 3 === count( $out ), (string) count( $out ) );
check( 'two attachments with the same title stay separate', 10 === $out[0]['id'] && 11 === $out[1]['id'] );
check( 'same ID of another object type is not a duplicate', 'post' === $out[2]['object'] );

$s1 = array( 'label' => 'Products', 'links' => array( array( 'url' => 'https://x/a' ) ) );
$s2 = array( 'label' => 'Products', 'links' => array( array( 'url' => 'https://x/b' ) ) );
$out = Integrations::dedupe_rows( array( $s1, $s1, $s2 ) );
check( 'rows without ID: label + first URL', 2 === count( $out ), (string) count( $out ) );
check( 'empty input', array() === Integrations::dedupe_rows( array() ) );
check( 'result is a re-indexed list', array( 0, 1 ) === array_keys( $out ) );

echo $fail ? "\n$fail FAILED\n" : "\nAll passed\n";
exit( $fail ? 1 : 0 );
