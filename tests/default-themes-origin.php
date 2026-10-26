<?php
// Test only: never runs over HTTP (the release zip also excludes tests/).
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { exit; }
/**
 * Standalone test of the origin tracking of default themes (Security module, "Delete unused default themes"): which
 * Twenty* theme is initial / core / manual and which ones may be deleted. Needs no WordPress and no database:
 *
 *   php tests/default-themes-origin.php
 *
 * @package UxStudio
 */

// phpcs:disable

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

require_once dirname( __DIR__ ) . '/includes/Modules/SecurityOptimization/DefaultThemes.php';

use UxStudio\Modules\SecurityOptimization\DefaultThemes as D;

$failures = 0;
$assert   = static function ( string $name, bool $ok, string $detail = '' ) use ( &$failures ): void {
	if ( ! $ok ) {
		++$failures;
	}
	echo ( $ok ? 'PASS ' : 'FAIL ' ) . $name . ( $ok || '' === $detail ? '' : ' => ' . $detail ) . PHP_EOL;
};
$j = static fn( $v ): string => (string) json_encode( $v );

// Slugs.
$assert( 'slug: twentytwentysix and twentyfifteen are default', D::is_default_slug( 'twentytwentysix' ) && D::is_default_slug( 'twentyfifteen' ) );
$assert( 'slug: custom themes and look-alikes are not', ! D::is_default_slug( 'twenty-custom' ) && ! D::is_default_slug( 'mytwentytheme' ) && ! D::is_default_slug( 'astra' ) && ! D::is_default_slug( 'twentytwentyfour-child' ) );

// 1. First run: everything present is initial.
$r1 = D::classify( null, array( 'astra', 'twentytwentyfour', 'twentytwentyfive' ), D::EVENT_SYNC, array(), 100 );
$assert( 'initial: first sync marks every present Twenty* as initial', $j( $r1['origins'] ) === '{"twentytwentyfive":"initial","twentytwentyfour":"initial"}', $j( $r1['origins'] ) );
$assert( 'initial: snapshot holds all themes (also non-default), time stored', $r1['snapshot'] === array( 'astra', 'twentytwentyfour', 'twentytwentyfive' ) && 100 === $r1['time'] );

// 2. Manual install through the theme upgrader (ZIP, wp.org, WP-CLI).
$cur = array( 'astra', 'twentytwentyfour', 'twentytwentyfive', 'twentytwentythree' );
$r2  = D::classify( $r1, $cur, D::EVENT_THEME_INSTALL, array( 'twentytwentythree' ), 200 );
$assert( 'manual: theme upgrader install -> manual, others untouched', $j( $r2['origins'] ) === '{"twentytwentyfive":"initial","twentytwentyfour":"initial","twentytwentythree":"manual"}', $j( $r2['origins'] ) );
$r2b = D::classify( $r1, array( 'astra', 'twentytwentyfour', 'twentytwentyfive' ), D::EVENT_THEME_INSTALL, array( 'twentytwentyfour', 'astra' ), 200 );
$assert( 'manual: re-installing an existing initial theme makes it manual; non-default slugs are ignored', $r2b['origins']['twentytwentyfour'] === 'manual' && ! isset( $r2b['origins']['astra'] ) );
$r2c = D::classify( $r1, array( 'astra', 'twentytwentyfour', 'twentytwentyfive', 'twentytwenty', 'twentynineteen' ), D::EVENT_THEME_INSTALL, array( 'twentytwenty', 'twentynineteen' ), 200 );
$assert( 'manual: bulk install marks all installed slugs', $r2c['origins']['twentytwenty'] === 'manual' && $r2c['origins']['twentynineteen'] === 'manual' );

// 3. Core update brings Twenty Twenty-Six.
$cur3 = array( 'astra', 'twentytwentyfour', 'twentytwentyfive', 'twentytwentysix' );
$r3   = D::classify( $r1, $cur3, D::EVENT_CORE_UPDATE, array(), 300 );
$assert( 'core: a Twenty* absent from the snapshot after a core update -> core', $r3['origins']['twentytwentysix'] === 'core' && $r3['origins']['twentytwentyfour'] === 'initial', $j( $r3['origins'] ) );
$assert( 'core: snapshot refreshed, so a repeated core event is a no-op', D::classify( $r3, $cur3, D::EVENT_CORE_UPDATE, array(), 301 )['origins'] === $r3['origins'] );

// Deletion of the core theme when unused. Fallback = newest (twentytwentysix) stays, so use a case where a newer core theme exists.
$cur3b = array( 'astra', 'twentytwentyfour', 'twentytwentyfive', 'twentytwentysix', 'twentytwentyseven' );
$r3b   = D::classify( $r3, $cur3b, D::EVENT_CORE_UPDATE, array(), 310 );
$del   = D::select_deletions( $r3b['origins'], array( 'twentytwentyfour', 'twentytwentyfive', 'twentytwentysix', 'twentytwentyseven' ), array( 'astra', 'twentytwentyseven' ) );
$assert( 'core: unused core theme is deleted together with unused initial ones; the fallback stays', $j( $del ) === '{"twentytwentyfour":"initial","twentytwentyfive":"initial","twentytwentysix":"core"}', $j( $del ) );

// 4. Manual Twenty* is never deleted.
$del = D::select_deletions( $r2['origins'], array( 'twentytwentyfour', 'twentytwentyfive', 'twentytwentythree' ), array( 'astra' ) );
$assert( 'manual: a manually installed Twenty* is never deleted, the initial ones are', $j( $del ) === '{"twentytwentyfour":"initial","twentytwentyfive":"initial"}', $j( $del ) );
$assert( 'manual: a manual theme survives a later core update and sync', 'manual' === D::classify( $r2, $cur, D::EVENT_CORE_UPDATE, array(), 400 )['origins']['twentytwentythree'] && 'manual' === D::classify( $r2, $cur, D::EVENT_SYNC, array(), 400 )['origins']['twentytwentythree'] );

// 5. Appeared on disk (FTP) without a core update -> manual.
$r5 = D::classify( $r1, array( 'astra', 'twentytwentyfour', 'twentytwentyfive', 'twentytwentytwo' ), D::EVENT_SYNC, array(), 500 );
$assert( 'ftp: a new Twenty* found by the daily sync -> manual', $r5['origins']['twentytwentytwo'] === 'manual' );
$assert( 'ftp: and it is not deleted', ! isset( D::select_deletions( $r5['origins'], array( 'twentytwentytwo' ), array() )['twentytwentytwo'] ) );
// Pre-core sync protects the FTP theme from being taken for a core one.
$pre = D::classify( $r1, array( 'astra', 'twentytwentyfour', 'twentytwentyfive', 'twentytwentytwo' ), D::EVENT_SYNC, array(), 600 );
$post = D::classify( $pre, array( 'astra', 'twentytwentyfour', 'twentytwentyfive', 'twentytwentytwo', 'twentytwentysix' ), D::EVENT_CORE_UPDATE, array(), 601 );
$assert( 'ftp: synced before a core update stays manual, the core theme is core', $post['origins']['twentytwentytwo'] === 'manual' && $post['origins']['twentytwentysix'] === 'core' );

// 6. Safety: keep list, unknown origin, deleted themes.
$del = D::select_deletions( array( 'twentytwentyfour' => 'initial', 'twentytwentyfive' => 'initial', 'twentytwentysix' => 'core' ), array( 'twentytwentyfour', 'twentytwentyfive', 'twentytwentysix', 'twentytwentythree' ), array( 'twentytwentyfour', 'twentytwentysix' ) );
$assert( 'keep: active (twentytwentyfour) and fallback (twentytwentysix) kept; unrecorded twentytwentythree not deleted', $j( $del ) === '{"twentytwentyfive":"initial"}', $j( $del ) );
$assert( 'keep: corrupt origin value is not deletable', array() === D::select_deletions( array( 'twentytwentyfour' => 'bogus' ), array( 'twentytwentyfour' ), array() ) );
$r7 = D::classify( $r1, array( 'astra', 'twentytwentyfive' ), D::EVENT_SYNC, array(), 700 );
$assert( 'gone: a deleted theme leaves the record', $j( $r7['origins'] ) === '{"twentytwentyfive":"initial"}' );
$r8 = D::classify( $r7, array( 'astra', 'twentytwentyfive', 'twentytwentyfour' ), D::EVENT_SYNC, array(), 800 );
$assert( 'gone: the same slug coming back later is treated as new (manual without a core update)', $r8['origins']['twentytwentyfour'] === 'manual' );
$assert( 'robust: garbage record is handled like none', D::classify( array( 'origins' => 'x', 'snapshot' => 'y' ), array( 'twentytwentyfour' ), D::EVENT_SYNC, array(), 1 )['origins']['twentytwentyfour'] === 'initial' );

echo PHP_EOL . ( 0 === $failures ? 'ALL PASSED' : $failures . ' FAILED' ) . PHP_EOL;
exit( 0 === $failures ? 0 : 1 );
