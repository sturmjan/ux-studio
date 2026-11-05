<?php
// Test only: never runs over HTTP (the release zip also excludes tests/).
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { exit; }
/**
 * TablePress migration source (wizard) against a real WordPress and mock TablePress data: detect, plan, dry run,
 * real run, verify, second run unchanged, the "leave out empty rows" option, the rollback of what a run created and
 * the [table id=N /] alias. The plugin does not have to be active or loaded; the test brings its own autoloader and
 * creates (and removes again) its own posts and options. Needs WordPress:
 *
 *   cd D:\xampp\htdocs\pobyty
 *   php D:\xampp\wp-cli\wp-cli.phar --skip-plugins --skip-themes eval-file D:\xampp\htdocs\<worktree>\tests\migration-tablepress-wp.php
 *
 * @package UxStudio
 */

// phpcs:disable

use UxStudio\Core\Migration\Revert;
use UxStudio\Core\Migration\SourceRegistry;
use UxStudio\Modules\Tables\Module;

$root = dirname( __DIR__ );
if ( ! defined( 'UXSTUDIO_VERSION' ) ) { define( 'UXSTUDIO_VERSION', 'test' ); }
if ( ! defined( 'UXSTUDIO_PATH' ) ) { define( 'UXSTUDIO_PATH', $root . '/' ); }
if ( ! defined( 'UXSTUDIO_URL' ) ) { define( 'UXSTUDIO_URL', 'http://localhost/x/' ); }
require_once $root . '/includes/Autoloader.php';
\UxStudio\Autoloader::register();

$ok = 0; $fail = 0;
$assert = static function ( string $name, bool $cond, string $info = '' ) use ( &$ok, &$fail ): void {
	if ( $cond ) { ++$ok; echo "PASS $name\n"; } else { ++$fail; echo "FAIL $name" . ( '' !== $info ? " [$info]" : '' ) . "\n"; }
};

$had_option = get_option( 'tablepress_tables', null );
if ( null !== $had_option || get_posts( array( 'post_type' => array( 'tablepress_table', 'uxstudio_table' ), 'post_status' => 'any', 'posts_per_page' => 1, 'fields' => 'ids' ) ) ) {
	echo "SKIP: this site already has TablePress or UX Studio table data, the test would clash with it.\n";
	return;
}

$posts = array();
kses_remove_filters(); // TablePress itself saves its JSON unfiltered.
$mk = static function ( string $title, array $cells, array $opts, array $vis ) use ( &$posts ): int {
	$id = wp_insert_post( array( 'post_type' => 'tablepress_table', 'post_status' => 'publish', 'post_title' => $title, 'post_excerpt' => 'note', 'post_content' => wp_slash( wp_json_encode( $cells ) ) ) );
	update_post_meta( $id, '_tablepress_table_options', wp_slash( wp_json_encode( $opts ) ) );
	update_post_meta( $id, '_tablepress_table_visibility', wp_slash( wp_json_encode( $vis ) ) );
	$posts[] = $id;
	return $id;
};
$opts = array( 'table_head' => 1, 'table_foot' => 0, 'alternating_row_colors' => true, 'row_hover' => true, 'print_name' => false, 'use_datatables' => true, 'datatables_sort' => false, 'datatables_filter' => false, 'datatables_paginate' => false, 'datatables_paginate_entries' => 10, 'datatables_scrollx' => true, 'extra_css_classes' => '' );
$p1 = $mk( 'Price list', array( array( 'Service', 'Price' ), array( 'Cut', '100 CZK' ), array( '', '' ), array( 'Color', '[TODO]' ) ), $opts, array( 'rows' => array( 1, 1, 1, 1 ), 'columns' => array( 1, 1 ) ) );
$p2 = $mk( 'Second', array( array( 'A', 'B', 'C' ), array( '<div>x</div>', 'hidden', '=A1+C1' ) ), array_merge( $opts, array( 'print_name' => true, 'datatables_sort' => true, 'datatables_custom_commands' => '"order": []' ) ), array( 'rows' => array( 1, 1 ), 'columns' => array( 1, 0, 1 ) ) );
update_option( 'tablepress_tables', wp_json_encode( array( 'last_id' => 3, 'table_post' => array( '1' => $p1, '2' => $p2, '3' => 999999 ) ) ), false );

$source = static fn() => SourceRegistry::get( 'tablepress' );
$count  = static fn(): int => count( get_posts( array( 'post_type' => 'uxstudio_table', 'post_status' => 'any', 'posts_per_page' => -1, 'fields' => 'ids' ) ) );

/** Whole data phase of one source call sequence. */
$run = static function ( bool $dry, array $options = array() ) use ( $source ): array {
	$src   = $source();
	$state = array( 'run_id' => '', 'options' => $options );
	$out   = $src->migrate( $state, $dry );
	$guard = 0;
	while ( empty( $out['done'] ) && ++$guard < 20 ) {
		$state['cursor'] = $out['cursor'];
		$out             = $src->migrate( $state, $dry );
	}
	return $out;
};

$assert( 'registered for tablepress and targets the tables module', null !== $source() && 'tables' === $source()->target_module() );
$detect = $source()->detect();
$assert( 'detect: has data and the source is known', $detect['has_data'] && 'tables' === $detect['module'] );
$assert( 'detect: summary counts 3 tables (one of them broken)', 3 === (int) ( $detect['summary']['data'][0]['count'] ?? 0 ), json_encode( $detect['summary'] ) );
$assert( 'detect: empty rows reported', 1 === (int) ( $detect['summary']['data'][1]['count'] ?? 0 ), json_encode( $detect['summary'] ) );

$plan = $source()->plan();
$lossy = implode( ' | ', $plan['lossy'] );
$assert( 'plan: HTML, formula, custom commands and damaged table are reported', 4 === count( $plan['lossy'] ) && str_contains( $lossy, 'HTML' ) && str_contains( $lossy, 'formulas' ) && str_contains( $lossy, 'DataTables' ) && str_contains( $lossy, 'damaged' ), $lossy );
$assert( 'plan: asks the empty-rows question, default off', 'drop_empty_rows' === $plan['options'][0]['key'] && false === $plan['options'][0]['default'] );
$assert( 'plan: nothing to transfer as a setting, not staged', 0 === $plan['counts']['transfer'] && ! $plan['staged'] );

$before = $count();
$dry    = $run( true );
$assert( 'dry run: writes nothing', $count() === $before );
$assert( 'dry run: 2 created, 1 skipped', str_contains( (string) $dry['items'][0]['to'], '2 created' ) && str_contains( (string) $dry['items'][0]['to'], '1 skipped' ), json_encode( $dry['items'][0] ?? null ) );

$real = $run( false );
$assert( 'real run: 2 tables created', 2 === $count() - $before, (string) ( $count() - $before ) );
$assert( 'real run: matches the dry run', str_contains( (string) $real['items'][0]['to'], '2 created' ) );

$module = Module::standalone();
$t1     = $module->get_table( $module->find_by_legacy_id( 1 ) ?: 0 );
$t2     = $module->get_table( $module->find_by_legacy_id( 2 ) ?: 0 );
$assert( 'table 1 copied 1:1 incl. the empty row', $t1 && 4 === count( $t1['cells'] ) && 'Price list' === $t1['title'] && 'note' === $t1['description'], json_encode( $t1 ) );
$assert( 'table 1 options', $t1 && $t1['options']['head'] && $t1['options']['striped'] && 'scroll' === $t1['options']['responsive'] );
$assert( 'table 2: hidden column dropped, HTML stripped, caption + sort', $t2 && array( array( 'A', 'C' ), array( 'x', '=A1+C1' ) ) === $t2['cells'] && 'Second' === $t2['options']['caption'] && $t2['options']['sort'], json_encode( $t2 ) );
$assert( 'legacy id 3 (damaged) not created', 0 === $module->find_by_legacy_id( 3 ) );

$verify = $source()->verify();
$bad    = array_filter( $verify, static fn( $c ) => ! $c['ok'] );
$data_check = array_values( array_filter( $verify, static fn( $c ) => 'data:table' === $c['id'] ) );
$assert( 'verify: 2 of 3 found, the damaged table is the only failure', 1 === count( $data_check ) && ! $data_check[0]['ok'] && str_contains( $data_check[0]['detail'], '2 of 3' ) && 2 === count( $bad ), json_encode( $verify ) );

$again = $run( false );
$assert( 'second run: nothing new, same table count', 2 === $count() - $before && str_contains( (string) $again['items'][0]['to'], '2 already there' ) && 0 === (int) $again['written'], json_encode( $again['items'][0] ?? null ) );

// Rollback of exactly what the run created.
$ids = array( $module->find_by_legacy_id( 1 ), $module->find_by_legacy_id( 2 ) );
$assert( 'rollback: Revert::one deletes only uxstudio_table posts', ! Revert::one( 'table_post', (string) $p1 ) && Revert::one( 'table_post', (string) $ids[0] ) && Revert::one( 'table_post', (string) $ids[1] ) && $count() === $before );

// Option: leave out empty rows.
$source()->forget();
$run( false, array( 'drop_empty_rows' => true ) );
$d1 = $module->get_table( $module->find_by_legacy_id( 1 ) ?: 0 );
$assert( 'option drop_empty_rows: the empty row is left out', $d1 && 3 === count( $d1['cells'] ), json_encode( $d1 ) );

// The alias answers once the module is booted and TablePress does not own the shortcode.
$module->boot();
do_action( 'init' );
$assert( 'alias [table id=N /] is registered (TablePress is not loaded here)', shortcode_exists( 'table' ) );
$html = do_shortcode( '[table id="1" /]' );
$assert( 'alias output has the table', str_contains( $html, '<th scope="col">Service</th>' ) && str_contains( $html, 'Color' ), $html );

// Cleanup: everything the test made.
foreach ( get_posts( array( 'post_type' => 'uxstudio_table', 'post_status' => 'any', 'posts_per_page' => -1, 'fields' => 'ids' ) ) as $id ) { wp_delete_post( $id, true ); }
foreach ( $posts as $id ) { wp_delete_post( $id, true ); }
delete_option( 'tablepress_tables' );
delete_option( 'uxstudio_tables' );
$left = get_posts( array( 'post_type' => array( 'tablepress_table', 'uxstudio_table' ), 'post_status' => 'any', 'posts_per_page' => -1, 'fields' => 'ids' ) );
$assert( 'cleanup: no test posts left', array() === $left );

echo $fail ? "\n$fail failure(s)\n" : "\nALL PASSED\n";
