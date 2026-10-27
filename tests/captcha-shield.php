<?php
// Test only: never runs over HTTP (the release zip also excludes tests/).
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { exit; }
/**
 * Standalone test of the key-less Shield CAPTCHA (PLAN.md 31): the challenge and answer round trip, every kind of
 * tampering, the expiry, the one-time use, the fill time rule, the difficulty levels, the typed-question fallback and
 * the decision table of the login form, the order in which a CAPTCHA provider is chosen, and the starter pack rules.
 * Needs no WordPress and no database:
 *
 *   php tests/captcha-shield.php
 *
 * @package UxStudio
 */

// phpcs:disable

define( 'ABSPATH', __DIR__ . '/' );
define( 'DAY_IN_SECONDS', 86400 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'MINUTE_IN_SECONDS', 60 );

$root = dirname( __DIR__ );
define( 'UXSTUDIO_PATH', $root . '/' );

function __( $text, $domain = '' ) { return $text; }
function get_option( $name, $default = false ) { return $default; }

require_once $root . '/includes/Autoloader.php';
\UxStudio\Autoloader::register();

use UxStudio\Core\Onboarding\StarterPack;
use UxStudio\Modules\SecurityOptimization\CaptchaResolver as R;
use UxStudio\Modules\SecurityOptimization\CaptchaShield as S;
use UxStudio\Modules\SecurityOptimization\SettingHelp;

$failures = 0;
$assert   = static function ( string $name, bool $ok, string $detail = '' ) use ( &$failures ): void {
	if ( ! $ok ) {
		++$failures;
	}
	echo ( $ok ? 'PASS ' : 'FAIL ' ) . $name . ( $ok || '' === $detail ? '' : ' => ' . $detail ) . PHP_EOL;
};

/** A one-time store in memory, like the options table row of the real thing. */
$store   = array();
$consume = static function ( string $id, int $ttl ) use ( &$store ): bool {
	if ( isset( $store[ $id ] ) ) {
		return false;
	}
	$store[ $id ] = $ttl;
	return true;
};
$secret = 'test-secret';
$now    = 1800000000000; // ms
// Small puzzle so the PHP brute force stays fast: the protocol is the same at every size.
$make = static fn( int $number = 321, int $max = 2000, int $at = 0 ): array => S::issue( $secret, $max, $at ?: $now, $number, str_repeat( 'ab', 16 ) );

echo "== round trip ==\n";
$c = $make();
$assert( 'the challenge has the documented fields', array( 'algorithm', 'salt', 'number_max', 'challenge', 'issued', 'expires', 'signature' ) === array_keys( $c ) );
$assert( 'algorithm is SHA-256, the challenge is sha256( salt . number )', 'SHA-256' === $c['algorithm'] && hash( 'sha256', $c['salt'] . '321' ) === $c['challenge'] );
$assert( 'it lives 300 seconds', $c['expires'] === intdiv( $now, 1000 ) + 300 );
$assert( 'the solver finds the number', 321 === S::solve( $c ) );
$answer = S::answer( $c, 321 );
$assert( 'a good answer after the fill time is accepted', '' === S::verify( $secret, $answer, $now + 1500, 1200, $consume ) );
$assert( 'the same answer a second time is a replay', 'replay' === S::verify( $secret, $answer, $now + 1600, 1200, $consume ) );

$random = S::issue( $secret, 5000, $now );
$assert( 'a random challenge is solvable within number_max', S::solve( $random ) >= 0 );
$assert( 'two challenges never share a salt', S::issue( $secret, 100, $now )['salt'] !== S::issue( $secret, 100, $now )['salt'] );
$assert( 'the salt is 32 hex characters', 1 === preg_match( '/^[a-f0-9]{32}$/', $random['salt'] ) );

echo "\n== tampering ==\n";
$tamper = static function ( array $change ) use ( $c, $secret, $now, $consume ): string {
	$data = array_merge(
		array(
			'algorithm'  => $c['algorithm'],
			'challenge'  => $c['challenge'],
			'number'     => 321,
			'salt'       => $c['salt'],
			'signature'  => $c['signature'],
			'issued'     => $c['issued'],
			'expires'    => $c['expires'],
			'number_max' => $c['number_max'],
		),
		$change
	);
	return S::verify( $secret, base64_encode( json_encode( $data ) ), $now + 2000, 1200, $consume );
};
$assert( 'a wrong number is "solution"', 'solution' === $tamper( array( 'number' => 322 ) ) );
$assert( 'a changed signature is "signature"', 'signature' === $tamper( array( 'signature' => str_repeat( '0', 64 ) ) ) );
$other = $make( 500 );
$assert( 'a challenge from another puzzle with this signature is "signature"', 'signature' === $tamper( array( 'challenge' => $other['challenge'], 'number' => 500 ) ) );
$assert( 'a changed salt is "signature"', 'signature' === $tamper( array( 'salt' => str_repeat( 'cd', 16 ) ) ) );
$assert( 'a larger number_max is "signature" (cannot ask for an easier puzzle)', 'signature' === $tamper( array( 'number_max' => 99999 ) ) );
$assert( 'a later expiry is "signature"', 'signature' === $tamper( array( 'expires' => $c['expires'] + 999 ) ) );
$assert( 'an earlier issue time is "signature" (cannot skip the fill time)', 'signature' === $tamper( array( 'issued' => $c['issued'] - 9000 ) ) );
$assert( 'another algorithm is refused', 'algorithm' === $tamper( array( 'algorithm' => 'SHA-1' ) ) );
$assert( 'a negative number is malformed', 'malformed' === $tamper( array( 'number' => -1 ) ) );
$assert( 'a number above number_max is malformed', 'malformed' === $tamper( array( 'number' => 2001 ) ) );
$assert( 'a string number is malformed', 'malformed' === $tamper( array( 'number' => '321' ) ) );
$assert( 'a short salt is malformed', 'malformed' === $tamper( array( 'salt' => 'ab' ) ) );
$assert( 'a missing field is malformed', 'malformed' === S::verify( $secret, base64_encode( json_encode( array( 'algorithm' => 'SHA-256' ) ) ), $now + 2000, 1200, $consume ) );
$assert( 'garbage is malformed', 'malformed' === S::verify( $secret, '%%%not-base64%%%', $now, 0, $consume ) );
$assert( 'valid base64 of not JSON is malformed', 'malformed' === S::verify( $secret, base64_encode( 'hello' ), $now, 0, $consume ) );
$assert( 'an oversized payload is malformed', 'malformed' === S::verify( $secret, str_repeat( 'A', 5000 ), $now, 0, $consume ) );
$assert( 'an empty payload is "missing"', 'missing' === S::verify( $secret, '  ', $now, 0, $consume ) );
$assert( 'a signature under another secret is refused', 'signature' === S::verify( 'another-secret', S::answer( $c, 321 ), $now + 2000, 1200, $consume ) );
$fresh = $make( 77 );
$bad   = base64_encode( json_encode( array( 'algorithm' => 'SHA-256', 'challenge' => $fresh['challenge'], 'number' => 78, 'salt' => $fresh['salt'], 'signature' => $fresh['signature'], 'issued' => $fresh['issued'], 'expires' => $fresh['expires'], 'number_max' => $fresh['number_max'] ) ) );
$assert( 'a rejected answer does not burn the challenge', 'solution' === S::verify( $secret, $bad, $now + 2000, 1200, $consume ) && '' === S::verify( $secret, S::answer( $fresh, 77 ), $now + 2000, 1200, $consume ) );

echo "\n== expiry, fill time ==\n";
$e = $make( 11 );
$assert( 'an answer after 300 seconds is "expired"', 'expired' === S::verify( $secret, S::answer( $e, 11 ), $now + 301000, 0, $consume ) );
$e2 = $make( 12 );
$assert( 'an answer just before the end is accepted', '' === S::verify( $secret, S::answer( $e2, 12 ), $now + 299000, 1200, $consume ) );
$f = $make( 13 );
$assert( 'an answer 500 ms after the challenge is "toosoon" (fill time 1200 ms)', 'toosoon' === S::verify( $secret, S::answer( $f, 13 ), $now + 500, 1200, $consume ) );
$assert( 'the same challenge 1300 ms after is accepted (a too-soon answer burned nothing)', '' === S::verify( $secret, S::answer( $f, 13 ), $now + 1300, 1200, $consume ) );
$g = $make( 14 );
$assert( 'fill time 0 switches the rule off', '' === S::verify( $secret, S::answer( $g, 14 ), $now + 1, 0, $consume ) );
$h = S::issue( $secret, 2000, $now + 60000, 15, str_repeat( '12', 16 ) );
$assert( 'a challenge issued in the future is refused', 'malformed' === S::verify( $secret, S::answer( $h, 15 ), $now, 0, $consume ) );
$assert( 'the fill time is ms precise: 1199 ms refused', 'toosoon' === S::verify( $secret, S::answer( $make( 16 ), 16 ), $now + 1199, 1200, $consume ) );
$i = $make( 17 );
$assert( 'exactly the fill time is accepted', '' === S::verify( $secret, S::answer( $i, 17 ), $now + 1200, 1200, $consume ) );

echo "\n== difficulty ==\n";
$assert( 'easy / medium / hard are 50k / 150k / 500k', 50000 === S::number_max( 'easy' ) && 150000 === S::number_max( 'medium' ) && 500000 === S::number_max( 'hard' ) );
$assert( 'an unknown level is medium', 'medium' === S::normalize_level( 'nonsense' ) && 150000 === S::number_max( null ) && 150000 === S::number_max( 7 ) );
$assert( 'after failures the level is one step harder', 'medium' === S::effective_level( 'easy', true ) && 'hard' === S::effective_level( 'medium', true ) );
$assert( 'hard stays hard', 'hard' === S::effective_level( 'hard', true ) );
$assert( 'without failures it stays', 'easy' === S::effective_level( 'easy', false ) && 'medium' === S::effective_level( 'medium', false ) );
$assert( 'the fill time setting: 1.2 s = 1200 ms, 0 = off, 99 s is capped at 10 s, junk = default', 1200 === S::min_ms( 1.2 ) && 0 === S::min_ms( 0 ) && 10000 === S::min_ms( 99 ) && 1200 === S::min_ms( 'x' ) && 0 === S::min_ms( -3 ) );
$assert( 'issue clamps an absurd difficulty', 5000000 === S::issue( $secret, PHP_INT_MAX, $now, 0, str_repeat( 'ab', 16 ) )['number_max'] );

echo "\n== constant time ==\n";
$src = (string) file_get_contents( $root . '/includes/Modules/SecurityOptimization/CaptchaShield.php' );
$assert( 'the verifier compares with hash_equals (algorithm, signature, solution, answer, token)', substr_count( $src, 'hash_equals(' ) >= 5 );
$assert( 'no signature or challenge is compared with == or ===', 0 === preg_match( '/\$data\[\'(signature|challenge)\'\]\s*[!=]==?/', $src ) && 0 === preg_match( '/==\s*\$data\[\'(signature|challenge)\'\]/', $src ) );

echo "\n== typed question (no JavaScript) ==\n";
$q = S::nojs_issue( $secret, 1000, '0123456789abcdef' );
$sum = (string) ( $q['a'] + $q['b'] );
$assert( 'the question is two small numbers', $q['a'] >= 3 && $q['a'] <= 19 && $q['b'] >= 3 && $q['b'] <= 19 );
$assert( 'the same nonce always asks the same question', S::nojs_numbers( $secret, '0123456789abcdef' ) === array( $q['a'], $q['b'] ) );
$assert( 'another secret asks another question (the answer is not in the page)', S::nojs_numbers( 'x', '0123456789abcdef' ) !== array( $q['a'], $q['b'] ) );
$assert( 'the token does not contain the answer or the numbers', false === strpos( $q['token'], $sum . '.' ) );
$nstore = array();
$nc     = static function ( string $id, int $ttl ) use ( &$nstore ): bool { if ( isset( $nstore[ $id ] ) ) { return false; } $nstore[ $id ] = 1; return true; };
$assert( 'too soon (under 3 s) is refused', 'toosoon' === S::nojs_verify( $secret, $q['token'], $sum, 1002, $nc ) );
$assert( 'a wrong sum is refused', 'solution' === S::nojs_verify( $secret, $q['token'], (string) ( $sum + 1 ), 1010, $nc ) );
$assert( 'a non-number is refused', 'solution' === S::nojs_verify( $secret, $q['token'], 'abc', 1010, $nc ) );
$assert( 'the right sum after 3 s is accepted', '' === S::nojs_verify( $secret, $q['token'], $sum, 1010, $nc ) );
$assert( 'it works only once', 'replay' === S::nojs_verify( $secret, $q['token'], $sum, 1011, $nc ) );
$assert( 'a token older than 6 hours is expired', 'expired' === S::nojs_verify( $secret, S::nojs_issue( $secret, 1000, 'aaaaaaaaaaaaaaaa' )['token'], '5', 1000 + 21601, $nc ) );
$assert( 'a changed token is refused', 'signature' === S::nojs_verify( $secret, str_replace( '.0123', '.0124', $q['token'] ), $sum, 1010, $nc ) );
$assert( 'a token under another secret is refused', 'signature' === S::nojs_verify( 'other', S::nojs_issue( $secret, 1000, 'bbbbbbbbbbbbbbbb' )['token'], '5', 1010, $nc ) );
$assert( 'an empty answer or token is "missing"', 'missing' === S::nojs_verify( $secret, $q['token'], '', 1010, $nc ) && 'missing' === S::nojs_verify( $secret, '', '5', 1010, $nc ) );
$assert( 'a malformed token is refused', 'malformed' === S::nojs_verify( $secret, 'abc', '5', 1010, $nc ) );

echo "\n== decision of a whole request (the login form must never lock an administrator out) ==\n";
$js_ok   = static fn( string $p ): string => 'good' === $p ? '' : 'solution';
$nojs_ok = static fn( string $t, string $a ): string => ( 'tok' === $t && '7' === $a ) ? '' : 'solution';
$d = static fn( array $req, string $ctx ) => S::decide( $req, $ctx, $js_ok, $nojs_ok );
$assert( 'JavaScript answer passes', '' === $d( array( 'shield' => 'good' ), 'login' ) );
$assert( 'a wrong JavaScript answer fails', 'solution' === $d( array( 'shield' => 'bad' ), 'login' ) );
$assert( 'a wrong JavaScript answer does not fall back to the typed question (only a MISSING answer does)', 'solution' === $d( array( 'shield' => 'bad', 'nojs' => '7', 'nojs_token' => 'tok' ), 'login' ) );
$assert( 'no JavaScript answer + the right typed sum passes on the login form', '' === $d( array( 'nojs' => '7', 'nojs_token' => 'tok' ), 'login' ) );
$assert( '... and on registration, lost password, the gate and the WooCommerce account forms', '' === $d( array( 'nojs' => '7', 'nojs_token' => 'tok' ), 'register' ) && '' === $d( array( 'nojs' => '7', 'nojs_token' => 'tok' ), 'lostpassword' ) && '' === $d( array( 'nojs' => '7', 'nojs_token' => 'tok' ), 'gate' ) && '' === $d( array( 'nojs' => '7', 'nojs_token' => 'tok' ), 'woo_login' ) );
$assert( 'no JavaScript answer + a wrong sum fails', 'nojs_solution' === $d( array( 'nojs' => '9', 'nojs_token' => 'tok' ), 'login' ) );
$assert( 'nothing at all fails', 'nojs_solution' === $d( array(), 'login' ) );
$assert( 'comments have no typed fallback (cached pages would burn one token for everybody)', 'missing' === $d( array( 'nojs' => '7', 'nojs_token' => 'tok' ), 'comment' ) );
$assert( 'forms of the Forms module have none either (they need JavaScript to send)', 'missing' === $d( array( 'nojs' => '7', 'nojs_token' => 'tok' ), 'forms' ) );
$assert( 'a filled honeypot fails whatever else is right', 'trap' === $d( array( 'shield' => 'good', 'trap' => 'http://spam' ), 'login' ) );
$assert( 'a filled honeypot fails the typed fallback too', 'trap' === $d( array( 'nojs' => '7', 'nojs_token' => 'tok', 'trap' => 'x' ), 'login' ) );
$assert( 'which contexts may use the fallback', S::nojs_allowed( 'login' ) && S::nojs_allowed( 'gate' ) && S::nojs_allowed( 'woo_checkout' ) && ! S::nojs_allowed( 'comment' ) && ! S::nojs_allowed( 'forms' ) );

echo "\n== which provider is used ==\n";
$table = array(
	// connected, ca_keys, own_keys => result
	array( true, true, true, 'ca' ),
	array( true, true, false, 'ca' ),
	array( true, false, true, 'own' ),
	array( true, false, false, 'shield' ),
	array( false, true, true, 'own' ),
	array( false, true, false, 'shield' ),
	array( false, false, true, 'own' ),
	array( false, false, false, 'shield' ),
);
foreach ( $table as $row ) {
	$assert( sprintf( 'connected=%d ca_keys=%d own_keys=%d => %s', $row[0], $row[1], $row[2], $row[3] ), $row[3] === R::resolve( $row[0], $row[1], $row[2] ) );
}
$flags = R::key_flags( true, 'ca', true );
$assert( 'keys from the central application while connected: ca keys, not own keys', true === $flags['ca_keys'] && false === $flags['own_keys'] );
$flags = R::key_flags( true, 'ca', false );
$assert( 'the same keys after the connection is gone still work: they count as own keys, nothing switches off silently', true === $flags['ca_keys'] && true === $flags['own_keys'] && 'own' === R::resolve( false, $flags['ca_keys'], $flags['own_keys'] ) );
$flags = R::key_flags( true, 'manual', true );
$assert( 'manual keys are own keys', false === $flags['ca_keys'] && true === $flags['own_keys'] );
$flags = R::key_flags( false, 'ca', true );
$assert( 'no keys stored: neither', false === $flags['ca_keys'] && false === $flags['own_keys'] );

$assert( 'setting: a fresh install gets "auto"', 'auto' === R::setting( null, false ) );
$assert( 'setting: a site that already stores CAPTCHA settings keeps Turnstile', 'turnstile' === R::setting( null, true ) );
$assert( 'setting: a stored value wins', 'recaptcha_v3' === R::setting( 'recaptcha_v3', true ) && 'shield' === R::setting( 'shield', false ) && 'auto' === R::setting( 'auto', true ) );
$assert( 'setting: junk falls back like unset', 'auto' === R::setting( 'nope', false ) && 'turnstile' === R::setting( 7, true ) );

$assert( 'auto + connected + CA keys => Turnstile from the central application', array( 'provider' => 'turnstile', 'source' => 'ca' ) === R::provider( 'auto', 'recaptcha_v2', true, true, false ) );
$assert( 'auto + own keys of type reCAPTCHA v2 => reCAPTCHA v2', array( 'provider' => 'recaptcha_v2', 'source' => 'own' ) === R::provider( 'auto', 'recaptcha_v2', false, false, true ) );
$assert( 'auto + no keys => the Shield', array( 'provider' => 'shield', 'source' => 'shield' ) === R::provider( 'auto', 'turnstile', true, false, false ) );
$assert( 'shield chosen by hand stays the Shield even with keys', 'shield' === R::provider( 'shield', 'turnstile', true, true, true )['provider'] );
$assert( 'turnstile chosen by hand stays Turnstile (the old rule)', 'turnstile' === R::provider( 'turnstile', 'turnstile', false, false, false )['provider'] );
$assert( 'an unknown type of own keys is Turnstile', 'turnstile' === R::provider( 'auto', 'junk', false, false, true )['provider'] );
$assert( 'forms: CA keys win', array( 'provider' => 'turnstile', 'source' => 'ca' ) === R::for_forms( 'turnstile', 'shield', true, true, true ) );
$assert( 'forms: independent of the login provider setting; own keys, type from the setting', array( 'provider' => 'recaptcha_v2', 'source' => 'own' ) === R::for_forms( 'turnstile', 'recaptcha_v2', false, false, true ) );
$assert( 'forms: own keys of the type named by "own provider" when the login uses the Shield', 'recaptcha_v3' === R::for_forms( 'recaptcha_v3', 'shield', false, false, true )['provider'] );
$assert( 'forms: no keys => the Shield, whatever the login provider is', 'shield' === R::for_forms( 'turnstile', 'turnstile', true, false, false )['provider'] && 'shield' === R::for_forms( 'turnstile', 'auto', false, false, false )['provider'] );

echo "\n== starter pack ==\n";
$item = static function ( array $env ) { return StarterPack::item( 'captcha-shield', $env ); };
$base = $item( array() );
$assert( 'the item exists, is pre-checked and available on a plain site', null !== $base && $base['available'] && $base['checked'] && ! $base['optional'] );
$set = $base['settings']['security-optimization'];
$assert( 'it switches on the CAPTCHA with the automatic provider, inline', true === $set['captcha_enabled'] && 'auto' === $set['captcha_provider'] && 'inline' === $set['captcha_placement'] );
$assert( 'plain site: login, registration, lost password (comments are switched off by the pack)', array( 'login', 'register', 'lostpassword' ) === $set['captcha_forms'] );
$assert( 'WooCommerce keeps comments (reviews), so they are protected too', in_array( 'comment', $item( array( 'woocommerce' => true ) )['settings']['security-optimization']['captcha_forms'], true ) );
$assert( 'a plugin that already disables comments: the pack leaves that alone, comments stay in the list', in_array( 'comment', $item( array( 'blocked_settings' => array( 'disable_comments' => array( 'X' ) ) ) )['settings']['security-optimization']['captcha_forms'], true ) );
$skip = $item( array( 'captcha_set' => true ) );
$assert( 'skipped when a CAPTCHA is already set up (keys or switched on)', ! $skip['available'] && 'captcha_set' === $skip['unavailable'] && ! $skip['checked'] );
$skip = $item( array( 'blocked_settings' => array( 'captcha_enabled' => array( 'Simple Cloudflare Turnstile' ) ) ) );
$assert( 'skipped when a CAPTCHA plugin is active', ! $skip['available'] && 'captcha_plugin' === $skip['unavailable'] );
$skip = $item( array( 'xff' => true ) );
$assert( 'skipped behind a reverse proxy that is not Cloudflare (the same rule as the login attempt limit)', ! $skip['available'] && 'captcha_proxy' === $skip['unavailable'] );
$assert( 'behind Cloudflare it is fine', $item( array( 'xff' => true, 'cloudflare' => true ) )['available'] );
$assert( 'the plugin reason wins over the proxy reason', 'captcha_plugin' === StarterPack::captcha_skip_reason( array( 'xff' => true, 'blocked_settings' => array( 'captcha_enabled' => array( 'X' ) ) ) ) );
$assert( 'the item is in the id list, before "hide-wordpress"', array_search( 'captcha-shield', StarterPack::ids(), true ) < array_search( 'hide-wordpress', StarterPack::ids(), true ) );
$assert( 'applying it by id works (whitelist)', array( 'captcha-shield' ) === StarterPack::sanitize_ids( array( 'captcha-shield', 'nope' ) ) );
foreach ( array( 'captcha_enabled', 'captcha_provider' ) as $key ) {
	$ex = SettingHelp::for_key( $key, true );
	$assert( "$key has what it does, the risk, when to switch it off and when the pack leaves it out", null !== $ex && '' !== trim( $ex['does'] ) && '' !== trim( $ex['risk'] ) && '' !== trim( $ex['off'] ) && '' !== trim( (string) ( $ex['skip'] ?? '' ) ) );
}
$assert( 'the other keys of the preset are plain parameters', in_array( 'captcha_placement', SettingHelp::PARAMETERS, true ) && in_array( 'captcha_forms', SettingHelp::PARAMETERS, true ) );
$module_code = (string) file_get_contents( $root . '/includes/Modules/SecurityOptimization/Module.php' );
foreach ( array_keys( $set ) as $key ) {
	$assert( "$key exists in the Security schema", false !== strpos( $module_code, "'key'     => '$key'" ) );
}

echo $failures ? "\n$failures FAILED\n" : "\nAll passed\n";
exit( $failures ? 1 : 0 );
