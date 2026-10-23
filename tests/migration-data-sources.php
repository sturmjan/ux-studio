<?php
/**
 * Standalone test of the migration wizard sources with DATA (PLAN.md 26, phase F3): every source reads
 * its fixture in the real format of the foreign plugin, maps it, reports what is lost, the batch engine is
 * idempotent, behaviour-changing data waits in the staging area while the target module is on, and the rollback
 * removes exactly what a run created. Needs no WordPress and no database:
 *
 *   php tests/migration-data-sources.php
 *
 * The writers that talk to WordPress modules (Repository::save, SnippetManager, term API) are replaced by a
 * test double (AbstractSource::set_data_double) here and exercised against the real site by the live checks.
 * The post / user meta sources run their real writers on an in-memory meta store.
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
$GLOBALS['uxs_posts']   = array();
$GLOBALS['uxs_meta']    = array( 'post' => array(), 'user' => array() );
$GLOBALS['uxs_terms']   = array();
$GLOBALS['uxs_tables']  = array();
$GLOBALS['uxs_sql']     = array();
$GLOBALS['uxs_cron']    = array();
$GLOBALS['uxs_actions'] = array();

class WP_Error {
	public $code;
	public $message;
	public $data;
	public function __construct( $code = '', $message = '', $data = '' ) { $this->code = $code; $this->message = $message; $this->data = $data; }
	public function get_error_code() { return $this->code; }
	public function get_error_message() { return $this->message; }
	public function get_error_data() { return $this->data; }
}

/** $wpdb: tables exist when listed; SELECTs are answered by registered handlers (regex => callable). */
class FakeWpdb {
	public $prefix = 'wp_';
	public $options = 'wp_options';
	public $posts = 'wp_posts';
	public $postmeta = 'wp_postmeta';
	public $usermeta = 'wp_usermeta';
	public $terms = 'wp_terms';
	public $term_taxonomy = 'wp_term_taxonomy';
	public $term_relationships = 'wp_term_relationships';
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
	public function esc_like( $s ) { return $s; }
	public function prepare( $sql, ...$args ) {
		if ( 1 === count( $args ) && is_array( $args[0] ) ) { $args = $args[0]; }
		$i = 0;
		return preg_replace_callback( '/%[sd]/', static function ( $m ) use ( &$i, $args ) {
			$v = $args[ $i++ ] ?? '';
			return '%d' === $m[0] ? (string) (int) $v : "'" . addslashes( (string) $v ) . "'";
		}, $sql );
	}
	private function answer( $sql ) {
		foreach ( $GLOBALS['uxs_sql'] as [ $re, $fn ] ) {
			if ( preg_match( $re, $sql, $m ) ) { return $fn( $m ); }
		}
		return null;
	}
	public function get_var( $sql ) {
		if ( preg_match( "/SHOW TABLES LIKE '([^']*)'/", $sql, $m ) ) {
			return in_array( $m[1], $GLOBALS['uxs_tables'], true ) ? $m[1] : null;
		}
		$r = $this->answer( $sql );
		return is_array( $r ) ? ( $r ? reset( $r ) : null ) : $r;
	}
	public function get_col( $sql ) { $r = $this->answer( $sql ); return is_array( $r ) ? $r : array(); }
	public function get_results( $sql, $output = null ) {
		if ( false !== strpos( $sql, 'uxstudio_migration_map' ) && preg_match( "/run_id = '([^']*)'/", $sql, $m ) ) {
			return array_values( array_filter( $this->map, static fn( $row ) => $row['run_id'] === $m[1] ) );
		}
		$r = $this->answer( $sql );
		return is_array( $r ) ? $r : array();
	}
	public function query( $sql ) { return 0; }
}
$GLOBALS['wpdb'] = new FakeWpdb();

/* ── WordPress function stubs ── */

function get_option( $name, $default = false ) { return array_key_exists( $name, $GLOBALS['uxs_options'] ) ? $GLOBALS['uxs_options'][ $name ] : $default; }
function update_option( $name, $value, $autoload = null ) { $GLOBALS['uxs_options'][ $name ] = $value; return true; }
function add_option( $name, $value = '', $dep = '', $autoload = null ) { if ( array_key_exists( $name, $GLOBALS['uxs_options'] ) ) { return false; } $GLOBALS['uxs_options'][ $name ] = $value; return true; }
function delete_option( $name ) { unset( $GLOBALS['uxs_options'][ $name ] ); return true; }
function apply_filters( $hook, $value, ...$args ) { return $value; }
function do_action( $hook, ...$args ) { $GLOBALS['uxs_actions'][] = $hook; }
function __( $text, $domain = '' ) { return $text; }
function _n( $single, $plural, $n, $domain = '' ) { return 1 === $n ? $single : $plural; }
function is_wp_error( $x ) { return $x instanceof WP_Error; }
function current_time( $type ) { return '2026-10-21 12:00:00'; }
function get_current_user_id() { return 1; }
function current_user_can( $cap ) { return true; }
function wp_salt( $scheme = '' ) { return 'test-salt-' . $scheme; }
function sanitize_text_field( $s ) { return trim( strip_tags( (string) $s ) ); }
function sanitize_textarea_field( $s ) { return trim( strip_tags( (string) $s ) ); }
function sanitize_hex_color( $s ) { return preg_match( '/^#[0-9a-f]{3,6}$/i', (string) $s ) ? (string) $s : ''; }
function sanitize_key( $s ) { return strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', (string) $s ) ); }
function absint( $v ) { return abs( (int) $v ); }
function wp_kses_post( $s ) { return (string) $s; }
function wp_strip_all_tags( $s ) { return trim( strip_tags( (string) $s ) ); }
function maybe_serialize( $v ) { return is_scalar( $v ) ? (string) $v : serialize( $v ); }
function wp_json_encode( $v ) { return json_encode( $v ); }
function trailingslashit( $s ) { return rtrim( $s, '/' ) . '/'; }
function home_url( $p = '' ) { return 'https://example.test/site' . $p; }
function wp_date( $fmt, $ts = null ) { return gmdate( $fmt, $ts ?? time() ); }
function wp_roles() { return (object) array( 'roles' => array( 'administrator' => array( 'capabilities' => array() ), 'editor' => array( 'capabilities' => array() ), 'author' => array( 'capabilities' => array() ), 'subscriber' => array( 'capabilities' => array() ) ) ); }
function get_plugins() { return $GLOBALS['uxs_plugins']; }
$GLOBALS['uxs_plugins'] = array();

function _get_cron_array() { return $GLOBALS['uxs_cron']; }
function wp_next_scheduled( $hook, $args = array() ) { foreach ( $GLOBALS['uxs_cron'] as $ts => $events ) { if ( isset( $events[ $hook ][ md5( serialize( $args ) ) ] ) || ( ! $args && isset( $events[ $hook ] ) ) ) { return $ts; } } return false; }
function wp_schedule_single_event( $ts, $hook, $args = array() ) { $GLOBALS['uxs_cron'][ $ts ][ $hook ][ md5( serialize( $args ) ) ] = array( 'args' => $args ); return true; }
function wp_unschedule_event( $ts, $hook, $args = array() ) { unset( $GLOBALS['uxs_cron'][ $ts ][ $hook ][ md5( serialize( $args ) ) ] ); if ( empty( $GLOBALS['uxs_cron'][ $ts ][ $hook ] ) ) { unset( $GLOBALS['uxs_cron'][ $ts ][ $hook ] ); } return true; }

/* posts, terms and meta (WordPress meta semantics: a key holds a list of values) */
function get_post( $id ) { return $GLOBALS['uxs_posts'][ (int) $id ] ?? null; }
function get_the_title( $id ) { $p = get_post( $id ); return $p ? $p->post_title : ''; }
function get_posts( $args ) {
	$types = (array) ( $args['post_type'] ?? array() );
	$stat  = (array) ( $args['post_status'] ?? array( 'publish' ) );
	return array_values( array_filter( $GLOBALS['uxs_posts'], static fn( $p ) => in_array( $p->post_type, $types, true ) && in_array( $p->post_status, $stat, true ) ) );
}
function get_userdata( $id ) { return (object) array( 'user_login' => 'user' . $id ); }
function metadata_exists( $type, $id, $key ) { return isset( $GLOBALS['uxs_meta'][ $type ][ $id ][ $key ] ); }
function get_metadata( $type, $id, $key, $single = false ) {
	$vals = $GLOBALS['uxs_meta'][ $type ][ $id ][ $key ] ?? array();
	return $single ? ( $vals ? $vals[0] : '' ) : $vals;
}
function add_metadata( $type, $id, $key, $value, $unique = false ) {
	if ( $unique && isset( $GLOBALS['uxs_meta'][ $type ][ $id ][ $key ] ) ) { return false; }
	$GLOBALS['uxs_meta'][ $type ][ $id ][ $key ][] = $value;
	return true;
}
function get_post_meta( $id, $key = '', $single = false ) { return get_metadata( 'post', $id, $key, $single ); }
function delete_post_meta( $id, $key ) { $had = isset( $GLOBALS['uxs_meta']['post'][ $id ][ $key ] ); unset( $GLOBALS['uxs_meta']['post'][ $id ][ $key ] ); return $had; }
function delete_user_meta( $id, $key ) { $had = isset( $GLOBALS['uxs_meta']['user'][ $id ][ $key ] ); unset( $GLOBALS['uxs_meta']['user'][ $id ][ $key ] ); return $had; }

// WordPress ships a libsodium polyfill (sodium_compat) for hosts without the extension; WP Mail SMTP relies on it.
if ( ! function_exists( 'sodium_crypto_secretbox' ) ) {
	foreach ( array( getenv( 'WP_SODIUM_COMPAT' ), 'D:/xampp/htdocs/pobyty/wp-includes/sodium_compat/autoload.php', dirname( $root, 4 ) . '/wp-includes/sodium_compat/autoload.php' ) as $candidate ) {
		if ( $candidate && is_readable( $candidate ) ) {
			require_once $candidate;
			break;
		}
	}
}
require_once $root . '/includes/Autoloader.php';
\UxStudio\Autoloader::register();

use UxStudio\Core\Migration\AbstractSource;
use UxStudio\Core\Migration\Catalog;
use UxStudio\Core\Migration\History;
use UxStudio\Core\Migration\Mapping;
use UxStudio\Core\Migration\PluginInfo;
use UxStudio\Core\Migration\Revert;
use UxStudio\Core\Migration\Runner;
use UxStudio\Core\Migration\SettingsWriter;
use UxStudio\Core\Migration\Snapshot;
use UxStudio\Core\Migration\SourceRegistry;
use UxStudio\Core\Migration\Sources\ActivityLogSource;
use UxStudio\Core\Migration\Sources\MenuIconsSource;
use UxStudio\Core\Migration\Sources\SmtpSource;
use UxStudio\Core\Migration\Staging;
use UxStudio\Core\Security;
use UxStudio\Modules\MenuIcons\IconLibrary;

$failures = 0;
$assert   = static function ( string $name, bool $ok, string $detail = '' ) use ( &$failures ): void {
	if ( ! $ok ) {
		++$failures;
	}
	echo ( $ok ? 'PASS ' : 'FAIL ' ) . $name . ( $ok || '' === $detail ? '' : ' => ' . $detail ) . PHP_EOL;
};

/* Module schemas (keys as in the real Module.php files, checked by the typo guard below). */
$sel = static fn( string $key, array $opts, $def = '' ) => array( 'key' => $key, 'type' => 'select', 'options' => array_fill_keys( $opts, '' ), 'default' => $def );
$tog = static fn( string $key, $def = false ) => array( 'key' => $key, 'type' => 'toggle', 'default' => $def );
$txt = static fn( string $key, $def = '' ) => array( 'key' => $key, 'type' => 'text', 'default' => $def );
$num = static fn( string $key, $def = 0 ) => array( 'key' => $key, 'type' => 'number', 'default' => $def );
$mul = static fn( string $key, array $opts, $def = array() ) => array( 'key' => $key, 'type' => 'multiselect', 'options' => array_fill_keys( $opts, '' ), 'default' => $def );
$col = static fn( string $key ) => array( 'key' => $key, 'type' => 'color', 'default' => '' );
$rich = static fn( string $key ) => array( 'key' => $key, 'type' => 'richtext', 'default' => '' );

$schemas = array(
	'smtp-email'          => array(
		$sel( 'mailer', array( 'smtp', 'brevo', 'gmail' ), 'smtp' ), $txt( 'from_email' ), $tog( 'force_from_email' ), $txt( 'from_name' ), $tog( 'force_from_name' ),
		$txt( 'host' ), $num( 'port', 587 ), $sel( 'encryption', array( 'tls', 'ssl', 'none' ), 'tls' ), $txt( 'username' ), $txt( 'gmail_client_id' ),
	),
	'email-log'           => array( $num( 'retention_days', 30 ), $tog( 'detect_source', true ) ),
	'activity-log'        => array( $num( 'retention_days', 180 ), $tog( 'alert_new_ip' ) ),
	'external-permalinks' => array( $mul( 'post_types', array( 'post', 'page' ) ) ),
	'post-type-order'     => array( $mul( 'post_types', array( 'post', 'page', 'product' ), array( 'post', 'page' ) ) ),
	'maintenance-mode'    => array(
		$sel( 'mode', array( 'disabled', 'maintenance', 'coming_soon' ), 'disabled' ), $sel( 'layout_type', array( 'custom', 'page' ), 'custom' ),
		$sel( 'existing_page', array( '7', '8' ), '' ), $sel( 'auth_type', array( 'logged_in', 'roles' ), 'logged_in' ),
		$mul( 'access_roles', array( 'administrator', 'editor', 'author', 'subscriber' ) ), $txt( 'headline_text' ), $col( 'headline_colour' ),
		$rich( 'body_text' ), $col( 'body_colour' ), $col( 'background_colour' ),
	),
);
$schemas['security-optimization'] = array(
	$tog( 'limit_login_attempts' ), $num( 'max_attempts', 3 ), $num( 'lockout_time', 30 ), $tog( 'notify_admin' ),
	$txt( 'lockout_notify_email' ), $tog( 'lockout_progressive' ), $num( 'lockout_multiplier', 2 ), $num( 'lockout_max_hours', 24 ),
	$tog( 'ip_firewall_enabled' ), array( 'key' => 'ip_firewall_allowlist', 'type' => 'textarea', 'default' => '' ),
);
SettingsWriter::set_schema_resolver( static fn( string $m ) => $schemas[ $m ] ?? null );

/** Reset the fake site. */
$site = static function ( array $plugins = array(), array $active = array(), array $options = array(), array $modules = array( 'activity-log' ) ): void {
	$GLOBALS['uxs_options'] = array_merge(
		array(
			'active_plugins'              => array_map( static fn( $s ) => "$s/$s.php", $active ),
			'uxstudio_active_modules'     => $modules,
			'uxstudio_module_db_versions' => array( 'core_migration' => 1 ),
		),
		$options
	);
	$GLOBALS['uxs_plugins'] = array();
	foreach ( $plugins as $slug ) {
		$GLOBALS['uxs_plugins']["$slug/$slug.php"] = array( 'Name' => "Plugin $slug", 'Version' => '1.0' );
	}
	$GLOBALS['uxs_posts']  = array();
	$GLOBALS['uxs_meta']   = array( 'post' => array(), 'user' => array() );
	$GLOBALS['uxs_terms']  = array();
	$GLOBALS['uxs_tables'] = array();
	$GLOBALS['uxs_sql']    = array();
	$GLOBALS['uxs_cron']   = array();
	$GLOBALS['wpdb']->map  = array();
	// Terms of a post (WPCode type / location): the sources read them with SQL.
	$GLOBALS['uxs_sql'][] = array( "/SELECT t\\.slug FROM wp_term_relationships tr .* tr\\.object_id = (\\d+) AND tt\\.taxonomy = '([a-z_]+)'/", static fn( $m ) => array( ( $GLOBALS['uxs_terms'][ (int) $m[1] ][ $m[2] ] ?? array( null ) )[0] ) );
	AbstractSource::set_data_double( null );
	PluginInfo::flush();
};
$post  = static function ( int $id, string $type, string $status, string $title = '', string $content = '' ): void {
	$GLOBALS['uxs_posts'][ $id ] = (object) array( 'ID' => $id, 'post_type' => $type, 'post_status' => $status, 'post_title' => $title, 'post_content' => $content );
};
$meta  = static function ( string $type, int $id, string $key, $value ): void { $GLOBALS['uxs_meta'][ $type ][ $id ][ $key ] = array( $value ); };
$table = static function ( string $name ): void { $GLOBALS['uxs_tables'][] = 'wp_' . $name; };
$sql   = static function ( string $regex, callable $fn ): void { $GLOBALS['uxs_sql'][] = array( $regex, $fn ); };
$opt   = static fn( string $name ) => $GLOBALS['uxs_options'][ $name ] ?? null;

/** Drive a started run to its end (one REST call = one batch, like the wizard does). */
$drive = static function (): array {
	do {
		$state = Runner::step();
	} while ( ! is_wp_error( $state ) && 'running' === $state['status'] );
	return $state;
};

/** Plan lossy text matching helper. */
$lossy_has = static function ( array $plan, string $needle ): bool {
	foreach ( $plan['lossy'] as $line ) {
		if ( false !== stripos( $line, $needle ) ) { return true; }
	}
	return false;
};
$data_count = static function ( array $plan, string $key ): int {
	foreach ( $plan['data'] as $row ) {
		if ( $row['key'] === $key ) { return $row['count']; }
	}
	return 0;
};
/** Read every normalized item of a source (the engine's own batching). */
$items_of = static function ( string $slug ) {
	$src = SourceRegistry::get( $slug );
	$ref = new ReflectionMethod( $src, 'read_batch' );
	$ref->setAccessible( true );
	$out = $ref->invoke( $src, null, 100000, array() );
	return $out['items'];
};
/** A test double that stores items by key; module-on data is "created inactive" and reported for the switch. */
$install_double = static function ( array &$store, callable $module_on ): void {
	AbstractSource::set_data_double(
		static function ( string $mode, string $slug, array $item, array $ctx ) use ( &$store, $module_on ): array {
			$has = isset( $store[ $slug ][ $item['key'] ] );
			if ( 'verify' === $mode ) {
				return array( 'ok' => $has, 'detail' => $has ? '' : 'missing' );
			}
			if ( 'preview' === $mode ) {
				return array( 'status' => $has ? 'unchanged' : 'created' );
			}
			if ( $has ) {
				return array( 'status' => 'unchanged' );
			}
			$store[ $slug ][ $item['key'] ] = $item;
			$res = array( 'status' => 'created', 'map_type' => 'testobj', 'map_id' => $item['key'] );
			if ( $module_on() && ! empty( $item['enabled'] ) ) {
				$res['activate'] = $item['key'];
				$res['payload']  = array( 'k' => $item['key'] );
			}
			return $res;
		}
	);
};

/* ═══ Registry ═══ */

$f3 = array(
	'redirection', 'safe-redirect-manager', 'simple-301-redirects', '301-redirects', 'code-snippets', 'insert-headers-and-footers', 'header-footer-code-manager',
	'filebird', 'folders', 'real-media-library-lite', 'wicked-folders', 'page-links-to', 'post-expirator', 'menu-icons', 'nav-menu-roles', 'if-menu',
	'menu-items-visibility-control', 'when-last-login', 'wp-last-login', 'wp-mail-smtp', 'easy-wp-smtp', 'post-smtp', 'fluent-smtp', 'wp-mail-logging', 'email-log',
	'wp-crontrol', 'wp-maintenance-mode', 'coming-soon', 'maintenance', 'under-construction-page', 'simple-history', 'wp-security-audit-log', 'aryo-activity-log',
	'adminify-activity-logs', 'post-types-order', 'intuitive-custom-post-order',
);
$classes = SourceRegistry::classes();
$assert( 'all 36 F3 plugin slugs are registered', array() === array_diff( $f3, array_keys( $classes ) ), implode( ',', array_diff( $f3, array_keys( $classes ) ) ) );
$bad = array();
foreach ( $f3 as $slug ) {
	$entry = Catalog::entry_for_slug( $slug );
	$src   = SourceRegistry::get( $slug );
	if ( ! $entry || $src->target_module() !== $entry['module'] || ! is_readable( $root . '/includes/Modules/' . str_replace( ' ', '', ucwords( str_replace( '-', ' ', $entry['module'] ) ) ) . '/meta.json' ) ) {
		$bad[] = $slug;
	}
}
$assert( 'every F3 source targets the Catalog module and an existing module folder', array() === $bad, implode( ',', $bad ) );

/* Taxonomy name used by Revert is the one the module registers. */
$assert( 'Revert::FOLDER_TAXONOMY matches the Folder Manager module', 1 === preg_match( "/const TAXONOMY\s*=\s*'" . preg_quote( Revert::FOLDER_TAXONOMY, '/' ) . "'/", (string) file_get_contents( $root . '/includes/Modules/FolderManager/Module.php' ) ) );

/* Classification: which sources are behaviour-changing (staged when the module is on), which harmless (direct). */
$classification = array(
	'staged' => array( 'redirection', 'code-snippets', 'page-links-to', 'post-expirator', 'menu-icons', 'nav-menu-roles', 'wp-mail-smtp', 'wp-maintenance-mode', 'simple-history', 'post-types-order' ),
	'direct' => array( 'filebird', 'when-last-login', 'wp-mail-logging', 'wp-crontrol' ),
);
$wrong = array();
foreach ( $classification as $kind => $slugs ) {
	foreach ( $slugs as $slug ) {
		$module = SourceRegistry::get( $slug )->target_module();
		$site( array( $slug ), array( $slug ), array(), array( $module ) );
		$staged = SourceRegistry::get( $slug )->plan()['staged'];
		if ( ( 'staged' === $kind ) !== $staged ) {
			$wrong[] = "$slug($kind)";
		}
	}
}
$assert( 'plan classifies every source: behaviour-changing = staged when the module is on, harmless = direct', array() === $wrong, implode( ',', $wrong ) );

// The SPA relies on every plan / scan row having the same shape, whichever source it comes from.
$site( array(), array() );
$shape = array();
foreach ( array_keys( SourceRegistry::classes() ) as $slug ) {
	$src  = SourceRegistry::get( (string) $slug );
	$plan = $src->plan();
	$det  = $src->detect();
	if ( ! isset( $plan['data'], $plan['options'], $plan['staged'], $plan['counts']['data'], $det['summary']['data'] ) || ! is_array( $plan['data'] ) || ! is_array( $det['summary']['data'] ) ) {
		$shape[] = $slug;
	}
}
$assert( 'every source returns data / options / staged in its plan and data rows in the scan summary', array() === $shape, implode( ',', $shape ) );

/* ═══ 1 Redirects ═══ */

$site( array( 'redirection' ), array( 'redirection' ) );
$table( 'redirection_items' );
$redir = static function ( int $id, string $url, string $match, string $atype, int $code, string $target, int $regex = 0, string $status = 'enabled', int $group = 1, string $data = '' ): array {
	return array( 'id' => $id, 'url' => $url, 'match_url' => $url, 'match_data' => $data, 'regex' => $regex, 'position' => $id, 'last_count' => 0, 'last_access' => '', 'group_id' => $group, 'status' => $status, 'action_type' => $atype, 'action_code' => $code, 'action_data' => $target, 'match_type' => $match, 'title' => '' );
};
$redir_rows = array(
	$redir( 1, '/stara', 'url', 'url', 301, '/nova' ),
	$redir( 2, '/blog/(.*)', 'url', 'url', 301, '/novy-blog/$1', 1, 'enabled', 1, '{"source":{"flag_regex":true,"flag_case":true}}' ),
	$redir( 3, '/smazano', 'url', 'error', 410, '' ),
	$redir( 4, '/vypnuto', 'url', 'url', 302, '/jinam', 0, 'disabled', 1, '{"source":{"flag_query":"exactorder"}}' ),
	$redir( 5, '/ve-skupine', 'url', 'url', 301, '/x', 0, 'enabled', 2 ),
	$redir( 6, '/', 'server', 'url', 301, serialize( array( 'url_from' => 'https://nova.test', 'url_notfrom' => '', 'server' => 'https://old.test' ) ) ),
	$redir( 7, '/nahoda', 'url', 'random', 301, '' ),
	$redir( 8, '/prihlasen', 'login', 'url', 301, serialize( array( 'logged_in' => '/y', 'logged_out' => '/gost' ) ) ),
	$redir( 9, '/velke', 'url', 'url', 301, '/male', 0, 'enabled', 1, '{"source":{"flag_query":"ignore","flag_case":true,"flag_trailing":false}}' ),
	$redir( 10, '/see-other?x=1', 'url', 'url', 303, '/z', 0, 'enabled', 1, '{"source":{"flag_query":"pass","flag_case":true,"flag_trailing":true}}' ),
	$redir( 11, 'https://old.test/Stara/', 'url', 'url', 301, '/jinde' ), // same address as rule 1 (case, slash, host): never reached
	// Match types: one rule per state that has a target ("matched" / "not matched"), both on the SAME address as rule 1 here.
	$redir( 12, '/stara', 'referrer', 'url', 301, serialize( array( 'url_from' => '/z-google', 'url_notfrom' => '/bez-google', 'regex' => true, 'referrer' => 'google\\.' ) ) ),
	$redir( 13, '/jazyk', 'language', 'url', 301, serialize( array( 'language' => 'cs', 'url_from' => '/cs', 'url_notfrom' => '' ) ) ),
	$redir( 14, '/bot', 'agent', 'url', 301, serialize( array( 'url_from' => '/bot-stranka', 'url_notfrom' => '', 'regex' => false, 'agent' => 'Googlebot' ) ) ),
	$redir( 15, '/cookie', 'cookie', 'url', 301, serialize( array( 'url_from' => '/vip', 'url_notfrom' => '', 'name' => 'vip', 'value' => '1', 'regex' => false ) ) ),
	$redir( 16, '/role', 'role', 'url', 301, serialize( array( 'url_from' => '/redakce', 'url_notfrom' => '', 'role' => 'editor' ) ) ),
	$redir( 17, '/ip', 'ip', 'url', 301, serialize( array( 'url_from' => '/kancelar', 'url_notfrom' => '', 'ip' => array( '203.0.113.5', '10.0.0.1' ) ) ) ),
	$redir( 18, '/hlavicka', 'header', 'url', 301, serialize( array( 'url_from' => '/h', 'url_notfrom' => '', 'name' => 'X-Foo', 'value' => 'bar', 'regex' => false ) ) ),
);
$redir_rows[0]['last_count']  = 12;
$redir_rows[0]['last_access'] = '2026-03-17 08:18:11';
$redir_rows[1]['last_count']  = 3;
$sql( '/SELECT id, status FROM wp_redirection_groups/', static fn() => array( array( 'id' => 1, 'status' => 'enabled' ), array( 'id' => 2, 'status' => 'disabled' ) ) );
$sql( '/SELECT COUNT\(\*\) FROM wp_redirection_items/', static fn() => array( count( $redir_rows ) ) );
$sql( '/FROM wp_redirection_items WHERE id > (\d+) ORDER BY id ASC LIMIT (\d+)/', static function ( $m ) use ( $redir_rows ) {
	return array_slice( array_values( array_filter( $redir_rows, static fn( $r ) => $r['id'] > (int) $m[1] ) ), 0, (int) $m[2] );
} );
$plan = SourceRegistry::get( 'redirection' )->plan();
$assert( 'redirection: 17 rules (7 plain + server 1 + login 2 + referrer 2 + agent / cookie / role / IP / header 5); language and random skipped', 17 === $data_count( $plan, 'redirect' ), json_encode( $plan['data'] ) );
$assert( 'redirection: disabled rule and rule in a disabled group count as inactive', 2 === $data_count( $plan, 'inactive' ), json_encode( $plan['data'] ) );
$assert( 'redirection: 1 regex, 1 gone', 1 === $data_count( $plan, 'regex' ) && 1 === $data_count( $plan, 'gone' ) );
$assert( 'redirection: a rule with the same address AND the same conditions as an earlier one is still dropped (the first one wins) and reported', $lossy_has( $plan, 'same address and the same conditions' ) && 17 === $data_count( $plan, 'redirect' ), json_encode( $plan['lossy'] ) );
$assert( 'redirection: a rule with the same address but other conditions is NOT a duplicate (several rules per address)', isset( array_column( $items_of( 'redirection' ), null, 'key' )['item:12'], array_column( $items_of( 'redirection' ), null, 'key' )['item:12:else'], array_column( $items_of( 'redirection' ), null, 'key' )['item:1'] ) && '/stara' === array_column( $items_of( 'redirection' ), null, 'key' )['item:12']['source'] );
$assert( 'redirection: lossy no longer lists server / login / referrer / agent / cookie / role / IP / header rules, nor case flags and counters (transferred); it lists random target, language, the query flags the module cannot express, trailing slash and the logs', ! $lossy_has( $plan, 'whole domain' ) && ! $lossy_has( $plan, 'logged in' ) && ! $lossy_has( $plan, 'referring page' ) && ! $lossy_has( $plan, 'cookie' ) && ! $lossy_has( $plan, 'IP address' ) && ! $lossy_has( $plan, 'HTTP header' ) && $lossy_has( $plan, 'random target' ) && $lossy_has( $plan, 'depend on the language' ) && ! $lossy_has( $plan, 'were case sensitive' ) && ! $lossy_has( $plan, 'Hit counters' ) && $lossy_has( $plan, 'let other parameters through' ) && $lossy_has( $plan, 'in the order written' ) && $lossy_has( $plan, 'slash at the end' ) && $lossy_has( $plan, 'redirect log and the 404 log' ), json_encode( $plan['lossy'] ) );
$assert( 'redirection: the plan says that case, query handling and hit counters are transferred', false !== strpos( implode( ' ', $plan['notes'] ), 'Letter case and query string handling are transferred' ) && false !== strpos( implode( ' ', $plan['notes'] ), 'hit counter and the time of the last hit of 2 rules' ), json_encode( $plan['notes'] ) );
$assert( 'redirection: the plan says how many rules became conditional rules', false !== strpos( implode( ' ', $plan['notes'] ), '10 rules depend on who is asking' ), json_encode( $plan['notes'] ) );
$items = $items_of( 'redirection' );
$by    = array_column( $items, null, 'key' );
$assert( 'redirection: 303 becomes 302, 410 has no target, regex keeps match type', 302 === $by['item:10']['code'] && '' === $by['item:3']['target'] && 410 === $by['item:3']['code'] && 'regex' === $by['item:2']['match_type'] );
$assert( 'redirection: Redirection\'s default (no flag_case) is case SENSITIVE, flag_case=true means case insensitive', true === $by['item:1']['case_sensitive'] && false === $by['item:9']['case_sensitive'] && false === $by['item:2']['case_sensitive'] );
$assert( 'redirection: query handling: default exact -> exact, ignore -> ignore, regex -> default, exactorder -> exact', 'exact' === $by['item:1']['query_mode'] && 'ignore' === $by['item:9']['query_mode'] && 'default' === $by['item:2']['query_mode'] && 'exact' === $by['item:4']['query_mode'] && true === $by['item:4']['query_order'] );
$assert( 'redirection: "pass" with parameters in the source cannot be expressed: default mode and counted as unmapped', 'default' === $by['item:10']['query_mode'] && true === $by['item:10']['query_unmapped'] && false === $by['item:9']['query_unmapped'] );
$assert( 'redirection: trailing slash flag false is reported only for exact rules', true === $by['item:1']['trailing_slash'] && true === $by['item:9']['trailing_slash'] && false === $by['item:10']['trailing_slash'] && false === $by['item:2']['trailing_slash'] );
$assert( 'redirection: hits and last access travel (UTC converted to site time when WordPress can), the epoch means never', 12 === $by['item:1']['hits'] && '2026-03-17 08:18:11' === $by['item:1']['last_hit'] && 3 === $by['item:2']['hits'] && '' === $by['item:2']['last_hit'] && 0 === $by['item:9']['hits'] );
$cond = static fn( string $key ): array => $by[ $key ]['conditions'];
$assert( 'redirection: login -> two rules, logged in / logged out, each with its own target', array( array( 'type' => 'login', 'value' => 'in' ) ) === $cond( 'item:8' ) && '/y' === $by['item:8']['target'] && array( array( 'type' => 'login', 'value' => 'out' ) ) === $cond( 'item:8:else' ) && '/gost' === $by['item:8:else']['target'] );
$assert( 'redirection: referrer regex -> matched rule + negated "not matched" rule on the same address, order kept by position', array( array( 'type' => 'referrer', 'op' => 'regex', 'value' => 'google\\.' ) ) === $cond( 'item:12' ) && true === $cond( 'item:12:else' )[0]['negate'] && '/z-google' === $by['item:12']['target'] && '/bez-google' === $by['item:12:else']['target'] && 12 === $by['item:12']['position'] );
$assert( 'redirection: a state without a target makes no rule (the plugin does nothing there)', ! isset( $by['item:14:else'], $by['item:6:else'], $by['item:15:else'] ) );
$assert( 'redirection: agent / cookie / role / IP / header / server map to their conditions', array( array( 'type' => 'agent', 'op' => 'equals', 'value' => 'Googlebot' ) ) === $cond( 'item:14' ) && array( array( 'type' => 'cookie', 'name' => 'vip', 'op' => 'equals', 'value' => '1' ) ) === $cond( 'item:15' ) && array( array( 'type' => 'role', 'value' => 'editor' ) ) === $cond( 'item:16' ) && array( array( 'type' => 'ip', 'value' => array( '203.0.113.5', '10.0.0.1' ) ) ) === $cond( 'item:17' ) && array( array( 'type' => 'header', 'name' => 'X-Foo', 'op' => 'equals', 'value' => 'bar' ) ) === $cond( 'item:18' ) && array( array( 'type' => 'host', 'op' => 'equals', 'value' => 'https://old.test' ) ) === $cond( 'item:6' ) && 'https://nova.test' === $by['item:6']['target'] );
$assert( 'redirection: plain rules carry no conditions', array() === $cond( 'item:1' ) && array() === $cond( 'item:2' ) );
$assert( 'redirection: enabled flag follows rule status and group status', true === $by['item:1']['enabled'] && false === $by['item:4']['enabled'] && false === $by['item:5']['enabled'] );
$assert( 'scan shows data rows for the plugin', array() !== SourceRegistry::get( 'redirection' )->detect()['summary']['data'] && 'active' === SourceRegistry::get( 'redirection' )->detect()['status'] );

// Engine: module OFF - created directly; module ON - created and the active ones wait for the switch.
$store     = array();
$module_on = false;
$install_double( $store, static function () use ( &$module_on ) { return $module_on; } );
$run = Runner::start( array( 'redirection' ), false );
$r1  = $drive();
$assert( 'redirects (module off): 17 created, nothing staged, map rows only for created objects', 'done' === $r1['status'] && 17 === count( $store['redirection'] ) && array() === Staging::all() && 17 === count( $GLOBALS['wpdb']->map ), json_encode( array_map( static fn( $r ) => $r['target_type'], $GLOBALS['wpdb']->map ) ) );
$rows = $r1['per_source']['redirection']['items'];
$assert( 'redirects: report row says how many were created', 'Redirects' === $rows[0]['label'] && false !== strpos( $rows[0]['to'], '17 created' ), json_encode( $rows ) );
$assert( 'redirects: state has total and processed for the n / total progress', 17 === $r1['per_source']['redirection']['total'] && 17 === $r1['per_source']['redirection']['processed'], json_encode( $r1['per_source']['redirection'] ) );
$map_before = count( $GLOBALS['wpdb']->map );
$run2 = Runner::start( array( 'redirection' ), false );
$r2   = $drive();
$assert( 'redirects: second real run writes 0 (idempotent) and adds no map rows', 0 === $r2['per_source']['redirection']['written'] && 17 === $r2['per_source']['redirection']['unchanged'] && count( $GLOBALS['wpdb']->map ) === $map_before );
$rb = Runner::rollback( $run['id'] );
$assert( 'redirects: rollback removes the run\'s map rows', ! is_wp_error( $rb ) && 0 === count( $GLOBALS['wpdb']->map ) );

$site( array( 'redirection' ), array( 'redirection' ), array(), array( 'activity-log', 'redirects' ) );
$table( 'redirection_items' );
$sql( '/SELECT id, status FROM wp_redirection_groups/', static fn() => array( array( 'id' => 1, 'status' => 'enabled' ), array( 'id' => 2, 'status' => 'disabled' ) ) );
$sql( '/SELECT COUNT\(\*\) FROM wp_redirection_items/', static fn() => array( count( $redir_rows ) ) );
$sql( '/FROM wp_redirection_items WHERE id > (\d+) ORDER BY id ASC LIMIT (\d+)/', static function ( $m ) use ( $redir_rows ) {
	return array_slice( array_values( array_filter( $redir_rows, static fn( $r ) => $r['id'] > (int) $m[1] ) ), 0, (int) $m[2] );
} );
$store     = array();
$module_on = true;
$install_double( $store, static function () use ( &$module_on ) { return $module_on; } );
$plan = SourceRegistry::get( 'redirection' )->plan();
$assert( 'redirects (module on): plan says rules are created switched off', true === $plan['staged'] && $lossy_has( array( 'lossy' => $plan['warnings'] ), 'switched off' ), json_encode( $plan['warnings'] ) );
$run = Runner::start( array( 'redirection' ), false );
$r1  = $drive();
$entry = Staging::entry( $run['id'] );
$assert( 'redirects (module on): the 15 rules that were active wait for the switch', 15 === count( $entry['activations'][0]['ids'] ) && 'redirect' === $entry['activations'][0]['kind'] && 'redirection' === $entry['activations'][0]['source'] && 15 === $r1['per_source']['redirection']['staged'], json_encode( $entry ) );
$assert( 'redirects (module on): summary says N waiting + staged flag on the report row', true === $r1['per_source']['redirection']['items'][0]['staged'] );
$ver = Runner::verify();
$assert( 'redirects: verify finds all 17 and marks them staged (waiting for the switch)', array() === array_filter( $ver['redirection'], static fn( $c ) => ! $c['ok'] ) && ! empty( array_filter( $ver['redirection'], static fn( $c ) => ! empty( $c['staged'] ) ) ), json_encode( $ver ) );
$run2 = Runner::start( array( 'redirection' ), false );
$r2   = $drive();
$assert( 'redirects (module on): second run stages nothing new', 0 === $r2['per_source']['redirection']['staged'] && null === Staging::entry( $run2['id'] ), json_encode( Staging::all() ) );
$disc = Runner::rollback( $run['id'] );
$assert( 'redirects: rollback discards the waiting activations', null === Staging::entry( $run['id'] ) );

/* Other redirect plugins */
$site( array( 'safe-redirect-manager' ), array( 'safe-redirect-manager' ) );
$post( 1, 'redirect_rule', 'publish', '/stary' );
$meta( 'post', 1, '_redirect_rule_from', '/stary' ); $meta( 'post', 1, '_redirect_rule_to', '/novy' ); $meta( 'post', 1, '_redirect_rule_status_code', '301' );
$post( 2, 'redirect_rule', 'publish', '/produkty/*' );
$meta( 'post', 2, '_redirect_rule_from', '/produkty/*' ); $meta( 'post', 2, '_redirect_rule_to', '/shop/*' ); $meta( 'post', 2, '_redirect_rule_status_code', '303' ); $meta( 'post', 2, '_force_https', '1' );
$post( 3, 'redirect_rule', 'draft', '/koncept' );
$meta( 'post', 3, '_redirect_rule_from', '/koncept' ); $meta( 'post', 3, '_redirect_rule_to', '/k' );
$post( 4, 'redirect_rule', 'publish', '/zakazano' );
$meta( 'post', 4, '_redirect_rule_from', '/zakazano' ); $meta( 'post', 4, '_redirect_rule_status_code', '403' );
$post( 5, 'redirect_rule', 'publish', '^/rx/(\d+)' );
$meta( 'post', 5, '_redirect_rule_from', '^/rx/(\d+)' ); $meta( 'post', 5, '_redirect_rule_to', '/novy-rx/$1' ); $meta( 'post', 5, '_redirect_rule_status_code', '301' ); $meta( 'post', 5, '_redirect_rule_from_regex', '1' );
$items = $items_of( 'safe-redirect-manager' );
$by    = array_column( $items, null, 'key' );
$plan  = SourceRegistry::get( 'safe-redirect-manager' )->plan();
$assert( 'safe-redirect-manager: 4 rules, 403 skipped and reported', 4 === count( $items ) && $lossy_has( $plan, '403' ), json_encode( $plan['lossy'] ) );
$assert( 'safe-redirect-manager: wildcard becomes a regex with $1, 303 -> 302, draft = inactive, plugin default code is 302', 'regex' === $by['srm:2']['match_type'] && '^/produkty/(.*)' === $by['srm:2']['source'] && '/shop/$1' === $by['srm:2']['target'] && 302 === $by['srm:2']['code'] && false === $by['srm:3']['enabled'] && 302 === $by['srm:3']['code'], json_encode( $by['srm:2'] ) );
$assert( 'safe-redirect-manager: regex flag kept as written, force https reported', 'regex' === $by['srm:5']['match_type'] && '^/rx/(\d+)' === $by['srm:5']['source'] && $lossy_has( $plan, 'forced https' ) );

$site( array( 'simple-301-redirects' ), array( 'simple-301-redirects' ), array( '301_redirects' => array( '/stara' => '/nova', '/blog/*' => '/novy-blog/*', '/jentext*' => '/x' ), '301_redirects_wildcard' => 'true' ) );
$items = $items_of( 'simple-301-redirects' );
$by    = array_column( $items, null, 'label' );
$assert( 'simple-301: wildcards on => * becomes (.*) and $1, plain rules stay exact', 3 === count( $items ) && 'exact' === $by['/stara']['match_type'] && '^/blog/(.*)' === $by['^/blog/(.*)']['source'] && '/novy-blog/$1' === $by['^/blog/(.*)']['target'] && 'regex' === $by['^/jentext(.*)']['match_type'], json_encode( array_keys( $by ) ) );
$site( array( 'simple-301-redirects' ), array( 'simple-301-redirects' ), array( '301_redirects' => array( '/stara' => '/nova', '/blog/*' => '/novy-blog/*' ) ) );
$plan  = SourceRegistry::get( 'simple-301-redirects' )->plan();
$assert( 'simple-301: wildcards off => rules with * never matched, skipped and reported', 1 === count( $items_of( 'simple-301-redirects' ) ) && $lossy_has( $plan, 'wildcards were switched off' ), json_encode( $plan['lossy'] ) );

$site( array( '301-redirects' ), array( '301-redirects' ) );
$table( 'ts_redirects' );
$sql( '/FROM wp_ts_redirects/', static fn() => array( array( 'id' => 1, 'title' => 'Akce', 'new_link' => 'https://example.test/site/akce/', 'old_link' => 'https://example.test/site/stara-akce/' ), array( 'id' => 2, 'title' => '', 'new_link' => '', 'old_link' => '/prazdne' ) ) );
$items = $items_of( '301-redirects' );
$assert( '301-redirects: table rows become 301 exact rules, rows without target are skipped', 1 === count( $items ) && 301 === $items[0]['code'] && 'Akce' === $items[0]['note'] && $lossy_has( SourceRegistry::get( '301-redirects' )->plan(), 'empty source or target' ) );

/* ═══ 2 Code snippets ═══ */

$site( array(), array() );
$table( 'snippets' );
$sql( '/FROM wp_snippets ORDER BY id ASC/', static fn() => array(
	array( 'id' => 1, 'name' => ' WooCommerce kategorie 580 ', 'description' => '', 'code' => "add_filter( 'x', '__return_true' );", 'tags' => 'sample, woo', 'scope' => 'global', 'priority' => 10, 'active' => 1 ),
	array( 'id' => 2, 'name' => 'Skryj admin bar', 'description' => 'popis', 'code' => 'add_filter("show_admin_bar","__return_false");', 'tags' => '', 'scope' => 'front-end', 'priority' => 5, 'active' => 0 ),
	array( 'id' => 3, 'name' => 'Aktualni rok', 'description' => '', 'code' => 'return date("Y");', 'tags' => '', 'scope' => 'content', 'priority' => 10, 'active' => 1 ),
	array( 'id' => 4, 'name' => 'Pixel', 'description' => '', 'code' => '<script>x()</script>', 'tags' => '', 'scope' => 'head-content', 'priority' => 10, 'active' => 1 ),
	array( 'id' => 5, 'name' => 'CSS', 'description' => '', 'code' => 'body{margin:0}', 'tags' => '', 'scope' => 'site-css', 'priority' => 10, 'active' => 1 ),
	array( 'id' => 6, 'name' => 'JS', 'description' => '', 'code' => 'console.log(1)', 'tags' => '', 'scope' => 'site-footer-js', 'priority' => 10, 'active' => 0 ),
	array( 'id' => 7, 'name' => 'Jednou', 'description' => '', 'code' => 'run();', 'tags' => '', 'scope' => 'single-use', 'priority' => 10, 'active' => 1 ),
	array( 'id' => 8, 'name' => 'Obsah s PHP', 'description' => '', 'code' => '<p>Rok <?php echo date("Y"); ?></p>', 'tags' => '', 'scope' => 'content', 'priority' => 10, 'active' => 1 ),
	array( 'id' => 9, 'name' => 'Obsah jen PHP', 'description' => '', 'code' => '<?php echo "x";', 'tags' => '', 'scope' => 'content', 'priority' => 10, 'active' => 0 ),
	array( 'id' => 10, 'name' => 'Podminka', 'description' => '', 'code' => 'x', 'tags' => '', 'scope' => 'condition', 'priority' => 10, 'active' => 1 ),
) );
$items = $items_of( 'code-snippets' );
$by    = array_column( $items, null, 'key' );
$plan  = SourceRegistry::get( 'code-snippets' )->plan();
$assert( 'code-snippets: 9 snippets convert (content -> shortcode, single-use -> run once); only the condition scope is lost', 9 === count( $items ) && ! $lossy_has( $plan, 'shortcode snippets' ) && ! $lossy_has( $plan, 'run-once' ) && $lossy_has( $plan, 'condition snippets' ), json_encode( $plan['lossy'] ) );
$assert( 'code-snippets: content snippet without PHP -> html shortcode, with PHP tags -> php shortcode that starts in HTML mode, PHP-only content -> php shortcode', 'html' === $by['cs:3']['type'] && 'shortcode' === $by['cs:3']['run_location'] && 'php' === $by['cs:8']['type'] && 'shortcode' === $by['cs:8']['run_location'] && "?>\n<p>Rok <?php echo date(\"Y\"); ?></p>" === $by['cs:8']['code'] && 'php' === $by['cs:9']['type'] && 'echo "x";' === $by['cs:9']['code'] && 'shortcode' === $by['cs:9']['run_location'] );
$assert( 'code-snippets: single-use -> php, run once, and it is NEVER switched on by the switch (keep_off)', 'php' === $by['cs:7']['type'] && 'run_once' === $by['cs:7']['run_location'] && true === $by['cs:7']['keep_off'] && false === $by['cs:3']['keep_off'] );
$assert( 'code-snippets: the plan explains shortcodes and the run-once rule', false !== strpos( implode( ' ', $plan['notes'] ), 'uxstudio_snippet' ) && false !== strpos( implode( ' ', $plan['warnings'] ), 'run-once snippet' ) && 1 === $data_count( $plan, 'manual' ), json_encode( array( $plan['notes'], $plan['warnings'], $plan['data'] ) ) );
$assert( 'code-snippets: scopes map to type + place', 'php' === $by['cs:1']['type'] && 'everywhere' === $by['cs:1']['run_location'] && 'frontend_only' === $by['cs:2']['run_location'] && 'html' === $by['cs:4']['type'] && 'site_header' === $by['cs:4']['run_location'] && 'css' === $by['cs:5']['type'] && 'js' === $by['cs:6']['type'] && 'site_footer' === $by['cs:6']['run_location'] );
$assert( 'code-snippets: name is trimmed, tags go into the description, active flag is kept', 'WooCommerce kategorie 580' === $by['cs:1']['name'] && false !== strpos( $by['cs:1']['description'], 'sample, woo' ) && true === $by['cs:1']['enabled'] && false === $by['cs:2']['enabled'] );
$assert( 'code-snippets: plan says every snippet is created switched off', false !== strpos( implode( ' ', $plan['notes'] ), 'switched off' ) && 5 === $data_count( $plan, 'enable' ), json_encode( $plan['data'] ) );
// Always disabled + activation staged, also with the module OFF.
$store = array();
$install_double( $store, static fn() => true );
$run = Runner::start( array( 'code-snippets' ), false );
$r1  = $drive();
$entry = Staging::entry( $run['id'] );
$assert( 'code-snippets: snippets that were active wait for the switch (not the run-once one), whatever the module state', 6 === count( $entry['activations'][0]['ids'] ) && 'snippet' === $entry['activations'][0]['kind'], json_encode( $entry ) );

$site( array( 'insert-headers-and-footers' ), array( 'insert-headers-and-footers' ), array( 'ihaf_insert_header' => '<meta name="x">', 'ihaf_insert_footer' => '', 'ihaf_insert_body' => '<noscript>y</noscript>' ) );
$post( 20, 'wpcode', 'publish', 'GA kod', "<script>ga()</script>" );
$GLOBALS['uxs_terms'][20] = array( 'wpcode_type' => array( 'html' ), 'wpcode_location' => array( 'site_wide_header' ) );
$meta( 'post', 20, '_wpcode_auto_insert', 1 ); $meta( 'post', 20, '_wpcode_priority', 3 ); $meta( 'post', 20, '_wpcode_note', 'Analytika' );
$post( 21, 'wpcode', 'publish', 'PHP s podminkou', 'add_action("init","f");' );
$GLOBALS['uxs_terms'][21] = array( 'wpcode_type' => array( 'php' ), 'wpcode_location' => array( 'everywhere' ) );
$meta( 'post', 21, '_wpcode_auto_insert', 1 ); $meta( 'post', 21, '_wpcode_conditional_logic_enabled', 1 );
$post( 22, 'wpcode', 'draft', 'Shortcode', 'echo 1;' );
$GLOBALS['uxs_terms'][22] = array( 'wpcode_type' => array( 'php' ), 'wpcode_location' => array( 'everywhere' ) );
$meta( 'post', 22, '_wpcode_auto_insert', 0 );
$post( 23, 'wpcode', 'publish', 'Text', 'ahoj' );
$GLOBALS['uxs_terms'][23] = array( 'wpcode_type' => array( 'text' ), 'wpcode_location' => array( 'site_wide_footer' ) );
$meta( 'post', 23, '_wpcode_auto_insert', 1 );
$post( 24, 'wpcode', 'publish', 'Pred prispevkem', '<p>x</p>' );
$GLOBALS['uxs_terms'][24] = array( 'wpcode_type' => array( 'html' ), 'wpcode_location' => array( 'before_post' ) );
$meta( 'post', 24, '_wpcode_auto_insert', 1 );
$post( 25, 'wpcode', 'publish', 'Za obsahem', '<p>za</p>' );
$GLOBALS['uxs_terms'][25] = array( 'wpcode_type' => array( 'html' ), 'wpcode_location' => array( 'after_content' ) );
$meta( 'post', 25, '_wpcode_auto_insert', 1 );
$post( 26, 'wpcode', 'publish', 'Mezi odstavci', '<p>mezi</p>' );
$GLOBALS['uxs_terms'][26] = array( 'wpcode_type' => array( 'html' ), 'wpcode_location' => array( 'before_paragraph' ) );
$meta( 'post', 26, '_wpcode_auto_insert', 1 );
$post( 27, 'wpcode', 'publish', 'Jen uvodni', '<p>uvod</p>' );
$GLOBALS['uxs_terms'][27] = array( 'wpcode_type' => array( 'html' ), 'wpcode_location' => array( 'site_wide_footer' ) );
$meta( 'post', 27, '_wpcode_auto_insert', 1 ); $meta( 'post', 27, '_wpcode_conditional_logic_enabled', 1 ); $meta( 'post', 27, '_wpcode_device_type', 'desktop' );
$meta( 'post', 27, '_wpcode_conditional_logic', array( 'show' => 'show', 'groups' => array( array(
	array( 'type' => 'page', 'option' => 'type_of_page', 'relation' => '=', 'value' => 'is_front_page' ),
	array( 'type' => 'user', 'option' => 'logged_in', 'relation' => '=', 'value' => '1' ),
) ) ) );
$items = $items_of( 'insert-headers-and-footers' );
$by    = array_column( $items, null, 'key' );
$plan  = SourceRegistry::get( 'insert-headers-and-footers' )->plan();
$assert( 'wpcode: legacy header + body boxes (footer empty) and 5 snippet posts convert (manual and before / after content are new)', 8 === count( $items ) && isset( $by['ihaf:header'], $by['ihaf:body'], $by['wpcode:27'], $by['wpcode:20'], $by['wpcode:21'], $by['wpcode:22'], $by['wpcode:24'], $by['wpcode:25'] ), implode( ',', array_keys( $by ) ) );
$assert( 'wpcode: a snippet that is not inserted automatically becomes a shortcode snippet (a draft stays off)', 'shortcode' === $by['wpcode:22']['run_location'] && 'php' === $by['wpcode:22']['type'] && false === $by['wpcode:22']['enabled'] && false === $by['wpcode:22']['keep_off'] );
$assert( 'wpcode: before post / before content -> before the content, after content -> after, with posts and pages as the default post types; other snippets have none', 'before_content' === $by['wpcode:24']['run_location'] && 'after_content' === $by['wpcode:25']['run_location'] && array( 'post', 'page' ) === $by['wpcode:24']['post_types'] && array( 'post', 'page' ) === $by['wpcode:25']['post_types'] && array() === $by['wpcode:20']['post_types'] );
$assert( 'wpcode: header box -> html in the site header, body box -> after the opening body tag', 'html' === $by['ihaf:header']['type'] && 'site_header' === $by['ihaf:header']['run_location'] && 'site_body_open' === $by['ihaf:body']['run_location'] );
$assert( 'wpcode: priority and note are kept; conditional logic marks the snippet for review (stays off)', 3 === $by['wpcode:20']['priority'] && 'Analytika' === $by['wpcode:20']['description'] && true === $by['wpcode:21']['review'] && false === $by['wpcode:20']['review'] );
$assert( 'wpcode: text type, a place the module lacks (before paragraph) and conditions are reported; shortcode-only and before / after content are not', $lossy_has( $plan, 'plain text' ) && $lossy_has( $plan, 'before_paragraph' ) && $lossy_has( $plan, 'conditions' ) && ! $lossy_has( $plan, 'shortcode or on demand' ) && ! $lossy_has( $plan, 'before or after posts' ), json_encode( $plan['lossy'] ) );
$assert( 'wpcode: notes tell about the shortcode and the placement around the content', false !== strpos( implode( ' ', $plan['notes'] ), '[uxstudio_snippet' ) && false !== strpos( implode( ' ', $plan['notes'] ), 'before or after the post content' ), json_encode( $plan['notes'] ) );
$assert( 'wpcode: 1 snippet in review is not counted among those switched on at the switch', 1 === $data_count( $plan, 'review' ), json_encode( $plan['data'] ) );
$assert( 'wpcode: conditional logic (front page AND logged in) and the device become run conditions, the snippet is NOT held for review', false === $by['wpcode:27']['review'] && array(
	array( 'type' => 'page', 'value' => array( 'front_page' ), 'negate' => false ),
	array( 'type' => 'login', 'value' => 'in', 'negate' => false ),
	array( 'type' => 'device', 'value' => 'desktop', 'negate' => false ),
) === $by['wpcode:27']['conditions'], json_encode( $by['wpcode:27']['conditions'] ) );
$assert( 'wpcode: logic that is enabled but has no readable rules still holds the snippet for review (no conditions are guessed)', true === $by['wpcode:21']['review'] && array() === $by['wpcode:21']['conditions'] );
// The WPCode logic matrix: what maps exactly and what stays for review.
$wp = SourceRegistry::get( 'insert-headers-and-footers' );
$row = static fn( string $type, string $option, string $rel, $value ): array => array( 'type' => $type, 'option' => $option, 'relation' => $rel, 'value' => $value );
$rules = static fn( string $show, array ...$groups ): array => array( 'show' => $show, 'groups' => $groups );
$kinds = array( 'is_front_page' => 'front_page', 'is_home' => 'blog_home', 'is_single' => 'singular', 'is_author' => 'author', 'is_archive' => 'archive', 'is_search' => 'search', 'is_404' => '404' );
$kind_ok = true;
foreach ( $kinds as $wp_value => $kind ) {
	$got = $wp->wpcode_conditions( $rules( 'show', array( $row( 'page', 'type_of_page', '=', $wp_value ) ) ) );
	$neg = $wp->wpcode_conditions( $rules( 'show', array( $row( 'page', 'type_of_page', '!=', $wp_value ) ) ) );
	$kind_ok = $kind_ok && array( array( 'type' => 'page', 'value' => array( $kind ), 'negate' => false ) ) === $got && true === $neg[0]['negate'];
}
$assert( 'wpcode logic: every "type of page" maps to its page kind, "!=" negates', $kind_ok );
$assert( 'wpcode logic: logged in true / false / "!=", user role = / !=, post type', array( array( 'type' => 'login', 'value' => 'in', 'negate' => false ) ) === $wp->wpcode_conditions( $rules( 'show', array( $row( 'user', 'logged_in', '=', true ) ) ) ) && 'out' === $wp->wpcode_conditions( $rules( 'show', array( $row( 'user', 'logged_in', '=', '' ) ) ) )[0]['value'] && 'out' === $wp->wpcode_conditions( $rules( 'show', array( $row( 'user', 'logged_in', '!=', '1' ) ) ) )[0]['value'] && array( array( 'type' => 'role', 'value' => array( 'editor' ), 'negate' => true ) ) === $wp->wpcode_conditions( $rules( 'show', array( $row( 'user', 'user_role', '!=', 'editor' ) ) ) ) && array( array( 'type' => 'post_type', 'value' => array( 'product' ), 'scope' => 'single', 'negate' => false ) ) === $wp->wpcode_conditions( $rules( 'show', array( $row( 'page', 'post_type', '=', 'product' ) ) ) ) );
$assert( 'wpcode logic: "hide" on one rule is that rule turned around, "hide" on several rules cannot be written (review)', true === $wp->wpcode_conditions( $rules( 'hide', array( $row( 'page', 'type_of_page', '=', 'is_404' ) ) ) )[0]['negate'] && 'out' === $wp->wpcode_conditions( $rules( 'hide', array( $row( 'user', 'logged_in', '=', '1' ) ) ) )[0]['value'] && null === $wp->wpcode_conditions( $rules( 'hide', array( $row( 'page', 'type_of_page', '=', 'is_404' ), $row( 'user', 'logged_in', '=', '1' ) ) ) ) );
$assert( 'wpcode logic: several OR groups, other relations, page URL / referrer / taxonomy rules, unknown options and a missing "show" are NOT guessed (review)', null === $wp->wpcode_conditions( $rules( 'show', array( $row( 'user', 'logged_in', '=', '1' ) ), array( $row( 'page', 'type_of_page', '=', 'is_404' ) ) ) ) && null === $wp->wpcode_conditions( $rules( 'show', array( $row( 'page', 'page_url', 'contains', '/shop' ) ) ) ) && null === $wp->wpcode_conditions( $rules( 'show', array( $row( 'page', 'page_url', '=', '/shop/' ) ) ) ) && null === $wp->wpcode_conditions( $rules( 'show', array( $row( 'page', 'referrer', '=', 'x' ) ) ) ) && null === $wp->wpcode_conditions( $rules( 'show', array( $row( 'page', 'taxonomy_term', '=', array( 3 ) ) ) ) ) && null === $wp->wpcode_conditions( $rules( 'show', array( $row( 'user', 'user_meta', '=', 'x' ) ) ) ) && null === $wp->wpcode_conditions( array( 'groups' => array( array( $row( 'user', 'logged_in', '=', '1' ) ) ) ) ) && null === $wp->wpcode_conditions( array( 'show' => 'show', 'groups' => array() ) ) && null === $wp->wpcode_conditions( '' ) );
$assert( 'wpcode: the plan tells how many snippets became run conditions', false !== strpos( implode( ' ', $plan['notes'] ), '1 snippet used page, user, role or device targeting' ), json_encode( $plan['notes'] ) );

$site( array( 'header-footer-code-manager' ), array( 'header-footer-code-manager' ) );
$table( 'hfcm_scripts' );
$sql( '/FROM wp_hfcm_scripts ORDER BY script_id ASC/', static fn() => array(
	array( 'script_id' => 1, 'name' => 'Pixel', 'snippet' => '&lt;script&gt;fbq()&lt;/script&gt;', 'snippet_type' => 'js', 'device_type' => 'both', 'location' => 'header', 'display_on' => 'All', 'status' => 'active' ),
	array( 'script_id' => 2, 'name' => 'Jen produkty', 'snippet' => '<style>a{}</style>', 'snippet_type' => 'css', 'device_type' => 'mobile', 'location' => 'footer', 'display_on' => 's_posts', 'status' => 'active' ),
	array( 'script_id' => 3, 'name' => 'Za obsahem', 'snippet' => '<b>x</b>', 'snippet_type' => 'html', 'device_type' => 'both', 'location' => 'after_content', 'display_on' => 'All', 'status' => 'active' ),
	array( 'script_id' => 4, 'name' => 'Shortcode', 'snippet' => 'x', 'snippet_type' => 'html', 'device_type' => 'both', 'location' => 'header', 'display_on' => 'manual', 'status' => 'inactive' ),
	array( 'script_id' => 5, 'name' => 'Stranky', 'snippet' => '<i>p</i>', 'snippet_type' => 'html', 'device_type' => 'desktop', 'location' => 'header', 'display_on' => 's_pages', 's_pages' => '["12","45"]', 'status' => 'active' ),
	array( 'script_id' => 6, 'name' => 'Vsude krome', 'snippet' => '<i>v</i>', 'snippet_type' => 'html', 'device_type' => 'both', 'location' => 'footer', 'display_on' => 'All', 'ex_pages' => '["7"]', 'ex_posts' => '["9"]', 'status' => 'active' ),
	array( 'script_id' => 7, 'name' => 'Typy', 'snippet' => '<i>t</i>', 'snippet_type' => 'html', 'device_type' => 'both', 'location' => 'footer', 'display_on' => 's_custom_posts', 's_custom_posts' => '["product"]', 'spt_display_on' => 'archives', 'status' => 'active' ),
	array( 'script_id' => 8, 'name' => 'Kategorie', 'snippet' => '<i>c</i>', 'snippet_type' => 'html', 'device_type' => 'both', 'location' => 'footer', 'display_on' => 's_categories', 's_categories' => '["3"]', 'status' => 'active' ),
) );
$items = $items_of( 'header-footer-code-manager' );
$by    = array_column( $items, null, 'key' );
$plan  = SourceRegistry::get( 'header-footer-code-manager' )->plan();
$assert( 'hfcm: code is decoded and kept as html (its js / css carry their own tags); all 8 snippets convert', 8 === count( $items ) && 'html' === $by['hfcm:1']['type'] && '<script>fbq()</script>' === $by['hfcm:1']['code'] && 'site_footer' === $by['hfcm:2']['run_location'] );
$assert( 'hfcm: after content -> placement after the content, manual -> shortcode snippet (inactive stays off)', 'after_content' === $by['hfcm:3']['run_location'] && array( 'post', 'page' ) === $by['hfcm:3']['post_types'] && false === $by['hfcm:3']['review'] && 'shortcode' === $by['hfcm:4']['run_location'] && false === $by['hfcm:4']['enabled'] && false === $by['hfcm:4']['review'] );
$assert( 'hfcm: specific pages + desktop -> post condition + device condition, no review', false === $by['hfcm:5']['review'] && array(
	array( 'type' => 'post', 'value' => array( 12, 45 ), 'negate' => false ),
	array( 'type' => 'device', 'value' => 'desktop', 'negate' => false ),
) === $by['hfcm:5']['conditions'] && 'site_header' === $by['hfcm:5']['run_location'], json_encode( $by['hfcm:5']['conditions'] ) );
$assert( 'hfcm: site wide with exclusions -> one negated post condition; post types (archives only) -> post type condition with scope', array(
	array( 'type' => 'post', 'value' => array( 7, 9 ), 'negate' => true ),
) === $by['hfcm:6']['conditions'] && false === $by['hfcm:6']['review'] && array(
	array( 'type' => 'post_type', 'value' => array( 'product' ), 'scope' => 'archive', 'negate' => false ),
) === $by['hfcm:7']['conditions'], json_encode( array( $by['hfcm:6']['conditions'], $by['hfcm:7']['conditions'] ) ) );
$assert( 'hfcm: categories (no matching condition) and a mobile snippet with unreadable targeting stay for review, without conditions', true === $by['hfcm:8']['review'] && array() === $by['hfcm:8']['conditions'] && true === $by['hfcm:2']['review'] && array() === $by['hfcm:2']['conditions'] );
$assert( 'hfcm: page / device targeting that cannot be expressed still marks a snippet for review; after-content and shortcode snippets are no longer reported as lost', true === $by['hfcm:2']['review'] && false === $by['hfcm:1']['review'] && 2 === $data_count( $plan, 'review' ) && ! $lossy_has( $plan, 'after_content' ) && ! $lossy_has( $plan, 'shortcode' ), json_encode( $plan['lossy'] ) );

/* ═══ 3 Folders ═══ */

$site( array( 'filebird' ), array( 'filebird' ) );
$table( 'fbv' ); $table( 'fbv_attachment_folder' );
$sql( '/FROM wp_fbv ORDER BY id ASC/', static fn() => array(
	array( 'id' => 3, 'name' => 'Detail', 'parent' => 2, 'type' => 0 ),
	array( 'id' => 1, 'name' => 'Hotely', 'parent' => 0, 'type' => 0 ),
	array( 'id' => 2, 'name' => 'Lazne', 'parent' => 1, 'type' => 0 ),
	array( 'id' => 4, 'name' => 'Kolekce', 'parent' => 0, 'type' => 1 ),
) );
$sql( '/SELECT COUNT\(\*\) FROM wp_fbv_attachment_folder/', static fn() => array( 42 ) );
$items = $items_of( 'filebird' );
$plan  = SourceRegistry::get( 'filebird' )->plan();
$assert( 'filebird: folders come out parents first, collections are skipped and reported', array( '1', '2', '3' ) === array_column( $items, 'id' ) && $lossy_has( $plan, 'collections' ), json_encode( array_column( $items, 'id' ) ) );
$assert( 'filebird: 3 folders and 42 placed files in the plan; folder migration is direct (harmless)', 3 === $data_count( $plan, 'folder' ) && 42 === $data_count( $plan, 'items' ) && false === $plan['staged'] );

$site( array( 'real-media-library-lite' ), array( 'real-media-library-lite' ) );
$table( 'realmedialibrary' ); $table( 'realmedialibrary_posts' );
$sql( '/FROM wp_realmedialibrary ORDER BY id ASC/', static fn() => array( array( 'id' => 1, 'name' => 'A', 'parent' => -1, 'type' => '0' ), array( 'id' => 2, 'name' => 'Galerie', 'parent' => 1, 'type' => '2' ) ) );
$sql( '/isShortcut = 0 AND fid > 0/', static fn() => array( 10 ) );
$sql( '/isShortcut > 0/', static fn() => array( 3 ) );
$plan = SourceRegistry::get( 'real-media-library-lite' )->plan();
$assert( 'real-media-library: only ordinary folders, galleries and shortcuts are reported', 1 === $data_count( $plan, 'folder' ) && 10 === $data_count( $plan, 'items' ) && $lossy_has( $plan, 'shortcuts' ) && $lossy_has( $plan, 'collections, galleries' ), json_encode( $plan['lossy'] ) );

$site( array( 'wicked-folders' ), array( 'wicked-folders' ) );
$sql( '/tt\.taxonomy = \'wf_attachment_folders\' ORDER BY/', static fn() => array( array( 'id' => 11, 'name' => 'Media', 'parent' => 0, 'term_id' => 5 ), array( 'id' => 12, 'name' => 'Podslozka', 'parent' => 5, 'term_id' => 6 ) ) );
$sql( '/SELECT COUNT\(\*\) FROM wp_term_relationships/', static fn() => array( 7 ) );
$sql( '/GROUP BY taxonomy/', static fn() => array( array( 'taxonomy' => 'wf_page_folders', 'n' => 4 ) ) );
$items = $items_of( 'wicked-folders' );
$plan  = SourceRegistry::get( 'wicked-folders' )->plan();
$assert( 'wicked-folders: attachment folders with hierarchy; page folders reported as other content types', 2 === count( $items ) && '11' === $items[1]['parent'] && $lossy_has( $plan, 'wf_page_folders' ), json_encode( $items ) );

$site( array( 'folders' ), array( 'folders' ) );
$sql( '/tt\.taxonomy = \'media_folder\' ORDER BY/', static fn() => array( array( 'id' => 21, 'name' => 'Fotky', 'parent' => 0, 'term_id' => 9 ) ) );
$sql( '/SELECT COUNT\(\*\) FROM wp_term_relationships/', static fn() => array( 3 ) );
$assert( 'folders (Premio): media_folder terms are read', 1 === count( $items_of( 'folders' ) ) );

/* ═══ 4 Page Links To, Post Expirator ═══ */

$site( array( 'page-links-to' ), array( 'page-links-to' ) );
$sql( '/FROM wp_postmeta m JOIN wp_posts p ON p.ID = m.post_id WHERE m.meta_key = \'_links_to\'/', static fn() => array( array( 'ID' => 1, 'post_type' => 'page' ), array( 'ID' => 2, 'post_type' => 'page' ), array( 'ID' => 3, 'post_type' => 'post' ) ) );
$meta( 'post', 1, '_links_to_target', '_blank' ); $meta( 'post', 2, '_links_to_type', '301' );
$plan = SourceRegistry::get( 'page-links-to' )->plan();
$assert( 'page-links-to: addresses stay in place, only the post type list is set', 1 === count( $plan['transfer'] ) && 'post_types' === $plan['transfer'][0]['key'] && false !== strpos( implode( ' ', $plan['notes'] ), 'same field' ), json_encode( $plan['transfer'] ) );
$assert( 'page-links-to: new tab and 301 counts are reported precisely', $lossy_has( $plan, '1 links were set to open in a new tab' ) && $lossy_has( $plan, '1 links redirected permanently' ), json_encode( $plan['lossy'] ) );
SourceRegistry::get( 'page-links-to' )->migrate( array( 'run_id' => 'rplt' ), false );
$assert( 'page-links-to: post types written (module off)', array( 'page', 'post' ) === ( function () use ( $opt ) { $v = $opt( 'uxstudio_external_permalinks' )['post_types'] ?? array(); sort( $v ); return $v; } )() );

$site( array( 'post-expirator' ), array( 'post-expirator' ) );
$future = time() + 5 * DAY_IN_SECONDS;
$table( 'ppfuture_actions_args' );
$sql( '/FROM wp_ppfuture_actions_args WHERE enabled = 1/', static fn() => array(
	array( 'post_id' => 10, 'scheduled_date' => gmdate( 'Y-m-d H:i:s', $future ), 'args' => '{"expireType":"draft"}' ),
	array( 'post_id' => 11, 'scheduled_date' => gmdate( 'Y-m-d H:i:s', $future ), 'args' => '{"expireType":"change-status","newStatus":"draft"}' ),
	array( 'post_id' => 12, 'scheduled_date' => gmdate( 'Y-m-d H:i:s', $future ), 'args' => '{"expireType":"delete"}' ),
	array( 'post_id' => 13, 'scheduled_date' => gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS ), 'args' => '{"expireType":"draft"}' ),
) );
$sql( '/meta_key = \'_expiration-date\'/', static fn() => array( array( 'post_id' => 14, 'meta_value' => (string) $future ), array( 'post_id' => 10, 'meta_value' => (string) $future ) ) );
foreach ( array( 10, 11, 12, 13, 14 ) as $pid ) { $post( $pid, 'post', 'publish', "Clanek $pid" ); }
$meta( 'post', 14, '_expiration-date-status', 'saved' ); $meta( 'post', 14, '_expiration-date-type', 'draft' );
$items = $items_of( 'post-expirator' );
$plan  = SourceRegistry::get( 'post-expirator' )->plan();
$assert( 'post-expirator: v3 table rows + legacy meta; draft actions convert (duplicate post 10 once)', array( 10, 11, 14 ) === array_column( $items, 'id' ), json_encode( array_column( $items, 'id' ) ) );
$assert( 'post-expirator: delete action and past dates are reported, never migrated', $lossy_has( $plan, '"delete"' ) && $lossy_has( $plan, 'already in the past' ), json_encode( $plan['lossy'] ) );
$assert( 'post-expirator: date is written as site-local MySQL time', gmdate( 'Y-m-d H:i:s', $future ) === $items[0]['meta']['_uxstudio_auto_unpublish'] );

// Real meta writers: module off => written + cron event; module on => staged then written by activate().
$run = Runner::start( array( 'post-expirator' ), false );
$r1  = $drive();
$assert( 'post-expirator (module off): meta written, event scheduled, 3 created', 3 === $r1['per_source']['post-expirator']['written'] && metadata_exists( 'post', 10, '_uxstudio_auto_unpublish' ) && false !== wp_next_scheduled( 'ux_studio/auto_unpublish_post' ) && array() === Staging::all() );
$r2 = Runner::start( array( 'post-expirator' ), false );
$r2 = $drive();
$assert( 'post-expirator: second run writes 0', 0 === $r2['per_source']['post-expirator']['written'] && 3 === $r2['per_source']['post-expirator']['unchanged'] );
$maps = array_map( static fn( $r ) => $r['target_type'], $GLOBALS['wpdb']->map );
$assert( 'post-expirator: map rows record only what the first run created (postmeta + cron_event)', 3 === count( array_keys( $maps, 'postmeta' ) ) && 3 === count( array_keys( $maps, 'cron_event' ) ), json_encode( array_count_values( $maps ) ) );
$rb = Runner::rollback( $run['id'] );
$assert( 'post-expirator: rollback deletes the meta and unschedules the events', ! is_wp_error( $rb ) && ! metadata_exists( 'post', 10, '_uxstudio_auto_unpublish' ) && false === wp_next_scheduled( 'ux_studio/auto_unpublish_post' ) );

$GLOBALS['uxs_options']['uxstudio_active_modules'][] = 'auto-unpublish';
$run = Runner::start( array( 'post-expirator' ), false );
$r1  = $drive();
$entry = Staging::entry( $run['id'] );
$assert( 'post-expirator (module on): nothing written, 3 dates wait in staging with their payload', ! metadata_exists( 'post', 10, '_uxstudio_auto_unpublish' ) && 3 === count( $entry['activations'][0]['ids'] ) && isset( $entry['activations'][0]['data']['10']['meta'] ), json_encode( $entry ) );
$ver = Runner::verify();
$assert( 'post-expirator: verify accepts waiting values', array() === array_filter( $ver['post-expirator'], static fn( $c ) => ! $c['ok'] ), json_encode( $ver ) );
$res = Staging::apply( $run['id'] );
$assert( 'Staging::apply writes the dates and schedules the events', 3 === $res['activated'] && array() === $res['errors'] && metadata_exists( 'post', 14, '_uxstudio_auto_unpublish' ) && false !== wp_next_scheduled( 'ux_studio/auto_unpublish_post' ), json_encode( $res ) );
$assert( 'Staging::apply of meta data is idempotent (the entry is gone after the first call)', 0 === Staging::apply( $run['id'] )['activated'] && null === Staging::entry( $run['id'] ) );

/* ═══ 5 Menus ═══ */

$allow = IconLibrary::names();
$bad   = array_filter( array_merge( array_values( MenuIconsSource::DASHICONS ), array_values( MenuIconsSource::FONT_AWESOME ) ), static fn( $n ) => ! in_array( $n, $allow, true ) );
$assert( 'menu-icons: every mapped Lucide name is on the module allowlist', array() === $bad, implode( ',', $bad ) );
$assert( 'menu-icons: Font Awesome 4, 5 and 6 class names resolve', 'house' === MenuIconsSource::lucide_for( 'fa', 'fa fa-home' ) && 'house' === MenuIconsSource::lucide_for( 'fa', 'fas fa-house' ) && 'facebook' === MenuIconsSource::lucide_for( 'fa', 'fa-brands fa-facebook-f' ) && null === MenuIconsSource::lucide_for( 'fa', 'fa fa-bed' ) && 'mail' === MenuIconsSource::lucide_for( 'dashicons', 'dashicons-email-alt' ) && null === MenuIconsSource::lucide_for( 'genericon', 'genericon-home' ) );

$site( array( 'menu-icons' ), array( 'menu-icons' ) );
$sql( '/SELECT DISTINCT m\.post_id FROM wp_postmeta m JOIN wp_posts p ON p\.ID = m\.post_id WHERE m\.meta_key = \'menu-icons\'/', static fn() => array( 31, 32, 33, 34, 35 ) );
foreach ( array( 31, 32, 33, 34, 35 ) as $pid ) { $post( $pid, 'nav_menu_item', 'publish', "Polozka $pid" ); }
$post( 99, 'attachment', 'inherit', 'logo.svg' );
$meta( 'post', 31, 'menu-icons', array( 'type' => 'fa', 'icon' => 'fa-home', 'position' => 'before' ) );
$meta( 'post', 32, 'menu-icons', array( 'type' => 'dashicons', 'icon' => 'dashicons-phone', 'hide_label' => '1' ) );
$meta( 'post', 33, 'menu-icons', array( 'type' => 'fa', 'icon' => 'fa-bed', 'position' => 'after' ) );
$meta( 'post', 34, 'menu-icons', array( 'type' => 'svg', 'icon' => '99', 'position' => 'after' ) );
$meta( 'post', 35, 'menu-icons', array( 'type' => 'image', 'icon' => '1234' ) );
$items = $items_of( 'menu-icons' );
$by    = array_column( $items, null, 'id' );
$plan  = SourceRegistry::get( 'menu-icons' )->plan();
$assert( 'menu-icons: font icons -> Lucide, hide label -> "only", after -> "after", svg attachment -> media', 'house' === $by[31]['meta']['_uxstudio_menu_item_icon_value'] && 'only' === $by[32]['meta']['_uxstudio_menu_item_icon_position'] && 'media' === $by[34]['meta']['_uxstudio_menu_item_icon_type'] && 'after' === $by[34]['meta']['_uxstudio_menu_item_icon_position'] && 3 === count( $items ), json_encode( array_keys( $by ) ) );
$assert( 'menu-icons: a Dashicon stays that Dashicon (not the nearest Lucide), the other font icon still becomes Lucide', 'dashicons' === $by[32]['meta']['_uxstudio_menu_item_icon_type'] && 'dashicons-phone' === $by[32]['meta']['_uxstudio_menu_item_icon_value'] && 'lucide' === $by[31]['meta']['_uxstudio_menu_item_icon_type'], json_encode( $by[32]['meta'] ) );
$assert( 'menu-icons: dashicon_for takes the dashicons- class out of a class list and refuses names that are not core Dashicons', 'dashicons-admin-home' === MenuIconsSource::dashicon_for( 'dashicons dashicons-admin-home' ) && null === MenuIconsSource::dashicon_for( 'dashicons-not-a-real-icon' ) && null === MenuIconsSource::dashicon_for( 'fa fa-home' ) );
$assert( 'menu-icons: the plan counts Dashicons and tells they are kept', 1 === $data_count( $plan, 'dashicons' ) && false !== strpos( implode( ' ', $plan['notes'] ), 'Dashicons are transferred as they are' ), json_encode( $plan['data'] ) );
$assert( 'menu-icons: the icon without a counterpart is named, an image whose file is gone is reported', $lossy_has( $plan, 'fa: fa-bed' ) && $lossy_has( $plan, 'no longer exists' ), json_encode( $plan['lossy'] ) );

// One item, three metas of the same type: every one is recorded in the mapping table and removed by the rollback.
$run = Runner::start( array( 'menu-icons' ), false );
$drive();
$rows32 = array_filter( $GLOBALS['wpdb']->map, static fn( $r ) => 'postmeta' === $r['target_type'] && 0 === strpos( $r['target_id'], '32|' ) );
$assert( 'menu-icons: item 32 writes type + value + position and records all three', 3 === count( $rows32 ) && 'only' === get_post_meta( 32, '_uxstudio_menu_item_icon_position', true ), json_encode( array_column( $rows32, 'target_id' ) ) );
$rb = Runner::rollback( $run['id'] );
$left = array_filter( array( 31, 32, 34 ), static fn( $id ) => metadata_exists( 'post', $id, '_uxstudio_menu_item_icon_type' ) || metadata_exists( 'post', $id, '_uxstudio_menu_item_icon_value' ) || metadata_exists( 'post', $id, '_uxstudio_menu_item_icon_position' ) );
$assert( 'menu-icons: rollback leaves none of the migrated meta behind', ! is_wp_error( $rb ) && array() === $left, json_encode( $left ) );

$site( array( 'nav-menu-roles' ), array( 'nav-menu-roles' ) );
$sql( '/SELECT DISTINCT m\.post_id FROM wp_postmeta m JOIN wp_posts p ON p\.ID = m\.post_id WHERE m\.meta_key = \'_nav_menu_role\'/', static fn() => array( 41, 42, 43, 44, 45, 46, 47, 48 ) );
foreach ( array( 41, 42, 43, 44, 45, 46, 47, 48 ) as $pid ) { $post( $pid, 'nav_menu_item', 'publish', "Menu $pid" ); }
$meta( 'post', 41, '_nav_menu_role', 'in' );
$meta( 'post', 42, '_nav_menu_role', 'out' );
$meta( 'post', 43, '_nav_menu_role', 'in' ); $meta( 'post', 43, '_nav_menu_role_display_mode', 'hide' );
$meta( 'post', 44, '_nav_menu_role', array( 'administrator', 'editor' ) );
$meta( 'post', 45, '_nav_menu_role', array( 'subscriber' ) ); $meta( 'post', 45, '_nav_menu_role_display_mode', 'hide' );
$meta( 'post', 46, '_nav_menu_role', array() );
$meta( 'post', 47, '_nav_menu_role', array( 'ghost' ) );
$meta( 'post', 48, '_nav_menu_role', array( 'editor', 'edit_posts' ) );
$items = $items_of( 'nav-menu-roles' );
$by    = array_column( $items, null, 'id' );
$plan  = SourceRegistry::get( 'nav-menu-roles' )->plan();
$vis   = static fn( int $id ) => $by[ $id ]['meta']['_uxstudio_menu_item_visible'] ?? null;
$rules = static fn( int $id ) => isset( $by[ $id ]['meta']['_uxstudio_menu_item_rules'] ) ? json_decode( $by[ $id ]['meta']['_uxstudio_menu_item_rules'], true ) : null;
$assert( 'nav-menu-roles: in / out / hide-for-logged-in map exactly; no rule = nothing', '1' === $vis( 41 ) && '2' === $vis( 42 ) && '2' === $vis( 43 ) && ! isset( $by[46] ) );
$assert( 'nav-menu-roles: a role list becomes a "has one of the roles" rule (no longer narrowed to logged in)', array( array( 't' => 'role', 'r' => array( 'administrator', 'editor' ) ) ) === $rules( 44 ) && null === $vis( 44 ), json_encode( $by[44]['meta'] ?? null ) );
$assert( 'nav-menu-roles: hide-for-roles becomes "has none of the roles" (nothing is skipped any more)', array( array( 't' => 'not_role', 'r' => array( 'subscriber' ) ) ) === $rules( 45 ) && ! $lossy_has( $plan, 'Menu 45 (subscriber)' ), json_encode( $plan['lossy'] ) );
$assert( 'nav-menu-roles: a role the site does not have is skipped when it is the only one, and named when only part of the list is unknown', ! isset( $by[47] ) && array( array( 't' => 'role', 'r' => array( 'editor' ) ) ) === $rules( 48 ) && $lossy_has( $plan, 'Menu 48 (edit_posts)' ) && $lossy_has( $plan, 'Menu 47 (ghost)' ) && $lossy_has( $plan, 'do not exist on this site' ), json_encode( $plan['lossy'] ) );
$assert( 'nav-menu-roles: the old "narrowed to logged in" and "cannot hide for a role" sentences are gone', ! $lossy_has( $plan, 'only knows "logged in"' ) && ! $lossy_has( $plan, 'cannot hide an item for a role' ) );

$site( array( 'if-menu' ), array( 'if-menu' ) );
$sql( '/m\.meta_key = \'if_menu_enable\'/', static fn() => array( 51, 52, 53, 54, 55, 56, 57, 58, 59 ) );
foreach ( array( 51, 52, 53, 54, 55, 56, 57, 58, 59 ) as $pid ) { $post( $pid, 'nav_menu_item', 'publish', "Item $pid" ); }
$meta( 'post', 51, 'if_menu_enable', '1' ); $meta( 'post', 51, 'if_menu_condition', 'user-logged-in' ); $meta( 'post', 51, 'if_menu_condition_type', 'show' );
$meta( 'post', 52, 'if_menu_enable', '1' ); $meta( 'post', 52, 'if_menu_condition', 'user-logged-in' ); $meta( 'post', 52, 'if_menu_condition_type', 'hide' );
$meta( 'post', 53, 'if_menu_enable', '1' ); $meta( 'post', 53, 'if_menu_condition', 'is-front-page' ); $meta( 'post', 53, 'if_menu_condition_type', 'show' );
$GLOBALS['uxs_meta']['post'][54]['if_menu_enable'] = array( '1', 'and' ); $GLOBALS['uxs_meta']['post'][54]['if_menu_condition'] = array( 'user-logged-in', 'is-front-page' ); $GLOBALS['uxs_meta']['post'][54]['if_menu_condition_type'] = array( 'show', 'show' );
$im = static function ( int $id, array $enable, array $conds, array $types ): void {
	$GLOBALS['uxs_meta']['post'][ $id ]['if_menu_enable']         = $enable;
	$GLOBALS['uxs_meta']['post'][ $id ]['if_menu_condition']      = $conds;
	$GLOBALS['uxs_meta']['post'][ $id ]['if_menu_condition_type'] = $types;
};
$im( 55, array( '1', 'and' ), array( 'user-logged-in', 'user-is-editor' ), array( 'show', 'show' ) );                       // logged in AND editor
$im( 56, array( '1', 'or' ), array( 'user-is-editor', 'user-is-author' ), array( 'show', 'show' ) );                         // editor OR author
$im( 57, array( '1', 'and', 'and' ), array( 'user-logged-in', 'user-is-subscriber', 'user-is-editor' ), array( 'show', 'hide', 'hide' ) ); // logged in AND not subscriber AND not editor
$im( 58, array( '1', 'or' ), array( 'user-logged-in', 'user-is-editor' ), array( 'show', 'show' ) );                       // logged in OR editor (not expressible)
$im( 59, array( '1' ), array( 'user-is-ghost' ), array( 'show' ) );                                                           // role the site lacks
$items = $items_of( 'if-menu' );
$by    = array_column( $items, null, 'id' );
$plan  = SourceRegistry::get( 'if-menu' )->plan();
$rules = static fn( int $id ) => isset( $by[ $id ]['meta']['_uxstudio_menu_item_rules'] ) ? json_decode( $by[ $id ]['meta']['_uxstudio_menu_item_rules'], true ) : null;
$assert( 'if-menu: single "logged in" rules map (hide = logged out); a page condition is counted', '1' === $by[51]['meta']['_uxstudio_menu_item_visible'] && '2' === $by[52]['meta']['_uxstudio_menu_item_visible'] && ! isset( $by[53] ) && ! isset( $by[54] ) && $lossy_has( $plan, '"is-front-page"' ), json_encode( $plan['lossy'] ) );
$assert( 'if-menu: several rules joined by AND are transferred (logged in AND role editor)', array( array( 't' => 'in' ), array( 't' => 'role', 'r' => array( 'editor' ) ) ) === $rules( 55 ), json_encode( $by[55]['meta'] ?? null ) );
$assert( 'if-menu: OR of "user is <role>" becomes one role rule', array( array( 't' => 'role', 'r' => array( 'author', 'editor' ) ) ) === $rules( 56 ), json_encode( $by[56]['meta'] ?? null ) );
$assert( 'if-menu: show + hide terms mix (logged in, not subscriber, not editor)', array( array( 't' => 'in' ), array( 't' => 'not_role', 'r' => array( 'editor' ) ), array( 't' => 'not_role', 'r' => array( 'subscriber' ) ) ) === $rules( 57 ), json_encode( $by[57]['meta'] ?? null ) );
$assert( 'if-menu: any other OR and a role the site lacks are skipped and counted', ! isset( $by[58] ) && ! isset( $by[59] ) && $lossy_has( $plan, 'combine rules with OR' ) && $lossy_has( $plan, 'role "ghost"' ), json_encode( $plan['lossy'] ) );
$assert( 'if-menu: the old "several rules are not transferred" sentence is gone', ! $lossy_has( $plan, 'several rules' ) );

$site( array( 'menu-items-visibility-control' ), array( 'menu-items-visibility-control' ) );
$sql( '/m\.meta_key = \'_menu_item_visibility\'/', static fn() => array( 61, 62, 63, 64, 65, 66, 67, 68 ) );
foreach ( array( 61, 62, 63, 64, 65, 66, 67, 68 ) as $pid ) { $post( $pid, 'nav_menu_item', 'publish', "Logic $pid" ); }
$meta( 'post', 61, '_menu_item_visibility', 'is_user_logged_in()' ); $meta( 'post', 62, '_menu_item_visibility', '! is_user_logged_in()' ); $meta( 'post', 63, '_menu_item_visibility', 'is_front_page() && current_user_can("edit_posts")' );
$meta( 'post', 64, '_menu_item_visibility', "is_user_logged_in() && current_user_can('editor')" );
$meta( 'post', 65, '_menu_item_visibility', '!current_user_can( "subscriber" );' );
$meta( 'post', 66, '_menu_item_visibility', "current_user_can('edit_posts')" );
$meta( 'post', 67, '_menu_item_visibility', "current_user_can('editor') || current_user_can('author')" );
$meta( 'post', 68, '_menu_item_visibility', "wp_get_current_user()->ID == 1; system('id')" );
$items = $items_of( 'menu-items-visibility-control' );
$by    = array_column( $items, null, 'id' );
$plan  = SourceRegistry::get( 'menu-items-visibility-control' )->plan();
$rules = static fn( int $id ) => isset( $by[ $id ]['meta']['_uxstudio_menu_item_rules'] ) ? json_decode( $by[ $id ]['meta']['_uxstudio_menu_item_rules'], true ) : null;
$assert( 'menu-items-visibility-control: is_user_logged_in() and its negation map to logged in / out', '1' === $by[61]['meta']['_uxstudio_menu_item_visible'] && '2' === $by[62]['meta']['_uxstudio_menu_item_visible'] );
$assert( 'menu-items-visibility-control: fixed forms with roles map (current_user_can(role), negation, &&), pattern only, nothing executed', array( array( 't' => 'in' ), array( 't' => 'role', 'r' => array( 'editor' ) ) ) === $rules( 64 ) && array( array( 't' => 'not_role', 'r' => array( 'subscriber' ) ) ) === $rules( 65 ), json_encode( array( $by[64]['meta'] ?? null, $by[65]['meta'] ?? null ) ) );
$assert( 'menu-items-visibility-control: capabilities, ||, other functions and injected code stay lossy (5 expressions)', ! isset( $by[63] ) && ! isset( $by[66] ) && ! isset( $by[67] ) && ! isset( $by[68] ) && 4 === count( $items ) && $lossy_has( $plan, '4 menu items are controlled by a PHP expression' ), json_encode( $plan['lossy'] ) );

// Real meta writers on the menu visibility source: module off => written, module on => staged then written; idempotent; rollback.
$site( array( 'nav-menu-roles' ), array( 'nav-menu-roles' ) );
$sql( '/SELECT DISTINCT m\.post_id FROM wp_postmeta m JOIN wp_posts p ON p\.ID = m\.post_id WHERE m\.meta_key = \'_nav_menu_role\'/', static fn() => array( 41, 42 ) );
foreach ( array( 41, 42 ) as $pid ) { $post( $pid, 'nav_menu_item', 'publish', "Menu $pid" ); }
$meta( 'post', 41, '_nav_menu_role', 'in' ); $meta( 'post', 42, '_nav_menu_role', 'out' );
$meta( 'post', 42, '_uxstudio_menu_item_visible', '1' ); // UX Studio already has a different value here.
$run = Runner::start( array( 'nav-menu-roles' ), false );
$r1  = $drive();
$assert( 'menu visibility (module off): written where free, an existing different value is kept (skipped, reported)', '1' === get_post_meta( 41, '_uxstudio_menu_item_visible', true ) && '1' === get_post_meta( 42, '_uxstudio_menu_item_visible', true ) && 1 === $r1['per_source']['nav-menu-roles']['written'] && false !== strpos( json_encode( $r1['per_source']['nav-menu-roles']['items'] ), 'different value' ), json_encode( $r1['per_source']['nav-menu-roles']['items'] ) );
$ver = Runner::verify();
$assert( 'menu visibility: verify flags the item that kept a different value', 1 === count( array_filter( $ver['nav-menu-roles'], static fn( $c ) => ! $c['ok'] && 0 === strpos( $c['id'], 'data-bad:' ) ) ), json_encode( $ver ) );
$rb = Runner::rollback( $run['id'] );
$assert( 'menu visibility: rollback deletes the meta the run created and leaves the older value', ! metadata_exists( 'post', 41, '_uxstudio_menu_item_visible' ) && '1' === get_post_meta( 42, '_uxstudio_menu_item_visible', true ) );

// Rules meta (roles): written as JSON, compared by meaning, an item with another rule is kept, rollback removes only what the run wrote.
$site( array( 'nav-menu-roles' ), array( 'nav-menu-roles' ) );
$sql( '/SELECT DISTINCT m\.post_id FROM wp_postmeta m JOIN wp_posts p ON p\.ID = m\.post_id WHERE m\.meta_key = \'_nav_menu_role\'/', static fn() => array( 71, 72, 73 ) );
foreach ( array( 71, 72, 73 ) as $pid ) { $post( $pid, 'nav_menu_item', 'publish', "Roles $pid" ); }
$meta( 'post', 71, '_nav_menu_role', array( 'editor', 'author' ) );
$meta( 'post', 72, '_nav_menu_role', array( 'editor' ) ); $meta( 'post', 72, '_uxstudio_menu_item_visible', '2' ); // another rule already there
$meta( 'post', 73, '_nav_menu_role', array( 'subscriber' ) ); $meta( 'post', 73, '_uxstudio_menu_item_rules', '[{"t":"role","r":["subscriber"]}]' ); // the same rule, already there
$run = Runner::start( array( 'nav-menu-roles' ), false );
$r1  = $drive();
$assert( 'menu visibility rules: the role rule is written as canonical JSON', '[{"t":"role","r":["author","editor"]}]' === get_post_meta( 71, '_uxstudio_menu_item_rules', true ), (string) get_post_meta( 71, '_uxstudio_menu_item_rules', true ) );
$assert( 'menu visibility rules: another rule on the item is kept (skipped, reported), the same rule counts as unchanged', '2' === get_post_meta( 72, '_uxstudio_menu_item_visible', true ) && ! metadata_exists( 'post', 72, '_uxstudio_menu_item_rules' ) && false !== strpos( json_encode( $r1['per_source']['nav-menu-roles']['items'] ), 'different value' ), json_encode( $r1['per_source']['nav-menu-roles']['items'] ) );
$ver = Runner::verify();
$assert( 'menu visibility rules: verify flags only the item that kept another rule', 1 === count( array_filter( $ver['nav-menu-roles'], static fn( $c ) => ! $c['ok'] && 0 === strpos( $c['id'], 'data-bad:' ) ) ), json_encode( $ver ) );
$rb = Runner::rollback( $run['id'] );
$assert( 'menu visibility rules: rollback removes the rules meta the run created and keeps the others', ! metadata_exists( 'post', 71, '_uxstudio_menu_item_rules' ) && metadata_exists( 'post', 73, '_uxstudio_menu_item_rules' ) && '2' === get_post_meta( 72, '_uxstudio_menu_item_visible', true ) );

/* ═══ 6 Last login ═══ */

$site( array( 'when-last-login' ), array( 'when-last-login' ), array(), array( 'activity-log', 'user-last-login' ) );
$sql( '/FROM wp_usermeta WHERE meta_key = \'when_last_login\'/', static fn() => array( array( 'user_id' => 1, 'meta_value' => '1790000000' ), array( 'user_id' => 2, 'meta_value' => '0' ) ) );
$plan = SourceRegistry::get( 'when-last-login' )->plan();
$assert( 'when-last-login: one time to copy; module on does NOT stage it (harmless)', 1 === $data_count( $plan, 'last_login' ) && false === $plan['staged'] );
$run = Runner::start( array( 'when-last-login' ), false );
$r1  = $drive();
$assert( 'when-last-login: written directly even with the module on, nothing staged', '1790000000' === get_metadata( 'user', 1, 'uxstudio_user_last_login', true ) && array() === Staging::all() );
$r2 = Runner::start( array( 'when-last-login' ), false );
$r2 = $drive();
$assert( 'when-last-login: second run writes 0', 0 === $r2['per_source']['when-last-login']['written'] );
Runner::rollback( $run['id'] );
$assert( 'when-last-login: rollback removes the created meta', ! metadata_exists( 'user', 1, 'uxstudio_user_last_login' ) );
$site( array( 'wp-last-login' ), array( 'wp-last-login' ) );
$sql( '/FROM wp_usermeta WHERE meta_key = \'wp-last-login\'/', static fn() => array( array( 'user_id' => 5, 'meta_value' => '1780000000' ) ) );
$assert( 'wp-last-login: reads its own meta key', 1 === count( $items_of( 'wp-last-login' ) ) );

/* ═══ 7 SMTP ═══ */

$key  = random_bytes( SODIUM_CRYPTO_SECRETBOX_KEYBYTES );
$enc  = static function ( string $plain ) use ( $key ): string { $n = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ); return base64_encode( $n . sodium_crypto_secretbox( $plain, $n, $key ) ); };
$assert( 'smtp: libsodium secretbox of WP Mail SMTP decrypts; wrong key and missing key give null, plain values pass', 'tajne' === SmtpSource::sodium_decrypt( $enc( 'tajne' ), $key ) && null === SmtpSource::sodium_decrypt( $enc( 'tajne' ), random_bytes( 32 ) ) && null === SmtpSource::sodium_decrypt( $enc( 'tajne' ), '' ) && 'pla' === SmtpSource::sodium_decrypt( 'pla', $key ) );
$wpms = array(
	'mail' => array( 'from_email' => 'rezervace@firma.cz', 'from_name' => 'Firma', 'mailer' => 'smtp', 'return_path' => true, 'from_email_force' => true, 'from_name_force' => false ),
	'smtp' => array( 'host' => 'smtp.firma.cz', 'port' => 465, 'encryption' => 'ssl', 'autotls' => true, 'auth' => true, 'user' => 'rezervace@firma.cz', 'pass' => $enc( 'Heslo#1' ) ),
	'logs' => array( 'enabled' => true ),
);
$site( array( 'wp-mail-smtp' ), array( 'wp-mail-smtp' ), array( 'wp_mail_smtp' => $wpms, 'wp_mail_smtp_mail_key' => base64_encode( $key ) ) );
$plan = SourceRegistry::get( 'wp-mail-smtp' )->plan();
$assert( 'wp-mail-smtp: SMTP settings + encrypted password are planned; the password is never shown', 'smtp.firma.cz' === array_column( $plan['transfer'], 'to', 'key' )['host'] && false === strpos( json_encode( $plan ), 'Heslo#1' ) && '********' === array_column( $plan['transfer'], 'to', 'key' )['uxstudio_secret_smtp_password'], json_encode( $plan['transfer'] ) );
$assert( 'wp-mail-smtp: return path and e-mail log are reported as not transferred', $lossy_has( $plan, 'return path' ) && $lossy_has( $plan, 'e-mail log' ) );
SourceRegistry::get( 'wp-mail-smtp' )->migrate( array( 'run_id' => 'rs1' ), false );
$assert( 'wp-mail-smtp (module off): settings written, password decrypts to the source password through Security', 465 === $opt( 'uxstudio_smtp_email' )['port'] && 'ssl' === $opt( 'uxstudio_smtp_email' )['encryption'] && 'Heslo#1' === Security::get_secret( 'uxstudio_secret_smtp_password' ) && ! isset( $opt( 'uxstudio_smtp_email' )['password'] ) );
$site( array( 'wp-mail-smtp' ), array( 'wp-mail-smtp' ), array( 'wp_mail_smtp' => $wpms, 'wp_mail_smtp_mail_key' => base64_encode( $key ) ), array( 'activity-log', 'smtp-email' ) );
$run = Runner::start( array( 'wp-mail-smtp' ), false );
$drive();
$entry = Staging::entry( $run['id'] );
$assert( 'wp-mail-smtp (module on): live mail settings untouched, values and the sealed password wait in staging', null === $opt( 'uxstudio_smtp_email' ) && '' === Security::get_secret( 'uxstudio_secret_smtp_password' ) && 'smtp.firma.cz' === $entry['settings']['smtp-email']['host'] && 1 === count( $entry['secrets'] ) && false === strpos( json_encode( $GLOBALS['uxs_options'][ Staging::OPTION ] ), 'Heslo#1' ) );
Staging::apply( $run['id'] );
$assert( 'wp-mail-smtp: Staging::apply makes the mail settings live', 'smtp.firma.cz' === $opt( 'uxstudio_smtp_email' )['host'] && 'Heslo#1' === Security::get_secret( 'uxstudio_secret_smtp_password' ) );

// Unreadable password + unsupported mailer.
$site( array( 'wp-mail-smtp' ), array( 'wp-mail-smtp' ), array( 'wp_mail_smtp' => array( 'mail' => array( 'mailer' => 'sendgrid', 'from_email' => 'a@b.cz' ), 'smtp' => array( 'pass' => $enc( 'x' ) ) ) ) );
$plan = SourceRegistry::get( 'wp-mail-smtp' )->plan();
$assert( 'wp-mail-smtp: an unsupported mailer is named and only the sender moves', $lossy_has( $plan, '"sendgrid"' ) && 'smtp' === array_column( $plan['transfer'], 'to', 'key' )['mailer'] );
$site( array( 'wp-mail-smtp' ), array( 'wp-mail-smtp' ), array( 'wp_mail_smtp' => array( 'mail' => array( 'mailer' => 'smtp' ), 'smtp' => array( 'host' => 'h', 'user' => 'u', 'pass' => $enc( 'x' ) ) ) ) );
$assert( 'wp-mail-smtp: a password that cannot be decrypted (no key) is reported', $lossy_has( SourceRegistry::get( 'wp-mail-smtp' )->plan(), 'could not be decrypted' ) );
$site( array( 'wp-mail-smtp' ), array( 'wp-mail-smtp' ), array( 'wp_mail_smtp' => array( 'mail' => array( 'mailer' => 'gmail' ), 'gmail' => array( 'client_id' => 'cid', 'client_secret' => $enc( 'csec' ), 'refresh_token' => 'REFRESH-TOKEN-VALUE' ) ), 'wp_mail_smtp_mail_key' => base64_encode( $key ) ) );
$plan = SourceRegistry::get( 'wp-mail-smtp' )->plan();
$assert( 'wp-mail-smtp (Gmail): client id + secret move, the OAuth token does not ("connect again")', 'cid' === array_column( $plan['transfer'], 'to', 'key' )['gmail_client_id'] && $lossy_has( $plan, 'Connect to Google' ) && false === strpos( json_encode( $plan ), 'REFRESH-TOKEN-VALUE' ), json_encode( $plan['lossy'] ) );

// Easy WP SMTP (same scheme, own option / key / constants), Post SMTP (base64), FluentSMTP (AES-CTR).
$site( array( 'easy-wp-smtp' ), array( 'easy-wp-smtp' ), array( 'easy_wp_smtp' => array( 'mail' => array( 'mailer' => 'smtp', 'from_email' => 'e@x.cz' ), 'smtp' => array( 'host' => 'easy.host', 'port' => 587, 'encryption' => 'tls', 'user' => 'u', 'pass' => $enc( 'easy-pass' ) ) ), 'easy_wp_smtp_mail_key' => base64_encode( $key ) ) );
SourceRegistry::get( 'easy-wp-smtp' )->migrate( array( 'run_id' => 'rs3' ), false );
$assert( 'easy-wp-smtp: own option + key option decrypt', 'easy.host' === $opt( 'uxstudio_smtp_email' )['host'] && 'easy-pass' === Security::get_secret( 'uxstudio_secret_smtp_password' ) );
$site( array( 'post-smtp' ), array( 'post-smtp' ), array( 'postman_options' => array( 'transport_type' => 'smtp', 'sender_email' => 'p@x.cz', 'sender_name' => 'P', 'hostname' => 'post.host', 'port' => 25, 'enc_type' => 'none', 'auth_type' => 'plain', 'basic_auth_username' => 'pu', 'basic_auth_password' => base64_encode( 'post-pass' ), 'prevent_sender_email_override' => 'true', 'fallback_smtp_enabled' => 'true' ) ) );
$plan = SourceRegistry::get( 'post-smtp' )->plan();
SourceRegistry::get( 'post-smtp' )->migrate( array( 'run_id' => 'rs4' ), false );
$assert( 'post-smtp: base64 password, force sender and fallback server (reported)', 'post-pass' === Security::get_secret( 'uxstudio_secret_smtp_password' ) && true === $opt( 'uxstudio_smtp_email' )['force_from_email'] && 'none' === $opt( 'uxstudio_smtp_email' )['encryption'] && $lossy_has( $plan, 'fallback' ) );
define( 'LOGGED_IN_KEY', 'k-test-key' );
define( 'LOGGED_IN_SALT', 'k-test-salt' );
$iv   = random_bytes( 16 );
$fenc = static fn( string $v ): string => base64_encode( $iv . openssl_encrypt( $v . LOGGED_IN_SALT, 'aes-256-ctr', LOGGED_IN_KEY, 0, $iv ) );
$assert( 'fluent-smtp: AES-256-CTR secret decrypts', 'fl-pass' === SmtpSource::fluent_decrypt( $fenc( 'fl-pass' ) ) && null === SmtpSource::fluent_decrypt( 'garbage' ) );
$site( array( 'fluent-smtp' ), array( 'fluent-smtp' ), array( 'fluentmail-settings' => array( 'use_encrypt' => 'yes', 'misc' => array( 'default_connection' => 'b' ), 'connections' => array(
	'a' => array( 'provider_settings' => array( 'provider' => 'smtp', 'host' => 'a.host', 'sender_email' => 'a@x.cz' ) ),
	'b' => array( 'provider_settings' => array( 'provider' => 'smtp', 'host' => 'fl.host', 'port' => '2525', 'encryption' => 'tls', 'auth' => 'yes', 'username' => 'flu', 'password' => $fenc( 'fl-pass' ), 'sender_email' => 'f@x.cz', 'force_from_email' => 'yes' ) ),
) ) ) );
$plan = SourceRegistry::get( 'fluent-smtp' )->plan();
SourceRegistry::get( 'fluent-smtp' )->migrate( array( 'run_id' => 'rs5' ), false );
$assert( 'fluent-smtp: the default connection is used, its secret decrypted; several connections reported', 'fl.host' === $opt( 'uxstudio_smtp_email' )['host'] && 'fl-pass' === Security::get_secret( 'uxstudio_secret_smtp_password' ) && $lossy_has( $plan, '2 connections' ) );

/* ═══ 8 E-mail log ═══ */

$site( array( 'wp-mail-logging' ), array( 'wp-mail-logging' ), array( 'wpml_settings' => array( 'log-rotation-delete-time' => '1', 'log-rotation-delete-time-days' => 14, 'log-rotation-limit-amout' => '1' ) ), array( 'activity-log', 'email-log' ) );
$table( 'wpml_mails' );
$mails = array(
	array( 'mail_id' => 1, 'timestamp' => gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS ), 'host' => '127.0.0.1', 'receiver' => 'a@x.cz', 'subject' => 'Nova', 'message' => 'tělo', 'headers' => 'H', 'attachments' => '', 'error' => '' ),
	array( 'mail_id' => 2, 'timestamp' => gmdate( 'Y-m-d H:i:s', time() - 40 * DAY_IN_SECONDS ), 'host' => '127.0.0.1', 'receiver' => 'b@x.cz', 'subject' => 'Stara', 'message' => '', 'headers' => '', 'attachments' => '', 'error' => '' ),
	array( 'mail_id' => 3, 'timestamp' => gmdate( 'Y-m-d H:i:s', time() - 2 * DAY_IN_SECONDS ), 'host' => '127.0.0.1', 'receiver' => 'c@x.cz', 'subject' => 'Chyba', 'message' => '', 'headers' => '', 'attachments' => 'a.pdf,b.pdf', 'error' => 'SMTP error' ),
);
$sql( '/SELECT COUNT\(\*\) FROM wp_wpml_mails/', static fn() => array( count( $mails ) ) );
$sql( '/FROM wp_wpml_mails WHERE mail_id > (\d+) ORDER BY mail_id ASC LIMIT (\d+)/', static function ( $m ) use ( $mails ) { return array_slice( array_values( array_filter( $mails, static fn( $r ) => $r['mail_id'] > (int) $m[1] ) ), 0, (int) $m[2] ); } );
$plan = SourceRegistry::get( 'wp-mail-logging' )->plan();
$assert( 'wp-mail-logging: retention days map; the history option is offered, default off', 14 === (int) $plan['transfer'][0]['to'] && 'history' === $plan['options'][0]['key'] && false === $plan['options'][0]['default'] && false === $plan['staged'] && $lossy_has( $plan, 'latest N entries' ), json_encode( $plan ) );
$assert( 'wp-mail-logging: the history is counted for the plan', 3 === $data_count( $plan, 'email' ) );
$run = Runner::start( array( 'wp-mail-logging' ), false );
$assert( 'e-mail log: without the option the run has no data phase (settings only)', false === $run['per_source']['wp-mail-logging']['options']['history'] );
$s1 = $drive();
$assert( 'e-mail log (module on): retention written directly - harmless, not staged', 14 === $opt( 'uxstudio_email_log' )['retention_days'] && array() === Staging::all() && 0 === $s1['per_source']['wp-mail-logging']['total'] );
$store = array();
$install_double( $store, static fn() => false );
$run  = Runner::start( array( 'wp-mail-logging' ), false, array( 'wp-mail-logging' => array( 'history' => true ) ) );
$s1   = $drive();
$row  = $s1['per_source']['wp-mail-logging'];
$assert( 'e-mail log (history on): all 3 rows pass through the engine; progress knows the total', true === $row['options']['history'] && 3 === $row['total'] && 3 === count( $store['wp-mail-logging'] ) && 'done' === $row['status'], json_encode( $row ) );
$assert( 'e-mail log: normalized rows - error text means status error, attachments split', 'error' === $store['wp-mail-logging']['wpml:3']['status'] && array( 'a.pdf', 'b.pdf' ) === $store['wp-mail-logging']['wpml:3']['attachments'] && 'success' === $store['wp-mail-logging']['wpml:1']['status'] );
$r2 = Runner::start( array( 'wp-mail-logging' ), false, array( 'wp-mail-logging' => array( 'history' => true ) ) );
$r2 = $drive();
$assert( 'e-mail log: second history run writes nothing new', 0 === $r2['per_source']['wp-mail-logging']['written'] );

$site( array( 'email-log' ), array( 'email-log' ) );
$table( 'email_log' );
$sql( '/SELECT COUNT\(\*\) FROM wp_email_log/', static fn() => array( 1 ) );
$sql( '/FROM wp_email_log WHERE id > (\d+) ORDER BY id ASC LIMIT (\d+)/', static function ( $m ) { return (int) $m[1] < 1 ? array( array( 'id' => 1, 'to_email' => 'z@x.cz', 'subject' => 'S', 'message' => 'M', 'headers' => '', 'attachments' => '/srv/a.pdf', 'sent_date' => '2026-10-20 10:00:00', 'ip_address' => '1.2.3.4', 'result' => '0', 'error_message' => 'fail' ) ) : array(); } );
$items = $items_of( 'email-log' );
$assert( 'email-log: result 0 = error, attachment path reduced to the file name', 'error' === $items[0]['status'] && array( 'a.pdf' ) === $items[0]['attachments'] );

/* ═══ 9 Cron ═══ */

$site( array( 'wp-crontrol' ), array( 'wp-crontrol' ), array( 'crontrol_schedules' => array( 'every_5' => array( 'interval' => 300, 'display' => 'Co 5 minut' ), 'bad' => array( 'interval' => 0 ) ), 'wp_crontrol_paused' => array( 'moje_akce' => true ) ), array( 'activity-log', 'cron-control' ) );
$GLOBALS['uxs_cron'] = array( time() + 60 => array( 'crontrol_cron_job' => array( 'a' => array( 'args' => array() ), 'b' => array( 'args' => array() ) ), 'crontrol_url_cron_job' => array( 'c' => array( 'args' => array() ) ), 'jina_akce' => array( 'd' => array() ) ) );
$plan = SourceRegistry::get( 'wp-crontrol' )->plan();
$assert( 'wp-crontrol: 1 valid interval; PHP and URL events and paused hooks are counted precisely', 1 === $data_count( $plan, 'schedule' ) && 2 === $data_count( $plan, 'php' ) && 1 === $data_count( $plan, 'url' ) && $lossy_has( $plan, '2 cron events run PHP code' ) && $lossy_has( $plan, '1 cron events call a URL' ) && $lossy_has( $plan, '1 paused hooks' ), json_encode( $plan ) );
$assert( 'wp-crontrol: harmless, written directly even with the module on', false === $plan['staged'] );
$store = array();
AbstractSource::set_data_double( null );
$run = Runner::start( array( 'wp-crontrol' ), false );
$r1  = $drive();
$assert( 'wp-crontrol: the interval lands in the module option (real writer)', 300 === ( $opt( 'uxstudio_cron_schedules' )['every_5']['interval'] ?? 0 ) && 1 === $r1['per_source']['wp-crontrol']['written'] );
$r2 = Runner::start( array( 'wp-crontrol' ), false );
$r2 = $drive();
$assert( 'wp-crontrol: second run is idempotent', 0 === $r2['per_source']['wp-crontrol']['written'] );
Runner::rollback( $run['id'] );
$assert( 'wp-crontrol: rollback restores the option (it did not exist before)', null === $opt( 'uxstudio_cron_schedules' ) );

/* ═══ 10 Maintenance ═══ */

$site( array( 'wp-maintenance-mode' ), array( 'wp-maintenance-mode' ), array( 'wpmm_settings' => array(
	'general' => array( 'status' => 1, 'frontend_role' => array( 'editor' ) ),
	'design'  => array( 'heading' => '<b>Pracujeme</b>', 'text' => '<p>Brzy budeme zpet.</p>', 'heading_color' => '#112233', 'text_color' => '#445566', 'bg_type' => 'custom', 'bg_color' => '#ffffff' ),
) ), array( 'activity-log', 'maintenance-mode' ) );
$plan = SourceRegistry::get( 'wp-maintenance-mode' )->plan();
$to   = array_column( $plan['transfer'], 'to', 'key' );
$assert( 'wp-maintenance-mode: status on -> mode maintenance, texts, colours and roles map; background image is lost', 'maintenance' === $to['mode'] && 'Pracujeme' === $to['headline_text'] && '#112233' === $to['headline_colour'] && 'roles' === $to['auth_type'] && 'editor' === $to['access_roles'] && ! isset( $to['background_colour'] ) && $lossy_has( $plan, 'background image' ), json_encode( $to ) );
$assert( 'wp-maintenance-mode: staged because turning maintenance on is behaviour-changing; warns about the switch', true === $plan['staged'] && false !== strpos( implode( ' ', $plan['warnings'] ), 'shows the maintenance page' ), json_encode( $plan['warnings'] ) );
$run = Runner::start( array( 'wp-maintenance-mode' ), false );
$drive();
$assert( 'wp-maintenance-mode (module on): live mode untouched, mode waits in staging', null === $opt( 'uxstudio_maintenance_mode' ) && 'maintenance' === Staging::entry( $run['id'] )['settings']['maintenance-mode']['mode'] );

$site( array( 'coming-soon' ), array( 'coming-soon' ), array( 'seedprod_settings' => json_encode( array( 'enable_coming_soon_mode' => true ) ), 'seedprod_coming_soon_page_id' => 7 ) );
$post( 7, 'page', 'publish', 'Brzy' );
$plan = SourceRegistry::get( 'coming-soon' )->plan();
$to   = array_column( $plan['transfer'], 'to', 'key' );
$assert( 'coming-soon (SeedProd): coming_soon mode and its page as an existing page', 'coming_soon' === $to['mode'] && 'page' === $to['layout_type'] && '7' === $to['existing_page'], json_encode( $to ) );
$site( array( 'maintenance' ), array( 'maintenance' ), array( 'mtnc-options' => array( 'options' => array( 'status' => '1', 'header_text' => 'Probíhá údržba', 'mode' => 'layout' ) ) ) );
$to = array_column( SourceRegistry::get( 'maintenance' )->plan()['transfer'], 'to', 'key' );
$assert( 'maintenance (WebFactory): status + header text', 'maintenance' === $to['mode'] && 'Probíhá údržba' === $to['headline_text'] );
$site( array( 'under-construction-page' ), array( 'under-construction-page' ), array( 'ucp_options' => array( 'status' => '0', 'heading1' => 'Stavime', 'content' => 'Text', 'whitelisted_roles' => array( 'administrator', 'editor' ) ) ) );
$to = array_column( SourceRegistry::get( 'under-construction-page' )->plan()['transfer'], 'to', 'key' );
$assert( 'under-construction-page: off stays disabled, roles map (administrators always pass, so they are not listed)', 'disabled' === $to['mode'] && 'editor' === $to['access_roles'], json_encode( $to ) );

/* ═══ 11 Activity log ═══ */

$assert( 'activity log: period parser (months, years, days)', 90 === ActivityLogSource::period_to_days( '3 months' ) && 365 === ActivityLogSource::period_to_days( '1 year' ) && 30 === ActivityLogSource::period_to_days( '30 days' ) && 0 === ActivityLogSource::period_to_days( 'nesmysl' ) );
$site( array( 'wp-security-audit-log' ), array( 'wp-security-audit-log' ), array( 'wsal_pruning-date-e' => 'yes', 'wsal_pruning-date' => '3 months', 'wsal_pruning-limit-e' => 'yes' ), array( 'activity-log' ) );
$plan = SourceRegistry::get( 'wp-security-audit-log' )->plan();
$assert( 'wp-security-audit-log: 3 months -> 90 days; module is on, so it is staged; history not imported (said so)', '90' === $plan['transfer'][0]['to'] && true === $plan['staged'] && $lossy_has( $plan, 'history of the plugin is not imported' ) && $lossy_has( $plan, 'latest N events' ), json_encode( $plan ) );
$run = Runner::start( array( 'wp-security-audit-log' ), false );
$drive();
$assert( 'wp-security-audit-log: the live retention is not changed while the module is on', null === $opt( 'uxstudio_activity_log' ) && 90 === Staging::entry( $run['id'] )['settings']['activity-log']['retention_days'] );
$site( array( 'wp-security-audit-log' ), array( 'wp-security-audit-log' ), array( 'wsal_pruning-date-e' => 'no', 'wsal_pruning-date' => '3 months' ) );
$assert( 'wp-security-audit-log: pruning off = keep forever (0)', '0' === SourceRegistry::get( 'wp-security-audit-log' )->plan()['transfer'][0]['to'] );
$site( array( 'simple-history' ), array( 'simple-history' ), array( 'simple_history_retention_days' => 60 ) );
$assert( 'simple-history: retention days', '60' === SourceRegistry::get( 'simple-history' )->plan()['transfer'][0]['to'] );
$site( array( 'aryo-activity-log' ), array( 'aryo-activity-log' ), array( 'activity-log-settings' => array( 'logs_lifespan' => '0' ) ) );
$assert( 'aryo-activity-log: lifespan 0 = keep forever', '0' === SourceRegistry::get( 'aryo-activity-log' )->plan()['transfer'][0]['to'] );
$site( array( 'adminify-activity-logs' ), array( 'adminify-activity-logs' ), array( '_adminify_activity_logs_days' => 14 ) );
$assert( 'adminify-activity-logs: its own retention option', '14' === SourceRegistry::get( 'adminify-activity-logs' )->plan()['transfer'][0]['to'] );

/* ═══ 12 Post type order ═══ */

$site( array( 'post-types-order' ), array( 'post-types-order' ), array( 'cpto_options' => array( 'show_reorder_interfaces' => array( 'post' => 'show', 'page' => 'hide', 'product' => 'show' ), 'archive_drag_drop' => 1 ) ) );
$plan = SourceRegistry::get( 'post-types-order' )->plan();
$assert( 'post-types-order: only types with the interface shown; menu_order itself is not copied (said so)', 'post, product' === $plan['transfer'][0]['to'] && false !== strpos( implode( ' ', $plan['notes'] ), 'menu order' ) && $lossy_has( $plan, 'archive pages' ), json_encode( $plan ) );
$site( array( 'intuitive-custom-post-order' ), array( 'intuitive-custom-post-order' ), array( 'hicpo_options' => array( 'objects' => array( 'page' ), 'tags' => array( 'category' ) ) ) );
$plan = SourceRegistry::get( 'intuitive-custom-post-order' )->plan();
$assert( 'intuitive-custom-post-order: post types + taxonomies reported as lost', 'page' === $plan['transfer'][0]['to'] && $lossy_has( $plan, 'terms (category)' ) );

/* ═══ Cross-cutting ═══ */

// Every key a mapping writes exists in the real module code (typo guard for the settings written by F3 sources).
$typos = array();
$check = array(
	'smtp-email'          => array( 'mailer', 'from_email', 'force_from_email', 'from_name', 'force_from_name', 'host', 'port', 'encryption', 'username', 'gmail_client_id' ),
	'email-log'           => array( 'retention_days' ),
	'activity-log'        => array( 'retention_days' ),
	'external-permalinks' => array( 'post_types' ),
	'post-type-order'     => array( 'post_types' ),
	'maintenance-mode'    => array( 'mode', 'layout_type', 'existing_page', 'auth_type', 'access_roles', 'headline_text', 'headline_colour', 'body_text', 'body_colour', 'background_colour' ),
);
foreach ( $check as $module => $keys ) {
	$code = (string) file_get_contents( $root . '/includes/Modules/' . str_replace( ' ', '', ucwords( str_replace( '-', ' ', $module ) ) ) . '/Module.php' );
	foreach ( $keys as $key ) {
		if ( 1 !== preg_match( "/'key'\s*=>\s*'" . preg_quote( $key, '/' ) . "'/", $code ) ) { $typos[] = "$module.$key"; }
	}
}
$assert( 'every setting key the F3 sources write exists in the target module code', array() === $typos, implode( ',', $typos ) );
$assert( 'meta keys the sources write exist in the target modules', 1 === preg_match( "/META_KEY\s*=\s*'_uxstudio_menu_item_visible'/", (string) file_get_contents( $root . '/includes/Modules/MenuVisibility/Module.php' ) ) && 1 === preg_match( "/META_KEY\s*=\s*'_uxstudio_auto_unpublish'/", (string) file_get_contents( $root . '/includes/Modules/AutoUnpublish/Module.php' ) ) && 1 === preg_match( "/META_KEY\s*=\s*'uxstudio_user_last_login'/", (string) file_get_contents( $root . '/includes/Modules/UserLastLogin/Module.php' ) ) && 1 === preg_match( "/ICON_TYPE_META\s*=\s*'_uxstudio_menu_item_icon_type'/", (string) file_get_contents( $root . '/includes/Modules/MenuIcons/Module.php' ) ) && 1 === preg_match( "/const SCHEDULES_OPTION\s*=\s*'uxstudio_cron_schedules'/", (string) file_get_contents( $root . '/includes/Modules/CronControl/Module.php' ) ) );

// Revert only touches what it is told to, and refuses path tricks.
define( 'WP_CONTENT_DIR', sys_get_temp_dir() );
$assert( 'Revert refuses snippet file names with a path', false === Revert::one( 'snippet_file', '../etc/passwd' ) && false === Revert::one( 'snippet_file', 'a/b.php' ) && false === Revert::one( 'unknown_type', '1' ) );

// Staging::apply reports activations that could not be switched on.
$site( array( 'redirection' ), array( 'redirection' ) );
Staging::put_activation( 'rpartial', 'nonexistent-source', 'x', array( 1, 2 ) );
$res = Staging::apply( 'rpartial' );
$assert( 'Staging::apply says so when an activation could not be switched on', 1 === count( $res['errors'] ) && 0 === $res['activated'] );

// Constants cannot be undone in PHP, so this runs last.
// wp-config constants win over the stored values.
define( 'WPMS_ON', true );
define( 'WPMS_SMTP_HOST', 'konstanta.firma.cz' );
define( 'WPMS_SMTP_PASS', 'z-konstanty' );
$site( array( 'wp-mail-smtp' ), array( 'wp-mail-smtp' ), array( 'wp_mail_smtp' => $wpms ) );
$plan = SourceRegistry::get( 'wp-mail-smtp' )->plan();
$assert( 'wp-mail-smtp: constants of wp-config (WPMS_ON) are the effective values', 'konstanta.firma.cz' === array_column( $plan['transfer'], 'to', 'key' )['host'] );
SourceRegistry::get( 'wp-mail-smtp' )->migrate( array( 'run_id' => 'rs2' ), false );
$assert( 'wp-mail-smtp: password constant is transferred even without the encryption key', 'z-konstanty' === Security::get_secret( 'uxstudio_secret_smtp_password' ) );

/* ═══ 10 IP block lists of Limit Login Attempts Reloaded and Loginizer -> Firewall bans (PLAN.md 27, A1) ═══ */

$bl_loginizer = array( 'loginizer_options' => array( 'max_retries' => 3 ), 'loginizer_blacklist' => array(
	1 => array( 'start' => '203.0.113.5', 'end' => '203.0.113.5' ),
	2 => array( 'start' => '198.51.100.0', 'end' => '198.51.100.255' ),
	3 => array( 'start' => '203.0.113.5', 'end' => '203.0.113.5' ), // duplicate of 1
	4 => array( 'start' => 'garbage', 'end' => 'garbage' ),
) );
$site( array( 'loginizer' ), array( 'loginizer' ), $bl_loginizer, array( 'activity-log', 'security-optimization' ) );
$assert( 'ip bans: both sources can switch bans on and off (Activatable)', SourceRegistry::get( 'loginizer' ) instanceof \UxStudio\Core\Migration\Activatable && SourceRegistry::get( 'limit-login-attempts-reloaded' ) instanceof \UxStudio\Core\Migration\Activatable );
$items = $items_of( 'loginizer' );
$assert( 'ip bans: entries are normalized, duplicates and garbage left out', 2 === count( $items ) && 'single' === $items[0]['type'] && '203.0.113.5' === $items[0]['value'] && 'cidr' === $items[1]['type'] && '198.51.100.0/24' === $items[1]['value'], json_encode( $items ) );
$plan = SourceRegistry::get( 'loginizer' )->plan();
$assert( 'ip bans: plan counts 2 bans and reports the garbage entry', 2 === $data_count( $plan, 'ip_ban' ) && $lossy_has( $plan, '1 IP list entries could not be transferred' ), json_encode( $plan['lossy'] ) );

// Module on: bans are created switched off and wait for the switch; the Firewall setting waits in staging too.
$store     = array();
$module_on = true;
$install_double( $store, static function () use ( &$module_on ) { return $module_on; } );
$run   = Runner::start( array( 'loginizer' ), false );
$r1    = $drive();
$entry = Staging::entry( $run['id'] );
$act   = $entry['activations'][0] ?? array();
$assert( 'ip bans (module on): both bans wait for the switch under kind ip_ban', 'ip_ban' === ( $act['kind'] ?? '' ) && 'loginizer' === ( $act['source'] ?? '' ) && 2 === count( $act['ids'] ?? array() ), json_encode( $entry ) );
$assert( 'ip bans (module on): Firewall setting is staged, not live', true === ( $entry['settings']['security-optimization']['ip_firewall_enabled'] ?? null ) && ! isset( ( $opt( 'uxstudio_security_optimization' ) ?? array() )['ip_firewall_enabled'] ), json_encode( $entry['settings'] ?? null ) );
$assert( 'ip bans: only created bans get map rows (rollback target)', 2 === count( array_filter( $GLOBALS['wpdb']->map, static fn( $r ) => 'testobj' === $r['target_type'] ) ) );
$run2 = Runner::start( array( 'loginizer' ), false );
$r2   = $drive();
$assert( 'ip bans: a second run creates nothing new (idempotent)', 0 === $r2['per_source']['loginizer']['written'] && 0 === $r2['per_source']['loginizer']['staged'], json_encode( $r2['per_source']['loginizer'] ) );
$ver = Runner::verify();
$assert( 'ip bans: verify finds both and marks them staged', array() === array_filter( $ver['loginizer'], static fn( $c ) => ! $c['ok'] ) && ! empty( array_filter( $ver['loginizer'], static fn( $c ) => ! empty( $c['staged'] ) ) ), json_encode( $ver['loginizer'] ) );
Runner::rollback( $run['id'] );
$assert( 'ip bans: rollback discards the waiting activations', null === Staging::entry( $run['id'] ) );

// Module off: created directly (they have no effect until the module is on), nothing staged.
$site( array( 'limit-login-attempts-reloaded' ), array( 'limit-login-attempts-reloaded' ), array( 'limit_login_blacklist' => array( '203.0.113.9', '198.51.100.7-198.51.100.8' ), 'limit_login_whitelist' => array( '192.0.2.1' ) ), array( 'activity-log' ) );
$store     = array();
$module_on = false;
$install_double( $store, static function () use ( &$module_on ) { return $module_on; } );
$plan = SourceRegistry::get( 'limit-login-attempts-reloaded' )->plan();
$assert( 'llar: 2 bans + 2 CIDR blocks of the range', 3 === $data_count( $plan, 'ip_ban' ), json_encode( $plan['data'] ) );
$run = Runner::start( array( 'limit-login-attempts-reloaded' ), false );
$r1  = $drive();
$assert( 'llar (module off): bans created directly, nothing staged, allow list written to the module option', 3 === count( $store['limit-login-attempts-reloaded'] ) && array() === Staging::all() && "192.0.2.1" === ( $opt( 'uxstudio_security_optimization' )['ip_firewall_allowlist'] ?? null ) && true === ( $opt( 'uxstudio_security_optimization' )['ip_firewall_enabled'] ?? null ), json_encode( array( $opt( 'uxstudio_security_optimization' ), array_keys( $store ) ) ) );
AbstractSource::set_data_double( null );

echo PHP_EOL . ( $failures ? "$failures FAILED" : 'ALL PASSED' ) . PHP_EOL;
exit( $failures ? 1 : 0 );
