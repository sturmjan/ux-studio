<?php
// Test only: never runs over HTTP (the release zip also excludes tests/).
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { exit; }
/**
 * Standalone test over every includes/Modules/<id>/meta.json: virtual groups only point at existing modules, no module
 * belongs to two groups, a group is not a child, and each module (e.g. quick-add-post under the Dashboard group) shows up
 * exactly once in the overview (top level tiles + group children). Also: every icon of the SPA's Modules list and of
 * the module metas exists in the launcher's generated icon set. Needs no WordPress:
 *
 *   php tests/module-groups.php
 *
 * @package UxStudio
 */

// phpcs:disable

define( 'ABSPATH', __DIR__ . '/' );
$root = dirname( __DIR__ );

$fail = 0;
function check( string $name, bool $ok, string $detail = '' ): void {
	global $fail;
	if ( ! $ok ) { $fail++; }
	echo ( $ok ? 'PASS ' : 'FAIL ' ) . $name . ( $ok || '' === $detail ? '' : ' => ' . $detail ) . "\n";
}

$meta = array();
foreach ( glob( $root . '/includes/Modules/*/meta.json' ) as $file ) {
	$data = json_decode( (string) file_get_contents( $file ), true );
	check( 'valid json: ' . basename( dirname( $file ) ), is_array( $data ) && isset( $data['id'] ) );
	if ( is_array( $data ) && isset( $data['id'] ) ) {
		check( 'id unique: ' . $data['id'], ! isset( $meta[ $data['id'] ] ) );
		$meta[ $data['id'] ] = $data;
	}
}

$parents = array();
foreach ( $meta as $id => $m ) {
	if ( empty( $m['virtual'] ) ) {
		check( "$id: not virtual, no children", empty( $m['children'] ) );
		continue;
	}
	check( "$id: virtual group has children", ! empty( $m['children'] ) && is_array( $m['children'] ) );
	foreach ( (array) ( $m['children'] ?? array() ) as $child ) {
		check( "$id: child $child exists", isset( $meta[ $child ] ) );
		check( "$id: child $child is not a group", empty( $meta[ $child ]['virtual'] ?? false ) );
		check( "$id: child $child not repeated", ( $parents[ $child ] ?? $id ) === $id && 1 === count( array_keys( $m['children'], $child, true ) ) );
		$parents[ $child ] = $id;
	}
}

// The overview (ModuleGrid) shows the tiles without a parent, each group with its children.
$overview = array();
foreach ( $meta as $id => $m ) {
	if ( isset( $parents[ $id ] ) ) { continue; }
	$overview[] = $id;
	foreach ( (array) ( $m['children'] ?? array() ) as $child ) {
		$overview[] = $child;
	}
}
check( 'overview has no duplicates', count( $overview ) === count( array_unique( $overview ) ) );
check( 'every module is in the overview', array() === array_diff( array_keys( $meta ), $overview ) );
check( 'quick-add-post listed exactly once', 1 === count( array_keys( $overview, 'quick-add-post', true ) ) );
check( 'quick-add-post is a child of the dashboard group', 'dashboard' === ( $parents['quick-add-post'] ?? '' ) );
check( 'dashboard-widgets is a child of the dashboard group', 'dashboard' === ( $parents['dashboard-widgets'] ?? '' ) );
check( 'dashboard group keeps both features in order', array( 'dashboard-widgets', 'quick-add-post' ) === ( $meta['dashboard']['children'] ?? null ) );
check( 'module ids unchanged', isset( $meta['quick-add-post'], $meta['dashboard-widgets'] ) && 'content' === $meta['quick-add-post']['group'] );

// Launcher icons: the same lucide set as the SPA's Modules list.
$icons = require $root . '/includes/Core/launcher-icons.php';
$tsx   = (string) file_get_contents( $root . '/src/app/moduleIcons.tsx' );
$block = substr( $tsx, strpos( $tsx, 'const ICONS' ), strpos( $tsx, '/** Renders a module' ) - strpos( $tsx, 'const ICONS' ) );
preg_match_all( "/^\t'?([a-z0-9-]+)'?:/m", $block, $mm );
check( 'SPA icon map parsed', count( $mm[1] ) > 40 );
foreach ( $mm[1] as $name ) {
	check( "launcher icon for SPA icon $name", isset( $icons[ $name ] ) && str_starts_with( $icons[ $name ], '<' ) );
}
foreach ( array( 'list', 'chevron-right', 'layout-grid', 'puzzle' ) as $name ) {
	check( "launcher chrome icon $name", isset( $icons[ $name ] ) );
}
foreach ( $meta as $id => $m ) {
	$icon = (string) ( $m['icon'] ?? 'puzzle' );
	// Same fallback as the SPA: an icon outside the map is drawn as Puzzle there and here.
	check( "$id: icon '$icon' resolves identically in SPA and launcher", in_array( $icon, $mm[1], true ) === isset( $icons[ $icon ] ) );
}

if ( $fail ) { echo "$fail failure(s)\n"; exit( 1 ); }
echo "All module group checks passed\n";
