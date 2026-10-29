<?php
// Test only: never runs over HTTP (the release zip also excludes tests/).
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { exit; }
/**
 * Rollback manager version list (0.9.3 hotfix): version_compare() was called with the invalid operator '===' which
 * throws a ValueError on PHP 8 and made GET /rollback-manager/versions return 500. Run on the local pobyty:
 *
 *   cd D:\xampp\htdocs\pobyty
 *   D:/xampp/wp-cli/wp.bat --skip-plugins=wp-mail-smtp eval-file D:\xampp\htdocs\_uxs-wt-csp\tests\rollback-versions-wp.php </dev/null
 *
 * @package UxStudio
 */

defined( 'ABSPATH' ) || exit;

use UxStudio\Modules\RollbackManager\RestController;

$failures = 0;
$assert   = static function ( string $name, bool $ok, string $detail = '' ) use ( &$failures ): void {
	if ( ! $ok ) {
		++$failures;
	}
	WP_CLI::log( ( $ok ? 'PASS ' : 'FAIL ' ) . $name . ( $ok || '' === $detail ? '' : ' => ' . $detail ) );
};

$list = array(
	array( 'version' => '2.0.0', 'download_url' => 'https://downloads.wordpress.org/plugin/x.2.0.0.zip' ),
	array( 'version' => '1.10.0', 'download_url' => 'https://downloads.wordpress.org/plugin/x.1.10.0.zip' ),
	array( 'version' => '1.9.0', 'download_url' => 'https://downloads.wordpress.org/plugin/x.1.9.0.zip' ),
);

try {
	$out = RestController::present_versions( $list, '1.10.0' );
	$assert( 'no ValueError', true );
} catch ( \Throwable $e ) {
	$assert( 'no ValueError', false, $e->getMessage() );
	$out = array();
}

$assert( 'keeps all entries', 3 === count( $out ) );
$assert( 'marks only the installed version', array( false, true, false ) === array_column( $out, 'is_current' ) );
$assert( '1.10.0 is not equal to 1.1.0 (numeric compare)', false === RestController::present_versions( $list, '1.1.0' )[1]['is_current'] );
$assert( 'download_url never leaves the server', ! isset( $out[0]['download_url'] ) && ! isset( $out[2]['download_url'] ) );
$assert( 'unknown current version flags nothing', array( false, false, false ) === array_column( RestController::present_versions( $list, '' ), 'is_current' ) );
$assert( 'empty list', array() === RestController::present_versions( array(), '1.0' ) );

if ( $failures ) {
	WP_CLI::error( "$failures failure(s)" );
}
WP_CLI::success( 'rollback-versions-wp: all passed' );
