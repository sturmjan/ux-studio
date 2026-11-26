<?php
// Test only: never runs over HTTP (the release zip also excludes tests/).
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { exit; }
/**
 * Image generation in the AI Assistant (ImageService on the shared AI core): the save-to-Media-Library flow, usage
 * logging and limits, permissions, which chat tools are offered (only with "images in chat" on, a key and a provider
 * with tool support), the trust model for images shown in the chat and the settings defaults.
 *
 *   php tests/ai-images.php
 *
 * Needs no WordPress: doubles of Core\Security, Core\Settings, $wpdb, the WP media functions and a fake core HttpClient.
 * Never calls a real image API. Exits non-zero on failure.
 *
 * @package UxStudio
 */

// phpcs:disable

use Ux1\AiCore\Http\HttpClient;
use Ux1\AiCore\Http\HttpResponse;
use Ux1\AiCore\Image\GeminiImageProvider;
use UxStudio\Core\Settings;
use UxStudio\Modules\AiAssistant\ImageService;

define( 'ABSPATH', __DIR__ . '/' );
define( 'WPINC', 'wp-includes' );
$root = dirname( __DIR__ );
define( 'UXSTUDIO_PATH', $root . '/' );

eval( 'namespace UxStudio\Core; final class Security { public static array $secrets = array(); public static function get_secret( string $o ): string { return self::$secrets[ $o ] ?? ""; } public static function store_secret( string $o, string $v ): void { self::$secrets[ $o ] = $v; } }' );
eval( 'namespace UxStudio\Core; final class Settings { public static array $values = array(); public function __construct( string $o ) {} public function get( string $k, $d = null ) { return array_key_exists( $k, self::$values ) ? self::$values[ $k ] : $d; } public function all(): array { return self::$values; } }' );

class UxsFakeWpdb {
	public string $prefix = 'wp_';
	public array $inserts = array();
	public function prepare( $sql, ...$args ) { return $sql; }
	public function get_var( $sql ) { return str_starts_with( $sql, 'SHOW TABLES' ) ? 'wp_uxstudio_ai_assistant_usage' : 0; }
	public function insert( $table, $data, $formats = null ) { $this->inserts[] = $data; return 1; }
}
$GLOBALS['wpdb'] = new UxsFakeWpdb();

$GLOBALS['uxs'] = array( 'user' => 7, 'caps' => array( 'upload_files' => true, 'edit_posts' => true ), 'uploads' => array(), 'attachments' => array(), 'meta' => array(), 'next_id' => 100 );
function __( $text, $domain = null ) { return $text; }
function number_format_i18n( $n ) { return number_format( (float) $n ); }
function get_current_user_id() { return $GLOBALS['uxs']['user']; }
function current_user_can( $cap ) { return ! empty( $GLOBALS['uxs']['caps'][ $cap ] ); }
function wp_strip_all_tags( $text ) { return trim( strip_tags( (string) $text ) ); }
function wp_json_encode( $data ) { return json_encode( $data ); }
function sanitize_title( $title ) { return trim( preg_replace( '/[^a-z0-9]+/', '-', strtolower( (string) $title ) ), '-' ); }
function admin_url( $path = '' ) { return 'https://site.test/wp-admin/' . $path; }
function is_wp_error( $thing ) { return false; }
function wp_upload_bits( $name, $deprecated, $bits ) {
	$GLOBALS['uxs']['uploads'][] = array( 'name' => $name, 'bytes' => strlen( $bits ) );
	return array( 'file' => '/uploads/' . $name, 'url' => 'https://site.test/wp-content/uploads/' . $name, 'error' => false );
}
function wp_insert_attachment( $args, $file, $parent = 0 ) {
	$id = $GLOBALS['uxs']['next_id']++;
	$GLOBALS['uxs']['attachments'][ $id ] = array( 'args' => $args, 'file' => $file, 'parent' => $parent, 'author' => get_current_user_id() );
	return $id;
}
function wp_generate_attachment_metadata( $id, $file ) { return array( 'file' => $file ); }
function wp_update_attachment_metadata( $id, $data ) { $GLOBALS['uxs']['meta'][ $id ]['_wp_attachment_metadata'] = $data; return true; }
function update_post_meta( $id, $key, $value ) { $GLOBALS['uxs']['meta'][ $id ][ $key ] = $value; return true; }
function get_post_meta( $id, $key, $single = false ) { return $GLOBALS['uxs']['meta'][ $id ][ $key ] ?? ''; }
function get_post_type( $id ) { return isset( $GLOBALS['uxs']['attachments'][ $id ] ) ? 'attachment' : 'post'; }
function get_post_field( $field, $id ) { return $GLOBALS['uxs']['attachments'][ $id ]['author'] ?? 0; }
function wp_get_attachment_url( $id ) { return isset( $GLOBALS['uxs']['attachments'][ $id ] ) ? 'https://site.test/wp-content/uploads/' . basename( $GLOBALS['uxs']['attachments'][ $id ]['file'] ) : false; }

require $root . '/includes/Autoloader.php';
\UxStudio\Autoloader::register();
require $root . '/vendor/ux1-ai-core/autoload.php';

$failed = 0;
function check( string $name, bool $ok, string $detail = '' ): void {
	global $failed;
	echo ( $ok ? 'ok   ' : 'FAIL ' ) . $name . ( $ok || '' === $detail ? '' : ' - ' . $detail ) . PHP_EOL;
	if ( ! $ok ) { ++$failed; }
}

/** Fake core HTTP client: replays one response, records the requests. Never touches the network. */
final class UxsFakeHttp implements HttpClient {
	public array $calls = array();
	public function __construct( public HttpResponse $response ) {}
	public function request( string $method, string $url, array $headers = array(), ?string $body = null, int $timeout = 120 ): HttpResponse {
		$this->calls[] = array( $method, $url, $headers, $body );
		return $this->response;
	}
	public function stream( string $url, array $headers, string $body, int $timeout, callable $on_data ): HttpResponse { throw new \LogicException( 'unused' ); }
}

$png     = base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==' );
$gemini  = static fn ( string $mime = 'image/png', string $data = '' ) => new HttpResponse( 200, json_encode( array( 'candidates' => array( array( 'content' => array( 'parts' => array( array( 'inlineData' => array( 'mimeType' => $mime, 'data' => '' !== $data ? $data : base64_encode( $GLOBALS['png'] ) ) ) ) ) ) ) ) ) );
$GLOBALS['png'] = $png;
$reset = static function (): void {
	Settings::$values = array();
	\UxStudio\Core\Security::$secrets = array();
	$GLOBALS['wpdb']->inserts = array();
	$GLOBALS['uxs'] = array( 'user' => 7, 'caps' => array( 'upload_files' => true, 'edit_posts' => true ), 'uploads' => array(), 'attachments' => array(), 'meta' => array(), 'next_id' => 100 );
};

// ── Save-to-media flow ──────────────────────────────────────────────────
$reset();
$http    = new UxsFakeHttp( $gemini() );
$service = new ImageService( new GeminiImageProvider( 'test-key', $http ) );
$saved   = $service->generate( array( 'prompt' => 'A cosy mountain cabin at dusk', 'style' => 'photo', 'aspect_ratio' => '16:9', 'tier' => 'preview' ), 5 );

check( 'media: one file written with the PNG bytes', 1 === count( $GLOBALS['uxs']['uploads'] ) && strlen( $png ) === $GLOBALS['uxs']['uploads'][0]['bytes'] );
check( 'media: filename is a slug of the prompt with the right extension', (bool) preg_match( '/^a-cosy-mountain-cabin-at-dusk-\d{8}-\d{6}\.png$/', $GLOBALS['uxs']['uploads'][0]['name'] ), $GLOBALS['uxs']['uploads'][0]['name'] );
$att = $GLOBALS['uxs']['attachments'][ $saved['attachment_id'] ] ?? array();
check( 'media: attachment has mime, inherit status and the parent post', 'image/png' === ( $att['args']['post_mime_type'] ?? '' ) && 'inherit' === ( $att['args']['post_status'] ?? '' ) && 5 === ( $att['parent'] ?? -1 ) );
check( 'media: alt text comes from the prompt', 'A cosy mountain cabin at dusk' === ( $GLOBALS['uxs']['meta'][ $saved['attachment_id'] ]['_wp_attachment_image_alt'] ?? '' ) );
check( 'media: attachment metadata is generated', isset( $GLOBALS['uxs']['meta'][ $saved['attachment_id'] ]['_wp_attachment_metadata'] ) );
check( 'media: attachment is marked as generated and carries the cost', 1 === ( $GLOBALS['uxs']['meta'][ $saved['attachment_id'] ][ ImageService::META_GENERATED ] ?? 0 ) && 0.0 === (float) $GLOBALS['uxs']['meta'][ $saved['attachment_id'] ][ ImageService::META_COST ] );
check( 'media: result has id, url, Media Library link, size and model', str_ends_with( $saved['url'], '.png' ) && str_contains( $saved['edit_url'], 'upload.php?item=' . $saved['attachment_id'] ) && 1 === $saved['width'] && 'gemini-2.5-flash-image' === $saved['model'] );
check( 'api: Gemini request went through the injected client with the style suffix', 1 === count( $http->calls ) && str_contains( $http->calls[0][1], 'gemini-2.5-flash-image:generateContent' ) && str_contains( (string) $http->calls[0][3], 'realistic photograph' ) );

// Usage logging
check( 'usage: one row with feature "image", provider, model and 0 tokens (Gemini reports none)', 1 === count( $GLOBALS['wpdb']->inserts ) && 'image' === $GLOBALS['wpdb']->inserts[0]['feature'] && 'gemini' === $GLOBALS['wpdb']->inserts[0]['provider'] && 'gemini-2.5-flash-image' === $GLOBALS['wpdb']->inserts[0]['model'] && 0 === $GLOBALS['wpdb']->inserts[0]['input_tokens'] );

// Estimate: paid tier costs money, no API call
$before = count( $http->calls );
$est    = $service->estimate( array( 'prompt' => 'x', 'tier' => 'final', 'resolution' => '2K' ) );
check( 'estimate: final tier 2K is 0.134 USD and calls no API', 0.134 === $est['cost_usd'] && count( $http->calls ) === $before );

// Permissions, limits, bad input, bad provider output
$reset();
$GLOBALS['uxs']['caps']['upload_files'] = false;
$http = new UxsFakeHttp( $gemini() );
try { ( new ImageService( new GeminiImageProvider( 'k', $http ) ) )->generate( array( 'prompt' => 'x' ) ); check( 'perm: without upload_files it fails', false ); }
catch ( \RuntimeException $e ) { check( 'perm: without upload_files it fails with 403 and no API call', 403 === $e->getCode() && 0 === count( $http->calls ) ); }

$reset();
Settings::$values = array( 'daily_request_limit' => 0, 'monthly_token_limit' => 0, 'user_daily_request_limit' => 0 );
$http = new UxsFakeHttp( $gemini() );
$GLOBALS['wpdb'] = new class extends UxsFakeWpdb { public function get_var( $sql ) { return str_starts_with( $sql, 'SHOW TABLES' ) ? 'wp_uxstudio_ai_assistant_usage' : 9999999; } };
Settings::$values = array( 'daily_request_limit' => 10 );
try { ( new ImageService( new GeminiImageProvider( 'k', $http ) ) )->generate( array( 'prompt' => 'x' ) ); check( 'limits: a reached limit blocks generation', false ); }
catch ( \RuntimeException $e ) { check( 'limits: a reached limit blocks generation (429) before any API call', 429 === $e->getCode() && 0 === count( $http->calls ) ); }
$GLOBALS['wpdb'] = new UxsFakeWpdb();

$reset();
try { ( new ImageService( new GeminiImageProvider( 'k', new UxsFakeHttp( $gemini() ) ) ) )->generate( array( 'prompt' => '   ' ) ); check( 'input: empty prompt fails', false ); }
catch ( \RuntimeException $e ) { check( 'input: empty prompt fails with 400', 400 === $e->getCode() ); }

$reset();
$http = new UxsFakeHttp( $gemini( 'image/svg+xml' ) );
try { ( new ImageService( new GeminiImageProvider( 'k', $http ) ) )->generate( array( 'prompt' => 'x' ) ); check( 'mime: unsupported type is refused', false ); }
catch ( \RuntimeException $e ) { check( 'mime: an unsupported image type is refused and nothing is stored', 502 === $e->getCode() && array() === $GLOBALS['uxs']['uploads'] && array() === $GLOBALS['wpdb']->inserts ); }

$reset();
$http = new UxsFakeHttp( new HttpResponse( 401, '{"error":{"message":"bad key"}}' ) );
try { ( new ImageService( new GeminiImageProvider( 'k', $http ) ) )->generate( array( 'prompt' => 'x' ) ); check( 'api error: 401 surfaces', false ); }
catch ( \RuntimeException $e ) { check( 'api error: a provider error is a 502 and nothing is stored or logged', 502 === $e->getCode() && str_contains( $e->getMessage(), '401' ) && array() === $GLOBALS['uxs']['uploads'] && array() === $GLOBALS['wpdb']->inserts ); }

// ── Chat tool offering ──────────────────────────────────────────────────
$reset();
\UxStudio\Core\Security::$secrets[ ImageService::secret_option( 'gemini' ) ] = 'image-key';
check( 'settings: images in chat are off by default', false === ImageService::chat_images_enabled() );
check( 'tool: not offered by default (setting off, key present)', array() === ImageService::chat_tools( 'claude' ) );

Settings::$values = array( 'chat_images_enabled' => true );
$tools = ImageService::chat_tools( 'claude' );
check( 'tool: offered when enabled, a key exists and the provider supports tools', 1 === count( $tools ) && 'generate_image' === $tools[0]['name'] );
check( 'tool: other tool-capable providers get it too', 1 === count( ImageService::chat_tools( 'openai' ) ) && 1 === count( ImageService::chat_tools( 'deepseek' ) ) );
check( 'tool: not offered to the Claude Code Bridge (no tool support)', array() === ImageService::chat_tools( 'claude_code' ) );

Settings::$values = array( 'chat_images_enabled' => '0' );
check( 'tool: a legacy "0" string counts as off', array() === ImageService::chat_tools( 'claude' ) );

Settings::$values = array( 'chat_images_enabled' => true );
\UxStudio\Core\Security::$secrets = array();
check( 'tool: not offered without an image key', array() === ImageService::chat_tools( 'claude' ) );

Settings::$values = array( 'chat_images_enabled' => true, 'image_provider' => 'openai' );
\UxStudio\Core\Security::$secrets[ ImageService::secret_option( 'gemini' ) ] = 'only-gemini-key';
check( 'tool: the key of the OTHER image provider does not count', array() === ImageService::chat_tools( 'claude' ) );
\UxStudio\Core\Security::$secrets[ ImageService::secret_option( 'openai' ) ] = 'openai-key';
check( 'tool: offered with the selected provider key', 1 === count( ImageService::chat_tools( 'claude' ) ) );
check( 'secrets: image keys use their own encrypted options', 'uxstudio_secret_ai_assistant_image_openai_api_key' === ImageService::secret_option( 'openai' ) );

// Chat generation: the tool arguments become a cheap request (preview tier, 1K) and the result is saved.
$reset();
$http = new UxsFakeHttp( $gemini() );
$chat = ( new ImageService( new GeminiImageProvider( 'k', $http ) ) )->generate_for_chat( array( 'prompt' => 'Red bicycle', 'aspect_ratio' => '1:1', 'style' => 'nonsense' ) );
check( 'chat tool: uses the free preview model by default and saves to media', 'gemini-2.5-flash-image' === $chat['model'] && 0.0 === $chat['cost_usd'] && isset( $GLOBALS['uxs']['attachments'][ $chat['attachment_id'] ] ) );
try { ( new ImageService( new GeminiImageProvider( 'k', $http ) ) )->generate_for_chat( array() ); check( 'chat tool: missing prompt fails', false ); }
catch ( \RuntimeException $e ) { check( 'chat tool: a missing prompt is a 400', 400 === $e->getCode() ); }

// ── Trust model: only generated attachments owned by the user are rendered ──
$reset();
$mine = ( new ImageService( new GeminiImageProvider( 'k', new UxsFakeHttp( $gemini() ) ) ) )->generate( array( 'prompt' => 'mine' ) )['attachment_id'];
$GLOBALS['uxs']['attachments'][500] = array( 'file' => '/uploads/other.png', 'author' => 7, 'args' => array(), 'parent' => 0 ); // Attachment NOT made by the tool.
$GLOBALS['uxs']['attachments'][600] = array( 'file' => '/uploads/foreign.png', 'author' => 9, 'args' => array(), 'parent' => 0 );
$GLOBALS['uxs']['meta'][600][ ImageService::META_GENERATED ] = 1; // Generated, but by another user.
check( 'trust: describe() only knows attachments created by the service', null !== ImageService::describe( $mine ) && null === ImageService::describe( 500 ) && null === ImageService::describe( 99999 ) );
check( 'trust: client-sent image lists keep only own generated attachments', array( $mine ) === ImageService::owned_ids( array( $mine, 500, 600, 'https://evil.test/x.png', array( 'id' => $mine ), 0 ), 7 ) );
$msgs = ImageService::expand_message_images( array( array( 'role' => 'assistant', 'content' => 'hi', 'images' => array( $mine, 500 ) ), array( 'role' => 'user', 'content' => 'x' ) ) );
check( 'trust: stored ids are expanded to display data, foreign ids dropped', 1 === count( $msgs[0]['images'] ) && $mine === $msgs[0]['images'][0]['id'] && str_starts_with( $msgs[0]['images'][0]['url'], 'https://site.test/' ) && ! isset( $msgs[1]['images'] ) );

// ── Staff chat tool loop (InternalChatEngine) with a fake chat provider and a fake WP HTTP transport ──
function wp_remote_request( $url, $args ) { $GLOBALS['uxs_http'][] = $url; return array( 'code' => 200, 'body' => json_encode( array( 'candidates' => array( array( 'content' => array( 'parts' => array( array( 'inlineData' => array( 'mimeType' => 'image/png', 'data' => base64_encode( $GLOBALS['png'] ) ) ) ) ) ) ) ) ) ); }
function wp_remote_retrieve_response_code( $r ) { return $r['code']; }
function wp_remote_retrieve_body( $r ) { return $r['body']; }

final class UxsFakeChatProvider implements \UxStudio\Modules\AiAssistant\Providers\AiProviderInterface {
	public array $tools_seen = array();
	public array $tool_results = array();
	public int $calls = 0;
	public function __construct( private int $image_calls ) {}
	public function get_id(): string { return 'claude'; }
	public function get_label(): string { return 'Fake'; }
	public function get_models(): array { return array(); }
	public function generate_content( string $s, string $u, string $m, array $o = array() ): array { return array(); }
	public function stream_chat( array $m, string $model, callable $c, array $o = array() ): void {}
	public function get_last_stream_usage(): array { return array( 'input_tokens' => 0, 'output_tokens' => 0 ); }
	public function chat_with_tools( array $messages, string $model, array $tools = array(), array $options = array() ): array {
		$this->tools_seen[] = array_column( $tools, 'name' );
		++$this->calls;
		if ( $this->calls <= $this->image_calls ) {
			return array( 'content' => null, 'input_tokens' => 10, 'output_tokens' => 5, 'assistant_message' => array( 'role' => 'assistant', 'content' => '' ),
				'tool_calls' => array( array( 'id' => 't' . $this->calls, 'name' => 'generate_image', 'arguments' => array( 'prompt' => 'A red bicycle' . $this->calls ) ) ) );
		}
		return array( 'content' => 'Done, here it is.', 'tool_calls' => array(), 'input_tokens' => 10, 'output_tokens' => 5, 'assistant_message' => array( 'role' => 'assistant', 'content' => 'Done' ) );
	}
	public function format_tool_results( array $r ): array { $this->tool_results = array_merge( $this->tool_results, $r ); return array( array( 'role' => 'tool', 'content' => 'x' ) ); }
	public function test_connection( string $model ): array { return array(); }
}

$run_turn = static function ( UxsFakeChatProvider $provider, array &$ids ): string {
	$engine = new \UxStudio\Modules\AiAssistant\InternalChatEngine();
	$m      = new ReflectionMethod( $engine, 'run_image_tool_turn' );
	$m->setAccessible( true );
	$tools = ImageService::chat_tools( 'claude' );
	return $m->invokeArgs( $engine, array( $provider, 'claude', 'model-x', array( array( 'role' => 'user', 'content' => 'draw a bicycle' ) ), 'sys', $tools, &$ids ) );
};
$chat_case = static function ( int $image_calls, bool $can_upload = true ) use ( $reset ): UxsFakeChatProvider {
	$reset();
	$GLOBALS['uxs_http'] = array();
	Settings::$values = array( 'chat_images_enabled' => true );
	\UxStudio\Core\Security::$secrets[ ImageService::secret_option( 'gemini' ) ] = 'image-key';
	$GLOBALS['uxs']['caps']['upload_files'] = $can_upload;
	return new UxsFakeChatProvider( $image_calls );
};

$provider = $chat_case( 1 );
$ids      = array();
$text     = $run_turn( $provider, $ids );
check( 'chat loop: the model got exactly the generate_image tool', array( 'generate_image' ) === $provider->tools_seen[0] );
check( 'chat loop: a tool call creates one image through the API, saved to media', 1 === count( $ids ) && 1 === count( $GLOBALS['uxs_http'] ) && isset( $GLOBALS['uxs']['attachments'][ $ids[0] ] ) );
check( 'chat loop: the tool result confirms success and contains no URL', str_contains( $provider->tool_results[0]['content'], '"success":true' ) && ! str_contains( $provider->tool_results[0]['content'], 'http' ) );
check( 'chat loop: the final answer is returned and usage is logged for the chat', 'Done, here it is.' === $text && 'internal_chat' === end( $GLOBALS['wpdb']->inserts )['feature'] );

$provider = $chat_case( 5 ); // A model looping on the tool.
$ids      = array();
$run_turn( $provider, $ids );
check( 'chat loop: at most ' . ImageService::MAX_CHAT_IMAGES_PER_TURN . ' images per reply (cost guard)', ImageService::MAX_CHAT_IMAGES_PER_TURN === count( $ids ) && ImageService::MAX_CHAT_IMAGES_PER_TURN === count( $GLOBALS['uxs_http'] ) );

$provider = $chat_case( 1, false );
$ids      = array();
$run_turn( $provider, $ids );
check( 'chat loop: a user without upload_files gets a tool error, no image and no API call', array() === $ids && array() === $GLOBALS['uxs_http'] && str_contains( $provider->tool_results[0]['content'], '"success":false' ) );

// ── Settings schema: defaults, help, encrypted keys ─────────────────────
$module_src = (string) file_get_contents( $root . '/includes/Modules/AiAssistant/Module.php' );
foreach ( array( 'image_provider', 'image_gemini_api_key', 'image_openai_api_key', 'image_openai_model', 'chat_images_enabled' ) as $key ) {
	$ok = (bool) preg_match( "/'key'\\s*=>\\s*'" . $key . "'.*?'help'\\s*=>\\s*__\\(/s", $module_src );
	check( "schema: $key has a help text", $ok );
}
check( 'schema: chat_images_enabled defaults to false', (bool) preg_match( "/'key'\\s*=>\\s*'chat_images_enabled',.*?'default'\\s*=>\\s*false,/s", $module_src ) );
check( 'schema: image provider defaults to gemini', (bool) preg_match( "/'key'\\s*=>\\s*'image_provider',.*?'default'\\s*=>\\s*'gemini',/s", $module_src ) );
check( 'schema: image keys are stored as secrets, not plain settings', str_contains( $module_src, "'image_gemini' => self::SECRET_IMAGE_GEMINI" ) && str_contains( $module_src, "'image_openai' => self::SECRET_IMAGE_OPENAI" ) );
check( 'schema: OpenAI image models come from the core', array_keys( ImageService::openai_models() ) === array_keys( \Ux1\AiCore\Image\OpenAiImageProvider::get_models() ) && isset( ImageService::openai_models()['gpt-image-1'] ) );

echo PHP_EOL . ( $failed ? "FAILED: $failed" : 'All image checks passed.' ) . PHP_EOL;
exit( $failed ? 1 : 0 );
