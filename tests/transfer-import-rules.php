<?php
// Test only: never runs over HTTP (the release zip also excludes tests/).
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { exit; }
/**
 * Standalone test of the safety rules of the settings import (PLAN.md 29): strict type checks, merge versus
 * replace, the diff, masking of secrets, and every rule that keeps a file from locking the administrator out.
 *
 *   php tests/transfer-import-rules.php
 *
 * Needs no WordPress and no database. Exits non-zero when any case fails.
 *
 * @package UxStudio
 */

// phpcs:disable

define( 'ABSPATH', __DIR__ . '/' );
define( 'UXSTUDIO_PATH', dirname( __DIR__ ) . '/' );

function __( $text, $domain = '' ) { return $text; }

require_once dirname( __DIR__ ) . '/includes/Autoloader.php';
\UxStudio\Autoloader::register();

use UxStudio\Core\Transfer\ImportRules;
use UxStudio\Core\Transfer\Planner;
use UxStudio\Core\Transfer\TypeCheck;

$failures = 0;
$assert   = static function ( string $name, bool $ok, string $detail = '' ) use ( &$failures ): void {
	if ( ! $ok ) {
		++$failures;
	}
	echo ( $ok ? 'PASS ' : 'FAIL ' ) . $name . ( $ok || '' === $detail ? '' : ' => ' . $detail ) . PHP_EOL;
};

/* ── Type sanitisation: JSON types are exact ── */

$toggle = array( 'key' => 't', 'type' => 'toggle' );
$assert( 'toggle accepts true / false', TypeCheck::check( true, $toggle )['ok'] && TypeCheck::check( false, $toggle )['ok'] );
foreach ( array( 1, 0, '1', 'on', 'true', null, array() ) as $bad ) {
	$assert( 'toggle rejects ' . json_encode( $bad ), ! TypeCheck::check( $bad, $toggle )['ok'] );
}
$number = array( 'key' => 'n', 'type' => 'number' );
$assert( 'number accepts int and float', TypeCheck::check( 5, $number )['ok'] && TypeCheck::check( 1.5, $number )['ok'] );
foreach ( array( '5', 'abc', true, null, array( 1 ), NAN, INF ) as $bad ) {
	$assert( 'number rejects ' . ( is_float( $bad ) ? (string) $bad : json_encode( $bad ) ), ! TypeCheck::check( $bad, $number )['ok'] );
}
$select = array( 'key' => 's', 'type' => 'select', 'options' => array( 'a' => 'A', 'b' => 'B', 3 => 'Three' ) );
$assert( 'select accepts a listed choice', 'a' === TypeCheck::check( 'a', $select )['value'] );
$assert( 'select accepts a numeric key given as number or text', '3' === TypeCheck::check( 3, $select )['value'] && '3' === TypeCheck::check( '3', $select )['value'] );
$assert( 'select rejects a choice that does not exist here', ! TypeCheck::check( 'zzz', $select )['ok'] );
$assert( 'select rejects an array and a bool', ! TypeCheck::check( array( 'a' ), $select )['ok'] && ! TypeCheck::check( true, $select )['ok'] );
$multi = array( 'key' => 'm', 'type' => 'multiselect', 'options' => array( 'editor' => 'Editor', 'author' => 'Author' ) );
$res   = TypeCheck::check( array( 'editor', 'ghost', 'editor' ), $multi );
$assert( 'multiselect drops choices this site does not have (and says how many) and removes duplicates', $res['ok'] && array( 'editor' ) === $res['value'] && 1 === $res['dropped'] );
$assert( 'multiselect rejects a non-list', ! TypeCheck::check( 'editor', $multi )['ok'] && ! TypeCheck::check( array( 'k' => 'editor' ), $multi )['ok'] );
$assert( 'multiselect rejects nested arrays', ! TypeCheck::check( array( array( 'editor' ) ), $multi )['ok'] );
$assert( 'multiselect rejects a huge list', ! TypeCheck::check( array_fill( 0, 600, 'editor' ), $multi )['ok'] );
$color = array( 'key' => 'c', 'type' => 'color' );
$assert( 'color accepts #fff, #a1b2c3 and empty', TypeCheck::check( '#fff', $color )['ok'] && TypeCheck::check( '#a1B2c3', $color )['ok'] && TypeCheck::check( '', $color )['ok'] );
$assert( 'color rejects names, rgb() and injection', ! TypeCheck::check( 'red', $color )['ok'] && ! TypeCheck::check( 'rgb(0,0,0)', $color )['ok'] && ! TypeCheck::check( '#fff;}</style>', $color )['ok'] );
$text = array( 'key' => 'x', 'type' => 'text' );
$assert( 'text accepts strings', TypeCheck::check( 'hello', $text )['ok'] );
$assert( 'text rejects numbers, bools, arrays and NUL bytes', ! TypeCheck::check( 5, $text )['ok'] && ! TypeCheck::check( true, $text )['ok'] && ! TypeCheck::check( array(), $text )['ok'] && ! TypeCheck::check( "a\0b", $text )['ok'] );
$assert( 'text rejects an over-long value', ! TypeCheck::check( str_repeat( 'a', TypeCheck::MAX_TEXT + 1 ), $text )['ok'] );
$date = array( 'key' => 'd', 'type' => 'datetime' );
$assert( 'datetime accepts the browser format and empty', TypeCheck::check( '2026-10-26T10:30', $date )['ok'] && TypeCheck::check( '', $date )['ok'] && ! TypeCheck::check( 'tomorrow', $date )['ok'] );
$assert( 'every rejection carries a message', '' !== TypeCheck::check( 'no', $toggle )['error'] );

/* ── Merge versus replace ── */

$schema   = array(
	array( 'key' => 'a', 'type' => 'toggle', 'default' => true ),
	array( 'key' => 'b', 'type' => 'number', 'default' => 10 ),
	array( 'key' => 'c', 'type' => 'text', 'default' => 'x' ),
	array( 'key' => 'd', 'type' => 'multiselect', 'default' => array( 'q' ), 'options' => array( 'q' => 'Q' ) ),
	array( 'key' => 'keepme', 'type' => 'text', 'default' => '' ),
);
$portable = array( 'a', 'b', 'c', 'd' );
$accepted = array( 'b' => 20 );
$assert( 'merge keeps only the keys of the file', array( 'b' => 20 ) === Planner::candidate( $schema, $portable, $accepted, false ) );
$replace = Planner::candidate( $schema, $portable, $accepted, true );
$assert( 'replace resets the portable keys the file does not mention to their defaults', true === $replace['a'] && 20 === $replace['b'] && 'x' === $replace['c'] && array( 'q' ) === $replace['d'] );
$assert( 'replace leaves non-portable keys alone', ! array_key_exists( 'keepme', $replace ) );
$assert( 'replace keeps a key whose value in the file was rejected', ! array_key_exists( 'c', Planner::candidate( $schema, $portable, $accepted, true, array( 'c' ) ) ) );

/* ── Diff ── */

$current = array( 'a' => true, 'b' => 10, 'c' => 'x', 'd' => array( 'q' ) );
$diff    = Planner::changes( $current, array( 'a' => true, 'b' => 20, 'c' => 'x', 'd' => array( 'q' ) ) );
$assert( 'diff finds exactly the changed key', array( 'b' ) === array_keys( $diff['changed'] ) && 10 === $diff['changed']['b']['old'] && 20 === $diff['changed']['b']['new'] );
$assert( 'diff counts the keys without change', array( 'a', 'c', 'd' ) === $diff['same'] );
$assert( 'a number equals the same number as float', Planner::same( 5, 5.0 ) );
$assert( 'a choice stored as number equals the same choice as text', Planner::same( 3, '3' ) );
$assert( 'a toggle never equals a number', ! Planner::same( true, 1 ) && ! Planner::same( false, 0 ) );
$assert( 'a list of choices is a set', Planner::same( array( 'a', 'b' ), array( 'b', 'a' ) ) && ! Planner::same( array( 'a' ), array( 'a', 'b' ) ) );
$assert( 'an unset value equals an empty text', Planner::same( null, '' ) );
$rows = Planner::rows(
	'smtp-email',
	array(
		array( 'key' => 'host', 'type' => 'text', 'label' => 'SMTP host' ),
		array( 'key' => 'password', 'type' => 'text', 'label' => 'Password' ),
		array( 'key' => 'on', 'type' => 'toggle', 'label' => 'On' ),
	),
	array(
		'host'     => array( 'old' => 'old.example', 'new' => 'new.example' ),
		'password' => array( 'old' => 'oldSECRET', 'new' => 'newSECRET' ),
		'on'       => array( 'old' => false, 'new' => true ),
	)
);
$assert( 'diff rows use the human label of the schema', 'SMTP host' === $rows[0]['label'] && 'old.example' === $rows[0]['old'] && 'new.example' === $rows[0]['new'] );
$assert( 'diff rows mask secret values', $rows[1]['secret'] && Planner::MASK === $rows[1]['old'] && Planner::MASK === $rows[1]['new'] && false === strpos( json_encode( $rows[1] ), 'SECRET' ) );
$assert( 'toggles read On / Off', 'Off' === $rows[2]['old'] && 'On' === $rows[2]['new'] );
$long = Planner::value_label( str_repeat( 'abcdefghij', 30 ), array( 'type' => 'textarea' ) );
$assert( 'long values are truncated', mb_strlen( $long, 'UTF-8' ) <= Planner::LABEL_MAX && '…' === mb_substr( $long, -1, 1, 'UTF-8' ) );
$assert( 'choices show their label', 'Bee' === Planner::value_label( 'b', array( 'type' => 'select', 'options' => array( 'b' => 'Bee' ) ) ) );
$assert( 'a sealed value is masked whatever the key is called', Planner::MASK === Planner::value_label( 'uxs1:abcdef', array( 'type' => 'text' ) ) );
$secrets_in = Planner::incoming_secrets( 'smtp-email', array( 'password' => 'p', 'brevo_api_key' => 'k', 'host' => 'evil', 'sendgrid_api_key' => '' ) );
$assert( 'only registered secret keys are taken from the secrets block', array( 'password' => 'p', 'brevo_api_key' => 'k' ) === $secrets_in['accepted'] && in_array( 'host', $secrets_in['rejected'], true ) );
$assert( 'a secret for a module that has none is rejected', array() === Planner::incoming_secrets( 'redirects', array( 'password' => 'p' ) )['accepted'] );

/* ── Safety rules ── */

$sec_schema = array(
	array( 'key' => 'csp_mode', 'type' => 'select', 'options' => array( 'off' => 'Off', 'audit' => 'Audit', 'report-only' => 'Test', 'enforce' => 'Enforce' ) ),
);
$ctx_ok = array(
	'can_unfiltered_html' => true,
	'admin_has_2fa'       => true,
	'admin_roles'         => array( 'administrator' ),
	'admin_login'         => 'jan',
	'admin_ip'            => '203.0.113.7',
	'allow_maintenance'   => false,
	'menu_lock_safe'      => true,
	'is_ssl'              => true,
	'captcha_ready'       => true,
);
$apply = static fn( string $module, array $values, array $current = array(), array $ctx = array(), array $schema = array() ): array => ImportRules::apply( $module, $schema, $values, $current, $ctx + $ctx_ok );

// 1. CSP.
$r = $apply( 'security-optimization', array( 'csp_mode' => 'enforce' ), array( 'csp_mode' => 'off' ) );
$assert( 'CSP: enforce from a file becomes the policy test (report only)', 'report-only' === $r['values']['csp_mode'] && 'csp_enforce' === $r['measures'][0]['code'] );
$r = $apply( 'security-optimization', array( 'csp_mode' => 'enforce' ), array( 'csp_mode' => 'audit' ) );
$assert( 'CSP: from audit also only to report only', 'report-only' === $r['values']['csp_mode'] );
$r = $apply( 'security-optimization', array( 'csp_mode' => 'enforce' ), array( 'csp_mode' => 'report-only' ) );
$assert( 'CSP: stays what it is when the file wants enforce', 'report-only' === $r['values']['csp_mode'] );
$r = $apply( 'security-optimization', array( 'csp_mode' => 'enforce' ), array( 'csp_mode' => 'enforce' ) );
$assert( 'CSP: already enforcing and the file says enforce: nothing to change', ! array_key_exists( 'csp_mode', $r['values'] ) && array() === $r['measures'] );
$r = $apply( 'security-optimization', array( 'csp_mode' => 'audit' ), array( 'csp_mode' => 'enforce' ) );
$assert( 'CSP: going down from enforce is allowed', 'audit' === $r['values']['csp_mode'] );
$in = Planner::incoming( 'security-optimization', $sec_schema, array( 'csp_mode' => 'report-only', 'csp_enforce_override' => true ) );
$assert( 'CSP: the gate override flag of the settings form is not in the schema, so a file cannot carry it', array( 'csp_mode' => 'report-only' ) === $in['accepted'] && array( 'csp_enforce_override' ) === $in['unknown'] );
foreach ( array( 'off', 'audit', 'report-only' ) as $mode ) {
	foreach ( array( 'off', 'audit', 'report-only', 'enforce' ) as $now ) {
		$out = $apply( 'security-optimization', array( 'csp_mode' => 'enforce' ), array( 'csp_mode' => $now ) )['values']['csp_mode'] ?? $now;
		if ( 'enforce' === $out && 'enforce' !== $now ) {
			$assert( "CSP: enforce can never be reached from $now", false );
		}
	}
}
$assert( 'CSP: checked for every starting mode (no path to enforce)', true );

// 2. Login address.
$r = $apply( 'security-optimization', array( 'login_url_enabled' => true, 'login_url' => 'moje-prihlaseni' ), array( 'login_url_enabled' => false, 'login_url' => '' ) );
$assert( 'Login address: a new address starts in the pending state', 'moje-prihlaseni' === $r['login_pending'] && true === $r['values']['login_url_enabled'] );
$r = $apply( 'security-optimization', array( 'login_url_enabled' => true, 'login_url' => 'moje-prihlaseni' ), array( 'login_url_enabled' => true, 'login_url' => 'moje-prihlaseni' ) );
$assert( 'Login address: the same address needs no new confirmation', null === $r['login_pending'] );
$r = $apply( 'security-optimization', array( 'login_url_enabled' => false ), array( 'login_url_enabled' => true, 'login_url' => 'x-login' ) );
$assert( 'Login address: switching it off opens wp-login.php and needs no pending state', null === $r['login_pending'] && false === $r['values']['login_url_enabled'] );
$r = $apply( 'security-optimization', array( 'login_url_enabled' => true, 'login_url' => '' ), array( 'login_url_enabled' => false ) );
$assert( 'Login address: switched on with an empty address is dropped', ! isset( $r['values']['login_url_enabled'] ) && null === $r['login_pending'] );
$r = $apply( 'security-optimization', array( 'login_url_enabled' => true, 'login_url' => '../../etc' ), array( 'login_url_enabled' => false ) );
$assert( 'Login address: an address that is not a plain word is dropped', ! isset( $r['values']['login_url'] ) && null === $r['login_pending'] );
$r = $apply( 'security-optimization', array( 'login_url' => 'novy-slug' ), array( 'login_url_enabled' => true, 'login_url' => 'stary-slug' ) );
$assert( 'Login address: changing the slug while the address is on is pending again', 'novy-slug' === $r['login_pending'] );

// 3. Two-factor.
$r = $apply( 'security-optimization', array( 'two_factor_enabled' => true ), array( 'two_factor_enabled' => false ), array( 'admin_has_2fa' => false ) );
$assert( '2FA: not switched on when the importing administrator has none', ! isset( $r['values']['two_factor_enabled'] ) && 'two_factor' === $r['skipped']['two_factor_enabled']['code'] );
$r = $apply( 'security-optimization', array( 'two_factor_enabled' => true ), array( 'two_factor_enabled' => false ), array( 'admin_has_2fa' => true ) );
$assert( '2FA: switched on when the administrator has it', true === $r['values']['two_factor_enabled'] );
$r = $apply( 'security-optimization', array( 'two_factor_required_roles' => array( 'administrator' ) ), array( 'two_factor_required_roles' => array() ), array( 'admin_has_2fa' => false ) );
$assert( '2FA: roles that include the own role are not forced without a second factor', ! isset( $r['values']['two_factor_required_roles'] ) );
$r = $apply( 'security-optimization', array( 'two_factor_required_roles' => array( 'editor' ) ), array( 'two_factor_required_roles' => array() ), array( 'admin_has_2fa' => false ) );
$assert( '2FA: roles that do not include the own role pass', array( 'editor' ) === $r['values']['two_factor_required_roles'] );

// 4. Country blocks and IP lists.
$r = $apply( 'security-optimization', array( 'country_blocklist_enabled' => true, 'blocked_countries' => array( 'CN' ) ), array( 'ip_firewall_allowlist' => '' ) );
$assert( 'Firewall: country blocks are skipped when the own IP is not on the allowlist', ! isset( $r['values']['country_blocklist_enabled'] ) && ! isset( $r['values']['blocked_countries'] ) );
$r = $apply( 'security-optimization', array( 'country_blocklist_enabled' => true, 'blocked_countries' => array( 'CN' ) ), array( 'ip_firewall_allowlist' => "203.0.113.0/24\n" ) );
$assert( 'Firewall: country blocks pass when the own IP is on the current allowlist (range)', true === $r['values']['country_blocklist_enabled'] );
$r = $apply( 'security-optimization', array( 'country_blocklist_enabled' => true, 'blocked_countries' => array( 'CN' ), 'ip_firewall_allowlist' => '203.0.113.7' ), array() );
$assert( 'Firewall: country blocks pass when the file itself allowlists the own IP', true === $r['values']['country_blocklist_enabled'] );
$r = $apply( 'security-optimization', array( 'country_blocklist_enabled' => false, 'blocked_countries' => array() ), array() );
$assert( 'Firewall: switching blocks off is never held back', false === $r['values']['country_blocklist_enabled'] );
$assert( 'IP list: a line with the own IP is removed', "198.51.100.1\n10.0.0.0/8" === ImportRules::strip_ip_lines( "198.51.100.1\n203.0.113.7\n10.0.0.0/8", '203.0.113.7' ) );
$assert( 'IP list: a range that includes the own IP is removed', "198.51.100.1" === ImportRules::strip_ip_lines( "198.51.100.1\n203.0.113.0/24", '203.0.113.7' ) );
$assert( 'IP list: an IPv6 range works too', "" === ImportRules::strip_ip_lines( "2001:db8::/32", '2001:db8::5' ) );
$assert( 'IP list: unrelated lines stay', "198.51.100.1" === ImportRules::strip_ip_lines( "198.51.100.1", '203.0.113.7' ) );
$r = ImportRules::apply( 'some-module', array(), array( 'blocked_ips' => "1.1.1.1\n203.0.113.7" ), array(), $ctx_ok + array( 'block_ip_lists' => array( 'some-module' => array( 'blocked_ips' ) ) ) );
$assert( 'IP list: a registered block list never keeps the own IP (with a note)', "1.1.1.1" === $r['values']['blocked_ips'] && 'admin_ip' === $r['measures'][0]['code'] );

// 5. Menu lock.
$r = $apply( 'admin-customiser', array( 'menu_lock_enabled' => true ), array( 'menu_lock_enabled' => false ), array( 'menu_lock_safe' => false ) );
$assert( 'Menu lock: not switched on when it would lock the administrator out of key screens', ! isset( $r['values']['menu_lock_enabled'] ) && 'menu_lock' === $r['skipped']['menu_lock_enabled']['code'] );
$r = $apply( 'admin-customiser', array( 'menu_lock_enabled' => true ), array( 'menu_lock_enabled' => false ), array( 'menu_lock_safe' => true ) );
$assert( 'Menu lock: switched on when it is safe', true === $r['values']['menu_lock_enabled'] );
$r = $apply( 'admin-customiser', array( 'menu_lock_enabled' => false ), array( 'menu_lock_enabled' => true ), array( 'menu_lock_safe' => false ) );
$assert( 'Menu lock: switching it off is never held back', false === $r['values']['menu_lock_enabled'] );

// 6. Maintenance.
$r = $apply( 'maintenance-mode', array( 'mode' => 'maintenance' ), array( 'mode' => 'disabled' ) );
$assert( 'Maintenance: not switched on without the extra box', ! isset( $r['values']['mode'] ) && 'maintenance' === $r['skipped']['mode']['code'] );
$r = $apply( 'maintenance-mode', array( 'mode' => 'coming_soon' ), array( 'mode' => 'disabled' ) );
$assert( 'Maintenance: coming soon is held back too', ! isset( $r['values']['mode'] ) );
$r = $apply( 'maintenance-mode', array( 'mode' => 'maintenance' ), array( 'mode' => 'disabled' ), array( 'allow_maintenance' => true ) );
$assert( 'Maintenance: allowed with the extra box', 'maintenance' === $r['values']['mode'] );
$r = $apply( 'maintenance-mode', array( 'mode' => 'disabled' ), array( 'mode' => 'maintenance' ) );
$assert( 'Maintenance: switching it off passes', 'disabled' === $r['values']['mode'] );
$r = $apply( 'maintenance-mode', array( 'mode' => 'maintenance' ), array( 'mode' => 'maintenance' ) );
$assert( 'Maintenance: the mode it already has passes', 'maintenance' === $r['values']['mode'] );

// 7. Code needs unfiltered_html.
$css_schema = array(
	array( 'key' => 'admin_custom_css', 'type' => 'css' ),
	array( 'key' => 'login_logo', 'type' => 'media' ),
	array( 'key' => 'primary_color', 'type' => 'color' ),
);
$r = $apply( 'admin-customiser', array( 'admin_custom_css' => 'body{display:none}', 'primary_color' => '#fff' ), array(), array( 'can_unfiltered_html' => false ), $css_schema );
$assert( 'Code: CSS fields are skipped without unfiltered_html', ! isset( $r['values']['admin_custom_css'] ) && 'unfiltered_html' === $r['skipped']['admin_custom_css']['code'] );
$assert( 'Code: other fields of the module still pass', '#fff' === $r['values']['primary_color'] );
$r = $apply( 'admin-customiser', array( 'admin_custom_css' => 'body{}' ), array(), array( 'can_unfiltered_html' => true ), $css_schema );
$assert( 'Code: CSS passes with unfiltered_html', 'body{}' === $r['values']['admin_custom_css'] );
$r = $apply( 'maintenance-mode', array( 'custom_css' => 'a{}' ), array(), array( 'can_unfiltered_html' => false ), array( array( 'key' => 'custom_css', 'type' => 'textarea' ) ) );
$assert( 'Code: the custom CSS of the maintenance page is listed explicitly', ! isset( $r['values']['custom_css'] ) );

// 8. HTTPS.
$r = $apply( 'security-optimization', array( 'force_https' => true, 'enable_hsts' => true ), array( 'force_https' => false, 'enable_hsts' => false ), array( 'is_ssl' => false ) );
$assert( 'HTTPS: redirect and HSTS are not switched on from a plain HTTP session', ! isset( $r['values']['force_https'] ) && ! isset( $r['values']['enable_hsts'] ) );
$r = $apply( 'security-optimization', array( 'force_https' => true ), array( 'force_https' => false ), array( 'is_ssl' => true ) );
$assert( 'HTTPS: allowed from an HTTPS session', true === $r['values']['force_https'] );

// 9. CAPTCHA.
$r = $apply( 'security-optimization', array( 'captcha_enabled' => true ), array( 'captcha_enabled' => false ), array( 'captcha_ready' => false ) );
$assert( 'CAPTCHA: not switched on when this site has no keys', ! isset( $r['values']['captcha_enabled'] ) );
$r = $apply( 'security-optimization', array( 'captcha_enabled' => true ), array( 'captcha_enabled' => false ), array( 'captcha_ready' => true ) );
$assert( 'CAPTCHA: switched on when this site has keys', true === $r['values']['captcha_enabled'] );
$r = $apply( 'security-optimization', array( 'captcha_enabled' => true, 'captcha_provider' => 'shield' ), array( 'captcha_enabled' => false ), array( 'captcha_ready' => false ) );
$assert( 'CAPTCHA: the key-less Shield needs no keys, so it is switched on', true === $r['values']['captcha_enabled'] );
$r = $apply( 'security-optimization', array( 'captcha_enabled' => true, 'captcha_provider' => 'turnstile' ), array( 'captcha_enabled' => false ), array( 'captcha_ready' => false ) );
$assert( 'CAPTCHA: Turnstile without keys is still not switched on', ! isset( $r['values']['captcha_enabled'] ) );

// 10. Own login in the blocked user names.
$r = $apply( 'security-optimization', array( 'blocked_usernames_extra' => "admin\nJan\ntest" ), array() );
$assert( 'Blocked user names: the own login is removed from the list', "admin\ntest" === $r['values']['blocked_usernames_extra'] );

// Rules never add values.
$r = $apply( 'security-optimization', array(), array( 'csp_mode' => 'off' ) );
$assert( 'An empty import stays empty (rules only remove or weaken)', array() === $r['values'] && null === $r['login_pending'] );
$r = $apply( 'redirects', array( 'monitor_slugs' => true ), array() );
$assert( 'A module without rules passes unchanged', array( 'monitor_slugs' => true ) === $r['values'] );

echo PHP_EOL . ( 0 === $failures ? 'ALL PASSED' : $failures . ' FAILED' ) . PHP_EOL;
exit( 0 === $failures ? 0 : 1 );
