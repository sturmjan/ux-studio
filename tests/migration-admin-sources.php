<?php
/**
 * Standalone test of migration phase F5 (PLAN.md 26.11): the Admin Customiser sources
 * (Admin Menu Editor, Adminimize, White Label CMS, Adminify, LoginPress) and Admin Columns.
 *
 * Fixtures in tests/fixtures/f5/ are REAL option values exported from the local pobyty site
 * (Admin Menu Editor, Adminimize, White Label CMS, Adminify), with the heavy "defaults" snapshots,
 * empty per-role lists and personal strings trimmed. LoginPress and Admin Columns are not
 * installed there: their fixtures are written from the plugin code (downloaded zips).
 *
 *   php tests/migration-admin-sources.php
 *
 * Needs no WordPress and no database. Exits non-zero when any case fails.
 *
 * @package UxStudio
 */

// phpcs:disable

define( 'ABSPATH', __DIR__ . '/' );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );
define( 'KB_IN_BYTES', 1024 );
define( 'ARRAY_A', 'ARRAY_A' );

$root = dirname( __DIR__ );
define( 'UXSTUDIO_PATH', $root . '/' );

$GLOBALS['uxs_options'] = array();
$GLOBALS['uxs_log']     = array();
$GLOBALS['uxs_actions'] = array();
$GLOBALS['uxs_plugins'] = array();
$GLOBALS['uxs_http']    = null;
$GLOBALS['uxs_user']    = array( 'roles' => array( 'administrator' ), 'can' => true );

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
	public $map = array();
	public function insert( $table, $data ) { return 1; }
	public function replace( $table, $data ) {
		$k = $data['source_plugin'] . '|' . $data['source_key'] . '|' . $data['target_type'];
		$this->map[ $k ] = $data;
		return 1;
	}
	public function delete( $table, $where ) {
		foreach ( $this->map as $k => $row ) {
			if ( $row['run_id'] === $where['run_id'] ) { unset( $this->map[ $k ] ); }
		}
		return 1;
	}
	public function prepare( $sql, ...$args ) {
		if ( 1 === count( $args ) && is_array( $args[0] ) ) { $args = $args[0]; }
		$i = 0;
		return preg_replace_callback( '/%[sd]/', static function ( $m ) use ( &$i, $args ) {
			$v = $args[ $i++ ] ?? '';
			return '%d' === $m[0] ? (string) (int) $v : "'" . addslashes( (string) $v ) . "'";
		}, $sql );
	}
	public function get_var( $sql ) { return null; }
	public function get_col( $sql ) { return array(); }
	public function get_results( $sql, $output = null ) {
		if ( false !== strpos( $sql, 'uxstudio_migration_map' ) && preg_match( "/run_id = '([^']*)'/", $sql, $m ) ) {
			return array_values( array_filter( $this->map, static fn( $row ) => $row['run_id'] === $m[1] ) );
		}
		return array();
	}
	public function query( $sql ) { return 0; }
	public function esc_like( $s ) { return $s; }
}
$GLOBALS['wpdb'] = new FakeWpdb();

function get_option( $name, $default = false ) { return array_key_exists( $name, $GLOBALS['uxs_options'] ) ? $GLOBALS['uxs_options'][ $name ] : $default; }
function update_option( $name, $value, $autoload = null ) { $GLOBALS['uxs_options'][ $name ] = $value; return true; }
function add_option( $name, $value = '', $dep = '', $autoload = null ) {
	if ( array_key_exists( $name, $GLOBALS['uxs_options'] ) ) { return false; }
	$GLOBALS['uxs_options'][ $name ] = $value;
	return true;
}
function delete_option( $name ) { unset( $GLOBALS['uxs_options'][ $name ] ); return true; }
function apply_filters( $hook, $value, ...$args ) { return $value; }
function do_action( $hook, ...$args ) { $GLOBALS['uxs_actions'][] = $hook; }
function __( $text, $domain = '' ) { return $text; }
function _n( $single, $plural, $n, $domain = '' ) { return 1 === $n ? $single : $plural; }
function is_wp_error( $x ) { return $x instanceof WP_Error; }
function current_time( $type ) { return '2026-10-21 12:00:00'; }
function get_current_user_id() { return 7; }
function wp_salt( $scheme = '' ) { return 'test-salt-' . $scheme; }
function sanitize_text_field( $s ) { return trim( strip_tags( (string) $s ) ); }
function sanitize_textarea_field( $s ) { return trim( strip_tags( (string) $s ) ); }
function sanitize_hex_color( $s ) { return preg_match( '/^#(?:[0-9a-f]{3}|[0-9a-f]{6})$/i', (string) $s ) ? (string) $s : ''; }
function sanitize_key( $s ) { return strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', (string) $s ) ); }
function esc_url_raw( $s ) { return preg_match( '#^https?://#i', (string) $s ) ? (string) $s : ''; }
function wp_kses_post( $s ) { return (string) $s; }
function maybe_serialize( $v ) { return is_scalar( $v ) ? (string) $v : serialize( $v ); }
function wp_json_encode( $v ) { return json_encode( $v ); }
function trailingslashit( $s ) { return rtrim( $s, '/' ) . '/'; }
function home_url( $p = '' ) { return 'https://example.test/site' . $p; }
function site_url( $p = '', $scheme = null ) { return 'https://example.test/site/' . ltrim( $p, '/' ); }
function admin_url( $p = '' ) { return 'https://example.test/site/wp-admin/' . ltrim( $p, '/' ); }
function add_query_arg( $a, $b, $c = null ) { $args = is_array( $a ) ? $a : array( $a => $b ); $url = is_array( $a ) ? $b : $c; return $url . ( false === strpos( $url, '?' ) ? '?' : '&' ) . http_build_query( $args ); }
function wp_create_nonce( $a ) { return substr( md5( $a ), 0, 10 ); }
function absint( $v ) { return abs( (int) $v ); }
function translate_user_role( $n ) { return $n; }
function get_plugins() { return $GLOBALS['uxs_plugins']; }
function wp_remote_request( $url, $args ) { return $GLOBALS['uxs_http']( $url, $args ); }
function wp_remote_retrieve_response_code( $r ) { return $r['code']; }
function wp_remote_retrieve_body( $r ) { return $r['body']; }
function wp_remote_retrieve_headers( $r ) { return $r['headers']; }
function get_post_types( $args = array(), $output = 'names' ) { return array(); }
function wp_get_current_user() { return (object) array( 'ID' => 7, 'roles' => $GLOBALS['uxs_user']['roles'] ); }
function current_user_can( $cap ) { return 'manage_options' === $cap ? (bool) $GLOBALS['uxs_user']['can'] : true; }

/** The roles of the fake site: like pobyty (shop roles included). */
function wp_roles() {
	$caps = static fn( array $c = array() ) => array( 'capabilities' => $c );
	return (object) array(
		'roles' => array(
			'administrator' => array( 'name' => 'Administrator' ) + $caps( array( 'manage_options' => true, 'upload_files' => true ) ),
			'editor'        => array( 'name' => 'Editor' ) + $caps( array( 'upload_files' => true ) ),
			'author'        => array( 'name' => 'Author' ) + $caps( array( 'upload_files' => true ) ),
			'contributor'   => array( 'name' => 'Contributor' ) + $caps(),
			'subscriber'    => array( 'name' => 'Subscriber' ) + $caps(),
			'shop_manager'  => array( 'name' => 'Shop manager' ) + $caps( array( 'upload_files' => true ) ),
			'customer'      => array( 'name' => 'Customer' ) + $caps(),
			'translator'    => array( 'name' => 'Translator' ) + $caps(),
		),
	);
}

require_once $root . '/includes/Autoloader.php';
\UxStudio\Autoloader::register();

use UxStudio\Core\Migration\Catalog;
use UxStudio\Core\Migration\Mapping;
use UxStudio\Core\Migration\MenuSimulator;
use UxStudio\Core\Migration\PluginInfo;
use UxStudio\Core\Migration\Runner;
use UxStudio\Core\Migration\SettingsWriter;
use UxStudio\Core\Migration\Smoke;
use UxStudio\Core\Migration\SourceRegistry;
use UxStudio\Core\Migration\Sources\AbstractAdminSource;
use UxStudio\Core\Migration\Sources\AdminColumnsSource;
use UxStudio\Core\Migration\Staging;
use UxStudio\Modules\AdminCustomiser\MenuOrganizer;

$failures = 0;
$assert   = static function ( string $name, bool $ok, string $detail = '' ) use ( &$failures ): void {
	if ( ! $ok ) {
		++$failures;
	}
	echo ( $ok ? 'PASS ' : 'FAIL ' ) . $name . ( $ok || '' === $detail ? '' : ' => ' . $detail ) . PHP_EOL;
};
$j = static fn( $v ): string => (string) json_encode( $v, JSON_UNESCAPED_UNICODE );

/* ── Schemas: the REAL Admin Customiser schema; the two small modules by hand (checked against their code below) ── */

$ac_module = ( new ReflectionClass( \UxStudio\Modules\AdminCustomiser\Module::class ) )->newInstanceWithoutConstructor();
$ac_schema = $ac_module->settings_schema();
$schemas   = array(
	'admin-customiser'  => $ac_schema,
	'hide-admin-bar'    => array( array( 'key' => 'roles', 'type' => 'multiselect', 'options' => array_fill_keys( array_keys( wp_roles()->roles ), '' ), 'default' => array() ) ),
	'dashboard-widgets' => array(
		array( 'key' => 'disable_all_widgets', 'type' => 'toggle', 'default' => false ),
		array( 'key' => 'hidden_widgets', 'type' => 'multiselect', 'options' => array_fill_keys( array( 'dashboard_welcome', 'dashboard_right_now', 'dashboard_activity', 'dashboard_quick_press', 'dashboard_primary', 'e-dashboard-overview', 'wordfence_activity_report_widget', 'wpseo-dashboard-overview', 'wpseo-wincher-dashboard-overview' ), '' ), 'default' => array() ),
	),
);
SettingsWriter::set_schema_resolver( static fn( string $m ) => $schemas[ $m ] ?? null );

$site = static function ( array $plugins = array(), array $active = array(), array $options = array() ): void {
	$GLOBALS['uxs_options'] = array_merge(
		array(
			'active_plugins'              => array_map( static fn( $s ) => "$s/$s.php", $active ),
			'uxstudio_active_modules'     => array( 'activity-log' ),
			'uxstudio_module_db_versions' => array( 'core_migration' => 1 ),
		),
		$options
	);
	$GLOBALS['uxs_plugins'] = array();
	foreach ( $plugins as $slug ) {
		$GLOBALS['uxs_plugins']["$slug/$slug.php"] = array( 'Name' => "Plugin $slug", 'Version' => '1.0' );
	}
	$GLOBALS['uxs_user']   = array( 'roles' => array( 'administrator' ), 'can' => true );
	$GLOBALS['wpdb']->map  = array();
	AbstractAdminSource::set_media_double( null );
	AdminColumnsSource::set_reader( null );
	PluginInfo::flush();
};
$fx = static function ( string $file ) use ( $root ) {
	$data = json_decode( (string) file_get_contents( $root . '/tests/fixtures/f5/' . $file ), true );
	return $data;
};
$opt = static fn( string $name ) => $GLOBALS['uxs_options'][ $name ] ?? null;
$state = static fn(): array => $GLOBALS['uxs_options'];

$exercise = static function ( string $slug ) use ( $state ): array {
	$before    = $state();
	$plan      = SourceRegistry::get( $slug )->plan();
	$dry       = SourceRegistry::get( $slug )->migrate( array( 'run_id' => 'rtest' ), true );
	$after_dry = $state();
	$first     = SourceRegistry::get( $slug )->migrate( array( 'run_id' => 'rtest' ), false );
	$state1    = $state();
	$second    = SourceRegistry::get( $slug )->migrate( array( 'run_id' => 'rtest' ), false );
	$state2    = $state();
	$verify    = SourceRegistry::get( $slug )->verify();
	return compact( 'before', 'plan', 'dry', 'after_dry', 'first', 'state1', 'second', 'state2', 'verify' );
};
$common = static function ( string $label, array $r, bool $writes = true ) use ( $assert, $j ): void {
	$assert( "$label: dry run writes nothing", $r['before'] === $r['after_dry'] );
	$assert( "$label: second run is idempotent (same options)", $r['state1'] === $r['state2'] );
	$assert( "$label: second run writes 0", 0 === $r['second']['written'], (string) $r['second']['written'] );
	if ( $writes ) {
		$assert( "$label: first run writes something", $r['first']['written'] > 0, (string) $r['first']['written'] );
		$assert( "$label: dry run reports what the first run writes", $r['dry']['written'] === $r['first']['written'], $r['dry']['written'] . ' vs ' . $r['first']['written'] );
	}
	$bad = array_filter( $r['verify'], static fn( $c ) => ! $c['ok'] );
	$assert( "$label: verify is all ok", array() === $bad, $j( $bad ) );
};
$menu_cfg = static fn(): array => array_merge( MenuOrganizer::default_config(), (array) ( $GLOBALS['uxs_options'][ MenuOrganizer::CONFIG_OPTION ] ?? array() ) );

/* ── Registry and catalog ── */

$classes = SourceRegistry::classes();
foreach ( array( 'admin-menu-editor', 'admin-menu-editor-pro', 'adminimize', 'white-label-cms', 'adminify', 'loginpress', 'codepress-admin-columns' ) as $slug ) {
	$entry = Catalog::entry_for_slug( $slug );
	$assert( "F5: $slug is registered and in the Catalog", isset( $classes[ $slug ] ) && $entry && SourceRegistry::get( $slug )->target_module() === $entry['module'] );
}
$assert( 'F5: Admin Customiser sources are partial, Admin Columns is a full match', 'partial' === SourceRegistry::get( 'adminimize' )->match_type() && 'full' === SourceRegistry::get( 'codepress-admin-columns' )->match_type() );

/* Every key the sources write exists in the real module code. */
$key_in_module = static function ( string $module, string $key ) use ( $root ): bool {
	$dir  = $root . '/includes/Modules/' . str_replace( ' ', '', ucwords( str_replace( '-', ' ', $module ) ) ) . '/Module.php';
	$code = is_readable( $dir ) ? (string) file_get_contents( $dir ) : '';
	return 1 === preg_match( "/'key'\s*=>\s*'" . preg_quote( $key, '/' ) . "'/", $code );
};
$written_keys = array();

/* ══ Admin Menu Editor: the real configuration of pobyty ══ */

$ame_opt = $fx( 'ws_menu_editor.json' );
$site( array( 'admin-menu-editor' ), array( 'admin-menu-editor' ), $ame_opt );
$r = $exercise( 'admin-menu-editor' );
$common( 'AME (real)', $r );
$cfg = $menu_cfg();
$plan = $r['plan'];
$assert( 'AME (real): groups between separators become categories + Other', count( $cfg['categories'] ) >= 5 && isset( $cfg['categories']['other'] ) && 'group-1' === array_key_first( $cfg['categories'] ), $j( $cfg['categories'] ?? null ) );
$assert( 'AME (real): top-level order follows the plugin (Dashboard first, Media before Pages)', 'index.php' === $cfg['order']['group-1'][0] && array_search( 'upload.php', $cfg['order'][ $cfg['assignments']['upload.php'] ] ) < array_search( 'edit.php?post_type=page', $cfg['order'][ $cfg['assignments']['edit.php?post_type=page'] ] ), $j( $cfg['order'] ?? null ) );
$assert( 'AME (real): every item has a category, order lists match assignments', count( $cfg['assignments'] ) === array_sum( array_map( 'count', $cfg['order'] ) ), count( $cfg['assignments'] ) . ' vs ' . array_sum( array_map( 'count', $cfg['order'] ) ) );
$assert( 'AME (real): renames of moved items are kept (Objednávky, Zákaznící)', 'Objednávky' === ( $cfg['item_titles']['wc-orders'] ?? '' ) && 'Zákaznící' === ( $cfg['item_titles']['wc-admin&path=/customers'] ?? '' ), $j( $cfg['item_titles'] ?? null ) );
$assert( 'AME (real): sub-items shown at the top level become promoted_subs with their parent', 'woocommerce' === ( $cfg['promoted_subs']['wc-orders']['parent'] ?? '' ) && isset( $cfg['promoted_subs']['edit.php?post_type=elementor_library&tabs_group=popup&elementor_library_type=popup'] ), $j( array_keys( (array) $cfg['promoted_subs'] ) ) );
$assert( 'AME (real): dashicons of promoted items survive (cart)', 'dashicons-cart' === ( $cfg['item_icons']['wc-orders'] ?? '' ), $j( $cfg['item_icons'] ?? null ) );
$assert( 'AME (real): the menu organizer is switched on in the module settings', true === ( $opt( 'uxstudio_admin_customiser' )['menu_organizer_enabled'] ?? false ) );
$assert( 'AME (real): nothing hidden, nothing role-limited (the real config has no such rule); empty keys are not written', array() === $cfg['hidden_items'] && array() === $cfg['item_roles'] && ! array_key_exists( 'hidden_items', (array) $opt( MenuOrganizer::CONFIG_OPTION ) ) );
$assert( 'AME (real): plan carries a per-role table', 8 === count( $plan['menu_table'] ?? array() ) && 'administrator' === $plan['menu_table'][0]['role'], $j( $plan['menu_table'][0] ?? null ) );
$assert( 'AME (real): lossy list names moved sub-items and the capability model', (bool) preg_grep( '/moved under another parent/', $plan['lossy'] ) && (bool) preg_grep( '/show for/', $plan['lossy'] ), $j( $plan['lossy'] ) );
$assert( 'AME (real): plan warns the Organizer only hides (no capability change)', (bool) preg_grep( '/only hides menu items/', $plan['warnings'] ) );
$verify_roles = array_filter( $r['verify'], static fn( $c ) => 0 === strpos( $c['id'], 'menu-role:' ) );
$assert( 'AME (real): verify compares every role (8 role rows)', 8 === count( $verify_roles ), (string) count( $verify_roles ) );
foreach ( $plan['transfer'] as $row ) {
	if ( ! empty( $row['module'] ) && 'admin-customiser' === $row['module'] && empty( $row['option'] ) ) {
		$written_keys[ $row['key'] ] = 'admin-customiser';
	}
}
$check = SourceRegistry::get( 'admin-menu-editor' )->smoke_checks();
$assert( 'AME (real): smoke check for admin access is announced with the four screens', 1 === count( $check ) && 'admin_access' === $check[0]['type'] && array( 'plugins.php', 'admin.php?page=ux-studio', 'users.php', 'options-general.php' ) === $check[0]['urls'] );
$assert( 'AME (real): snapshot covers the menu config option', in_array( MenuOrganizer::CONFIG_OPTION, SourceRegistry::get( 'admin-menu-editor' )->target_options(), true ) );

/* ── AME: roles, hidden items, lockout rule, lossy parts (synthetic, same format) ── */

$tree = static function ( array $items ): array {
	$out = array();
	$pos = 0;
	foreach ( $items as $file => $item ) {
		$out[ $file ] = array_merge( array( 'position' => $pos++, 'template_id' => '>' . $file, 'defaults' => array( 'file' => $file, 'menu_title' => ucfirst( $file ) ) ), $item );
	}
	return array( 'ws_menu_editor' => array( 'custom_menu' => array( 'tree' => $out ) ) );
};
$site(
	array( 'admin-menu-editor' ), array( 'admin-menu-editor' ),
	$tree(
		array(
			'index.php'                 => array(),
			'separator_1'               => array( 'separator' => true ),
			'edit.php'                  => array( 'menu_title' => 'Blog', 'hidden_from_actor' => array( 'role:author' => true ), 'icon_url' => 'dashicons-admin-post' ),
			'upload.php'                => array( 'grant_access' => array( 'role:editor' => false, 'role:subscriber' => false, 'user:bob' => true ) ),
			'plugins.php'               => array( 'hidden' => true ),
			'users.php'                 => array( 'grant_access' => array( 'role:administrator' => false, 'role:shop_manager' => false ) ),
			'tools.php'                 => array( 'hidden' => true, 'extra_capability' => 'manage_tools_x' ),
			'options-general.php'       => array( 'items' => array( array( 'template_id' => 'options-general.php>options-general.php', 'defaults' => array( 'file' => 'options-general.php', 'menu_title' => 'General' ), 'hidden' => true ), array( 'template_id' => 'options-general.php>options-writing.php', 'defaults' => array( 'file' => 'options-writing.php', 'menu_title' => 'Writing' ), 'menu_title' => 'Psaní' ) ) ),
			'custom-1'                  => array( 'custom' => true, 'menu_title' => 'Docs', 'url' => 'https://docs.example.test/', 'icon_url' => 'dashicons-book', 'open_in' => 'new_window', 'template_id' => '' ),
			'custom-2'                  => array( 'custom' => true, 'menu_title' => 'Frame', 'url' => 'https://x.example.test/', 'open_in' => 'iframe', 'template_id' => '' ),
			'ux-studio'                 => array( 'grant_access' => array( 'role:administrator' => false ) ),
		)
	)
);
$plan = SourceRegistry::get( 'admin-menu-editor' )->plan();
$r    = $exercise( 'admin-menu-editor' );
$cfg  = $menu_cfg();
$assert( 'AME (synthetic): hidden item goes to hidden_items (Tools)', in_array( 'tools.php', $cfg['hidden_items'], true ), $j( $cfg['hidden_items'] ) );
$assert( 'AME (synthetic): LOCKOUT RULE drops "hide Plugins" (hidden flag)', ! in_array( 'plugins.php', $cfg['hidden_items'], true ) && (bool) preg_grep( '/hides "Plugins"/', $plan['warnings'] ), $j( $plan['warnings'] ) );
$assert( 'AME (synthetic): LOCKOUT RULE keeps UX Studio and Users visible even though AME denies the administrator role', ! in_array( 'users.php', $cfg['hidden_items'], true ) && ! in_array( 'ux-studio', $cfg['hidden_items'], true ) );
$assert( 'AME (synthetic): role denied for author becomes a whitelist without author', isset( $cfg['item_roles']['edit.php'] ) && ! in_array( 'author', $cfg['item_roles']['edit.php'], true ) && in_array( 'editor', $cfg['item_roles']['edit.php'], true ), $j( $cfg['item_roles']['edit.php'] ?? null ) );
$assert( 'AME (synthetic): grant_access=false for editor+subscriber limits Media to the other roles', isset( $cfg['item_roles']['upload.php'] ) && ! array_intersect( array( 'editor', 'subscriber' ), $cfg['item_roles']['upload.php'] ), $j( $cfg['item_roles']['upload.php'] ?? null ) );
$assert( 'AME (synthetic): rename and icon', 'Blog' === ( $cfg['item_titles']['edit.php'] ?? '' ) && 'dashicons-admin-post' === ( $cfg['item_icons']['edit.php'] ?? '' ) );
$assert( 'AME (synthetic): custom link with a new-window target', 1 === count( $cfg['custom_links'] ) && 'Docs' === $cfg['custom_links'][0]['title'] && '_blank' === $cfg['custom_links'][0]['target'], $j( $cfg['custom_links'] ) );
$lossy = implode( ' | ', $plan['lossy'] );
$assert( 'AME (synthetic): lossy names the user rule, the capability, the sub-item hide/rename, the iframe item', false !== strpos( $lossy, 'bob' ) && false !== strpos( $lossy, 'Tools' ) && false !== strpos( $lossy, 'General' ) && false !== strpos( $lossy, 'Psaní' ) && false !== strpos( $lossy, 'Frame' ), $lossy );
$assert( 'AME (synthetic): users who can manage options keep seeing items AME hid from administrators (lossy)', false !== strpos( $lossy, 'always shows every item to users who can manage options' ), $lossy );
$common( 'AME (synthetic)', $r );
$ver = array_column( $r['verify'], null, 'id' );
$assert( 'AME (synthetic): verify for author shows the known difference, not a failure', ! empty( $ver['menu-role:author']['ok'] ), $j( $ver['menu-role:author'] ?? null ) );
$tbl = array_column( $plan['menu_table'], null, 'role' );
$assert( 'AME (synthetic): per-role table: Author loses Blog after, had it hidden before', in_array( 'Blog', $tbl['author']['before'], true ) && in_array( 'Blog', $tbl['author']['after'], true ), $j( $tbl['author'] ?? null ) );

/* AME Pro uses its own option and is read the same way. */
$site( array( 'admin-menu-editor-pro' ), array( 'admin-menu-editor-pro' ), array( 'ws_menu_editor_pro' => $tree( array( 'index.php' => array(), 'edit.php' => array( 'menu_title' => 'Clanky' ) ) )['ws_menu_editor'] ) );
$p = SourceRegistry::get( 'admin-menu-editor-pro' )->plan();
$assert( 'AME Pro: reads ws_menu_editor_pro', $p['counts']['transfer'] > 0 );
$site( array( 'admin-menu-editor' ), array( 'admin-menu-editor' ), array( 'ws_menu_editor' => array( 'custom_menu' => null ) ) );
$p = SourceRegistry::get( 'admin-menu-editor' )->plan();
$assert( 'AME without a custom menu: nothing to transfer, says so', 0 === $p['counts']['transfer'] && array() !== $p['notes'] );

/* ══ Adminimize: the real configuration of pobyty ══ */

$adm = $fx( 'mw_adminimize.json' );
$site( array( 'adminimize' ), array( 'adminimize' ), $adm );
$r    = $exercise( 'adminimize' );
$common( 'Adminimize (real)', $r );
$cfg  = $menu_cfg();
$plan = $r['plan'];
$assert( 'Adminimize (real): hidden for editor and shop manager -> whitelist without those two (Themes)', isset( $cfg['item_roles']['themes.php'] ) && ! array_intersect( array( 'editor', 'shop_manager' ), $cfg['item_roles']['themes.php'] ) && in_array( 'administrator', $cfg['item_roles']['themes.php'], true ), $j( $cfg['item_roles']['themes.php'] ?? null ) );
$assert( 'Adminimize (real): 20 items limited, slugs with query strings intact', 20 === count( $cfg['item_roles'] ) && isset( $cfg['item_roles']['edit.php?post_type=alertni-lista'] ), (string) count( $cfg['item_roles'] ) );
$assert( 'Adminimize (real): hide-only config keeps the native order in ONE category (no regrouping)', array( 'other' ) === array_keys( $cfg['categories'] ) && array() === $cfg['order'] && array() === $cfg['assignments'] && array() === $cfg['hidden_items'], $j( $cfg['categories'] ) );
$assert( 'Adminimize (real): Menu Organizer switched on', true === ( $opt( 'uxstudio_admin_customiser' )['menu_organizer_enabled'] ?? false ) );
$assert( 'Adminimize (real): no module besides Admin Customiser needed', array() === SourceRegistry::get( 'adminimize' )->extra_modules() );
$ver = array_column( $r['verify'], null, 'id' );
$assert( 'Adminimize (real): editor menu agrees (known differences only)', ! empty( $ver['menu-role:editor']['ok'] ), $j( $ver['menu-role:editor'] ?? null ) );
$tbl = array_column( $plan['menu_table'], null, 'role' );
$assert( 'Adminimize (real): per-role table: editor hides 20 before and the same 20 after', 20 === count( $tbl['editor']['before'] ) && $tbl['editor']['before'] === $tbl['editor']['after'] && 0 === count( $tbl['subscriber']['after'] ), $j( array( count( $tbl['editor']['before'] ), count( $tbl['editor']['after'] ), count( $tbl['subscriber']['after'] ) ) ) );
// The editor of the fake site sees nothing from the whitelist-only items through MenuSimulator.
$assert( 'Adminimize (real): MenuSimulator agrees with the Organizer rules (editor cannot see Themes, administrator can)', ! MenuSimulator::visible( $cfg, 'themes.php', array( 'editor' ), false ) && MenuSimulator::visible( $cfg, 'themes.php', array( 'administrator' ), true ) && MenuSimulator::visible( $cfg, 'themes.php', array( 'subscriber' ), false ) );

/* Both menu plugins of pobyty migrated into one Menu Organizer config: AME gives the arrangement, Adminimize the role limits. */
$site( array( 'admin-menu-editor', 'adminimize' ), array( 'admin-menu-editor', 'adminimize' ), $ame_opt + $adm );
SourceRegistry::get( 'admin-menu-editor' )->migrate( array( 'run_id' => 'rboth' ), false );
SourceRegistry::get( 'adminimize' )->migrate( array( 'run_id' => 'rboth' ), false );
$cfg = $menu_cfg();
$assert( 'AME + Adminimize: the arrangement of AME survives and the role limits of Adminimize are added', count( $cfg['categories'] ) >= 5 && 'index.php' === $cfg['order']['group-1'][0] && 20 === count( $cfg['item_roles'] ), $j( array_keys( $cfg['categories'] ) ) );
$bad = array();
foreach ( array( 'admin-menu-editor', 'adminimize' ) as $slug ) {
	$bad = array_merge( $bad, array_filter( SourceRegistry::get( $slug )->verify(), static fn( $c ) => ! $c['ok'] ) );
}
$assert( 'AME + Adminimize: both verify ok (each is checked only on the items it has a rule for)', array() === $bad, $j( $bad ) );
$second = SourceRegistry::get( 'adminimize' )->migrate( array( 'run_id' => 'rboth' ), false );
$assert( 'AME + Adminimize: running Adminimize again changes nothing', 0 === $second['written'] );

/* Adminimize: admin bar, global options, dashboard, lockout rule, bbPress-free synthetic extras. */
$roles8 = array( 'administrator', 'editor', 'author', 'contributor', 'subscriber', 'shop_manager', 'customer', 'translator' );
$per = static function ( string $prefix, array $by_role ) use ( $roles8 ): array {
	$o = array();
	foreach ( $roles8 as $role ) {
		$o[ 'mw_adminimize_disabled_' . $prefix . $role . '_items' ] = $by_role[ $role ] ?? array();
	}
	return $o;
};
$syn = array_merge(
	$per( 'menu_', array_merge( array_fill_keys( $roles8, array( 'plugins.php' ) ), array( 'administrator' => array( 'plugins.php', 'tools.php' ), 'subscriber' => array( 'plugins.php', 'users.php' ) ) ) ),
	$per( 'submenu_', array( 'editor' => array( 'options-general.php__1' ) ) ),
	$per( 'admin_bar_', array_fill_keys( $roles8, array( 'wp-logo', 'comments' ) ) ),
	$per( 'admin_bar_frontend_', array( 'administrator' => array( 'wp-logo' ), 'editor' => array( 'search' ) ) ),
	$per( 'global_option_', array( 'subscriber' => array( '.show-admin-bar', '.admin-notices' ), 'administrator' => array( '#screen-options, #screen-options-link-wrap' ), 'editor' => array( '#contextual-help-link-wrap' ) ) ),
	$per( 'dashboard_option_', array( 'administrator' => array( 'dashboard_primary' ), 'editor' => array( 'dashboard_primary', 'dashboard_activity' ) ) ),
	array(
		'mw_adminimize_default_menu'    => array( array( 'Plugins', 'activate_plugins', 'plugins.php', '', 'menu-top' ), array( 'Tools', 'edit_posts', 'tools.php', '', 'menu-top' ), array( 'Users', 'list_users', 'users.php', '', 'menu-top' ), array( 'Settings', 'manage_options', 'options-general.php', '', 'menu-top' ) ),
		'mw_adminimize_default_submenu' => array( 'options-general.php' => array( 0 => array( 'General', 'manage_options', 'options-general.php' ), 1 => array( 'Writing', 'manage_options', 'options-writing.php' ) ) ),
		'mw_adminimize_disabled_metaboxes_post_editor_items' => array( '#commentstatusdiv' ),
		'_mw_adminimize_footer'         => 1,
		'mw_adminimize_prevent_page_access' => 1,
	)
);
$site( array( 'adminimize' ), array( 'adminimize' ), array( 'mw_adminimize' => $syn ) );
$plan = SourceRegistry::get( 'adminimize' )->plan();
$r    = $exercise( 'adminimize' );
$cfg  = $menu_cfg();
$st   = (array) $opt( 'uxstudio_admin_customiser' );
$assert( 'Adminimize (synthetic): LOCKOUT RULE: Plugins hidden for every role (administrators too) is not transferred, with a warning in the plan and the lossy list', ! in_array( 'plugins.php', $cfg['hidden_items'], true ) && ! isset( $cfg['item_roles']['plugins.php'] ) && (bool) preg_grep( '/hides "Plugins"/', $plan['warnings'] ) && (bool) preg_grep( '/hides "Plugins"/', $plan['lossy'] ), $j( array( $cfg['hidden_items'], $plan['warnings'] ) ) );
$assert( 'Adminimize (synthetic): Tools hidden for the administrator role only stays visible, and lossy says so', ! isset( $cfg['item_roles']['tools.php'] ) && (bool) preg_grep( '/"Tools" is hidden from Administrator/', $plan['lossy'] ), $j( $plan['lossy'] ) );
$assert( 'Adminimize (synthetic): Users hidden for subscribers -> whitelist', isset( $cfg['item_roles']['users.php'] ) && ! in_array( 'subscriber', $cfg['item_roles']['users.php'], true ) );
$assert( 'Adminimize (synthetic): admin bar for subscribers -> Hide Admin Bar module, exactly that role', array( 'subscriber' ) === ( $opt( 'uxstudio_hide_admin_bar' )['roles'] ?? null ) && array( 'hide-admin-bar', 'dashboard-widgets' ) === SourceRegistry::get( 'adminimize' )->extra_modules(), $j( array( $opt( 'uxstudio_hide_admin_bar' ), SourceRegistry::get( 'adminimize' )->extra_modules() ) ) );
$assert( 'Adminimize (synthetic): WordPress logo and comments hidden everywhere -> hidden elements', array( 'wp_logo', 'comments' ) === ( $st['hide_admin_bar_elements'] ?? null ), $j( $st['hide_admin_bar_elements'] ?? null ) );
$assert( 'Adminimize (synthetic): screen options hidden for administrators -> global switch with a warning', true === ( $st['hide_screen_options'] ?? false ) && (bool) preg_grep( '/Screen Options.*everybody/', $plan['warnings'] ), $j( $plan['warnings'] ) );
$assert( 'Adminimize (synthetic): help tab / notices only for non-admin roles are NOT switched on globally', empty( $st['hide_help_tab'] ) && empty( $st['hide_notices_enabled'] ) && (bool) preg_grep( '/contextual-help-link-wrap|admin-notices/', $plan['lossy'] ), $j( $plan['lossy'] ) );
$assert( 'Adminimize (synthetic): dashboard widget hidden for administrators and editors -> hidden_widgets with a lossy remark about the other roles', array( 'dashboard_primary' ) === ( $opt( 'uxstudio_dashboard_widgets' )['hidden_widgets'] ?? null ) && (bool) preg_grep( '/dashboard_primary/', $plan['lossy'] ), $j( array( $opt( 'uxstudio_dashboard_widgets' ), $plan['lossy'] ) ) );
$lossy = implode( ' | ', $plan['lossy'] );
$assert( 'Adminimize (synthetic): lossy lists meta boxes, hidden footer, sub-menu hiding, prevent page access', false !== strpos( $lossy, 'meta boxes' ) && false !== strpos( $lossy, 'hidden admin footer' ) && false !== strpos( $lossy, 'Settings / Writing' ) && false !== strpos( $lossy, 'Prevent page access' ), $lossy );
$common( 'Adminimize (synthetic)', $r );

/* ══ White Label CMS: the real configuration of pobyty ══ */

$wl = $fx( 'wlcms_options.json' );
$site( array( 'white-label-cms' ), array( 'white-label-cms' ), $wl );
AbstractAdminSource::set_media_double( static fn( string $url ): int => false !== strpos( $url, 'logo-TM_bile.png' ) ? 321 : 0 );
$r    = $exercise( 'white-label-cms' );
$common( 'WLCMS (real)', $r );
$st   = (array) $opt( 'uxstudio_admin_customiser' );
$plan = $r['plan'];
$assert( 'WLCMS (real): login logo URL becomes an attachment id (media field)', 321 === ( $st['login_logo'] ?? null ), $j( $st['login_logo'] ?? null ) );
$assert( 'WLCMS (real): login colours', '#000000' === ( $st['login_background_color'] ?? '' ) && '#999999' === ( $st['login_button_bg_color'] ?? '' ) && '#ffffff' === ( $st['login_button_text_color'] ?? '' ) && '#999999' === ( $st['login_text_color'] ?? '' ), $j( $st ) );
$assert( 'WLCMS (real): login customization switched on, hide WordPress logo -> hidden element', true === ( $st['login_customization_enabled'] ?? false ) && array( 'wp_logo' ) === ( $st['hide_admin_bar_elements'] ?? null ) );
$assert( 'WLCMS (real): dashboard widgets hidden for administrators -> Dashboard Widgets module gets the ids; roles not covered are reported', array( 'e-dashboard-overview', 'wordfence_activity_report_widget', 'wpseo-dashboard-overview', 'wpseo-wincher-dashboard-overview', 'dashboard_primary' ) === ( $opt( 'uxstudio_dashboard_widgets' )['hidden_widgets'] ?? null ) && (bool) preg_grep( '/Shop manager/', $plan['lossy'] ), $j( array( $opt( 'uxstudio_dashboard_widgets' ), $plan['lossy'] ) ) );
$assert( 'WLCMS (real): lossy lists the hidden "Back to site" link, the page title and the footer', (bool) preg_grep( '/hidden "Back to the site" link/', $plan['lossy'] ) && (bool) preg_grep( '/admin page title suffix/', $plan['lossy'] ) && (bool) preg_grep( '/empties the admin footer/', $plan['lossy'] ), $j( $plan['lossy'] ) );
$assert( 'WLCMS (real): extra modules for the dashboard widgets', array( 'dashboard-widgets' ) === SourceRegistry::get( 'white-label-cms' )->extra_modules() );
// Widgets the module has not seen on a dashboard yet are named, not silently dropped.
$narrow = $schemas;
$narrow['dashboard-widgets'][1]['options'] = array_fill_keys( array( 'dashboard_primary', 'e-dashboard-overview' ), '' );
SettingsWriter::set_schema_resolver( static fn( string $m ) => $narrow[ $m ] ?? null );
$p = SourceRegistry::get( 'white-label-cms' )->plan();
$assert( 'WLCMS: widgets the Dashboard Widgets module does not know yet are listed, with the advice to open the dashboard', (bool) preg_grep( '/wordfence_activity_report_widget, wpseo-dashboard-overview/', $p['lossy'] ) && (bool) preg_grep( '/Open the dashboard once/', $p['lossy'] ), $j( $p['lossy'] ) );
SettingsWriter::set_schema_resolver( static fn( string $m ) => $schemas[ $m ] ?? null );
// A logo that is not in the media library is not invented.
AbstractAdminSource::set_media_double( static fn( string $url ): int => 0 );
$site( array( 'white-label-cms' ), array( 'white-label-cms' ), $wl );
$p = SourceRegistry::get( 'white-label-cms' )->plan();
$assert( 'WLCMS: a logo outside the media library is reported, not written', (bool) preg_grep( '/not in the media library/', $p['lossy'] ) && ! in_array( 'login_logo', array_column( $p['transfer'], 'key' ), true ) );
// Footer, admin bar logo, help/screen options, nag messages, welcome panel.
$wl2 = $wl['wlcms_options'];
$wl2['footer_html'] = '<p>Made by <a href="https://x.test">Us</a></p>';
$wl2['admin_bar_logo'] = 'https://127.0.0.1/pobyty/wp-content/uploads/2024/01/bar.png';
$wl2['hide_help_box'] = '1';
$wl2['hide_screen_options'] = '1';
$wl2['hide_nag_messages'] = '1';
$wl2['hide_admin_bar_all'] = '1';
$wl2['dashboard_widgets_visibility_roles'] = array( 'editor' );
$wl2['welcome_panel'] = array( array( 'title' => 'Hi', 'description' => 'Hello' ) );
$wl2['login_custom_css'] = 'body{}';
$site( array( 'white-label-cms' ), array( 'white-label-cms' ), array( 'wlcms_options' => $wl2 ) );
AbstractAdminSource::set_media_double( static fn( string $url ): int => 55 );
$p = SourceRegistry::get( 'white-label-cms' )->plan();
SourceRegistry::get( 'white-label-cms' )->migrate( array( 'run_id' => 'rwl2' ), false );
$st = (array) $opt( 'uxstudio_admin_customiser' );
$assert( 'WLCMS (synthetic): footer HTML is reduced to text, with a lossy line', 'Made by Us' === ( $st['admin_footer_text'] ?? '' ) && (bool) preg_grep( '/footer is HTML/', $p['lossy'] ) );
$assert( 'WLCMS (synthetic): admin bar logo -> attachment', 55 === ( $st['admin_bar_logo'] ?? null ) );
$assert( 'WLCMS (synthetic): help, screen options, nag messages', true === ( $st['hide_help_tab'] ?? false ) && true === ( $st['hide_screen_options'] ?? false ) && true === ( $st['hide_notices_enabled'] ?? false ) && array( 'core' ) === ( $st['global__update_notifications'] ?? null ) );
$assert( 'WLCMS (synthetic): hide admin bar for everybody but the administrator role', array_values( array_diff( $roles8, array( 'administrator' ) ) ) === ( $opt( 'uxstudio_hide_admin_bar' )['roles'] ?? null ) );
$assert( 'WLCMS (synthetic): dashboard hidden only for editors is NOT applied globally; welcome panel and custom CSS are lossy', empty( $opt( 'uxstudio_dashboard_widgets' )['hidden_widgets'] ) && (bool) preg_grep( '/custom welcome panel/', $p['lossy'] ) && (bool) preg_grep( '/own CSS of the login screen/', $p['lossy'] ), $j( $p['lossy'] ) );

/* ══ Adminify: the real configuration of pobyty ══ */

$ad = $fx( 'wpadminify.json' );
$site( array( 'adminify' ), array(), $ad );
$r    = $exercise( 'adminify' );
$common( 'Adminify (real)', $r );
$cfg  = $menu_cfg();
$plan = $r['plan'];
$assert( 'Adminify (real): order and "separator after" give groups; Dashboard first', 'index.php' === $cfg['order']['group-1'][0] && count( $cfg['categories'] ) >= 5, $j( $cfg['categories'] ?? null ) );
$assert( 'Adminify (real): quick search follows the Adminify admin bar search switch', true === ( $opt( 'uxstudio_admin_customiser' )['quick_search_enabled'] ?? null ) );
$assert( 'Adminify (real): the footer text Adminify ships is not copied', empty( $opt( 'uxstudio_admin_customiser' )['admin_footer_text'] ) && (bool) preg_grep( '/Developed by WP Adminify/', $plan['lossy'] ), $j( $plan['lossy'] ) );
$assert( 'Adminify (real): horizontal layout, its own interface and sub-menu notes are honestly lossy', (bool) preg_grep( '/horizontal menu layout/', $plan['lossy'] ) && (bool) preg_grep( '/own admin interface/', $plan['lossy'] ), $j( $plan['lossy'] ) );
$assert( 'Adminify (real): inactive plugin still migrates, with the "not active" warning', (bool) preg_grep( '/not active/', $plan['warnings'] ) );

/* ══ LoginPress (fixture from the plugin code) ══ */

$lp = array(
	'loginpress_customization' => array(
		'setting_logo' => 'https://example.test/site/wp-content/uploads/2025/01/logo.png', 'setting_logo_display' => 1,
		'setting_background_color' => '#101820', 'setting_background' => 'https://example.test/bg.jpg', 'custom_button_color' => '#ff6600',
		'button_text_color' => '#ffffff', 'button_hover_color' => '#cc5200', 'form_background_color' => '#ffffff', 'textfield_background_color' => '#f4f4f4',
		'textfield_color' => '#222222', 'textfield_label_color' => '#333333', 'customize_form_radius' => '12', 'welcome_message' => 'Vítejte zpět',
		'login_footer_color' => 'rgba(0,0,0,.5)', 'loginpress_custom_css' => '.x{}', 'lp_random_bg_img_check' => 1,
	),
	'loginpress_setting' => array( 'login_order' => 'default', 'session_expiration' => array( 'administrator' => 7 ), 'enable_privacy_policy' => 'on' ),
);
$site( array( 'loginpress' ), array( 'loginpress' ), $lp );
AbstractAdminSource::set_media_double( static fn( string $url ): int => 900 );
$r    = $exercise( 'loginpress' );
$common( 'LoginPress', $r );
$st   = (array) $opt( 'uxstudio_admin_customiser' );
$plan = $r['plan'];
$assert( 'LoginPress: logo, colours, radius, message, enabled flag', 900 === $st['login_logo'] && '#101820' === $st['login_background_color'] && '#ff6600' === $st['login_button_bg_color'] && '#ffffff' === $st['login_button_text_color'] && '#ffffff' === $st['login_form_bg_color'] && '#f4f4f4' === $st['login_input_bg_color'] && '#222222' === $st['login_input_text_color'] && '#333333' === $st['login_form_text_color'] && 12 === $st['login_form_border_radius'] && 'Vítejte zpět' === $st['login_message'] && true === $st['login_customization_enabled'], $j( $st ) );
$assert( 'LoginPress: a non-hex colour (rgba) is reported, not written', empty( $st['login_text_color'] ) && (bool) preg_grep( '/not a plain hex colour/', $plan['lossy'] ) );
$assert( 'LoginPress: lossy lists background image, hover colours, own CSS and the behaviour settings', (bool) preg_grep( '/background image.*button hover colours|button hover colours.*background image|background image/', $plan['lossy'] ) && (bool) preg_grep( '/Behaviour settings/', $plan['lossy'] ), $j( $plan['lossy'] ) );
$site( array( 'loginpress' ), array( 'loginpress' ), array( 'loginpress_customization' => array( 'setting_logo' => 'https://example.test/x.png', 'setting_logo_display' => 0 ) ) );
AbstractAdminSource::set_media_double( static fn( string $url ): int => 5 );
SourceRegistry::get( 'loginpress' )->migrate( array( 'run_id' => 'rlp' ), false );
$assert( 'LoginPress: logo display off -> hide_login_logo', array( 'hide_login_logo' ) === ( $opt( 'uxstudio_admin_customiser' )['form_options'] ?? null ) );

/* ══ Admin Columns (fixture from the plugin code: AC 4+ rows and the old cpac_options) ══ */

$lists = array(
	array(
		'list_key' => 'post', 'list_id' => 'abc', 'title' => 'Original', 'status' => '', 'settings' => array(),
		'columns'  => array(
			'cb'    => array( 'type' => 'cb', 'label' => '' ),
			'title' => array( 'type' => 'title', 'label' => 'Nadpis', 'width' => '30', 'width_unit' => '%' ),
			'5f1'   => array( 'type' => 'column-meta', 'label' => 'Cena', 'field' => '_price', 'field_type' => 'numeric', 'width' => '90', 'width_unit' => 'px' ),
			'5f2'   => array( 'type' => 'column-featured_image', 'label' => 'Obrázek' ),
			'5f3'   => array( 'type' => 'column-postid', 'label' => 'ID' ),
			'5f4'   => array( 'type' => 'column-taxonomy', 'label' => 'Téma', 'taxonomy' => 'topic' ),
			'5f5'   => array( 'type' => 'column-word_count', 'label' => 'Slova' ),
			'5f6'   => array( 'type' => 'column-meta', 'label' => 'Pole', 'field' => 'acf_arr', 'field_type' => 'array' ),
			'date'  => array( 'type' => 'date', 'label' => 'Datum' ),
		),
	),
	array(
		'list_key' => 'post', 'list_id' => 'abd', 'title' => 'Editor layout', 'status' => '', 'settings' => array( 'roles' => array( 'editor' ) ),
		'columns'  => array( 'title' => array( 'type' => 'title', 'label' => 'T' ) ),
	),
	array(
		'list_key' => 'wp-users', 'list_id' => 'u1', 'title' => 'Users', 'status' => '', 'settings' => array(),
		'columns'  => array( 'username' => array( 'type' => 'username', 'label' => 'Login' ), 'email' => array( 'type' => 'email', 'label' => 'Mail' ), '5u' => array( 'type' => 'column-user_id', 'label' => '#' ) ),
	),
	array( 'list_key' => 'wp-taxonomy_category', 'list_id' => 't1', 'title' => 'Categories', 'status' => '', 'settings' => array(), 'columns' => array( 'name' => array( 'type' => 'name' ) ) ),
);
$site( array( 'codepress-admin-columns' ), array( 'codepress-admin-columns' ) );
AdminColumnsSource::set_reader( static fn(): array => $lists );
$r    = $exercise( 'codepress-admin-columns' );
$common( 'Admin Columns', $r );
$ac   = (array) $opt( 'uxstudio_admin_columns' );
$plan = $r['plan'];
$by_key = static fn( array $cols ): array => array_column( $cols, null, 'key' );
$post = $by_key( $ac['post'] ?? array() );
$assert( 'Admin Columns: post list keeps the order of Admin Columns (unsupported word count left out)', array( 'title', '_price', 'featured_image', 'item_id', 'topic', 'acf_arr', 'date' ) === array_slice( array_column( $ac['post'], 'key' ), 0, 7 ), $j( array_column( $ac['post'] ?? array(), 'key' ) ) );
$assert( 'Admin Columns: native column renamed with width', 'Nadpis' === $post['title']['label'] && '30%' === $post['title']['width'] && 'default' === $post['title']['type'] );
$assert( 'Admin Columns: custom field column with numeric display type and pixel width', 'meta' === $post['_price']['type'] && 'number' === $post['_price']['field_type'] && '90px' === $post['_price']['width'] && 'Cena' === $post['_price']['label'] );
$assert( 'Admin Columns: featured image, ID and taxonomy columns', 'thumbnail' === $post['featured_image']['type'] && 'post_id' === $post['item_id']['type'] && 'taxonomy' === $post['topic']['type'] );
$assert( 'Admin Columns: native columns Admin Columns removed are switched off (author, categories, tags, comments)', ! $post['author']['enabled'] && ! $post['categories']['enabled'] && ! $post['tags']['enabled'] && ! $post['comments']['enabled'] && $post['title']['enabled'] );
$assert( 'Admin Columns: users list is mapped (wp-users -> users) and the other native columns are off', 'users' === ( array_keys( $ac )[1] ?? '' ) && ! ( $by_key( $ac['users'] )['role']['enabled'] ?? true ) && 'post_id' === $by_key( $ac['users'] )['item_id']['type'], $j( array_keys( $ac ) ) );
$assert( 'Admin Columns: unsupported types, unknown display types, taxonomy screens and extra layouts are lossy', (bool) preg_grep( '/column-word_count/', $plan['lossy'] ) && (bool) preg_grep( '/array/', $plan['lossy'] ) && (bool) preg_grep( '/wp-taxonomy_category/', $plan['lossy'] ) && (bool) preg_grep( '/Several layouts/', $plan['lossy'] ), $j( $plan['lossy'] ) );
$assert( 'Admin Columns: snapshot covers the module option', in_array( 'uxstudio_admin_columns', SourceRegistry::get( 'codepress-admin-columns' )->target_options(), true ) );
$assert( 'Admin Columns: a stored value rendered back by the module normalizer is identical (round trip)', $ac['post'] === array_map( array( \UxStudio\Modules\AdminColumns\Module::class, 'clean_column' ), $ac['post'] ) );
// Old storage (cpac_options_*) through the same path.
$GLOBALS['wpdb'] = new class extends FakeWpdb {
	public function get_results( $sql, $output = null ) {
		if ( false !== strpos( $sql, 'cpac_options_' ) ) {
			return array( array( 'option_name' => 'cpac_options_page', 'option_value' => serialize( array( 'title' => array( 'type' => 'title', 'label' => 'Titulek' ) ) ) ) );
		}
		return parent::get_results( $sql, $output );
	}
};
$site( array( 'codepress-admin-columns' ), array() );
$p = SourceRegistry::get( 'codepress-admin-columns' )->plan();
$assert( 'Admin Columns: older cpac_options_* storage is read (page list)', 1 === $p['counts']['transfer'] && 'page' === ( SourceRegistry::get( 'codepress-admin-columns' )->migrate( array( 'run_id' => 'rold' ), true )['items'][0]['key'] ?? '' ) );
$GLOBALS['wpdb'] = new FakeWpdb();

/* ══ Staging: Admin Customiser module already ENABLED ══ */

$site( array( 'adminimize' ), array( 'adminimize' ), array( 'mw_adminimize' => $syn, 'uxstudio_active_modules' => array( 'activity-log', 'admin-customiser' ), 'uxstudio_admin_customiser' => array( 'hide_screen_options' => false, 'primary_color' => '#112233' ) ) );
$live_settings = $GLOBALS['uxs_options']['uxstudio_admin_customiser'];
$p = SourceRegistry::get( 'adminimize' )->plan();
$assert( 'staging: plan rows of the menu config are marked staged', (bool) array_filter( $p['transfer'], static fn( $row ) => ! empty( $row['option'] ) && $row['staged'] ) && (bool) preg_grep( '/only prepared/', $p['warnings'] ) );
$run = Runner::start( array( 'adminimize' ), false );
$step = Runner::step();
$rid  = $run['id'];
$assert( 'staging: live menu config and module settings are untouched while the module is on', ! array_key_exists( MenuOrganizer::CONFIG_OPTION, $GLOBALS['uxs_options'] ) && $GLOBALS['uxs_options']['uxstudio_admin_customiser'] === $live_settings );
$entry = Staging::entry( $rid );
$assert( 'staging: the menu config waits in the staging area (options section)', isset( $entry['options'][ MenuOrganizer::CONFIG_OPTION ]['values']['item_roles'] ) && 'admin-customiser' === $entry['options'][ MenuOrganizer::CONFIG_OPTION ]['module'], $j( array_keys( (array) $entry ) ) );
$assert( 'staging: the summary counts option keys', Staging::summary( $rid )['settings'] >= 6 && in_array( 'admin-customiser', Staging::summary( $rid )['modules'], true ) );
$assert( 'staging: pending_options layer the staged keys over the live option', array_key_exists( 'item_roles', Staging::pending_options( MenuOrganizer::CONFIG_OPTION ) ) );
$ver = Runner::verify();
$flat = array();
foreach ( $ver as $checks ) { foreach ( $checks as $c ) { $flat[] = $c; } }
$assert( 'staging: verify is ok and flagged "staged" (menu rows and per-role rows)', array() === array_filter( $flat, static fn( $c ) => ! $c['ok'] ) && (bool) array_filter( $flat, static fn( $c ) => 0 === strpos( $c['id'], 'menu-role:' ) && ! empty( $c['staged'] ) ), $j( array_filter( $flat, static fn( $c ) => ! $c['ok'] ) ) );
$before_second = $state();
$second = SourceRegistry::get( 'adminimize' )->migrate( array( 'run_id' => 'rsecond' ), false );
$assert( 'staging: a second run stages and writes nothing new', 0 === $second['written'] && 0 === $second['staged'] && $before_second === $state() );
$res = Staging::apply( $rid );
$cfg = $menu_cfg();
$assert( 'Staging::apply writes the menu config (merged into the live option) and the module settings', isset( $cfg['item_roles']['users.php'] ) && true === $GLOBALS['uxs_options']['uxstudio_admin_customiser']['menu_organizer_enabled'] && '#112233' === $GLOBALS['uxs_options']['uxstudio_admin_customiser']['primary_color'] && $res['settings'] > 0, $j( $res ) );
$assert( 'Staging::apply is idempotent for options too', 0 === Staging::apply( $rid )['settings'] );
// Rollback of a staged run leaves nothing behind.
$site( array( 'adminimize' ), array( 'adminimize' ), array( 'mw_adminimize' => $syn, 'uxstudio_active_modules' => array( 'activity-log', 'admin-customiser' ) ) );
$before = $state();
$run = Runner::start( array( 'adminimize' ), false );
Runner::step();
$rb = Runner::rollback( $run['id'] );
$own = array( Runner::OPTION => 1, 'uxstudio_migration_runs' => 1 );
$assert( 'rollback of a staged menu run: staging discarded, options as before', ! is_wp_error( $rb ) && null === Staging::entry( $run['id'] ) && array_diff_key( $state(), $own ) === $before, is_wp_error( $rb ) ? $rb->get_error_message() : '' );
// Direct write (module off) + rollback restores the new options.
$site( array( 'admin-menu-editor' ), array( 'admin-menu-editor' ), $ame_opt );
$before = $state();
$run = Runner::start( array( 'admin-menu-editor' ), false );
Runner::step();
$assert( 'module off: menu config is written directly', array() !== $menu_cfg() && array() === Staging::all() );
$rb = Runner::rollback( $run['id'] );
$assert( 'rollback removes the menu config and the module settings again', ! is_wp_error( $rb ) && array_diff_key( $state(), $own ) === $before );

/* ══ Lockout rule as a pure function ══ */

$cfg_bad = array( 'hidden_items' => array( 'plugins.php', 'edit.php' ), 'item_roles' => array( 'users.php' => array( 'editor' ), 'tools.php' => array( 'editor' ) ) );
$GLOBALS['uxs_user'] = array( 'roles' => array( 'administrator' ), 'can' => true );
$out = MenuSimulator::enforce_access( $cfg_bad );
$assert( 'enforce_access: drops the hide of Plugins for administrators, keeps other rules', array( 'edit.php' ) === $out['config']['hidden_items'] && array( 'plugins.php' => 'hidden' ) === $out['dropped'] && isset( $out['config']['item_roles']['tools.php'] ), $j( $out ) );
$GLOBALS['uxs_user'] = array( 'roles' => array( 'shop_manager' ), 'can' => false );
$out = MenuSimulator::enforce_access( array( 'item_roles' => array( 'users.php' => array( 'editor' ), 'plugins.php' => array( 'administrator', 'shop_manager' ) ) ) );
$assert( 'enforce_access: a role limit that would lock the current (non-administrator) user out is dropped', ! isset( $out['config']['item_roles']['users.php'] ) && isset( $out['config']['item_roles']['plugins.php'] ) && array( 'users.php' => 'roles' ) === $out['dropped'], $j( $out ) );
$GLOBALS['uxs_user'] = array( 'roles' => array( 'administrator' ), 'can' => true );
$assert( 'visible(): administrators bypass role limits but not hidden_items', MenuSimulator::visible( array( 'item_roles' => array( 'a' => array( 'editor' ) ) ), 'a', array( 'administrator' ), true ) && ! MenuSimulator::visible( array( 'hidden_items' => array( 'a' ) ), 'a', array( 'administrator' ), true ) );
$tm = MenuSimulator::target_matrix( array( 'hidden_items' => array( 'x' ), 'item_roles' => array( 'y' => array( 'editor' ) ) ), array( 'x', 'y', 'z' ), array( 'z' => array( 's' ) ), array( 'editor', 'author', 'administrator' ), array( 'administrator' ) );
$assert( 'target_matrix: per role, sub-items follow their parent', array( 'y', 'z', 'z>s' ) === $tm['editor'] && array( 'z', 'z>s' ) === $tm['author'] && array( 'y', 'z', 'z>s' ) === $tm['administrator'], $j( $tm ) );

/* ══ Smoke check: the administrator can still open the four screens ══ */

$smoke = new ReflectionMethod( Smoke::class, 'check_admin_access' );
$smoke->setAccessible( true );
$http = static function ( array $answers ) {
	return static function ( string $url, array $args ) use ( $answers ) {
		$path = preg_replace( '/[?&]uxs_cb=[a-f0-9]+/', '', substr( $url, strlen( 'https://example.test/site/wp-admin/' ) ) );
		$mode = isset( $args['headers']['X-UXS-Preview'] ) ? 'preview' : 'live';
		$a    = $answers[ $mode ][ $path ] ?? array( 200, '<html>ok</html>' );
		return array( 'code' => $a[0], 'body' => $a[1], 'headers' => 'preview' === $mode ? array( 'X-UXS-Preview-Active' => 'r1' ) : array() );
	};
};
$check = array( 'urls' => MenuSimulator::protected_urls() );
Smoke::set_transport( $http( array() ) );
$res = $smoke->invoke( null, $check, 'tok' );
$assert( 'smoke admin_access: all four open in the preview -> ok', 'ok' === $res['status'] && false !== strpos( $res['detail'], '4 of 4' ), $j( $res ) );
Smoke::set_transport( $http( array( 'preview' => array( 'plugins.php' => array( 200, '<body id="error-page"><div class="wp-die-message">Sorry, you are not allowed to access this page.</div></body>' ) ) ) ) );
$res = $smoke->invoke( null, $check, 'tok' );
$assert( 'smoke admin_access: "not allowed" in the preview but open now -> fail', 'fail' === $res['status'] && false !== strpos( $res['detail'], 'plugins.php' ), $j( $res ) );
Smoke::set_transport( $http( array( 'preview' => array( 'users.php' => array( 403, 'Forbidden' ) ), 'live' => array( 'users.php' => array( 403, 'Forbidden' ) ) ) ) );
$res = $smoke->invoke( null, $check, 'tok' );
$assert( 'smoke admin_access: refused before the migration too (old plugin) -> warning only', 'warn' === $res['status'], $j( $res ) );
Smoke::set_transport( $http( array( 'live' => array( 'options-general.php' => array( 200, '<body id="error-page"><div class="wp-die-message">Nemáte oprávnění k přístupu na tuto stránku.</div></body>' ) ) ) ) );
$res = $smoke->invoke( null, $check, null );
$assert( 'smoke admin_access: live site, Czech refusal text -> fail', 'fail' === $res['status'], $j( $res ) );
$assert( 'Smoke::is_denied recognises 403 and the WordPress error screen in both languages', Smoke::is_denied( array( 'code' => 403, 'body' => '' ) ) && Smoke::is_denied( array( 'code' => 200, 'body' => '<body id="error-page"><div class="wp-die-message">Sorry, you are not allowed to access this page.</div></body>' ) ) && Smoke::is_denied( array( 'code' => 200, 'body' => '<body id="error-page"><div class="wp-die-message">Nemáte dostatečná oprávnění</div>' ) ) && ! Smoke::is_denied( array( 'code' => 200, 'body' => '<h1>Plugins</h1>' ) ) );
$assert( 'Smoke::is_denied is NOT fooled by the same sentence inside a translated JavaScript string of a normal admin page (found live on pobyty)', ! Smoke::is_denied( array( 'code' => 200, 'body' => '<body class="wp-admin"><script>var s={"%s: Sorry, you are not allowed to upload this file type.":["Nemáte dostatečná oprávnění"]}</script></body>' ) ) );
Smoke::set_transport( null );

/* ── Typo guard: every Admin Customiser key written exists in the real schema ── */

$ac_keys = array_column( $ac_schema, 'key' );
$bad = array();
foreach ( array( 'login_logo', 'login_background_color', 'login_text_color', 'login_form_bg_color', 'login_form_text_color', 'login_form_border_radius', 'login_input_bg_color', 'login_input_text_color', 'login_button_bg_color', 'login_button_text_color', 'login_message', 'form_options', 'login_customization_enabled', 'admin_footer_text', 'hide_screen_options', 'hide_help_tab', 'menu_organizer_enabled', 'admin_bar_logo', 'hide_admin_bar_elements', 'hide_notices_enabled', 'hide_notices_relocate', 'global__update_notifications', 'quick_search_enabled' ) as $k ) {
	if ( ! in_array( $k, $ac_keys, true ) ) { $bad[] = $k; }
}
$assert( 'every Admin Customiser key the F5 sources write is in the real schema', array() === $bad, implode( ',', $bad ) );
$assert( 'hide-admin-bar.roles and dashboard-widgets keys exist in their module code', $key_in_module( 'hide-admin-bar', 'roles' ) && $key_in_module( 'dashboard-widgets', 'hidden_widgets' ) && $key_in_module( 'dashboard-widgets', 'disable_all_widgets' ) );
$assert( 'the Menu Organizer and Admin Columns option names match the module code', 1 === preg_match( "/CONFIG_OPTION = 'uxstudio_admin_customiser_menu_config'/", (string) file_get_contents( $root . '/includes/Modules/AdminCustomiser/MenuOrganizer.php' ) ) && 1 === preg_match( "/OPTION = 'uxstudio_admin_columns'/", (string) file_get_contents( $root . '/includes/Modules/AdminColumns/Module.php' ) ) );

echo PHP_EOL . ( $failures ? "$failures FAILED" : 'ALL PASSED' ) . PHP_EOL;
exit( $failures ? 1 : 0 );
