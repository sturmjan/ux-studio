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
use UxStudio\Core\Upgrade;
use UxStudio\Modules\SecurityOptimization\LoginUrl;

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
	// A site that had the custom login address configured BEFORE this release (the upgrade routine recorded it).
	$GLOBALS['uxs_options'] = array( 'admin_email' => 'owner@example.test', 'blogname' => 'Test &amp; site', Upgrade::LOGIN_LOCK_OPTION => array( 'configured' => true, 'time' => 1 ) );
	$GLOBALS['uxs_meta']    = array();
	$GLOBALS['uxs_log']     = array();
	$mails                  = array();
};
$active = array( 'active' => true, 'slug' => 'moje-prihlaseni', 'url' => 'https://example.test/pobyty/moje-prihlaseni/', 'reason' => '' );
$off    = array( 'active' => false, 'slug' => '', 'url' => '', 'reason' => 'off' );

/* ── Decision ── */

$assert( 'decide: first load with the lock in force -> notify', 'notify' === LoginUrlNotice::decide( array( 'handled' => false, 'active' => true, 'legacy' => true ) ) );
$assert( 'decide: first load with the lock off (or fail-open collision, or kill switch) -> only record', 'record' === LoginUrlNotice::decide( array( 'handled' => false, 'active' => false, 'legacy' => true ) ) );
$assert( 'decide: after the first load -> done, whatever the lock does', 'done' === LoginUrlNotice::decide( array( 'handled' => true, 'active' => true, 'legacy' => true ) ) && 'done' === LoginUrlNotice::decide( array( 'handled' => true, 'active' => false ) ) );
$assert( 'decide: lock set up for the first time AFTER the update (not configured before) -> only record, no e-mail', 'record' === LoginUrlNotice::decide( array( 'handled' => false, 'active' => true, 'legacy' => false ) ) );

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

/* ── Only sites that had the address configured before the update ── */

$reset();
unset( $GLOBALS['uxs_options'][ Upgrade::LOGIN_LOCK_OPTION ] );
$assert( 'no upgrade marker (fresh 0.6.0 site): the first load with the lock in force sends nothing', 'record' === LoginUrlNotice::handle_first_load( $active ) && 0 === count( $mails ) && ! LoginUrlNotice::visible_for( 1 ) );

// The upgrade routine reads the settings as the PREVIOUS release left them.
$reset();
unset( $GLOBALS['uxs_options'][ Upgrade::LOGIN_LOCK_OPTION ] );
$GLOBALS['uxs_options']['uxstudio_security_optimization'] = array( 'login_url_enabled' => true, 'login_url' => 'moje-prihlaseni' );
Upgrade::record_login_lock();
$assert( 'upgrade routine: lock configured before the update is remembered', true === Upgrade::login_lock_was_configured() );
$GLOBALS['uxs_options']['uxstudio_security_optimization'] = array( 'login_url_enabled' => false, 'login_url' => '' );
Upgrade::record_login_lock();
$assert( 'upgrade routine: the marker is written once, a later change of the setting does not rewrite it', true === Upgrade::login_lock_was_configured() );

$reset();
unset( $GLOBALS['uxs_options'][ Upgrade::LOGIN_LOCK_OPTION ] );
$GLOBALS['uxs_options']['uxstudio_security_optimization'] = array( 'login_url_enabled' => true, 'login_url' => '/' );
Upgrade::record_login_lock();
$assert( 'upgrade routine: enabled but empty slug counts as not configured', false === Upgrade::login_lock_was_configured() );
$GLOBALS['uxs_options']['uxstudio_security_optimization'] = array( 'login_url_enabled' => true, 'login_url' => 'moje' );
$assert( 'user configures the lock AFTER the update: still no e-mail (marker says not configured before)', 'record' === LoginUrlNotice::handle_first_load( $active ) && 0 === count( $mails ) );

/* ── Wording is neutral (the lock may already have worked in 0.5.0) ── */

$reset();
LoginUrlNotice::handle_first_load( $active );
$assert( 'mail: does not claim the address changed', false === stripos( $mails[0]['subject'] . $mails[0]['body'], 'changed' ) && false !== strpos( $mails[0]['body'], 'nothing changes for you' ), $mails[0]['subject'] );
$assert( 'notice: does not claim the address changed', false === stripos( LoginUrlNotice::render( $active['url'], '#', '#' ), 'has changed' ) );

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

$base = 'https://example.test/pobyty/moje-prihlaseni/';
$assert( 'login url: the form action wp-login.php goes to the custom address', $base === LoginUrl::swap( 'https://example.test/pobyty/wp-login.php', 'wp-login.php', $base ) );
$assert( 'login url: query strings stay (lost password, checkemail, redirect_to)', $base . '?action=lostpassword' === LoginUrl::swap( 'x', 'wp-login.php?action=lostpassword', $base ) && $base . '?redirect_to=a%2Fb' === LoginUrl::swap( 'x', '/wp-login.php?redirect_to=a%2Fb', $base ) );
$assert( 'login url: other paths and look-alikes are untouched', 'U' === LoginUrl::swap( 'U', 'wp-admin/admin-ajax.php', $base ) && 'U' === LoginUrl::swap( 'U', 'wp-login.php.bak', $base ) && 'U' === LoginUrl::swap( 'U', 'xwp-login.php', $base ) );
$assert( 'login url: without a custom address nothing is rewritten', 'U' === LoginUrl::swap( 'U', 'wp-login.php', '' ) );

/* ── Public wp-login.php actions and permalinks ── */

$assert( 'postpass (password form of a protected post) is a public action, with or without a leading slash', LoginUrl::is_public_action( 'wp-login.php?action=postpass' ) && LoginUrl::is_public_action( '/wp-login.php?action=postpass' ) && LoginUrl::is_public_action( 'wp-login.php?foo=1&action=postpass' ) );
$assert( 'logout, lostpassword, rp, resetpass, confirmaction and the plain login are NOT public (they are rewritten to the custom address)', ! LoginUrl::is_public_action( 'wp-login.php?action=logout' ) && ! LoginUrl::is_public_action( 'wp-login.php?action=rp' ) && ! LoginUrl::is_public_action( 'wp-login.php?action=resetpass' ) && ! LoginUrl::is_public_action( 'wp-login.php?action=confirmaction' ) && ! LoginUrl::is_public_action( 'wp-login.php?action=lostpassword' ) && ! LoginUrl::is_public_action( 'wp-login.php' ) );
$assert( 'look-alikes of postpass and other files are not public', ! LoginUrl::is_public_action( 'wp-login.php?action=postpass2' ) && ! LoginUrl::is_public_action( 'wp-login.php?action[]=postpass' ) && ! LoginUrl::is_public_action( 'wp-admin/admin-ajax.php?action=postpass' ) );
$assert( 'live postpass: only exact lowercase action, POST and post_password', LoginUrl::is_public_request( 'postpass', 'POST', true ) && ! LoginUrl::is_public_request( 'POSTPASS', 'POST', true ) && ! LoginUrl::is_public_request( 'post.pass', 'POST', true ) && ! LoginUrl::is_public_request( 'postpass', 'GET', true ) && ! LoginUrl::is_public_request( 'postpass', 'POST', false ) && ! LoginUrl::is_public_request( 'foo&action=postpass', 'POST', true ) && ! LoginUrl::is_public_request( 'postpass ', 'POST', true ) );
/* ── Redirects back to wp-login.php (lost password, registration) must land on the custom address ── */

$custom = 'https://www.chalupyroubal.cz/administrace/';
$assert( 'redirect: the live bug - /administrace/wp-login.php?checkemail=confirm becomes /administrace/?checkemail=confirm', $custom . '?checkemail=confirm' === LoginUrl::fix_redirect( '/administrace/wp-login.php?checkemail=confirm', $custom ) );
$assert( 'redirect: the same as an absolute URL on the same host', $custom . '?checkemail=registered' === LoginUrl::fix_redirect( 'https://www.chalupyroubal.cz/administrace/wp-login.php?checkemail=registered', $custom ) );
$assert( 'redirect: the bare relative wp-login.php?checkemail=confirm is repaired while the lock is in force', $custom . '?checkemail=confirm' === LoginUrl::fix_redirect( 'wp-login.php?checkemail=confirm', $custom, true ) );
$assert( 'redirect: the bare relative form is left alone when wp-login.php is open (bare=false, pending)', 'wp-login.php?checkemail=confirm' === LoginUrl::fix_redirect( 'wp-login.php?checkemail=confirm', $custom, false ) );
$assert( 'redirect: the artifact is repaired even when bare=false (it can never be a real file)', $custom . '?checkemail=confirm' === LoginUrl::fix_redirect( '/administrace/wp-login.php?checkemail=confirm', $custom, false ) );
$assert( 'redirect: query and fragment survive, no query gives the plain address', $custom . '?a=1&b=2#x' === LoginUrl::fix_redirect( '/administrace/wp-login.php?a=1&b=2#x', $custom ) && $custom === LoginUrl::fix_redirect( '/administrace/wp-login.php', $custom ) );
$assert( 'redirect: other hosts, other paths, the real root wp-login.php and look-alikes are untouched', 'https://evil.test/administrace/wp-login.php?x=1' === LoginUrl::fix_redirect( 'https://evil.test/administrace/wp-login.php?x=1', $custom ) && '/wp-login.php?x=1' === LoginUrl::fix_redirect( '/wp-login.php?x=1', $custom ) && '/other/wp-login.php' === LoginUrl::fix_redirect( '/other/wp-login.php', $custom ) && '/administrace/wp-login.php.bak' === LoginUrl::fix_redirect( '/administrace/wp-login.php.bak', $custom ) && 'wp-login.phpx?a=1' === LoginUrl::fix_redirect( 'wp-login.phpx?a=1', $custom ) && '/wp-admin/' === LoginUrl::fix_redirect( '/wp-admin/', $custom ) );
$assert( 'redirect: the protected-post password action is never rewritten', '/administrace/wp-login.php?action=postpass' === LoginUrl::fix_redirect( '/administrace/wp-login.php?action=postpass', $custom ) && 'wp-login.php?action=postpass' === LoginUrl::fix_redirect( 'wp-login.php?action=postpass', $custom, true ) );
$assert( 'redirect: a site in a subdirectory (example.com/blog/my-login/) works too', 'https://example.test/blog/my-login/?checkemail=confirm' === LoginUrl::fix_redirect( '/blog/my-login/wp-login.php?checkemail=confirm', 'https://example.test/blog/my-login/' ) );
$assert( 'redirect: empty input and no custom address change nothing', '' === LoginUrl::fix_redirect( '', $custom ) && 'wp-login.php?x=1' === LoginUrl::fix_redirect( 'wp-login.php?x=1', '' ) );

$assert( 'pretty permalinks: /%postname%/ yes; plain and /index.php/ (PATHINFO) no', LoginUrl::permalinks_are_pretty( '/%postname%/' ) && LoginUrl::permalinks_are_pretty( '/blog/%year%/%postname%/' ) && ! LoginUrl::permalinks_are_pretty( '' ) && ! LoginUrl::permalinks_are_pretty( '/index.php/%postname%/' ) && ! LoginUrl::permalinks_are_pretty( '  ' ) );
$permalinks = LoginUrlNotice::render_permalinks_notice( 'https://example.test/wp-admin/options-permalink.php', LoginUrlNotice::settings_url() );
$assert( 'plain permalinks notice: explains it, links to Permalinks and to Security > Login, escapes', false !== strpos( $permalinks, 'options-permalink.php' ) && false !== strpos( $permalinks, 'tab=login' ) && false !== strpos( $permalinks, 'not enforced' ) && false === strpos( LoginUrlNotice::render_permalinks_notice( '"><script>', '#' ), '<script>' ) );

/* ── The module decides "in force" with the same conditions as its hooks ── */

$src = (string) file_get_contents( $root . '/includes/Modules/SecurityOptimization/Module.php' );
$assert( 'module: status function exists and the notice is registered from the module boot', 1 === preg_match( '/function login_lock_status\(\): array/', $src ) && false !== strpos( $src, 'LoginUrlNotice::register( $this )' ) );
$assert( 'module: wp_redirect is filtered while the lock is configured, and the filter uses the pending-aware status', false !== strpos( $src, "add_filter( 'wp_redirect', array( \$this, 'filter_login_redirect'" ) && false !== strpos( $src, 'LoginUrl::fix_redirect( $location, $lock[' ) );
$assert( 'module: the site_url filters that send the form to the custom address are hooked together with the lock', false !== strpos( $src, "add_filter( 'site_url', array( \$this, 'filter_login_site_url'" ) && false !== strpos( $src, "add_filter( 'network_site_url'" ) );
$assert( 'module: status covers off, empty slug, kill switch, plain permalinks and the fail-open collision', 1 === preg_match( "/'off'.*'empty'.*'kill_switch'.*'no_pretty_permalinks'.*'collision'/s", substr( $src, (int) strpos( $src, 'function login_lock_status' ), 1500 ) ) );
$assert( 'module: the direct-login block uses the same status (fails open on plain permalinks), lets postpass through, never filters postpass', false !== strpos( $src, '! $this->lock_status_cached()' . "['active']" ) && 1 === preg_match( '/function block_direct_wp_login.*is_public_request.*lock_status_cached/s', $src ) && 1 === preg_match( '/function filter_login_site_url.*is_public_action/s', $src ) );
$handler = substr( $src, (int) strpos( $src, 'function handle_custom_login_url' ), 3200 );
$assert( 'module: the custom login address is marked non-cacheable (DONOTCACHEPAGE + nocache headers) before wp-login.php is loaded', 1 === preg_match( "/DONOTCACHEPAGE.*nocache_headers\(\).*require ABSPATH \. 'wp-login\.php'/s", $handler ) );
$assert( 'module: wp-login.php is loaded with its global variables declared (no Undefined variable warnings)', 1 === preg_match( '/global \$error, \$interim_login, \$action, \$user_login;.*require ABSPATH \. .wp-login\.php./s', $handler ) );
$assert( 'module: the collision is logged at most once a day (transient)', 1 === preg_match( '/COLLISION_LOG_TRANSIENT.*DAY_IN_SECONDS.*login_url_collision/s', substr( $src, (int) strpos( $src, 'function handle_custom_login_url' ), 1200 ) ) );

echo PHP_EOL . ( $failures ? "$failures FAILED" : 'ALL PASSED' ) . PHP_EOL;
exit( $failures ? 1 : 0 );
