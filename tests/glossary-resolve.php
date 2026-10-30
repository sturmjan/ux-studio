<?php
/**
 * Test only: `php tests/glossary-resolve.php`
 * Every `[[term]]` or `[[term|shown]]` marker used in a help.php or in the TSX sources of the app must resolve to an
 * entry of Core\Glossary (after normalisation and aliases). Standalone: ABSPATH, __() and apply_filters() are stubbed.
 */
define( 'ABSPATH', __DIR__ . '/' );
if ( ! function_exists( '__' ) ) {
	function __( $s ) { return $s; }
}
if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( $tag, $value ) { return $value; }
}
require dirname( __DIR__ ) . '/includes/Core/Glossary.php';

use UxStudio\Core\Glossary;

$root  = dirname( __DIR__ );
$files = array();
$it    = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ) );
foreach ( $it as $f ) {
	$p = str_replace( chr( 92 ), '/', $f->getPathname() );
	if ( preg_match( '#/(node_modules|vendor|build|languages|tests|\.git|docs)/#', $p ) ) {
		continue;
	}
	if ( preg_match( '#/help\.php$#', $p ) || preg_match( '#/src/.*\.tsx?$#', $p ) || preg_match( '#/includes/.*\.php$#', $p ) ) {
		$files[] = $p;
	}
}
$bad   = 0;
$count = 0;
foreach ( $files as $p ) {
	$src = (string) file_get_contents( $p );
	if ( ! preg_match_all( '/(?<![\$\w\]\)])\[\[([^\[\]|\n]{1,60})(?:\|[^\[\]\n]*)?\]\]/u', $src, $m, PREG_OFFSET_CAPTURE ) ) {
		continue;
	}
	foreach ( $m[1] as $hit ) {
		++$count;
		if ( 'term' !== $hit[0] && null === Glossary::get( $hit[0] ) ) {
			++$bad;
			$line = substr_count( substr( $src, 0, $hit[1] ), "\n" ) + 1;
			echo 'FAIL unresolved [[' . $hit[0] . ']] in ' . substr( $p, strlen( $root ) + 1 ) . ':' . $line . "\n";
		}
	}
}
echo "markers checked: $count\n";
echo $bad ? "$bad FAILED\n" : "ALL PASSED\n";
exit( $bad ? 1 : 0 );
