<?php
// Test only: never runs over HTTP (the release zip also excludes tests/).
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { exit; }
/**
 * Standalone test: which admin screens count as the RESULT of an action (a green notice there is feedback and stays on the page).
 *
 *   php tests/notice-result-screen.php
 *
 * @package UxStudio
 */

// phpcs:disable

define( 'ABSPATH', __DIR__ . '/' );
require dirname( __DIR__ ) . '/includes/Modules/AdminCustomiser/HideAdminNotices.php';

use UxStudio\Modules\AdminCustomiser\HideAdminNotices;

$failures = 0;
$assert   = static function ( string $name, bool $ok ) use ( &$failures ): void {
	echo ( $ok ? 'PASS' : 'FAIL' ) . ' ' . $name . PHP_EOL;
	if ( ! $ok ) {
		++$failures;
	}
};

$removable = array( 'updated', 'settings-updated', 'saved', 'activated', 'deleted', 'message' );

$assert( 'a plain dashboard has no result argument', ! HideAdminNotices::has_result_argument( array( 'page' ), $removable ) && ! HideAdminNotices::has_result_argument( array(), $removable ) );
$assert( 'settings-updated, updated, saved: a result screen', HideAdminNotices::has_result_argument( array( 'page', 'settings-updated' ), $removable ) && HideAdminNotices::has_result_argument( array( 'updated' ), $removable ) && HideAdminNotices::has_result_argument( array( 'saved' ), $removable ) );
$assert( 'activate / deactivate / deleted / trashed / message / ids / bulk_edit', HideAdminNotices::has_result_argument( array( 'activate' ) ) && HideAdminNotices::has_result_argument( array( 'deactivate' ) ) && HideAdminNotices::has_result_argument( array( 'deleted' ) ) && HideAdminNotices::has_result_argument( array( 'trashed' ) ) && HideAdminNotices::has_result_argument( array( 'message' ) ) && HideAdminNotices::has_result_argument( array( 'ids' ) ) && HideAdminNotices::has_result_argument( array( 'bulk_edit' ) ) );
$assert( 'what WordPress lists as removable counts even with an unusual name', HideAdminNotices::has_result_argument( array( 'my_plugin_done_flag' ), array( 'my_plugin_done_flag' ) ) );
$assert( 'ordinary screens do not: page, tab, post_type, post, s, paged, orderby', ! HideAdminNotices::has_result_argument( array( 'page', 'tab', 'post_type', 'post', 's', 'paged', 'orderby', 'action' ), $removable ) );

echo PHP_EOL . ( $failures ? "$failures FAILED" : 'ALL PASSED' ) . PHP_EOL;
exit( $failures ? 1 : 0 );
