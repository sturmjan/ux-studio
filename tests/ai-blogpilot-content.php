<?php
// Test only: never runs over HTTP (the release zip also excludes tests/).
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { exit; }
/**
 * Blog Pilot on the shared content pipeline: generators go through the core ArticleWriter (adapter, length retry), AI
 * topic suggestions, publish plan with occupied slots and `future` posts, calendar month structure, the schedule /
 * approve / reject workflow with its permissions and validation, REST registration (capabilities, AI rate limit) and the
 * migration of an old generator row.
 *
 *   php tests/ai-blogpilot-content.php
 *
 * Needs no WordPress: WP function doubles, an in-memory $wpdb, a fake core TextProvider. Never calls a real AI API.
 * Exits non-zero on failure.
 *
 * @package UxStudio
 */

// phpcs:disable

use Ux1\AiCore\Provider\TextProvider;
use UxStudio\Modules\AiAssistant\BlogPilot\ArticleGenerator;
use UxStudio\Modules\AiAssistant\BlogPilot\CalendarService;
use UxStudio\Modules\AiAssistant\BlogPilot\GeneratorManager;
use UxStudio\Modules\AiAssistant\BlogPilot\PostCreator;
use UxStudio\Modules\AiAssistant\BlogPilot\PostWorkflow;
use UxStudio\Modules\AiAssistant\BlogPilot\PublishSlots;
use UxStudio\Modules\AiAssistant\BlogPilot\RateLimit;
use UxStudio\Modules\AiAssistant\BlogPilot\Scheduler;
use UxStudio\Modules\AiAssistant\BlogPilot\TopicSuggester;
use UxStudio\Modules\AiAssistant\BlogPilotRestController;

define( 'ABSPATH', __DIR__ . '/' );
define( 'WPINC', 'wp-includes' );
$root = dirname( __DIR__ );
define( 'UXSTUDIO_PATH', $root . '/' );
define( 'ARRAY_A', 'ARRAY_A' );
define( 'WEEK_IN_SECONDS', 604800 );
define( 'MONTH_IN_SECONDS', 2592000 );

// ── Doubles of plugin classes that need a database or settings ──────────────────────────────────
eval( 'namespace UxStudio\Core; final class Security { public static bool $allow_write = true; public static function check_write_rate_limit(): bool { return self::$allow_write; } }' );
eval( 'namespace UxStudio\Modules\AiAssistant; final class UsageLimiter { public static ?string $error = null; public static function check( ?int $u = null ): ?string { return self::$error; } }' );
eval( 'namespace UxStudio\Modules\AiAssistant; final class UsageTracker { public static array $log = array(); public static function log( string $p, string $m, string $f, int $i, int $o ): void { self::$log[] = array( $p, $m, $f, $i, $o ); } }' );
eval( 'namespace UxStudio\Modules\AiAssistant; final class ErrorLogger { public const TYPE_PROVIDER = "provider"; public const TYPE_UNKNOWN = "unknown"; public static array $log = array(); public static function log( array $d ): void { self::$log[] = $d; } public static function parse_exception_detail( string $m ): array { return array( "http_status" => 0 ); } }' );
eval( 'namespace UxStudio\Modules\AiAssistant; final class ContentHelper { public static function get_or_create_category( string $n ): int { $GLOBALS["t"]["cats"][] = $n; return 7; } public static function get_or_create_tags( array $t ): array { $GLOBALS["t"]["tags"] = $t; return array( 1, 2 ); } }' );
eval( 'namespace UxStudio\Modules\AiAssistant; use UxStudio\Modules\AiAssistant\Providers\CoreProvider; final class ProviderFactory { public static function create( ?string $id = null ) { return new CoreProvider( $GLOBALS["t"]["provider"] ); } public static function get_all_providers(): array { return array(); } }' );

// ── WordPress doubles ───────────────────────────────────────────────────────────────────────────
class WP_Error {
	public function __construct( public string $code = '', public string $message = '', public array $data = array() ) {}
	public function get_error_code() { return $this->code; }
	public function get_error_message() { return $this->message; }
}
class WP_REST_Response { public function __construct( public $data = null ) {} }
class WP_REST_Request {
	public function __construct( private array $params = array() ) {}
	public function get_param( string $k ) { return $this->params[ $k ] ?? null; }
	public function get_json_params() { return $this->params; }
}
$GLOBALS['t'] = array(
	'caps'    => array( 'manage_options' => true, 'edit_posts' => true, 'edit_post' => true, 'publish_post' => true, 'delete_post' => true ),
	'user'    => 5,
	'posts'   => array(),
	'meta'    => array(),
	'next_id' => 100,
	'trans'   => array(),
	'routes'  => array(),
	'updates' => array(),
	'inserts' => array(),
	'trashed' => array(),
	'options' => array( 'start_of_week' => 1 ),
);
function __( $t, $d = null ) { return $t; }
function absint( $v ) { return abs( (int) $v ); }
function is_wp_error( $t ) { return $t instanceof WP_Error; }
function sanitize_text_field( $s ) { return trim( strip_tags( (string) $s ) ); }
function sanitize_textarea_field( $s ) { return trim( strip_tags( (string) $s ) ); }
function sanitize_key( $s ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $s ) ); }
function wp_json_encode( $d ) { return json_encode( $d, JSON_UNESCAPED_UNICODE ); }
function wp_strip_all_tags( $s ) { return trim( strip_tags( (string) $s ) ); }
function wp_kses_post( $s ) { return preg_replace( '#<script\b.*?</script>#is', '', (string) $s ); }
function current_time( $t ) { return gmdate( 'Y-m-d H:i:s' ); }
function get_current_user_id() { return $GLOBALS['t']['user']; }
function current_user_can( $cap, $id = null ) {
	$caps = $GLOBALS['t']['caps'];
	if ( null !== $id && array_key_exists( "$cap:$id", $caps ) ) { return (bool) $caps[ "$cap:$id" ]; }
	return ! empty( $caps[ $cap ] );
}
function get_transient( $k ) { return $GLOBALS['t']['trans'][ $k ] ?? false; }
function set_transient( $k, $v, $e = 0 ) { $GLOBALS['t']['trans'][ $k ] = $v; return true; }
function wp_timezone_string() { return 'Europe/Prague'; }
function wp_date( $f ) { return ( new DateTimeImmutable( 'now', new DateTimeZone( 'Europe/Prague' ) ) )->format( $f ); }
function get_bloginfo( $k ) { return 'name' === $k ? 'Dílna Novák' : 'Truhlářství a nábytek na míru'; }
function get_locale() { return 'cs_CZ'; }
function get_option( $k, $d = false ) { return $GLOBALS['t']['options'][ $k ] ?? $d; }
function get_permalink( $id ) { return "https://site.test/?p=$id"; }
function get_edit_post_link( $id, $c = 'display' ) { return "https://site.test/wp-admin/post.php?post=$id&action=edit"; }
function register_rest_route( $ns, $path, $args ) { $GLOBALS['t']['routes'][ $path . ' ' . $args['methods'] ] = $args; }
function get_post( $id ) { return $GLOBALS['t']['posts'][ (int) $id ] ?? null; }
function wp_insert_post( $d, $e = false ) {
	$id  = $GLOBALS['t']['next_id']++;
	$now = gmdate( 'Y-m-d H:i:s' );
	$GLOBALS['t']['inserts'][] = $d;
	$GLOBALS['t']['posts'][ $id ] = (object) array_merge( array( 'ID' => $id, 'post_title' => '', 'post_content' => '', 'post_excerpt' => '', 'post_type' => 'post', 'post_status' => 'draft', 'post_date' => $now, 'post_author' => 1 ), $d );
	return $id;
}
function wp_update_post( $d, $e = false ) {
	$GLOBALS['t']['updates'][] = $d;
	if ( ! empty( $GLOBALS['t']['update_fails'] ) ) { return new WP_Error( 'db', 'update failed' ); }
	foreach ( $d as $k => $v ) { if ( 'ID' !== $k && 'edit_date' !== $k ) { $GLOBALS['t']['posts'][ $d['ID'] ]->$k = $v; } }
	return $d['ID'];
}
function wp_trash_post( $id ) { $GLOBALS['t']['trashed'][] = $id; $GLOBALS['t']['posts'][ $id ]->post_status = 'trash'; return true; }
function wp_set_post_categories( $id, $c ) { $GLOBALS['t']['post_cats'][ $id ] = $c; }
function wp_set_post_tags( $id, $tags ) { $GLOBALS['t']['post_tags'][ $id ] = $tags; }
function update_post_meta( $id, $k, $v ) { $GLOBALS['t']['meta'][ $id ][ $k ] = $v; return true; }
function get_post_meta( $id, $k, $single = false ) { return $GLOBALS['t']['meta'][ $id ][ $k ] ?? ''; }

/** In-memory $wpdb: only the statements Blog Pilot issues. Everything passes through prepare(). */
final class TDb {
	public string $prefix   = 'wp_';
	public string $posts    = 'wp_posts';
	public string $postmeta = 'wp_postmeta';
	public int $insert_id   = 0;
	public array $generators = array();
	public array $gen_posts  = array();
	public bool $reject_new_columns = false;
	public array $queries = array();

	public function prepare( string $q, ...$args ) {
		if ( 1 === count( $args ) && is_array( $args[0] ) ) { $args = $args[0]; }
		$i = 0;
		return (string) preg_replace_callback( '/%[sd]/', function ( $m ) use ( &$i, $args ) {
			$v = $args[ $i++ ];
			return '%d' === $m[0] ? (string) (int) $v : "'" . addslashes( (string) $v ) . "'";
		}, $q );
	}
	private function posts_of_type(): array { return array_filter( $GLOBALS['t']['posts'], fn( $p ) => 'post' === $p->post_type ); }
	public function get_col( $sql ) {
		$this->queries[] = $sql;
		if ( str_contains( $sql, 'SELECT post_date FROM' ) ) {
			preg_match( "/post_date >= '([^']*)'/", $sql, $m );
			$excl = array();
			if ( preg_match( '/ID NOT IN \(([\d,]+)\)/', $sql, $x ) ) { $excl = array_map( 'intval', explode( ',', $x[1] ) ); }
			$out = array();
			foreach ( $this->posts_of_type() as $p ) {
				if ( 'future' === $p->post_status && $p->post_date >= $m[1] && ! in_array( $p->ID, $excl, true ) ) { $out[] = $p->post_date; }
			}
			return $out;
		}
		if ( str_contains( $sql, 'SELECT post_title FROM' ) ) {
			$rows = array_filter( $this->posts_of_type(), fn( $p ) => in_array( $p->post_status, array( 'publish', 'future', 'draft', 'pending' ), true ) && '' !== $p->post_title );
			usort( $rows, fn( $a, $b ) => strcmp( $b->post_date, $a->post_date ) );
			return array_map( fn( $p ) => $p->post_title, $rows );
		}
		throw new RuntimeException( 'unsupported get_col: ' . $sql );
	}
	public function get_results( $sql ) {
		$this->queries[] = $sql;
		if ( str_contains( $sql, 'SELECT ID, post_title FROM' ) ) {
			return array_values( array_filter( $this->posts_of_type(), fn( $p ) => 'publish' === $p->post_status ) );
		}
		if ( str_contains( $sql, 'AS generated' ) ) {
			preg_match( "/p\.post_date >= '([^']*)' AND p\.post_date < '([^']*)'/", $sql, $m );
			$out = array();
			foreach ( $this->posts_of_type() as $p ) {
				$generated = isset( $GLOBALS['t']['meta'][ $p->ID ]['_uxstudio_blog_pilot'] );
				if ( in_array( $p->post_status, array( 'future', 'publish', 'draft', 'pending' ), true ) && $p->post_date >= $m[1] && $p->post_date < $m[2] && ( 'future' === $p->post_status || $generated ) ) {
					$out[] = (object) array( 'ID' => $p->ID, 'post_title' => $p->post_title, 'post_status' => $p->post_status, 'post_date' => $p->post_date, 'generated' => $generated ? 1 : null );
				}
			}
			return $out;
		}
		if ( str_contains( $sql, 'blog_generated_posts gp' ) ) {
			$statuses = null;
			if ( preg_match( "/p\.post_status IN \(([^)]*)\)/", $sql, $m ) ) { $statuses = array_map( fn( $s ) => trim( $s, "' " ), explode( ',', $m[1] ) ); }
			$out = array();
			foreach ( $this->gen_posts as $row ) {
				$post = $GLOBALS['t']['posts'][ $row['post_id'] ] ?? null;
				if ( null !== $statuses && ( ! $post || ! in_array( $post->post_status, $statuses, true ) ) ) { continue; }
				$out[] = (object) array_merge( $row, array( 'wp_title' => $post->post_title ?? null, 'wp_status' => $post->post_status ?? null, 'wp_date' => $post->post_date ?? null, 'generator_title' => 'G' ) );
			}
			return $out;
		}
		if ( str_contains( $sql, 'blog_generators' ) ) { return array_map( fn( $r ) => (object) $r, array_values( $this->generators ) ); }
		throw new RuntimeException( 'unsupported get_results: ' . $sql );
	}
	public function get_row( $sql ) {
		$this->queries[] = $sql;
		if ( preg_match( '/blog_generators WHERE id = (\d+)/', $sql, $m ) ) { return isset( $this->generators[ (int) $m[1] ] ) ? (object) $this->generators[ (int) $m[1] ] : null; }
		if ( preg_match( '/blog_generated_posts WHERE post_id = (\d+)/', $sql, $m ) ) {
			$rows = array_values( array_filter( $this->gen_posts, fn( $r ) => $r['post_id'] === (int) $m[1] ) );
			return $rows ? (object) end( $rows ) : null;
		}
		throw new RuntimeException( 'unsupported get_row: ' . $sql );
	}
	public function get_var( $sql ) {
		$this->queries[] = $sql;
		if ( str_starts_with( $sql, 'SELECT COUNT(*)' ) ) { return count( $this->gen_posts ); }
		return 0;
	}
	public function insert( $table, $row, $f = null ) {
		if ( str_contains( $table, 'generated_posts' ) ) {
			if ( $this->reject_new_columns && isset( $row['keyword'] ) ) { return false; }
			$this->gen_posts[] = $row;
		} else {
			$row['id'] = count( $this->generators ) + 1;
			$this->generators[ $row['id'] ] = $row;
		}
		$this->insert_id = (int) ( $row['id'] ?? 0 );
		return 1;
	}
	public function update( $table, $data, $where ) {
		if ( isset( $where['id'], $this->generators[ $where['id'] ] ) ) { $this->generators[ $where['id'] ] = array_merge( $this->generators[ $where['id'] ], $data ); }
		return 1;
	}
	public function query( $sql ) { $this->queries[] = $sql; return true; }
	public function delete( $t, $w ) { return 1; }
}
$GLOBALS['wpdb'] = new TDb();

require $root . '/includes/Autoloader.php';
\UxStudio\Autoloader::register();
require $root . '/vendor/ux1-ai-core/autoload.php';

// ── Fake core provider ──────────────────────────────────────────────────────────────────────────
final class FakeText implements TextProvider {
	/** @var array<int, string|Throwable> Queued answers. */
	public array $queue = array();
	public array $calls = array();
	public function get_id(): string { return 'fake'; }
	public function get_label(): string { return 'Fake'; }
	public function get_models(): array { return array( 'fake-1' => 'Fake 1' ); }
	public function generate_content( string $system_prompt, string $user_prompt, string $model, array $options = array() ): array {
		$this->calls[] = array( 'system' => $system_prompt, 'user' => $user_prompt, 'model' => $model );
		$next = array_shift( $this->queue );
		if ( $next instanceof Throwable ) { throw $next; }
		return array( 'content' => (string) $next, 'usage' => array( 'input_tokens' => 100, 'output_tokens' => 200 ) );
	}
	public function stream_chat( array $messages, string $model, callable $on_chunk, array $options = array() ): void {}
	public function get_last_stream_usage(): array { return array(); }
	public function chat_with_tools( array $messages, string $model, array $tools = array(), array $options = array() ): array { return array(); }
	public function format_tool_results( array $tool_results ): array { return array(); }
	public function test_connection( string $model ): array { return array(); }
}

function article_json( int $words, string $title = 'Jak vybrat masivní stůl' ): string {
	$para = trim( str_repeat( 'slovo ', 60 ) );
	$html = '<p>' . $para . '</p><script>alert(1)</script><h2>Materiály</h2>';
	for ( $i = 0; $i < max( 0, intdiv( $words - 60, 60 ) ); $i++ ) { $html .= '<p>' . $para . '</p>'; }
	return json_encode( array( 'title' => $title, 'content' => $html, 'excerpt' => 'Krátké shrnutí.', 'meta_title' => 'Meta', 'meta_description' => 'Popis', 'focus_keyword' => 'Masivní stůl', 'tags' => array( 'stůl', 'dřevo' ), 'category_suggestion' => 'Nábytek' ), JSON_UNESCAPED_UNICODE );
}
function topics_json( array $titles ): string {
	$rows = array();
	foreach ( $titles as $i => $t ) { $rows[] = array( 'title' => $t, 'summary' => "Shrnutí $i", 'focus_keyword' => "Klíč $i", 'article_type' => 0 === $i % 2 ? 'how-to' : 'listicle' ); }
	return json_encode( $rows, JSON_UNESCAPED_UNICODE );
}
function fresh(): FakeText {
	$GLOBALS['t']['provider'] = new FakeText();
	$GLOBALS['t']['posts'] = $GLOBALS['t']['meta'] = $GLOBALS['t']['inserts'] = $GLOBALS['t']['updates'] = $GLOBALS['t']['trashed'] = $GLOBALS['t']['trans'] = array();
	$GLOBALS['t']['next_id'] = 100;
	$GLOBALS['t']['update_fails'] = false;
	$GLOBALS['t']['caps'] = array( 'manage_options' => true, 'edit_posts' => true, 'edit_post' => true, 'publish_post' => true, 'delete_post' => true );
	$GLOBALS['wpdb']->generators = $GLOBALS['wpdb']->gen_posts = $GLOBALS['wpdb']->queries = array();
	$GLOBALS['wpdb']->reject_new_columns = false;
	\UxStudio\Modules\AiAssistant\UsageTracker::$log = array();
	\UxStudio\Modules\AiAssistant\UsageLimiter::$error = null;
	return $GLOBALS['t']['provider'];
}
function add_post( int $id, string $status, string $date, string $title = 'Post', bool $generated = false, array $extra = array() ): void {
	$GLOBALS['t']['posts'][ $id ] = (object) array_merge( array( 'ID' => $id, 'post_title' => $title, 'post_content' => '<p>Obsah</p><script>x()</script>', 'post_excerpt' => 'Výtah', 'post_type' => 'post', 'post_status' => $status, 'post_date' => $date ), $extra );
	if ( $generated ) { $GLOBALS['t']['meta'][ $id ]['_uxstudio_blog_pilot'] = '1'; }
}
function clock_at( string $when ): callable {
	return fn(): DateTimeImmutable => new DateTimeImmutable( $when, new DateTimeZone( 'Europe/Prague' ) );
}
function legacy_generator( array $config = array() ): object {
	return (object) array( 'id' => 1, 'provider' => '', 'model' => '', 'config' => $config + array( 'tone' => 'professional', 'length' => 'short', 'language' => 'cs', 'post_status' => 'draft' ) );
}

$failed = 0;
function check( string $name, bool $ok, string $detail = '' ): void {
	global $failed;
	echo ( $ok ? 'ok   ' : 'FAIL ' ) . $name . ( $ok || '' === $detail ? '' : ' - ' . $detail ) . PHP_EOL;
	if ( ! $ok ) { ++$failed; }
}

// ── 1. Adapter -> core ArticleWriter ────────────────────────────────────────────────────────────
$fake = fresh();
$gen  = new ArticleGenerator();
$g    = legacy_generator( array( 'tone' => 'friendly', 'length' => 'medium', 'language' => 'de', 'custom_instructions' => 'Mention oak.' ) );
$fake->queue = array( article_json( 120 ), article_json( 700 ) );
$out  = $gen->write( $g, 'Masivní stoly', 'how-to', array( 'keyword' => 'masivní stůl', 'summary' => 'Pohled truhláře' ), array( 'site_name' => 'Dílna', 'site_context' => 'Nábytek', 'today' => '2026-11-26', 'link_candidates' => array( array( 'title' => 'O nás', 'url' => 'https://site.test/o-nas' ) ) ) );
$draft = $out['draft'];
check( 'adapter: too-short answer is retried once', 2 === count( $fake->calls ), (string) count( $fake->calls ) );
check( 'adapter: retry prompt tells the model it was too short', str_contains( $fake->calls[1]['user'], 'too short' ) );
check( 'adapter: the longer second draft wins and has no too_short warning', $draft->word_count >= 550 && ! in_array( 'too_short', $draft->warnings, true ) );
check( 'adapter: usage is summed over both attempts', 200 === $draft->usage['input_tokens'] && 400 === $draft->usage['output_tokens'] && 2 === $draft->attempts );
check( 'adapter: HTML is cleaned by the core (no script)', ! str_contains( $draft->content, '<script' ) && str_contains( $draft->content, '<h2>' ) );
check( 'adapter: tone, language, instructions, site and links reach the system prompt', str_contains( $fake->calls[0]['system'], 'German' ) && str_contains( $fake->calls[0]['system'], 'friendly' ) && str_contains( $fake->calls[0]['system'], 'Mention oak.' ) && str_contains( $fake->calls[0]['system'], 'https://site.test/o-nas' ) && str_contains( $fake->calls[0]['system'], 'Dílna' ) );
check( 'adapter: article type hint, keyword and angle reach the user prompt', str_contains( $fake->calls[0]['user'], 'TYPE-SPECIFIC' ) && str_contains( $fake->calls[0]['user'], 'masivní stůl' ) && str_contains( $fake->calls[0]['user'], 'Pohled truhláře' ) );
check( 'adapter: provider id and model come from the plugin provider', 'fake' === $out['provider'] && 'fake-1' === $out['model'] && 'fake-1' === $fake->calls[0]['model'] );
$req = $gen->build_request( array(), 'T', 'no-such-type' );
check( 'adapter: unknown article type falls back to a generic article, defaults to cs/professional/medium', '' === $req->article_type && 'cs' === $req->language && 'professional' === $req->tone && 'medium' === $req->length );
check( 'adapter: all 33 core article types are accepted', 33 === count( \Ux1\AiCore\Content\ArticleTypes::ids() ) && '' !== $gen->build_request( array(), 'T', 'comment-reply' )->article_type );

$fake->queue = array( article_json( 100 ), article_json( 120 ) );
$short = $gen->write( $g, 'T', 'how-to' )['draft'];
check( 'adapter: still too short after the retry -> too_short warning, draft kept', in_array( 'too_short', $short->warnings, true ) && '' !== $short->content );
$fake->queue = array( new RuntimeException( 'Fake API: HTTP 401: bad key' ) );
try { $gen->write( $g, 'T', 'how-to' ); check( 'adapter: provider failure propagates unchanged', false ); } catch ( RuntimeException $e ) { check( 'adapter: provider failure propagates unchanged', str_contains( $e->getMessage(), 'HTTP 401' ) ); }

// ── 2. PostCreator: status, category, tags, author, usage ───────────────────────────────────────
$fake = fresh();
$fake->queue = array( article_json( 700 ) );
$r = ( new PostCreator( null, clock_at( '2026-11-26 09:00:00' ) ) )->generate( legacy_generator( array( 'post_status' => 'pending', 'author_id' => 9, 'category_id' => 4, 'length' => 'medium' ) ), 'Téma', 'how-to', array( 'keyword' => 'k' ) );
$ins = $GLOBALS['t']['inserts'][0];
check( 'post: created with the configured status, author and type', 'pending' === $ins['post_status'] && 9 === $ins['post_author'] && 'post' === $ins['post_type'] );
check( 'post: body is the cleaned HTML, excerpt kept', str_contains( $ins['post_content'], '<h2>Materiály</h2>' ) && ! str_contains( $ins['post_content'], 'script' ) && 'Krátké shrnutí.' === $ins['post_excerpt'] );
check( 'post: fixed category id wins, tags set, marked as Blog Pilot', array( 4 ) === $GLOBALS['t']['post_cats'][ $r['post_id'] ] && array( 1, 2 ) === $GLOBALS['t']['post_tags'][ $r['post_id'] ] && '1' === get_post_meta( $r['post_id'], '_uxstudio_blog_pilot', true ) );
check( 'post: token usage logged once with the summed tokens', 1 === count( \UxStudio\Modules\AiAssistant\UsageTracker::$log ) && array( 'fake', 'fake-1', 'blog_pilot', 100, 200 ) === \UxStudio\Modules\AiAssistant\UsageTracker::$log[0] );
check( 'post: result carries status, no schedule, keyword', 'pending' === $r['status'] && null === $r['scheduled_for'] && 'masivní stůl' === $r['keyword'] );
$fake->queue = array( article_json( 700 ) );
$r2 = ( new PostCreator() )->generate( legacy_generator( array( 'post_status' => 'nonsense', 'length' => 'medium' ) ), 'Téma', 'how-to' );
check( 'post: unknown status falls back to draft, category suggestion creates a category', 'draft' === $GLOBALS['t']['posts'][ $r2['post_id'] ]->post_status && array( 'Nábytek' ) === $GLOBALS['t']['cats'] );

// ── 3. Publish plan, occupied slots, `future` ───────────────────────────────────────────────────
fresh();
$plan = array( 'mode' => 'period', 'per' => 1, 'unit' => 'day', 'window_start' => '10:00', 'window_end' => '11:00', 'weekdays' => array( 1, 2, 3, 4, 5 ) );
$cfg  = array( 'post_status' => 'future', 'length' => 'medium', 'publish_plan' => $plan );
$GLOBALS['t']['provider']->queue = array( article_json( 700 ), article_json( 700 ), article_json( 700 ) );
$creator = new PostCreator( null, clock_at( '2026-11-26 09:00:00' ) ); // Thursday
$a = $creator->generate( legacy_generator( $cfg ), 'A', 'how-to' );
check( 'plan: first article goes into the middle of today\'s window as a future post', 'future' === $a['status'] && '2026-11-26 10:30:00' === $a['scheduled_for'] );
$ins = $GLOBALS['t']['inserts'][0];
check( 'plan: wp_insert_post gets future status, local and GMT date', 'future' === $ins['post_status'] && '2026-11-26 10:30:00' === $ins['post_date'] && '2026-11-26 09:30:00' === $ins['post_date_gmt'] );
$b = $creator->generate( legacy_generator( $cfg ), 'B', 'how-to' );
check( 'plan: second article skips the day that is full (1 per day)', '2026-11-27 10:30:00' === $b['scheduled_for'], (string) $b['scheduled_for'] );
$c = $creator->generate( legacy_generator( $cfg ), 'C', 'how-to' );
check( 'plan: third skips Saturday and Sunday (weekdays 1-5)', '2026-11-30 10:30:00' === $c['scheduled_for'], (string) $c['scheduled_for'] );

fresh();
add_post( 50, 'future', '2026-11-26 10:30:00', 'Occupied' );
$GLOBALS['t']['provider']->queue = array( article_json( 700 ) );
$cfg2 = array( 'post_status' => 'future', 'length' => 'medium', 'publish_plan' => array( 'per' => 2, 'window_start' => '10:00', 'window_end' => '11:00' ) );
$d = ( new PostCreator( null, clock_at( '2026-11-26 09:00:00' ) ) )->generate( legacy_generator( $cfg2 ), 'D', 'how-to' );
check( 'plan: 2 per day, one taken -> same day, but never the occupied minute', str_starts_with( (string) $d['scheduled_for'], '2026-11-26 10:' ) && '2026-11-26 10:30:00' !== $d['scheduled_for'], (string) $d['scheduled_for'] );

fresh();
$GLOBALS['t']['provider']->queue = array( article_json( 700 ) );
$e = ( new PostCreator( null, clock_at( '2026-11-26 12:00:00' ) ) )->generate( legacy_generator( array( 'post_status' => 'future', 'length' => 'medium' ) ), 'E', 'how-to' );
check( 'plan: no plan stored -> default plan (08:00-11:00), never in the past: today\'s window is over, so tomorrow 09:30', '2026-11-27 09:30:00' === $e['scheduled_for'], (string) $e['scheduled_for'] );

$p = PublishSlots::normalize( array( 'mode' => 'x', 'per' => 500, 'window_start' => '12:00', 'window_end' => '08:00', 'weekdays' => array( 9, 0 ), 'start_date' => '2026-02-31' ) );
check( 'plan: normalize repairs nonsense (mode, per, window, weekdays, date)', 'period' === $p['mode'] && 100 === $p['per'] && '08:00' === $p['window_start'] && '11:00' === $p['window_end'] && range( 1, 7 ) === $p['weekdays'] && '' === $p['start_date'] );
check( 'plan: validate rejects a range without end, a bad time and an empty weekday list', null !== PublishSlots::validate( array( 'mode' => 'range', 'start_date' => '2026-12-01' ) ) && null !== PublishSlots::validate( array( 'window_start' => '25:00' ) ) && null !== PublishSlots::validate( array( 'weekdays' => array( 8 ) ) ) && null === PublishSlots::validate( array( 'mode' => 'range', 'start_date' => '2026-12-01', 'end_date' => '2026-12-04', 'weekdays' => array( 1, 2, 3 ) ) ) );
$range = PublishSlots::plan( array( 'mode' => 'range', 'start_date' => '2026-12-01', 'end_date' => '2026-12-04' ), 2, array(), new DateTimeImmutable( '2026-11-26 09:00:00', new DateTimeZone( 'Europe/Prague' ) ), 'Europe/Prague' );
check( 'plan: range mode spreads inside the range, ascending, different days', 2 === count( $range ) && $range[0] < $range[1] && $range[0]->format( 'Y-m-d' ) >= '2026-12-01' && $range[1]->format( 'Y-m-d' ) <= '2026-12-04' && $range[0]->format( 'Y-m-d' ) !== $range[1]->format( 'Y-m-d' ) );
$over = PublishSlots::plan( array( 'mode' => 'range', 'start_date' => '2026-10-01', 'end_date' => '2026-10-05' ), 1, array(), new DateTimeImmutable( '2026-11-26 09:00:00', new DateTimeZone( 'Europe/Prague' ) ), 'Europe/Prague' );
check( 'plan: an elapsed range continues from today instead of failing', $over[0]->format( 'Y-m-d' ) >= '2026-11-26' );
fresh();
add_post( 61, 'future', '2026-11-26 10:30:00' );
add_post( 62, 'draft', '2026-11-26 10:30:00' );
add_post( 63, 'future', '2026-10-01 10:30:00' );
$occ = PublishSlots::occupied( '2026-11-26 09:00:00' );
check( 'plan: occupied() lists only future posts from now on, via a prepared statement', array( '2026-11-26 10:30:00' ) === $occ && ! str_contains( end( $GLOBALS['wpdb']->queries ), '%' ) );
check( 'plan: occupied() can exclude the posts being planned', array() === PublishSlots::occupied( '2026-11-26 09:00:00', array( 61 ) ) );

// ── 4. Topic suggestions ────────────────────────────────────────────────────────────────────────
$fake = fresh();
add_post( 70, 'publish', '2026-11-01 10:00:00', 'Jak pečovat o dřevěný stůl' );
$ts = new TopicSuggester();
check( 'topics: default brief is built from the site name and tagline', str_contains( $ts->defaults()['brief'], 'Dílna Novák' ) && str_contains( $ts->defaults()['brief'], 'Truhlářství' ) && 1 === $ts->defaults()['existing_count'] && 'cs' === $ts->defaults()['language'] );
$fake->queue = array( topics_json( array( 'Jak pečovat o dřevěný stůl', 'Olejování dřeva krok za krokem', 'Nábytek na míru: 7 chyb', 'Masivní dub nebo buk?', 'Dřevěné podlahy a vlhkost', 'Zahradní nábytek na zimu', 'Údržba parket' ) ) );
$res = $ts->suggest( array( 'brief' => '', 'count' => 4, 'existing_topics' => array( 'Masivní dub nebo buk?' ), 'types' => array( 'how-to', 'bogus' ) ) );
$titles = array_column( $res['proposals'], 'title' );
check( 'topics: empty brief uses the site default in the prompt', str_contains( $fake->calls[0]['user'] . $fake->calls[0]['system'], 'Dílna Novák' ) );
check( 'topics: existing post titles and generator topics are sent as titles to avoid', str_contains( $fake->calls[0]['system'], 'Jak pečovat o dřevěný stůl' ) && str_contains( $fake->calls[0]['system'], 'Masivní dub nebo buk?' ) );
check( 'topics: titles that already exist are dropped from the answer', ! in_array( 'Jak pečovat o dřevěný stůl', $titles, true ) && ! in_array( 'Masivní dub nebo buk?', $titles, true ) );
check( 'topics: result is clamped to the requested count with title, summary, keyword, type, id', 4 === count( $res['proposals'] ) && isset( $res['proposals'][0]['id'], $res['proposals'][0]['summary'], $res['proposals'][0]['focus_keyword'], $res['proposals'][0]['article_type'] ) && '' !== $res['proposals'][0]['title'] );
check( 'topics: usage is returned and logged under blog_pilot_topics', 100 === $res['usage']['input_tokens'] && 'blog_pilot_topics' === \UxStudio\Modules\AiAssistant\UsageTracker::$log[0][2] );
$one = $res['proposals'][0];
$fake->queue = array( topics_json( array( $one['title'], 'Úplně nový nápad o lazuře', 'Další nápad' ) ) );
$re = $ts->regenerate_one( array( 'count' => 4 ), $one, $res['proposals'] );
check( 'topics: regenerate_one returns a new title different from the rejected and the shown ones', ! in_array( $re['proposal']['title'], array_column( $res['proposals'], 'title' ), true ) && 'Úplně nový nápad o lazuře' === $re['proposal']['title'] );
$fake->queue = array( 'sorry, no JSON here' );
try { $ts->suggest( array( 'brief' => 'x' ) ); check( 'topics: unusable answer throws', false ); } catch ( RuntimeException $e ) { check( 'topics: unusable answer throws', true ); }
check( 'topics: count is clamped to 1..20', 20 === $ts->build_request( array( 'count' => 99 ) )->count && 1 === $ts->build_request( array( 'count' => 0 ) )->count );

// ── 5. REST: registration, capabilities, AI rate limit ──────────────────────────────────────────
fresh();
$ctl = new BlogPilotRestController();
$ctl->register_routes();
$routes = $GLOBALS['t']['routes'];
$B = '/ai-assistant/blog-pilot';
$perm = fn( string $key ): bool => (bool) $routes[ $key ]['permission_callback']();
check( 'rest: all new routes are registered', isset( $routes[ "$B/topics/suggest POST" ], $routes[ "$B/topics/regenerate-one POST" ], $routes[ "$B/topics/defaults GET" ], $routes[ "$B/calendar GET" ], $routes[ "$B/posts/bulk-approve POST" ], $routes[ "$B/posts/(?P<id>\\d+)/schedule POST" ], $routes[ "$B/posts/(?P<id>\\d+)/approve POST" ], $routes[ "$B/posts/(?P<id>\\d+)/regenerate POST" ], $routes[ "$B/posts/(?P<id>\\d+)/reject POST" ], $routes[ "$B/posts/(?P<id>\\d+)/preview GET" ] ) );
$GLOBALS['t']['caps'] = array( 'edit_posts' => true );
check( 'rest: an editor (edit_posts only) can use post routes but not generator or topic routes', $perm( "$B/calendar GET" ) && $perm( "$B/posts GET" ) && $perm( "$B/posts/(?P<id>\\d+)/schedule POST" ) && ! $perm( "$B/generators GET" ) && ! $perm( "$B/generators POST" ) && ! $perm( "$B/topics/suggest POST" ) && ! $perm( "$B/generators/(?P<id>\\d+)/run POST" ) );
$GLOBALS['t']['caps'] = array();
check( 'rest: a user without capabilities gets nothing', ! $perm( "$B/calendar GET" ) && ! $perm( "$B/generators GET" ) );
$GLOBALS['t']['caps'] = array( 'manage_options' => true, 'edit_posts' => true );
check( 'rest: schema args are declared (id integer, calendar month range, approve mode enum, schedule datetime)', 'integer' === $routes[ "$B/posts/(?P<id>\\d+)/schedule POST" ]['args']['id']['type'] && 12 === $routes[ "$B/calendar GET" ]['args']['month']['maximum'] && array( 'now', 'schedule', 'plan' ) === $routes[ "$B/posts/(?P<id>\\d+)/approve POST" ]['args']['mode']['enum'] && ! empty( $routes[ "$B/posts/(?P<id>\\d+)/schedule POST" ]['args']['datetime']['required'] ) );
check( 'rest: the write rate limit wraps POST callbacks', ( function () use ( $routes, $B ) { \UxStudio\Core\Security::$allow_write = false; $r = $routes[ "$B/posts/(?P<id>\\d+)/reject POST" ]['callback']( new WP_REST_Request( array( 'id' => 1 ) ) ); \UxStudio\Core\Security::$allow_write = true; return $r instanceof WP_Error && 429 === $r->data['status']; } )() );

$fake = fresh();
$fake->queue = array( topics_json( array( 'A1 téma', 'B2 téma', 'C3 téma' ) ) );
$res = $ctl->suggest_topics( new WP_REST_Request( array( 'brief' => 'Dřevo', 'count' => 2, 'existing_topics' => array( 'Něco' ), 'types' => array( 'how-to' ) ) ) );
check( 'rest: suggest returns the proposals in the standard envelope', $res instanceof WP_REST_Response && 2 === count( $res->data['data']['proposals'] ) );
\UxStudio\Modules\AiAssistant\UsageLimiter::$error = 'Monthly limit reached';
$res = $ctl->suggest_topics( new WP_REST_Request( array( 'brief' => 'x' ) ) );
check( 'rest: the AI usage limit blocks suggestions with 429 and no provider call', $res instanceof WP_Error && 429 === $res->data['status'] && 0 === count( $fake->calls ) - 1 );
\UxStudio\Modules\AiAssistant\UsageLimiter::$error = null;
$GLOBALS['t']['trans'] = array( 'uxstudio_bp_rl_ai_5' => RateLimit::LIMIT );
$before = count( $fake->calls );
$res = $ctl->suggest_topics( new WP_REST_Request( array( 'brief' => 'x' ) ) );
$res2 = $ctl->regenerate_topic( new WP_REST_Request( array( 'rejected' => array( 'title' => 'T' ) ) ) );
$res3 = $ctl->run_generator( new WP_REST_Request( array( 'id' => 1 ) ) );
check( 'rest: suggest, regenerate-one and run share an AI rate limit (429, no provider call)', $res instanceof WP_Error && $res2 instanceof WP_Error && $res3 instanceof WP_Error && 429 === $res->data['status'] && 429 === $res2->data['status'] && 429 === $res3->data['status'] && $before === count( $fake->calls ) );
$GLOBALS['t']['trans'] = array();
$bad = $ctl->regenerate_topic( new WP_REST_Request( array() ) );
check( 'rest: regenerate-one without a rejected proposal is a 400', $bad instanceof WP_Error && 400 === $bad->data['status'] );
$fake->queue = array( new RuntimeException( 'Fake API: HTTP 500: boom' ) );
$res = $ctl->suggest_topics( new WP_REST_Request( array( 'brief' => 'x' ) ) );
check( 'rest: a provider failure is a 502 and is written to the AI error log', $res instanceof WP_Error && 502 === $res->data['status'] && 'blog_pilot_topics' === \UxStudio\Modules\AiAssistant\ErrorLogger::$log[ count( \UxStudio\Modules\AiAssistant\ErrorLogger::$log ) - 1 ]['context']['scope'] );

// ── 6. Schedule endpoint: permissions and validation ────────────────────────────────────────────
fresh();
add_post( 200, 'future', '2030-01-01 10:00:00', 'Scheduled', true );
add_post( 201, 'draft', '2026-11-01 10:00:00', 'Draft', true );
add_post( 202, 'publish', '2026-11-01 10:00:00', 'Published', true );
add_post( 203, 'draft', '2026-11-01 10:00:00', 'Page-like', false, array( 'post_type' => 'page' ) );
$sched = fn( int $id, string $dt ) => $ctl->schedule_post( new WP_REST_Request( array( 'id' => $id, 'datetime' => $dt ) ) );
$future = ( new DateTimeImmutable( '+3 days', new DateTimeZone( 'Europe/Prague' ) ) )->setTime( 9, 15 );
$ok = $sched( 200, $future->format( 'Y-m-d\TH:i' ) );
check( 'schedule: a scheduled post gets the new date (datetime-local format), status future, local and GMT stored', $ok instanceof WP_REST_Response && 'future' === $ok->data['data']['status'] && $future->format( 'Y-m-d 09:15:00' ) === $ok->data['data']['datetime'] && 'future' === end( $GLOBALS['t']['updates'] )['post_status'] && end( $GLOBALS['t']['updates'] )['post_date_gmt'] === $future->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' ) && true === end( $GLOBALS['t']['updates'] )['edit_date'] );
$ok = $sched( 201, $future->format( 'Y-m-d H:i' ) );
check( 'schedule: a draft can be scheduled too (space-separated format)', $ok instanceof WP_REST_Response && 'future' === $GLOBALS['t']['posts'][201]->post_status );
foreach ( array( 'garbage', '2026-13-40 10:00', '2030-02-30T10:00', '', '2030-01-01', '2030-01-01T25:00' ) as $bad ) {
	$r = $sched( 200, $bad );
	check( "schedule: invalid date '$bad' -> 422", $r instanceof WP_Error && 422 === $r->data['status'] && 'uxstudio_invalid_date' === $r->code );
}
$r = $sched( 200, '2020-01-01 10:00' );
check( 'schedule: a date in the past -> 422', $r instanceof WP_Error && 422 === $r->data['status'] && 'uxstudio_date_in_past' === $r->code );
$r = $sched( 202, $future->format( 'Y-m-d H:i' ) );
check( 'schedule: a published post cannot be rescheduled (409)', $r instanceof WP_Error && 409 === $r->data['status'] );
$r = $sched( 203, $future->format( 'Y-m-d H:i' ) );
check( 'schedule: a page or unknown id -> 404', $r instanceof WP_Error && 404 === $r->data['status'] && $sched( 9999, $future->format( 'Y-m-d H:i' ) )->data['status'] === 404 );
$GLOBALS['t']['caps']['edit_post:200'] = false;
$r = $sched( 200, $future->format( 'Y-m-d H:i' ) );
check( 'schedule: edit_post is checked for that very post (403)', $r instanceof WP_Error && 403 === $r->data['status'] );
unset( $GLOBALS['t']['caps']['edit_post:200'] );
add_post( 204, 'draft', '2026-11-01 10:00:00', 'Contributor draft', true );
$GLOBALS['t']['caps']['publish_post:204'] = false;
$r = $sched( 204, $future->format( 'Y-m-d H:i' ) );
check( 'schedule: scheduling a draft needs publish_post for it (403 for a contributor)', $r instanceof WP_Error && 403 === $r->data['status'] && 'draft' === $GLOBALS['t']['posts'][204]->post_status );
$GLOBALS['t']['update_fails'] = true;
$r = $sched( 200, $future->format( 'Y-m-d H:i' ) );
$GLOBALS['t']['update_fails'] = false;
check( 'schedule: a failed wp_update_post is reported as 500', $r instanceof WP_Error && 500 === $r->data['status'] );

// ── 7. Review: preview, approve, bulk approve, reject, regenerate ───────────────────────────────
$fake = fresh();
add_post( 300, 'draft', '2026-11-20 10:00:00', 'Draft <b>A</b>', true );
$wf = new PostWorkflow( clock_at( '2026-11-26 09:00:00' ) );
$pv = $wf->preview( 300 );
check( 'review: preview returns sanitised content (no script), plain title, edit link', ! str_contains( $pv['content'], 'script' ) && 'Draft A' === $pv['title'] && str_contains( $pv['edit_url'], 'post=300' ) );
$ap = $wf->approve( 300, 'now' );
check( 'review: approve now publishes with the current date', 'publish' === $ap['status'] && 'publish' === $GLOBALS['t']['posts'][300]->post_status && '2026-11-26 09:00:00' === end( $GLOBALS['t']['updates'] )['post_date'] );
$again = $wf->approve( 300, 'now' );
check( 'review: an already published post cannot be approved again (409)', $again instanceof WP_Error && 409 === $again->data['status'] );
add_post( 301, 'pending', '2026-11-20 10:00:00', 'Pending', true );
$ap = $wf->approve( 301, 'schedule', '2026-12-01 09:00' );
check( 'review: approve at a chosen datetime schedules it', 'future' === $ap['status'] && '2026-12-01 09:00:00' === $ap['datetime'] );
add_post( 302, 'draft', '2026-11-20 10:00:00', 'Plan me', true );
$GLOBALS['wpdb']->gen_posts[] = array( 'generator_id' => 1, 'post_id' => 302, 'topic' => 'T', 'article_type' => 'how-to', 'keyword' => 'k', 'summary' => '' );
$GLOBALS['wpdb']->generators[1] = array( 'id' => 1, 'title' => 'G', 'topics' => '["T"]', 'article_types' => '[]', 'provider' => '', 'model' => '', 'config' => json_encode( array( 'publish_plan' => array( 'window_start' => '14:00', 'window_end' => '15:00' ) ) ), 'schedule_type' => 'daily', 'schedule_config' => '{}', 'posts_per_run' => 1, 'status' => 'active' );
$ap = $wf->approve( 302, 'plan' );
check( 'review: approve into the planned slot uses the plan of the post\'s generator', 'future' === $ap['status'] && '2026-11-26 14:30:00' === $ap['datetime'], (string) ( $ap['datetime'] ?? '' ) );
$bad = $wf->approve( 302, 'bogus' );
check( 'review: unknown mode is rejected', $bad instanceof WP_Error );

fresh();
add_post( 310, 'draft', '2026-11-20 10:00:00', 'A', true );
add_post( 311, 'pending', '2026-11-21 10:00:00', 'B', true );
add_post( 312, 'draft', '2026-11-22 10:00:00', 'C', true );
add_post( 313, 'publish', '2026-11-22 10:00:00', 'Already live', true );
add_post( 314, 'draft', '2026-11-23 10:00:00', 'Not mine', true );
add_post( 315, 'future', '2026-11-26 10:30:00', 'Occupied today' );
$GLOBALS['t']['caps']['publish_post:314'] = false;
$wf  = new PostWorkflow( clock_at( '2026-11-26 09:00:00' ) );
$bulk = $wf->bulk_approve( array( 312, 310, 311, 313, 314, 999 ) );
$slots = array_column( $bulk['scheduled'], 'datetime', 'id' );
check( 'bulk: auto-plan gives the oldest draft the earliest slot, one per day, skipping the busy day', array( 310 => '2026-11-27 09:30:00', 311 => '2026-11-28 09:30:00', 312 => '2026-11-29 09:30:00' ) === $slots, json_encode( $slots ) );
check( 'bulk: scheduled posts are future posts with the planned date', 'future' === $GLOBALS['t']['posts'][310]->post_status && '2026-11-27 09:30:00' === $GLOBALS['t']['posts'][310]->post_date );
$reasons = array_column( $bulk['skipped'], 'reason', 'id' );
check( 'bulk: published, forbidden and missing posts are skipped with a reason', 'uxstudio_post_status' === ( $reasons[313] ?? '' ) && 'uxstudio_forbidden' === ( $reasons[314] ?? '' ) && 'uxstudio_post_not_found' === ( $reasons[999] ?? '' ) );
fresh();
add_post( 320, 'draft', '2026-11-20 10:00:00', 'A', true );
add_post( 321, 'draft', '2026-11-21 10:00:00', 'B', true );
$bulk = ( new PostWorkflow( clock_at( '2026-11-26 09:00:00' ) ) )->bulk_approve( array( 320, 321 ), array( 'per' => 2, 'unit' => 'day', 'window_start' => '09:00', 'window_end' => '10:00' ) );
check( 'bulk: 2 per day puts both on the same day at different times', 2 === count( $bulk['scheduled'] ) && substr( $bulk['scheduled'][0]['datetime'], 0, 10 ) === substr( $bulk['scheduled'][1]['datetime'], 0, 10 ) && $bulk['scheduled'][0]['datetime'] !== $bulk['scheduled'][1]['datetime'] );
$r = $ctl->bulk_approve( new WP_REST_Request( array( 'post_ids' => array(), 'plan' => null ) ) );
check( 'bulk rest: no posts -> 400; nonsense plan -> 422', $r instanceof WP_Error && 400 === $r->data['status'] && 422 === $ctl->bulk_approve( new WP_REST_Request( array( 'post_ids' => array( 320 ), 'plan' => array( 'mode' => 'range' ) ) ) )->data['status'] );
add_post( 322, 'draft', '2026-11-20 10:00:00', 'E', true );
add_post( 323, 'draft', '2026-11-21 10:00:00', 'F', true );
$r = $ctl->bulk_approve( new WP_REST_Request( array( 'post_ids' => array( 322, 323 ) ) ) );
check( 'bulk rest: works through the controller without a plan (default plan)', $r instanceof WP_REST_Response && 0 === count( $r->data['data']['skipped'] ) );

fresh();
add_post( 330, 'draft', '2026-11-20 10:00:00', 'R', true );
add_post( 331, 'publish', '2026-11-20 10:00:00', 'Live', true );
$wf = new PostWorkflow( clock_at( '2026-11-26 09:00:00' ) );
$r  = $wf->reject( 330 );
check( 'reject: moves the post to the trash', 'trash' === $r['status'] && array( 330 ) === $GLOBALS['t']['trashed'] );
$r = $wf->reject( 331 );
check( 'reject: a published post is not trashed from the review (409)', $r instanceof WP_Error && 409 === $r->data['status'] );
$GLOBALS['t']['caps']['delete_post:331'] = false;
add_post( 332, 'draft', '2026-11-20 10:00:00', 'No delete', true );
$GLOBALS['t']['caps']['delete_post:332'] = false;
$r = $wf->reject( 332 );
check( 'reject: delete_post is checked for that post (403)', $r instanceof WP_Error && 403 === $r->data['status'] && 'draft' === $GLOBALS['t']['posts'][332]->post_status );

// regenerate through the controller
$fake = fresh();
add_post( 340, 'draft', '2026-11-20 10:00:00', 'Old title', true, array( 'post_content' => '<p>old</p>' ) );
$GLOBALS['wpdb']->generators[1] = array( 'id' => 1, 'title' => 'G', 'topics' => '["T"]', 'article_types' => '[]', 'provider' => '', 'model' => '', 'config' => json_encode( array( 'length' => 'medium', 'language' => 'cs' ) ), 'schedule_type' => 'daily', 'schedule_config' => '{}', 'posts_per_run' => 1, 'status' => 'active' );
$GLOBALS['wpdb']->gen_posts[] = array( 'generator_id' => 1, 'post_id' => 340, 'topic' => 'Moje téma', 'article_type' => 'listicle', 'keyword' => 'klíč', 'summary' => 'Úhel', 'tokens_used' => 10 );
$fake->queue = array( article_json( 700, 'Nový titulek' ) );
$r = $ctl->regenerate_post( new WP_REST_Request( array( 'id' => 340 ) ) );
check( 'regenerate: rewrites title and body of the same post, keeps its status', $r instanceof WP_REST_Response && 'Nový titulek' === $GLOBALS['t']['posts'][340]->post_title && str_contains( $GLOBALS['t']['posts'][340]->post_content, 'Materiály' ) && 'draft' === $GLOBALS['t']['posts'][340]->post_status && ! isset( end( $GLOBALS['t']['updates'] )['post_status'] ) );
check( 'regenerate: uses the stored topic, type, keyword and angle', str_contains( $fake->calls[0]['user'], 'Moje téma' ) && str_contains( $fake->calls[0]['user'], 'klíč' ) && str_contains( $fake->calls[0]['user'], 'Úhel' ) && str_contains( $fake->calls[0]['user'], 'Listicle' ) );
check( 'regenerate: tokens are logged and added to the post\'s row', 'blog_pilot' === \UxStudio\Modules\AiAssistant\UsageTracker::$log[0][2] && str_contains( implode( ' ', $GLOBALS['wpdb']->queries ), 'tokens_used = tokens_used + 300' ) );
add_post( 341, 'publish', '2026-11-20 10:00:00', 'Live', true );
$r = $ctl->regenerate_post( new WP_REST_Request( array( 'id' => 341 ) ) );
add_post( 342, 'draft', '2026-11-20 10:00:00', 'Manual draft' );
$r2 = $ctl->regenerate_post( new WP_REST_Request( array( 'id' => 342 ) ) );
$GLOBALS['t']['caps']['edit_post:340'] = false;
$r3 = $ctl->regenerate_post( new WP_REST_Request( array( 'id' => 340 ) ) );
check( 'regenerate: published -> 409, not a Blog Pilot post -> 404, no edit_post -> 403', 409 === $r->data['status'] && 404 === $r2->data['status'] && 403 === $r3->data['status'] );

// ── 8. Calendar ─────────────────────────────────────────────────────────────────────────────────
fresh();
add_post( 400, 'future', '2026-11-27 10:30:00', 'Scheduled Friday', true );
add_post( 401, 'future', '2026-11-27 09:00:00', 'Manual scheduled' );
add_post( 402, 'draft', '2026-11-03 08:00:00', 'Draft early', true );
add_post( 403, 'publish', '2026-11-10 12:00:00', 'Generated live', true );
add_post( 404, 'publish', '2026-11-10 13:00:00', 'Not generated, published' );
add_post( 405, 'future', '2026-12-01 09:00:00', 'First days of December (shown in the grid)', true );
add_post( 406, 'future', '2027-03-01 09:00:00', 'Far away', true );
add_post( 407, 'future', '2026-11-12 09:00:00', 'Not editable', true );
$GLOBALS['t']['caps']['edit_post:407'] = false;
$cal = $ctl->get_calendar( new WP_REST_Request( array( 'year' => 2026, 'month' => 11 ) ) );
$m   = $cal->data['data'];
$days = array();
foreach ( $m['weeks'] as $w ) { foreach ( $w as $d ) { $days[ $d['date'] ] = $d; } }
check( 'calendar: core structure (year, month, prev/next, timezone, weeks x 7)', 2026 === $m['year'] && 11 === $m['month'] && array( 'year' => 2026, 'month' => 10 ) === $m['prev'] && array( 'year' => 2026, 'month' => 12 ) === $m['next'] && 'Europe/Prague' === $m['timezone'] && count( $m['weeks'] ) >= 4 && 7 === count( $m['weeks'][0] ) );
check( 'calendar: weeks start on Monday per the site setting', 1 === $m['weeks'][0][0]['weekday'] && '2026-10-26' === $m['weeks'][0][0]['date'] );
$fri = $days['2026-11-27']['items'];
check( 'calendar: day holds generated and manually scheduled posts sorted by time', 2 === count( $fri ) && 'Manual scheduled' === $fri[0]['title'] && 'Scheduled Friday' === $fri[1]['title'] && '09:00' === $fri[0]['time'] && '10:30' === $fri[1]['time'] );
$it = $fri[1];
check( 'calendar: item has id, status, time, edit link, generated flag and reschedule flag', 400 === $it['id'] && 'future' === $it['status'] && str_contains( $it['edit_url'], 'post=400' ) && true === $it['generated'] && true === $it['can_reschedule'] && false === $fri[0]['generated'] );
check( 'calendar: a generated draft and a generated published post are shown, a plain published post is not', 1 === count( $days['2026-11-03']['items'] ) && true === $days['2026-11-03']['items'][0]['can_reschedule'] && 1 === count( $days['2026-11-10']['items'] ) && 'Generated live' === $days['2026-11-10']['items'][0]['title'] && false === $days['2026-11-10']['items'][0]['can_reschedule'] );
check( 'calendar: posts of neighbouring days that appear in the grid are included, far ones are not', isset( $days['2026-12-01'] ) && 1 === count( $days['2026-12-01']['items'] ) && ! isset( $days['2027-03-01'] ) );
check( 'calendar: posts the user may not edit are left out', 0 === count( $days['2026-11-12']['items'] ) );
$bad = $ctl->get_calendar( new WP_REST_Request( array( 'year' => 1800, 'month' => 5 ) ) );
$bad2 = ( new CalendarService() )->month( 2026, 13 );
check( 'calendar: invalid year/month -> 400', $bad instanceof WP_Error && 400 === $bad->data['status'] && $bad2 instanceof WP_Error );
$GLOBALS['t']['options']['start_of_week'] = 0;
$sun = $ctl->get_calendar( new WP_REST_Request( array( 'year' => 2026, 'month' => 11 ) ) )->data['data'];
$GLOBALS['t']['options']['start_of_week'] = 1;
check( 'calendar: WordPress "week starts on Sunday" is honoured', 7 === $sun['weeks'][0][0]['weekday'] && '2026-11-01' === $sun['weeks'][0][0]['date'] );

// ── 9. Migration of an old generator row, run through the Scheduler ─────────────────────────────
$fake = fresh();
$GLOBALS['wpdb']->generators[7] = array(
	'id' => 7, 'title' => 'Old generator', 'topics' => '["Jak vybrat stůl","Péče o dřevo"]', 'article_types' => '["how-to","listicle"]',
	'provider' => 'claude', 'model' => 'claude-old', 'config' => '{"tone":"friendly","length":"medium","language":"cs","post_status":"pending","author_id":3,"category_id":8}',
	'schedule_type' => 'weekly', 'schedule_config' => '{"time_from":"08:00","time_to":"18:00","days_of_week":[1,3]}', 'posts_per_run' => 1, 'status' => 'active', 'total_posts' => 4,
);
$mgr = new GeneratorManager();
$old = $mgr->get( 7 );
check( 'migration: an old row hydrates (no publish_plan, no topic_meta, no keyword columns)', is_array( $old->config ) && ! isset( $old->config['publish_plan'] ) && 2 === count( $old->topics ) && 'weekly' === $old->schedule_type );
$fake->queue = array( article_json( 700 ) );
$run = ( new Scheduler() )->run_manual( 7 );
$post = $GLOBALS['t']['posts'][ $run['post_id'] ] ?? null;
check( 'migration: the old generator still runs on the new pipeline (pending post, author, category kept)', true === $run['success'] && 'pending' === $post->post_status && 3 === $post->post_author && array( 8 ) === $GLOBALS['t']['post_cats'][ $run['post_id'] ] && 'pending' === $run['status'] && null === $run['scheduled_for'] );
$logged = end( $GLOBALS['wpdb']->gen_posts );
check( 'migration: the log row is written with topic, type, tokens and the new keyword column', 7 === $logged['generator_id'] && in_array( $logged['topic'], array( 'Jak vybrat stůl', 'Péče o dřevo' ), true ) && in_array( $logged['article_type'], array( 'how-to', 'listicle' ), true ) && 300 === $logged['tokens_used'] && 'masivní stůl' === $logged['keyword'] );
check( 'migration: the provider and model of the row are used', 'fake-1' === $fake->calls[0]['model'] || 'claude-old' === $fake->calls[0]['model'] );
$GLOBALS['wpdb']->reject_new_columns = true;
$fake->queue = array( article_json( 700 ) );
$cnt = count( $GLOBALS['wpdb']->gen_posts );
$run2 = ( new Scheduler() )->run_manual( 7 );
check( 'migration: before the schema upgrade (no keyword column) the log row is still written', true === $run2['success'] && $cnt + 1 === count( $GLOBALS['wpdb']->gen_posts ) && ! isset( end( $GLOBALS['wpdb']->gen_posts )['keyword'] ) );
$GLOBALS['wpdb']->reject_new_columns = false;

$mgr->update( 7, array( 'config' => array( 'tone' => 'formal', 'post_status' => 'future', 'publish_plan' => array( 'per' => 2, 'window_start' => '09:00', 'window_end' => '10:00' ), 'evil' => '<script>', 'length' => 'huge', 'topic_meta' => array( 'Jak vybrat stůl' => array( 'summary' => 'Úhel', 'keyword' => 'stůl', 'article_type' => 'faq' ), 'Bad' => array( 'article_type' => 'nope' ) ) ) ) );
$new = $mgr->get( 7 );
check( 'migration: updating the config merges into the stored one (author, category survive), unknown keys and bad values are dropped', 3 === $new->config['author_id'] && 8 === $new->config['category_id'] && 'formal' === $new->config['tone'] && 'future' === $new->config['post_status'] && ! isset( $new->config['evil'] ) && 'medium' === $new->config['length'] && 2 === $new->config['publish_plan']['per'] );
check( 'migration: topic_meta is kept with a valid type only', 'faq' === $new->config['topic_meta']['Jak vybrat stůl']['article_type'] && '' === $new->config['topic_meta']['Bad']['article_type'] );
$mgr->update( 7, array( 'config' => array( 'publish_plan' => null ) ) );
check( 'migration: publish_plan null removes the plan', ! isset( $mgr->get( 7 )->config['publish_plan'] ) );
$fake->queue = array( article_json( 700 ) );
$run3 = ( new Scheduler() )->run_manual( 7, array( 'topic' => 'Vlastní téma', 'article_type' => 'faq', 'keyword' => 'kw', 'summary' => 'S' ) );
$last = end( $fake->calls )['user'];
check( 'run with a chosen topic (from the suggestions) uses it, with its type and keyword', true === $run3['success'] && str_contains( $last, 'Vlastní téma' ) && str_contains( $last, 'FAQ' ) && str_contains( $last, 'kw' ), $last );
$mgr->update( 7, array( 'topics' => array( 'Jak vybrat stůl' ), 'config' => array( 'post_status' => 'draft' ) ) );
$fake->queue = array( article_json( 700 ) );
( new Scheduler() )->run_manual( 7 );
$last = end( $fake->calls )['user'];
check( 'run: stored topic_meta (angle, keyword, type) is applied when that topic is picked', str_contains( $last, 'Úhel' ) && str_contains( $last, 'FOCUS KEYWORD: stůl' ) && str_contains( $last, 'FAQ' ), $last );

// SQL safety of the status filter
$GLOBALS['wpdb']->queries = array();
$mgr->get_generated_posts( 0, 1, 20, array( "draft' OR '1'='1", 'pending' ) );
$sql = implode( "\n", $GLOBALS['wpdb']->queries );
check( 'sql: a hostile status value cannot break out of the prepared statement', ! str_contains( $sql, "OR '1'='1" ) );

echo PHP_EOL . ( 0 === $failed ? 'All checks passed.' : "$failed check(s) FAILED." ) . PHP_EOL;
exit( 0 === $failed ? 0 : 1 );
