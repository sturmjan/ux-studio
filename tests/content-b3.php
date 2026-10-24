<?php
/**
 * Standalone test of PLAN.md 27.9 (B3, "Obsah II"): the options of Duplicate Post (status of the copy, excluded fields with
 * wildcards, excluded taxonomies, roles, all post types), the rules of Link Manager (per domain, never external, icons, one
 * pass over the content), the editor choice of Classic Editor, the exceptions and countdown of Maintenance Mode and the model
 * of Folder Manager (post types, colours, manual order). Needs no WordPress and no database:
 *
 *   php tests/content-b3.php
 *
 * The WordPress functions the modules call are small doubles (an in-memory term store for the folders); the real queries,
 * the screens and the front end are covered by the live checks on the site.
 *
 * @package UxStudio
 */

// phpcs:disable

define( 'ABSPATH', __DIR__ . '/' );
define( 'UXSTUDIO_PATH', dirname( __DIR__ ) . '/' );
define( 'ARRAY_A', 'ARRAY_A' );

$GLOBALS['uxs_options']  = array();
$GLOBALS['uxs_meta']     = array(); // post meta: id => key => list of values
$GLOBALS['uxs_added']    = array(); // add_post_meta calls
$GLOBALS['uxs_user']     = array( 'roles' => array( 'editor' ), 'can' => array() );
$GLOBALS['uxs_status']   = array(); // post id => status
$GLOBALS['uxs_ptypes']   = array();
$GLOBALS['uxs_terms_of'] = array(); // post id => taxonomy => slugs
$GLOBALS['uxs_set_terms'] = array();
$GLOBALS['uxs_filters']  = array();
$GLOBALS['uxs_enqueued'] = array();
$GLOBALS['uxs_usermeta'] = array();
$GLOBALS['uxs_postmeta_write'] = array();

class WP_Error {
	public $code; public $message; public $data;
	public function __construct( $code = '', $message = '', $data = '' ) { $this->code = $code; $this->message = $message; $this->data = $data; }
	public function get_error_code() { return $this->code; }
	public function get_error_message() { return $this->message; }
	public function get_error_data() { return $this->data; }
}
class WP_Term {
	public $term_id; public $name; public $slug; public $parent; public $taxonomy;
	public function __construct( array $a ) { foreach ( $a as $k => $v ) { $this->$k = $v; } }
}
class WP_Post {
	public $ID = 0; public $post_type = 'post'; public $post_status = 'publish'; public $post_content = ''; public $post_title = '';
}
class FakeWpdb {
	public $prefix = 'wp_'; public $log = array();
	public $term_relationships = 'wp_term_relationships'; public $term_taxonomy = 'wp_term_taxonomy'; public $posts = 'wp_posts';
	public function insert( $table, $data, $f = null ) { $this->log[] = $data; return 1; }
	public function prepare( $sql, ...$args ) { return $sql; }
	public function get_results( $sql, $output = null ) { return array(); }
	public function get_var( $sql ) { return 0; }
}
$GLOBALS['wpdb'] = new FakeWpdb();

function is_wp_error( $x ) { return $x instanceof WP_Error; }
function __( $text, $domain = '' ) { return $text; }
function esc_html__( $text, $domain = '' ) { return $text; }
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_url( $s ) { return (string) $s; }
function sanitize_text_field( $s ) { return trim( strip_tags( (string) $s ) ); }
function sanitize_textarea_field( $s ) { return trim( strip_tags( (string) $s ) ); }
function sanitize_key( $s ) { return strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', (string) $s ) ); }
function sanitize_title( $s ) { return trim( preg_replace( '/[^a-z0-9]+/', '-', strtolower( (string) $s ) ), '-' ); }
function sanitize_hex_color( $s ) { return preg_match( '/^#(?:[0-9a-f]{3}|[0-9a-f]{6})$/i', (string) $s ) ? (string) $s : ''; }
function absint( $v ) { return abs( (int) $v ); }
function wp_slash( $v ) { return $v; }
function wp_unslash( $v ) { return $v; }
function maybe_unserialize( $v ) { return $v; }
function wp_parse_url( $url, $component = -1 ) { return parse_url( $url, $component ); }
function home_url( $path = '' ) { return 'https://www.example.test/site' . $path; }
function wp_json_encode( $v ) { return json_encode( $v ); }
function current_time( $t ) { return '2026-10-23 12:00:00'; }
function get_current_user_id() { return 7; }
function get_option( $name, $default = false ) { return array_key_exists( $name, $GLOBALS['uxs_options'] ) ? $GLOBALS['uxs_options'][ $name ] : $default; }
function update_option( $name, $value, $autoload = null ) { $GLOBALS['uxs_options'][ $name ] = $value; return true; }
function add_filter( $hook, $cb, $prio = 10, $args = 1 ) { $GLOBALS['uxs_filters'][ $hook ][] = $cb; }
function add_action( $hook, $cb, $prio = 10, $args = 1 ) { $GLOBALS['uxs_filters'][ $hook ][] = $cb; }
function apply_filters( $hook, $value, ...$args ) { return $value; }
function do_action( $hook, ...$args ) {}
function wp_get_current_user() { return (object) array( 'ID' => 7, 'roles' => $GLOBALS['uxs_user']['roles'] ); }
function current_user_can( $cap ) { return 'manage_options' === $cap ? in_array( 'administrator', $GLOBALS['uxs_user']['roles'], true ) : in_array( $cap, $GLOBALS['uxs_user']['can'], true ); }
function get_post_status( $id ) { return $GLOBALS['uxs_status'][ (int) $id ] ?? false; }
function get_post_type( $id ) { return $GLOBALS['uxs_ptypes'][ (int) $id ] ?? 'post'; }
function get_post_type_object( $type ) {
	$reg = array( 'post' => true, 'page' => true, 'product' => true, 'portfolio' => true, 'hidden' => false );
	if ( ! array_key_exists( $type, $reg ) ) { return null; }
	return (object) array( 'show_ui' => $reg[ $type ], 'cap' => (object) array( 'publish_posts' => 'publish_' . $type . 's', 'edit_posts' => 'edit_' . $type . 's' ) );
}
function post_type_exists( $t ) { return in_array( $t, array( 'post', 'page', 'product', 'attachment', 'portfolio' ), true ); }
function get_post_meta( $id, $key = '', $single = false ) {
	$all = $GLOBALS['uxs_meta'][ $id ] ?? array();
	if ( '' === $key ) { return $all; }
	$v = $all[ $key ] ?? array();
	return $single ? ( $v ? $v[0] : '' ) : $v;
}
function add_post_meta( $id, $key, $value ) { $GLOBALS['uxs_added'][] = array( $id, $key, $value ); return true; }
function update_post_meta( $id, $key, $value ) { $GLOBALS['uxs_postmeta_write'][ $id ][ $key ] = $value; $GLOBALS['uxs_meta'][ $id ][ $key ] = array( $value ); return true; }
function get_object_taxonomies( $type ) { return array( 'category', 'post_tag', 'post_format' ); }
function wp_get_object_terms( $id, $tax, $args = array() ) { return $GLOBALS['uxs_terms_of'][ $id ][ $tax ] ?? array(); }
function wp_set_object_terms( $id, $terms, $tax, $append = false ) { $GLOBALS['uxs_set_terms'][ $id ][ $tax ] = $terms; return $terms; }
function wp_roles() { return (object) array( 'roles' => array(), 'get_names' => null ); }
function wp_style_is( $h, $list = 'enqueued' ) { return in_array( $h, $GLOBALS['uxs_enqueued'], true ); }
function wp_enqueue_style( $h ) { $GLOBALS['uxs_enqueued'][] = $h; }
function wp_timezone() { return new DateTimeZone( 'Europe/Prague' ); }
function wp_date( $fmt, $ts = null ) { return ( new DateTimeImmutable( '@' . ( $ts ?? time() ) ) )->setTimezone( new DateTimeZone( 'Europe/Prague' ) )->format( $fmt ); }
function is_feed() { return ! empty( $GLOBALS['uxs_is_feed'] ); }
function has_blocks( $content ) { return false !== strpos( (string) $content, '<!-- wp:' ); }
function get_user_option( $key, $user_id = 0 ) { return $GLOBALS['uxs_usermeta'][ $key ] ?? false; }
function post_type_supports( $type, $feature ) { return 'attachment' !== $type; }
function use_block_editor_for_post_type( $type ) { return 'portfolio' !== $type; }
function get_bloginfo( $what = '' ) { return '6.7'; }

/* An in-memory term store for the Folder Manager model. */
$GLOBALS['uxs_tstore'] = array();
$GLOBALS['uxs_tmeta']  = array();
$GLOBALS['uxs_tnext']  = 100;
function taxonomy_exists( $t ) { return true; }
function get_term( $id, $tax = '' ) { return isset( $GLOBALS['uxs_tstore'][ (int) $id ] ) ? new WP_Term( $GLOBALS['uxs_tstore'][ (int) $id ] ) : new WP_Error( 'invalid_term', 'x' ); }
function get_terms( $args ) {
	$out = array();
	foreach ( $GLOBALS['uxs_tstore'] as $row ) {
		if ( isset( $args['parent'] ) && (int) $row['parent'] !== (int) $args['parent'] ) { continue; }
		if ( ! empty( $args['exclude'] ) && in_array( $row['term_id'], $args['exclude'], true ) ) { continue; }
		if ( ! empty( $args['meta_query'] ) ) {
			$type = $GLOBALS['uxs_tmeta'][ $row['term_id'] ]['uxstudio_folder_post_type'] ?? null;
			$want = null;
			foreach ( $args['meta_query'] as $k => $q ) { if ( is_array( $q ) && isset( $q['value'] ) ) { $want = $q['value']; } }
			if ( 'attachment' === $want ? ( null !== $type && 'attachment' !== $type ) : $type !== $want ) { continue; }
		}
		$out[] = new WP_Term( $row );
	}
	usort( $out, static fn( $a, $b ) => strcmp( $a->name, $b->name ) );
	return $out;
}
function wp_insert_term( $name, $tax, $args = array() ) {
	$id = ++$GLOBALS['uxs_tnext'];
	$GLOBALS['uxs_tstore'][ $id ] = array( 'term_id' => $id, 'name' => $name, 'slug' => $args['slug'] ?? sanitize_title( $name ), 'parent' => (int) ( $args['parent'] ?? 0 ), 'taxonomy' => $tax );
	return array( 'term_id' => $id );
}
function wp_update_term( $id, $tax, $args = array() ) { foreach ( $args as $k => $v ) { $GLOBALS['uxs_tstore'][ $id ][ $k ] = $v; } return array( 'term_id' => $id ); }
function wp_unique_term_slug( $slug, $term ) { return $slug; }
function update_term_meta( $id, $key, $value ) { $GLOBALS['uxs_tmeta'][ $id ][ $key ] = $value; return true; }
function get_term_meta( $id, $key, $single = false ) { return $GLOBALS['uxs_tmeta'][ $id ][ $key ] ?? ''; }
function delete_term_meta( $id, $key ) { unset( $GLOBALS['uxs_tmeta'][ $id ][ $key ] ); return true; }
function register_taxonomy() {}

require_once dirname( __DIR__ ) . '/includes/Autoloader.php';
\UxStudio\Autoloader::register();

use UxStudio\Modules\ClassicEditor\EditorChoice;
use UxStudio\Modules\DuplicatePost\DuplicateRules as D;
use UxStudio\Modules\DuplicatePost\Duplicator;
use UxStudio\Modules\LinkManager\LinkRules as L;
use UxStudio\Modules\MaintenanceMode\MaintenanceRules as M;

$failures = 0;
$assert   = static function ( string $name, bool $ok, string $detail = '' ) use ( &$failures ): void {
	if ( ! $ok ) {
		++$failures;
	}
	echo ( $ok ? 'PASS ' : 'FAIL ' ) . $name . ( $ok || '' === $detail ? '' : ' => ' . $detail ) . PHP_EOL;
};
$call = static function ( $object, string $method, ...$args ) {
	$ref = new ReflectionMethod( $object, $method );
	$ref->setAccessible( true );
	return $ref->invoke( $object, ...$args );
};
$set = static function ( string $option, array $values ): void { $GLOBALS['uxs_options'][ $option ] = $values; };

/* ═══ Duplicate Post ═══ */

$assert( 'list: lines and commas, comments, duplicates', array( '_a', '_b_*', '_c' ) === D::parse_list( "_a, _b_*\n# comment\n_c  # trailing\n_a\n\n" ), json_encode( D::parse_list( "_a, _b_*\n# comment\n_c  # trailing\n_a\n\n" ) ) );
$p = array( '_edit_*', 'foo', '*_cache' );
$assert( 'wildcard: prefix, suffix, exact; partial names do not match', D::matches( '_edit_lock', $p ) && D::matches( '_edit_last', $p ) && D::matches( 'page_cache', $p ) && D::matches( 'foo', $p ) && ! D::matches( 'foobar', $p ) && ! D::matches( 'my_edit_lock', $p ) && ! D::matches( 'cache', $p ) && ! D::matches( 'x', array() ) );
$assert( 'wildcard: regex characters in a key are literal, star in the middle', D::matches( 'a.b', array( 'a.b' ) ) && ! D::matches( 'axb', array( 'a.b' ) ) && D::matches( 'acf_field_9', array( 'acf_*_9' ) ) && D::matches( '_x', array( '*' ) ) );
$assert( 'status of the copy: fixed, kept, scheduled and unknown become a draft', 'draft' === D::copy_status( 'publish', 'draft', false ) && 'publish' === D::copy_status( 'publish', 'draft', true ) && 'private' === D::copy_status( 'private', 'draft', true ) && 'pending' === D::copy_status( 'pending', 'publish', true ) && 'draft' === D::copy_status( 'future', 'publish', true ) && 'draft' === D::copy_status( 'inherit', 'publish', true ) );
$assert( 'roles: nothing selected = everybody, administrators always, otherwise any matching role', D::role_allowed( array( 'author' ), array(), false ) && D::role_allowed( array( 'subscriber' ), array( 'editor' ), true ) && D::role_allowed( array( 'author', 'editor' ), array( 'editor' ), false ) && ! D::role_allowed( array( 'author' ), array( 'editor' ), false ) && ! D::role_allowed( array(), array( 'editor' ), false ) );

// The engine: meta and taxonomies.
$GLOBALS['uxs_meta'][10] = array(
	'_edit_lock' => array( 'x' ), '_thumbnail_id' => array( '5' ), 'keep_me' => array( 'a', 'b' ), 'secret_one' => array( 'z' ), '_cache_1' => array( 'c' ), 'obj' => array( 'O:8:"stdClass":0:{}' ),
);
$dup = new Duplicator( array( '_edit_lock', 'secret_*', '_cache_1' ) );
$call( $dup, 'duplicate_post_meta', 10, 20, array( 'meta_fields', 'featured_image' ) );
$copied = array_map( static fn( $a ) => $a[1] . '=' . $a[2], $GLOBALS['uxs_added'] );
$assert( 'engine: custom fields copy except the excluded keys and patterns; the featured image and multiple values stay; object values are refused', array( '_thumbnail_id=5', 'keep_me=a', 'keep_me=b' ) === $copied, json_encode( $copied ) );
$GLOBALS['uxs_added'] = array();
$call( new Duplicator( array() ), 'duplicate_post_meta', 10, 21, array( 'featured_image' ) );
$assert( 'engine: without the custom fields element only the featured image goes', array( '_thumbnail_id=5' ) === array_map( static fn( $a ) => $a[1] . '=' . $a[2], $GLOBALS['uxs_added'] ) );
$GLOBALS['uxs_terms_of'][10] = array( 'category' => array( 'news' ), 'post_tag' => array( 't1' ), 'post_format' => array( 'video' ) );
$call( new Duplicator( array(), array( 'post_tag', 'post_format' ) ), 'duplicate_taxonomies', 10, 22 );
$assert( 'engine: excluded taxonomies are not copied, the others are', array( 'category' => array( 'news' ) ) === $GLOBALS['uxs_set_terms'][22], json_encode( $GLOBALS['uxs_set_terms'] ) );

// The module.
$module = new UxStudio\Modules\DuplicatePost\Module( 'duplicate-post', array() );
$set( 'uxstudio_duplicate_post', array( 'post_types' => array( 'post' ) ) );
$module = new UxStudio\Modules\DuplicatePost\Module( 'duplicate-post', array() );
$ex = new ReflectionProperty( $module, 'excluded_post_types' ); $ex->setAccessible( true ); $ex->setValue( $module, array( 'attachment' ) );
$assert( 'post types: the selected list when "all" is off', $call( $module, 'is_allowed_post_type', 'post' ) && ! $call( $module, 'is_allowed_post_type', 'page' ) && ! $call( $module, 'is_allowed_post_type', 'portfolio' ) );
$set( 'uxstudio_duplicate_post', array( 'post_types' => array( 'post' ), 'all_post_types' => true ) );
$module = new UxStudio\Modules\DuplicatePost\Module( 'duplicate-post', array() ); $ex->setValue( $module, array( 'attachment' ) );
$assert( 'post types: "all" offers every type with a list screen, not the hard exclusions, not types without a screen or unknown ones', $call( $module, 'is_allowed_post_type', 'page' ) && $call( $module, 'is_allowed_post_type', 'portfolio' ) && ! $call( $module, 'is_allowed_post_type', 'attachment' ) && ! $call( $module, 'is_allowed_post_type', 'hidden' ) && ! $call( $module, 'is_allowed_post_type', 'unknown' ) );

$GLOBALS['uxs_status'][5] = 'publish'; $GLOBALS['uxs_ptypes'][5] = 'post';
$GLOBALS['uxs_user'] = array( 'roles' => array( 'editor' ), 'can' => array( 'publish_posts' ) );
$set( 'uxstudio_duplicate_post', array( 'post_status' => 'pending' ) );
$module = new UxStudio\Modules\DuplicatePost\Module( 'duplicate-post', array() );
$assert( 'status: the fixed status is the default behaviour', 'pending' === $call( $module, 'get_post_status', 5 ) );
$set( 'uxstudio_duplicate_post', array( 'post_status' => 'draft', 'keep_status' => true ) );
$module = new UxStudio\Modules\DuplicatePost\Module( 'duplicate-post', array() );
$assert( 'status: "keep" gives a published original a published copy to a user who can publish', 'publish' === $call( $module, 'get_post_status', 5 ) );
$GLOBALS['uxs_user']['can'] = array();
$assert( 'status: ... but a user who cannot publish gets a pending copy', 'pending' === $call( $module, 'get_post_status', 5 ) );
$GLOBALS['uxs_status'][5] = 'future'; $GLOBALS['uxs_user']['can'] = array( 'publish_posts' );
$assert( 'status: a scheduled original gives a draft', 'draft' === $call( $module, 'get_post_status', 5 ) );

$set( 'uxstudio_duplicate_post', array( 'allowed_roles' => array( 'editor' ) ) );
$module = new UxStudio\Modules\DuplicatePost\Module( 'duplicate-post', array() );
$GLOBALS['uxs_user'] = array( 'roles' => array( 'editor' ), 'can' => array() );
$a = $module->user_may_duplicate();
$GLOBALS['uxs_user'] = array( 'roles' => array( 'author' ), 'can' => array() );
$b = $module->user_may_duplicate();
$GLOBALS['uxs_user'] = array( 'roles' => array( 'administrator' ), 'can' => array() );
$c = $module->user_may_duplicate();
$set( 'uxstudio_duplicate_post', array() );
$module = new UxStudio\Modules\DuplicatePost\Module( 'duplicate-post', array() );
$GLOBALS['uxs_user'] = array( 'roles' => array( 'author' ), 'can' => array() );
$assert( 'roles: the selected role may, another may not, administrators always, no selection = everybody', $a && ! $b && $c && $module->user_may_duplicate() );
$actions = $module->add_duplicate_link( array(), new WP_Post() );
$assert( 'row action: not offered for a post the user cannot edit (and no link is built)', array() === $actions );

/* ═══ Link Manager ═══ */

$parsed = L::parse_rules( "# partners\npartner.example tab=no rel=none\n*.shop.example rel=sponsored,nofollow icon=no\nexample.org/affiliate/* rel=sponsored\nbroken line here\nonlypattern.example\nbad.example tab=maybe\nrel.example rel=hello\n" );
$assert( 'rules: valid lines parse, comments are skipped', 3 === count( $parsed['rules'] ) && 'partner.example' === $parsed['rules'][0]['pattern'] && false === $parsed['rules'][0]['tab'] && array() === $parsed['rules'][0]['rel'] && array( 'sponsored', 'nofollow' ) === $parsed['rules'][1]['rel'] && false === $parsed['rules'][1]['icon'], json_encode( $parsed['rules'] ) );
$assert( 'rules: broken lines are reported with their number (a lone pattern, a wrong value, an unknown rel)', array( 5, 6, 7, 8 ) === array_column( $parsed['errors'], 'line' ), json_encode( $parsed['errors'] ) );
$assert( 'patterns: scheme and www are dropped, comma and line separated', array( 'a.example', '*.b.example', 'c.example/x/*' ) === L::parse_patterns( "https://www.a.example\n*.b.example, c.example/x/* # note" ), json_encode( L::parse_patterns( "https://www.a.example\n*.b.example, c.example/x/* # note" ) ) );
$part = L::parts( 'https://WWW.Shop.Example/Path/Sub?x=1' );
$assert( 'parts: host without www in lower case, host and path', 'shop.example' === $part['host'] && 'shop.example/path/sub?x=1' === $part['host_path'] );
$assert( 'match: exact host, www ignored, subdomains only with a star, a path with a prefix on whole segments', L::matches( 'shop.example', 'shop.example', 'shop.example/' ) && ! L::matches( 'shop.example', 'a.shop.example', 'a.shop.example/' ) && L::matches( '*.shop.example', 'a.shop.example', 'a.shop.example/' ) && ! L::matches( '*.shop.example', 'shop.example', 'shop.example/' ) && L::matches( 'example.org/affiliate', 'example.org', 'example.org/affiliate/x' ) && ! L::matches( 'example.org/affiliate', 'example.org', 'example.org/affiliates' ) && L::matches( 'example.org/affiliate/*', 'example.org', 'example.org/affiliate/a/b' ) );
$rules = $parsed['rules'];
$assert( 'find: first matching line wins', 'partner.example' === L::find_rule( $rules, 'partner.example', 'partner.example/x' )['pattern'] && 'example.org/affiliate/*' === L::find_rule( $rules, 'example.org', 'example.org/affiliate/z' )['pattern'] && null === L::find_rule( $rules, 'other.example', 'other.example/' ) );
$assert( 'base domain: www and second level domains', 'example.com' === L::base_domain( 'www.example.com' ) && 'example.com' === L::base_domain( 'a.b.example.com' ) && 'example.co.uk' === L::base_domain( 'shop.example.co.uk' ) && 'example.cz' === L::base_domain( 'example.cz' ) );
$cl = static fn( string $href, bool $sub = false, array $int = array() ) => L::classify( $href, 'example.test', $sub, $int )['kind'];
$assert( 'classify: mailto, tel, javascript and other schemes are skipped, anchors and empty href too', 'skip' === $cl( 'mailto:a@b.cz' ) && 'skip' === $cl( 'tel:+420' ) && 'skip' === $cl( 'javascript:void(0)' ) && 'skip' === $cl( 'ftp://x.example/f' ) && 'anchor' === $cl( '#top' ) && 'anchor' === $cl( '' ) );
$assert( 'classify: relative addresses are internal (also without a leading slash), the own host with either scheme and www', 'internal' === $cl( '/kontakt' ) && 'internal' === $cl( 'kontakt/' ) && 'internal' === $cl( '?s=x' ) && 'internal' === $cl( 'https://example.test/x' ) && 'internal' === $cl( 'http://www.example.test/x' ) );
$assert( 'classify: a protocol-relative address to another host is external (it used to count as internal)', 'external' === $cl( '//cdn.other.example/x' ) && 'internal' === $cl( '//example.test/x' ) && 'external' === $cl( 'https://example.com/' ) );
$assert( 'classify: subdomains of the site only with the switch, the listed addresses are never external', 'external' === $cl( 'https://shop.example.test/' ) && 'internal' === $cl( 'https://shop.example.test/', true ) && 'external' === $cl( 'https://evil-example.test/', true ) && 'internal' === $cl( 'https://my.partner.example/a', false, array( '*.partner.example' ) ) && 'external' === $cl( 'https://my.partner.example/a', false, array( 'partner.example' ) ) );
$assert( 'rel merge: existing tokens stay, nothing twice', 'external noopener noreferrer' === L::merge_rel( 'external noopener', array( 'noopener', 'noreferrer' ) ) && 'nofollow' === L::merge_rel( '', array( 'nofollow' ) ) );

$set( 'uxstudio_link_manager', array(
	'link_rules'       => "partner.example tab=no rel=none\n*.shop.example rel=sponsored,nofollow icon=no\nexample.org/affiliate/* rel=sponsored",
	'internal_domains' => 'friend.example',
) );
$lm = new UxStudio\Modules\LinkManager\Module( 'link-manager', array() );
$cl = new ReflectionProperty( $lm, 'exclude_classes' ); $cl->setAccessible( true ); $cl->setValue( $lm, $call( $lm, 'build_exclude_classes' ) );
$html = '<p><a href="https://plain.example/a">plain</a> <a href="https://partner.example/x">partner</a> <a href="https://a.shop.example/y">shop</a> <a href="https://example.org/affiliate/z">aff</a> <a href="https://friend.example/q">friend</a> <a href="/local">local</a> <a href="mailto:a@b.cz">mail</a> <a href="https://plain.example/b" class="wpe-exclude--link">skipped</a> <a href="https://plain.example/c" class="wpe-no-arrow">noarrow</a></p>';
$out  = $lm->process_content( $html );
$doc  = new DOMDocument(); @$doc->loadHTML( '<?xml encoding="UTF-8">' . $out ); $links = array(); foreach ( $doc->getElementsByTagName( 'a' ) as $a ) { $links[ trim( $a->textContent ) ] = $a; }
$attr = static fn( $name, $k ) => $links[ $name ]->getAttribute( $k );
$has_icon = static fn( $name ) => $links[ $name ]->getElementsByTagName( 'svg' )->length > 0;
$assert( 'content: a plain external link gets the default target, rel, an aria note and the arrow', '_blank' === $attr( 'plain', 'target' ) && 'noopener noreferrer nofollow' === $attr( 'plain', 'rel' ) && '' !== $attr( 'plain', 'aria-label' ) && $has_icon( 'plain' ) );
$assert( 'content: tab=no leaves the target, rel=none adds nothing, no aria note for a link that stays in place', '' === $attr( 'partner', 'target' ) && '' === $attr( 'partner', 'rel' ) && '' === $attr( 'partner', 'aria-label' ) && $has_icon( 'partner' ) );
$assert( 'content: a wildcard subdomain rule sets the rel tokens and switches the icon off', 'sponsored nofollow' === $attr( 'shop', 'rel' ) && '_blank' === $attr( 'shop', 'target' ) && ! $has_icon( 'shop' ) );
$assert( 'content: a path rule', 'sponsored' === $attr( 'aff', 'rel' ) && '_blank' === $attr( 'aff', 'target' ) );
$assert( 'content: an address on the never-external list, a relative link, mailto and an excluded class stay untouched', '' === $attr( 'friend', 'target' ) && '' === $attr( 'friend', 'rel' ) && '' === $attr( 'local', 'target' ) && '' === $attr( 'mail', 'target' ) && '' === $attr( 'skipped', 'target' ) && ! $has_icon( 'friend' ) && ! $has_icon( 'local' ) );
$assert( 'content: the per-link class still removes just the arrow', '_blank' === $attr( 'noarrow', 'target' ) && ! $has_icon( 'noarrow' ) );
$plain = '<p>No links here, &amp; entities stay as they are.</p>';
$assert( 'content without a link comes back unchanged (and is not parsed)', $plain === $lm->process_content( $plain ) && '' === $lm->process_content( '' ) );
$dec = new ReflectionProperty( $lm, 'decisions' ); $dec->setAccessible( true );
$many = ''; for ( $i = 0; $i < 400; $i++ ) { $many .= '<a href="https://plain.example/' . ( $i % 5 ) . '">x</a>'; }
$dec->setValue( $lm, array() );
$t0 = microtime( true ); $lm->process_content( $many ); $t = microtime( true ) - $t0;
$assert( 'performance: 400 links with 5 different addresses are decided 5 times, one pass', 5 === count( $dec->getValue( $lm ) ) && $t < 3.0, (string) $t );

$set( 'uxstudio_link_manager', array( 'icon_type' => 'dashicon', 'icon_dashicon' => 'dashicons-admin-links', 'icon_position' => 'left', 'default_target' => 'keep', 'default_rel' => 'noopener ugc', 'icon_skip_images' => true ) );
$lm = new UxStudio\Modules\LinkManager\Module( 'link-manager', array() );
$cl = new ReflectionProperty( $lm, 'exclude_classes' ); $cl->setAccessible( true ); $cl->setValue( $lm, $call( $lm, 'build_exclude_classes' ) );
$out = $lm->process_content( '<a href="https://x.example/">Text</a> <a href="https://y.example/"><img src="a.png"></a>' );
$doc = new DOMDocument(); @$doc->loadHTML( '<?xml encoding="UTF-8">' . $out ); $a = $doc->getElementsByTagName( 'a' );
$assert( 'icons: a Dashicon on the left, no target when the default is "keep", the chosen rel, no icon on image links; Dashicons are enqueued', '' === $a->item( 0 )->getAttribute( 'target' ) && 'noopener ugc' === $a->item( 0 )->getAttribute( 'rel' ) && 'span' === $a->item( 0 )->firstChild->nodeName && false !== strpos( $a->item( 0 )->firstChild->getAttribute( 'class' ), 'dashicons-admin-links' ) && 0 === $a->item( 1 )->getElementsByTagName( 'span' )->length && in_array( 'dashicons', $GLOBALS['uxs_enqueued'], true ), $out );
$set( 'uxstudio_link_manager', array( 'disable_arrow' => true ) );
$lm = new UxStudio\Modules\LinkManager\Module( 'link-manager', array() );
$assert( 'legacy: a site saved with "disable arrow" has no icon and shows "none" in the settings', 'none' === $lm->config()['icon'] && 'none' === $lm->settings_values()['icon_type'] );
$set( 'uxstudio_link_manager', array( 'default_rel' => '' ) );
$assert( 'rel: saved empty = no rel; never saved = the old three tokens', array() === ( new UxStudio\Modules\LinkManager\Module( 'link-manager', array() ) )->config()['rel'] );
$set( 'uxstudio_link_manager', array() );
$assert( 'rel: never saved = noopener noreferrer nofollow', array( 'noopener', 'noreferrer', 'nofollow' ) === ( new UxStudio\Modules\LinkManager\Module( 'link-manager', array() ) )->config()['rel'] );

/* ═══ Classic Editor: which editor opens ═══ */

$ctx = static fn( array $o = array() ) => $o + array( 'default_classic' => false, 'new_post' => false, 'forget' => false, 'qs_classic' => false, 'remembered' => '', 'has_blocks' => true );
$assert( 'default editor: the user choice wins over the site, anything else follows the site', EditorChoice::default_is_classic( false, 'classic' ) && ! EditorChoice::default_is_classic( true, 'block' ) && EditorChoice::default_is_classic( true, '' ) && ! EditorChoice::default_is_classic( false, 'nonsense' ) );
$assert( 'Add new: the default editor, unless the user picked one in the address', EditorChoice::is_classic( $ctx( array( 'new_post' => true, 'default_classic' => true ) ) ) && ! EditorChoice::is_classic( $ctx( array( 'new_post' => true ) ) ) && ! EditorChoice::is_classic( $ctx( array( 'new_post' => true, 'default_classic' => true, 'forget' => true ) ) ) && EditorChoice::is_classic( $ctx( array( 'new_post' => true, 'forget' => true, 'qs_classic' => true ) ) ) );
$assert( 'existing post: the remembered editor wins, nothing remembered = blocks decide', EditorChoice::is_classic( $ctx( array( 'remembered' => 'classic-editor' ) ) ) && ! EditorChoice::is_classic( $ctx( array( 'remembered' => 'block-editor', 'default_classic' => true ) ) ) && EditorChoice::is_classic( $ctx( array( 'has_blocks' => false ) ) ) && ! EditorChoice::is_classic( $ctx( array( 'has_blocks' => true, 'default_classic' => true ) ) ) );
$assert( 'existing post: an explicit choice in the address (forget) beats the remembered one', EditorChoice::is_classic( $ctx( array( 'forget' => true, 'qs_classic' => true, 'remembered' => 'block-editor' ) ) ) && ! EditorChoice::is_classic( $ctx( array( 'forget' => true, 'remembered' => 'classic-editor', 'has_blocks' => false ) ) ) );
$assert( 'profile value: only classic and block are stored, the rest means "follow the site"', 'classic' === EditorChoice::clean_choice( 'classic' ) && 'block' === EditorChoice::clean_choice( 'block' ) && '' === EditorChoice::clean_choice( '' ) && '' === EditorChoice::clean_choice( '<script>' ) );
$assert( 'the stored keys are those of the Classic Editor plugin (nothing to copy when migrating)', 'classic-editor-settings' === EditorChoice::USER_OPTION && 'classic-editor-remember' === EditorChoice::REMEMBER_META );

$set( 'uxstudio_classic_editor', array( 'allow_users' => true, 'post_types' => array( 'page' ) ) );
$ce = new UxStudio\Modules\ClassicEditor\Module( 'classic-editor', array() );
$p = new WP_Post(); $p->ID = 30; $p->post_type = 'page'; $p->post_status = 'publish'; $p->post_content = '<!-- wp:paragraph --><p>x</p><!-- /wp:paragraph -->';
$GLOBALS['uxs_usermeta'] = array();
$_GET = array();
$r1 = $ce->choose_editor( true, $p );                        // existing page with blocks, nothing remembered -> blocks decide -> block
$p2 = clone $p; $p2->ID = 31; $p2->post_status = 'auto-draft'; $p2->post_content = '';
$r2 = $ce->choose_editor( true, $p2 );                       // Add new, page default classic
$p3 = clone $p2; $p3->ID = 32; $p3->post_type = 'post';
$r3 = $ce->choose_editor( true, $p3 );                       // Add new, post default block (not selected)
$GLOBALS['uxs_usermeta']['classic-editor-settings'] = 'classic';
$r4 = $ce->choose_editor( true, $p3 );                       // the user prefers classic
$GLOBALS['uxs_usermeta']['classic-editor-settings'] = 'block';
$r5 = $ce->choose_editor( true, $p2 );                       // the user prefers block for pages
$GLOBALS['uxs_meta'][30]['classic-editor-remember'] = array( 'classic-editor' );
$r6 = $ce->choose_editor( true, $p );                        // remembered classic
$_GET['classic-editor__forget'] = '';
$r7 = $ce->choose_editor( true, $p );                        // explicit: forget + no classic -> block
$_GET['classic-editor'] = '';
$r8 = $ce->choose_editor( true, $p );                        // explicit classic
$_GET = array();
$r9 = $ce->choose_editor( false, $p );                       // WordPress said no block editor for this type: stays false
$p4 = clone $p; $p4->post_type = 'portfolio';
$r10 = $ce->choose_editor( true, $p4 );                      // a type without the block editor: pass through
$assert( 'module: choose_editor matrix (existing with blocks -> block; Add new page -> classic; Add new post -> block; user classic -> classic; user block on a new page -> block; remembered classic -> classic; explicit block; explicit classic; WordPress forbids blocks; type without blocks)',
	array( true, false, true, false, true, false, true, false, false, true ) === array( $r1, $r2, $r3, $r4, $r5, $r6, $r7, $r8, $r9, $r10 ), json_encode( array( $r1, $r2, $r3, $r4, $r5, $r6, $r7, $r8, $r9, $r10 ) ) );
$set( 'uxstudio_classic_editor', array( 'default_editor' => 'block', 'post_types' => array( 'page' ) ) );
$ce = new UxStudio\Modules\ClassicEditor\Module( 'classic-editor', array() );
$assert( 'module: the block default means the site default is block for every type', ! $ce->site_default_is_classic( 'page' ) && ! $ce->site_default_is_classic( 'post' ) );
$set( 'uxstudio_classic_editor', array( 'post_types' => array() ) );
$ce = new UxStudio\Modules\ClassicEditor\Module( 'classic-editor', array() );
$assert( 'module: no types selected = classic everywhere (the behaviour before the switch existed)', $ce->site_default_is_classic( 'page' ) && $ce->site_default_is_classic( 'post' ) && ! $ce->users_may_choose() );

/* ═══ Maintenance Mode ═══ */

$pr = M::parse_paths( "# comment\n/kontakt\n/o-nas/*\n*feed*\nregex:^/(en|de)/\nregex:([bad\n/a, /b\n" );
$assert( 'paths: exact, star, regex, commas; an invalid regex is reported; comments skipped', 6 === count( $pr['rules'] ) && array( 'regex:([bad' ) === $pr['invalid'], json_encode( $pr ) );
$ep = static fn( string $path ) => M::path_excepted( M::request_path( $path, '/site' ), $pr['rules'] );
$assert( 'exceptions: exact (slash and case do not matter), the page itself and below for /x/*, substring stars, regex', $ep( '/site/kontakt' ) && $ep( '/site/Kontakt/' ) && ! $ep( '/site/kontakt/pobocka' ) && $ep( '/site/o-nas' ) && $ep( '/site/o-nas/tym' ) && ! $ep( '/site/o-nasi' ) && $ep( '/site/comments/feed/' ) && $ep( '/site/en/page' ) && $ep( '/site/de/' ) && ! $ep( '/site/fr/' ) && $ep( '/site/a' ) && $ep( '/site/b?x=1' ) && ! $ep( '/site/c' ) );
$assert( 'request path: the site directory and the query string are removed', '/kontakt' === M::request_path( '/site/kontakt/?a=1', '/site' ) && '/' === M::request_path( '/site/', '/site' ) && '/x' === M::request_path( '/x', '' ) && '/' === M::request_path( '/site', '/site' ) );
$assert( 'IP list: addresses and ranges, comments and rubbish left out', array( '203.0.113.7', '203.0.113.0/24', '2001:db8::/32' ) === M::parse_ips( "203.0.113.7, 203.0.113.0/24\n# office\nnonsense 2001:db8::/32 300.1.1.1 1.2.3.4/99" ), json_encode( M::parse_ips( "203.0.113.7, 203.0.113.0/24\n# office\nnonsense 2001:db8::/32 300.1.1.1 1.2.3.4/99" ) ) );
$tz = new DateTimeZone( 'Europe/Prague' );
$ts = M::parse_end( '2026-12-24T18:00', $tz );
$assert( 'countdown end: parsed in the time of the site (Prague winter = UTC+1), seconds optional, rubbish refused', 1798131600 === $ts && $ts === M::parse_end( '2026-12-24 18:00:00', $tz ) && null === M::parse_end( '', $tz ) && null === M::parse_end( '2026-13-40T99:00', $tz ) && null === M::parse_end( 'tomorrow', $tz ), (string) $ts );
$assert( 'clean_end: the form value is normalised, rubbish becomes empty', '2026-12-24T18:00' === M::clean_end( '2026-12-24T18:00:30' ) && '2026-12-24T18:00' === M::clean_end( '2026-12-24 18:00' ) && '' === M::clean_end( '2026-02-30T10:00' ) && '' === M::clean_end( 'x' ) );
$assert( 'auto-disable: only when switched on, an end exists and it has passed', M::expired( 100, true, 100 ) && M::expired( 99, true, 100 ) && ! M::expired( 101, true, 100 ) && ! M::expired( 50, false, 100 ) && ! M::expired( null, true, 100 ) );
$assert( 'Retry-After: until the end, an hour when unknown or passed, never more than 30 days', 600 === M::retry_after( 1600, 1000 ) && 3600 === M::retry_after( null, 1000 ) && 3600 === M::retry_after( 900, 1000 ) && 30 * 86400 === M::retry_after( 1000 + 90 * 86400, 1000 ) );
$assert( 'split: days, hours, minutes, seconds; negative counts as zero', array( 'd' => 2, 'h' => 3, 'm' => 4, 's' => 5 ) === M::split( 2 * 86400 + 3 * 3600 + 4 * 60 + 5 ) && array( 'd' => 0, 'h' => 0, 'm' => 0, 's' => 0 ) === M::split( -5 ) );

$mm = static function ( array $settings ) use ( $set ) {
	$set( 'uxstudio_maintenance_mode', $settings );
	return new UxStudio\Modules\MaintenanceMode\Module( 'maintenance-mode', array() );
};
$_SERVER['REQUEST_URI'] = '/site/kontakt/';
$GLOBALS['uxs_is_feed'] = false;
$m = $mm( array( 'mode' => 'maintenance', 'exclude_paths' => "/kontakt\n/o-nas/*" ) );
$assert( 'module: a listed address is excepted, another is not', true === $call( $m, 'request_excepted' ) );
$_SERVER['REQUEST_URI'] = '/site/cenik/';
$assert( 'module: ... another address is not', false === $call( $m, 'request_excepted' ) );
$GLOBALS['uxs_is_feed'] = true;
$assert( 'module: feeds stay open only with the switch', false === $call( $m, 'request_excepted' ) && true === $call( $mm( array( 'allow_feeds' => true ) ), 'request_excepted' ) );
$GLOBALS['uxs_is_feed'] = false;
$_SERVER['REMOTE_ADDR'] = '203.0.113.9';
$m = $mm( array( 'bypass_ips' => "203.0.113.0/24\n198.51.100.4" ) );
$a = $call( $m, 'ip_bypassed' );
$_SERVER['REMOTE_ADDR'] = '198.51.100.5';
$b = $call( $m, 'ip_bypassed' );
$assert( 'module: a visitor from a listed range passes, from another address does not', $a && ! $b );
$future = time() + 3600; $past = time() - 3600;
$fmt = static fn( int $t ) => ( new DateTimeImmutable( '@' . $t ) )->setTimezone( new DateTimeZone( 'Europe/Prague' ) )->format( 'Y-m-d\TH:i' );
$m = $mm( array( 'mode' => 'maintenance', 'countdown_end' => $fmt( $past ), 'countdown_auto_disable' => true ) );
$assert( 'module: auto-disable on and the end passed = the mode in force is disabled, the saved mode stays', 'disabled' === $call( $m, 'get_mode' ) && 'maintenance' === $call( $m, 'configured_mode' ) );
$m = $mm( array( 'mode' => 'maintenance', 'countdown_end' => $fmt( $past ), 'countdown_auto_disable' => false ) );
$assert( 'module: ... default off keeps the maintenance mode after the end', 'maintenance' === $call( $m, 'get_mode' ) );
$m = $mm( array( 'mode' => 'maintenance', 'countdown_end' => $fmt( $future ), 'countdown_auto_disable' => true ) );
$assert( 'module: ... and before the end', 'maintenance' === $call( $m, 'get_mode' ) );
$m = $mm( array( 'countdown_enabled' => true, 'countdown_end' => $fmt( $future ) ) );
$cd = $call( $m, 'countdown_data', false );
$assert( 'module: countdown data = seconds left and the end in text', true === $cd['enabled'] && $cd['remaining'] > 3500 && $cd['remaining'] <= 3600 && '' !== $cd['end_label'] );
$assert( 'module: no countdown on an existing WordPress page, none without a date or switch', false === $call( $m, 'countdown_data', true )['enabled'] && false === $call( $mm( array( 'countdown_enabled' => true ) ), 'countdown_data', false )['enabled'] && false === $call( $mm( array( 'countdown_end' => $fmt( $future ) ) ), 'countdown_data', false )['enabled'] );

/* ═══ Folder Manager model ═══ */

$GLOBALS['uxs_tstore'] = array(); $GLOBALS['uxs_tmeta'] = array();
$set( 'uxstudio_folder_manager', array( 'post_types' => array( 'attachment', 'page', 'post' ) ) );
$fm = new UxStudio\Modules\FolderManager\Module( 'folder-manager', array() );
$names = static fn( array $list ) => array_column( $list, 'name' );
$a  = $fm->create_folder( 'Alfa', 0, 'page' );
$b  = $fm->create_folder( 'Beta', 0, 'page' );
$c  = $fm->create_folder( 'Gama', 0, 'page' );
$m1 = $fm->create_folder( 'Media', 0, 'attachment' );
$assert( 'folders: a folder belongs to one post type; new folders go to the end of their level (order 1, 2, 3)', array( 1, 2, 3 ) === array( $a['order'], $b['order'], $c['order'] ) && 'page' === $a['post_type'] && 1 === $m1['order'] && 'attachment' === $m1['post_type'] );
$assert( 'folders: the list of one post type is in the manual order', array( 'Alfa', 'Beta', 'Gama' ) === $names( $fm->list_folders( 'page' ) ) && array( 'Media' ) === $names( $fm->list_folders( 'attachment' ) ) );
$moved = $fm->move_folder( $c['id'], 'up' );
$assert( 'order: one place up swaps with the neighbour', array( 'Alfa', 'Gama', 'Beta' ) === $names( $moved ), json_encode( $names( $moved ) ) );
$fm->move_folder( $c['id'], 'up' ); $moved = $fm->move_folder( $c['id'], 'up' );
$assert( 'order: the first folder cannot go further up, down moves it back', array( 'Gama', 'Alfa', 'Beta' ) === $names( $moved ) && array( 'Alfa', 'Gama', 'Beta' ) === $names( $fm->move_folder( $c['id'], 'down' ) ) );
$moved = $fm->reorder_folders( array( $b['id'], $a['id'] ) );
$assert( 'order: an explicit order puts the listed folders first, the others after them', array( 'Beta', 'Alfa', 'Gama' ) === $names( $moved ), json_encode( $names( $moved ) ) );
$assert( 'order: folders of different levels or post types cannot be ordered together', is_wp_error( $fm->reorder_folders( array( $a['id'], $m1['id'] ) ) ) && is_wp_error( $fm->reorder_folders( array() ) ) && is_wp_error( $fm->move_folder( $a['id'], 'sideways' ) ) && is_wp_error( $fm->move_folder( 99999, 'up' ) ) );
$kid = $fm->create_folder( 'Dite', $a['id'], 'page' );
$top = static fn() => array_map( static fn( int $id ) => get_term( $id )->name, $fm->sibling_ids( 0, 'page' ) );
$assert( 'order: a child has its own level (it starts at 1 under its parent)', 1 === $kid['order'] && array( 'Dite' ) === array_map( static fn( int $id ) => get_term( $id )->name, $fm->sibling_ids( $a['id'], 'page' ) ) && array( 'Beta', 'Alfa', 'Gama' ) === $top() );
$fm->move_folder( $a['id'], 'down' );
$assert( 'order: moving a folder down changes only its level', array( 'Beta', 'Gama', 'Alfa' ) === $top() && array( 'Dite' ) === array_map( static fn( int $id ) => get_term( $id )->name, $fm->sibling_ids( $a['id'], 'page' ) ), json_encode( $top() ) );
$r = $fm->reparent_folder( $kid['id'], 0 );
$assert( 'reparent: a folder moved to another level goes to the end of that level', $r['order'] > 3 && 0 === $r['parent'], json_encode( $r ) );
$col = $fm->set_color( $a['id'], '#ff8800' );
$assert( 'colour: stored as given, shown in the list', '#ff8800' === $col['color'] && '#ff8800' === $fm->list_folders( 'page' )[ array_search( $a['id'], array_column( $fm->list_folders( 'page' ), 'id' ), true ) ]['color'] );
$assert( 'colour: rubbish is refused, empty removes the colour, an unknown folder is an error', is_wp_error( $fm->set_color( $a['id'], 'red' ) ) && is_wp_error( $fm->set_color( $a['id'], 'javascript:1' ) ) && '' === $fm->set_color( $a['id'], '' )['color'] && is_wp_error( $fm->set_color( 99999, '#fff' ) ) );
$assert( 'media folders are exactly as before: no colour, listed under attachment, the post type default for old folders', '' === $m1['color'] && 'attachment' === $fm->folder_post_type( $m1['id'] ) );
$old = wp_insert_term( 'Stara', 'uxstudio_media_folder', array() )['term_id'];
$assert( 'old folders without the new meta have order 0 and no colour and still list (by name) before newly ordered ones', 'Stara' === $names( $fm->list_folders( 'attachment' ) )[0] && 0 === $fm->folder_order( $old ) && '' === $fm->folder_color( $old ) );

echo PHP_EOL . ( $failures ? "$failures FAILED" : 'ALL PASSED' ) . PHP_EOL;
exit( $failures ? 1 : 0 );
