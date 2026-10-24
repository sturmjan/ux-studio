<?php
// Test only: never runs over HTTP (the release zip also excludes tests/).
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { exit; }
/**
 * Standalone test of the migration wizard backend (PLAN.md 26, phase F2): every
 * settings-only source maps its fixture options to the expected UX Studio values,
 * a dry run writes nothing, running twice gives the same result, and the snapshot
 * restores exactly. Needs no WordPress and no database:
 *
 *   php tests/migration-sources.php
 *
 * WordPress functions and $wpdb are stubbed below. Exits non-zero when any case fails.
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

class WP_Error {
	public $code;
	public $message;
	public $data;
	public function __construct( $code = '', $message = '', $data = '' ) {
		$this->code    = $code;
		$this->message = $message;
		$this->data    = $data;
	}
	public function get_error_code() { return $this->code; }
	public function get_error_data() { return $this->data; }
}

/** Minimal $wpdb: only what the migration classes call. */
class FakeWpdb {
	public $prefix  = 'wp_';
	public $options = 'wp_options';
	public $posts = 'wp_posts';
	public $postmeta = 'wp_postmeta';
	public $usermeta = 'wp_usermeta';
	public $terms = 'wp_terms';
	public $term_taxonomy = 'wp_term_taxonomy';
	public $term_relationships = 'wp_term_relationships';
	public $map     = array();
	public function insert( $table, $data ) { $GLOBALS['uxs_log'][] = $data; return 1; }
	public function replace( $table, $data ) {
		$k = $data['source_plugin'] . '|' . $data['source_key'] . '|' . $data['target_type'];
		$this->map[ $k ] = $data;
		return 1;
	}
	public function delete( $table, $where ) {
		foreach ( $this->map as $k => $row ) {
			if ( $row['run_id'] === $where['run_id'] ) {
				unset( $this->map[ $k ] );
			}
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
	if ( array_key_exists( $name, $GLOBALS['uxs_options'] ) ) {
		return false;
	}
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
function get_current_user_id() { return 1; }
function wp_salt( $scheme = '' ) { return 'test-salt-' . $scheme; }
function sanitize_text_field( $s ) { return trim( strip_tags( (string) $s ) ); }
function sanitize_textarea_field( $s ) { return trim( strip_tags( (string) $s ) ); }
function sanitize_hex_color( $s ) { return (string) $s; }
function sanitize_key( $s ) { return strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', (string) $s ) ); }
function wp_kses_post( $s ) { return (string) $s; }
function maybe_serialize( $v ) { return is_scalar( $v ) ? (string) $v : serialize( $v ); }
function wp_json_encode( $v ) { return json_encode( $v ); }
function trailingslashit( $s ) { return rtrim( $s, '/' ) . '/'; }
function untrailingslashit( $s ) { return rtrim( $s, '/' ); }
function home_url( $p = '' ) { return 'https://example.test/site' . $p; }
function site_url( $p = '' ) { return 'https://example.test/site' . $p; }
function absint( $v ) { return abs( (int) $v ); }

/** Roles of the fake site. */
function wp_roles() {
	return (object) array(
		'roles' => array(
			'administrator' => array( 'capabilities' => array( 'upload_files' => true, 'copy_posts' => true ) ),
			'editor'        => array( 'capabilities' => array( 'upload_files' => true, 'copy_posts' => true ) ),
			'author'        => array( 'capabilities' => array( 'upload_files' => true, 'duplicate_posts' => true ) ),
			'subscriber'    => array( 'capabilities' => array() ),
		),
	);
}

/** Installed plugins of the fake site (set per case). */
$GLOBALS['uxs_plugins'] = array();
function get_plugins() { return $GLOBALS['uxs_plugins']; }

require_once $root . '/includes/Autoloader.php';
\UxStudio\Autoloader::register();

use UxStudio\Core\Migration\Catalog;
use UxStudio\Core\Migration\History;
use UxStudio\Core\Migration\Mapping;
use UxStudio\Core\Migration\Overlaps;
use UxStudio\Core\Migration\PluginInfo;
use UxStudio\Core\Migration\Runner;
use UxStudio\Core\Migration\SettingsWriter;
use UxStudio\Core\Migration\Snapshot;
use UxStudio\Core\Migration\SourceRegistry;
use UxStudio\Core\Migration\Staging;
use UxStudio\Core\Security;

$failures = 0;
$assert   = static function ( string $name, bool $ok, string $detail = '' ) use ( &$failures ): void {
	if ( ! $ok ) {
		++$failures;
	}
	echo ( $ok ? 'PASS ' : 'FAIL ' ) . $name . ( $ok || '' === $detail ? '' : ' => ' . $detail ) . PHP_EOL;
};

/* Minimal module schemas (keys and types as in the real Module.php files, checked below). */
$sel = static fn( string $key, array $opts, $def = '' ) => array( 'key' => $key, 'type' => 'select', 'options' => array_fill_keys( $opts, '' ), 'default' => $def );
$tog = static fn( string $key, $def = false ) => array( 'key' => $key, 'type' => 'toggle', 'default' => $def );
$txt = static fn( string $key, $def = '' ) => array( 'key' => $key, 'type' => 'text', 'default' => $def );
$num = static fn( string $key, $def = 0 ) => array( 'key' => $key, 'type' => 'number', 'default' => $def );
$mul = static fn( string $key, array $opts, $def = array() ) => array( 'key' => $key, 'type' => 'multiselect', 'options' => array_fill_keys( $opts, '' ), 'default' => $def );
$area = static fn( string $key, $def = '' ) => array( 'key' => $key, 'type' => 'textarea', 'default' => $def );
$all_roles = array( 'administrator', 'editor', 'author', 'subscriber' );

$schemas = array(
	'classic-editor'        => array( $mul( 'post_types', array( 'post', 'page' ) ), $tog( 'allow_users' ), $sel( 'default_editor', array( 'classic', 'block' ), 'classic' ) ),
	'duplicate-post'        => array(
		$mul( 'post_types', array( 'post', 'page' ), array( 'post', 'page' ) ),
		$tog( 'post_redirect', true ),
		$sel( 'post_status', array( 'draft', 'pending', 'private', 'publish' ), 'draft' ),
		$mul( 'duplicate_elements', array( 'post_content', 'post_excerpt', 'featured_image', 'taxonomies', 'meta_fields', 'menu_order', 'post_parent' ) ),
		$tog( 'custom_title' ),
		$txt( 'custom_title_format', '[Duplicate] {post_title}' ),
		$tog( 'all_post_types' ), $tog( 'keep_status' ), $mul( 'allowed_roles', array( 'editor', 'author', 'subscriber' ) ),
		$area( 'excluded_meta' ), $mul( 'excluded_taxonomies', array( 'category', 'post_tag', 'post_format' ) ),
	),
	'hide-admin-bar'        => array( $mul( 'roles', $all_roles ) ),
	'svg-upload'            => array( $mul( 'allowed_roles', $all_roles, array( 'administrator' ) ) ),
	'disable-auto-updates'  => array(
		$tog( 'disable_core_updates', true ), $sel( 'core_update_type', array( 'all', 'major', 'minor' ), 'all' ),
		$tog( 'disable_plugin_updates', true ), $sel( 'plugin_update_type', array( 'all', 'specific' ), 'all' ),
		$tog( 'disable_theme_updates', true ), $sel( 'theme_update_type', array( 'all', 'specific' ), 'all' ),
		$tog( 'disable_translation_updates', true ), $tog( 'allow_security_core' ), $txt( 'updates_visible_to' ),
		$tog( 'hide_notices_core' ), $tog( 'hide_notices_plugins' ), $tog( 'hide_notices_themes' ),
		$tog( 'disable_checks_core' ), $tog( 'disable_checks_plugins' ), $tog( 'disable_checks_themes' ),
	),
	'auto-image-upload'     => array(
		$txt( 'base_url' ), $txt( 'file_name_pattern', '%filename%' ), $txt( 'alt_pattern', '%image_alt%' ), $num( 'max_width' ), $num( 'max_height' ),
		$area( 'excluded_domains' ), $mul( 'excluded_post_types', array( 'post', 'page', 'product' ) ), $area( 'custom_fields' ),
	),
	'link-manager'          => array(
		$txt( 'exclude_classes' ), $sel( 'default_target', array( 'new_tab', 'keep' ), 'new_tab' ), $txt( 'default_rel', 'noopener noreferrer nofollow' ),
		$sel( 'icon_type', array( 'arrow', 'dashicon', 'none' ), 'arrow' ), $sel( 'icon_dashicon', array( 'dashicons-external', 'dashicons-admin-links' ), 'dashicons-external' ),
		$sel( 'icon_position', array( 'right', 'left' ), 'right' ), $tog( 'icon_skip_images' ), $area( 'link_rules' ), $area( 'internal_domains' ), $tog( 'subdomains_internal' ),
	),
	'security-optimization' => array(
		$tog( 'disable_comments' ), $sel( 'disable_comments_scope', array( 'all', 'selected' ), 'all' ),
		$mul( 'disable_comments_post_types', array( 'post', 'page' ) ), $tog( 'disable_comments_hide_existing', true ),
		$tog( 'disable_comments_admin_ui', true ), $tog( 'disable_comments_block_endpoints', true ),
		$mul( 'disable_comments_allowed_roles', array_merge( $all_roles, array( 'logged-out-users' ) ) ), $mul( 'disable_comments_allowed_types', array( 'pingback', 'trackback' ) ),
		$mul( 'comments_blocked_types', array( 'pingback', 'trackback', 'review' ) ), $num( 'comments_close_after_days', 0 ),
		$tog( 'limit_login_attempts' ), $num( 'max_attempts', 3 ), $num( 'lockout_time', 30 ), $tog( 'notify_admin' ),
		$txt( 'lockout_notify_email' ), $tog( 'lockout_progressive' ), $num( 'lockout_multiplier', 2 ), $num( 'lockout_max_hours', 24 ),
		$tog( 'ip_firewall_enabled' ), $area( 'ip_firewall_allowlist' ),
		$tog( 'login_url_enabled' ), $txt( 'login_url' ), $sel( 'disabled_behavior', array( '404', 'home', 'url_redirect', 'existing_page', 'message' ), '404' ), $txt( 'url_redirect' ),
		$tog( 'captcha_enabled' ), $sel( 'captcha_provider', array( 'turnstile', 'recaptcha_v2', 'recaptcha_v3' ), 'turnstile' ),
		$sel( 'captcha_placement', array( 'inline', 'gate' ), 'gate' ), $sel( 'captcha_mode', array( 'always', 'adaptive' ), 'always' ),
		$mul( 'captcha_forms', array( 'login', 'register', 'lostpassword', 'comment', 'woo_login', 'woo_register', 'woo_lostpassword', 'woo_checkout' ), array( 'login', 'register', 'lostpassword' ) ),
		$sel( 'captcha_theme', array( 'auto', 'light', 'dark' ), 'auto' ), $sel( 'captcha_size', array( 'normal', 'compact', 'flexible' ), 'normal' ),
		$txt( 'captcha_site_key' ), $sel( 'captcha_key_source', array( 'manual', 'ca', 'self_api' ), 'manual' ),
		$tog( 'disable_xmlrpc', true ), $tog( 'disable_emojis' ), $tog( 'disable_rss_feeds' ),
	),
);
SettingsWriter::set_schema_resolver( static fn( string $m ) => $schemas[ $m ] ?? null );

/** Reset the fake site. */
$site = static function ( array $plugins = array(), array $active = array(), array $options = array() ): void {
	$GLOBALS['uxs_options'] = array_merge(
		array(
			'active_plugins'               => array_map( static fn( $s ) => "$s/$s.php", $active ),
			'uxstudio_active_modules'      => array( 'activity-log' ),
			'uxstudio_module_db_versions'  => array( 'core_migration' => 1 ), // table "exists": MigrationMap::ensure_table() is a no-op
		),
		$options
	);
	$GLOBALS['uxs_plugins'] = array();
	foreach ( $plugins as $slug ) {
		$GLOBALS['uxs_plugins']["$slug/$slug.php"] = array( 'Name' => "Plugin $slug", 'Version' => '1.0' );
	}
	$GLOBALS['wpdb']->map = array();
	PluginInfo::flush();
};

$snapshot_options = static fn(): array => $GLOBALS['uxs_options'];

/** Run a source: plan, dry run, real run, second real run. Returns the pieces. */
$exercise = static function ( string $slug ) use ( $snapshot_options ): array {
	$before = $snapshot_options();
	$plan   = SourceRegistry::get( $slug )->plan();
	$dry    = SourceRegistry::get( $slug )->migrate( array( 'run_id' => 'rtest' ), true );
	$after_dry = $snapshot_options();
	$first  = SourceRegistry::get( $slug )->migrate( array( 'run_id' => 'rtest' ), false );
	$state1 = $snapshot_options();
	$second = SourceRegistry::get( $slug )->migrate( array( 'run_id' => 'rtest' ), false );
	$state2 = $snapshot_options();
	$verify = SourceRegistry::get( $slug )->verify();
	return compact( 'before', 'plan', 'dry', 'after_dry', 'first', 'state1', 'second', 'state2', 'verify' );
};

$opt = static fn( string $name ) => $GLOBALS['uxs_options'][ $name ] ?? null;

/** Shared checks for one source run. */
$common = static function ( string $label, array $r, bool $writes = true ) use ( $assert ): void {
	$assert( "$label: dry run writes nothing", $r['before'] === $r['after_dry'], 'options changed in dry run' );
	$assert( "$label: second run is idempotent (same options)", $r['state1'] === $r['state2'] );
	$assert( "$label: second run writes 0", 0 === $r['second']['written'], (string) $r['second']['written'] );
	if ( $writes ) {
		$assert( "$label: first run writes something", $r['first']['written'] > 0, (string) $r['first']['written'] );
		$assert( "$label: dry run reports what the first run writes", $r['dry']['written'] === $r['first']['written'], $r['dry']['written'] . ' vs ' . $r['first']['written'] );
	}
	$bad = array_filter( $r['verify'], static fn( $c ) => ! $c['ok'] );
	$assert( "$label: verify is all ok", array() === $bad, json_encode( $bad ) );
};

/* ── Registry vs Catalog ── */

$classes = SourceRegistry::classes();
$notcat  = array();
$nomodule = array();
foreach ( $classes as $slug => $class ) {
	$entry = Catalog::entry_for_slug( (string) $slug );
	if ( ! $entry ) {
		$notcat[] = $slug;
		continue;
	}
	$source = SourceRegistry::get( (string) $slug );
	if ( $source->target_module() !== $entry['module'] ) {
		$nomodule[] = $slug;
	}
	if ( Catalog::ANY_MODULE !== $entry['module'] && ! is_readable( $root . '/includes/Modules/' . str_replace( ' ', '', ucwords( str_replace( '-', ' ', $entry['module'] ) ) ) . '/meta.json' ) ) {
		$nomodule[] = $slug . '(no module folder)';
	}
}
$assert( 'every source slug is in the Catalog', array() === $notcat, implode( ',', $notcat ) );
$assert( 'every source targets the Catalog module and an existing module folder', array() === $nomodule, implode( ',', $nomodule ) );
$assert( 'all F2 plugins from the brief are registered', array() === array_diff(
	array( 'classic-editor', 'classic-widgets', 'duplicate-post', 'duplicate-page', 'post-duplicator', 'duplicate-menu', 'disable-comments', 'hide-admin-bar', 'safe-svg', 'svg-support', 'all-404-redirect-to-homepage', 'redirect-404-to-homepage', 'disable-wordpress-updates', 'stops-core-theme-and-plugin-updates', 'limit-login-attempts-reloaded', 'wps-hide-login', 'loginizer', 'simple-cloudflare-turnstile', 'advanced-google-recaptcha', 'disable-xml-rpc', 'disable-xml-rpc-api', 'auto-upload-images', 'wp-external-links', 'enable-media-replace', 'post-type-switcher', 'user-switching', 'wp-rollback', 'instant-images', 'reveal-ids-for-wp-admin-25', 'show-ids', 'simple-page-ordering', 'wpextended' ),
	array_keys( $classes )
) );

/* Every key a mapping writes must exist in the real module code (typo guard). */
$key_in_module = static function ( string $module, string $key ) use ( $root ): bool {
	$dir  = $root . '/includes/Modules/' . str_replace( ' ', '', ucwords( str_replace( '-', ' ', $module ) ) ) . '/Module.php';
	$code = is_readable( $dir ) ? (string) file_get_contents( $dir ) : '';
	return 1 === preg_match( "/'key'\s*=>\s*'" . preg_quote( $key, '/' ) . "'/", $code );
};
$seen_keys = array();

/* ── Fixtures: slug => [installed, active, options, expected options after migrate] ── */

$cases = array(
	'classic-editor / classic'     => array(
		'classic-editor', true, array( 'classic-editor-replace' => 'classic', 'classic-editor-allow-users' => 'disallow' ),
		array( 'uxstudio_classic_editor' => array( 'post_types' => array(), 'default_editor' => 'classic', 'allow_users' => false ) ),
	),
	'classic-editor / users choose, classic default' => array(
		'classic-editor', true, array( 'classic-editor-replace' => 'classic', 'classic-editor-allow-users' => 'allow' ),
		array( 'uxstudio_classic_editor' => array( 'post_types' => array(), 'default_editor' => 'classic', 'allow_users' => true ) ),
	),
	'classic-editor / users choose, block default' => array(
		'classic-editor', true, array( 'classic-editor-replace' => 'block', 'classic-editor-allow-users' => 'allow' ),
		array( 'uxstudio_classic_editor' => array( 'default_editor' => 'block', 'allow_users' => true ) ),
	),
	'duplicate-page'               => array(
		'duplicate-page', false,
		array( 'duplicate_page_options' => array( 'duplicate_post_status' => 'private', 'duplicate_post_redirect' => 'to_page', 'duplicate_post_suffix' => 'copy', 'duplicate_post_editor' => 'classic' ) ),
		array( 'uxstudio_duplicate_post' => array( 'post_status' => 'private', 'post_redirect' => true, 'custom_title' => true, 'custom_title_format' => '{post_title} -- copy', 'all_post_types' => true ) ),
	),
	'duplicate-post (Yoast)'       => array(
		'duplicate-post', true,
		array(
			'duplicate_post_types_enabled' => array( 'post', 'page' ), 'duplicate_post_copytitle' => 1, 'duplicate_post_copycontent' => 1,
			'duplicate_post_copyexcerpt' => 0, 'duplicate_post_copythumbnail' => 1, 'duplicate_post_copymenuorder' => 0,
			'duplicate_post_title_prefix' => 'Copy of', 'duplicate_post_title_suffix' => '(dup)', 'duplicate_post_copydate' => 1,
			'duplicate_post_copystatus' => 0, 'duplicate_post_copytemplate' => 0, 'duplicate_post_copyformat' => 0,
			'duplicate_post_blacklist' => '_secret, _cache_*', 'duplicate_post_taxonomies_blacklist' => array( 'post_tag' ),
		),
		array( 'uxstudio_duplicate_post' => array(
			'post_types' => array( 'post', 'page' ),
			'duplicate_elements' => array( 'post_content', 'featured_image', 'taxonomies', 'meta_fields', 'post_parent' ),
			'custom_title' => true, 'custom_title_format' => 'Copy of {post_title} (dup)',
			'keep_status' => false, 'post_status' => 'draft', 'excluded_meta' => "_secret\n_cache_*\n_wp_page_template", 'excluded_taxonomies' => array( 'post_tag', 'post_format' ),
			'allowed_roles' => array( 'editor' ),
		) ),
	),
	'post-duplicator'              => array(
		'post-duplicator', true,
		array( 'mtphr_post_duplicator_settings' => array( 'status' => 'publish', 'title' => 'Copy', 'single_after_duplication_action' => 'new_tab', 'excluded_meta_keys' => "_a\n_b", 'post_types_config' => array( 'post' => array( 'allow_duplication' => true ), 'page' => array( 'allow_duplication' => false ) ) ) ),
		array( 'uxstudio_duplicate_post' => array( 'keep_status' => false, 'post_status' => 'publish', 'custom_title' => true, 'custom_title_format' => '{post_title} Copy', 'post_redirect' => true, 'post_types' => array( 'post' ), 'all_post_types' => false, 'excluded_meta' => "_a\n_b", 'allowed_roles' => array( 'author' ) ) ),
	),
	'post-duplicator / same status, all types' => array(
		'post-duplicator', true,
		array( 'mtphr_post_duplicator_settings' => array( 'status' => 'same', 'post_types_config' => array( 'post' => array( 'allow_duplication' => true ), 'page' => array( 'allow_duplication' => true ) ) ) ),
		array( 'uxstudio_duplicate_post' => array( 'keep_status' => true, 'all_post_types' => true, 'post_types' => array( 'post', 'page' ), 'allowed_roles' => array( 'author' ) ) ),
	),
	'disable-comments / selected'  => array(
		'disable-comments', true,
		array( 'disable_comments_options' => array( 'remove_everywhere' => false, 'disabled_post_types' => array( 'post', 'page' ), 'show_existing_comments' => false ) ),
		array( 'uxstudio_security_optimization' => array( 'disable_comments' => true, 'disable_comments_scope' => 'selected', 'disable_comments_post_types' => array( 'post', 'page' ), 'disable_comments_hide_existing' => true ) ),
	),
	'disable-comments / everywhere' => array(
		'disable-comments', true,
		array( 'disable_comments_options' => array( 'remove_everywhere' => true, 'show_existing_comments' => true, 'remove_xmlrpc_comments' => 1 ) ),
		array( 'uxstudio_security_optimization' => array( 'disable_comments' => true, 'disable_comments_scope' => 'all', 'disable_comments_hide_existing' => false, 'disable_comments_admin_ui' => true, 'disable_comments_block_endpoints' => true ) ),
	),
	'disable-comments / exceptions' => array(
		'disable-comments', true,
		array( 'disable_comments_options' => array(
			'remove_everywhere' => true, 'show_existing_comments' => false,
			'enable_exclude_by_role' => '1', 'exclude_by_role' => array( 'editor', 'logged-out-users', 'shop_manager' ),
			'allowed_comment_types' => array( 'pingback', 'note' ), 'blocked_comment_types' => array( 'review', 'trackback' ),
			'enable_conditional_rules' => '1', 'auto_close_days' => 45,
		) ),
		array( 'uxstudio_security_optimization' => array(
			'disable_comments' => true, 'disable_comments_scope' => 'all', 'disable_comments_hide_existing' => true,
			'disable_comments_allowed_roles' => array( 'editor', 'logged-out-users' ), 'disable_comments_allowed_types' => array( 'pingback' ),
			'comments_blocked_types' => array( 'trackback', 'review' ), 'comments_close_after_days' => 45,
		) ),
	),
	'disable-comments / old posts only' => array(
		'disable-comments', true,
		array( 'disable_comments_options' => array(
			'remove_everywhere' => false, 'disabled_post_types' => array(),
			'enable_conditional_rules' => '1', 'auto_close_days' => 30, 'enable_exclude_by_role' => '1', 'exclude_by_role' => array( 'administrator' ),
		) ),
		array( 'uxstudio_security_optimization' => array(
			'comments_close_after_days' => 30, 'disable_comments_allowed_roles' => array( 'administrator' ),
		) ),
	),
	'hide-admin-bar / roles'       => array(
		'hide-admin-bar', true, array( 'hab_admin_bar_settings' => array( 'remove_by_roles' => array( 'subscriber', 'author' ) ) ),
		array( 'uxstudio_hide_admin_bar' => array( 'roles' => array( 'subscriber', 'author' ) ) ),
	),
	'hide-admin-bar / default all' => array(
		'hide-admin-bar', true, array(),
		array( 'uxstudio_hide_admin_bar' => array( 'roles' => array( 'administrator', 'editor', 'author', 'subscriber' ) ) ),
	),
	'safe-svg'                     => array(
		'safe-svg', true, array( 'safe_svg_upload_roles' => array( 'editor' ) ),
		array( 'uxstudio_svg_upload' => array( 'allowed_roles' => array( 'editor' ) ) ),
	),
	'safe-svg / empty = upload_files' => array(
		'safe-svg', true, array( 'safe_svg_upload_roles' => array() ),
		array( 'uxstudio_svg_upload' => array( 'allowed_roles' => array( 'administrator', 'editor', 'author' ) ) ),
	),
	'svg-support'                  => array(
		'svg-support', true, array( 'bodhi_svgs_settings' => array( 'restrict' => array( 'administrator', 'editor' ), 'css_target' => 'x' ) ),
		array( 'uxstudio_svg_upload' => array( 'allowed_roles' => array( 'administrator', 'editor' ) ) ),
	),
	'disable-wordpress-updates'    => array(
		'disable-wordpress-updates', true, array(),
		array( 'uxstudio_disable_auto_updates' => array( 'disable_core_updates' => true, 'core_update_type' => 'all', 'disable_plugin_updates' => true, 'disable_theme_updates' => true, 'disable_translation_updates' => true, 'hide_notices_core' => true, 'disable_checks_core' => true, 'hide_notices_plugins' => true, 'disable_checks_plugins' => true, 'hide_notices_themes' => true, 'disable_checks_themes' => true ) ),
	),
	'disable-wordpress-updates / Security Mode' => array(
		'disable-wordpress-updates', true, array( 'osdwp_security_mode' => 1 ),
		array( 'uxstudio_disable_auto_updates' => array( 'allow_security_core' => true, 'hide_notices_plugins' => true, 'disable_checks_plugins' => true, 'hide_notices_themes' => true, 'disable_checks_themes' => true ) ),
	),
	'stops-core (Easy Updates) off' => array(
		'stops-core-theme-and-plugin-updates', false,
		array( 'MPSUM' => array( 'core' => array( 'all_updates' => 'off' ) ) ),
		array( 'uxstudio_disable_auto_updates' => array( 'disable_core_updates' => true, 'core_update_type' => 'all', 'disable_plugin_updates' => true, 'disable_theme_updates' => true, 'disable_translation_updates' => true, 'hide_notices_core' => true, 'disable_checks_core' => true, 'hide_notices_plugins' => true, 'disable_checks_plugins' => true, 'hide_notices_themes' => true, 'disable_checks_themes' => true ) ),
	),
	'stops-core (Easy Updates) plugins off only' => array(
		'stops-core-theme-and-plugin-updates', true,
		array( 'MPSUM' => array( 'core' => array( 'all_updates' => 'on', 'core_updates' => 'on', 'plugin_updates' => 'off', 'theme_updates' => 'on', 'translation_updates' => 'on' ) ) ),
		array( 'uxstudio_disable_auto_updates' => array( 'hide_notices_plugins' => true, 'disable_checks_plugins' => true ) ),
	),
	'stops-core (Easy Updates) mixed' => array(
		'stops-core-theme-and-plugin-updates', true,
		array( 'MPSUM' => array( 'core' => array( 'all_updates' => 'on', 'core_updates' => 'automatic_minor', 'plugin_updates' => 'automatic', 'theme_updates' => 'on', 'translation_updates' => 'automatic' ) ) ),
		array( 'uxstudio_disable_auto_updates' => array( 'disable_core_updates' => true, 'core_update_type' => 'major', 'disable_plugin_updates' => false, 'disable_theme_updates' => true, 'disable_translation_updates' => false ) ),
	),
	'limit-login-attempts-reloaded' => array(
		'limit-login-attempts-reloaded', true,
		array( 'limit_login_allowed_retries' => 5, 'limit_login_lockout_duration' => 3600, 'limit_login_lockout_notify' => 'log,email', 'limit_login_allowed_lockouts' => 2, 'limit_login_long_duration' => 14400, 'admin_notify_email' => 'ops@example.test', 'limit_login_whitelist' => array( '1.2.3.4', '10.0.0.1-10.0.0.3' ) ),
		array( 'uxstudio_security_optimization' => array( 'limit_login_attempts' => true, 'max_attempts' => 5, 'lockout_time' => 60, 'notify_admin' => true, 'lockout_notify_email' => 'ops@example.test', 'lockout_progressive' => true, 'lockout_multiplier' => 2.0, 'lockout_max_hours' => 4, 'ip_firewall_allowlist' => "1.2.3.4\n10.0.0.1\n10.0.0.2/31" ) ),
	),
	'wps-hide-login'               => array(
		'wps-hide-login', true, array( 'whl_page' => 'administrace', 'whl_redirect_admin' => 'neexistuje' ),
		array( 'uxstudio_security_optimization' => array( 'login_url_enabled' => true, 'login_url' => 'administrace', 'disabled_behavior' => 'url_redirect', 'url_redirect' => 'neexistuje' ) ),
	),
	'wps-hide-login / defaults'    => array(
		'wps-hide-login', true, array(),
		array( 'uxstudio_security_optimization' => array( 'login_url_enabled' => true, 'login_url' => 'login', 'disabled_behavior' => '404' ) ),
	),
	'loginizer'                    => array(
		'loginizer', true, array( 'loginizer_options' => array( 'max_retries' => 4, 'lockout_time' => 600, 'notify_email' => 1, 'notify_email_address' => 'ops@example.test', 'max_lockouts' => 3, 'lockouts_extend' => 7200 ), 'loginizer_whitelist' => array( 1 => array( 'start' => '5.5.5.5', 'end' => '5.5.5.5', 'time' => 1 ), 2 => array( 'start' => '192.168.1.0', 'end' => '192.168.1.255', 'time' => 1 ) ) ),
		array( 'uxstudio_security_optimization' => array( 'limit_login_attempts' => true, 'max_attempts' => 4, 'lockout_time' => 10, 'notify_admin' => true, 'lockout_notify_email' => 'ops@example.test', 'lockout_progressive' => true, 'lockout_multiplier' => 2.3, 'lockout_max_hours' => 2, 'ip_firewall_allowlist' => "5.5.5.5\n192.168.1.0/24" ) ),
	),
	'simple-cloudflare-turnstile'  => array(
		'simple-cloudflare-turnstile', true,
		array( 'cfturnstile_key' => 'SITEKEY', 'cfturnstile_secret' => 'SECRETKEY', 'cfturnstile_login' => 'on', 'cfturnstile_reset' => 'on', 'cfturnstile_comment' => 'on', 'cfturnstile_woo_login' => 'on', 'cfturnstile_woo_register' => 'on', 'cfturnstile_woo_reset' => 'on', 'cfturnstile_woo_checkout' => 'on', 'cfturnstile_theme' => 'dark', 'cfturnstile_size' => 'flexible' ),
		array( 'uxstudio_security_optimization' => array( 'captcha_provider' => 'turnstile', 'captcha_site_key' => 'SITEKEY', 'captcha_placement' => 'inline', 'captcha_forms' => array( 'login', 'lostpassword', 'comment', 'woo_login', 'woo_register', 'woo_lostpassword', 'woo_checkout' ), 'captcha_enabled' => true, 'captcha_key_source' => 'manual', 'captcha_theme' => 'dark', 'captcha_size' => 'flexible' ) ),
	),
	'advanced-google-recaptcha'    => array(
		'advanced-google-recaptcha', true,
		array( 'wpcaptcha_options' => array( 'captcha' => 'recaptchav2', 'captcha_site_key' => 'SK', 'captcha_secret_key' => 'SS', 'captcha_show_login' => 1, 'captcha_show_wp_registration' => 0, 'captcha_show_wp_lost_password' => 1, 'captcha_show_wp_comment' => 1, 'captcha_show_woo_checkout' => 1, 'captcha_theme' => 'dark', 'captcha_size' => 'compact' ) ),
		array( 'uxstudio_security_optimization' => array( 'captcha_provider' => 'recaptcha_v2', 'captcha_site_key' => 'SK', 'captcha_forms' => array( 'login', 'lostpassword', 'comment', 'woo_checkout' ), 'captcha_enabled' => true, 'captcha_theme' => 'dark', 'captcha_size' => 'compact' ) ),
	),
	'disable-xml-rpc'              => array(
		'disable-xml-rpc', true, array(),
		array( 'uxstudio_security_optimization' => array( 'disable_xmlrpc' => true ) ),
	),
	'disable-xml-rpc-api'          => array(
		'disable-xml-rpc-api', true, array( 'dsxmlrpc-settings' => array( 'remove-emojis' => 1, 'remove-rss' => 1 ) ),
		array( 'uxstudio_security_optimization' => array( 'disable_xmlrpc' => true, 'disable_emojis' => true, 'disable_rss_feeds' => true ) ),
	),
	'wp-external-links'            => array(
		'wp-external-links', true,
		array( 'wpel-external-link-settings' => array( 'icon_type' => '', 'target' => '_blank' ), 'wpel-exceptions-settings' => array( 'ignore_classes' => 'no-ext, skip' ) ),
		array( 'uxstudio_link_manager' => array( 'icon_type' => 'none', 'default_target' => 'new_tab', 'default_rel' => 'noopener noreferrer external', 'exclude_classes' => 'no-ext, skip' ) ),
	),
	'wp-external-links / rules'    => array(
		'wp-external-links', true,
		array(
			'wpel-external-link-settings' => array( 'apply_settings' => '1', 'target' => '_self', 'rel_follow' => 'nofollow', 'rel_noopener' => '1', 'rel_noreferrer' => '', 'rel_external' => '', 'rel_sponsored' => '1', 'icon_type' => 'dashicon', 'icon_dashicon' => 'dashicons-external', 'icon_position' => 'left', 'no_icon_for_img' => '1' ),
			'wpel-exceptions-settings'    => array( 'subdomains_as_internal_links' => '1', 'exclude_urls' => "shop.example.com\npartner.example/go/" ),
		),
		array( 'uxstudio_link_manager' => array( 'default_target' => 'keep', 'default_rel' => 'noopener nofollow sponsored', 'icon_type' => 'dashicon', 'icon_dashicon' => 'dashicons-external', 'icon_position' => 'left', 'icon_skip_images' => true, 'subdomains_internal' => true, 'internal_domains' => "*shop.example.com*\n*partner.example/go/*" ) ),
	),
	'wp-external-links / switched off' => array(
		'wp-external-links', true,
		array( 'wpel-external-link-settings' => array( 'apply_settings' => '' ) ),
		array( 'uxstudio_link_manager' => array( 'default_target' => 'keep', 'default_rel' => '', 'icon_type' => 'none' ) ),
	),
);

foreach ( $cases as $name => [ $slug, $active, $options, $expected ] ) {
	$site( array( $slug ), $active ? array( $slug ) : array(), $options );
	$r = $exercise( $slug );
	$common( $name, $r );
	foreach ( $expected as $option_name => $values ) {
		$stored = $GLOBALS['uxs_options'][ $option_name ] ?? array();
		$bad    = array();
		foreach ( $values as $k => $v ) {
			if ( ! array_key_exists( $k, $stored ) || ! Mapping::same( $stored[ $k ], $v ) ) {
				$bad[] = $k . ' = ' . json_encode( $stored[ $k ] ?? null ) . ' (expected ' . json_encode( $v ) . ')';
			}
			$seen_keys[ $option_name . '.' . $k ] = array( $option_name, $k );
		}
		$assert( "$name: $option_name values", array() === $bad, implode( '; ', $bad ) );
	}
}

/* Secrets go through store_secret and stay encrypted at rest. */
$site( array( 'simple-cloudflare-turnstile' ), array( 'simple-cloudflare-turnstile' ), array( 'cfturnstile_key' => 'K', 'cfturnstile_secret' => 'S3CRET', 'cfturnstile_login' => 'on' ) );
SourceRegistry::get( 'simple-cloudflare-turnstile' )->migrate( array( 'run_id' => 'rsec' ), false );
$raw = $opt( 'uxstudio_secret_security_optimization_captcha_secret_key' );
$assert( 'secret is stored encrypted (uxs1:) and decrypts to the source value', is_string( $raw ) && str_starts_with( $raw, 'uxs1:' ) && 'S3CRET' === Security::get_secret( 'uxstudio_secret_security_optimization_captcha_secret_key' ) );
$plan = SourceRegistry::get( 'simple-cloudflare-turnstile' )->plan();
$assert( 'plan never shows the secret value', false === strpos( json_encode( $plan ), 'S3CRET' ) );
$assert( 'secret option name matches the module code', 1 === preg_match( '/SECRET_CAPTCHA\s*=\s*\'uxstudio_secret_security_optimization_captcha_secret_key\'/', (string) file_get_contents( $root . '/includes/Modules/SecurityOptimization/Module.php' ) ) );

/* ── A1: lockout, notification e-mail, IP lists, CAPTCHA forms and look (PLAN.md 27) ── */

$lossy_of = static fn( string $slug ): string => implode( ' | ', SourceRegistry::get( $slug )->plan()['lossy'] );
$notes_of = static fn( string $slug ): string => implode( ' | ', SourceRegistry::get( $slug )->plan()['notes'] );

$site( array( 'limit-login-attempts-reloaded' ), array( 'limit-login-attempts-reloaded' ), array( 'limit_login_allowed_retries' => 5, 'limit_login_whitelist' => array( '1.2.3.4' ), 'limit_login_whitelist_usernames' => array( 'jan' ) ) );
$assert( 'llar: progressive lockout is transferred, no longer listed as lost', false === stripos( $lossy_of( 'limit-login-attempts-reloaded' ), 'longer lockouts' ) && false !== stripos( $notes_of( 'limit-login-attempts-reloaded' ), 'Repeated lockouts' ), $lossy_of( 'limit-login-attempts-reloaded' ) );
$assert( 'llar: the IP list is no longer lost, only the user name lists are', false === stripos( $lossy_of( 'limit-login-attempts-reloaded' ), 'lists of allowed and blocked IP' ) && false !== stripos( $lossy_of( 'limit-login-attempts-reloaded' ), 'user names' ), $lossy_of( 'limit-login-attempts-reloaded' ) );
// LLAR defaults when the plugin never stored the values: 20 min lockout, 4 lockouts -> 24 h.
$p = SourceRegistry::get( 'limit-login-attempts-reloaded' )->plan();
$rows = array_column( $p['transfer'], 'to', 'key' );
$assert( 'llar: plugin defaults give a progressive lockout of 2.9 x up to 24 h', '2.9' === ( $rows['lockout_multiplier'] ?? '' ) && '24' === ( $rows['lockout_max_hours'] ?? '' ), json_encode( $rows ) );

$site( array( 'limit-login-attempts-reloaded' ), array( 'limit-login-attempts-reloaded' ), array( 'limit_login_allowed_retries' => 3, 'limit_login_allowed_lockouts' => 4, 'limit_login_lockout_duration' => 1200, 'limit_login_long_duration' => 1200, 'admin_notify_email' => 'not-an-email' ) );
$p = SourceRegistry::get( 'limit-login-attempts-reloaded' )->plan();
$keys = array_column( $p['transfer'], 'key' );
$assert( 'llar: no long lockout configured => no progressive keys', ! in_array( 'lockout_progressive', $keys, true ) && ! in_array( 'lockout_multiplier', $keys, true ) );
$assert( 'llar: an invalid notification address is not transferred and says so', ! in_array( 'lockout_notify_email', $keys, true ) && false !== stripos( $lossy_of( 'limit-login-attempts-reloaded' ), 'e-mail address for lockout notices' ), $lossy_of( 'limit-login-attempts-reloaded' ) );

// The allow list is merged with what the module already has (and with what an earlier run prepared), without duplicates.
$site( array( 'limit-login-attempts-reloaded' ), array( 'limit-login-attempts-reloaded' ), array( 'limit_login_whitelist' => array( '1.2.3.4', '9.9.9.9' ), 'uxstudio_security_optimization' => array( 'ip_firewall_allowlist' => "9.9.9.9\n8.8.8.8" ) ) );
SourceRegistry::get( 'limit-login-attempts-reloaded' )->migrate( array( 'run_id' => 'rallow' ), false );
$assert( 'allow list: existing lines stay first, new ones are added once', "9.9.9.9\n8.8.8.8\n1.2.3.4" === ( $opt( 'uxstudio_security_optimization' )['ip_firewall_allowlist'] ?? null ), json_encode( $opt( 'uxstudio_security_optimization' ) ) );
SourceRegistry::get( 'limit-login-attempts-reloaded' )->migrate( array( 'run_id' => 'rallow2' ), false );
$assert( 'allow list: a second run changes nothing', "9.9.9.9\n8.8.8.8\n1.2.3.4" === ( $opt( 'uxstudio_security_optimization' )['ip_firewall_allowlist'] ?? null ) );

// Entries the Firewall cannot take are reported, not silently dropped.
$site( array( 'limit-login-attempts-reloaded' ), array( 'limit-login-attempts-reloaded' ), array( 'limit_login_whitelist' => array( '1.2.3.4', 'not-an-ip', '0.0.0.0/0' ) ) );
$assert( 'llar: unusable list entries (invalid, catch-all) are reported', false !== stripos( $lossy_of( 'limit-login-attempts-reloaded' ), '2 IP list entries could not be transferred' ) && false !== strpos( $lossy_of( 'limit-login-attempts-reloaded' ), 'not-an-ip' ), $lossy_of( 'limit-login-attempts-reloaded' ) );

// Blocked addresses: Firewall switched on with them, shown as data of the plan.
$site( array( 'loginizer' ), array( 'loginizer' ), array( 'loginizer_options' => array( 'max_retries' => 3 ), 'loginizer_blacklist' => array( 1 => array( 'start' => '203.0.113.5', 'end' => '203.0.113.5' ), 2 => array( 'start' => '198.51.100.0', 'end' => '198.51.100.255' ) ) ) );
$p = SourceRegistry::get( 'loginizer' )->plan();
$rows = array_column( $p['transfer'], 'to', 'key' );
$assert( 'loginizer: blocked addresses turn the Firewall on and are listed as data (2)', '✓' === ( $rows['ip_firewall_enabled'] ?? '' ) && 2 === $p['counts']['data'] && 'ip_ban' === $p['data'][0]['key'], json_encode( $p['data'] ) );
$assert( 'loginizer: warns that the Firewall starts blocking with the module', false !== stripos( implode( ' ', $p['warnings'] ), 'Firewall' ) );
$assert( 'loginizer: bans and the active flag are in the snapshot', array( 'uxstudio_ip_bans', 'uxstudio_ip_ban_ranges' ) === SourceRegistry::get( 'loginizer' )->target_tables() && in_array( 'uxstudio_ip_bans_active', SourceRegistry::get( 'loginizer' )->target_options(), true ) );
$assert( 'loginizer: two-step verification is reported as not movable, users set it up again', false !== stripos( $lossy_of( 'loginizer' ), 'set it up again' ) && false === stripos( $lossy_of( 'loginizer' ), 'lists of allowed and blocked IP' ), $lossy_of( 'loginizer' ) );
$site( array( 'wps-hide-login' ), array( 'wps-hide-login' ), array( 'whl_page' => 'x' ) );
$assert( 'wps-hide-login: no firewall tables in the snapshot', array() === SourceRegistry::get( 'wps-hide-login' )->target_tables() && 0 === SourceRegistry::get( 'wps-hide-login' )->plan()['counts']['data'] );

// CAPTCHA: comments, WooCommerce and the look are no longer lost; other plugins' forms still are.
$site( array( 'simple-cloudflare-turnstile' ), array( 'simple-cloudflare-turnstile' ), array( 'cfturnstile_key' => 'K', 'cfturnstile_secret' => 'S', 'cfturnstile_comment' => 'on', 'cfturnstile_woo_login' => 'on', 'cfturnstile_theme' => 'light', 'cfturnstile_wpforms' => 'on', 'cfturnstile_cf7' => 'on' ) );
$assert( 'turnstile: comment and WooCommerce forms are transferred, not listed as lost', false === stripos( $lossy_of( 'simple-cloudflare-turnstile' ), 'comment form' ) && false === stripos( $lossy_of( 'simple-cloudflare-turnstile' ), 'WooCommerce' ), $lossy_of( 'simple-cloudflare-turnstile' ) );
$assert( 'turnstile: forms of other plugins are still reported', false !== strpos( $lossy_of( 'simple-cloudflare-turnstile' ), 'Contact Form 7' ) && false !== strpos( $lossy_of( 'simple-cloudflare-turnstile' ), 'WPForms' ), $lossy_of( 'simple-cloudflare-turnstile' ) );
$assert( 'turnstile: the appearance is no longer listed as lost (only language, failsafe, allow lists)', false !== stripos( $lossy_of( 'simple-cloudflare-turnstile' ), 'widget language' ) && false === stripos( $lossy_of( 'simple-cloudflare-turnstile' ), 'theme' ), $lossy_of( 'simple-cloudflare-turnstile' ) );
$site( array( 'simple-cloudflare-turnstile' ), array( 'simple-cloudflare-turnstile' ), array( 'cfturnstile_key' => 'K', 'cfturnstile_secret' => 'S', 'cfturnstile_login' => 'on' ) );
$keys = array_column( SourceRegistry::get( 'simple-cloudflare-turnstile' )->plan()['transfer'], 'key' );
$assert( 'turnstile: no theme/size in the plugin => the module look is left alone', ! in_array( 'captcha_theme', $keys, true ) && ! in_array( 'captcha_size', $keys, true ) );
$site( array( 'advanced-google-recaptcha' ), array( 'advanced-google-recaptcha' ), array( 'wpcaptcha_options' => array( 'captcha' => 'recaptchav2', 'captcha_site_key' => 'A', 'captcha_secret_key' => 'B', 'captcha_show_wp_comment' => 1, 'captcha_show_woo_registration' => 1 ) ) );
$assert( 'recaptcha plugin: comments and WooCommerce registration no longer lost', false === stripos( $lossy_of( 'advanced-google-recaptcha' ), 'comments' ) && false === stripos( $lossy_of( 'advanced-google-recaptcha' ), 'WooCommerce' ), $lossy_of( 'advanced-google-recaptcha' ) );

// The real module offers every form id and look value the sources write.
$mod = (string) file_get_contents( $root . '/includes/Modules/SecurityOptimization/Module.php' );
foreach ( array( 'comment', 'woo_login', 'woo_register', 'woo_lostpassword', 'woo_checkout' ) as $form ) {
	$assert( "Module offers the captcha form '$form'", false !== strpos( $mod, "'$form'" ) );
}
foreach ( array( 'captcha_theme', 'captcha_size', 'lockout_progressive', 'lockout_multiplier', 'lockout_max_hours', 'lockout_notify_email', 'ip_firewall_allowlist', 'ip_firewall_enabled' ) as $key ) {
	$assert( "Module has the setting '$key'", false !== strpos( $mod, "'key'     => '$key'" ) );
}

/* IP entries: what the Firewall takes. */
$ip = static fn( string $raw ): array => \UxStudio\Core\Migration\IpEntries::convert( $raw );
$assert( 'ip: single address, CIDR, IPv6', array( '1.2.3.4' ) === $ip( '1.2.3.4' )['entries'] && array( '1.2.3.0/24' ) === $ip( '1.2.3.0/24' )['entries'] && array( '2001:db8::/32' ) === $ip( '2001:db8::/32' )['entries'] && array( '2001:db8::1' ) === $ip( '2001:db8::1' )['entries'] );
$assert( 'ip: start-end range is cut into exact CIDR blocks', array( '1.2.3.1', '1.2.3.2/31', '1.2.3.4/30', '1.2.3.8/29', '1.2.3.16/28', '1.2.3.32/28', '1.2.3.48/31', '1.2.3.50' ) === $ip( '1.2.3.1-1.2.3.50' )['entries'] );
$assert( 'ip: a range that is one address or a whole /24 stays short', array( '1.2.3.4' ) === $ip( '1.2.3.4 - 1.2.3.4' )['entries'] && array( '10.1.2.0/24' ) === $ip( '10.1.2.0-10.1.2.255' )['entries'] );
$assert( 'ip: wildcards', array( '10.1.2.0/24' ) === $ip( '10.1.2.*' )['entries'] && array( '10.1.0.0/16' ) === $ip( '10.1.*.*' )['entries'] );
$assert( 'ip: nonsense, reversed range, too wide, bad prefix are refused', ! $ip( 'bad' )['ok'] && ! $ip( '10.0.0.9-10.0.0.1' )['ok'] && ! $ip( '0.0.0.0/0' )['ok'] && ! $ip( '10.0.0.0/7' )['ok'] && ! $ip( '1.2.3.0/33' )['ok'] && ! $ip( '0.0.0.0-255.255.255.255' )['ok'] );
$assert( 'ip: merge_lines keeps order, drops duplicates and blank lines', "a\nb\nc" === \UxStudio\Core\Migration\IpEntries::merge_lines( "a\n\nb,a", array( 'b', 'c' ) ) );

/* Nothing-to-transfer sources. */
foreach ( array( 'classic-widgets', 'duplicate-menu', 'enable-media-replace', 'post-type-switcher', 'user-switching', 'wp-rollback', 'instant-images', 'reveal-ids-for-wp-admin-25', 'show-ids', 'simple-page-ordering', 'redirect-404-to-homepage' ) as $slug ) {
	$site( array( $slug ), array( $slug ) );
	$r = $exercise( $slug );
	$common( "$slug (no settings)", $r, false );
	$assert( "$slug: plan transfers nothing and says so", 0 === $r['plan']['counts']['transfer'] && array() !== $r['plan']['notes'], json_encode( $r['plan']['counts'] ) );
}
$site( array( 'auto-upload-images' ), array( 'auto-upload-images' ), array( 'aui-setting' => array( 'base_url' => 'https://cdn.example.test/', 'image_name' => '%postname%-%date%', 'alt_name' => '%image_alt% (%day%)', 'max_width' => '1200', 'max_height' => '800', 'exclude_urls' => "a.test\nhttps://b.test", 'exclude_post_types' => array( 'product', 'gone' ), 'custom_fields' => array( 'acf_text', 'extra' ) ) ) );
$r = $exercise( 'auto-upload-images' );
$common( 'auto-upload-images', $r );
$aui = $r['state1']['uxstudio_auto_image_upload'] ?? array();
$assert( 'auto-upload-images: base address, patterns (deprecated %date% / %day% renamed), size, exclusions and fields are transferred', 'https://cdn.example.test/' === ( $aui['base_url'] ?? '' ) && '%postname%-%today_date%' === ( $aui['file_name_pattern'] ?? '' ) && '%image_alt% (%today_day%)' === ( $aui['alt_pattern'] ?? '' ) && 1200 === (int) ( $aui['max_width'] ?? 0 ) && 800 === (int) ( $aui['max_height'] ?? 0 ) && "a.test\nhttps://b.test" === ( $aui['excluded_domains'] ?? '' ) && "acf_text\nextra" === ( $aui['custom_fields'] ?? '' ), json_encode( $aui ) );
$assert( 'auto-upload-images: the plain "resized copy" difference is the only lossy item (plus a type that does not exist here)', 2 === $r['plan']['counts']['lossy'] && false !== strpos( implode( ' ', $r['plan']['lossy'] ), 'gone' ) && false !== strpos( implode( ' ', $r['plan']['lossy'] ), 'COPY' ), json_encode( $r['plan']['lossy'] ) );
$site( array( 'auto-upload-images' ), array( 'auto-upload-images' ), array( 'aui-setting' => array( 'base_url' => 'https://www.example.test', 'image_name' => '%filename%', 'alt_name' => '%image_alt%' ) ) );
$r2 = $exercise( 'auto-upload-images' );
$assert( 'auto-upload-images: the plugin default (its own site address) means "no replacement"', '' === ( $r2['state1']['uxstudio_auto_image_upload']['base_url'] ?? 'x' ), json_encode( $r2['state1']['uxstudio_auto_image_upload'] ?? null ) );

$site( array( 'all-404-redirect-to-homepage' ), array( 'all-404-redirect-to-homepage' ), array( 'options-404-redirect-group' => array( 'p404_status' => '2', 'p404_redirect_to' => 'https://elsewhere.test/' ) ) );
$p = SourceRegistry::get( 'all-404-redirect-to-homepage' )->plan();
$assert( 'all-404: warns when switched off, lossy when a custom target is used', 1 === count( $p['warnings'] ) && count( $p['lossy'] ) >= 2, json_encode( $p ) );

/* classic-editor on the block editor with users NOT choosing transfers nothing and notes it. */
$site( array( 'classic-editor' ), array( 'classic-editor' ), array( 'classic-editor-replace' => 'block', 'classic-editor-allow-users' => 'disallow' ) );
$r = $exercise( 'classic-editor' );
$common( 'classic-editor / block default', $r, false );
$assert( 'classic-editor / block default: nothing written, only a note', 0 === $r['plan']['counts']['transfer'] && null === $opt( 'uxstudio_classic_editor' ) );

/* ... but the same default with users choosing becomes the site default editor + the switch (B3). */
$site( array( 'classic-editor' ), array( 'classic-editor' ), array( 'classic-editor-replace' => 'no-replace', 'classic-editor-allow-users' => 'allow' ) );
$r = $exercise( 'classic-editor' );
$common( 'classic-editor / block default, users choose', $r );
$assert( 'classic-editor / block default, users choose: nothing about the choice is lossy, the stored keys are named in a note', 0 === $r['plan']['counts']['lossy'] && array( 'default_editor' => 'block', 'allow_users' => true ) == $opt( 'uxstudio_classic_editor' ) && false !== strpos( json_encode( $r['plan']['notes'] ), 'classic-editor-remember' ), json_encode( $opt( 'uxstudio_classic_editor' ) ) );
$assert( 'classic-editor / users choose: no smoke test of a single editor', array() === SourceRegistry::get( 'classic-editor' )->smoke_checks() );

/* disable-comments exceptions: what is transferred and what is named as lost. */
$site( array( 'disable-comments' ), array( 'disable-comments' ), array( 'disable_comments_options' => array(
	'remove_everywhere' => true, 'show_existing_comments' => false,
	'enable_exclude_by_role' => '1', 'exclude_by_role' => array( 'editor', 'logged-out-users', 'shop_manager' ),
	'allowed_comment_types' => array( 'pingback', 'note' ), 'blocked_comment_types' => array( 'review', 'trackback' ),
	'enable_conditional_rules' => '1', 'auto_close_days' => 45, 'conditional_rules' => array( array( 'type' => 'taxonomy', 'taxonomy' => 'category', 'terms' => array( 3 ), 'action' => 'enable' ) ),
) ) );
$p = SourceRegistry::get( 'disable-comments' )->plan();
$lossy = implode( ' | ', $p['lossy'] );
$assert( 'disable-comments exceptions: roles (existing ones + logged-out visitors), ping types, refused types and the closing days are transferred (not lossy any more)', false === strpos( $lossy, 'Exceptions by user role' ) && false === strpos( $lossy, 'closing comments after a number of days' ) && false === strpos( $lossy, 'single comment types' ), $lossy );
$assert( 'disable-comments exceptions: a role that no longer exists is named', false !== strpos( $lossy, 'no longer exist on the site and are not transferred: shop_manager' ), $lossy );
$assert( 'disable-comments exceptions: a comment type the module cannot allow (note) is named, taxonomy / template rules are still lossy, existing comments of closed posts stay visible', false !== strpos( $lossy, 'not transferred: note' ) && false !== strpos( $lossy, 'taxonomy term or page template' ) && false !== strpos( $lossy, 'existing comments stay visible' ), $lossy );

/* disable-comments with comments disabled nowhere. */
$site( array( 'disable-comments' ), array( 'disable-comments' ), array( 'disable_comments_options' => array( 'remove_everywhere' => false, 'disabled_post_types' => array() ) ) );
$p = SourceRegistry::get( 'disable-comments' )->plan();
$assert( 'disable-comments: nowhere disabled => nothing transferred', 0 === $p['counts']['transfer'] );

/* Values the module cannot take are flagged, not silently dropped. */
$site( array( 'disable-comments' ), array( 'disable-comments' ), array( 'disable_comments_options' => array( 'disabled_post_types' => array( 'post', 'e-landing-page' ) ) ) );
$p = SourceRegistry::get( 'disable-comments' )->plan();
$d = array_filter( $p['transfer'], static fn( $row ) => $row['dropped'] );
$assert( 'unknown post type is reported (dropped flag + plan warning)', 1 === count( $d ) && array() !== $p['warnings'], json_encode( $p['warnings'] ) );
SourceRegistry::get( 'disable-comments' )->migrate( array( 'run_id' => 'rdrop' ), false );
$v = SourceRegistry::get( 'disable-comments' )->verify();
$assert( 'verify fails for the dropped value', (bool) array_filter( $v, static fn( $c ) => ! $c['ok'] ), json_encode( $v ) );

/* Sanitizer is applied: junk toggles/selects can't get through. */
$site( array( 'duplicate-page' ), array(), array( 'duplicate_page_options' => array( 'duplicate_post_status' => 'evil<script>', 'duplicate_post_suffix' => '<b>x</b>' ) ) );
SourceRegistry::get( 'duplicate-page' )->migrate( array( 'run_id' => 'rsan' ), false );
$stored = $opt( 'uxstudio_duplicate_post' );
$assert( 'invalid status is not written; suffix goes through the text sanitizer', ! isset( $stored['post_status'] ) && false === strpos( (string) ( $stored['custom_title_format'] ?? '' ), '<b>' ), json_encode( $stored ) );

/* Typo guard: every written key exists in the real module code. */
$typos = array();
foreach ( $seen_keys as [ $option_name, $key ] ) {
	$module = str_replace( '_', '-', substr( $option_name, strlen( 'uxstudio_' ) ) );
	if ( ! $key_in_module( $module, $key ) ) {
		$typos[] = "$module.$key";
	}
}
$assert( 'every key the sources write exists in the target module code', array() === $typos, implode( ',', $typos ) );

/* Warnings: inactive plugin, live module, login URL. */
$site( array( 'wps-hide-login' ), array(), array( 'whl_page' => 'moje', 'uxstudio_active_modules' => array( 'security-optimization' ) ) );
$p = SourceRegistry::get( 'wps-hide-login' )->plan();
$text = implode( ' ', $p['warnings'] );
$assert( 'wps-hide-login plan warns about inactive plugin, live module and the new login URL', 3 === count( $p['warnings'] ) && false !== strpos( $text, 'https://example.test/site/moje/' ), $text );

/* ── Detect / scan ── */

$site( array( 'classic-editor', 'disable-comments', 'loginizer' ), array( 'classic-editor' ), array( 'disable_comments_options' => array( 'remove_everywhere' => true ), 'loginizer_options' => array( 'max_retries' => 3 ), 'limit_login_allowed_retries' => 4 ) );
$scan = SourceRegistry::scan();
$by   = array_column( $scan, null, 'id' );
$assert( 'scan: active plugin', 'active' === ( $by['classic-editor']['status'] ?? '' ) && true === $by['classic-editor']['active'] );
$assert( 'scan: installed but inactive', 'inactive' === ( $by['disable-comments']['status'] ?? '' ) && $by['disable-comments']['summary']['settings'] > 0 );
$assert( 'scan: removed plugin whose settings remain => data_only (selectable, not installed)', 'data_only' === ( $by['limit-login-attempts-reloaded']['status'] ?? '' ) && false === $by['limit-login-attempts-reloaded']['installed'] && true === $by['limit-login-attempts-reloaded']['has_data'] );
$assert( 'scan: nothing for plugins that are neither installed nor have data', ! isset( $by['hide-admin-bar'] ) && ! isset( $by['safe-svg'] ) );
$assert( 'scan: active first, then inactive, then data only', 'active' === $scan[0]['status'] && 'data_only' === end( $scan )['status'], implode( ',', array_column( $scan, 'status' ) ) );

/* ── Snapshot / Runner / rollback ── */

$site(
	array( 'wps-hide-login', 'simple-cloudflare-turnstile', 'classic-editor' ),
	array( 'wps-hide-login', 'simple-cloudflare-turnstile', 'classic-editor' ),
	array(
		'whl_page'                      => 'tajne', 'cfturnstile_key' => 'K1', 'cfturnstile_secret' => 'S1', 'cfturnstile_login' => 'on',
		'uxstudio_security_optimization' => array( 'login_url_enabled' => false, 'login_url' => 'puvodni', 'max_attempts' => 9, 'untouched' => 'keep' ),
	)
);
$before = $snapshot_options();
$run    = Runner::start( array( 'wps-hide-login', 'simple-cloudflare-turnstile', 'classic-editor' ), false );
$assert( 'Runner::start (real) creates a snapshot before anything is written', ! is_wp_error( $run ) && true === $run['snapshot'] && Snapshot::exists( $run['id'] ) && $before['uxstudio_security_optimization'] === $GLOBALS['uxs_options']['uxstudio_security_optimization'] );
$rid = $run['id'];
$s1  = Runner::step();
$assert( 'step 1 processes one source and the run stays running (resumable)', 'running' === $s1['status'] && 'done' === $s1['per_source']['wps-hide-login']['status'] && 'pending' === $s1['per_source']['simple-cloudflare-turnstile']['status'] );
$assert( 'state survives in the option (a reload resumes from it)', Runner::state()['id'] === $rid && 'running' === Runner::state()['status'] );
$busy = Runner::start( array( 'classic-editor' ), true );
$assert( 'a second run is refused while one is running (409)', is_wp_error( $busy ) && 'uxstudio_migration_busy' === $busy->get_error_code() );
Runner::step();
$s3 = Runner::step();
$assert( 'last step closes the run', 'done' === $s3['status'] && $s3['finished'] > 0 );
$assert( 'history has the run with its sources', 1 === count( History::all() ) && $rid === History::all()[0]['id'] && 3 === count( History::all()[0]['sources'] ) );
$after = $snapshot_options();
$sec   = $after['uxstudio_security_optimization'];
$assert( 'migration merged into the existing module option and kept foreign keys', 'tajne' === $sec['login_url'] && true === $sec['login_url_enabled'] && 9 === $sec['max_attempts'] && 'keep' === $sec['untouched'] && 'K1' === $sec['captcha_site_key'], json_encode( $sec ) );
$assert( 'mapping rows were recorded for the run', count( $GLOBALS['wpdb']->map ) > 5 );
$ver = Runner::verify();
$assert( 'Runner::verify returns checks per source, all ok', 3 === count( $ver ) && array() === array_filter( $ver, static fn( $checks ) => array_filter( $checks, static fn( $c ) => ! $c['ok'] ) ) );

$rb = Runner::rollback( $rid );
$own = array( Runner::OPTION => 1, 'uxstudio_migration_runs' => 1 ); // run bookkeeping is not part of the snapshot
$assert( 'rollback restores every option exactly (values + deletes new ones)', ! is_wp_error( $rb ) && array_diff_key( $snapshot_options(), $own ) === $before, is_wp_error( $rb ) ? $rb->get_error_message() : json_encode( array_diff_key( array_diff_key( $snapshot_options(), $own ), $before ) ) );
$assert( 'rollback removed the mapping rows and the snapshot', array() === $GLOBALS['wpdb']->map && ! Snapshot::exists( $rid ) );
$assert( 'run is marked rolled back', 'rolled_back' === Runner::state()['status'] && ! empty( History::find( $rid )['rolled_back'] ) );
$again = Runner::rollback( $rid );
$assert( 'rolling back twice is refused', is_wp_error( $again ) && 'uxstudio_migration_rolled_back' === $again->get_error_code() );
$assert( 'history marks it as not rollbackable', false === Runner::history()[0]['can_rollback'] );

// Dry run through the Runner: no snapshot, no writes, no history entry.
$before = $snapshot_options();
$hist   = count( History::all() );
$dry    = Runner::start( array( 'wps-hide-login' ), true );
Runner::step();
$state_opt = $GLOBALS['uxs_options'][ Runner::OPTION ];
unset( $GLOBALS['uxs_options'][ Runner::OPTION ] );
$assert( 'Runner dry run: no snapshot, nothing written, no history entry', ! $dry['snapshot'] && 'done' === $state_opt['status'] && $snapshot_options() === array_diff_key( $before, array( Runner::OPTION => 1 ) ) && count( History::all() ) === $hist );

// Rollback window and purge.
$site( array(), array(), array( 'uxstudio_hide_admin_bar' => array( 'roles' => array( 'editor' ) ), 'hab_admin_bar_settings' => array( 'remove_by_roles' => array( 'author' ) ) ) );
$run = Runner::start( array( 'hide-admin-bar' ), false );
Runner::step();
$rid = $run['id'];
$assert( 'new run is rollbackable for 30 days', true === Runner::history()[0]['can_rollback'] && Snapshot::expires( $run['started'] ) === $run['started'] + 30 * 86400 );
$purged = Snapshot::purge_expired( time() + 29 * 86400 );
$assert( 'purge keeps a 29 day old snapshot', array() === $purged && Snapshot::exists( $rid ) );
$purged = Snapshot::purge_expired( time() + 31 * 86400 );
$assert( 'purge deletes a 31 day old snapshot and marks the history', array( $rid ) === $purged && ! Snapshot::exists( $rid ) && ! empty( History::find( $rid )['snapshot_purged'] ) );
$gone = Runner::rollback( $rid );
$assert( 'rollback after purge is refused', is_wp_error( $gone ) );

// Unknown / empty input.
$assert( 'unknown source id is rejected', is_wp_error( Runner::start( array( 'nonsense' ), true ) ) && is_wp_error( Runner::start( array(), true ) ) );
$assert( 'run ids are cleaned before they reach an option name', 'rabc123' === Snapshot::clean_id( 'rAbC1../23;' ) );

/* ── Staging: target module already ENABLED ── */

$site(
	array( 'wps-hide-login', 'simple-cloudflare-turnstile' ),
	array( 'wps-hide-login', 'simple-cloudflare-turnstile' ),
	array(
		'whl_page' => 'tajne', 'cfturnstile_key' => 'K9', 'cfturnstile_secret' => 'S3CRETX', 'cfturnstile_login' => 'on',
		'uxstudio_active_modules' => array( 'activity-log', 'security-optimization' ),
		'uxstudio_security_optimization' => array( 'login_url_enabled' => false, 'login_url' => '', 'max_attempts' => 9 ),
	)
);
$live_before = $GLOBALS['uxs_options']['uxstudio_security_optimization'];
$p = SourceRegistry::get( 'wps-hide-login' )->plan();
$assert( 'staging: plan rows are marked staged and the warning says "prepared"', ! empty( $p['transfer'][0]['staged'] ) && false !== strpos( implode( ' ', $p['warnings'] ), 'only prepared' ), json_encode( $p['warnings'] ) );
$run = Runner::start( array( 'wps-hide-login', 'simple-cloudflare-turnstile' ), false );
Runner::step();
$done = Runner::step();
$rid  = $run['id'];
$assert( 'staging: live module option is UNCHANGED while the module is on', $GLOBALS['uxs_options']['uxstudio_security_optimization'] === $live_before, json_encode( $GLOBALS['uxs_options']['uxstudio_security_optimization'] ) );
$assert( 'staging: secret option was not written to the live secret', '' === Security::get_secret( 'uxstudio_secret_security_optimization_captcha_secret_key' ) );
$entry = Staging::entry( $rid );
$assert( 'staging: values wait in the staging area (login url, captcha keys)', 'tajne' === ( $entry['settings']['security-optimization']['login_url'] ?? null ) && true === $entry['settings']['security-optimization']['login_url_enabled'] && 'K9' === $entry['settings']['security-optimization']['captcha_site_key'], json_encode( $entry ) );
$assert( 'staging: secret is sealed, never plain text', isset( $entry['secrets'] ) && 1 === count( $entry['secrets'] ) && false === strpos( json_encode( $GLOBALS['uxs_options'][ Staging::OPTION ] ), 'S3CRETX' ) && 'S3CRETX' === Staging::pending_secret( 'uxstudio_secret_security_optimization_captcha_secret_key' ) );
$assert( 'staging: Runner counts staged values per source and in history', $done['per_source']['wps-hide-login']['staged'] > 0 && History::all()[0]['sources'][0]['staged'] > 0 && Staging::summary( $rid )['settings'] > 0, json_encode( array( $done['per_source']['wps-hide-login']['staged'] ?? null, History::all()[0]['sources'] ?? null, Staging::summary( $rid ) ) ) );
$ver = Runner::verify();
$all_ok = true; $staged_flag = false;
foreach ( $ver as $checks ) { foreach ( $checks as $c ) { $all_ok = $all_ok && $c['ok']; $staged_flag = $staged_flag || ! empty( $c['staged'] ); } }
$assert( 'staging: verify compares source with the staged values (ok + staged flag)', $all_ok && $staged_flag, json_encode( $ver ) );
$snap_opts = $snapshot_options();
$second = SourceRegistry::get( 'wps-hide-login' )->migrate( array( 'run_id' => 'rsecond' ), false );
$assert( 'staging: a second run writes and stages nothing new (idempotent)', 0 === $second['written'] && 0 === $second['staged'] && $snap_opts === $snapshot_options() && null === Staging::entry( 'rsecond' ) );

$res = Staging::apply( $rid );
$sec = $GLOBALS['uxs_options']['uxstudio_security_optimization'];
$assert( 'Staging::apply writes the values into the live option and keeps foreign keys', true === $sec['login_url_enabled'] && 'tajne' === $sec['login_url'] && 9 === $sec['max_attempts'] && $res['settings'] > 0 && 1 === $res['secrets'], json_encode( $res ) );
$assert( 'Staging::apply stores the secret encrypted for the module', 'S3CRETX' === Security::get_secret( 'uxstudio_secret_security_optimization_captcha_secret_key' ) && str_starts_with( (string) $opt( 'uxstudio_secret_security_optimization_captcha_secret_key' ), 'uxs1:' ) );
$assert( 'Staging::apply removes the entry, marks the history and announces itself', null === Staging::entry( $rid ) && ! empty( History::find( $rid )['applied'] ) && in_array( 'uxstudio_migration_staged_applied', $GLOBALS['uxs_actions'], true ) && ! array_key_exists( Staging::OPTION, $GLOBALS['uxs_options'] ) );
$again = Staging::apply( $rid );
$assert( 'Staging::apply is idempotent (second call does nothing)', 0 === $again['settings'] && 0 === $again['secrets'] );
$ver = Runner::verify();
$assert( 'verify after apply: ok and no longer staged', array() === array_filter( $ver, static fn( $checks ) => array_filter( $checks, static fn( $c ) => ! $c['ok'] || ! empty( $c['staged'] ) ) ) );

// Discard (rollback) path.
$site(
	array( 'wps-hide-login' ), array( 'wps-hide-login' ),
	array( 'whl_page' => 'jina', 'uxstudio_active_modules' => array( 'activity-log', 'security-optimization' ), 'uxstudio_security_optimization' => array( 'max_attempts' => 9 ) )
);
$before = $snapshot_options();
$run    = Runner::start( array( 'wps-hide-login' ), false );
Runner::step();
$rid = $run['id'];
$assert( 'staging (rollback case): something is staged', Staging::summary( $rid )['settings'] > 0 );
$rb = Runner::rollback( $rid );
$assert( 'rollback discards the staged values; options are as before', ! is_wp_error( $rb ) && null === Staging::entry( $rid ) && array_diff_key( $snapshot_options(), $own ) === $before );
$assert( 'Staging::discard of an unknown run is harmless', false === Staging::discard( 'rnope' ) );

// Disabled target module: direct write, nothing staged.
$site( array( 'wps-hide-login' ), array( 'wps-hide-login' ), array( 'whl_page' => 'prima' ) );
SourceRegistry::get( 'wps-hide-login' )->migrate( array( 'run_id' => 'rdirect' ), false );
$assert( 'disabled target module: written directly, staging stays empty', 'prima' === ( $opt( 'uxstudio_security_optimization' )['login_url'] ?? null ) && array() === Staging::all() );

// A run that was switched on (Staging::apply) can no longer be rolled back from its snapshot.
$site( array( 'wps-hide-login' ), array( 'wps-hide-login' ), array( 'whl_page' => 'jina', 'uxstudio_active_modules' => array( 'activity-log', 'security-optimization' ) ) );
$run = Runner::start( array( 'wps-hide-login' ), false );
Runner::step();
Staging::apply( $run['id'] );
$refused = Runner::rollback( $run['id'] );
$assert( 'rollback of an already switched-on run is refused (409)', is_wp_error( $refused ) && 'uxstudio_migration_applied' === $refused->get_error_code(), is_wp_error( $refused ) ? $refused->get_error_code() : 'no error' );

// Old staged entries expire with the snapshot window.
$GLOBALS['uxs_options'][ Staging::OPTION ] = array( 'rold' => array( 'created' => time() - 31 * 86400, 'settings' => array( 'x' => array( 'a' => 1 ) ), 'secrets' => array(), 'activations' => array() ) );
$assert( 'staged entries older than the snapshot window are purged', array( 'rold' ) === Staging::purge_expired( time() ) && array() === Staging::all() );

/* ── WP Extended (reuses Migrator, settings only) ── */

$site( array( 'wpextended' ), array(), array( 'wpextended__classic-editor_settings' => array( 'post_types' => array( 'page' ) ), 'wpextended__link-manager_settings' => array( 'disable_arrow' => 1 ), 'uxstudio_link_manager' => array( 'disable_arrow' => 0 ) ) );
$wp   = SourceRegistry::get( 'wpextended' );
$plan = $wp->plan();
$assert( 'wpextended plan: 2 pairs found, 1 new, 1 already in UX Studio (kept)', 2 === $plan['counts']['transfer'] && 1 === $plan['counts']['changed'], json_encode( $plan['counts'] ) );
$assert( 'wpextended snapshot covers Migrator targets', in_array( 'uxstudio_classic_editor', $wp->target_options(), true ) && in_array( 'uxstudio_migrated_from_ux1', $wp->target_options(), true ) );
$before = $snapshot_options();
$wp->migrate( array( 'run_id' => 'rwpx' ), true );
$assert( 'wpextended dry run writes nothing', $before === $snapshot_options() );

echo PHP_EOL . ( $failures ? "$failures FAILED" : 'ALL PASSED' ) . PHP_EOL;
exit( $failures ? 1 : 0 );
