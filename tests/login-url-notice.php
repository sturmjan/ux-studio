<?php
// Test only: never runs over HTTP (the release zip also excludes tests/).
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { exit; }
/**
 * Standalone test of the release-safety notice for the custom login address (Security module, phase F7):
 * when it is raised, that the e-mail goes out exactly once per site, who sees the notice and how a user
 * dismisses it. Needs no WordPress and no database:
 *
 *   php tests/login-url-notice.php
 *
 * @package UxStudio
 */

// phpcs:disable

define( 'ABSPATH', __DIR__ . '/' );
define( 'UXSTUDIO_VERSION', '0.5.1-test' );
define( 'UXSTUDIO_PATH', dirname( __DIR__ ) . '/' );
define( 'UXSTUDIO_URL', 'https://example.test/wp-content/plugins/ux-studio/' );

$root = dirname( __DIR__ );

$GLOBALS['uxs_options'] = array();
$GLOBALS['uxs_meta']    = array();
$GLOBALS['uxs_caps']    = array( 'manage_options' => true );
$GLOBALS['uxs_log']     = array();

class FakeWpdb {
	public $prefix = 'wp_';
	public function insert( $table, $data ) { $GLOBALS['uxs_log'][] = $data; return 1; }
}
$GLOBALS['wpdb'] = new FakeWpdb();

function get_option( $name, $default = false ) { return array_key_exists( $name, $GLOBALS['uxs_options'] ) ? $GLOBALS['uxs_options'][ $name ] : $default; }
function update_option( $name, $value, $autoload = null ) { $GLOBALS['uxs_options'][ $name ] = $value; return true; }
function get_user_meta( $uid, $key, $single = false ) { return $GLOBALS['uxs_meta'][ $uid ][ $key ] ?? ''; }
function update_user_meta( $uid, $key, $value ) { $GLOBALS['uxs_meta'][ $uid ][ $key ] = $value; return true; }
function current_user_can( $cap ) { return ! empty( $GLOBALS['uxs_caps'][ $cap ] ); }
function get_current_user_id() { return 1; }
function current_time( $type ) { return '2026-10-21 12:00:00'; }
function wp_json_encode( $v ) { return json_encode( $v ); }
function __( $text, $domain = '' ) { return $text; }
function esc_html__( $text, $domain = '' ) { return htmlspecialchars( $text, ENT_QUOTES ); }
function esc_attr__( $text, $domain = '' ) { return htmlspecialchars( $text, ENT_QUOTES ); }
function esc_html( $text ) { return htmlspecialchars( (string) $text, ENT_QUOTES ); }
function esc_attr( $text ) { return htmlspecialchars( (string) $text, ENT_QUOTES ); }
function esc_url( $url ) { return htmlspecialchars( (string) $url, ENT_QUOTES ); }
function admin_url( $p = '' ) { return 'https://example.test/wp-admin/' . $p; }
function wp_nonce_url( $url, $action ) { return $url . '&_wpnonce=abc'; }
function wp_specialchars_decode( $s, $flags = null ) { return html_entity_decode( (string) $s, $flags ?? ENT_QUOTES ); }

require_once $root . '/includes/Autoloader.php';
\UxStudio\Autoloader::register();

use UxStudio\Modules\SecurityOptimization\LoginUrlNotice;

$failures = 0;
$assert   = static function ( string $name, bool $ok, string $detail = '' ) use ( &$failures ): void {
	if ( ! $ok ) {
		++$failures;
	}
	echo ( $ok ? 'PASS ' : 'FAIL ' ) . $name . ( $ok || '' === $detail ? '' : ' => ' . $detail ) . PHP_EOL;
};

$mails = array();
LoginUrlNotice::set_mailer(
	static function ( string $to, string $subject, string $body ) use ( &$mails ): bool {
		$mails[] = compact( 'to', 'subject', 'body' );
		return true;
	}
);
$reset = static function () use ( &$mails ): void {
	$GLOBALS['uxs_options'] = array( 'admin_email' => 'owner@example.test', 'blogname' => 'Test &amp; site' );
	$GLOBALS['uxs_meta']    = array();
	$GLOBALS['uxs_log']     = array();
	$mails                  = array();
};
$active = array( 'active' => true, 'slug' => 'moje-prihlaseni', 'url' => 'https://example.test/pobyty/moje-prihlaseni/', 'reason' => '' );
$off    = array( 'active' => false, 'slug' => '', 'url' => '', 'reason' => 'off' );

/* ── Decision ── */

$assert( 'decide: first load with the lock in force -> notify', 'notify' === LoginUrlNotice::decide( array( 'handled' => false, 'active' => true ) ) );
$assert( 'decide: first load with the lock off (or fail-open collision, or kill switch) -> only record', 'record' === LoginUrlNotice::decide( array( 'handled' => false, 'active' => false ) ) );
$assert( 'decide: after the first load -> done, whatever the lock does', 'done' === LoginUrlNotice::decide( array( 'handled' => true, 'active' => true ) ) && 'done' === LoginUrlNotice::decide( array( 'handled' => true, 'active' => false ) ) );

/* ── Once-only e-mail ── */

$reset();
$assert( 'first load: decision notify', 'notify' === LoginUrlNotice::handle_first_load( $active ) );
$assert( 'first load: exactly one e-mail, to the site e-mail', 1 === count( $mails ) && 'owner@example.test' === $mails[0]['to'], json_encode( $mails ) );
$assert( 'first load: the e-mail carries the full address, the way back and the rescue constant', false !== strpos( $mails[0]['body'], 'https://example.test/pobyty/moje-prihlaseni/' ) && false !== strpos( $mails[0]['body'], 'UXSTUDIO_DISABLE_LOGIN_LOCK' ) && false !== strpos( $mails[0]['body'], 'tab=login' ), $mails[0]['body'] );
$assert( 'first load: the subject names the site with decoded entities', false !== strpos( $mails[0]['subject'], 'Test & site' ), $mails[0]['subject'] );
$state = get_option( LoginUrlNotice::STATE );
$assert( 'first load: state remembers address, version and mail result', true === $state['active'] && 'sent' === $state['mail'] && 'https://example.test/pobyty/moje-prihlaseni/' === $state['url'] && '0.5.1-test' === $state['version'], json_encode( $state ) );
$assert( 'first load: the Activity Log has one entry about it', 1 === count( $GLOBALS['uxs_log'] ) && 'login_url_notice' === $GLOBALS['uxs_log'][0]['action'] );
$assert( 'second load: nothing again, no second e-mail', 'done' === LoginUrlNotice::handle_first_load( $active ) && 1 === count( $mails ) );
$assert( 'a later change of the setting does not send anything', 'done' === LoginUrlNotice::handle_first_load( array( 'url' => 'https://example.test/other/' ) + $active ) && 1 === count( $mails ) );

$reset();
LoginUrlNotice::handle_first_load( $off );
$assert( 'lock off at the first load: no e-mail, state recorded as inactive', 0 === count( $mails ) && false === get_option( LoginUrlNotice::STATE )['active'] );
LoginUrlNotice::handle_first_load( $active );
$assert( 'lock switched on later by the user: no surprise e-mail (the user did it)', 0 === count( $mails ) );
$assert( 'lock off at the first load: nobody gets the notice', ! LoginUrlNotice::visible_for( 1 ) );

// The mail failing (a site that blocks mail) is remembered, not retried.
$reset();
LoginUrlNotice::set_mailer( static function () use ( &$mails ): bool { $mails[] = 'tried'; return false; } );
LoginUrlNotice::handle_first_load( $active );
LoginUrlNotice::handle_first_load( $active );
$assert( 'blocked mail: one attempt only, marked failed in the state and in the log', 1 === count( $mails ) && 'failed' === get_option( LoginUrlNotice::STATE )['mail'] && 'failed' === json_decode( $GLOBALS['uxs_log'][0]['meta'], true )['mail'] );
$reset();
LoginUrlNotice::set_mailer( static function () use ( &$mails ): bool { $mails[] = 'tried'; return true; } );
$GLOBALS['uxs_options']['admin_email'] = '';
LoginUrlNotice::handle_first_load( $active );
$assert( 'no site e-mail: no attempt, state says so', 0 === count( $mails ) && 'failed' === get_option( LoginUrlNotice::STATE )['mail'] );
LoginUrlNotice::set_mailer(
	static function ( string $to, string $subject, string $body ) use ( &$mails ): bool {
		$mails[] = compact( 'to', 'subject', 'body' );
		return true;
	}
);

/* ── Who sees the notice ── */

$reset();
LoginUrlNotice::handle_first_load( $active );
$assert( 'notice: every administrator sees it', LoginUrlNotice::visible_for( 1 ) && LoginUrlNotice::visible_for( 2 ) );
$GLOBALS['uxs_caps']['manage_options'] = false;
$assert( 'notice: a user without manage_options does not', ! LoginUrlNotice::visible_for( 3 ) );
$GLOBALS['uxs_caps']['manage_options'] = true;
update_user_meta( 1, LoginUrlNotice::DISMISS_META, '123' );
$assert( 'notice: dismissed by one user only hides it for that user (persistent for the rest)', ! LoginUrlNotice::visible_for( 1 ) && LoginUrlNotice::visible_for( 2 ) );

/* ── What it says ── */

$html = LoginUrlNotice::render( $active['url'], 'https://example.test/wp-admin/admin-post.php?action=x&_wpnonce=abc', LoginUrlNotice::settings_url() );
$assert( 'render: full address, copy button, explanation, link to Security > Login, dismiss link', false !== strpos( $html, 'https://example.test/pobyty/moje-prihlaseni/' ) && false !== strpos( $html, 'data-copy="https://example.test/pobyty/moje-prihlaseni/"' ) && false !== strpos( $html, 'wp-login.php' ) && false !== strpos( $html, 'tab=login' ) && false !== strpos( $html, '_wpnonce=abc' ) );
$assert( 'render: nothing is changed automatically and it says so', false !== strpos( $html, 'Nothing was changed automatically' ) );
$evil = LoginUrlNotice::render( '"><script>alert(1)</script>', '#', '#' );
$assert( 'render: the address is escaped', false === strpos( $evil, '<script>' ) );
$assert( 'the notice script exists and only touches the notice', is_file( $root . '/assets/js/login-url-notice.js' ) && false !== strpos( (string) file_get_contents( $root . '/assets/js/login-url-notice.js' ), 'uxstudio-login-url-notice' ) );

/* ── The login form must post to the custom address (found live: it posted to the closed wp-login.php) ── */

use UxStudio\Modules\SecurityOptimization\LoginUrl;
$base = 'https://example.test/pobyty/moje-prihlaseni/';
$assert( 'login url: the form action wp-login.php goes to the custom address', $base === LoginUrl::swap( 'https://example.test/pobyty/wp-login.php', 'wp-login.php', $base ) );
$assert( 'login url: query strings stay (lost password, checkemail, redirect_to)', $base . '?action=lostpassword' === LoginUrl::swap( 'x', 'wp-login.php?action=lostpassword', $base ) && $base . '?redirect_to=a%2Fb' === LoginUrl::swap( 'x', '/wp-login.php?redirect_to=a%2Fb', $base ) );
$assert( 'login url: other paths and look-alikes are untouched', 'U' === LoginUrl::swap( 'U', 'wp-admin/admin-ajax.php', $base ) && 'U' === LoginUrl::swap( 'U', 'wp-login.php.bak', $base ) && 'U' === LoginUrl::swap( 'U', 'xwp-login.php', $base ) );
$assert( 'login url: without a custom address nothing is rewritten', 'U' === LoginUrl::swap( 'U', 'wp-login.php', '' ) );

/* ── The module decides "in force" with the same conditions as its hooks ── */

$src = (string) file_get_contents( $root . '/includes/Modules/SecurityOptimization/Module.php' );
$assert( 'module: status function exists and the notice is registered from the module boot', 1 === preg_match( '/function login_lock_status\(\): array/', $src ) && false !== strpos( $src, 'LoginUrlNotice::register( $this )' ) );
$assert( 'module: the site_url filters that send the form to the custom address are hooked together with the lock', false !== strpos( $src, "add_filter( 'site_url', array( \$this, 'filter_login_site_url'" ) && false !== strpos( $src, "add_filter( 'network_site_url'" ) );
$assert( 'module: status covers off, empty slug, kill switch and the fail-open collision', 1 === preg_match( "/'off'.*'empty'.*'kill_switch'.*'collision'/s", substr( $src, (int) strpos( $src, 'function login_lock_status' ), 900 ) ) );

echo PHP_EOL . ( $failures ? "$failures FAILED" : 'ALL PASSED' ) . PHP_EOL;
exit( $failures ? 1 : 0 );
