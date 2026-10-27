<?php
// Test only: never runs over HTTP (the release zip also excludes tests/).
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { exit; }
/**
 * The key-less Shield CAPTCHA inside a real WordPress (the local pobyty): the public challenge route and its rate
 * limit, the REST allow-list, the answer check and the one-time store, the widget in the login forms, the typed
 * question fallback, the CAPTCHA field of the Forms module (Shield, replay, tampering, independence of the Security
 * switch) and the keys from the central application (mocked HTTP) landing in the shared store.
 *
 * Nothing stays behind: the settings of the site are overridden through a filter (never saved), the options the
 * provisioning test writes are put back byte for byte, the temporary form, its submissions, the used-answer rows and
 * the rate limit transients are deleted, and the options and cron hooks of the plugin are compared before and after.
 *
 *   cd D:\xampp\htdocs\pobyty
 *   D:/xampp/wp-cli/wp.bat --skip-plugins=wp-mail-smtp eval-file D:\xampp\htdocs\_uxs-wt-csp\tests\captcha-shield-wp.php
 *
 * @package UxStudio
 */

defined( 'ABSPATH' ) || exit;

use UxStudio\Core\Security;
use UxStudio\Core\Settings;
use UxStudio\Modules\Forms\FormsCaptcha;
use UxStudio\Modules\Forms\Module as FormsModule;
use UxStudio\Modules\Forms\RestController as FormsRest;
use UxStudio\Modules\SecurityOptimization\CaptchaHandler;
use UxStudio\Modules\SecurityOptimization\CaptchaShield;
use UxStudio\Modules\SecurityOptimization\CaptchaVerifier;
use UxStudio\Modules\SecurityOptimization\Module as SecurityModule;
use UxStudio\Modules\SecurityOptimization\ShieldRuntime;
use UxStudio\Plugin;

global $wpdb;

$failures = 0;
$assert   = static function ( string $name, bool $ok, string $detail = '' ) use ( &$failures ): void {
	if ( ! $ok ) {
		++$failures;
	}
	WP_CLI::log( ( $ok ? 'PASS ' : 'FAIL ' ) . $name . ( $ok || '' === $detail ? '' : ' => ' . $detail ) );
};

/* ── Snapshot of everything the plugin keeps, to prove that nothing is left behind ── */

$snapshot = static function () use ( $wpdb ): array {
	$opts = $wpdb->get_results( "SELECT option_name, MD5(option_value) AS h, autoload FROM {$wpdb->options} WHERE (option_name LIKE 'uxstudio\\_%' OR option_name LIKE '\\_uxs\\_%') AND option_name NOT LIKE 'uxstudio\\_rl\\_%' AND option_name NOT LIKE '\\_transient%' ORDER BY option_name", ARRAY_A );
	$cron = array();
	foreach ( (array) _get_cron_array() as $hooks ) {
		foreach ( array_keys( (array) $hooks ) as $hook ) {
			if ( 0 === strpos( (string) $hook, 'uxstudio_' ) ) {
				$cron[ $hook ] = true;
			}
		}
	}
	ksort( $cron );
	return array( 'options' => $opts, 'cron' => array_keys( $cron ) );
};
$before = $snapshot();

$plugin   = Plugin::instance();
$security = $plugin->modules->instance( 'security-optimization' );
$forms    = $plugin->modules->instance( 'forms' );
if ( ! $security instanceof SecurityModule || ! $forms instanceof FormsModule ) {
	WP_CLI::error( 'Security or Forms module class not available.' );
}

/* ── Override the Security options through a filter (never saved) ── */

$prop = new ReflectionProperty( \UxStudio\Modules\BaseModule::class, 'settings' );
$prop->setAccessible( true );
$real_settings = $prop->getValue( $security );
$real_option   = get_option( 'uxstudio_security_optimization', array() );
$filter        = null;
$override      = static function ( array $values ) use ( &$filter, $prop, $security, $real_option ): void {
	if ( $filter ) {
		remove_filter( 'pre_option_uxstudio_security_optimization', $filter );
	}
	$merged = array_merge( (array) $real_option, $values );
	$filter = static fn() => $merged;
	add_filter( 'pre_option_uxstudio_security_optimization', $filter );
	$prop->setValue( $security, new Settings( 'uxstudio_security_optimization' ) );
	$security->flush_captcha_cache();
};
$restore = static function () use ( &$filter, $prop, $security, $real_settings ): void {
	if ( $filter ) {
		remove_filter( 'pre_option_uxstudio_security_optimization', $filter );
		$filter = null;
	}
	$prop->setValue( $security, $real_settings );
	$security->flush_captcha_cache();
};

/** Rate limit transients/rows of the Guard for the current REMOTE_ADDR. */
$guard_key = new ReflectionMethod( \UxStudio\Modules\BotThrottle\Guard::class, 'key' );
$guard_key->setAccessible( true );
$clean_guard = static function () use ( $guard_key, $wpdb ): void {
	foreach ( array( 'shield_challenge', 'shield_nojs', 'forms_submit' ) as $ctx ) {
		foreach ( array( '_burst', '_ban' ) as $suffix ) {
			$key = (string) $guard_key->invoke( null, $ctx . $suffix );
			delete_transient( $key );
			$wpdb->delete( $wpdb->prefix . 'uxstudio_bot_throttle_buckets', array( 'ip_hash' => $key ) );
		}
	}
};
$clean_used = static function () use ( $wpdb ): void {
	$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_uxs\\_shield\\_used\\_%'" );
};
$used_rows = static fn(): int => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE '\\_uxs\\_shield\\_used\\_%'" );

/** Fetch a challenge the way the browser does and solve it. */
$fetch = static function () use ( $assert ): array {
	$res = rest_do_request( new WP_REST_Request( 'GET', '/uxstudio/v1/shield/challenge' ) );
	return array( $res, (array) $res->get_data() );
};
$solved = static function ( int $level_max = 0 ) use ( $fetch ): array {
	list( , $c ) = $fetch();
	$n = CaptchaShield::solve( $c );
	return array( $c, $n, CaptchaShield::answer( $c, $n ) );
};

// The Forms module is off on this site, so its routes are not registered: build a fresh REST server that has them.
$forms_rest = new FormsRest( $forms );
$GLOBALS['wp_rest_server'] = null;
add_action( 'rest_api_init', array( $forms_rest, 'register_routes' ) );
rest_get_server();

$_SERVER['REMOTE_ADDR']    = '127.0.0.1';
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['HTTP_HOST']      = '127.0.0.1';
wp_set_current_user( 0 );
$clean_guard();
$clean_used();

try {
	/* ── 1. The public challenge route ── */

	WP_CLI::log( '== challenge route ==' );
	$override( array( 'captcha_shield_level' => 'medium', 'captcha_shield_min_time' => 0, 'captcha_shield_adaptive' => true ) );
	list( $res, $c ) = $fetch();
	$assert( 'GET uxstudio/v1/shield/challenge answers 200 to a visitor who is not logged in', 200 === $res->get_status() );
	$assert( 'it returns exactly the documented fields', array( 'algorithm', 'salt', 'number_max', 'challenge', 'issued', 'expires', 'signature' ) === array_keys( $c ) );
	$assert( 'the default difficulty is medium (150000)', 150000 === $c['number_max'] && 'SHA-256' === $c['algorithm'] );
	$assert( 'the signature is made with the server secret, which is not in the response', CaptchaShield::sign( ShieldRuntime::secret(), $c ) === $c['signature'] && false === strpos( wp_json_encode( $c ), ShieldRuntime::secret() ) );
	$cc = (string) ( $res->get_headers()['Cache-Control'] ?? '' );
	$assert( 'never cached: Cache-Control no-store', false !== strpos( $cc, 'no-store' ), $cc );
	$assert( 'two requests give two different salts', $c['salt'] !== $fetch()[1]['salt'] );
	$assert( 'the route is a GET only', 'GET' === implode( ',', array_keys( (array) rest_get_server()->get_routes( 'uxstudio/v1' )['/uxstudio/v1/shield/challenge'][0]['methods'] ) ) );

	$override( array( 'captcha_shield_level' => 'hard', 'captcha_shield_adaptive' => false ) );
	$assert( 'level "hard" gives 500000', 500000 === $fetch()[1]['number_max'] );
	$override( array( 'captcha_shield_level' => 'easy', 'captcha_shield_adaptive' => false ) );
	$assert( 'level "easy" gives 50000', 50000 === $fetch()[1]['number_max'] );

	// Failed logins make the next puzzle of that address harder (the adaptive logic), then a good login clears it.
	$_SERVER['REMOTE_ADDR'] = '198.51.100.9';
	$override( array( 'captcha_shield_level' => 'easy', 'captcha_shield_adaptive' => true, 'captcha_adaptive_threshold' => 2 ) );
	ShieldRuntime::clear_login_failures();
	$assert( 'no failures: easy stays easy', 50000 === $fetch()[1]['number_max'] );
	ShieldRuntime::record_login_failure( 'x', new WP_Error( 'incorrect_password', 'x' ) );
	$assert( 'one failure is below the threshold', 50000 === $fetch()[1]['number_max'] );
	ShieldRuntime::record_login_failure( 'x', new WP_Error( 'incorrect_password', 'x' ) );
	$assert( 'two failures: one step harder (medium)', 150000 === $fetch()[1]['number_max'] );
	$_SERVER['REMOTE_ADDR'] = '198.51.100.10';
	$assert( 'another address is not affected', 50000 === $fetch()[1]['number_max'] );
	$_SERVER['REMOTE_ADDR'] = '198.51.100.9';
	ShieldRuntime::clear_login_failures();
	$assert( 'a successful login clears it', 50000 === $fetch()[1]['number_max'] );
	$override( array( 'captcha_shield_level' => 'easy', 'captcha_shield_adaptive' => false, 'captcha_adaptive_threshold' => 2 ) );
	ShieldRuntime::record_login_failure( 'x', new WP_Error( 'incorrect_password', 'x' ) );
	ShieldRuntime::record_login_failure( 'x', new WP_Error( 'incorrect_password', 'x' ) );
	$assert( 'with the adaptive switch off the level stays', 50000 === $fetch()[1]['number_max'] );
	ShieldRuntime::clear_login_failures();
	$clean_guard();

	// Rate limit per IP (a documentation address, so nobody real is banned).
	$_SERVER['REMOTE_ADDR'] = '192.0.2.55';
	$clean_guard();
	$codes = array();
	for ( $i = 0; $i < 42; $i++ ) {
		$codes[] = $fetch()[0]->get_status();
	}
	$assert( 'the first 40 challenges of an address in a minute pass', 40 === count( array_keys( $codes, 200, true ) ) );
	$assert( 'the 41st is refused with 429', 429 === $codes[40] && 429 === $codes[41] );
	$_SERVER['REMOTE_ADDR'] = '192.0.2.56';
	$assert( 'another address is not limited', 200 === $fetch()[0]->get_status() );
	$_SERVER['REMOTE_ADDR'] = '192.0.2.55';
	$clean_guard();
	$_SERVER['REMOTE_ADDR'] = '192.0.2.56';
	$clean_guard();
	$_SERVER['REMOTE_ADDR'] = '127.0.0.1';

	/* ── 2. The REST allow-list with "restrict REST API" on ── */

	WP_CLI::log( '== rest allow-list ==' );
	$ref    = new ReflectionClass( SecurityModule::class );
	$probe  = $ref->newInstanceWithoutConstructor();
	$allows = static function ( string $uri, string $method = 'GET' ) use ( $probe ): string {
		$parts                     = wp_parse_url( $uri );
		$_SERVER['REQUEST_URI']    = $uri;
		$_SERVER['REQUEST_METHOD'] = $method;
		$_GET                      = array();
		if ( ! empty( $parts['query'] ) ) {
			parse_str( $parts['query'], $_GET );
		}
		$r = $probe->restrict_rest_api( null );
		return is_wp_error( $r ) ? $r->get_error_code() : 'allowed';
	};
	rest_get_server();
	$assert( 'an anonymous visitor reaches the challenge route although the REST API is restricted', 'allowed' === $allows( '/wp-json/uxstudio/v1/shield/challenge' ) );
	$assert( 'also with plain permalinks (?rest_route=)', 'allowed' === $allows( '/?rest_route=/uxstudio/v1/shield/challenge' ) );
	$assert( 'the users route stays closed', 'uxstudio_rest_forbidden' === $allows( '/wp-json/wp/v2/users' ) );
	$assert( 'other routes of our namespace stay closed (settings)', 'uxstudio_rest_forbidden' === $allows( '/wp-json/uxstudio/v1/settings/security-optimization' ) );
	$assert( 'the Forms provisioning route stays closed', 'uxstudio_rest_forbidden' === $allows( '/wp-json/uxstudio/v1/forms/captcha/provision', 'POST' ) );
	$assert( 'the Forms status route stays closed', 'uxstudio_rest_forbidden' === $allows( '/wp-json/uxstudio/v1/forms/captcha/status' ) );
	$assert( 'the public form submit route is still public', 'allowed' === $allows( '/wp-json/uxstudio/v1/forms/submit', 'POST' ) );
	$assert( 'a dot-segment cannot borrow the public route', 'uxstudio_rest_forbidden' === $allows( '/wp-json/uxstudio/v1/shield/challenge/../../wp/v2/users' ) );
	$_SERVER['REQUEST_METHOD'] = 'POST';
	$_GET                      = array();

	/* ── 3. The answer check and the one-time store ── */

	WP_CLI::log( '== answer check ==' );
	$override( array( 'captcha_shield_level' => 'easy', 'captcha_shield_min_time' => 0, 'captcha_shield_adaptive' => false ) );
	list( $c1, $n1, $answer1 ) = $solved();
	$assert( 'the solver finds a number for a real challenge', $n1 >= 0 );
	$_POST = array( CaptchaShield::FIELD => $answer1 );
	$assert( 'a good answer passes', '' === ShieldRuntime::check( 'login' ) );
	$assert( 'one row in the options table marks it used', 1 === $used_rows() );
	$assert( 'the same answer again is a replay', 'replay' === ShieldRuntime::check( 'login' ) );
	$assert( 'and it did not write a second row', 1 === $used_rows() );
	$autoload = $wpdb->get_var( "SELECT autoload FROM {$wpdb->options} WHERE option_name LIKE '\\_uxs\\_shield\\_used\\_%' LIMIT 1" );
	$assert( 'the mark is not autoloaded', in_array( $autoload, array( 'no', 'off' ), true ), (string) $autoload );

	list( $c2, $n2 ) = $solved();
	$tampered        = json_decode( (string) base64_decode( CaptchaShield::answer( $c2, $n2 ) ), true );
	$tampered['number'] = $n2 + 1;
	$_POST           = array( CaptchaShield::FIELD => base64_encode( wp_json_encode( $tampered ) ) );
	$assert( 'a wrong number is refused', 'solution' === ShieldRuntime::check( 'login' ) );
	$_POST = array( CaptchaShield::FIELD => CaptchaShield::answer( $c2, $n2 ), CaptchaShield::TRAP_FIELD => 'http://spam' );
	$assert( 'a filled honeypot is refused even with a good answer', 'trap' === ShieldRuntime::check( 'login' ) );
	$_POST = array( CaptchaShield::FIELD => CaptchaShield::answer( $c2, $n2 ) );
	$assert( 'and that did not burn the good answer', '' === ShieldRuntime::check( 'login' ) );

	$_POST = array();
	$assert( 'nothing posted on a form without the typed fallback (comments)', 'missing' === ShieldRuntime::check( 'comment' ) );
	$assert( 'nothing posted on a login form is a failed typed question', 'nojs_missing' === ShieldRuntime::check( 'login' ) );
	$assert( 'the REST comment path takes the answer as a parameter', '' === ShieldRuntime::check( 'comment', CaptchaShield::FIELD, $solved()[2] ) );

	// The fill time, measured for real: 1.3 s after the challenge is issued.
	$override( array( 'captcha_shield_level' => 'easy', 'captcha_shield_min_time' => 1.2, 'captcha_shield_adaptive' => false ) );
	list( $c3, $n3, $answer3 ) = $solved();
	$_POST                     = array( CaptchaShield::FIELD => $answer3 );
	$assert( 'an answer at once is refused: too soon (fill time 1.2 s)', 'toosoon' === ShieldRuntime::check( 'login' ) );
	usleep( 1300000 );
	$assert( 'the same answer after 1.3 s passes (the too-soon try did not burn it)', '' === ShieldRuntime::check( 'login' ) );
	$assert( 'the fill time setting reaches the widget: data-min', 1200 === ShieldRuntime::min_ms() );
	$clean_used();

	/* ── 4. The login forms ── */

	WP_CLI::log( '== login forms ==' );
	$override( array( 'captcha_enabled' => true, 'captcha_provider' => 'shield', 'captcha_placement' => 'inline', 'captcha_mode' => 'always', 'captcha_forms' => array( 'login', 'register', 'lostpassword', 'comment' ), 'captcha_shield_level' => 'easy', 'captcha_shield_min_time' => 0, 'captcha_shield_adaptive' => false, 'captcha_theme' => 'auto' ) );
	$assert( 'provider "shield" resolves to the Shield', 'shield' === CaptchaVerifier::provider( $security ) && CaptchaVerifier::is_configured( $security ) );
	ob_start();
	CaptchaVerifier::render_widget( $security, 'login' );
	$html = (string) ob_get_clean();
	$assert( 'the widget markup is the Shield, not a Turnstile box', false !== strpos( $html, 'data-uxs-shield' ) && false === strpos( $html, 'cf-turnstile' ) && false === strpos( $html, 'g-recaptcha' ) );
	$assert( 'it carries the challenge URL, the worker URL, the fill time and the labels', false !== strpos( $html, 'data-challenge="' . esc_url( rest_url( 'uxstudio/v1/shield/challenge' ) ) . '"' ) && false !== strpos( $html, 'data-worker="' ) && false !== strpos( $html, 'data-min="0"' ) && false !== strpos( $html, 'data-l-working="' ) );
	$assert( 'it has the answer field, the honeypot and the typed-question fallback', false !== strpos( $html, 'name="uxstudio_shield"' ) && false !== strpos( $html, 'name="uxs_trap"' ) && false !== strpos( $html, 'name="uxstudio_shield_nojs"' ) && false !== strpos( $html, 'name="uxstudio_shield_nojs_token"' ) );
	$assert( 'the honeypot is out of the tab order and hidden from assistive technology', false !== strpos( $html, 'tabindex="-1"' ) && false !== strpos( $html, 'aria-hidden="true"' ) );
	$assert( 'accessible: a label around the checkbox and a polite live region', false !== strpos( $html, '<label class="uxs-shield__box">' ) && false !== strpos( $html, 'role="status" aria-live="polite"' ) );
	$assert( 'no secret and no used-answer data in the markup', false === strpos( $html, ShieldRuntime::secret() ) );
	$assert( 'the script and the style are enqueued', wp_script_is( 'uxstudio-shield', 'enqueued' ) && wp_style_is( 'uxstudio-shield', 'enqueued' ) );
	ob_start();
	CaptchaVerifier::render_widget( $security, 'comment' );
	$comment_html = (string) ob_get_clean();
	$assert( 'on a login page "automatic" theme means light (the page is a white box), on a comment form it follows the visitor', false !== strpos( $html, 'data-theme="light"' ) && false !== strpos( $comment_html, 'data-theme="auto"' ) );
	$assert( 'the comment form widget has no typed fallback', false === strpos( $comment_html, 'uxstudio_shield_nojs' ) && false !== strpos( $comment_html, 'data-uxs-shield' ) );

	$handler = new CaptchaHandler( $security );
	foreach ( array( 'login_form', 'register_form', 'lostpassword_form' ) as $hook ) {
		ob_start();
		do_action( $hook );
		$out = (string) ob_get_clean();
		$assert( "$hook renders the Shield widget", false !== strpos( $out, 'data-uxs-shield' ) );
	}

	$_POST = array( 'log' => 'qa-shield-nobody' );
	$_SERVER['REQUEST_METHOD'] = 'POST';
	$verdict = static fn( $r ) => is_wp_error( $r ) ? $r->get_error_code() : 'ok';
	$assert( 'login without any answer is refused before the password is looked at', 'uxstudio_captcha_failed' === $verdict( $handler->verify_login( null, 'qa-shield-nobody', 'x' ) ) );
	list( , , $a ) = $solved();
	$_POST         = array( 'log' => 'qa-shield-nobody', CaptchaShield::FIELD => $a );
	$assert( 'login with a good answer goes on to the password check (user untouched)', 'ok' === $verdict( $handler->verify_login( null, 'qa-shield-nobody', 'x' ) ) );
	$assert( 'the second look in the same request sees the same verdict (an answer is burnt once, not twice)', 'ok' === $verdict( $handler->verify_login( null, 'qa-shield-nobody', 'x' ) ) && 1 === $used_rows() );
	CaptchaVerifier::flush(); // The next request.
	$assert( 'the same answer in the next request is refused (replay)', 'uxstudio_captcha_failed' === $verdict( $handler->verify_login( null, 'qa-shield-nobody', 'x' ) ) );
	list( , , $b ) = $solved();
	$_POST         = array( 'log' => 'qa-shield-nobody', CaptchaShield::FIELD => $b );
	CaptchaVerifier::flush();
	$errors = $handler->verify_registration( new WP_Error(), 'u', 'u@example.test' );
	$assert( 'registration accepts a good answer', ! $errors->has_errors() );
	CaptchaVerifier::flush();
	$errors = $handler->verify_registration( new WP_Error(), 'u', 'u@example.test' );
	$assert( 'registration refuses a replay', in_array( 'uxstudio_captcha_failed', $errors->get_error_codes(), true ) );

	// Without JavaScript: the typed question. The widget is rendered by the form, the visitor reads it and types the sum.
	$ask = static function () use ( $security ): array {
		ob_start();
		CaptchaVerifier::render_widget( $security, 'login' );
		$h = (string) ob_get_clean();
		preg_match( '/what is (\d+) \+ (\d+)\?/', $h, $q );
		preg_match( '/name="uxstudio_shield_nojs_token" value="([^"]+)"/', $h, $t );
		return array( (int) ( $q[1] ?? 0 ), (int) ( $q[2] ?? 0 ), (string) ( $t[1] ?? '' ) );
	};
	list( $qa, $qb, $token ) = $ask();
	$assert( 'the question and the signed token are in the page', $qa > 0 && $qb > 0 && '' !== $token );
	$assert( 'the token does not hold the answer', false === strpos( $token, (string) ( $qa + $qb ) . '.' ) );
	$_POST = array( 'log' => 'qa-shield-nobody', CaptchaShield::NOJS_FIELD => (string) ( $qa + $qb ), CaptchaShield::NOJS_TOKEN_FIELD => $token );
	CaptchaVerifier::flush();
	$assert( 'the typed sum is refused if sent at once (3 s minimum)', 'uxstudio_captcha_failed' === $verdict( $handler->verify_login( null, 'qa-shield-nobody', 'x' ) ) );
	sleep( 4 );
	CaptchaVerifier::flush();
	$assert( 'the typed sum after 3 s lets the login go on: no JavaScript, no lockout', 'ok' === $verdict( $handler->verify_login( null, 'qa-shield-nobody', 'x' ) ) );
	CaptchaVerifier::flush();
	$assert( 'the same token cannot be used twice', 'uxstudio_captcha_failed' === $verdict( $handler->verify_login( null, 'qa-shield-nobody', 'x' ) ) );
	list( $qa, $qb, $token ) = $ask();
	sleep( 4 );
	$_POST = array( 'log' => 'qa-shield-nobody', CaptchaShield::NOJS_FIELD => (string) ( $qa + $qb + 1 ), CaptchaShield::NOJS_TOKEN_FIELD => $token );
	CaptchaVerifier::flush();
	$assert( 'a wrong typed sum is refused', 'uxstudio_captcha_failed' === $verdict( $handler->verify_login( null, 'qa-shield-nobody', 'x' ) ) );
	$_POST = array( 'log' => 'qa-shield-nobody', CaptchaShield::NOJS_FIELD => (string) ( $qa + $qb ), CaptchaShield::NOJS_TOKEN_FIELD => $token );
	CaptchaVerifier::flush();
	$assert( 'and the right one still works afterwards (a wrong try does not burn the token)', 'ok' === $verdict( $handler->verify_login( null, 'qa-shield-nobody', 'x' ) ) );
	$clean_guard();

	// Automatic provider with no keys = the Shield too.
	$override( array( 'captcha_enabled' => true, 'captcha_provider' => 'auto', 'captcha_shield_min_time' => 0 ) );
	$assert( 'provider "auto" with no keys and no connection resolves to the Shield', 'shield' === CaptchaVerifier::provider( $security ) );
	$override( array( 'captcha_enabled' => true, 'captcha_provider' => 'turnstile', 'captcha_site_key' => '', 'captcha_shield_min_time' => 0 ) );
	$assert( 'an explicit Turnstile without keys is still "not configured" (old rule, nothing changes for existing sites)', 'turnstile' === CaptchaVerifier::provider( $security ) && ! CaptchaVerifier::is_configured( $security ) );
	$fresh = $ref->newInstanceWithoutConstructor();
	$sp    = new ReflectionProperty( \UxStudio\Modules\BaseModule::class, 'settings' );
	$sp->setAccessible( true );
	$sp->setValue( $fresh, new Settings( 'uxstudio_shield_test_nonexistent' ) );
	$assert( 'a fresh install (no CAPTCHA settings stored) defaults to "auto"', 'auto' === $fresh->captcha_default_provider() );
	update_option( 'uxstudio_shield_test_legacy', array( 'captcha_site_key' => 'x' ), false );
	$sp->setValue( $fresh, new Settings( 'uxstudio_shield_test_legacy' ) );
	$assert( 'a site that already stores a CAPTCHA setting defaults to "turnstile"', 'turnstile' === $fresh->captcha_default_provider() );
	delete_option( 'uxstudio_shield_test_legacy' );

	/* ── 5. The CAPTCHA field of the Forms module ── */

	WP_CLI::log( '== forms ==' );
	$override( array( 'captcha_enabled' => false, 'captcha_provider' => 'auto', 'captcha_shield_level' => 'easy', 'captcha_shield_min_time' => 0, 'captcha_shield_adaptive' => false ) );
	$st = FormsCaptcha::status();
	$assert( 'no keys: the field uses the Shield, whatever the Security CAPTCHA switch says (it is off here)', 'shield' === $st['provider'] && 'shield' === $st['source'] && ! $st['has_keys'] );
	$assert( 'not connected to the central application in this test', false === $st['connected'] && false === $st['can_provision'] );

	$form = $forms->create_form(
		array(
			'title'    => 'QA shield temp form',
			'status'   => 'active',
			'fields'   => array(
				array( 'type' => 'text', 'key' => 'name', 'label' => 'Name' ),
				array( 'type' => 'captcha', 'key' => 'captcha', 'label' => 'Captcha' ),
			),
			'settings' => array( 'actions' => array() ),
		)
	);
	$assert( 'a temporary form with a CAPTCHA field was created, with no e-mail action', is_array( $form ) && array() === (array) ( $form['settings']['actions'] ?? array( 'x' ) ) );
	$fid = (int) $form['id'];
	$rest = $forms_rest;
	$post = static function ( int $fid, string $payload ) use ( $rest ): WP_REST_Response|WP_Error {
		CaptchaVerifier::flush(); // A new request.
		$_POST = array( FormsCaptcha::field_name( $fid ) => $payload );
		$req   = new WP_REST_Request( 'POST', '/uxstudio/v1/forms/submit' );
		$req->set_param( 'id', $fid );
		$req->set_param( 'data', wp_json_encode( array( 'name' => 'QA' ) ) );
		return $rest->submit( $req );
	};
	$code = static fn( $r ): string => is_wp_error( $r ) ? $r->get_error_code() . '|' . (string) $r->get_error_message() : 'ok';

	$html = FormsCaptcha::render( $fid );
	$assert( 'the field renders the Shield with an answer field of its own form', false !== strpos( $html, 'name="uxs_shield_f' . $fid . '"' ) && false === strpos( $html, 'uxstudio_shield_nojs' ) );
	$assert( 'the form HTML renders through the public renderer too', false !== strpos( ( new \UxStudio\Modules\Forms\PublicRenderer() )->render( $forms->get_form( $fid ) ), 'name="uxs_shield_f' . $fid . '"' ) );

	$_POST = array();
	$r     = $post( $fid, '' );
	$assert( 'submit without an answer: 422 and a clear sentence', is_wp_error( $r ) && 'uxstudio_forms_captcha' === $r->get_error_code() && 422 === ( $r->get_error_data()['status'] ?? 0 ) && false !== strpos( $r->get_error_message(), 'verification' ), $code( $r ) );
	list( $fc, $fn, $fa ) = $solved();
	$r = $post( $fid, $fa );
	$assert( 'submit with a good answer succeeds', ! is_wp_error( $r ), $code( $r ) );
	$assert( 'the submission was stored', 1 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}uxstudio_form_submissions WHERE form_id = %d", $fid ) ) );
	$r = $post( $fid, $fa );
	$assert( 'the same answer a second time: refused as already used', is_wp_error( $r ) && 'uxstudio_forms_captcha' === $r->get_error_code() && false !== strpos( $r->get_error_message(), 'already used' ), $code( $r ) );
	list( $fc2, $fn2 ) = $solved();
	$bad               = json_decode( (string) base64_decode( CaptchaShield::answer( $fc2, $fn2 ) ), true );
	$bad['number']     = $fn2 + 3;
	$r                 = $post( $fid, base64_encode( wp_json_encode( $bad ) ) );
	$assert( 'a tampered answer is refused with the generic sentence', is_wp_error( $r ) && 'uxstudio_forms_captcha' === $r->get_error_code(), $code( $r ) );
	$r = $post( $fid, 'not-an-answer' );
	$assert( 'a garbage answer is refused', is_wp_error( $r ) && 'uxstudio_forms_captcha' === $r->get_error_code() );

	// An answer for one form is not valid for another field name.
	list( $fc3, $fn3, $fa3 ) = $solved();
	$_POST                   = array( 'uxs_shield_f' . ( $fid + 1 ) => $fa3 );
	$req                     = new WP_REST_Request( 'POST', '/uxstudio/v1/forms/submit' );
	$req->set_param( 'id', $fid );
	$req->set_param( 'data', wp_json_encode( array( 'name' => 'QA' ) ) );
	$assert( 'the answer field is per form: another form\'s field name does not count', is_wp_error( $rest->submit( $req ) ) );
	$assert( 'the answer posted under the right name is still good (it was not burnt)', ! is_wp_error( $post( $fid, $fa3 ) ) );

	$override( array( 'captcha_enabled' => false, 'captcha_provider' => 'auto', 'captcha_shield_level' => 'easy', 'captcha_shield_min_time' => 1.2, 'captcha_shield_adaptive' => false ) );
	list( , , $fa4 ) = $solved();
	$r               = $post( $fid, $fa4 );
	$assert( 'fill time on forms: an answer at once is refused with a "too fast" sentence', is_wp_error( $r ) && false !== strpos( $r->get_error_message(), 'too fast' ), $code( $r ) );
	usleep( 1300000 );
	$assert( 'and accepted a moment later', ! is_wp_error( $post( $fid, $fa4 ) ) );
	$clean_guard();

	/* ── 6. Keys from the central application land in the shared store ── */

	WP_CLI::log( '== keys from the central application (HTTP mocked) ==' );
	$restore();
	$cs  = $security->captcha_state();
	$raw = static function ( string $name ) use ( $wpdb ): ?string {
		$v = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $name ) );
		return null === $v ? null : (string) $v;
	};
	$raw_autoload = static function ( string $name ) use ( $wpdb ): ?string {
		$v = $wpdb->get_var( $wpdb->prepare( "SELECT autoload FROM {$wpdb->options} WHERE option_name = %s", $name ) );
		return null === $v ? null : (string) $v;
	};
	$store_opts = array( 'uxstudio_security_optimization', 'uxstudio_secret_security_optimization_captcha_secret_key', 'uxstudio_security_optimization_cf_registered_domain' );
	$backup     = array();
	foreach ( $store_opts as $name ) {
		$backup[ $name ] = array( $raw( $name ), $raw_autoload( $name ) );
	}
	$log_max = (int) $wpdb->get_var( "SELECT COALESCE(MAX(id),0) FROM {$wpdb->prefix}uxstudio_activity_log" );

	if ( $cs['has_keys'] ) {
		WP_CLI::log( 'SKIP provisioning test: this site already has CAPTCHA keys stored.' );
	} else {
		$node = Security::seal( str_repeat( 'n', 40 ) );
		$f_ca = static fn() => array( 'central_app_url' => 'https://ca.example.test', 'mode' => 'node' );
		$f_nk = static fn() => $node;
		add_filter( 'pre_option_uxstudio_content_sync', $f_ca );
		add_filter( 'pre_option_uxstudio_secret_content_sync_node_key', $f_nk );
		$security->flush_captcha_cache();
		$assert( 'with the URL and the node key set the site counts as connected', \UxStudio\Modules\SecurityOptimization\TurnstileCaProvisioner::is_connected() && $security->captcha_state()['connected'] );
		$st = FormsCaptcha::status();
		$assert( 'connected, no keys yet: the button is offered, the Shield protects meanwhile', 'shield' === $st['provider'] && true === $st['can_provision'] && true === $st['connected'] );

		$calls = array();
		$mock  = static function ( $pre, $args, $url ) use ( &$calls ) {
			$calls[] = $url;
			if ( false !== strpos( $url, 'turnstile_provision' ) ) {
				return array( 'response' => array( 'code' => 200, 'message' => 'OK' ), 'body' => wp_json_encode( array( 'success' => true, 'site_key' => '0xQASITEKEY', 'secret_key' => '0xQASECRETKEY' ) ), 'headers' => array(), 'cookies' => array(), 'filename' => null );
			}
			if ( false !== strpos( $url, 'siteverify' ) ) {
				$ok = 'good-token' === ( $args['body']['response'] ?? '' ) && '0xQASECRETKEY' === ( $args['body']['secret'] ?? '' );
				return array( 'response' => array( 'code' => 200, 'message' => 'OK' ), 'body' => wp_json_encode( array( 'success' => $ok ) ), 'headers' => array(), 'cookies' => array(), 'filename' => null );
			}
			return $pre;
		};
		$mock_fail = static function ( $pre, $args, $url ) {
			if ( false !== strpos( $url, 'turnstile_provision' ) ) {
				return array( 'response' => array( 'code' => 429, 'message' => 'Too Many Requests' ), 'body' => wp_json_encode( array( 'success' => false, 'error' => 'Limit 5 registrations per hour exceeded.' ) ), 'headers' => array(), 'cookies' => array(), 'filename' => null );
			}
			return $pre;
		};

		add_filter( 'pre_http_request', $mock_fail, 10, 3 );
		$r = FormsCaptcha::provision();
		$assert( 'a refusal of the central application: its own text is passed on, nothing is stored', is_wp_error( $r ) && false !== strpos( $r->get_error_message(), 'Limit 5 registrations per hour exceeded.' ) && $raw( 'uxstudio_secret_security_optimization_captcha_secret_key' ) === $backup['uxstudio_secret_security_optimization_captcha_secret_key'][0] );
		remove_filter( 'pre_http_request', $mock_fail, 10 );

		add_filter( 'pre_http_request', $mock, 10, 3 );
		$admin = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) );
		wp_set_current_user( (int) $admin[0] );
		$res = rest_do_request( new WP_REST_Request( 'POST', '/uxstudio/v1/forms/captcha/provision' ) );
		$json = wp_json_encode( $res->get_data() );
		$assert( 'POST forms/captcha/provision as an administrator answers 200', 200 === $res->get_status(), (string) $res->get_status() . ' ' . $json );
		$assert( 'the response says Turnstile from the central application', 'turnstile' === ( $res->get_data()['data']['provider'] ?? '' ) && 'ca' === ( $res->get_data()['data']['source'] ?? '' ) );
		$assert( 'no secret and no key in any response', false === strpos( $json, '0xQASECRETKEY' ) && false === strpos( $json, '0xQASITEKEY' ) );
		$assert( 'the secret is stored encrypted, not as plain text', null !== $raw( 'uxstudio_secret_security_optimization_captcha_secret_key' ) && false === strpos( (string) $raw( 'uxstudio_secret_security_optimization_captcha_secret_key' ), '0xQASECRETKEY' ) && '0xQASECRETKEY' === Security::get_secret( 'uxstudio_secret_security_optimization_captcha_secret_key' ) );
		$assert( 'the site key and the source are in the Security options (the one shared store)', '0xQASITEKEY' === get_option( 'uxstudio_security_optimization' )['captcha_site_key'] && 'ca' === get_option( 'uxstudio_security_optimization' )['captcha_key_source'] );
		$assert( 'the secret option is not autoloaded', in_array( $raw_autoload( 'uxstudio_secret_security_optimization_captcha_secret_key' ), array( 'no', 'off' ), true ) );
		$assert( 'the Security CAPTCHA switch was not touched by the Forms provisioning', ( ( (array) get_option( 'uxstudio_security_optimization' ) )['captcha_enabled'] ?? null ) === ( ( (array) $real_option )['captcha_enabled'] ?? null ) );

		$security->flush_captcha_cache();
		$cfg = CaptchaVerifier::forms_config( $security );
		$assert( 'the Forms config is now Turnstile, source ca', 'turnstile' === $cfg['provider'] && 'ca' === $cfg['source'] && '0xQASITEKEY' === $cfg['site_key'] );
		$assert( 'the SAME keys serve the login forms (no second provisioning): auto resolves to Turnstile from the central application', 'turnstile' === CaptchaVerifier::config( $security )['provider'] && 'ca' === CaptchaVerifier::config( $security )['source'] );
		$html = FormsCaptcha::render( $fid );
		$assert( 'the form field now draws the Turnstile widget with the site key', false !== strpos( $html, 'class="cf-turnstile"' ) && false !== strpos( $html, '0xQASITEKEY' ) && false === strpos( $html, '0xQASECRETKEY' ) );
		$_POST = array( 'cf-turnstile-response' => 'good-token' );
		$_POST = array( 'cf-turnstile-response' => 'good-token' );
		$req   = new WP_REST_Request( 'POST', '/uxstudio/v1/forms/submit' );
		$req->set_param( 'id', $fid );
		$req->set_param( 'data', wp_json_encode( array( 'name' => 'QA' ) ) );
		$assert( 'Turnstile token, good', ! is_wp_error( $rest->submit( $req ) ) );
		$_POST = array( 'cf-turnstile-response' => 'bad-token' );
		$assert( 'Turnstile token, bad: refused', is_wp_error( $rest->submit( $req ) ) );
		$_POST = array();
		$assert( 'no token: refused', is_wp_error( $rest->submit( $req ) ) );

		$assert( 'a second click on the button (keys came from the central application) is allowed: a refresh', true === FormsCaptcha::status()['can_provision'] );
		$calls = array();
		$r     = FormsCaptcha::provision();
		$assert( 'and calls the central application again', ! is_wp_error( $r ) && 1 === count( $calls ) );

		// Own keys must never be overwritten by the button.
		$security->store_captcha_keys( '0xOWNSITE', '0xOWNSECRET', 'manual' );
		$st = FormsCaptcha::status();
		$assert( 'own keys: the field uses them and the button is not offered', 'own' === $st['source'] && false === $st['can_provision'] );
		$calls = array();
		$r     = FormsCaptcha::provision();
		$assert( 'provisioning refuses to overwrite own keys and does not call the central application', is_wp_error( $r ) && 'uxstudio_forms_captcha_own_keys' === $r->get_error_code() && 0 === count( $calls ) );
		$assert( 'own keys are still there', '0xOWNSITE' === get_option( 'uxstudio_security_optimization' )['captcha_site_key'] && '0xOWNSECRET' === Security::get_secret( 'uxstudio_secret_security_optimization_captcha_secret_key' ) );

		// Not connected any more: keys from the central application keep working as own keys.
		$security->store_captcha_keys( '0xQASITEKEY', '0xQASECRETKEY', 'ca' );
		remove_filter( 'pre_option_uxstudio_content_sync', $f_ca );
		remove_filter( 'pre_option_uxstudio_secret_content_sync_node_key', $f_nk );
		$security->flush_captcha_cache();
		$st = FormsCaptcha::status();
		$assert( 'connection gone: the stored keys still protect (as own keys), nothing is switched off silently', 'turnstile' === $st['provider'] && 'own' === $st['source'] && false === $st['connected'] );

		wp_set_current_user( 0 );
		$res = rest_do_request( new WP_REST_Request( 'POST', '/uxstudio/v1/forms/captcha/provision' ) );
		$assert( 'a visitor cannot call the provisioning route', in_array( $res->get_status(), array( 401, 403 ), true ), (string) $res->get_status() );
		$res = rest_do_request( new WP_REST_Request( 'GET', '/uxstudio/v1/forms/captcha/status' ) );
		$assert( 'a visitor cannot read the status route', in_array( $res->get_status(), array( 401, 403 ), true ), (string) $res->get_status() );
		remove_filter( 'pre_http_request', $mock, 10 );
	}

	// Put the three options back byte for byte.
	foreach ( $backup as $name => $pair ) {
		if ( null === $pair[0] ) {
			delete_option( $name );
		} else {
			$wpdb->update( $wpdb->options, array( 'option_value' => $pair[0] ), array( 'option_name' => $name ) );
			if ( null === $raw( $name ) ) {
				$wpdb->insert( $wpdb->options, array( 'option_name' => $name, 'option_value' => $pair[0], 'autoload' => (string) $pair[1] ) );
			}
		}
		wp_cache_delete( $name, 'options' );
	}
	wp_cache_delete( 'alloptions', 'options' );
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}uxstudio_activity_log WHERE id > %d AND module = 'forms' AND action IN ('captcha_provisioned','captcha_provision_failed','create','delete')", $log_max ) );
	$same = true;
	foreach ( $backup as $name => $pair ) {
		$same = $same && $raw( $name ) === $pair[0];
	}
	$assert( 'the three options are back exactly as they were', $same );
} finally {
	// Cleanup runs even when an assertion threw.
	$restore();
	wp_set_current_user( 0 );
	if ( isset( $fid ) && $fid > 0 ) {
		$forms->delete_form_and_submissions( $fid );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}uxstudio_activity_log WHERE module = 'forms' AND object_id = %d", $fid ) );
	}
	foreach ( array( '127.0.0.1', '198.51.100.9', '198.51.100.10', '192.0.2.55', '192.0.2.56' ) as $ip ) {
		$_SERVER['REMOTE_ADDR'] = $ip;
		$clean_guard();
	}
	$clean_used();
	delete_option( 'uxstudio_shield_test_legacy' );
	\UxStudio\Modules\Forms\InlineScripts::rebuild();
}

WP_CLI::log( '== nothing left behind ==' );
$after = $snapshot();
$diff  = array();
$bmap  = array();
foreach ( $before['options'] as $row ) {
	$bmap[ $row['option_name'] ] = $row['h'] . $row['autoload'];
}
foreach ( $after['options'] as $row ) {
	if ( ! isset( $bmap[ $row['option_name'] ] ) ) {
		$diff[] = '+' . $row['option_name'];
	} elseif ( $bmap[ $row['option_name'] ] !== $row['h'] . $row['autoload'] ) {
		$diff[] = '~' . $row['option_name'];
	}
	unset( $bmap[ $row['option_name'] ] );
}
foreach ( array_keys( $bmap ) as $gone ) {
	$diff[] = '-' . $gone;
}
$assert( 'the plugin options are unchanged (apart from the form hook cache rebuilt by the temporary form)', array() === array_diff( $diff, array( '~uxstudio_forms_hook_hashes' ) ), implode( ',', $diff ) );
$assert( 'the uxstudio_* cron hooks are the same as before', $before['cron'] === $after['cron'], implode( ',', $before['cron'] ) . ' vs ' . implode( ',', $after['cron'] ) );
$assert( 'no used-answer rows are left', 0 === $used_rows() );
$assert( 'no temporary form is left', 0 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}uxstudio_forms WHERE title = 'QA shield temp form'" ) );
$assert( 'no submissions of it are left', 0 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}uxstudio_form_submissions WHERE form_id = " . (int) ( $fid ?? 0 ) ) );

WP_CLI::log( $failures ? "\n$failures FAILED" : "\nAll passed" );
exit( $failures ? 1 : 0 );
