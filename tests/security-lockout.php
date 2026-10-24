<?php
// Test only: never runs over HTTP (the release zip also excludes tests/).
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { exit; }
/**
 * Standalone test of the progressive lockout rule (PLAN.md 27, A1): the lockout time grows with every repeated
 * lockout of the same IP within 24 hours, never below the base time and never above the maximum. Needs no
 * WordPress and no database:
 *
 *   php tests/security-lockout.php
 *
 * @package UxStudio
 */

// phpcs:disable

define( 'ABSPATH', __DIR__ . '/' );
define( 'UXSTUDIO_PATH', dirname( __DIR__ ) . '/' );

require_once dirname( __DIR__ ) . '/includes/Autoloader.php';
\UxStudio\Autoloader::register();

use UxStudio\Modules\SecurityOptimization\AttemptsHandler as H;

$failures = 0;
$assert   = static function ( string $name, bool $ok, string $detail = '' ) use ( &$failures ): void {
	if ( ! $ok ) {
		++$failures;
	}
	echo ( $ok ? 'PASS ' : 'FAIL ' ) . $name . ( $ok || '' === $detail ? '' : ' => ' . $detail ) . PHP_EOL;
};

$seq = static function ( int $base, float $mult, int $max, int $n ): array {
	$out = array();
	for ( $i = 0; $i < $n; $i++ ) {
		$out[] = H::progressive_minutes( $base, $i, $mult, $max );
	}
	return $out;
};

$assert( 'x2 from 30 min, capped at 24 h', array( 30, 60, 120, 240, 480, 960, 1440, 1440 ) === $seq( 30, 2, 1440, 8 ), implode( ',', $seq( 30, 2, 1440, 8 ) ) );
$assert( 'x1 = no growth (the default behaviour)', array( 30, 30, 30, 30 ) === $seq( 30, 1, 1440, 4 ) );
$assert( 'x1.5 rounds to whole minutes', array( 10, 15, 23, 34 ) === $seq( 10, 1.5, 1440, 4 ), implode( ',', $seq( 10, 1.5, 1440, 4 ) ) );
$assert( 'a multiplier below 1 never shortens the lock', array( 30, 30, 30 ) === $seq( 30, 0.5, 1440, 3 ) );
$assert( 'a negative count of earlier locks is treated as none', 30 === H::progressive_minutes( 30, -3, 2.0, 1440 ) );
$assert( 'a maximum below the base time cannot make the lock shorter than the base', 30 === H::progressive_minutes( 30, 5, 2.0, 10 ) );
$assert( 'a huge count does not overflow, the cap holds', 2880 === H::progressive_minutes( 30, 5000, 2.0, 2880 ) );
$assert( 'a base of 0 is treated as 1 minute', 1 === H::progressive_minutes( 0, 0, 2.0, 60 ) && 4 === H::progressive_minutes( 0, 2, 2.0, 60 ) );
$assert( 'a week cap works', 10080 === H::progressive_minutes( 60, 20, 3.0, 168 * 60 ) );

echo PHP_EOL . ( $failures ? "$failures FAILED" : 'ALL PASSED' ) . PHP_EOL;
exit( $failures ? 1 : 0 );
