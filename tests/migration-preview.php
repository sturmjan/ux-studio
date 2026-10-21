<?php
/**
 * Standalone test of the safe preview and the switch (PLAN.md 26, phase F4): token signing, what a preview
 * request changes (plugins, modules, staged options, persistence guards), the smoke checks against a fake
 * loopback, the switch order and its exact undo. Needs no WordPress and no database:
 *
 *   php tests/migration-preview.php
 *
 * @package UxStudio
 */

// phpcs:disable

define( 'ABSPATH', __DIR__ . '/' );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );
define( 'YEAR_IN_SECONDS', 31536000 );
define( 'KB_IN_BYTES', 1024 );
define( 'ARRAY_A', 'ARRAY_A' );
define( 'AUTH_KEY', 'test-auth-key-0123456789' );
define( 'AUTH_SALT', 'test-auth-salt-9876543210' );

$root = dirname( __DIR__ );
define( 'UXSTUDIO_PATH', $root . '/' );
define( 'UXSTUDIO_FILE', $root . '/ux-studio.php' );
$mu_dir = $root . '/tests/_tmp_mu';
define( 'WPMU_PLUGIN_DIR', $mu_dir );

$GLOBALS['uxs_options'] = array();
$GLOBALS['uxs_posts']   = array();
$GLOBALS['uxs_meta']    = array( 'post' => array(), 'user' => array() );
$GLOBALS['uxs_filters'] = array();
$GLOBALS['uxs_log']     = array();
$GLOBALS['uxs_http']    = array();
$GLOBALS['uxs_plugins'] = array();

class WP_Error {
	public $code;
	public $message;
	public $data;
	public function __construct( $code = '', $message = '', $data = '' ) { $this->code = $code; $this->message = $message; $this->data = $data; }
	public function get_error_code() { return $this->code; }
	public function get_error_message() { return $this->message; }
	public function get_error_data() { return $this->data; }
}

class FakeWpdb {
	public $prefix = 'wp_';
	public $options = 'wp_options';
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

/* ── WordPress stubs ── */

function get_option( $name, $default = false ) {
	$value = array_key_exists( $name, $GLOBALS['uxs_options'] ) ? $GLOBALS['uxs_options'][ $name ] : $default;
	return array_key_exists( $name, $GLOBALS['uxs_options'] ) ? apply_filters( 'option_' . $name, $value ) : apply_filters( 'default_option_' . $name, $value );
}
function update_option( $name, $value, $autoload = null ) { $GLOBALS['uxs_log'][] = 'opt:' . $name; $GLOBALS['uxs_options'][ $name ] = $value; return true; }
function add_option( $name, $value = '', $dep = '', $autoload = null ) { $GLOBALS['uxs_options'][ $name ] = $value; return true; }
function delete_option( $name ) { unset( $GLOBALS['uxs_options'][ $name ] ); return true; }
function get_site_option( $name, $default = false ) { return $default; }
function add_filter( $hook, $cb, $priority = 10, $args = 1 ) { $GLOBALS['uxs_filters'][ $hook ][] = $cb; return true; }
function add_action( $hook, $cb, $priority = 10, $args = 1 ) { return add_filter( $hook, $cb, $priority, $args ); }
function apply_filters( $hook, $value, ...$args ) {
	foreach ( $GLOBALS['uxs_filters'][ $hook ] ?? array() as $cb ) {
		$value = $cb( $value, ...$args );
	}
	return $value;
}
function do_action( $hook, ...$args ) { $GLOBALS['uxs_log'][] = 'action:' . $hook; }
function __( $text, $domain = '' ) { return $text; }
function _n( $single, $plural, $n, $domain = '' ) { return 1 === $n ? $single : $plural; }
function is_wp_error( $x ) { return $x instanceof WP_Error; }
function current_time( $type ) { return '2026-10-21 12:00:00'; }
function get_current_user_id() { return 7; }
function current_user_can( $cap ) { return true; }
function wp_salt( $scheme = '' ) { return 'test-salt-' . $scheme; }
function sanitize_text_field( $s ) { return trim( strip_tags( (string) $s ) ); }
function sanitize_textarea_field( $s ) { return trim( strip_tags( (string) $s ) ); }
function sanitize_hex_color( $s ) { return preg_match( '/^#[0-9a-f]{3,6}$/i', (string) $s ) ? (string) $s : ''; }
function sanitize_key( $s ) { return strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', (string) $s ) ); }
function absint( $v ) { return abs( (int) $v ); }
function wp_kses_post( $s ) { return (string) $s; }
function maybe_serialize( $v ) { return is_scalar( $v ) ? (string) $v : serialize( $v ); }
function wp_json_encode( $v ) { return json_encode( $v ); }
function trailingslashit( $s ) { return rtrim( $s, '/' ) . '/'; }
function home_url( $p = '' ) { return 'https://example.test/site' . $p; }
function site_url( $p = '', $scheme = null ) { return 'https://example.test/site/' . ltrim( $p, '/' ); }
function admin_url( $p = '' ) { return 'https://example.test/site/wp-admin/' . ltrim( $p, '/' ); }
function rest_url( $p = '' ) { return 'https://example.test/site/wp-json/' . ltrim( $p, '/' ); }
function add_query_arg( $a, $b, $c = null ) { $args = is_array( $a ) ? $a : array( $a => $b ); $url = is_array( $a ) ? $b : $c; return $url . ( false === strpos( $url, '?' ) ? '?' : '&' ) . http_build_query( $args ); }
function wp_create_nonce( $a ) { return substr( md5( $a ), 0, 10 ); }
function wp_date( $fmt, $ts = null ) { return gmdate( $fmt, $ts ?? time() ); }
function wp_html_excerpt( $s, $n, $more = '' ) { return mb_substr( $s, 0, $n ) . ( mb_strlen( $s ) > $n ? $more : '' ); }
function wp_roles() { return (object) array( 'roles' => array() ); }
function get_plugins() { return $GLOBALS['uxs_plugins']; }
function wp_mkdir_p( $d ) { return is_dir( $d ) || mkdir( $d, 0777, true ); }
function wp_delete_file( $f ) { @unlink( $f ); }
function plugin_basename( $f ) { return 'ux-studio/ux-studio.php'; }
function get_posts( $args ) {
	$types = (array) ( $args['post_type'] ?? array() );
	return array_slice( array_values( array_filter( $GLOBALS['uxs_posts'], static fn( $p ) => in_array( $p->post_type, $types, true ) && 'publish' === $p->post_status ) ), 0, (int) ( $args['numberposts'] ?? 5 ) );
}
function get_permalink( $p ) { return 'https://example.test/site/?p=' . (int) $p->ID; }
function get_post( $id ) { return $GLOBALS['uxs_posts'][ (int) $id ] ?? null; }
function metadata_exists( $type, $id, $key ) { return isset( $GLOBALS['uxs_meta'][ $type ][ $id ][ $key ] ); }
function get_metadata( $type, $id, $key, $single = false ) { $v = $GLOBALS['uxs_meta'][ $type ][ $id ][ $key ] ?? array(); return $single ? ( $v ? $v[0] : '' ) : $v; }
function add_metadata( $type, $id, $key, $value, $unique = false ) { $GLOBALS['uxs_meta'][ $type ][ $id ][ $key ][] = $value; return true; }
function delete_post_meta( $id, $key ) { $had = isset( $GLOBALS['uxs_meta']['post'][ $id ][ $key ] ); unset( $GLOBALS['uxs_meta']['post'][ $id ][ $key ] ); return $had; }
function delete_user_meta( $id, $key ) { return false; }
function _get_cron_array() { return array(); }
function wp_next_scheduled( $hook, $args = array() ) { return false; }
function wp_remote_request( $url, $args ) { return $GLOBALS['uxs_http_fn']( $url, $args ); }
function wp_remote_retrieve_response_code( $r ) { return $r['code']; }
function wp_remote_retrieve_body( $r ) { return $r['body']; }
function wp_remote_retrieve_headers( $r ) { return $r['headers']; }

/* plugin activation: records the order, keeps the real option in step */
function deactivate_plugins( $plugins, $silent = false ) {
	foreach ( (array) $plugins as $p ) { $GLOBALS['uxs_log'][] = 'deactivate:' . $p; }
	$GLOBALS['uxs_options']['active_plugins'] = array_values( array_diff( (array) $GLOBALS['uxs_options']['active_plugins'], (array) $plugins ) );
}
function activate_plugin( $plugin ) {
	$GLOBALS['uxs_log'][] = 'activate:' . $plugin;
	$GLOBALS['uxs_options']['active_plugins'][] = $plugin;
	return null;
}

require_once $root . '/includes/Autoloader.php';
\UxStudio\Autoloader::register();

use UxStudio\Core\Migration\History;
use UxStudio\Core\Migration\Preview;
use UxStudio\Core\Migration\PreviewBoot;
use UxStudio\Core\Migration\PluginInfo;
use UxStudio\Core\Migration\Runner;
use UxStudio\Core\Migration\SettingsWriter;
use UxStudio\Core\Migration\Smoke;
use UxStudio\Core\Migration\Snapshot;
use UxStudio\Core\Migration\Staging;
use UxStudio\Core\Migration\Switcher;

$failures = 0;
$assert   = static function ( string $name, bool $ok, string $detail = '' ) use ( &$failures ): void {
	if ( ! $ok ) {
		++$failures;
	}
	echo ( $ok ? 'PASS ' : 'FAIL ' ) . $name . ( $ok || '' === $detail ? '' : ' => ' . $detail ) . PHP_EOL;
};

/** Fresh world: options, filters, log. */
$world = static function ( array $options = array() ): void {
	$GLOBALS['uxs_options'] = $options + array( 'uxstudio_module_db_versions' => array( 'core_migration' => 1 ) );
	$GLOBALS['uxs_filters'] = array();
	$GLOBALS['uxs_log']     = array();
	PreviewBoot::reset();
	PluginInfo::flush();
};

/* ═══ Token ═══ */

$now = 1_800_000_000;
$tok = PreviewBoot::make_token( 'rabc123', 7, $now + 1800, 'h' );
$assert( 'header token has five parts', 5 === count( explode( '.', $tok ) ), $tok );
$p = PreviewBoot::parse_token( $tok, $now );
$assert( 'header token verifies and carries run, user, expiry, scope', $p && 'rabc123' === $p['run'] && 7 === $p['uid'] && $now + 1800 === $p['exp'] && 'h' === $p['scope'] );
$assert( 'token expires exactly at its expiry', null !== PreviewBoot::parse_token( $tok, $now + 1799 ) && null === PreviewBoot::parse_token( $tok, $now + 1800 ) );
list( $r, $u, $e, $s, $sig ) = explode( '.', $tok );
$assert( 'tampered user id is refused', null === PreviewBoot::parse_token( implode( '.', array( $r, 8, $e, $s, $sig ) ), $now ) );
$assert( 'tampered run id is refused', null === PreviewBoot::parse_token( implode( '.', array( 'rother', $u, $e, $s, $sig ) ), $now ) );
$assert( 'extended expiry is refused', null === PreviewBoot::parse_token( implode( '.', array( $r, $u, $e + 99999, $s, $sig ) ), $now ) );
$assert( 'header token cannot be turned into a cookie token', null === PreviewBoot::parse_token( implode( '.', array( $r, $u, $e, 'c', $sig ) ), $now, 'bind' ) );
$assert( 'tampered signature is refused', null === PreviewBoot::parse_token( implode( '.', array( $r, $u, $e, $s, strrev( $sig ) ) ), $now ) );
$assert( 'garbage is refused', null === PreviewBoot::parse_token( 'nonsense', $now ) && null === PreviewBoot::parse_token( '', $now ) && null === PreviewBoot::parse_token( 'a.b.c.d.e', $now ) );
$assert( 'bad run id gives no token', '' === PreviewBoot::make_token( 'R-1;DROP', 7, $now + 5, 'h' ) );

$cookie_value = 'admin|' . ( $now + 86400 ) . '|SESSIONTOKEN123|abcdef';
$bind         = PreviewBoot::session_bind( array( 'wordpress_logged_in_deadbeef' => $cookie_value ) );
$assert( 'session binding comes from the logged-in cookie', 64 === strlen( $bind ) );
$assert( 'no logged-in cookie, no binding', '' === PreviewBoot::session_bind( array( 'other' => 'x' ) ) );
$ctok = PreviewBoot::make_token( 'rabc123', 7, $now + 1800, 'c', $bind );
$assert( 'cookie token verifies for the session it was made for', null !== PreviewBoot::parse_token( $ctok, $now, $bind ) );
$other = PreviewBoot::session_bind( array( 'wordpress_logged_in_deadbeef' => 'admin|1|OTHERSESSION|x' ) );
$assert( 'cookie token is refused in another session', null === PreviewBoot::parse_token( $ctok, $now, $other ) );
$assert( 'cookie token is refused without a session', null === PreviewBoot::parse_token( $ctok, $now, '' ) );
$assert( 'a cookie token cannot be made without a binding', '' === PreviewBoot::make_token( 'rabc123', 7, $now + 5, 'c', '' ) );

list( $t, $ch ) = PreviewBoot::request_token( array( 'HTTP_X_UXS_PREVIEW' => 'H' ), array( 'uxstudio_preview' => 'C' ) );
$assert( 'header wins over cookie', 'H' === $t && 'h' === $ch );
list( $t, $ch ) = PreviewBoot::request_token( array(), array( 'uxstudio_preview' => 'C' ) );
$assert( 'cookie is used without a header', 'C' === $t && 'c' === $ch );
$assert( 'no token at all', array( '', '' ) === PreviewBoot::request_token( array(), array() ) );

$assert( 'wizard REST routes are exempt from the cookie (pretty permalinks)', PreviewBoot::exempt_request( array( 'REQUEST_URI' => '/site/wp-json/uxstudio/v1/migration/switch' ), array() ) );
$assert( 'wizard REST routes are exempt from the cookie (rest_route)', PreviewBoot::exempt_request( array( 'REQUEST_URI' => '/site/index.php' ), array( 'rest_route' => '/uxstudio/v1/migration/status' ) ) );
$assert( 'exit action is exempt', PreviewBoot::exempt_request( array( 'REQUEST_URI' => '/site/wp-admin/admin-post.php' ), array( 'action' => 'uxstudio_preview_exit' ) ) );
$assert( 'other REST routes and pages are not exempt', ! PreviewBoot::exempt_request( array( 'REQUEST_URI' => '/site/wp-json/uxstudio/v1/modules' ), array() ) && ! PreviewBoot::exempt_request( array( 'REQUEST_URI' => '/site/' ), array() ) );

/* ═══ What a preview request changes ═══ */

$entry = static fn( array $extra = array() ) => array_merge(
	array(
		'run_id'  => 'rrun1',
		'user_id' => 7,
		'sources' => array( 'classic-editor' ),
		'plugins' => array( 'classic-editor/classic-editor.php', 'disable-comments/disable-comments.php' ),
		'modules' => array( 'classic-editor', 'security-optimization' ),
		'started' => $now,
		'expires' => $now + 1800,
		'results' => array(),
	),
	$extra
);
$staged = array(
	'created'     => $now,
	'settings'    => array( 'security-optimization' => array( 'disable_comments' => true, 'login_url' => 'moje-prihlaseni' ) ),
	'secrets'     => array( 'uxstudio_smtp_email_password' => array( 'module' => 'smtp-email', 'sealed' => 'uxs1:SEALEDVALUE' ) ),
	'options'     => array( 'uxstudio_admin_customiser_menu_config' => array( 'module' => 'admin-customiser', 'values' => array( 'hidden_items' => array( 'tools.php' ), 'item_roles' => array( 'themes.php' => array( 'administrator' ) ) ) ) ),
	'activations' => array(
		array( 'source' => 'redirection', 'kind' => 'redirect', 'ids' => array( '11', '12' ), 'data' => array() ),
		array( 'source' => 'code-snippets', 'kind' => 'snippet', 'ids' => array( '5' ), 'data' => array() ),
		array( 'source' => 'menu-icons', 'kind' => 'menu_icon', 'ids' => array( '101' ), 'data' => array( '101' => array( 'meta' => array( '_uxs_icon' => 'home' ), 'extra' => null, 'type' => 'post' ) ) ),
	),
);
$base_options = static fn() => array(
	'active_plugins'                  => array( 'a/a.php', 'classic-editor/classic-editor.php', 'disable-comments/disable-comments.php', 'ux-studio/ux-studio.php' ),
	'uxstudio_active_modules'         => array( 'activity-log', 'security-optimization' ),
	'uxstudio_security_optimization'  => array( 'limit_login_attempts' => true, 'login_url' => 'administrace', 'disable_comments' => false ),
	PreviewBoot::STATE_OPTION         => array( 'runs' => array( 'rrun1' => $entry() ) ),
	PreviewBoot::STAGED_OPTION        => array( 'rrun1' => $staged ),
	'cron'                            => array(),
);
$htok = static fn( int $uid = 7, int $exp = 0 ) => PreviewBoot::make_token( 'rrun1', $uid, $exp ?: $now + 1800, 'h' );

$world( $base_options() );
$assert( 'a plain request is not a preview', false === PreviewBoot::init( array(), array(), array(), $now ) && ! PreviewBoot::is_active() && ! Preview::is_active() );
$assert( 'a plain request registers nothing', array() === $GLOBALS['uxs_filters'] );

$world( $base_options() );
$assert( 'an invalid token does nothing', false === PreviewBoot::init( array( 'HTTP_X_UXS_PREVIEW' => 'rrun1.7.' . ( $now + 99 ) . '.h.' . str_repeat( 'a', 64 ) ), array(), array(), $now ) && array() === $GLOBALS['uxs_filters'] );

$world( $base_options() );
$assert( 'an expired token does nothing', false === PreviewBoot::init( array( 'HTTP_X_UXS_PREVIEW' => PreviewBoot::make_token( 'rrun1', 7, $now - 5, 'h' ) ), array(), array(), $now ) );

$world( $base_options() );
$assert( 'a token for another user than the preview belongs to does nothing', false === PreviewBoot::init( array( 'HTTP_X_UXS_PREVIEW' => $htok( 9 ) ), array(), array(), $now ) );

$world( $base_options() );
$GLOBALS['uxs_options'][ PreviewBoot::STATE_OPTION ]['runs']['rrun1']['expires'] = $now - 1;
$assert( 'a valid token of an expired preview does nothing', false === PreviewBoot::init( array( 'HTTP_X_UXS_PREVIEW' => $htok( 7, $now + 1800 ) ), array(), array(), $now ) );

$world( $base_options() );
unset( $GLOBALS['uxs_options'][ PreviewBoot::STATE_OPTION ] );
$assert( 'a valid token without any preview state does nothing', false === PreviewBoot::init( array( 'HTTP_X_UXS_PREVIEW' => $htok() ), array(), array(), $now ) );

$world( $base_options() );
$cookie_tok = PreviewBoot::make_token( 'rrun1', 7, $now + 1800, 'c', $bind );
$cookies    = array( 'uxstudio_preview' => $cookie_tok, 'wordpress_logged_in_deadbeef' => $cookie_value );
$assert( 'the wizard\'s own REST call is not a preview, cookie or not', false === PreviewBoot::init( array( 'REQUEST_URI' => '/site/wp-json/uxstudio/v1/migration/status' ), $cookies, array(), $now ) );

$world( $base_options() );
$assert( 'a browser with the cookie and the same session gets the preview', true === PreviewBoot::init( array( 'REQUEST_URI' => '/site/' ), $cookies, array(), $now ) && Preview::is_active() );

$world( $base_options() );
$assert( 'the cookie of another session gets nothing', false === PreviewBoot::init( array( 'REQUEST_URI' => '/site/' ), array( 'uxstudio_preview' => $cookie_tok, 'wordpress_logged_in_deadbeef' => 'x|1|OTHERSESSION|y' ), array(), $now ) );

$world( $base_options() );
$on = PreviewBoot::init( array( 'HTTP_X_UXS_PREVIEW' => $htok() ), array(), array(), $now );
$assert( 'a valid header token starts the preview', true === $on && 'rrun1' === PreviewBoot::context()['run'] );
$assert( 'the preview request defines its markers (no cache, no cron)', defined( 'UXSTUDIO_PREVIEW' ) && defined( 'DONOTCACHEPAGE' ) && defined( 'DISABLE_WP_CRON' ) );
$assert( 'old plugins vanish from active_plugins, the others and the order stay', array( 'a/a.php', 'ux-studio/ux-studio.php' ) === get_option( 'active_plugins' ), json_encode( get_option( 'active_plugins' ) ) );
$assert( 'the stored plugin list is not touched', 4 === count( $GLOBALS['uxs_options']['active_plugins'] ) );
$assert( 'old plugins vanish from the network list', array( 'x/x.php' => 1 ) === apply_filters( 'site_option_active_sitewide_plugins', array( 'x/x.php' => 1, 'classic-editor/classic-editor.php' => 2 ) ) );
$assert( 'target modules are added, enabled ones stay (no duplicates)', array( 'activity-log', 'security-optimization', 'classic-editor' ) === get_option( 'uxstudio_active_modules' ), json_encode( get_option( 'uxstudio_active_modules' ) ) );
$sec = get_option( 'uxstudio_security_optimization' );
$assert( 'staged values lie over the stored module option', true === $sec['disable_comments'] && 'moje-prihlaseni' === $sec['login_url'] && true === $sec['limit_login_attempts'] );
$assert( 'the stored module option is untouched', false === $GLOBALS['uxs_options']['uxstudio_security_optimization']['disable_comments'] && 'administrace' === $GLOBALS['uxs_options']['uxstudio_security_optimization']['login_url'] );
$assert( 'an option that never existed gets the staged values as its default', 'moje-prihlaseni' === ( apply_filters( 'default_option_uxstudio_security_optimization', array() )['login_url'] ?? '' ) );
$menu_cfg_preview = get_option( 'uxstudio_admin_customiser_menu_config', array( 'layout' => 'vertical' ) );
$assert( 'staged whole-option keys (menu config) lie over the stored option, other keys stay', array( 'tools.php' ) === $menu_cfg_preview['hidden_items'] && array( 'themes.php' => array( 'administrator' ) ) === $menu_cfg_preview['item_roles'] && 'vertical' === $menu_cfg_preview['layout'], json_encode( $menu_cfg_preview ) );
$assert( 'a staged whole option that does not exist yet gets the values as its default', array( 'tools.php' ) === ( apply_filters( 'default_option_uxstudio_admin_customiser_menu_config', array() )['hidden_items'] ?? null ) );
$assert( 'staged secrets are served sealed, in the storage format', 'uxs1:SEALEDVALUE' === apply_filters( 'pre_option_uxstudio_smtp_email_password', false ) );
$assert( 'redirect ids staged for the run count as active in the preview', Preview::includes( 'redirect', 11 ) && Preview::includes( 'redirect', '12' ) && ! Preview::includes( 'redirect', 99 ) && array( '11', '12' ) === Preview::ids( 'redirect' ) );
$assert( 'snippet ids staged for the run count as active in the preview', Preview::includes( 'snippet', 5 ) && ! Preview::includes( 'snippet', 6 ) );
$assert( 'staged post meta answers get_post_meta', 'home' === apply_filters( 'get_post_metadata', null, 101, '_uxs_icon', true ) && array( 'home' ) === apply_filters( 'get_post_metadata', null, 101, '_uxs_icon', false ) );
$assert( 'post meta of other objects and keys is not answered', null === apply_filters( 'get_post_metadata', null, 102, '_uxs_icon', true ) && null === apply_filters( 'get_post_metadata', null, 101, 'other', true ) );
$assert( 'real meta is never overridden', 'real' === apply_filters( 'get_post_metadata', 'real', 101, '_uxs_icon', true ) );
foreach ( array( 'pre_schedule_event', 'pre_reschedule_event', 'pre_unschedule_event', 'pre_clear_scheduled_hook', 'pre_unschedule_hook' ) as $hook ) {
	if ( false !== apply_filters( $hook, null ) ) {
		$assert( "$hook refuses", false );
	}
}
$assert( 'cron scheduling and unscheduling are refused', false === apply_filters( 'pre_schedule_event', null ) && false === apply_filters( 'pre_reschedule_event', null ) && false === apply_filters( 'pre_clear_scheduled_hook', null ) );

$guard = static fn( string $sql ) => apply_filters( 'query', $sql );
$assert( 'writes to our options are dropped', '' === $guard( "UPDATE wp_options SET option_value = 'x' WHERE option_name = 'uxstudio_security_optimization'" ) );
$assert( 'inserts of our options are dropped', '' === $guard( "INSERT INTO wp_options (option_name, option_value, autoload) VALUES ('uxstudio_migration_x', 'a', 'no') ON DUPLICATE KEY UPDATE option_value = 'a'" ) );
$assert( 'our transients are dropped', '' === $guard( "INSERT INTO `wp_options` (`option_name`, `option_value`) VALUES ('_transient_uxstudio_cache', 'a')" ) && '' === $guard( "DELETE FROM wp_options WHERE option_name = '_transient_timeout_uxstudio_cache'" ) );
$assert( 'writes to our tables are dropped', '' === $guard( "INSERT INTO wp_uxstudio_email_log (a) VALUES (1)" ) && '' === $guard( 'CREATE TABLE wp_uxstudio_new (a int)' ) && '' === $guard( 'UPDATE `wp_uxstudio_redirects` SET hits = hits + 1' ) );
$assert( 'writes to the plugin list are dropped', '' === $guard( "UPDATE wp_options SET option_value = 'a:0:{}' WHERE option_name = 'active_plugins'" ) );
$assert( 'our meta keys are dropped', '' === $guard( "INSERT INTO wp_usermeta (user_id, meta_key, meta_value) VALUES (1, 'uxstudio_prefs', 'x')" ) );
$assert( 'reads are never touched', "SELECT * FROM wp_options WHERE option_name = 'uxstudio_security_optimization'" === $guard( "SELECT * FROM wp_options WHERE option_name = 'uxstudio_security_optimization'" ) );
$assert( 'WordPress\'s own writes still go through (sessions, posts)', "UPDATE wp_usermeta SET meta_value = 'x' WHERE meta_key = 'session_tokens'" === $guard( "UPDATE wp_usermeta SET meta_value = 'x' WHERE meta_key = 'session_tokens'" ) && 'UPDATE wp_posts SET post_title = 1' === $guard( 'UPDATE wp_posts SET post_title = 1' ) );
$assert( 'the guard remembers what it dropped', count( PreviewBoot::blocked() ) >= 5 );

$world( $base_options() );
$GLOBALS['uxs_options'][ PreviewBoot::STAGED_OPTION ] = array();
PreviewBoot::init( array( 'HTTP_X_UXS_PREVIEW' => $htok() ), array(), array(), $now );
$assert( 'a preview without staged data still swaps plugins and modules', array( 'a/a.php', 'ux-studio/ux-studio.php' ) === get_option( 'active_plugins' ) && in_array( 'classic-editor', get_option( 'uxstudio_active_modules' ), true ) && array() === Preview::ids( 'redirect' ) );

$world( $base_options() );
$assert( 'outside a preview the engines see no staged ids', array() === Preview::ids( 'redirect' ) && ! Preview::includes( 'redirect', 11 ) );

/* ═══ Preview state, mu-plugin ═══ */

@mkdir( $mu_dir, 0777, true );
foreach ( glob( $mu_dir . '/*' ) ?: array() as $f ) { @unlink( $f ); }
$world(
	array(
		'active_plugins'          => array( 'classic-editor/classic-editor.php', 'disable-comments/disable-comments.php', 'ux-studio/ux-studio.php' ),
		'uxstudio_active_modules' => array( 'activity-log', 'security-optimization' ),
	)
);
$GLOBALS['uxs_plugins'] = array(
	'classic-editor/classic-editor.php'       => array( 'Name' => 'Classic Editor', 'Version' => '1.6' ),
	'disable-comments/disable-comments.php'   => array( 'Name' => 'Disable Comments', 'Version' => '2.5' ),
);
$assert( 'the preview is available when the mu-plugin folder is writable', true === Preview::availability()['ok'] );
$src = Preview::mu_source( 'ux-studio/ux-studio.php' );
file_put_contents( $mu_dir . '/lint.php', $src );
$lint = shell_exec( escapeshellarg( PHP_BINARY ) . ' -l ' . escapeshellarg( $mu_dir . '/lint.php' ) . ' 2>&1' );
@unlink( $mu_dir . '/lint.php' );
$assert( 'the generated mu-plugin is valid PHP', false !== strpos( (string) $lint, 'No syntax errors' ), (string) $lint );
$assert( 'the mu-plugin does nothing without a token and when UX Studio is not active', false !== strpos( $src, "empty( \$_COOKIE['uxstudio_preview'] )" ) && false !== strpos( $src, 'if ( ! $active )' ) && false !== strpos( $src, 'is_file( $boot )' ) );

// A run in the history, migrated, with prepared values.
History::put( array( 'id' => 'rrun1', 'started' => time() - 60, 'started_at' => '2026-10-21 12:00:00', 'finished' => time() - 30, 'user_id' => 7, 'sources' => array( array( 'id' => 'classic-editor', 'label' => 'Classic Editor', 'status' => 'done', 'written' => 1, 'staged' => 0 ), array( 'id' => 'disable-comments', 'label' => 'Disable Comments', 'status' => 'done', 'written' => 3, 'staged' => 3 ) ), 'errors' => 0, 'snapshot' => true, 'rolled_back' => 0 ) );
Staging::put_settings( 'rrun1', 'security-optimization', array( 'disable_comments' => true ) );

$started = Preview::start( 'rrun1', 7 );
$assert( 'start installs the mu-plugin and stores the preview', ! is_wp_error( $started ) && is_file( Preview::mu_path() ) && null !== Preview::entry( 'rrun1' ), is_wp_error( $started ) ? $started->get_error_message() : '' );
$assert( 'the preview removes the plugins of the run, adds its modules', array( 'classic-editor/classic-editor.php', 'disable-comments/disable-comments.php' ) === $started['plugins'] && array( 'classic-editor', 'security-optimization' ) === $started['modules'], json_encode( $started ) );
$assert( 'the preview lasts 30 minutes', PreviewBoot::TTL === $started['expires'] - $started['started'] );
$assert( 'start is refused for an unknown run', is_wp_error( Preview::start( 'rnope', 7 ) ) && 'uxstudio_migration_unknown_run' === Preview::start( 'rnope', 7 )->get_error_code() );

Preview::record_result( 'rrun1', array( 'id' => 'home', 'label' => 'Home', 'status' => 'ok', 'detail' => '' ) );
Preview::record_result( 'rrun1', array( 'id' => 'login', 'label' => 'Login', 'status' => 'fail', 'detail' => 'x' ) );
$hist = History::find( 'rrun1' );
$assert( 'test results are kept in the history and counted', 2 === count( $hist['preview']['results'] ) && 1 === $hist['preview']['counts']['ok'] && 1 === $hist['preview']['counts']['fail'] );

$second = $GLOBALS['uxs_options'][ PreviewBoot::STATE_OPTION ];
History::put( array( 'id' => 'rrun2', 'started' => time(), 'sources' => array( array( 'id' => 'classic-editor', 'status' => 'done' ) ), 'errors' => 0, 'snapshot' => true ) );
Preview::start( 'rrun2', 7 );
Preview::stop( 'rrun1' );
$assert( 'the mu-plugin stays while another preview runs', is_file( Preview::mu_path() ) && null === Preview::entry( 'rrun1' ) && null !== Preview::entry( 'rrun2' ) );
Preview::stop( 'rrun2' );
$assert( 'the mu-plugin goes with the last preview, the option too', ! is_file( Preview::mu_path() ) && ! array_key_exists( PreviewBoot::STATE_OPTION, $GLOBALS['uxs_options'] ) );
$assert( 'results survive the end of the preview', 2 === count( History::find( 'rrun1' )['preview']['results'] ) );

Preview::start( 'rrun1', 7 );
$gone = Preview::purge_expired( time() + PreviewBoot::TTL + 5 );
$assert( 'an expired preview is purged and the mu-plugin removed', array( 'rrun1' ) === $gone && ! is_file( Preview::mu_path() ) );
$assert( 'purging with no state removes a leftover file', ( file_put_contents( Preview::mu_path(), 'x' ) || true ) && array() === Preview::purge_expired() && ! is_file( Preview::mu_path() ) );

Preview::start( 'rrun1', 7 );
Preview::stop_all();
$assert( 'deactivation ends every preview and removes the file', ! is_file( Preview::mu_path() ) && array() === Preview::runs() );

$real_dir = WPMU_PLUGIN_DIR;
$assert( 'enter / exit urls carry the run and a nonce, in plain ampersands', false === strpos( Preview::enter_url( 'rrun1' ), '&amp;' ) && false !== strpos( Preview::exit_url( 'rrun1' ), '_wpnonce=' ) && false !== strpos( Preview::enter_url( 'rrun1' ), 'run=rrun1' ) );

/* ═══ Smoke tests against a fake loopback ═══ */

$GLOBALS['uxs_posts'] = array(
	1 => (object) array( 'ID' => 1, 'post_type' => 'post', 'post_status' => 'publish', 'post_title' => 'Hello' ),
	2 => (object) array( 'ID' => 2, 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'About' ),
);
$world(
	array(
		'active_plugins'          => array( 'classic-editor/classic-editor.php', 'disable-comments/disable-comments.php' ),
		'uxstudio_active_modules' => array( 'activity-log' ),
	)
);
$GLOBALS['uxs_plugins'] = array(
	'classic-editor/classic-editor.php'     => array( 'Name' => 'Classic Editor', 'Version' => '1.6' ),
	'disable-comments/disable-comments.php' => array( 'Name' => 'Disable Comments', 'Version' => '2.5' ),
);
History::put( array( 'id' => 'rsmoke', 'started' => time(), 'sources' => array( array( 'id' => 'classic-editor', 'status' => 'done' ), array( 'id' => 'disable-comments', 'status' => 'done' ) ), 'errors' => 0, 'snapshot' => true ) );
Staging::put_settings( 'rsmoke', 'security-optimization', array( 'disable_comments' => true, 'disable_comments_scope' => 'all' ) );
SettingsWriter::set_schema_resolver(
	static function ( string $m ) {
		return array(
			'classic-editor'        => array( array( 'key' => 'post_types', 'type' => 'multiselect', 'options' => array( 'post' => '', 'page' => '' ), 'default' => array() ) ),
			'security-optimization' => array(
				array( 'key' => 'disable_comments', 'type' => 'toggle', 'default' => false ),
				array( 'key' => 'disable_comments_scope', 'type' => 'select', 'options' => array( 'all' => '', 'selected' => '' ), 'default' => 'all' ),
				array( 'key' => 'disable_comments_post_types', 'type' => 'multiselect', 'options' => array( 'post' => '', 'page' => '' ), 'default' => array() ),
				array( 'key' => 'disable_comments_admin_ui', 'type' => 'toggle', 'default' => false ),
				array( 'key' => 'disable_comments_block_endpoints', 'type' => 'toggle', 'default' => false ),
				array( 'key' => 'disable_comments_hide_existing', 'type' => 'toggle', 'default' => false ),
				array( 'key' => 'login_url', 'type' => 'text', 'default' => '' ),
				array( 'key' => 'login_url_enabled', 'type' => 'toggle', 'default' => false ),
			),
		)[ $m ] ?? null;
	}
);
$GLOBALS['uxs_options']['disable_comments_options'] = array( 'remove_everywhere' => true );
$GLOBALS['uxs_options']['classic-editor-replace']   = 'classic';

$res = static function ( int $code, string $body = '<html>ok</html>', array $headers = array() ): array {
	return array( 'code' => $code, 'body' => $body, 'headers' => $headers );
};
$active_hdr = array( 'x-uxs-preview-active' => 'rsmoke' );
$GLOBALS['uxs_http_fn'] = static function ( string $url, array $args ) use ( &$res, $active_hdr ): array {
	$GLOBALS['uxs_http'][] = array( $url, $args );
	return $GLOBALS['uxs_http_next'] ?? $res( 200, '<html>ok</html>', isset( $args['headers']['X-UXS-Preview'] ) ? $active_hdr : array() );
};
$GLOBALS['uxs_http_next'] = null;

$plan = Smoke::plan( 'rsmoke' );
$ids  = array_column( $plan, 'id' );
$assert( 'plan: home, latest posts and pages, login, admin', in_array( 'home', $ids, true ) && in_array( 'post-1', $ids, true ) && in_array( 'page-2', $ids, true ) && in_array( 'login', $ids, true ) && in_array( 'admin', $ids, true ), implode( ',', $ids ) );
$assert( 'plan: source checks follow (editor, comments)', in_array( 'classic-editor:editor', $ids, true ) && in_array( 'disable-comments:comments', $ids, true ), implode( ',', $ids ) );

// Preview needed.
$gone = Smoke::run( 'rsmoke', 'home', 'preview' );
$assert( 'a check refuses to run without a live preview', is_wp_error( $gone ) && 'uxstudio_preview_expired' === $gone->get_error_code() );
Preview::start( 'rsmoke', 7 );
$GLOBALS['uxs_http'] = array();
$r = Smoke::run( 'rsmoke', 'home', 'preview' );
$assert( 'page check: baseline without the header, preview with it', 'ok' === $r['status'] && 2 === count( $GLOBALS['uxs_http'] ), json_encode( $r ) );
$with = array_values( array_filter( $GLOBALS['uxs_http'], static fn( $c ) => isset( $c[1]['headers']['X-UXS-Preview'] ) ) );
$without = array_values( array_filter( $GLOBALS['uxs_http'], static fn( $c ) => ! isset( $c[1]['headers']['X-UXS-Preview'] ) ) );
$assert( 'page check sends one request with and one without the header', 1 === count( $with ) && 1 === count( $without ) );
$assert( 'requests look like a browser and bypass caches', false !== strpos( $with[0][1]['user-agent'], 'Mozilla/5.0' ) && 'no-cache' === $with[0][1]['headers']['Cache-Control'] && false !== strpos( $with[0][0], 'uxs_cb=' ) && 0 === $with[0][1]['redirection'] );
$assert( 'the header token is a valid header-scope token for the preview user', null !== PreviewBoot::parse_token( $with[0][1]['headers']['X-UXS-Preview'], time() ) && 'h' === PreviewBoot::parse_token( $with[0][1]['headers']['X-UXS-Preview'], time() )['scope'] );
$assert( 'the result is stored for the switch', 'ok' === Preview::entry( 'rsmoke' )['results']['home']['status'] && 'ok' === History::find( 'rsmoke' )['preview']['results']['home']['status'] );

$GLOBALS['uxs_http_fn'] = static function ( string $url, array $args ) use ( $res, $active_hdr ): array {
	if ( isset( $args['headers']['X-UXS-Preview'] ) ) {
		return $res( 500, '<p>There has been a critical error on this website.</p>', $active_hdr );
	}
	return $res( 200 );
};
$r = Smoke::run( 'rsmoke', 'home', 'preview' );
$assert( 'a fatal error in the preview fails the check', 'fail' === $r['status'], json_encode( $r ) );
$GLOBALS['uxs_http_fn'] = static function ( string $url, array $args ) use ( $res, $active_hdr ): array {
	return isset( $args['headers']['X-UXS-Preview'] ) ? $res( 200, "<html><b>Fatal error</b>: Uncaught Error: x in /a.php:3\nStack trace:\n#0", $active_hdr ) : $res( 200 );
};
$assert( 'a fatal message inside a 200 page fails the check', 'fail' === Smoke::run( 'rsmoke', 'post-1', 'preview' )['status'] );
$GLOBALS['uxs_http_fn'] = static function ( string $url, array $args ) use ( $res ): array {
	return $res( 200 );
};
$r = Smoke::run( 'rsmoke', 'home', 'preview' );
$assert( 'a preview that did not take effect fails (no marker header)', 'fail' === $r['status'] && false !== strpos( $r['detail'], 'did not take effect' ) );
$GLOBALS['uxs_http_fn'] = static function ( string $url, array $args ) use ( $res, $active_hdr ): array {
	return isset( $args['headers']['X-UXS-Preview'] ) ? $res( 404, 'nope', $active_hdr ) : $res( 200 );
};
$assert( '200 turning into 404 fails the check', 'fail' === Smoke::run( 'rsmoke', 'page-2', 'preview' )['status'] );
$GLOBALS['uxs_http_fn'] = static function ( string $url, array $args ) use ( $res, $active_hdr ): array {
	return isset( $args['headers']['X-UXS-Preview'] ) ? $res( 301, '', $active_hdr + array( 'location' => '/x' ) ) : $res( 200 );
};
$assert( '200 turning into a redirect only warns', 'warn' === Smoke::run( 'rsmoke', 'page-2', 'preview' )['status'] );
$GLOBALS['uxs_http_fn'] = static function ( string $url, array $args ) use ( $res, $active_hdr ): array {
	return isset( $args['headers']['X-UXS-Preview'] ) ? $res( 200, 'ok <b>Warning</b>: Undefined variable $x in <b>/a.php</b> on line <b>9</b>', $active_hdr ) : $res( 200 );
};
$assert( 'new PHP warnings in the page warn', 'warn' === Smoke::run( 'rsmoke', 'home', 'preview' )['status'] );
$GLOBALS['uxs_http_fn'] = static function ( string $url, array $args ) {
	return new WP_Error( 'http_request_failed', 'cURL error 28: timeout' );
};
$r = Smoke::run( 'rsmoke', 'home', 'preview' );
$assert( 'a failed request fails the check with the reason', 'fail' === $r['status'] && false !== strpos( $r['detail'], 'timeout' ), json_encode( $r ) );

// admin forwards cookies, REST checks carry the nonce.
$_COOKIE = array( 'wordpress_logged_in_x' => 'u|1|t|h', 'wordpress_sec_x' => 'sec', 'unrelated' => 'no', 'uxstudio_preview' => 'no' );
$seen    = array();
$GLOBALS['uxs_http_fn'] = static function ( string $url, array $args ) use ( $res, $active_hdr, &$seen ): array {
	$seen[] = array( $url, $args );
	return $res( 200, '<html>ok</html>', isset( $args['headers']['X-UXS-Preview'] ) ? $active_hdr : array() );
};
Smoke::run( 'rsmoke', 'admin', 'preview' );
$assert( 'the admin check forwards the session cookies only', array( 'wordpress_logged_in_x' => 'u|1|t|h', 'wordpress_sec_x' => 'sec' ) === $seen[0][1]['cookies'], json_encode( $seen[0][1]['cookies'] ?? null ) );
$seen = array();
Smoke::run( 'rsmoke', 'home', 'preview' );
$assert( 'front-end checks never send cookies (a visitor\'s view)', empty( $seen[0][1]['cookies'] ) );

// Source checks.
$GLOBALS['uxs_http_fn'] = static function ( string $url, array $args ) use ( $res, $active_hdr ): array {
	if ( false !== strpos( $url, 'migration/probe' ) ) {
		return $res( 200, json_encode( array( 'data' => array( 'block_editor' => array( 'post' => false, 'page' => false, 'attachment' => true ) ) ) ), $active_hdr );
	}
	return $res( 200, '<html>no form here</html>', isset( $args['headers']['X-UXS-Preview'] ) ? $active_hdr : array() );
};
$r = Smoke::run( 'rsmoke', 'classic-editor:editor', 'preview' );
$assert( 'classic editor check passes when the block editor is off (excluded types ignored)', 'ok' === $r['status'], json_encode( $r ) );
$GLOBALS['uxs_http_fn'] = static function ( string $url, array $args ) use ( $res, $active_hdr ): array {
	return $res( 200, json_encode( array( 'data' => array( 'block_editor' => array( 'post' => true, 'page' => false ) ) ) ), $active_hdr );
};
$r = Smoke::run( 'rsmoke', 'classic-editor:editor', 'preview' );
$assert( 'classic editor check fails when a type still gets the block editor', 'fail' === $r['status'] && false !== strpos( $r['detail'], 'post' ), json_encode( $r ) );
$GLOBALS['uxs_http_fn'] = static function ( string $url, array $args ) use ( $res, $active_hdr ): array {
	return $res( 200, '<form id="commentform"></form>', $active_hdr );
};
$assert( 'comment check fails when the form is still there', 'fail' === Smoke::run( 'rsmoke', 'disable-comments:comments', 'preview' )['status'] );
$GLOBALS['uxs_http_fn'] = static function ( string $url, array $args ) use ( $res, $active_hdr ): array {
	return $res( 200, '<html>clean</html>', $active_hdr );
};
$assert( 'comment check passes without the form', 'ok' === Smoke::run( 'rsmoke', 'disable-comments:comments', 'preview' )['status'] );
$assert( 'an unknown check is refused', is_wp_error( Smoke::run( 'rsmoke', 'nope', 'preview' ) ) && is_wp_error( Smoke::run( 'rsmoke', 'home', 'sideways' ) ) );
$assert( 'the worst status wins', 'fail' === Smoke::worst( array( array( 'status' => 'ok' ), array( 'status' => 'fail' ), array( 'status' => 'warn' ) ) ) && 'ok' === Smoke::worst( array( array( 'status' => 'info' ), array( 'status' => 'ok' ) ) ) );
$assert( 'locations compare without scheme, host and trailing slash', '/new' === Smoke::normalize_location( 'https://example.test/site/new/' ) && '/new' === Smoke::normalize_location( '/new' ) && 'https://other.test/x' === Smoke::normalize_location( 'https://other.test/x/' ) );

// Redirect sample: build the check by hand (the source needs the Redirection tables).
$sample = array( 'id' => 'redirection:redirects', 'label' => 'Sample', 'type' => 'redirect_sample', 'group' => 'redirection', 'paths' => array( '/old-a', '/old-b', '/old-c' ) );
$rc = new ReflectionMethod( Smoke::class, 'check_redirects' );
$rc->setAccessible( true );
$GLOBALS['uxs_http_fn'] = static function ( string $url, array $args ) use ( $res, $active_hdr ): array {
	$pre = isset( $args['headers']['X-UXS-Preview'] );
	$to  = array( '/old-a' => 'https://example.test/site/new-a/', '/old-b' => '/new-b' );
	foreach ( $to as $from => $target ) {
		if ( false !== strpos( $url, $from ) ) {
			return $res( 301, '', ( $pre ? $active_hdr : array() ) + array( 'location' => $target ) );
		}
	}
	return $res( 404, '', $pre ? $active_hdr : array() );
};
$out = $rc->invoke( null, 'rsmoke', $sample, PreviewBoot::make_token( 'rsmoke', 7, time() + 100, 'h' ), 'preview' );
$assert( 'redirect sample: same status and target as the old plugin passes (404s are not compared)', 'ok' === $out['status'] && isset( $out['data']['baseline']['/old-a'] ), json_encode( $out ) );
$GLOBALS['uxs_http_fn'] = static function ( string $url, array $args ) use ( $res, $active_hdr ): array {
	$pre = isset( $args['headers']['X-UXS-Preview'] );
	if ( false !== strpos( $url, '/old-a' ) ) {
		return $res( 301, '', ( $pre ? $active_hdr : array() ) + array( 'location' => $pre ? '/different' : '/new-a' ) );
	}
	return $res( 404, '', $pre ? $active_hdr : array() );
};
$out = $rc->invoke( null, 'rsmoke', $sample, PreviewBoot::make_token( 'rsmoke', 7, time() + 100, 'h' ), 'preview' );
$assert( 'redirect sample: a different target fails and names the rule', 'fail' === $out['status'] && false !== strpos( $out['detail'], '/old-a' ), json_encode( $out ) );
$GLOBALS['uxs_http_fn'] = static function ( string $url, array $args ) use ( $res, $active_hdr ): array {
	return $res( 404, '', isset( $args['headers']['X-UXS-Preview'] ) ? $active_hdr : array() );
};
$out = $rc->invoke( null, 'rsmoke', $sample, PreviewBoot::make_token( 'rsmoke', 7, time() + 100, 'h' ), 'preview' );
$assert( 'redirect sample: nothing redirected by the old plugin only warns', 'warn' === $out['status'] );

// Login slug.
$lc = new ReflectionMethod( Smoke::class, 'check_login' );
$lc->setAccessible( true );
$GLOBALS['uxs_http_fn'] = static function ( string $url, array $args ) use ( $res, $active_hdr ): array {
	if ( false !== strpos( $url, 'wp-login.php' ) ) { return $res( 404, 'not found', $active_hdr ); }
	return $res( 200, '<input name="user_login">', $active_hdr );
};
$token = PreviewBoot::make_token( 'rsmoke', 7, time() + 100, 'h' );
$assert( 'login check: new slug shows the form, wp-login.php 404 as set', 'ok' === $lc->invoke( null, array( 'slug' => 'moje', 'behavior' => '404' ), $token )['status'] );
$assert( 'login check: wp-login.php that stays open fails', ( $GLOBALS['uxs_http_fn'] = static fn( string $url, array $args ) => $res( 200, '<input name="user_login">', $active_hdr ) ) && 'fail' === $lc->invoke( null, array( 'slug' => 'moje', 'behavior' => '404' ), $token )['status'] );
$assert( 'login check: new slug that does not answer fails', ( $GLOBALS['uxs_http_fn'] = static fn( string $url, array $args ) => $res( 404, 'x', $active_hdr ) ) && 'fail' === $lc->invoke( null, array( 'slug' => 'moje', 'behavior' => '404' ), $token )['status'] );

Preview::stop_all();

/* ═══ Switch and its undo ═══ */

class FakeModule {
	public string $id;
	public function __construct( string $id ) { $this->id = $id; }
	public function switch_options(): array { return 'security-optimization' === $this->id ? array( 'uxstudio_security_htaccess_hash' ) : array(); }
	public function after_migration_switch(): void { $GLOBALS['uxs_log'][] = 'effect:' . $this->id; }
}
class FakeModules {
	public array $made = array();
	public function set_enabled( string $id, bool $on ): bool {
		$list = array_values( array_diff( (array) $GLOBALS['uxs_options']['uxstudio_active_modules'], array( $id ) ) );
		if ( $on ) { $list[] = $id; }
		$GLOBALS['uxs_options']['uxstudio_active_modules'] = $list;
		$GLOBALS['uxs_log'][] = ( $on ? 'module+:' : 'module-:' ) . $id;
		return true;
	}
	public function instance( string $id ): FakeModule { return $this->made[ $id ] ??= new FakeModule( $id ); }
	public function enabled_ids(): array { return (array) $GLOBALS['uxs_options']['uxstudio_active_modules']; }
}
Switcher::set_modules( new FakeModules() );

$fresh_site = static function () use ( $world ): array {
	$orig = array(
		'active_plugins'                   => array( 'a/a.php', 'classic-editor/classic-editor.php', 'b/b.php', 'disable-comments/disable-comments.php', 'ux-studio/ux-studio.php' ),
		'uxstudio_active_modules'          => array( 'activity-log', 'security-optimization' ),
		'uxstudio_security_optimization'   => array( 'limit_login_attempts' => true, 'login_url' => 'administrace', 'login_url_enabled' => false ),
		'uxstudio_security_htaccess_hash'  => 'oldhash',
		'disable_comments_options'         => array( 'remove_everywhere' => true ),
		'classic-editor-replace'           => 'classic',
	);
	$world( $orig );
	$GLOBALS['uxs_plugins'] = array(
		'classic-editor/classic-editor.php'     => array( 'Name' => 'Classic Editor', 'Version' => '1.6' ),
		'disable-comments/disable-comments.php' => array( 'Name' => 'Disable Comments', 'Version' => '2.5' ),
	);
	$GLOBALS['uxs_meta'] = array( 'post' => array(), 'user' => array() );
	History::put( array( 'id' => 'rsw1', 'started' => time() - 600, 'started_at' => '2026-10-21 12:00:00', 'finished' => time() - 590, 'user_id' => 7, 'sources' => array( array( 'id' => 'classic-editor', 'label' => 'Classic Editor', 'status' => 'done', 'written' => 1, 'staged' => 0 ), array( 'id' => 'disable-comments', 'label' => 'Disable Comments', 'status' => 'done', 'written' => 3, 'staged' => 3 ) ), 'errors' => 0, 'snapshot' => true, 'rolled_back' => 0 ) );
	Snapshot::create( 'rsw1' );
	Snapshot::capture_options( 'rsw1', array( 'uxstudio_security_optimization', 'uxstudio_classic_editor' ) );
	Staging::put_settings( 'rsw1', 'security-optimization', array( 'disable_comments' => true, 'login_url' => 'nove-prihlaseni', 'login_url_enabled' => true ) );
	Staging::put_options( 'rsw1', 'security-optimization', 'uxstudio_admin_customiser_menu_config', array( 'hidden_items' => array( 'tools.php' ) ) );
	Staging::put_activation( 'rsw1', 'menu-icons', 'menu_icon', array( 101 ), array( '101' => array( 'meta' => array( '_uxs_icon' => 'home' ), 'extra' => null, 'type' => 'post' ) ) );
	return $orig;
};
$tests_ok = static function ( string $when ) use ( &$res, $active_hdr ): void {
	$GLOBALS['uxs_http_fn'] = static fn( string $url, array $args ) => $res( 200, '<html>fine</html>', array() );
};
$tests_ok( '' );
$orig = $fresh_site();

$plan = Switcher::plan( 'rsw1' );
$assert( 'plan lists the plugins to deactivate and the modules to enable', array( 'classic-editor/classic-editor.php', 'disable-comments/disable-comments.php' ) === array_column( $plan['plugins'], 'basename' ) && array( 'classic-editor', 'security-optimization' ) === array_column( $plan['modules'], 'id' ) && false === $plan['modules'][0]['enabled'] && true === $plan['modules'][1]['enabled'], json_encode( $plan ) );
$assert( 'plan announces a login address change', true === $plan['login']['changes'] && 'https://example.test/site/nove-prihlaseni/' === $plan['login']['url'], json_encode( $plan['login'] ) );
$assert( 'plan names the kill switch', 'UXSTUDIO_DISABLE_LOGIN_LOCK' === $plan['kill_switch'] );

$flags_ok = array( 'ack_login' => true );
$b        = Switcher::blockers( $plan, $flags_ok );
$assert( 'no preview tests yet blocks the switch', array( 'preview_missing' ) === array_column( $b, 'code' ), json_encode( $b ) );
$blocked = Switcher::switch_on( 'rsw1', $flags_ok, 7 );
$assert( 'the switch refuses with 409 and lists the blockers', is_wp_error( $blocked ) && 409 === $blocked->get_error_data()['status'] && 'preview_missing' === $blocked->get_error_data()['blockers'][0]['code'] );

$unavail = $plan;
$unavail['preview']['available'] = array( 'ok' => false, 'reason' => 'x', 'path' => '' );
$assert( 'without an available preview only an explicit "no preview" lets the switch go', array( 'preview_unavailable' ) === array_column( Switcher::blockers( $unavail, $flags_ok ), 'code' ) && array() === Switcher::blockers( $unavail, $flags_ok + array( 'no_preview' => true ) ) );
$assert( 'a changed login address needs the acknowledgement', in_array( 'login_ack', array_column( Switcher::blockers( $plan, array() ), 'code' ), true ) );

// Record complete, passing preview tests.
$pass_all = static function () use ( $plan ): void {
	$results = array();
	foreach ( Smoke::plan( 'rsw1' ) as $c ) {
		$results[ $c['id'] ] = array( 'id' => $c['id'], 'status' => 'ok', 'detail' => '' );
	}
	History::update( 'rsw1', array( 'preview' => array( 'at' => time(), 'results' => $results, 'counts' => Preview::counts( $results ) ) ) );
};
$pass_all();
$plan = Switcher::plan( 'rsw1' );
$assert( 'complete passing preview tests clear the way', array() === Switcher::blockers( $plan, $flags_ok ), json_encode( Switcher::blockers( $plan, $flags_ok ) ) );

$results = History::find( 'rsw1' )['preview']['results'];
$results['home']['status'] = 'fail';
History::update( 'rsw1', array( 'preview' => array( 'at' => time(), 'results' => $results, 'counts' => Preview::counts( $results ) ) ) );
$plan_f = Switcher::plan( 'rsw1' );
$assert( 'a failed preview test blocks the switch', array( 'preview_failed' ) === array_column( Switcher::blockers( $plan_f, $flags_ok ), 'code' ) );
$assert( 'the explicit override lets it through', array() === Switcher::blockers( $plan_f, $flags_ok + array( 'override_failures' => true ) ) );
$pass_all();
History::update( 'rsw1', array( 'preview' => array( 'at' => time() - Preview::RESULT_MAX_AGE - 5, 'results' => History::find( 'rsw1' )['preview']['results'], 'counts' => array() ) ) );
$assert( 'results older than a day no longer count', in_array( 'preview_incomplete', array_column( Switcher::blockers( Switcher::plan( 'rsw1' ), $flags_ok ), 'code' ), true ) );
$pass_all();

$before = $GLOBALS['uxs_options'];
$GLOBALS['uxs_log'] = array();
$out = Switcher::switch_on( 'rsw1', $flags_ok, 7 );
$assert( 'the switch succeeds', ! is_wp_error( $out ), is_wp_error( $out ) ? $out->get_error_message() : '' );
$log = $GLOBALS['uxs_log'];
$pos = static fn( string $needle ) => array_search( $needle, $log, true );
$assert( 'order: staged values are written first', false !== $pos( 'opt:uxstudio_security_optimization' ) && $pos( 'opt:uxstudio_security_optimization' ) < $pos( 'module+:classic-editor' ), implode( ' ', $log ) );
$assert( 'order: modules are enabled before side effects run', $pos( 'module+:classic-editor' ) < $pos( 'effect:classic-editor' ) && $pos( 'module+:classic-editor' ) < $pos( 'effect:security-optimization' ) );
$assert( 'order: plugins are deactivated last, after side effects', $pos( 'effect:security-optimization' ) < $pos( 'deactivate:classic-editor/classic-editor.php' ) && $pos( 'effect:classic-editor' ) < $pos( 'deactivate:disable-comments/disable-comments.php' ) );
$assert( 'the guard bypass is logged (module enabled although the plugin was still active)', in_array( 'module+:classic-editor', $log, true ) );
$assert( 'only the module that was off is enabled', array( 'classic-editor' ) === $out['enabled'] && array( 'activity-log', 'security-optimization', 'classic-editor' ) === $GLOBALS['uxs_options']['uxstudio_active_modules'] );
$assert( 'the old plugins are deactivated, others stay in order', array( 'a/a.php', 'b/b.php', 'ux-studio/ux-studio.php' ) === $GLOBALS['uxs_options']['active_plugins'], json_encode( $GLOBALS['uxs_options']['active_plugins'] ) );
$assert( 'staged settings are live', true === $GLOBALS['uxs_options']['uxstudio_security_optimization']['disable_comments'] && 'nove-prihlaseni' === $GLOBALS['uxs_options']['uxstudio_security_optimization']['login_url'] );
$assert( 'staged data is switched on (menu meta written)', 'home' === ( $GLOBALS['uxs_meta']['post'][101]['_uxs_icon'][0] ?? '' ) );
$assert( 'a staged whole option (menu config) is written at the switch', array( 'tools.php' ) === ( $GLOBALS['uxs_options']['uxstudio_admin_customiser_menu_config']['hidden_items'] ?? null ), json_encode( $GLOBALS['uxs_options']['uxstudio_admin_customiser_menu_config'] ?? null ) );
$assert( 'the staging entry is consumed', null === Staging::entry( 'rsw1' ) );
$assert( 'history shows the run as switched, and what was changed', ! empty( History::find( 'rsw1' )['switched'] ) && array( 'classic-editor' ) === History::find( 'rsw1' )['switch_modules'] && 2 === count( History::find( 'rsw1' )['switch_plugins'] ) );
$assert( 'a switch record is kept for the undo, 30 days', Switcher::has_switch( 'rsw1' ) && Switcher::can_revert( 'rsw1' ) && ! Switcher::can_revert( 'rsw1', time() + 31 * DAY_IN_SECONDS ) );
$assert( 'the live checks to run next are returned', in_array( 'home', array_column( $out['live_plan'], 'id' ), true ) );
$again = Switcher::plan( 'rsw1' );
$assert( 'a switched run cannot be switched again', true === $again['switched'] && array( 'switched' ) === array_column( Switcher::blockers( $again, $flags_ok ), 'code' ) );

// A run history built for the UI.
$rows = Runner::history();
$row  = array_values( array_filter( $rows, static fn( $r ) => 'rsw1' === $r['id'] ) )[0];
$assert( 'run history marks the switch and offers its undo', true === $row['switch_active'] && true === $row['can_revert_switch'] && true === $row['can_rollback'] && '' !== $row['revert_until'], json_encode( $row ) );

// Rollback of the switched run = undo the switch, then roll back.
$after_switch = $GLOBALS['uxs_options'];
$rev = Switcher::revert( 'rsw1' );
$assert( 'revert succeeds', ! is_wp_error( $rev ) && array() === $rev['errors'], is_wp_error( $rev ) ? $rev->get_error_message() : json_encode( $rev ) );
$log = $GLOBALS['uxs_log'];
$assert( 'undo order: the module goes off before the plugins come back', array_search( 'module-:classic-editor', $log, true ) < array_search( 'activate:classic-editor/classic-editor.php', $log, true ) );
$assert( 'undo: both plugins are reactivated through WordPress', in_array( 'activate:classic-editor/classic-editor.php', $log, true ) && in_array( 'activate:disable-comments/disable-comments.php', $log, true ) );
$assert( 'undo: the plugin list is back exactly, in its original order', $before['active_plugins'] === $GLOBALS['uxs_options']['active_plugins'], json_encode( $GLOBALS['uxs_options']['active_plugins'] ) );
$assert( 'undo: the module list is back exactly', $before['uxstudio_active_modules'] === $GLOBALS['uxs_options']['uxstudio_active_modules'] );
$assert( 'undo: module settings and the htaccess hash are back exactly', $before['uxstudio_security_optimization'] === $GLOBALS['uxs_options']['uxstudio_security_optimization'] && 'oldhash' === $GLOBALS['uxs_options']['uxstudio_security_htaccess_hash'] );
$assert( 'undo: a whole option the switch wrote (menu config) is gone again', ! array_key_exists( 'uxstudio_admin_customiser_menu_config', $GLOBALS['uxs_options'] ) && array_key_exists( 'options', Staging::entry( 'rsw1' ) ), json_encode( array_keys( $GLOBALS['uxs_options'] ) ) );
$assert( 'undo: the data the switch switched on is off again', ! isset( $GLOBALS['uxs_meta']['post'][101]['_uxs_icon'] ) );
$assert( 'undo: modules that stay on re-run their side effects with the old settings', in_array( 'effect:security-optimization', array_slice( $log, array_search( 'module-:classic-editor', $log, true ) ), true ) );
$assert( 'undo: the prepared values wait again', null !== Staging::entry( 'rsw1' ) && true === Staging::entry( 'rsw1' )['settings']['security-optimization']['disable_comments'] );
$assert( 'undo: the run is no longer switched or applied', empty( History::find( 'rsw1' )['switched'] ) && empty( History::find( 'rsw1' )['applied'] ) && ! empty( History::find( 'rsw1' )['switch_reverted'] ) && ! Switcher::has_switch( 'rsw1' ) );
$assert( 'undo twice is refused', is_wp_error( Switcher::revert( 'rsw1' ) ) );
$assert( 'after the undo the run can be switched again', array() === Switcher::blockers( Switcher::plan( 'rsw1' ), $flags_ok ), json_encode( Switcher::blockers( Switcher::plan( 'rsw1' ), $flags_ok ) ) );

// Switch again, then roll back the whole run.
$out = Switcher::switch_on( 'rsw1', $flags_ok, 7 );
$assert( 'second switch works', ! is_wp_error( $out ) );
$rb = Runner::rollback( 'rsw1' );
$assert( 'rolling back a switched run undoes the switch first', ! is_wp_error( $rb ) && ! Switcher::has_switch( 'rsw1' ) && $before['active_plugins'] === $GLOBALS['uxs_options']['active_plugins'] && $before['uxstudio_active_modules'] === $GLOBALS['uxs_options']['uxstudio_active_modules'], is_wp_error( $rb ) ? $rb->get_error_message() : '' );
$assert( 'and then restores the migration snapshot', ! empty( History::find( 'rsw1' )['rolled_back'] ) && null === Staging::entry( 'rsw1' ) );

// A broken site after the switch is undone automatically.
$fresh_site();
$pass_all();
$before = $GLOBALS['uxs_options'];
$calls  = 0;
$GLOBALS['uxs_http_fn'] = static function ( string $url, array $args ) use ( $res, &$calls ): array {
	++$calls;
	return $res( 500, '<p>There has been a critical error on this website.</p>' );
};
$out = Switcher::switch_on( 'rsw1', $flags_ok, 7 );
$assert( 'a site that fails right after the switch is put back at once', is_wp_error( $out ) && 'uxstudio_switch_failed' === $out->get_error_code() && true === $out->get_error_data()['reverted'] );
$assert( 'and everything is as before', $before['active_plugins'] === $GLOBALS['uxs_options']['active_plugins'] && $before['uxstudio_active_modules'] === $GLOBALS['uxs_options']['uxstudio_active_modules'] && $before['uxstudio_security_optimization'] === $GLOBALS['uxs_options']['uxstudio_security_optimization'] && ! Switcher::has_switch( 'rsw1' ) );

// A run with errors, a run switched without plugins.
$fresh_site();
History::update( 'rsw1', array( 'errors' => 1 ) );
$pass_all();
$assert( 'a run with errors cannot be switched', in_array( 'errors', array_column( Switcher::blockers( Switcher::plan( 'rsw1' ), $flags_ok ), 'code' ), true ) );

Switcher::set_modules( null );
foreach ( glob( $mu_dir . '/*' ) ?: array() as $f ) { @unlink( $f ); }
@rmdir( $mu_dir );

echo PHP_EOL . ( 0 === $failures ? 'ALL PASSED' : $failures . ' FAILED' ) . PHP_EOL;
exit( 0 === $failures ? 0 : 1 );
