<?php
// Test only: never runs over HTTP (the release zip also excludes tests/).
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { exit; }
/**
 * Standalone test of the one-time start of the migration wizard after activation (PLAN.md 26, phase F7):
 * decision matrix (single / bulk / WP-CLI / network / AJAX activation, spent flag, other administrator, Handoff,
 * an earlier migration, nothing to migrate) and the WordPress glue around it. Needs no WordPress and no database:
 *
 *   php tests/wizard-autostart.php
 *
 * @package UxStudio
 */

// phpcs:disable

define( 'ABSPATH', __DIR__ . '/' );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );
define( 'ARRAY_A', 'ARRAY_A' );

$root = dirname( __DIR__ );
define( 'UXSTUDIO_PATH', $root . '/' );
define( 'UXSTUDIO_FILE', '/plugins/ux-studio/ux-studio.php' );

$GLOBALS['uxs_options'] = array();
$GLOBALS['uxs_meta']    = array();
$GLOBALS['uxs_plugins'] = array();
$GLOBALS['uxs_caps']    = array( 'manage_options' => true );
$GLOBALS['uxs_user']    = 5;
$GLOBALS['uxs_ajax']    = false;
$GLOBALS['uxs_admin_network'] = false;

class UxsRedirect extends Exception {}

class FakeWpdb {
	public $prefix = 'wp_';
	public $options = 'wp_options';
	public $posts = 'wp_posts';
	public $postmeta = 'wp_postmeta';
	public $usermeta = 'wp_usermeta';
	public $terms = 'wp_terms';
	public $term_taxonomy = 'wp_term_taxonomy';
	public $term_relationships = 'wp_term_relationships';
	public $users = 'wp_users';
	public function insert( $table, $data ) { return 1; }
	public function replace( $table, $data ) { return 1; }
	public function delete( $table, $where ) { return 1; }
	public function esc_like( $s ) { return $s; }
	public function prepare( $sql, ...$args ) { return $sql; }
	public function get_var( $sql ) { return null; }
	public function get_col( $sql ) { return array(); }
	public function get_results( $sql, $output = null ) { return array(); }
	public function query( $sql ) { return 0; }
}
$GLOBALS['wpdb'] = new FakeWpdb();

function get_option( $name, $default = false ) { return array_key_exists( $name, $GLOBALS['uxs_options'] ) ? $GLOBALS['uxs_options'][ $name ] : $default; }
function update_option( $name, $value, $autoload = null ) { $GLOBALS['uxs_options'][ $name ] = $value; return true; }
function add_option( $name, $value = '', $dep = '', $autoload = null ) { if ( array_key_exists( $name, $GLOBALS['uxs_options'] ) ) { return false; } $GLOBALS['uxs_options'][ $name ] = $value; return true; }
function delete_option( $name ) { unset( $GLOBALS['uxs_options'][ $name ] ); return true; }
function get_transient( $k ) { return $GLOBALS['uxs_transients'][ $k ] ?? false; }
function set_transient( $k, $v, $ttl = 0 ) { $GLOBALS['uxs_transients'][ $k ] = $v; return true; }
function delete_transient( $k ) { unset( $GLOBALS['uxs_transients'][ $k ] ); return true; }
function get_user_meta( $uid, $key, $single = false ) { return $GLOBALS['uxs_meta'][ $uid ][ $key ] ?? ''; }
function update_user_meta( $uid, $key, $value ) { $GLOBALS['uxs_meta'][ $uid ][ $key ] = $value; return true; }
function apply_filters( $hook, $value, ...$args ) { return $value; }
function do_action( $hook, ...$args ) {}
function add_action( $hook, $cb, $prio = 10, $args = 1 ) { $GLOBALS['uxs_hooks'][] = $hook; }
function __( $text, $domain = '' ) { return $text; }
function _n( $single, $plural, $n, $domain = '' ) { return 1 === $n ? $single : $plural; }
function is_wp_error( $x ) { return false; }
function current_time( $type ) { return '2026-10-21 12:00:00'; }
function get_current_user_id() { return $GLOBALS['uxs_user']; }
function current_user_can( $cap ) { return ! empty( $GLOBALS['uxs_caps'][ $cap ] ); }
function wp_doing_ajax() { return $GLOBALS['uxs_ajax']; }
function wp_doing_cron() { return false; }
function is_network_admin() { return $GLOBALS['uxs_admin_network']; }
function sanitize_key( $s ) { return strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', (string) $s ) ); }
function sanitize_text_field( $s ) { return trim( strip_tags( (string) $s ) ); }
function wp_unslash( $s ) { return $s; }
function plugin_basename( $file ) { return 'ux-studio/ux-studio.php'; }
function admin_url( $p = '' ) { return 'https://example.test/wp-admin/' . $p; }
function wp_safe_redirect( $url ) { throw new UxsRedirect( $url ); }
function wp_roles() { return (object) array( 'roles' => array( 'administrator' => array( 'capabilities' => array() ) ) ); }
function get_plugins() { return $GLOBALS['uxs_plugins']; }
function maybe_serialize( $v ) { return is_scalar( $v ) ? (string) $v : serialize( $v ); }
function home_url( $p = '' ) { return 'https://example.test' . $p; }
function site_url( $p = '' ) { return 'https://example.test' . $p; }
function trailingslashit( $s ) { return rtrim( $s, '/' ) . '/'; }
function untrailingslashit( $s ) { return rtrim( $s, '/' ); }
function absint( $v ) { return abs( (int) $v ); }

require_once $root . '/includes/Autoloader.php';
\UxStudio\Autoloader::register();

use UxStudio\Core\Migration\AutoStart;
use UxStudio\Core\Migration\History;

$failures = 0;
$assert   = static function ( string $name, bool $ok, string $detail = '' ) use ( &$failures ): void {
	if ( ! $ok ) {
		++$failures;
	}
	echo ( $ok ? 'PASS ' : 'FAIL ' ) . $name . ( $ok || '' === $detail ? '' : ' => ' . $detail ) . PHP_EOL;
};

/* ── 1) The activation hook: when is the offer queued? ── */

$self = 'ux-studio/ux-studio.php';
$act  = array( 'network_wide' => false, 'cli' => false, 'ajax' => false, 'action' => 'activate', 'plugin' => $self, 'self' => $self, 'flag' => '' );
$d    = static fn( array $over ): string => AutoStart::decide_activation( $over + $act );

$assert( 'activation: a single click on "Activate" queues the offer', 'queue' === $d( array() ) );
$assert( 'activation: the folder name may differ (junction / renamed folder), the file name decides', 'queue' === $d( array( 'plugin' => 'ux-studio-csp/ux-studio.php' ) ) );
$assert( 'activation: bulk "Activate" from the list does not', 'bulk' === $d( array( 'action' => 'activate-selected' ) ) );
$assert( 'activation: the activate-multi action does not', 'bulk' === $d( array( 'action' => 'activate-multi' ) ) );
$assert( 'activation: WP-CLI does not', 'cli' === $d( array( 'cli' => true, 'action' => '' ) ) );
$assert( 'activation: network-wide does not', 'network' === $d( array( 'network_wide' => true ) ) );
$assert( 'activation: AJAX does not', 'ajax' === $d( array( 'ajax' => true ) ) );
$assert( 'activation: activation of another plugin in the same request (action points elsewhere) does not', 'not_ui' === $d( array( 'plugin' => 'other/other.php' ) ) );
$assert( 'activation: no action at all (activated from code) does not', 'not_ui' === $d( array( 'action' => '', 'plugin' => '' ) ) );
$assert( 'activation: an already spent / queued flag never queues again', 'already' === $d( array( 'flag' => 'done' ) ) && 'already' === $d( array( 'flag' => 'pending' ) ) && 'already' === $d( array( 'flag' => 'skipped' ) ) );

/* ── 2) The next admin request: redirect or not? ── */

$req = array( 'flag' => 'pending', 'can_manage' => true, 'same_user' => true, 'count' => 3 );
$r   = static fn( array $over ): string => AutoStart::decide_redirect( $over + $req );

$assert( 'redirect: queued, administrator, something to migrate -> go', 'go' === $r( array() ) );
$assert( 'redirect: nothing queued -> none', 'none' === $r( array( 'flag' => '' ) ) && 'none' === $r( array( 'flag' => 'done' ) ) && 'none' === $r( array( 'flag' => 'skipped' ) ) );
$assert( 'redirect: nothing to migrate -> the new-site starter pack (mode=new)', 'new' === $r( array( 'count' => 0 ) ) );
$assert( 'redirect: the scan is lazy, it is not run for requests that cannot redirect', 'wait' === $r( array( 'ajax' => true, 'count' => static function (): int { throw new RuntimeException( 'scan must not run' ); } ) ) );
$assert( 'redirect: the scan is a callable and its answer counts', 'go' === $r( array( 'count' => static fn(): int => 2 ) ) && 'new' === $r( array( 'count' => static fn(): int => 0 ) ) );
foreach ( array( 'ajax', 'cron', 'rest', 'cli', 'network_admin' ) as $kind ) {
	$assert( "redirect: a $kind request waits (offer kept for a normal admin page)", 'wait' === $r( array( $kind => true ) ) );
}
$assert( 'redirect: a user without manage_options waits', 'wait' === $r( array( 'can_manage' => false ) ) );
$assert( 'redirect: another administrator than the one who activated waits', 'wait' === $r( array( 'same_user' => false ) ) );
$assert( 'redirect: the bulk-activation screen (activate-multi) spends the offer without redirect', 'drop' === $r( array( 'activate_multi' => true ) ) );
$assert( 'redirect: Handoff just took over from UX1 -> no redirect, its notice links to the wizard instead', 'drop' === $r( array( 'handoff' => true ) ) );
$assert( 'redirect: a migration ran before -> no redirect', 'drop' === $r( array( 'ran' => true ) ) );
$assert( 'redirect: the starter pack was applied before -> no redirect', 'drop' === $r( array( 'starter_applied' => true, 'count' => 0 ) ) );

$assert( 'count_migratable: active plugins and plugins that hold data count, inactive empty and data-only settings do not', 2 === AutoStart::count_migratable( array(
	array( 'status' => 'active', 'has_data' => false ),
	array( 'status' => 'inactive', 'has_data' => true ),
	array( 'status' => 'inactive', 'has_data' => false ),
	array( 'status' => 'data_only', 'has_data' => false ),
) ) );

/* ── 3) The WordPress glue ── */

$run_activation = static function ( array $get, array $request, bool $network = false, bool $cli = false ): void {
	$_GET     = $get;
	$_REQUEST = $request;
	AutoStart::on_activation( $network );
};

$GLOBALS['uxs_options'] = array();
$run_activation( array( 'action' => 'activate', 'plugin' => $self ), array( 'action' => 'activate', 'plugin' => $self ) );
$assert( 'glue: single activation sets the flag and remembers the user', 'pending' === get_option( AutoStart::FLAG ) && 5 === get_option( AutoStart::FLAG . '_user' ) );

$GLOBALS['uxs_options'] = array();
$run_activation( array(), array( 'action' => 'activate-selected' ) );
$assert( 'glue: bulk activation leaves no flag', false === get_option( AutoStart::FLAG ) );
$GLOBALS['uxs_options'] = array();
$run_activation( array( 'action' => 'activate', 'plugin' => $self ), array( 'action' => 'activate' ), true );
$assert( 'glue: network activation leaves no flag', false === get_option( AutoStart::FLAG ) );

// A site with a plugin the wizard can take over (Classic Editor is active and has a source).
$site = static function ( array $options ): void {
	$GLOBALS['uxs_options'] = $options + array( 'active_plugins' => array( 'classic-editor/classic-editor.php', 'ux-studio/ux-studio.php' ), 'uxstudio_active_modules' => array() );
};
$GLOBALS['uxs_plugins'] = array( 'classic-editor/classic-editor.php' => array( 'Name' => 'Classic Editor', 'Version' => '1.6' ) );
$_GET = array();

$site( array( AutoStart::FLAG => 'pending', AutoStart::FLAG . '_user' => 5 ) );
$target = '';
try {
	AutoStart::maybe_redirect();
} catch ( UxsRedirect $e ) {
	$target = $e->getMessage();
}
$assert( 'glue: the first admin request redirects to the wizard', 'https://example.test/wp-admin/admin.php?page=ux-studio#/wizard' === $target, $target );
$assert( 'glue: the offer is spent by that request', 'done' === get_option( AutoStart::FLAG ) && false === get_option( AutoStart::FLAG . '_user' ) );
$target = '';
try {
	AutoStart::maybe_redirect();
} catch ( UxsRedirect $e ) {
	$target = $e->getMessage();
}
$assert( 'glue: the second request does not redirect again', '' === $target );

$site( array( AutoStart::FLAG => 'pending', AutoStart::FLAG . '_user' => 5, 'uxstudio_handoff_offer_delete' => '1' ) );
$target = '';
try {
	AutoStart::maybe_redirect();
} catch ( UxsRedirect $e ) {
	$target = $e->getMessage();
}
$assert( 'glue: after a Handoff there is no redirect, and the offer is spent', '' === $target && 'skipped' === get_option( AutoStart::FLAG ) );

$site( array( AutoStart::FLAG => 'pending', AutoStart::FLAG . '_user' => 5, 'active_plugins' => array( 'ux-studio/ux-studio.php' ) ) );
$GLOBALS['uxs_plugins'] = array();
$target = '';
try {
	AutoStart::maybe_redirect();
} catch ( UxsRedirect $e ) {
	$target = $e->getMessage();
}
$assert( 'glue: a site with nothing to migrate is sent to the new-site wizard, once', 'https://example.test/wp-admin/admin.php?page=ux-studio#/wizard?mode=new' === $target && 'done' === get_option( AutoStart::FLAG ), $target );
$target = '';
try {
	AutoStart::maybe_redirect();
} catch ( UxsRedirect $e ) {
	$target = $e->getMessage();
}
$assert( 'glue: ... and not again', '' === $target );
$site( array( AutoStart::FLAG => 'pending', AutoStart::FLAG . '_user' => 5, 'active_plugins' => array( 'ux-studio/ux-studio.php' ), 'uxstudio_starter_applied' => 123 ) );
$target = '';
try {
	AutoStart::maybe_redirect();
} catch ( UxsRedirect $e ) {
	$target = $e->getMessage();
}
$assert( 'glue: an applied starter pack means no redirect', '' === $target && 'skipped' === get_option( AutoStart::FLAG ) );
$GLOBALS['uxs_plugins'] = array( 'classic-editor/classic-editor.php' => array( 'Name' => 'Classic Editor', 'Version' => '1.6' ) );

$site( array( AutoStart::FLAG => 'pending', AutoStart::FLAG . '_user' => 9 ) );
$target = '';
try {
	AutoStart::maybe_redirect();
} catch ( UxsRedirect $e ) {
	$target = $e->getMessage();
}
$assert( 'glue: another administrator does not take the redirect, the offer stays', '' === $target && 'pending' === get_option( AutoStart::FLAG ) );

$GLOBALS['uxs_ajax'] = true;
$site( array( AutoStart::FLAG => 'pending', AutoStart::FLAG . '_user' => 5 ) );
$target = '';
try {
	AutoStart::maybe_redirect();
} catch ( UxsRedirect $e ) {
	$target = $e->getMessage();
}
$assert( 'glue: an AJAX request (heartbeat) keeps the offer for a real page', '' === $target && 'pending' === get_option( AutoStart::FLAG ) );
$GLOBALS['uxs_ajax'] = false;

/* ── 4) The hint on the Modules screen ── */

$site( array() );
$GLOBALS['uxs_transients'] = array();
$hint = AutoStart::hint( 5 );
$assert( 'hint: shown when a plugin can be taken over and no migration ever ran', $hint['show'] && $hint['count'] >= 1, json_encode( $hint ) );
AutoStart::dismiss_hint( 5 );
$assert( 'hint: hidden for the user who dismissed it, still shown to others', ! AutoStart::hint( 5 )['show'] && AutoStart::hint( 6 )['show'] );
History::put( array( 'id' => 'r1', 'started' => time(), 'sources' => array() ) );
$assert( 'hint: hidden for everybody once a migration ran', ! AutoStart::hint( 6 )['show'] );
$site( array( 'active_plugins' => array( 'ux-studio/ux-studio.php' ) ) );
$GLOBALS['uxs_plugins'] = array();
AutoStart::flush_hint_cache(); // A plugin was deactivated or deleted: the cached count is dropped.
$assert( 'hint: hidden when there is nothing to take over', ! AutoStart::hint( 7 )['show'] );
$assert( 'hint: offers the starter pack ("Set up new site") while it was never applied, also after a migration', true === AutoStart::hint( 7 )['starter'] && true === AutoStart::hint( 6 )['starter'] );
$assert( 'hint: a user who dismissed the hint is not offered the starter pack either', false === AutoStart::hint( 5 )['starter'] );
update_option( 'uxstudio_starter_applied', time() );
$assert( 'hint: no starter offer once the pack was applied', false === AutoStart::hint( 7 )['starter'] );

echo PHP_EOL . ( $failures ? "$failures FAILED" : 'ALL PASSED' ) . PHP_EOL;
exit( $failures ? 1 : 0 );
