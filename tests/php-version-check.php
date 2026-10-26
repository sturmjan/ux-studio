<?php
// Test only: never runs over HTTP (the release zip also excludes tests/).
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { exit; }
/**
 * Standalone test of the PHP version check (PLAN.md 29, phase F2): the decision function, the version parsing, the
 * validation of php.net data and the merge (remote may only make the result stricter), the snooze and the e-mail
 * schedule. Needs no WordPress and no database:
 *
 *   php tests/php-version-check.php
 *
 * @package UxStudio
 */

// phpcs:disable

define( 'ABSPATH', __DIR__ . '/' );

$root = dirname( __DIR__ );
define( 'UXSTUDIO_PATH', $root . '/' );
require_once $root . '/includes/Autoloader.php';
\UxStudio\Autoloader::register();

use UxStudio\Modules\SecurityOptimization\PhpVersionCheck as P;

$failures = 0;
$assert   = static function ( string $name, bool $ok, string $detail = '' ) use ( &$failures ): void {
	if ( ! $ok ) {
		++$failures;
	}
	echo ( $ok ? 'PASS ' : 'FAIL ' ) . $name . ( $ok || '' === $detail ? '' : ' => ' . $detail ) . PHP_EOL;
};

$today = '2026-10-25';
$table = P::baseline();
$eval  = static fn( string $v, ?array $t = null, string $d = '2026-10-25' ): array => P::evaluate( $v, $t ?? P::baseline(), $d );
$brief = static fn( array $r ): string => $r['level'] . '/' . $r['reason'];

/* ── The decision ── */

$r = $eval( '8.1.34' );
$assert( '8.1.x is critical, unsupported', 'critical/unsupported' === $brief( $r ), $brief( $r ) );
$assert( '8.1.x advises a version that stays supported', '' !== $r['recommended'] && version_compare( $r['recommended'], '8.3.0', '>=' ), $r['recommended'] );

$r = $eval( '7.4.33' );
$assert( '7.4 is critical, unsupported', 'critical/unsupported' === $brief( $r ), $brief( $r ) );

$r = $eval( '5.6.40' );
$assert( '5.6 is critical, unsupported', 'critical/unsupported' === $brief( $r ), $brief( $r ) );

$r = $eval( '8.2.34' );
$assert( '8.2.34 is critical, outdated', 'critical/outdated' === $brief( $r ), $brief( $r ) );
$assert( '8.2.34 names both CVEs', array( 'CVE-2026-109618', 'CVE-2026-109619' ) === $r['cves'], json_encode( $r['cves'] ) );
$assert( '8.2.34 links the advisory', 'https://www.php.net/archive/2026.php#2026-10-21-5' === $r['advisory_url'] );
$assert( '8.2.34 minimum is 8.2.35', '8.2.35' === $r['minimum'] && '8.2' === $r['branch'] && '2026-12-31' === $r['eol'] );

$r = $eval( '8.2.35' );
$assert( '8.2.35 on 2026-10-25 is a warning (EOL in 67 days)', 'warning/eol_soon' === $brief( $r ) && 67 === $r['days_to_eol'], $brief( $r ) . ' ' . json_encode( $r['days_to_eol'] ) );
$assert( '8.2.35 has no CVEs', array() === $r['cves'] );

$r = $eval( '8.2.35', null, '2026-12-31' );
$assert( 'the last day of support is still a warning', 'warning/eol_soon' === $brief( $r ) && 0 === $r['days_to_eol'], $brief( $r ) );
$r = $eval( '8.2.35', null, '2027-01-01' );
$assert( 'the day after EOL is critical, unsupported', 'critical/unsupported' === $brief( $r ), $brief( $r ) );
$r = $eval( '8.2.35', null, '2026-10-02' );
$assert( 'exactly 90 days before EOL is a warning', 'warning/eol_soon' === $brief( $r ) && 90 === $r['days_to_eol'], $brief( $r ) . ' ' . json_encode( $r['days_to_eol'] ) );
$r = $eval( '8.2.35', null, '2026-10-01' );
$assert( '91 days before EOL is ok', 'ok/' === $brief( $r ), $brief( $r ) );

$r = $eval( '8.3.36' );
$assert( '8.3.36 is ok', 'ok/' === $brief( $r ), $brief( $r ) );
$r = $eval( '8.3.35' );
$assert( '8.3.35 is critical, outdated, with CVEs', 'critical/outdated' === $brief( $r ) && 2 === count( $r['cves'] ), $brief( $r ) );
$r = $eval( '8.4.26' );
$assert( '8.4.26 is critical, outdated', 'critical/outdated' === $brief( $r ) && '8.4.27' === $r['minimum'], $brief( $r ) );
$r = $eval( '8.4.27' );
$assert( '8.4.27 is ok', 'ok/' === $brief( $r ), $brief( $r ) );
$r = $eval( '8.5.11' );
$assert( '8.5.11 is critical, outdated', 'critical/outdated' === $brief( $r ), $brief( $r ) );
$r = $eval( '8.5.12' );
$assert( '8.5.12 is ok', 'ok/' === $brief( $r ), $brief( $r ) );
$r = $eval( '8.6.0' );
$assert( '8.6.0 (newer than the table) is ok', 'ok/' === $brief( $r ), $brief( $r ) );
$r = $eval( '8.6.0RC1' );
$assert( '8.6.0RC1 is ok', 'ok/' === $brief( $r ), $brief( $r ) );
$r = $eval( '9.0.0' );
$assert( '9.0.0 is ok', 'ok/' === $brief( $r ), $brief( $r ) );
$r = $eval( 'garbage' );
$assert( 'an unparsable version is ok with reason unknown (no guessing)', 'ok/unknown' === $brief( $r ), $brief( $r ) );
$r = P::evaluate( '8.4.1', array( 'branches' => array(), 'advisories' => array() ), $today );
$assert( 'an empty table is ok with reason unknown', 'ok/unknown' === $brief( $r ), $brief( $r ) );

/* ── Distro suffixes ── */

$r = $eval( '8.1.2-1ubuntu2.20' );
$assert( 'distro suffix on 8.1: critical, unsupported, distro_build', 'critical/unsupported' === $brief( $r ) && true === $r['distro_build'] && '8.1.2' === $r['current'], json_encode( $r ) );
$r = $eval( '8.4.26-1+deb12u1' );
$assert( 'distro suffix on 8.4.26: outdated, distro_build, current is the numeric prefix', 'critical/outdated' === $brief( $r ) && true === $r['distro_build'] && '8.4.26' === $r['current'], json_encode( $r ) );
$r = $eval( '8.5.12' );
$assert( 'no suffix: distro_build false', false === $r['distro_build'] );
$assert( 'parse() splits the prefix', array( 'version' => '8.1.2', 'branch' => '8.1', 'distro_build' => true ) === P::parse( '8.1.2-1ubuntu2.20' ) );
$assert( 'parse() rejects junk', null === P::parse( 'php' ) && null === P::parse( '8.4' ) );

/* ── php.net data: validation and normalisation ── */

$states = array(
	'8' => array(
		'8.5' => array( 'state' => 'stable', 'security_support_end' => '2029-12-31T00:00:00+00:00' ),
		'8.4' => array( 'state' => 'stable', 'security_support_end' => '2028-12-31T00:00:00+00:00' ),
		'8.1' => array( 'state' => 'eol', 'security_support_end' => '2025-12-31T00:00:00+00:00' ),
	),
);
$rel = array(
	'8.5' => array(
		'8.5.13' => array( 'tags' => array( '' ) ),
		'8.5.12' => array( 'tags' => array( 'security' ) ),
		'8.5.11' => array( 'tags' => array( 'security' ) ),
	),
	'8.4' => array(
		'8.4.28' => array( 'tags' => array( 'security' ) ),
		'8.4.27' => array( 'tags' => array( 'security' ) ),
	),
);
$norm = P::normalize_remote( $states, $rel );
$assert( 'normalize: highest SECURITY release is the minimum (a later non-security one is not)', '8.5.12' === $norm['8.5']['min'] && '8.4.28' === $norm['8.4']['min'], json_encode( $norm ) );
$assert( 'normalize: end of support is cut to the date', '2029-12-31' === $norm['8.5']['eol'] );
$assert( 'normalize: an EOL branch is not taken', ! isset( $norm['8.1'] ) );

$bad = P::normalize_remote(
	array( '8' => array( '8.4' => array( 'state' => 'stable', 'security_support_end' => '2028-12-31T00:00:00+00:00' ), 'x.y' => array( 'state' => 'stable', 'security_support_end' => '2028-12-31' ) ) ),
	array( '8.4' => array( '8.4.x' => array( 'tags' => array( 'security' ) ), '8.4.1000' => array( 'tags' => array( 'security' ) ), '8.3.99' => array( 'tags' => array( 'security' ) ), "8.4.5\n" => array( 'tags' => array( 'security' ) ), '8.4.27-evil' => array( 'tags' => array( 'security' ) ) ) )
);
$assert( 'normalize: invalid versions (letters, huge patch, wrong branch, newline, suffix) are all ignored', isset( $bad['8.4'] ) && null === $bad['8.4']['min'] && 1 === count( $bad ), json_encode( $bad ) );
$assert( 'normalize: garbage input gives nothing', array() === P::normalize_remote( 'oops', array() ) && array() === P::normalize_remote( null, array() ) );
$assert( 'normalize: a bad date drops the branch', array() === P::normalize_remote( array( '8' => array( '8.4' => array( 'state' => 'stable', 'security_support_end' => 'soon' ) ) ), array() ) );

/* ── Merge: remote may only make it stricter ── */

$merged = P::merge( $table, array( '8.4' => array( 'min' => '8.4.28', 'eol' => '2028-12-31' ) ) );
$assert( 'merge: remote RAISES the minimum', '8.4.28' === $merged['branches']['8.4']['min'] );
$r = $eval( '8.4.27', $merged );
$assert( 'merge: 8.4.27 becomes outdated under the raised minimum, without CVEs (no advisory covers it)', 'critical/outdated' === $brief( $r ) && array() === $r['cves'] && '' === $r['advisory_url'], json_encode( $r ) );
$assert( 'merge: the outdated text for a newer release still has a target', '8.4.28' === $r['recommended'] );

$merged = P::merge( $table, array( '8.4' => array( 'min' => '8.4.20', 'eol' => '2030-12-31' ) ) );
$assert( 'merge: remote cannot LOWER the minimum', '8.4.27' === $merged['branches']['8.4']['min'] );
$assert( 'merge: remote cannot move the end of support later', '2028-12-31' === $merged['branches']['8.4']['eol'] );
$r = $eval( '8.4.26', $merged );
$assert( 'merge: a lowering attempt does not hide a vulnerable version', 'critical/outdated' === $brief( $r ), $brief( $r ) );

$merged = P::merge( $table, array( '8.3' => array( 'min' => '8.3.36', 'eol' => '2026-12-31' ) ) );
$assert( 'merge: remote may bring the end of support earlier', '2026-12-31' === $merged['branches']['8.3']['eol'] );

$merged = P::merge( $table, array( '8.4' => array( 'min' => '8.4.abc', 'eol' => 'never' ), '8.5' => array( 'min' => '8.4.99', 'eol' => '2029-12-31' ) ) );
$assert( 'merge: invalid remote values change nothing', $table['branches'] === $merged['branches'], json_encode( $merged['branches'] ) );

$merged = P::merge( $table, array( '8.1' => array( 'min' => '8.1.99', 'eol' => '2030-01-01' ) ) );
$assert( 'merge: a remote record cannot resurrect an old branch', ! isset( $merged['branches']['8.1'] ) );
$r = $eval( '8.1.34', $merged );
$assert( 'merge: 8.1 stays unsupported', 'critical/unsupported' === $brief( $r ) );

$merged = P::merge( $table, array( '8.6' => array( 'min' => null, 'eol' => '2030-12-31' ) ) );
$assert( 'merge: a new branch is added with branch.0 as the minimum', isset( $merged['branches']['8.6'] ) && '8.6.0' === $merged['branches']['8.6']['min'] );
$r = $eval( '8.6.0', $merged );
$assert( 'merge: 8.6.0 is ok in the extended table', 'ok/' === $brief( $r ), $brief( $r ) );
$merged = P::merge( $table, array( '8.6' => array( 'min' => '8.6.2', 'eol' => '2030-12-31' ) ) );
$r      = $eval( '8.6.1', $merged );
$assert( 'merge: 8.6.1 is outdated when remote says 8.6.2', 'critical/outdated' === $brief( $r ), $brief( $r ) );

/* ── Snooze ── */

$now = 1800000000;
$assert( 'snooze: active for the same version', P::snoozed( array( 'until' => $now + 100, 'version' => '8.4.26-1' ), '8.4.26-1', $now ) );
$assert( 'snooze: a different version shows the notice again', ! P::snoozed( array( 'until' => $now + 100, 'version' => '8.4.26-1' ), '8.4.27', $now ) );
$assert( 'snooze: expired', ! P::snoozed( array( 'until' => $now - 1, 'version' => '8.4.26' ), '8.4.26', $now ) );
$assert( 'snooze: nothing stored', ! P::snoozed( false, '8.4.26', $now ) && ! P::snoozed( array(), '8.4.26', $now ) );

/* ── E-mail schedule ── */

$assert( 'mail: first time critical', P::mail_due( array(), 'critical', $now ) && P::mail_due( false, 'critical', $now ) );
$assert( 'mail: not for warning or ok', ! P::mail_due( array(), 'warning', $now ) && ! P::mail_due( array(), 'ok', $now ) );
$assert( 'mail: not again after one day', ! P::mail_due( array( 'last' => $now - 86400 ), 'critical', $now ) );
$assert( 'mail: not after 6 days', ! P::mail_due( array( 'last' => $now - 6 * 86400 ), 'critical', $now ) );
$assert( 'mail: again after 7 days (an hour of cron jitter allowed)', P::mail_due( array( 'last' => $now - 7 * 86400 + 3600 ), 'critical', $now ) );

/* ── Helpers ── */

$assert( 'days_between', 67 === P::days_between( '2026-10-25', '2026-12-31' ) && -1 === P::days_between( '2027-01-01', '2026-12-31' ) && null === P::days_between( 'x', '2026-12-31' ) );
$assert( 'the bundled minimums are valid versions of their branch', (static function (): bool {
	foreach ( P::BASELINE as $branch => $row ) {
		if ( ! P::valid_version( $row['min'], (string) $branch ) ) {
			return false;
		}
	}
	return true;
})() );

/* ── Where the warning shows (PhpVersionNotice::visibility) ── */

use UxStudio\Modules\SecurityOptimization\PhpVersionNotice as N;

$crit = $eval( '8.1.34' );
$warn = $eval( '8.3.36', null, '2027-11-20' );
$okr  = $eval( '8.4.30' );
$assert( 'visibility fixtures: critical / warning / ok', 'critical' === $crit['level'] && 'warning' === $warn['level'] && 'ok' === $okr['level'], $crit['level'] . '/' . $warn['level'] . '/' . $okr['level'] );
$vis = static fn( array $r, bool $can, $snooze, string $dis, string $v, string $ctx ): string => ( ( $x = N::visibility( $r, $can, $snooze, $dis, $v, $now, $ctx ) )['notice'] ? 'N' : '-' ) . ( $x['bar'] ? 'B' : '-' ) . ( $x['dismissible'] ? 'D' : '-' );

$assert( 'visibility: administrator, critical, admin screen -> notice + bar, not dismissible', 'NB-' === $vis( $crit, true, array(), '', '8.1.34', 'admin' ) );
$assert( 'visibility: administrator, critical, front end -> bar only', '-B-' === $vis( $crit, true, array(), '', '8.1.34', 'front' ) );
$assert( 'visibility: not an administrator -> nothing, anywhere', '---' === $vis( $crit, false, array(), '', '8.1.34', 'admin' ) && '---' === $vis( $crit, false, array(), '', '8.1.34', 'front' ) );
$assert( 'visibility: level ok -> nothing', '---' === $vis( $okr, true, array(), '', '8.4.30', 'admin' ) );
$assert( 'visibility: warning -> notice + bar, dismissible', 'NBD' === $vis( $warn, true, array(), '', '8.3.36', 'admin' ) );
$snz = array( 'until' => $now + 100, 'version' => '8.1.34' );
$assert( 'visibility: snooze hides the notice and the bar', '---' === $vis( $crit, true, $snz, '', '8.1.34', 'admin' ) && '---' === $vis( $crit, true, $snz, '', '8.1.34', 'front' ) );
$assert( 'visibility: snooze of another PHP version does not count', 'NB-' === $vis( $crit, true, $snz, '', '8.1.35', 'admin' ) );
$assert( 'visibility: a hidden warning hides the notice only (bar stays)', '-BD' === $vis( $warn, true, array(), N::dismiss_key( $warn ), '8.3.36', 'admin' ) );
$assert( 'visibility: a hidden warning for another end date comes back', 'NBD' === $vis( $warn, true, array(), '8.3|2020-01-01', '8.3.36', 'admin' ) );
$assert( 'visibility: the critical notice cannot be dismissed away', 'NB-' === $vis( $crit, true, array(), N::dismiss_key( $crit ), '8.1.34', 'admin' ) );

echo PHP_EOL . ( 0 === $failures ? 'ALL PASSED' : $failures . ' FAILED' ) . PHP_EOL;
exit( 0 === $failures ? 0 : 1 );
