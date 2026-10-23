<?php
/**
 * Standalone test of the snippet features of PLAN.md 27, A2: the [uxstudio_snippet] shortcode, the placement before / after
 * the post content and run once. Real snippet files (header, integrity hash) are written to a scratch folder and rendered
 * through the real SnippetManager / SnippetRenderer / SnippetExecutor on an in-memory metadata table. Needs no WordPress and no
 * database:
 *
 *   php tests/snippets-features.php
 *
 * @package UxStudio
 */

// phpcs:disable

define( 'ABSPATH', __DIR__ . '/' );
define( 'WP_CONTENT_DIR', __DIR__ . '/.tmp-snippets-features' );
define( 'DAY_IN_SECONDS', 86400 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'ARRAY_A', 'ARRAY_A' );
define( 'UXSTUDIO_PATH', dirname( __DIR__ ) . '/' );

$scratch = WP_CONTENT_DIR;
$rm      = static function ( string $dir ) use ( &$rm ): void {
	foreach ( glob( $dir . '/{,.}*', GLOB_BRACE ) ?: array() as $f ) {
		if ( in_array( basename( $f ), array( '.', '..' ), true ) ) { continue; }
		is_dir( $f ) ? $rm( $f ) : unlink( $f );
	}
	@rmdir( $dir );
};
$rm( $scratch );
mkdir( $scratch, 0777, true );
register_shutdown_function( static function () use ( $rm, $scratch ) { $rm( $scratch ); } );

$GLOBALS['uxs_rows']    = array();
$GLOBALS['uxs_options'] = array();
$GLOBALS['uxs_hooks']   = array();
$GLOBALS['uxs_logs']    = array();
$GLOBALS['uxs_env']     = array( 'admin' => false, 'singular' => true, 'loop' => true, 'main' => true, 'feed' => false, 'post_id' => 7, 'queried' => 7, 'post_type' => 'post', 'can' => true, 'ajax' => false );

class FakeSnippetsDb {
	public $prefix = 'wp_';
	public $insert_id = 0;
	public function prepare( $sql, ...$args ) {
		if ( 1 === count( $args ) && is_array( $args[0] ) ) { $args = $args[0]; }
		$i = 0;
		return preg_replace_callback( '/%[sd]/', static function ( $m ) use ( &$i, $args ) {
			$v = $args[ $i++ ] ?? '';
			return '%d' === $m[0] ? (string) (int) $v : "'" . addslashes( (string) $v ) . "'";
		}, $sql );
	}
	public function insert( $table, $data, $formats = null ) {
		if ( false !== strpos( $table, 'activity_log' ) ) { $GLOBALS['uxs_logs'][] = $data; return 1; }
		$id = count( $GLOBALS['uxs_rows'] ) + 1;
		$GLOBALS['uxs_rows'][ $id ] = array( 'id' => $id, 'last_run' => null, 'run_result' => null ) + $data;
		$this->insert_id = $id;
		return 1;
	}
	public function update( $table, $data, $where, $f = null, $wf = null ) {
		$id = (int) $where['id'];
		if ( ! isset( $GLOBALS['uxs_rows'][ $id ] ) ) { return 0; }
		$GLOBALS['uxs_rows'][ $id ] = $data + $GLOBALS['uxs_rows'][ $id ];
		return 1;
	}
	public function delete( $table, $where, $f = null ) { unset( $GLOBALS['uxs_rows'][ (int) $where['id'] ] ); return 1; }
	public function get_row( $sql, $output = null ) {
		if ( preg_match( '/WHERE id = (\d+)/', $sql, $m ) ) { return $GLOBALS['uxs_rows'][ (int) $m[1] ] ?? null; }
		return null;
	}
	public function get_var( $sql ) {
		if ( preg_match( "/LOWER\(title\) = LOWER\('([^']*)'\)/", $sql, $m ) ) {
			$n = 0;
			foreach ( $GLOBALS['uxs_rows'] as $r ) { $n += 0 === strcasecmp( $r['title'], stripslashes( $m[1] ) ) ? 1 : 0; }
			return $n;
		}
		return 0;
	}
	public function get_results( $sql, $output = null ) {
		$rows = array_values( $GLOBALS['uxs_rows'] );
		if ( false !== strpos( $sql, 'enabled = 1' ) ) { $rows = array_values( array_filter( $rows, static fn( $r ) => (int) $r['enabled'] ) ); }
		return $rows;
	}
	/** The atomic claim of a run-once snippet. */
	public function query( $sql ) {
		if ( preg_match( "/UPDATE \S+ SET enabled = 0, updated_at = '[^']*' WHERE id = (\d+) AND enabled = 1 AND run_location = 'run_once'/", $sql, $m ) ) {
			$r = $GLOBALS['uxs_rows'][ (int) $m[1] ] ?? null;
			if ( $r && (int) $r['enabled'] && 'run_once' === $r['run_location'] ) { $GLOBALS['uxs_rows'][ (int) $m[1] ]['enabled'] = 0; return 1; }
		}
		return 0;
	}
}
$GLOBALS['wpdb'] = new FakeSnippetsDb();

function get_option( $n, $d = false ) { return array_key_exists( $n, $GLOBALS['uxs_options'] ) ? $GLOBALS['uxs_options'][ $n ] : $d; }
function apply_filters( $h, $v, ...$a ) { return $v; }
function __( $t, $d = '' ) { return $t; }
function _n( $s, $p, $n, $d = '' ) { return 1 === $n ? $s : $p; }
function is_wp_error( $x ) { return $x instanceof WP_Error; }
function current_time( $t ) { return '2026-10-22 12:00:00'; }
function sanitize_key( $s ) { return strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', (string) $s ) ); }
function sanitize_text_field( $s ) { return trim( strip_tags( (string) $s ) ); }
function sanitize_textarea_field( $s ) { return trim( strip_tags( (string) $s ) ); }
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function wp_salt( $s = '' ) { return 'test-salt-' . $s; }
function wp_json_encode( $v ) { return json_encode( $v ); }
function wp_mkdir_p( $d ) { return is_dir( $d ) || mkdir( $d, 0777, true ); }
function wp_get_current_user() { return (object) array( 'user_login' => 'tester' ); }
function get_current_user_id() { return 1; }
function post_type_exists( $t ) { return in_array( $t, array( 'post', 'page', 'product' ), true ); }
function is_admin() { return $GLOBALS['uxs_env']['admin']; }
function is_feed() { return $GLOBALS['uxs_env']['feed']; }
function is_singular() { return $GLOBALS['uxs_env']['singular']; }
function in_the_loop() { return $GLOBALS['uxs_env']['loop']; }
function is_main_query() { return $GLOBALS['uxs_env']['main']; }
function get_the_ID() { return $GLOBALS['uxs_env']['post_id']; }
function get_queried_object_id() { return $GLOBALS['uxs_env']['queried']; }
function get_post_type() { return $GLOBALS['uxs_env']['post_type']; }
function current_user_can( $c ) { return $GLOBALS['uxs_env']['can']; }
function is_user_logged_in() { return $GLOBALS['uxs_env']['can']; }
function wp_doing_ajax() { return $GLOBALS['uxs_env']['ajax']; }
function wp_is_json_request() { return false; }
function add_shortcode( $tag, $cb ) { $GLOBALS['uxs_hooks']['shortcode'][ $tag ] = $cb; }
function add_filter( $h, $cb, $p = 10, $n = 1 ) { $GLOBALS['uxs_hooks']['filter'][ $h ][] = $cb; }
function add_action( $h, $cb, $p = 10, $n = 1 ) { $GLOBALS['uxs_hooks']['action'][ $h ][] = $cb; }

class WP_Error { public $m; public function __construct( $c = '', $m = '' ) { $this->m = $m; } public function get_error_message() { return $this->m; } }

require_once dirname( __DIR__ ) . '/includes/Autoloader.php';
\UxStudio\Autoloader::register();

use UxStudio\Core\Settings;
use UxStudio\Modules\CodeSnippets\SnippetExecutor;
use UxStudio\Modules\CodeSnippets\SnippetManager;
use UxStudio\Modules\CodeSnippets\SnippetRenderer;

$failures = 0;
$assert   = static function ( string $name, bool $ok, string $detail = '' ) use ( &$failures ): void {
	if ( ! $ok ) {
		++$failures;
	}
	echo ( $ok ? 'PASS ' : 'FAIL ' ) . $name . ( $ok || '' === $detail ? '' : ' => ' . $detail ) . PHP_EOL;
};
$env = static function ( array $over ): void { $GLOBALS['uxs_env'] = $over + $GLOBALS['uxs_env']; };
// The manager remembers the enabled snippets for one request; a new request starts without that memory.
$new_request = static function (): void {
	$p = new ReflectionProperty( SnippetManager::class, 'enabledCache' );
	$p->setAccessible( true );
	$p->setValue( null, null );
};

$settings = new Settings( 'uxstudio_code_snippets' );
$manager  = new SnippetManager();
$create   = static function ( string $name, string $type, string $code, string $location, bool $enabled = true, array $extra = array() ) use ( $manager ): array {
	return $manager->createSnippet( array( 'name' => $name, 'type' => $type, 'code' => $code, 'run_location' => $location, 'enabled' => $enabled ) + $extra );
};
$id_of = static fn( array $r ): string => (string) ( $r['data']['id'] ?? '' );

/* ── Validation of the new run locations ── */

$assert( 'locations per type: every type has shortcode and both placements, only PHP can run once',
	in_array( 'shortcode', SnippetManager::allowedLocations( 'html' ), true ) && in_array( 'before_content', SnippetManager::allowedLocations( 'css' ), true ) && in_array( 'after_content', SnippetManager::allowedLocations( 'js' ), true )
	&& in_array( 'run_once', SnippetManager::allowedLocations( 'php' ), true ) && ! in_array( 'run_once', SnippetManager::allowedLocations( 'html' ), true ) && ! in_array( 'run_once', SnippetManager::allowedLocations( 'js' ), true ) );
$assert( 'post types: unknown ones dropped, the default pair when nothing valid is left', array( 'post', 'product' ) === SnippetManager::sanitizePostTypes( array( 'post', 'nonsense', 'product' ) ) && array( 'post', 'page' ) === SnippetManager::sanitizePostTypes( array() ) && array( 'post', 'page' ) === SnippetManager::sanitizePostTypes( 'nonsense' ) && array( 'page' ) === SnippetManager::sanitizePostTypes( 'page' ) );

$r = $create( 'Jednou HTML', 'html', '<p>x</p>', 'run_once' );
$assert( 'run once is refused for HTML (only PHP can run)', ! $r['success'] && false !== strpos( $r['message'], 'Only PHP' ), json_encode( $r['message'] ) );
$r = $create( 'Divne misto', 'php', 'echo 1;', 'site_header' );
$assert( 'a place that does not exist for the type is refused (an unknown PHP place used to mean "everywhere")', ! $r['success'], json_encode( $r['message'] ) );
$r = $create( 'Divne misto 2', 'php', 'echo 1;', 'nonsense' );
$assert( 'an unknown place is refused', ! $r['success'] );
$assert( 'nothing was written by the refused snippets (no rows, no snippet files)', array() === $GLOBALS['uxs_rows'] && array() === glob( $scratch . '/uxstudio-snippets/[0-9]*.php' ) );

/* ── Fixtures ── */

$sc_html  = $create( 'Shortcode html', 'html', '<b>Ahoj z HTML</b>', 'shortcode' );
$sc_php   = $create( 'Shortcode php', 'php', 'echo "Rok: " . ( $atts["rok"] ?? "?" ) . " obsah=" . $content; $GLOBALS["uxs_ran_shortcode"] = ( $GLOBALS["uxs_ran_shortcode"] ?? 0 ) + 1;', 'shortcode' );
$sc_mixed = $create( 'Shortcode smisene', 'php', "?>\n<i>smisene</i> <?php echo 'ok'; ?>", 'shortcode' );
$sc_js    = $create( 'Shortcode js', 'js', 'console.log(1);', 'shortcode' );
$sc_css   = $create( 'Shortcode css', 'css', 'a{color:red}', 'shortcode' );
$sc_off   = $create( 'Shortcode vypnuty', 'html', '<u>vypnuto</u>', 'shortcode', false );
$hook_html = $create( 'Hlavicka', 'html', '<meta name="x">', 'site_header' );
$before   = $create( 'Pred obsahem', 'html', '<div class="pred">PRED</div>', 'before_content', true, array( 'post_types' => array( 'post' ) ) );
$after    = $create( 'Za obsahem php', 'php', 'echo "<div class=\"za\">ZA</div>";', 'after_content', true, array( 'post_types' => array( 'post', 'page' ) ) );
$after_pg = $create( 'Za obsahem stranky', 'html', '<div class="za-stranka">ZA STRANKA</div>', 'after_content', true, array( 'post_types' => array( 'page' ) ) );
$every    = $create( 'Vsude php', 'php', '$GLOBALS["uxs_ran_everywhere"] = ( $GLOBALS["uxs_ran_everywhere"] ?? 0 ) + 1;', 'everywhere' );
$once     = $create( 'Jednou php', 'php', '$GLOBALS["uxs_ran_once"] = ( $GLOBALS["uxs_ran_once"] ?? 0 ) + 1; echo "hotovo";', 'run_once' );
$once_bad = $create( 'Jednou chyba', 'php', 'throw new \RuntimeException("selhalo");', 'run_once' );

$all_ok = true;
foreach ( array( $sc_html, $sc_php, $sc_mixed, $sc_js, $sc_css, $sc_off, $hook_html, $before, $after, $after_pg, $every, $once, $once_bad ) as $r ) {
	$all_ok = $all_ok && $r['success'];
}
$assert( 'all fixtures are created (PHP passes the validator, also HTML-first PHP that starts with a close tag)', $all_ok, json_encode( array_map( static fn( $r ) => $r['message'], array( $sc_html, $sc_php, $sc_mixed, $sc_js, $sc_css, $sc_off, $hook_html, $before, $after, $after_pg, $every, $once, $once_bad ) ) ) );
$assert( 'before / after-content rows keep their post types, other rows have none', 'post' === $GLOBALS['uxs_rows'][ (int) $id_of( $before ) ]['post_types'] && 'post,page' === $GLOBALS['uxs_rows'][ (int) $id_of( $after ) ]['post_types'] && '' === $GLOBALS['uxs_rows'][ (int) $id_of( $sc_html ) ]['post_types'] );
$asc = $manager->getSnippet( $id_of( $sc_html ) )->toApiArray();
$assert( 'API shows the exact shortcode for a shortcode snippet and none for others', '[uxstudio_snippet id="' . $id_of( $sc_html ) . '"]' === $asc['shortcode'] && '' === $manager->getSnippet( $id_of( $hook_html ) )->toApiArray()['shortcode'] );
$assert( 'API shows post types for placements', array( 'post', 'page' ) === $manager->getSnippet( $id_of( $after ) )->toApiArray()['post_types'] );

/* ── Shortcode ── */

$renderer = new SnippetRenderer( $settings );
$renderer->register();
$sc = static fn( string $id, array $atts = array(), string $content = '' ): string => $renderer->shortcode( array( 'id' => $id ) + $atts, $content );
$assert( 'the shortcode and the content filter are registered', isset( $GLOBALS['uxs_hooks']['shortcode']['uxstudio_snippet'] ) && ! empty( $GLOBALS['uxs_hooks']['filter']['the_content'] ) );
$assert( 'HTML snippet renders its HTML', false !== strpos( $sc( $id_of( $sc_html ) ), '<b>Ahoj z HTML</b>' ) );
$assert( 'PHP snippet is executed, its echo is returned (output buffered, not printed) and gets $atts and $content', 'Rok: 2026 obsah=vnitrek' === trim( $sc( $id_of( $sc_php ), array( 'rok' => '2026' ), 'vnitrek' ) ) && 1 === $GLOBALS['uxs_ran_shortcode'] );
$assert( 'PHP snippet that starts in HTML mode renders HTML and PHP parts', '<i>smisene</i> ok' === trim( $sc( $id_of( $sc_mixed ) ) ), $sc( $id_of( $sc_mixed ) ) );
$assert( 'JS and CSS snippets are wrapped in their tags', "<script>\nconsole.log(1);\n</script>" === $sc( $id_of( $sc_js ) ) && "<style>\na{color:red}\n</style>" === $sc( $id_of( $sc_css ) ) );
$assert( 'a disabled snippet renders nothing', '' === $sc( $id_of( $sc_off ) ) );
$assert( 'unknown id, id 0 and a missing id render nothing', '' === $sc( '9999' ) && '' === $sc( '0' ) && '' === $renderer->shortcode( array(), '' ) && '' === $renderer->shortcode( '', '' ) );
$assert( 'a snippet whose place is not "shortcode" cannot be reached through the shortcode (hook, placement, run once)', '' === $sc( $id_of( $hook_html ) ) && '' === $sc( $id_of( $before ) ) && '' === $sc( $id_of( $every ) ) && '' === $sc( $id_of( $once ) ) );
$assert( 'the shortcode does not accept a name or other attribute instead of id', '' === $renderer->shortcode( array( 'name' => 'Shortcode html' ), '' ) );

// Integrity: a file changed outside the application must not run.
$tamper = $manager->getSnippet( $id_of( $sc_php ) );
$file   = $tamper->getFilePath();
$orig   = file_get_contents( $file );
file_put_contents( $file, str_replace( 'Rok: ', 'EVIL: ', $orig ) );
$new_request();
$GLOBALS['uxs_ran_shortcode'] = 0;
$before_runs = $GLOBALS['uxs_ran_shortcode'];
$assert( 'a PHP file that was edited on disk (integrity hash no longer matches) is NOT executed', '' === $sc( $id_of( $sc_php ), array( 'rok' => '1' ) ) && $before_runs === $GLOBALS['uxs_ran_shortcode'] );
file_put_contents( $file, $orig );
$new_request();
$assert( 'restored file runs again', 'Rok: 5 obsah=' === trim( $sc( $id_of( $sc_php ), array( 'rok' => '5' ) ) ) );

// Path containment: a row that points outside the snippets folder never renders.
$sc_html_path = $GLOBALS['uxs_rows'][ (int) $id_of( $sc_html ) ]['file_path'];
$GLOBALS['uxs_rows'][ (int) $id_of( $sc_html ) ]['file_path'] = dirname( __DIR__ ) . '/ux-studio.php';
$new_request();
$assert( 'a snippet row pointing outside the snippets folder is not rendered', '' === $sc( $id_of( $sc_html ) ) );
$GLOBALS['uxs_rows'][ (int) $id_of( $sc_html ) ]['file_path'] = $sc_html_path;
$new_request();
$assert( 'path restored', false !== strpos( $sc( $id_of( $sc_html ) ), 'Ahoj' ) );

// Recursion: a snippet that renders itself yields nothing for the inner call.
$rec = $create( 'Rekurze', 'php', 'echo "A[" . $GLOBALS["uxs_renderer"]->shortcode( array( "id" => "' . ( count( $GLOBALS['uxs_rows'] ) + 1 ) . '" ), "" ) . "]";', 'shortcode' );
$GLOBALS['uxs_renderer'] = $renderer;
$assert( 'a snippet cannot include itself (recursion guard): the inner call is empty, the page survives', 'A[]' === trim( $sc( $id_of( $rec ) ) ), $sc( $id_of( $rec ) ) );

// Safe mode silences everything.
$GLOBALS['uxs_options']['uxstudio_code_snippets'] = array( 'safe_mode' => true );
$safe_settings = new Settings( 'uxstudio_code_snippets' );
$safe          = new SnippetRenderer( $safe_settings );
$assert( 'safe mode (module setting) silences shortcode snippets', '' === $safe->shortcode( array( 'id' => $id_of( $sc_html ) ), '' ) );
$GLOBALS['uxs_options']['uxstudio_code_snippets'] = array();

/* ── Placement before / after the content ── */

$filter = static fn( string $c = '<p>Obsah</p>' ): string => $renderer->filter_content( $c );
$out    = $filter();
$assert( 'post: the "before" snippet is put in front, the "after" PHP snippet behind, the page-only one is not used', 0 === strpos( trim( $out ), '<div class="pred">PRED</div>' ) && str_ends_with( trim( $out ), '<div class="za">ZA</div>' ) && false !== strpos( $out, '<p>Obsah</p>' ) && false === strpos( $out, 'ZA STRANKA' ), $out );
$env( array( 'post_type' => 'page' ) );
$out = $filter();
$assert( 'page: before snippet (post only) is absent, both after snippets are there', false === strpos( $out, 'PRED' ) && false !== strpos( $out, 'class="za"' ) && false !== strpos( $out, 'ZA STRANKA' ), $out );
$env( array( 'post_type' => 'product' ) );
$assert( 'a post type that is not selected is left alone', '<p>Obsah</p>' === $filter() );
$env( array( 'post_type' => 'post', 'singular' => false ) );
$assert( 'not on archives / lists (not singular)', '<p>Obsah</p>' === $filter() );
$env( array( 'singular' => true, 'loop' => false ) );
$assert( 'not outside the loop', '<p>Obsah</p>' === $filter() );
$env( array( 'loop' => true, 'main' => false ) );
$assert( 'not in secondary queries', '<p>Obsah</p>' === $filter() );
$env( array( 'main' => true, 'post_id' => 99 ) );
$assert( 'not for the content of another post printed inside the main loop (related posts)', '<p>Obsah</p>' === $filter() );
$env( array( 'post_id' => 7, 'feed' => true ) );
$assert( 'not in feeds', '<p>Obsah</p>' === $filter() );
$env( array( 'feed' => false, 'admin' => true ) );
$assert( 'not in wp-admin', '<p>Obsah</p>' === $filter() );
$env( array( 'admin' => false ) );
$assert( 'the filter leaves non-string input alone', null === $renderer->filter_content( null ) );
$GLOBALS['uxs_options']['uxstudio_code_snippets'] = array( 'safe_mode' => true );
$assert( 'safe mode silences the placement', '<p>Obsah</p>' === ( new SnippetRenderer( new Settings( 'uxstudio_code_snippets' ) ) )->filter_content( '<p>Obsah</p>' ) );
$GLOBALS['uxs_options']['uxstudio_code_snippets'] = array();
$GLOBALS['uxs_rows'][ (int) $id_of( $before ) ]['enabled'] = 0;
$new_request();
$assert( 'a disabled placement snippet is not inserted', 0 !== strpos( trim( $filter() ), '<div class="pred">' ) );
$GLOBALS['uxs_rows'][ (int) $id_of( $before ) ]['enabled'] = 1;
$GLOBALS['uxs_rows'][ (int) $id_of( $before ) ]['post_types'] = '';
$new_request();
$assert( 'a placement with no stored post types uses posts and pages', 0 === strpos( trim( $filter() ), '<div class="pred">' ) );
$GLOBALS['uxs_rows'][ (int) $id_of( $before ) ]['post_types'] = 'post';

/* ── Executor: what runs early, run-once lifecycle ── */

$GLOBALS['uxs_ran_everywhere'] = 0;
$GLOBALS['uxs_ran_shortcode']  = 0;
$GLOBALS['uxs_ran_once']       = 0;
$env( array( 'admin' => true, 'can' => true ) );
$GLOBALS['pagenow'] = 'index.php';
$_SERVER['REQUEST_METHOD'] = 'GET';
$exec = new SnippetExecutor( $settings );
$assert( 'the executor includes ordinary PHP snippets early, but NEVER a shortcode, placement or run-once snippet', 1 === $GLOBALS['uxs_ran_everywhere'] && 0 === $GLOBALS['uxs_ran_shortcode'] && 0 === $GLOBALS['uxs_ran_once'] );
$printed = array();
foreach ( $GLOBALS['uxs_hooks']['action'] as $hook => $cbs ) { $printed[] = $hook; }
$assert( 'only the hook snippet is queued for output on a hook (not shortcode / placement ones)', in_array( 'admin_head', $printed, true ) || in_array( 'wp_head', $printed, true ) );
$assert( 'run-once snippets are waiting for admin_init', ! empty( $GLOBALS['uxs_hooks']['action']['admin_init'] ) );
$assert( 'before the first admin page: not run, still switched on, no result', 0 === $GLOBALS['uxs_ran_once'] && 1 === (int) $GLOBALS['uxs_rows'][ (int) $id_of( $once ) ]['enabled'] && null === $GLOBALS['uxs_rows'][ (int) $id_of( $once ) ]['last_run'] );

// Safe preview: nothing runs and nothing is claimed.
$ref = new ReflectionProperty( \UxStudio\Core\Migration\PreviewBoot::class, 'ctx' );
$ref->setAccessible( true );
$ref->setValue( null, array( 'run' => 'x', 'uid' => 1, 'staged' => array( 'activations' => array() ) ) );
$exec->runOnceSnippets();
$claimed_in_preview = $manager->claimRunOnce( $id_of( $once ) );
$ref->setValue( null, null );
$assert( 'safe preview: a run-once snippet is NOT executed and NOT claimed (the claim itself refuses too)', false === $claimed_in_preview && 0 === $GLOBALS['uxs_ran_once'] && 1 === (int) $GLOBALS['uxs_rows'][ (int) $id_of( $once ) ]['enabled'] && null === $GLOBALS['uxs_rows'][ (int) $id_of( $once ) ]['last_run'] );

// Ajax and non-GET requests do not run it.
$env( array( 'ajax' => true ) );
$exec->runOnceSnippets();
$env( array( 'ajax' => false ) );
$_SERVER['REQUEST_METHOD'] = 'POST';
$exec->runOnceSnippets();
$_SERVER['REQUEST_METHOD'] = 'GET';
$GLOBALS['pagenow'] = 'admin-post.php';
$exec->runOnceSnippets();
$GLOBALS['pagenow'] = 'index.php';
$assert( 'AJAX, POST and admin-post requests do not run it', 0 === $GLOBALS['uxs_ran_once'] && 1 === (int) $GLOBALS['uxs_rows'][ (int) $id_of( $once ) ]['enabled'] );

// A user who may not manage options does not trigger it.
$env( array( 'can' => false ) );
$exec->runOnceSnippets();
$env( array( 'can' => true ) );
$assert( 'a user without manage_options does not trigger it', 0 === $GLOBALS['uxs_ran_once'] );

// The real run.
ob_start();
$exec->runOnceSnippets();
ob_end_clean();
$row = $GLOBALS['uxs_rows'][ (int) $id_of( $once ) ];
$res = json_decode( (string) $row['run_result'], true );
$assert( 'next admin page: the snippet ran exactly once, switched itself off and stored the result and the output', 1 === $GLOBALS['uxs_ran_once'] && 0 === (int) $row['enabled'] && '2026-10-22 12:00:00' === $row['last_run'] && true === $res['ok'] && 'hotovo' === $res['output'], json_encode( $row ) );
$exec->runOnceSnippets();
$assert( 'the next admin page does not run it again', 1 === $GLOBALS['uxs_ran_once'] );
$assert( 'the claim is atomic: a second request that read the snippet as enabled loses', false === $manager->claimRunOnce( $id_of( $once ) ) );

// A snippet that throws: result recorded with the message, still switched off, the page survives.
$bad = $GLOBALS['uxs_rows'][ (int) $id_of( $once_bad ) ];
$bres = json_decode( (string) $bad['run_result'], true );
$assert( 'a run-once snippet that throws is switched off, the error is stored, the other one still ran', 0 === (int) $bad['enabled'] && false === $bres['ok'] && false !== strpos( $bres['message'], 'selhalo' ), json_encode( $bad ) );
$api = $manager->getSnippet( $id_of( $once_bad ) )->toApiArray();
$assert( 'API exposes last_run and the result', '2026-10-22 12:00:00' === $api['last_run'] && false === $api['run_result']['ok'] );
$assert( 'the run is written to the activity log', (bool) array_filter( $GLOBALS['uxs_logs'], static fn( $l ) => 'run_once' === $l['action'] ) && (bool) array_filter( $GLOBALS['uxs_logs'], static fn( $l ) => 'run_once_failed' === $l['action'] ) );

// Switching it on again arms it again.
$manager->setSnippetEnabled( $id_of( $once ), true );
$assert( 'switching a run-once snippet on again arms it for one more run', 1 === (int) $GLOBALS['uxs_rows'][ (int) $id_of( $once ) ]['enabled'] );
$exec->runOnceSnippets();
$assert( '... and it runs once more', 2 === $GLOBALS['uxs_ran_once'] );

// An edited file must not run even when run-once: it is claimed (off), recorded as failed.
$tampered = $manager->getSnippet( $id_of( $once ) );
$tfile    = $tampered->getFilePath();
$torig    = file_get_contents( $tfile );
$manager->setSnippetEnabled( $id_of( $once ), true );
$torig = file_get_contents( $tfile );
file_put_contents( $tfile, str_replace( 'uxs_ran_once', 'uxs_ran_evil', $torig ) );
$exec->runOnceSnippets();
$tres = json_decode( (string) $GLOBALS['uxs_rows'][ (int) $id_of( $once ) ]['run_result'], true );
$assert( 'run-once with a tampered file: not executed, switched off, failure recorded', 2 === $GLOBALS['uxs_ran_once'] && empty( $GLOBALS['uxs_ran_evil'] ) && 0 === (int) $GLOBALS['uxs_rows'][ (int) $id_of( $once ) ]['enabled'] && false === $tres['ok'] );
file_put_contents( $tfile, $torig );

// PHP is never executed in a REST / AJAX / cron request, HTML still renders. Last, because constants cannot be undone.
$GLOBALS['uxs_ran_shortcode'] = 0;
define( 'REST_REQUEST', true );
$assert( 'REST request: the PHP shortcode produces nothing and does not execute', '' === $sc( $id_of( $sc_php ), array( 'rok' => '1' ) ) && 0 === $GLOBALS['uxs_ran_shortcode'] );
$env( array( 'admin' => false ) );
$out = $renderer->filter_content( '<p>Obsah</p>' );
$assert( 'REST request: the content filter does nothing at all (it is a front-end filter)', '<p>Obsah</p>' === $out );
$assert( 'REST request: an HTML shortcode (stored text only) still renders', false !== strpos( $sc( $id_of( $sc_html ) ), 'Ahoj z HTML' ) );
$assert( 'REST request: the module guard says no PHP', false === SnippetRenderer::may_execute_php() );

echo PHP_EOL . ( $failures ? "$failures FAILED" : 'ALL PASSED' ) . PHP_EOL;
exit( $failures ? 1 : 0 );
