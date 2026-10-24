<?php
/**
 * Standalone test of the two-step verification (PLAN.md 27, A1): the TOTP algorithm against the RFC 6238
 * Appendix B vectors, the window, recovery codes (hashed, one time), replay guard, the rate limit of code
 * attempts, the pending-login token, the kill switch and the grace logic of a role that requires 2FA.
 * Needs no WordPress and no database:
 *
 *   php tests/security-2fa.php
 *
 * @package UxStudio
 */

// phpcs:disable

define( 'ABSPATH', __DIR__ . '/' );
define( 'UXSTUDIO_PATH', dirname( __DIR__ ) . '/' );

$GLOBALS['uxs_meta'] = array();

function get_user_meta( $uid, $key, $single = false ) { return $GLOBALS['uxs_meta'][ $uid ][ $key ] ?? ''; }
function update_user_meta( $uid, $key, $value ) { $GLOBALS['uxs_meta'][ $uid ][ $key ] = $value; return true; }
function delete_user_meta( $uid, $key ) { unset( $GLOBALS['uxs_meta'][ $uid ][ $key ] ); return true; }
function wp_salt( $scheme = 'auth' ) { return 'test-salt-' . $scheme; }

require_once dirname( __DIR__ ) . '/includes/Autoloader.php';
\UxStudio\Autoloader::register();

use UxStudio\Core\Totp;
use UxStudio\Modules\SecurityOptimization\TwoFactorStore as Store;

$failures = 0;
$assert   = static function ( string $name, bool $ok, string $detail = '' ) use ( &$failures ): void {
	if ( ! $ok ) {
		++$failures;
	}
	echo ( $ok ? 'PASS ' : 'FAIL ' ) . $name . ( $ok || '' === $detail ? '' : ' => ' . $detail ) . PHP_EOL;
};

/* ── RFC 6238 Appendix B (SHA1, secret "12345678901234567890") ── */

$secret = Totp::base32_encode( '12345678901234567890' );
$assert( 'base32 of the RFC secret', 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ' === $secret, $secret );
$assert( 'base32 round trip', '12345678901234567890' === Totp::base32_decode( $secret ) );
$assert( 'base32 ignores spaces, case and padding', '12345678901234567890' === Totp::base32_decode( strtolower( 'GEZD GNBV GY3T QOJQ GEZD GNBV GY3T QOJQ====' ) ) );

foreach ( array(
	59          => '94287082',
	1111111109  => '07081804',
	1111111111  => '14050471',
	1234567890  => '89005924',
	2000000000  => '69279037',
	20000000000 => '65353130',
) as $time => $expected ) {
	$assert( "RFC 6238 vector T=$time (8 digits)", Totp::code( $secret, $time, 8 ) === $expected, Totp::code( $secret, $time, 8 ) );
	$assert( "6-digit code T=$time is the last 6 digits", Totp::code( $secret, $time ) === substr( $expected, -6 ) );
}

/* ── Window and format ── */

$t    = 1111111109;
$code = Totp::code( $secret, $t );
$step = intdiv( $t, 30 );
$assert( 'verify: same step', Totp::verify( $secret, $code, 1, $t ) === $step );
$assert( 'verify: one step later is accepted (window 1)', Totp::verify( $secret, $code, 1, $t + 30 ) === $step );
$assert( 'verify: one step earlier is accepted', Totp::verify( $secret, $code, 1, $t - 30 ) === $step );
$assert( 'verify: two steps later is refused', null === Totp::verify( $secret, $code, 1, $t + 60 ) );
$assert( 'verify: two steps earlier is refused', null === Totp::verify( $secret, $code, 1, $t - 60 ) );
$assert( 'verify: window 0 refuses the neighbour step', null === Totp::verify( $secret, $code, 0, $t + 30 ) );
$assert( 'verify: spaces in the typed code are ignored', Totp::verify( $secret, substr( $code, 0, 3 ) . ' ' . substr( $code, 3 ), 1, $t ) === $step );
$assert( 'verify: letters and wrong length refused', null === Totp::verify( $secret, 'abcdef', 1, $t ) && null === Totp::verify( $secret, '12345', 1, $t ) && null === Totp::verify( $secret, '1234567', 1, $t ) );
$assert( 'verify: a wrong code is refused', null === Totp::verify( $secret, '000000' === $code ? '111111' : '000000', 1, $t ) );
$generated = Totp::generate_secret();
$assert( 'generated secret: 32 base32 characters (160 bit)', 1 === preg_match( '/^[A-Z2-7]{32}$/', $generated ), $generated );
$assert( 'generated secrets differ', $generated !== Totp::generate_secret() );
$assert( 'provisioning URI', 'otpauth://totp/My%20Site:jan?secret=ABC&issuer=My%20Site&algorithm=SHA1&digits=6&period=30' === Totp::provisioning_uri( 'ABC', 'jan', 'My Site' ) );

/* ── Store: setup ── */

$now = 1800000000;
Store::set_clock( static function () use ( &$now ) { return $now; } );
$uid = 7;

$assert( 'new user: no second factor', ! Store::is_enabled( $uid ) );
$pending = Store::begin_setup( $uid );
$assert( 'pending secret is kept sealed (not plain text in user meta)', str_starts_with( (string) get_user_meta( $uid, 'uxstudio_2fa_pending', true ), 'uxs1:' ) && $pending === Store::pending_secret( $uid ) );
$assert( 'setup is not on before the first code', ! Store::is_enabled( $uid ) );
$assert( 'wrong code does not confirm the setup', null === Store::confirm_setup( $uid, '000000' === Totp::code( $pending, $now ) ? '111111' : '000000' ) );
$assert( 'the wrong code counted as a failure', 1 === ( get_user_meta( $uid, 'uxstudio_2fa_fail', true )['count'] ?? 0 ) );
$codes = Store::confirm_setup( $uid, Totp::code( $pending, $now ) );
$assert( 'right code confirms the setup and returns recovery codes', is_array( $codes ) && Store::RECOVERY_COUNT === count( $codes ) );
$assert( 'second factor is on, secret sealed', Store::is_enabled( $uid ) && str_starts_with( (string) get_user_meta( $uid, 'uxstudio_2fa_secret', true ), 'uxs1:' ) && Store::secret( $uid ) === $pending );
$assert( 'pending secret and failures cleared after confirm', '' === Store::pending_secret( $uid ) && '' === get_user_meta( $uid, 'uxstudio_2fa_fail', true ) );

/* ── Recovery codes ── */

$assert( 'recovery codes look like XXXXX-XXXXX', 10 === count( array_filter( $codes, static fn( $c ) => 1 === preg_match( '/^[A-HJKMNP-Z2-9]{5}-[A-HJKMNP-Z2-9]{5}$/', $c ) ) ) );
$assert( 'recovery codes are unique', count( array_unique( $codes ) ) === count( $codes ) );
$stored = (array) get_user_meta( $uid, 'uxstudio_2fa_recovery', true );
$leak   = false;
foreach ( $codes as $c ) {
	foreach ( $stored as $h ) {
		if ( false !== stripos( (string) $h, str_replace( '-', '', $c ) ) || $h === $c ) {
			$leak = true;
		}
	}
}
$assert( 'only hashes are stored (64 hex), never the codes', 10 === count( $stored ) && ! $leak && 10 === count( array_filter( $stored, static fn( $h ) => 1 === preg_match( '/^[0-9a-f]{64}$/', (string) $h ) ) ) );

$now += 1000; // Past the earlier failure window.
$r = Store::attempt( $uid, $codes[0] );
$assert( 'recovery code logs in once', $r['ok'] && 'recovery' === $r['method'] && 9 === Store::recovery_left( $uid ) );
$r = Store::attempt( $uid, $codes[0] );
$assert( 'the same recovery code is refused the second time', ! $r['ok'] && 9 === Store::recovery_left( $uid ) );
$r = Store::attempt( $uid, strtolower( str_replace( '-', '', $codes[1] ) ) );
$assert( 'recovery code works lower case and without the dash', $r['ok'] && 'recovery' === $r['method'] && 8 === Store::recovery_left( $uid ) );
$assert( 'regenerating invalidates the old codes', (function () use ( $uid, $codes ) {
	$new = Store::generate_recovery_codes( $uid );
	return 10 === count( $new ) && 10 === Store::recovery_left( $uid ) && ! Store::attempt( $uid, $codes[2] )['ok'];
})() );
$assert( 'a recovery code of another user does not work', (function () use ( $codes ) {
	$other = 8;
	update_user_meta( $other, 'uxstudio_2fa_enabled', '1' );
	update_user_meta( $other, 'uxstudio_2fa_secret', \UxStudio\Core\Security::seal( Totp::generate_secret() ) );
	Store::generate_recovery_codes( $other );
	return ! Store::attempt( $other, $codes[3] )['ok'];
})() );

/* ── Replay guard and the app code ── */

$now = 1800100000;
Store::clear_failures( $uid );
$good = Totp::code( $pending, $now );
$r    = Store::attempt( $uid, $good );
$assert( 'app code accepted', $r['ok'] && 'totp' === $r['method'] );
$r = Store::attempt( $uid, $good );
$assert( 'the same code in the same step is refused (replay)', ! $r['ok'] );
Store::clear_failures( $uid );
$now += 30;
$r = Store::attempt( $uid, Totp::code( $pending, $now ) );
$assert( 'the next step code is accepted', $r['ok'] );
Store::clear_failures( $uid );
$now += 30;
$old = Totp::code( $pending, $now - 60 ); // Two steps old: outside the window.
$assert( 'a code two steps old is refused', ! Store::attempt( $uid, $old )['ok'] );
Store::clear_failures( $uid );

/* ── Rate limit: 5 wrong codes per 10 minutes ── */

$now = 1800200000;
$bad = '000000';
for ( $i = 1; $i <= 4; $i++ ) {
	$r = Store::attempt( $uid, $bad );
}
$assert( 'after 4 wrong codes the account is not locked yet', ! $r['locked'] && 1 === $r['remaining'] && 0 === Store::lock_remaining( $uid ) );
$r = Store::attempt( $uid, $bad );
$assert( 'the 5th wrong code locks the account (just_locked reported once)', $r['locked'] && $r['just_locked'] && Store::lock_remaining( $uid ) > 0 );
$r = Store::attempt( $uid, $bad );
$assert( 'a 6th try is not evaluated and does not report a new lockout', $r['locked'] && ! $r['just_locked'] );
$r = Store::attempt( $uid, Totp::code( $pending, $now ) );
$assert( 'a CORRECT code is refused while locked', ! $r['ok'] && $r['locked'] && $r['retry_in'] > 0 && $r['retry_in'] <= Store::WINDOW );
$now += Store::WINDOW - 1;
$assert( 'still locked one second before the window ends', Store::lock_remaining( $uid ) > 0 );
$now += 2;
$assert( 'unlocked when the window ends', 0 === Store::lock_remaining( $uid ) );
$r = Store::attempt( $uid, Totp::code( $pending, $now ) );
$assert( 'correct code works after the lockout', $r['ok'] );
$assert( 'failures cleared by the good code', '' === get_user_meta( $uid, 'uxstudio_2fa_fail', true ) );

$now = 1800300000;
for ( $i = 0; $i < 4; $i++ ) {
	Store::attempt( $uid, $bad );
}
$now += Store::WINDOW + 1;
$r = Store::attempt( $uid, $bad );
$assert( 'old failures do not add up: the window starts over', 4 === $r['remaining'] && ! $r['locked'] );
Store::clear_failures( $uid );

/* ── Pending login token ── */

$now   = 1800400000;
$token = Store::issue_login_token( $uid );
$assert( 'login token: 32 hex characters, only its hash is stored', 1 === preg_match( '/^[0-9a-f]{32}$/', $token ) && $token !== ( get_user_meta( $uid, 'uxstudio_2fa_login', true )['hash'] ?? '' ) );
$assert( 'login token valid', Store::check_login_token( $uid, $token ) );
$assert( 'login token stays valid for retries', Store::check_login_token( $uid, $token ) );
$assert( 'wrong login token refused', ! Store::check_login_token( $uid, str_repeat( 'a', 32 ) ) && ! Store::check_login_token( $uid, '' ) );
$assert( 'login token of another user refused', ! Store::check_login_token( 99, $token ) );
$now += Store::LOGIN_TTL + 1;
$assert( 'login token expires', ! Store::check_login_token( $uid, $token ) );
$token = Store::issue_login_token( $uid );
Store::clear_login_token( $uid );
$assert( 'login token cleared after the login', ! Store::check_login_token( $uid, $token ) );

/* ── Disable removes everything ── */

Store::disable( $uid );
$assert( 'disable removes the factor, codes and counters', ! Store::is_enabled( $uid ) && 0 === Store::recovery_left( $uid ) && '' === Store::secret( $uid ) && ! isset( $GLOBALS['uxs_meta'][ $uid ]['uxstudio_2fa_counter'] ) );

/* ── Role requirement and the grace logins ── */

$u      = 21;
$req    = array( 'administrator', 'editor' );
$assert( 'role_requires: matching role', Store::role_requires( array( 'editor' ), $req ) && ! Store::role_requires( array( 'subscriber' ), $req ) && ! Store::role_requires( array( 'editor' ), array() ) );
$assert( 'user whose role is not required: nothing', 'none' === Store::login_gate( $u, array( 'subscriber' ), $req, 3 ) );
$states = array();
for ( $i = 1; $i <= 5; $i++ ) {
	$states[] = Store::login_gate( $u, array( 'editor' ), $req, 3 );
}
$assert( 'three logins are graced, the fourth is forced', array( 'grace', 'grace', 'grace', 'forced', 'forced' ) === $states, implode( ',', $states ) );
$assert( 'forced state is remembered', Store::is_forced( $u ) && 0 === Store::grace_left( $u, 3 ) );
$assert( 'zero grace means forced from the first login', 'forced' === Store::login_gate( 22, array( 'administrator' ), $req, 0 ) );
// The user sets the factor up.
$sec = Store::begin_setup( $u );
$now = 1800500000;
Store::confirm_setup( $u, Totp::code( $sec, $now ) );
$assert( 'setting the factor up ends the forced state', Store::is_enabled( $u ) && ! Store::is_forced( $u ) && 'none' === Store::login_gate( $u, array( 'editor' ), $req, 3 ) );
$assert( 'the requirement being lifted clears the forced flag', (function () use ( $req ) {
	Store::login_gate( 23, array( 'editor' ), $req, 0 );
	$was = Store::is_forced( 23 );
	Store::login_gate( 23, array( 'editor' ), array(), 0 );
	return $was && ! Store::is_forced( 23 );
})() );
Store::disable( $u );
$assert( 'switching the factor off again does not give a fresh grace (the used logins stay used)', 0 === Store::grace_left( $u, 3 ) );

/* ── Unreadable secret fails closed (finding 3) ── */

$now = 1800600000;
$bad_user = 30;
$sec2     = Store::begin_setup( $bad_user );
$codes2   = Store::confirm_setup( $bad_user, Totp::code( $sec2, $now ) );
$assert( 'prepared user with a working factor', is_array( $codes2 ) && Store::is_enabled( $bad_user ) && ! Store::secret_unreadable( $bad_user ) );
update_user_meta( $bad_user, 'uxstudio_2fa_secret', 'uxs1:' . base64_encode( str_repeat( 'x', 40 ) ) ); // Sealed with another salt.
$assert( 'secret unreadable: is_enabled STAYS true (no silent password-only login)', Store::is_enabled( $bad_user ) && '' === Store::secret( $bad_user ) && Store::secret_unreadable( $bad_user ) );
$now += 100;
$r = Store::attempt( $bad_user, Totp::code( $sec2, $now ) );
$assert( 'secret unreadable: an app code cannot pass', ! $r['ok'] );
Store::clear_failures( $bad_user );
$r = Store::attempt( $bad_user, $codes2[0] );
$assert( 'secret unreadable: a hashed recovery code still verifies', $r['ok'] && 'recovery' === $r['method'] );
$assert( 'a user without the flag is not "unreadable"', ! Store::secret_unreadable( 777 ) && ! Store::is_enabled( 777 ) );
$assert( 'disable() clears an unreadable factor (admin reset)', (function () use ( $bad_user ) {
	Store::disable( $bad_user );
	return ! Store::is_enabled( $bad_user ) && ! Store::secret_unreadable( $bad_user );
})() );

/* ── Atomic attempts: per-user lock (finding 11a) ── */

class Uxs_Fake_Wpdb {
	public $options = 'wp_options';
	public $rows = array();
	public $queries = 0;
	public function prepare( $sql, ...$args ) { return vsprintf( str_replace( array( '%s', '%d' ), array( "'%s'", '%d' ), $sql ), $args ); }
	public function query( $sql ) {
		++$this->queries;
		if ( preg_match( "/INSERT IGNORE INTO \S+ \(.*?\) VALUES \('([^']+)', '([^']+)'/", $sql, $m ) ) {
			if ( isset( $this->rows[ $m[1] ] ) ) { return 0; }
			$this->rows[ $m[1] ] = $m[2];
			return 1;
		}
		if ( preg_match( "/DELETE FROM \S+ WHERE option_name = '([^']+)' AND option_value = '([^']+)'/", $sql, $m ) ) {
			if ( ( $this->rows[ $m[1] ] ?? null ) === $m[2] ) { unset( $this->rows[ $m[1] ] ); return 1; }
			return 0;
		}
		if ( preg_match( "/DELETE FROM \S+ WHERE option_name = '([^']+)'/", $sql, $m ) ) {
			$had = isset( $this->rows[ $m[1] ] ); unset( $this->rows[ $m[1] ] ); return $had ? 1 : 0;
		}
		return 0;
	}
	public function get_var( $sql ) {
		return preg_match( "/option_name = '([^']+)'/", $sql, $m ) ? ( $this->rows[ $m[1] ] ?? null ) : null;
	}
}
$GLOBALS['wpdb'] = new Uxs_Fake_Wpdb();
$race     = 31;
$sec3     = Store::begin_setup( $race );
$now      = 1800700000;
$codes3   = Store::confirm_setup( $race, Totp::code( $sec3, $now ) );
$assert( 'with the DB lock: confirm_setup works and releases the lock', is_array( $codes3 ) && array() === $GLOBALS['wpdb']->rows );
$now += 100;
$GLOBALS['wpdb']->rows['uxstudio_2fa_lock_31'] = (string) ( $now + 10 ); // A parallel request holds the lock.
$t0 = microtime( true );
$r  = Store::attempt( $race, $codes3[0] );
$assert( 'a held lock fails closed: a valid recovery code is NOT accepted without the lock', ! $r['ok'] && 10 === Store::recovery_left( $race ) );
$assert( 'waiting for the lock is bounded (about 2 s)', microtime( true ) - $t0 < 4.0 );
$assert( 'the foreign lock row is untouched', isset( $GLOBALS['wpdb']->rows['uxstudio_2fa_lock_31'] ) );
$GLOBALS['wpdb']->rows['uxstudio_2fa_lock_31'] = (string) ( $now - 5 ); // A crashed request left a stale lock.
$r = Store::attempt( $race, $codes3[0] );
$assert( 'a stale lock is cleaned and the attempt goes through; the lock is released', $r['ok'] && 'recovery' === $r['method'] && array() === $GLOBALS['wpdb']->rows );
$r = Store::attempt( $race, $codes3[0] );
$assert( 'the used recovery code stays one-time', ! $r['ok'] && 9 === Store::recovery_left( $race ) );
$GLOBALS['wpdb'] = null;

/* ── Forced setup: ajax and REST (finding 11b), same answer as a wrong password (11c) ── */

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error { public $code; public $message; public function __construct( $c = '', $m = '' ) { $this->code = $c; $this->message = $m; } public function get_error_code() { return $this->code; } public function get_error_message() { return $this->message; } }
}
if ( ! function_exists( '__' ) ) { function __( $t, $d = '' ) { return $t; } }
if ( ! function_exists( 'esc_url' ) ) { function esc_url( $u ) { return $u; } }
if ( ! function_exists( 'wp_lostpassword_url' ) ) { function wp_lostpassword_url() { return 'https://example.com/wp-login.php?action=lostpassword'; } }
use UxStudio\Modules\SecurityOptimization\TwoFactor;
$assert( 'forced: the 2FA ajax action and heartbeat stay reachable', TwoFactor::forced_request_allowed( 'ajax', 'uxstudio_2fa' ) && TwoFactor::forced_request_allowed( 'ajax', 'heartbeat' ) );
$assert( 'forced: any other admin-ajax action is refused', ! TwoFactor::forced_request_allowed( 'ajax', 'delete-post' ) && ! TwoFactor::forced_request_allowed( 'ajax', '' ) );
$assert( 'forced: REST reads are allowed', TwoFactor::forced_request_allowed( 'rest', 'GET', '/wp/v2/posts' ) && TwoFactor::forced_request_allowed( 'rest', 'OPTIONS', '/uxstudio/v1/modules' ) );
$assert( 'forced: REST writes are refused', ! TwoFactor::forced_request_allowed( 'rest', 'POST', '/wp/v2/posts' ) && ! TwoFactor::forced_request_allowed( 'rest', 'DELETE', '/uxstudio/v1/modules/x' ) && ! TwoFactor::forced_request_allowed( 'rest', 'POST', '/wp/v2/users/1' ) );
$assert( 'forced: the own profile endpoint stays reachable', TwoFactor::forced_request_allowed( 'rest', 'POST', '/wp/v2/users/me' ) && TwoFactor::forced_request_allowed( 'rest', 'POST', '/wp/v2/users/me/' ) );
$err = TwoFactor::wrong_password_error( 'jan' );
$assert( 'non-form login of a 2FA user: core wrong-password code and text, no hint about 2FA', 'incorrect_password' === $err->get_error_code() && str_contains( $err->get_error_message(), 'The password you entered for the username <strong>jan</strong> is incorrect.' ) && ! stripos( $err->get_error_message(), 'two-step' ) && ! stripos( $err->get_error_message(), '2fa' ) );

/* ── Kill switch ── */

$assert( 'active: setting on and no kill switch', Store::active( true ) && ! Store::active( false ) && ! Store::killed() );
define( 'UXSTUDIO_DISABLE_2FA', true );
$assert( 'kill switch constant switches the feature off even when the setting is on', Store::killed() && ! Store::active( true ) );

echo PHP_EOL . ( $failures ? "$failures FAILED" : 'ALL PASSED' ) . PHP_EOL;
exit( $failures ? 1 : 0 );
