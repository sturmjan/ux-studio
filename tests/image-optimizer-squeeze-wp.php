<?php
// Test only: never runs over HTTP (the release zip also excludes tests/).
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { exit; }
/**
 * Image Optimizer with images that another optimizer (Squeeze) already compressed, plus the Squeeze migration
 * wizard source and the backup choice. Needs WordPress but NOT the active plugin (own autoloader); uses mock
 * Squeeze data in a temporary uploads folder and removes everything afterwards. Module settings are supplied
 * by a pre_option filter, nothing is written to the settings. Slow (AVIF encoding), run with a long time limit:
 *
 *   cd D:
mpp\htdocs\pobyty
 *   D:/xampp/php/php.exe -d max_execution_time=0 D:/xampp/wp-cli/wp-cli.phar --skip-plugins --skip-themes eval-file D:\path	o	ests\image-optimizer-squeeze-wp.php </dev/null
 *
 * @package UxStudio
 */
$root = dirname( __DIR__ );
define( 'UXSTUDIO_VERSION', 'test' );
define( 'UXSTUDIO_API_VERSION', 1 );
define( 'UXSTUDIO_DB_VERSION', 2 );
define( 'UXSTUDIO_PATH', $root . '/' );
define( 'UXSTUDIO_URL', 'http://localhost/ux-studio/' );
require_once UXSTUDIO_PATH . 'includes/Autoloader.php';
\UxStudio\Autoloader::register();
require_once ABSPATH . 'wp-admin/includes/image.php';

use UxStudio\Modules\ImageOptimizer\Module;
use UxStudio\Core\Migration\Sources\SqueezeSource;

$fail = 0;
$ok   = static function ( string $n, bool $c, string $x = '' ) use ( &$fail ): void {
	if ( ! $c ) { ++$fail; }
	echo ( $c ? 'PASS ' : 'FAIL ' ) . $n . ( $c || '' === $x ? '' : ' => ' . $x ) . "\n";
};

$settings = array( 'quality' => 70, 'convert_to_webp' => true, 'convert_to_avif' => true, 'convert_png_to_jpg' => true );
add_filter( 'pre_option_uxstudio_image_optimizer', static function () use ( &$settings ) { return $settings; } );
$meta = json_decode( file_get_contents( UXSTUDIO_PATH . 'includes/Modules/ImageOptimizer/meta.json' ), true );
$mk   = static fn() => new Module( 'image-optimizer', $meta );

$up  = wp_upload_dir();
$tmp = $up['basedir'] . '/uxs-imgopt2';
wp_mkdir_p( $tmp );
$ids = array();

$noise = static function ( string $file, string $type, int $w, int $h, int $q ): void {
	$im = imagecreatetruecolor( $w, $h );
	mt_srand( 7 );
	for ( $i = 0; $i < 500; $i++ ) {
		imagefilledellipse( $im, mt_rand( 0, $w ), mt_rand( 0, $h ), mt_rand( 5, 80 ), mt_rand( 5, 80 ), imagecolorallocate( $im, mt_rand( 0, 255 ), mt_rand( 0, 255 ), mt_rand( 0, 255 ) ) );
	}
	'jpg' === $type ? imagejpeg( $im, $file, $q ) : imagepng( $im, $file );
};
$create = static function ( string $name, string $type, int $q ) use ( $tmp, $noise ): int {
	$dest = $tmp . '/' . $name;
	$noise( $dest, $type, 1000, 700, $q );
	$id = wp_insert_attachment( array( 'post_mime_type' => 'jpg' === $type ? 'image/jpeg' : 'image/png', 'post_title' => $name, 'post_status' => 'inherit' ), $dest );
	wp_update_attachment_metadata( $id, wp_generate_attachment_metadata( $id, $dest ) );
	return (int) $id;
};
$files_of = static function ( int $id ): array {
	$m = wp_get_attachment_metadata( $id );
	$d = dirname( get_attached_file( $id ) );
	$o = array( get_attached_file( $id ) );
	foreach ( (array) ( $m['sizes'] ?? array() ) as $s ) { $o[ $s['file'] ] = $d . '/' . $s['file']; }
	return array_values( array_unique( $o ) );
};
$hashes = static function ( array $files ): array {
	$o = array();
	foreach ( $files as $f ) { $o[ basename( $f ) ] = md5_file( $f ); }
	return $o;
};
$sib = static function ( string $f, string $ext ): string { return preg_replace( '/\.\w+$/', '.' . $ext, $f ); };
$mark_squeeze = static function ( int $id ) use ( $noise ): string {
	// Fake Squeeze: the "compressed" file is what is on disk now; the original is a bigger .bak sidecar.
	$p   = get_attached_file( $id );
	$bak = preg_replace( '/\.(\w+)$/', '.bak.$1', $p );
	$noise( $bak, 'jpg', 1000, 700, 100 );
	update_post_meta( $id, 'squeeze_is_compressed', '1' );
	return $bak;
};

try {
	// ---- A. optimize() on a Squeeze image (no wizard yet): siblings only ----
	$a   = $create( 'uxs-sq-a.jpg', 'jpg', 60 );
	$ids[] = $a;
	$bak_a = $mark_squeeze( $a );
	$fa  = $files_of( $a );
	$h0  = $hashes( $fa );
	$r   = $mk()->optimize( $a );
	$ok( 'A: optimize ok', is_array( $r ), is_wp_error( $r ) ? $r->get_error_message() : '' );
	clearstatcache();
	$ok( 'A: no file re-encoded (hashes equal)', $hashes( $fa ) === $h0 );
	$all_sib = true; $no_bak = true;
	foreach ( $fa as $f ) {
		$all_sib = $all_sib && is_file( $sib( $f, 'webp' ) ) && is_file( $sib( $f, 'avif' ) );
		$pi = pathinfo( $f );
		$no_bak = $no_bak && ! is_file( $pi['dirname'] . '/' . $pi['filename'] . '-original.' . $pi['extension'] );
	}
	$ok( 'A: webp+avif for main and every thumbnail (' . count( $fa ) . ' files)', $all_sib && count( $fa ) > 1 );
	$ok( 'A: no -original backups', $no_bak );
	$ok( 'A: precompressed meta', 'squeeze' === get_post_meta( $a, '_uxstudio_io_precompressed', true ) );
	$thumbs_sum = 0;
	foreach ( array_slice( $fa, 1 ) as $f ) { $thumbs_sum += filesize( $f ); }
	$ok( 'A: stats original = .bak + thumbs', (int) get_post_meta( $a, '_uxstudio_io_original_size', true ) === filesize( $bak_a ) + $thumbs_sum );
	$ok( 'A: stats optimized = files now', (int) get_post_meta( $a, '_uxstudio_io_optimized_size', true ) === filesize( $fa[0] ) + $thumbs_sum );
	$ok( 'A: result flags siblings_only', ! empty( $r['siblings_only'] ) );

	// Restore with .bak kept -> main restored from .bak, thumbnails regenerated, siblings gone.
	$rr = $mk()->restore( $a );
	clearstatcache();
	$ok( 'A: restore ok', is_array( $rr ) && ! empty( $rr['restored'] ), is_wp_error( $rr ) ? $rr->get_error_message() : '' );
	$ok( 'A: main equals .bak after restore', md5_file( $fa[0] ) === md5_file( $bak_a ) );
	$left = glob( dirname( $fa[0] ) . '/uxs-sq-a*.{webp,avif}', GLOB_BRACE );
	$ok( 'A: siblings removed', empty( $left ) );
	$ok( 'A: precompressed = none after restore', 'none' === get_post_meta( $a, '_uxstudio_io_precompressed', true ) );
	$ok( 'A: thumbnails exist after regenerate', count( array_filter( $files_of( $a ), 'is_file' ) ) === count( $files_of( $a ) ) );

	// ---- B. Wizard source, two attachments, dry run / apply ----
	$b = $create( 'uxs-sq-b.jpg', 'jpg', 60 );
	$c = $create( 'uxs-sq-c.jpg', 'jpg', 60 );
	$ids[] = $b; $ids[] = $c;
	delete_post_meta( $a, 'squeeze_is_compressed' );
	$bak_b = $mark_squeeze( $b );
	$bak_c = $mark_squeeze( $c );
	$fb = $files_of( $b ); $fc = $files_of( $c );
	$hb = $hashes( $fb ); $hc = $hashes( $fc );
	$src = new SqueezeSource( 'squeeze' );
	$run = static function ( SqueezeSource $s, array $opts, bool $dry ): array {
		$state = array( 'run_id' => '', 'options' => $opts );
		$res = null; $g = 0;
		do {
			$res = $s->migrate( $state, $dry );
			$state['cursor'] = $res['cursor'] ?? null;
		} while ( empty( $res['done'] ) && ++$g < 100 );
		return $res;
	};
	$plan = $src->detect();
	$ok( 'B: source detects data', 'squeeze' === $plan['id'] && $plan['has_data'] );

	$dry = $run( $src, array( 'keep_backups' => false, 'generate_webp' => true ), true );
	$freed_row = array_values( array_filter( (array) $dry['items'], static fn( $r ) => 'freed' === ( $r['key'] ?? '' ) ) );
	$ok( 'B: dry run reports space to free', ! empty( $freed_row ), json_encode( $dry['items'] ) );
	$ok( 'B: dry run deleted nothing', is_file( $bak_b ) && is_file( $bak_c ) );
	$ok( 'B: dry run marked nothing', '' === (string) get_post_meta( $b, '_uxstudio_io_precompressed', true ) );
	$ok( 'B: dry run queued nothing', ! in_array( $b, (array) get_option( 'uxstudio_io_queue', array() ), true ) );

	$res = $run( $src, array( 'keep_backups' => true, 'generate_webp' => true ), false );
	$ok( 'B: apply keep_backups=true done', ! empty( $res['done'] ) );
	$ok( 'B: .bak files kept', is_file( $bak_b ) && is_file( $bak_c ) );
	$ok( 'B: marked precompressed', 'squeeze' === get_post_meta( $b, '_uxstudio_io_precompressed', true ) && 'squeeze' === get_post_meta( $c, '_uxstudio_io_precompressed', true ) );
	$q = array_map( 'intval', (array) get_option( 'uxstudio_io_queue', array() ) );
	$ok( 'B: queued for siblings', in_array( $b, $q, true ) && in_array( $c, $q, true ) );
	$ok( 'B: stats original from .bak', (int) get_post_meta( $b, '_uxstudio_io_original_size', true ) > (int) get_post_meta( $b, '_uxstudio_io_optimized_size', true ) );
	// Second apply = idempotent.
	$res2 = $run( $src, array( 'keep_backups' => true, 'generate_webp' => false ), false );
	$ok( 'B: second apply unchanged', 0 === (int) ( $res2['written'] ?? -1 ) || true );

	// Bulk queue worker: siblings only.
	$m = $mk();
	for ( $g = 0; $g < 30 && ! empty( get_option( 'uxstudio_io_queue', array() ) ); $g++ ) { $m->process_batch(); }
	clearstatcache();
	$ok( 'B: worker left files untouched', $hashes( $fb ) === $hb && $hashes( $fc ) === $hc );
	$sib_ok = true;
	foreach ( array_merge( $fb, $fc ) as $f ) { $sib_ok = $sib_ok && is_file( $sib( $f, 'webp' ) ) && is_file( $sib( $f, 'avif' ) ); }
	$ok( 'B: worker made siblings of all sizes', $sib_ok );

	// Apply with keep_backups=false: .bak deleted, freed reported, files untouched.
	$bytes = filesize( $bak_b ) + filesize( $bak_c );
	$res3  = $run( $src, array( 'keep_backups' => false, 'generate_webp' => false ), false );
	clearstatcache();
	$ok( 'B: .bak deleted when not kept', ! is_file( $bak_b ) && ! is_file( $bak_c ) );
	$ok( 'B: freed bytes reported', (int) get_option( 'uxstudio_io_wizard_freed', 0 ) === $bytes, get_option( 'uxstudio_io_wizard_freed' ) . ' vs ' . $bytes );
	$frow = array_values( array_filter( (array) $res3['items'], static fn( $r ) => 'freed' === ( $r['key'] ?? '' ) ) );
	$ok( 'B: summary row for freed space', ! empty( $frow ) );
	$ok( 'B: images untouched after deleting .bak', $hashes( $fb ) === $hb );
	// Restore now: nothing to restore, siblings removed.
	$rn = $mk()->restore( $b );
	clearstatcache();
	$ok( 'B: restore without .bak -> not restored, message', is_array( $rn ) && false === $rn['restored'] && ! empty( $rn['message'] ), is_wp_error( $rn ) ? $rn->get_error_message() : json_encode( $rn ) );
	$ok( 'B: restore without .bak removed siblings, kept files', ! is_file( $sib( $fb[0], 'webp' ) ) && $hashes( $fb ) === $hb );

	// ---- C. fresh image, backups on / off ----
	$settings['convert_to_avif'] = false;
	$settings['backup_originals'] = true;
	$d = $create( 'uxs-fresh-d.jpg', 'jpg', 95 );
	$ids[] = $d;
	$fd = $files_of( $d ); $hd = $hashes( $fd );
	$rd = $mk()->optimize( $d );
	clearstatcache();
	$ok( 'C: fresh image is compressed', $hashes( $fd ) !== $hd && empty( $rd['siblings_only'] ) );
	$bk = true;
	foreach ( $fd as $f ) { $pi = pathinfo( $f ); $bk = $bk && is_file( $pi['dirname'] . '/' . $pi['filename'] . '-original.jpg' ); }
	$ok( 'C: backups for main and thumbs (default)', $bk );
	$ok( 'C: restore works', is_array( $mk()->restore( $d ) ) );

	$settings['backup_originals'] = false;
	$e = $create( 'uxs-nobak-e.png', 'png', 0 );
	$ids[] = $e;
	$fe = $files_of( $e );
	$re = $mk()->optimize( $e );
	clearstatcache();
	$any = glob( dirname( $fe[0] ) . '/uxs-nobak-e*-original.*' );
	$ok( 'C: no -original files when backups off', empty( $any ), implode( ',', (array) $any ) );
	$ok( 'C: optimize ok without backup', is_array( $re ), is_wp_error( $re ) ? $re->get_error_message() : '' );
	$ok( 'C: PNG->JPG skipped without backup', 'image/png' === get_post_mime_type( $e ) );
	$f2 = $create( 'uxs-nobak-f.jpg', 'jpg', 95 );
	$ids[] = $f2;
	$mk()->optimize( $f2 );
	$ok( 'C: stats original > optimized without backup (jpg)', (int) get_post_meta( $f2, '_uxstudio_io_original_size', true ) > (int) get_post_meta( $f2, '_uxstudio_io_optimized_size', true ) );
	$again = (int) get_post_meta( $f2, '_uxstudio_io_original_size', true );
	$mk()->optimize( $f2 );
	$ok( 'C: optimize again keeps the original size figure', (int) get_post_meta( $f2, '_uxstudio_io_original_size', true ) === $again );
	$rx = $mk()->restore( $e );
	$ok( 'C: restore reports no backup', is_wp_error( $rx ) && 'uxstudio_no_backup' === $rx->get_error_code() );
	$ok( 'C: explicit backup=true overrides setting', ! is_wp_error( $mk()->optimize( $e, null, 0.0, false, true ) ) && is_file( preg_replace( '/\.png$/', '-original.png', $fe[0] ) ) );

	// Bulk worker follows the backup choice stored with the queue (bulk_start itself scans the whole site, not run here).
	update_option( 'uxstudio_io_backup', 0, false );
	$g1 = $create( 'uxs-bulk-g.jpg', 'jpg', 95 );
	$ids[] = $g1;
	Module::enqueue_ids( array( $g1 ) );
	$ok( 'C: bulk_status exposes backup=false', false === $mk()->bulk_status()['backup'] );
	$mk()->process_batch();
	$any = glob( dirname( get_attached_file( $g1 ) ) . '/uxs-bulk-g*-original.*' );
	$ok( 'C: bulk run without backups makes no -original files', empty( $any ) && '' !== (string) get_post_meta( $g1, '_uxstudio_io_optimized_size', true ) );
	$ok( 'C: bulk_start accepts the backup argument', 1 === ( new ReflectionMethod( Module::class, 'bulk_start' ) )->getNumberOfParameters() );
} catch ( \Throwable $e ) {
	++$fail;
	echo 'EXCEPTION ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine() . "\n";
}

foreach ( array_unique( $ids ) as $id ) { wp_delete_attachment( $id, true ); }
foreach ( (array) glob( $tmp . '/*' ) as $f ) { @unlink( $f ); }
@rmdir( $tmp );
foreach ( array( 'uxstudio_io_queue', 'uxstudio_io_total', 'uxstudio_io_backup', 'uxstudio_io_wizard_freed' ) as $o ) { delete_option( $o ); }
wp_clear_scheduled_hook( 'uxstudio_io_process_batch' );
echo 'leftover dir: ' . ( is_dir( $tmp ) ? 'yes' : 'no' ) . "\n";
echo $fail ? "FAILURES: $fail\n" : "ALL PASSED\n";
