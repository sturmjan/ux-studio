<?php
// Test only: never runs over HTTP (the release zip also excludes tests/).
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { exit; }
/**
 * Migration from Complianz (PLAN.md 42.16), without WordPress: the pure mapping from the real fixture of Complianz 7.5.7,
 * the shortcode and block conversion, the cookie logic, and the migration source with stubbed WordPress functions.
 *
 *   php tests/consent-complianz.php
 *
 * @package UxStudio
 */

// phpcs:disable

define( 'ABSPATH', __DIR__ . '/' );
define( 'DAY_IN_SECONDS', 86400 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'ARRAY_A', 'ARRAY_A' );
define( 'KB_IN_BYTES', 1024 );
$root = dirname( __DIR__ );
define( 'UXSTUDIO_PATH', $root . '/' );

$GLOBALS['uxs_options'] = array();
$GLOBALS['uxs_posts']   = array();
$GLOBALS['uxs_meta']    = array();

class WP_Error { public $code; public function __construct( $c = '', $m = '' ) { $this->code = $c; } }
class WP_Post { public $ID; public $post_type = 'page'; public $post_status = 'publish'; public $post_title = ''; public $post_content = ''; }
class FakeWpdb {
	public $prefix = 'wp_'; public $options = 'wp_options'; public $posts = 'wp_posts'; public $postmeta = 'wp_postmeta'; public $map = array();
	public function replace( $t, $d ) { $this->map[ $d['source_plugin'] . '|' . $d['source_key'] . '|' . $d['target_type'] ] = $d; return 1; }
	public function insert( $t, $d ) { return 1; }
	public function delete( $t, $w ) { return 1; }
	public function prepare( $sql, ...$a ) { return $sql; }
	public function get_var( $sql ) { return null; }
	public function get_col( $sql ) { return array(); }
	public function get_results( $sql, $o = null ) { return array(); }
	public function esc_like( $s ) { return $s; }
	public function query( $s ) { return 0; }
}
$GLOBALS['wpdb'] = new FakeWpdb();

function get_option( $n, $d = false ) { return array_key_exists( $n, $GLOBALS['uxs_options'] ) ? $GLOBALS['uxs_options'][ $n ] : $d; }
function update_option( $n, $v, $a = null ) { $GLOBALS['uxs_options'][ $n ] = $v; return true; }
function add_option( $n, $v = '', $d = '', $a = null ) { if ( array_key_exists( $n, $GLOBALS['uxs_options'] ) ) { return false; } $GLOBALS['uxs_options'][ $n ] = $v; return true; }
function delete_option( $n ) { unset( $GLOBALS['uxs_options'][ $n ] ); return true; }
function apply_filters( $h, $v, ...$a ) { return $v; }
function do_action( $h, ...$a ) {}
function __( $t, $d = '' ) { return $t; }
function _n( $a, $b, $n, $d = '' ) { return 1 === $n ? $a : $b; }
function is_wp_error( $x ) { return $x instanceof WP_Error; }
function current_time( $t ) { return '2026-11-09 12:00:00'; }
function get_current_user_id() { return 1; }
function wp_salt( $s = '' ) { return 'salt'; }
function sanitize_text_field( $s ) { return trim( strip_tags( (string) $s ) ); }
function sanitize_textarea_field( $s ) { return trim( strip_tags( (string) $s ) ); }
function sanitize_hex_color( $s ) { return preg_match( '/^#[0-9a-f]{3,6}$/i', (string) $s ) ? $s : ''; }
function sanitize_key( $s ) { return strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', (string) $s ) ); }
function is_email( $e ) { return (bool) filter_var( $e, FILTER_VALIDATE_EMAIL ); }
function esc_url_raw( $u, $p = null ) { return (string) $u; }
function esc_url( $u ) { return (string) $u; }
function wp_kses_post( $s ) { return (string) $s; }
function maybe_serialize( $v ) { return is_scalar( $v ) ? (string) $v : serialize( $v ); }
function wp_json_encode( $v ) { return json_encode( $v ); }
function trailingslashit( $s ) { return rtrim( $s, '/' ) . '/'; }
function home_url( $p = '' ) { return 'https://example.test' . $p; }
function absint( $v ) { return abs( (int) $v ); }
function wp_slash( $v ) { return $v; }
function get_permalink( $id ) { return 'https://example.test/page-' . (int) $id . '/'; }
function get_post( $id ) { return $GLOBALS['uxs_posts'][ $id ] ?? null; }
function get_post_meta( $id, $k, $single = false ) { return $GLOBALS['uxs_meta'][ $id ][ $k ] ?? ''; }
function update_post_meta( $id, $k, $v ) { $GLOBALS['uxs_meta'][ $id ][ $k ] = $v; return true; }
function delete_post_meta( $id, $k ) { unset( $GLOBALS['uxs_meta'][ $id ][ $k ] ); return true; }
function wp_update_post( $a, $e = false ) { $GLOBALS['uxs_posts'][ $a['ID'] ]->post_content = $a['post_content']; return $a['ID']; }
function wp_roles() { return (object) array( 'roles' => array() ); }
function get_plugins() { return $GLOBALS['uxs_plugins']; }
$GLOBALS['uxs_plugins'] = array();

require_once $root . '/includes/Autoloader.php';
\UxStudio\Autoloader::register();

use UxStudio\Core\Migration\PluginInfo;
use UxStudio\Core\Migration\SettingsWriter;
use UxStudio\Core\Migration\Sources\ComplianzSource;
use UxStudio\Core\Migration\SourceRegistry;
use UxStudio\Core\Migration\Staging;
use UxStudio\Modules\Consent\ComplianzMap;

$failures = 0;
$assert   = static function ( string $name, bool $ok, string $detail = '' ) use ( &$failures ): void {
	if ( ! $ok ) { ++$failures; }
	echo ( $ok ? 'PASS ' : 'FAIL ' ) . $name . ( $ok || '' === $detail ? '' : ' => ' . $detail ) . PHP_EOL;
};

$fx  = json_decode( (string) file_get_contents( __DIR__ . '/fixtures/complianz/7.5.7.json' ), true );
$ctx = array( 'policy_id' => 7, 'cookie_page' => 'https://example.test/prace-s-cookies/' );
$res = ComplianzMap::settings( $fx['options'], $fx['banner_row'], $fx['custom_scripts'], $ctx );
$s   = $res['settings'];

/* settings from the fixture */
$assert( 'consent mode: yes + not basic = advanced', 'advanced' === $s['consent_mode'] );
$assert( 'expiry days copied', 180 === $s['expiry_days'] );
$assert( 'blocker on = scripts and iframes blocked', true === $s['block_scripts'] && true === $s['block_iframes'] );
$assert( 'policy url is the cookie page', 'https://example.test/prace-s-cookies/' === $s['policy_url'] );
$assert( 'texts: title, accept, reject, customize, save', 'Spravovat souhlas' === $s['title'] && 'Přijmout vše' === $s['btn_accept'] && 'Odmítnout' === $s['btn_reject'] && 'Nastavení' === $s['btn_settings'] && 'Uložit výběr' === $s['btn_save'] );
$assert( 'message is plain text without the tags', str_starts_with( $s['message'], 'Používáme cookies' ) && false === strpos( $s['message'], '<' ) );
$assert( 'position bottom-right = box on the right', 'box' === $s['layout'] && 'right' === $s['position'] );
$assert( 'colours: background, text, accent, accent text', '#fafafa' === $s['color_bg'] && '#222222' === $s['color_text'] && '#0a7d3b' === $s['color_accent'] && '#ffffff' === $s['color_accent_text'] );
$assert( 'radius from the px border radius', 12 === $s['radius'] );
$assert( 'profile: name, address (lines kept), email, phone', 'Autoservis Vydra s.r.o.' === $res['profile']['name'] && "Hlavní 12\n123 45 Vydra" === $res['profile']['address'] && 'info@autoservis-test.cz' === $res['profile']['email'] && '+420 123 456 789' === $res['profile']['phone'] );
$assert( 'GA id picked up for Pixel & Tag Manager', 'G-TESTF6CZ12' === $res['ga'] && '' === $res['gtm'] );
$lossy = implode( ' | ', $res['lossy'] );
$assert( 'lossy: DNT, soft wall, custom css, design extras', false !== strpos( $lossy, 'Do Not Track' ) && false !== strpos( $lossy, 'soft cookie wall' ) && false !== strpos( $lossy, 'custom CSS' ) && false !== strpos( $lossy, 'animation' ) );
$assert( 'script centre: block rules with categories, scheme stripped', "chat.example-widgets.cz/loader.js marketing\ncdn.example-chat.com/w.js marketing\nstatic.heatmap-tool.io/tracker statistics\nmapy.nase-firma.cz/embed necessary" === implode( "\n", $res['rules'] ), implode( '|', $res['rules'] ) );
$assert( 'script centre: a disabled rule is skipped', false === strpos( implode( '', $res['rules'] ), 'off.example.com' ) );
$assert( 'script centre: add_script is reported with its code', 1 === count( $res['add_scripts'] ) && false !== strpos( $res['add_scripts'][0]['code'], 'window.x=1' ) );
$parsed = \UxStudio\Modules\Consent\Services::parse_rules( implode( "\n", $res['rules'] ) );
$assert( 'every rule line is accepted by the rule parser of the module', 4 === count( $parsed ) );

$o2 = ComplianzMap::settings( array( 'consent-mode' => 'yes', 'gtag-basic-consent-mode' => 'yes', 'compile_statistics' => 'google-tag-manager', 'gtm_code' => 'GTM-ABC123' ), null, array(), $ctx );
$assert( 'consent mode basic', 'basic' === $o2['settings']['consent_mode'] );
$assert( 'GTM container is reported', 'GTM-ABC123' === $o2['gtm'] && '' === $o2['ga'] );
$o3 = ComplianzMap::settings( array( 'consent-mode' => 'no', 'compile_statistics' => 'matomo' ), null, array(), $ctx );
$assert( 'consent mode off, matomo reported', 'off' === $o3['settings']['consent_mode'] && 'matomo' === $o3['other_stats'] );
$o4 = ComplianzMap::settings( array(), array( 'position' => 'bottom', 'dismiss' => serialize( array( 'text' => 'x', 'show' => 0 ) ), 'message_optin' => '<p>Hi <a href="/x">link</a></p><p>there</p>' ), array(), $ctx );
$assert( 'position bottom = bar; hidden deny button reported; message links reported', 'bar' === $o4['settings']['layout'] && ! isset( $o4['settings']['btn_reject'] ) && 'Hi link there' === $o4['settings']['message'] && false !== strpos( implode( '|', $o4['lossy'] ), 'no "Deny" button' ) && false !== strpos( implode( '|', $o4['lossy'] ), 'had links' ) );
$o5 = ComplianzMap::settings( array(), array( 'colorpalette_background' => serialize( array( 'color' => 'url(x)' ) ) ), array(), $ctx );
$assert( 'an invalid colour is dropped', ! isset( $o5['settings']['color_bg'] ) );
$o6 = ComplianzMap::settings( array(), null, array( 'block_script' => array( array( 'enable' => 1, 'category' => 'functional', 'urls' => array( 'https://a.test/x', 'not valid {}' ) ) ) ), $ctx );
$assert( 'functional = necessary; an address that does not fit the rule format is reported', 'a.test/x necessary' === $o6['rules'][0] && 1 === count( $o6['rule_problems'] ) );

/* content conversion */
$ready = array( 'cookies' => true, 'privacy' => true );
$c = ComplianzMap::convert_content( '<!-- wp:complianz/document {"title":"Z","selectedDocument":"cookie-statement"} /-->', $ready );
$assert( 'block cookie-statement -> shortcode block of our document', '<!-- wp:shortcode -->[uxstudio_legal doc="cookies"]<!-- /wp:shortcode -->' === $c['content'] && $c['changed'] );
$c = ComplianzMap::convert_content( '[cmplz-document type="privacy-statement" region="eu"]', $ready );
$assert( 'shortcode privacy-statement', '[uxstudio_legal doc="privacy"]' === $c['content'] );
$c = ComplianzMap::convert_content( '[cmplz-document type="cookie-statement-us" region="us"]', $ready );
$assert( 'a US document is left and reported', false !== strpos( $c['content'], '[cmplz-document' ) && ! $c['changed'] && 1 === count( $c['left'] ), json_encode( $c ) );
$c = ComplianzMap::convert_content( '[cmplz-document type="impressum" region="eu"]', $ready );
$assert( 'impressum is left and reported', ! $c['changed'] && 'document:impressum (eu)' === $c['left'][0] );
$c = ComplianzMap::convert_content( '[cmplz-document type="cookie-statement" region="eu"]', array( 'cookies' => false ) );
$assert( 'a document whose details are incomplete is left as it is', ! $c['changed'] );
$c = ComplianzMap::convert_content( '<!-- wp:complianz/document {"selectedDocument":"cookie-statement","documentSyncStatus":"unlink","customDocument":"<p>Own<\/p>"} /-->', $ready );
$assert( 'an unlinked block keeps the owner text as HTML', '<!-- wp:html --><p>Own</p><!-- /wp:html -->' === $c['content'], $c['content'] );
$c = ComplianzMap::convert_content( 'a [cmplz-manage-consent] b [cmplz-revoke-link text="Odvolat"] c [cmplz-accept-link] d [cmplz-cookies]', $ready );
$assert( 'buttons and table', 'a [uxstudio_cookie_settings] b [uxstudio_cookie_settings label="Odvolat"] c [uxstudio_cookie_settings] d [uxstudio_cookie_table]' === $c['content'], $c['content'] );
$c = ComplianzMap::convert_content( '[cmplz-consent-area category="marketing"]x[/cmplz-consent-area] <!-- wp:complianz/consent-area {"a":1} /-->', $ready );
$assert( 'consent area is left and reported', ! $c['changed'] && 2 === count( $c['left'] ), json_encode( $c ) );
$assert( 'conversion is idempotent', ! ComplianzMap::convert_content( '[uxstudio_legal doc="cookies"] [uxstudio_cookie_settings]', $ready )['changed'] );

/* cookie logic (mirrors consent.js) */
$ck = array( 'cmplz_banner-status' => 'dismissed', 'cmplz_policy_id' => '7', 'cmplz_functional' => 'allow', 'cmplz_preferences' => 'allow', 'cmplz_statistics' => 'allow', 'cmplz_marketing' => 'deny' );
$assert( 'legacy: preferences + statistics = 3', 3 === ComplianzMap::legacy_bits( $ck, 'cmplz_', 7 ) );
$assert( 'legacy: other policy number = no', null === ComplianzMap::legacy_bits( $ck, 'cmplz_', 8 ) );
$assert( 'legacy: not dismissed = no', null === ComplianzMap::legacy_bits( array_merge( $ck, array( 'cmplz_banner-status' => '' ) ), 'cmplz_', 7 ) );
$assert( 'legacy: no category cookie = no', null === ComplianzMap::legacy_bits( array( 'cmplz_banner-status' => 'dismissed', 'cmplz_policy_id' => '7' ), 'cmplz_', 7 ) );
$assert( 'legacy: deny everything = 0', 0 === ComplianzMap::legacy_bits( array_merge( $ck, array( 'cmplz_preferences' => 'deny', 'cmplz_statistics' => 'deny' ) ), 'cmplz_', 7 ) );

/* the source */
$assert( 'both slugs have a source and it is the Catalog entry of the consent module', SourceRegistry::get( 'complianz-gdpr' ) instanceof ComplianzSource && SourceRegistry::get( 'complianz-gdpr-premium' ) instanceof ComplianzSource && 'consent' === SourceRegistry::get( 'complianz-gdpr' )->target_module() );

$cons_schema = array(
	array( 'key' => 'consent_mode', 'type' => 'select', 'options' => array( 'off' => '', 'basic' => '', 'advanced' => '' ), 'default' => 'basic' ),
	array( 'key' => 'expiry_days', 'type' => 'number', 'default' => 365 ),
	array( 'key' => 'block_scripts', 'type' => 'toggle', 'default' => true ),
	array( 'key' => 'block_iframes', 'type' => 'toggle', 'default' => true ),
	array( 'key' => 'policy_url', 'type' => 'text', 'default' => '' ),
	array( 'key' => 'title', 'type' => 'text', 'default' => '' ),
	array( 'key' => 'message', 'type' => 'textarea', 'default' => '' ),
	array( 'key' => 'btn_accept', 'type' => 'text', 'default' => '' ), array( 'key' => 'btn_reject', 'type' => 'text', 'default' => '' ),
	array( 'key' => 'btn_settings', 'type' => 'text', 'default' => '' ), array( 'key' => 'btn_save', 'type' => 'text', 'default' => '' ),
	array( 'key' => 'layout', 'type' => 'select', 'options' => array( 'box' => '', 'bar' => '' ), 'default' => 'box' ),
	array( 'key' => 'position', 'type' => 'select', 'options' => array( 'left' => '', 'right' => '' ), 'default' => 'left' ),
	array( 'key' => 'color_bg', 'type' => 'color', 'default' => '#ffffff' ), array( 'key' => 'color_text', 'type' => 'color', 'default' => '#1d2327' ),
	array( 'key' => 'color_accent', 'type' => 'color', 'default' => '#1d4ed8' ), array( 'key' => 'color_accent_text', 'type' => 'color', 'default' => '#ffffff' ),
	array( 'key' => 'radius', 'type' => 'number', 'default' => 8 ),
	array( 'key' => 'custom_rules', 'type' => 'textarea', 'default' => '' ),
	array( 'key' => 'legacy_prefix', 'type' => 'text', 'default' => '' ), array( 'key' => 'legacy_policy_id', 'type' => 'number', 'default' => 0 ),
);
$ptm_schema = array( array( 'key' => 'google_analytics', 'type' => 'text', 'default' => '' ) );
SettingsWriter::set_schema_resolver( static fn( string $m ) => 'consent' === $m ? $cons_schema : ( 'pixel-tag-manager' === $m ? $ptm_schema : null ) );

$code_module = file_get_contents( $root . '/includes/Modules/Consent/Module.php' );
$missing = array();
foreach ( $cons_schema as $f ) {
	if ( 1 !== preg_match( "/'key'\s*=>\s*'" . preg_quote( $f['key'], '/' ) . "'/", $code_module ) ) { $missing[] = $f['key']; }
}
$assert( 'every consent key the source writes exists in the real module schema', array() === $missing, implode( ',', $missing ) );

$GLOBALS['uxs_options'] = array(
	'uxstudio_active_modules' => array( 'activity-log' ), 'uxstudio_module_db_versions' => array( 'core_migration' => 1 ),
	'active_plugins' => array( 'complianz-gdpr/complianz-gpdr.php' ),
);
$GLOBALS['uxs_plugins'] = array( 'complianz-gdpr/complianz-gpdr.php' => array( 'Name' => 'Complianz', 'Version' => '7.5.7' ) );
PluginInfo::flush();

$mkpost = static function ( int $id, string $type, string $content, string $title = 'T' ) {
	$p = new WP_Post(); $p->ID = $id; $p->post_type = $type; $p->post_content = $content; $p->post_title = $title;
	$GLOBALS['uxs_posts'][ $id ] = $p;
	return array( 'ID' => $id, 'post_type' => $type, 'post_status' => 'publish', 'post_title' => $title, 'post_content' => $content );
};
$rows = array(
	$mkpost( 8, 'page', '<!-- wp:complianz/document {"title":"Z","selectedDocument":"cookie-statement"} /-->', 'Cookies' ),
	$mkpost( 9, 'page', '[cmplz-document type="impressum" region="eu"]', 'Impressum' ),
	$mkpost( 11, 'page', 'x [cmplz-manage-consent] y', 'Nastaveni' ),
);
ComplianzSource::$raw_override = array(
	'opt' => $fx['options'], 'scripts' => $fx['custom_scripts'], 'banner' => $fx['banner_row'], 'banners' => 1,
	'policy_id' => 7, 'prefix' => 'cmplz_', 'posts' => $rows,
);
$src  = SourceRegistry::get( 'complianz-gdpr' );
$plan = $src->plan();
$assert( 'plan: lists settings, the profile option and the pixel module id', $plan['counts']['transfer'] > 15 && in_array( 'profile', array_column( $plan['transfer'], 'key' ), true ) && in_array( 'google_analytics', array_column( $plan['transfer'], 'key' ), true ) );
$assert( 'plan: 2 pages will be rewritten (the impressum stays)', 2 === $plan['counts']['data'], json_encode( $plan['data'] ) );
$wtxt = implode( ' | ', $plan['warnings'] );
$assert( 'plan: the add_script script blocks the switch and shows its code', false !== strpos( $wtxt, 'BLOCKS THE SWITCH' ) && false !== strpos( $wtxt, 'window.x=1' ) );
$assert( 'plan: impressum is listed as not converted', false !== strpos( implode( ' | ', $plan['lossy'] ), 'Impressum (document:impressum (eu))' ), implode( ' | ', $plan['lossy'] ) );
$assert( 'extra module: Pixel & Tag Manager', array( 'pixel-tag-manager' ) === $src->extra_modules() );
$checks = $src->smoke_checks();
$assert( 'smoke: banner check (one page) + blockers check', 'consent_banner' === $checks[0]['type'] && 1 === count( $checks[0]['pages'] ) && 'consent_blockers' === $checks[1]['type'], json_encode( $checks ) );

$dry = $src->migrate( array( 'run_id' => 'rtest' ), true );
$assert( 'dry run writes nothing', ! isset( $GLOBALS['uxs_options']['uxstudio_consent'] ) && ! isset( $GLOBALS['uxs_options']['uxstudio_consent_legal'] ) );
$first = $src->migrate( array( 'run_id' => 'rtest' ), false );
$guard = 0;
while ( empty( $first['done'] ) && $guard++ < 5 ) { $first = $src->migrate( array( 'run_id' => 'rtest', 'cursor' => $first['cursor'] ), false ); }
$ox = $GLOBALS['uxs_options']['uxstudio_consent'] ?? array();
$assert( 'module disabled: the settings are written straight into the module option', 'advanced' === ( $ox['consent_mode'] ?? '' ) && 'cmplz_' === ( $ox['legacy_prefix'] ?? '' ) && 7 === (int) ( $ox['legacy_policy_id'] ?? 0 ), json_encode( $ox ) );
$assert( 'the legal profile was prefilled', 'Autoservis Vydra s.r.o.' === ( $GLOBALS['uxs_options']['uxstudio_consent_legal']['profile']['name'] ?? '' ) );
$assert( 'GA id prepared for Pixel & Tag Manager', 'G-TESTF6CZ12' === ( $GLOBALS['uxs_options']['uxstudio_pixel_tag_manager']['google_analytics'] ?? '' ) );
$assert( 'page content is NOT changed by the migration itself', false !== strpos( $GLOBALS['uxs_posts'][8]->post_content, 'wp:complianz/document' ) );
$staged = Staging::entry( 'rtest' );
$ids = array();
foreach ( (array) ( $staged['activations'] ?? array() ) as $row ) { $ids = array_merge( $ids, $row['ids'] ); }
$assert( 'the two pages wait in the staging area', array( '8', '11' ) === $ids, json_encode( $ids ) );
$src->forget();
$second = $src->migrate( array( 'run_id' => 'rtest2' ), false );
$guard = 0;
while ( empty( $second['done'] ) && $guard++ < 5 ) { $second = $src->migrate( array( 'run_id' => 'rtest2', 'cursor' => $second['cursor'] ), false ); }
$assert( 'second run: nothing new is staged', 0 === ( $second['staged'] ?? 0 ) && 0 === ( $second['written'] ?? 0 ), json_encode( $second ) );
$bad = array_filter( $src->verify(), static fn( $c ) => ! $c['ok'] );
$assert( 'verify is all ok', array() === $bad, json_encode( $bad ) );

/* the switch: activate and the way back */
$orig8 = $GLOBALS['uxs_posts'][8]->post_content;
$n = $src->activate( 'page', array( '8', '11' ), array() );
$assert( 'activate rewrites both pages', 2 === $n, (string) $n );
$assert( 'page 8 now holds our shortcode, same post (address stays)', false !== strpos( $GLOBALS['uxs_posts'][8]->post_content, '[uxstudio_legal doc="cookies"]' ) && $orig8 === $GLOBALS['uxs_meta'][8]['_uxs_cz_original'] );
$assert( 'page 11 now holds the settings button', 'x [uxstudio_cookie_settings] y' === $GLOBALS['uxs_posts'][11]->post_content );
$assert( 'page 8 was adopted as the cookies page of the legal pages', 8 === (int) ( $GLOBALS['uxs_options']['uxstudio_consent_legal']['pages']['cookies'] ?? 0 ) );
$assert( 'activate twice does nothing more', 0 === $src->activate( 'page', array( '8', '11' ), array() ) );
$back = $src->deactivate( 'page', array( '8', '11' ), array() );
$assert( 'deactivate restores the original content byte for byte', 2 === $back && $orig8 === $GLOBALS['uxs_posts'][8]->post_content && 'x [cmplz-manage-consent] y' === $GLOBALS['uxs_posts'][11]->post_content );
$assert( 'deactivate removes the helper meta and releases the page', ! isset( $GLOBALS['uxs_meta'][8]['_uxs_cz_original'] ) && ! isset( $GLOBALS['uxs_options']['uxstudio_consent_legal']['pages']['cookies'] ) );
$src->activate( 'page', array( '11' ), array() );
$GLOBALS['uxs_posts'][11]->post_content .= ' edited later';
$assert( 'a page edited after the switch is not overwritten by the undo', 0 === $src->deactivate( 'page', array( '11' ), array() ) && false !== strpos( $GLOBALS['uxs_posts'][11]->post_content, 'edited later' ) );

/* GTM without a replacement blocks; with GTM Kit it does not */
ComplianzSource::$raw_override['opt']     = array( 'compile_statistics' => 'google-tag-manager', 'gtm_code' => 'GTM-ABC123' );
ComplianzSource::$raw_override['scripts'] = array();
$src = SourceRegistry::get( 'complianz-gdpr' );
$assert( 'GTM container with nothing else to insert it: blocking test', 'consent_blockers' === ( $src->smoke_checks()[1]['type'] ?? '' ) );
$GLOBALS['uxs_options']['active_plugins'][]     = 'gtm-kit/gtm-kit.php';
$GLOBALS['uxs_plugins']['gtm-kit/gtm-kit.php'] = array( 'Name' => 'GTM Kit', 'Version' => '1' );
PluginInfo::flush();
$src = SourceRegistry::get( 'complianz-gdpr' );
$assert( 'GTM container inserted by GTM Kit: no blocking test', 1 === count( $src->smoke_checks() ) );

foreach ( array( 'Modules/Consent/ComplianzMap.php', 'Modules/Consent/ComplianzCompat.php', 'Core/Migration/Sources/ComplianzSource.php' ) as $f ) {
	$t = file_get_contents( $root . '/includes/' . $f );
	$assert( "no em or en dash in $f", false === strpos( $t, "\u{2014}" ) && false === strpos( $t, "\u{2013}" ) );
}

echo $failures ? "\n$failures FAILED\n" : "\nALL PASSED\n";
exit( $failures ? 1 : 0 );
