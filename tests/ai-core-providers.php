<?php
// Test only: never runs over HTTP (the release zip also excludes tests/).
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { exit; }
/**
 * The plugin side of the shared AI core (PLAN.md §45, F1): ProviderFactory builds the core providers
 * (vendor/ux1-ai-core) behind the plugin contract, with the same ids, labels and models as before the extraction,
 * and plain requests go through the WordPress HTTP API (WpHttpClient) with the same error messages.
 *
 *   php tests/ai-core-providers.php
 *
 * Needs no WordPress: doubles of Core\Security, Core\Settings and the WP HTTP functions. Exits non-zero on failure.
 *
 * @package UxStudio
 */

// phpcs:disable

use UxStudio\Modules\AiAssistant\ProviderFactory;
use UxStudio\Modules\AiAssistant\Providers\AiProviderInterface;

define( 'ABSPATH', __DIR__ . '/' );
define( 'WPINC', 'wp-includes' );
$root = dirname( __DIR__ );
define( 'UXSTUDIO_PATH', $root . '/' );

eval( 'namespace UxStudio\Core; final class Security { public static array $secrets = array(); public static function get_secret( string $o ): string { return self::$secrets[ $o ] ?? ""; } }' );
eval( 'namespace UxStudio\Core; final class Settings { public static array $values = array(); public function __construct( string $o ) {} public function get( string $k, $d = null ) { return self::$values[ $k ] ?? $d; } }' );

final class WP_Error { public function __construct( private string $code = '', private string $message = '' ) {} public function get_error_message() { return $this->message; } }
$GLOBALS['uxs_http'] = array( 'calls' => array(), 'next' => null );
function wp_remote_request( $url, $args ) { $GLOBALS['uxs_http']['calls'][] = array( $url, $args ); return $GLOBALS['uxs_http']['next']; }
function is_wp_error( $thing ) { return $thing instanceof WP_Error; }
function wp_remote_retrieve_response_code( $r ) { return $r['response']['code'] ?? 0; }
function wp_remote_retrieve_body( $r ) { return $r['body'] ?? ''; }

require $root . '/includes/Autoloader.php';
\UxStudio\Autoloader::register();
require $root . '/vendor/ux1-ai-core/autoload.php';

$failed = 0;
function check( string $name, bool $ok, string $detail = '' ): void {
	global $failed;
	echo ( $ok ? 'ok   ' : 'FAIL ' ) . $name . ( $ok || '' === $detail ? '' : ' - ' . $detail ) . PHP_EOL;
	if ( ! $ok ) { ++$failed; }
}

// Same catalogue as before the extraction (ids, labels, model ids drive the settings selects and saved values).
$all = ProviderFactory::get_all_providers();
check( 'factory: same provider ids in the same order', array( 'claude', 'openai', 'deepseek', 'claude_code' ) === array_keys( $all ) );
check( 'factory: same labels', array( 'Claude (Anthropic)', 'ChatGPT (OpenAI)', 'DeepSeek', 'Claude Code Bridge (local PC)' ) === array_column( $all, 'label' ) );
check( 'factory: same model ids', array( 'claude-opus-4-8', 'claude-sonnet-5', 'claude-haiku-4-5-20251001' ) === array_keys( $all['claude']['models'] )
	&& array( 'gpt-5.1', 'gpt-5.1-mini', 'gpt-5.1-nano' ) === array_keys( $all['openai']['models'] )
	&& array( 'deepseek-chat', 'deepseek-reasoner' ) === array_keys( $all['deepseek']['models'] )
	&& array( 'claude-code' ) === array_keys( $all['claude_code']['models'] ) );

// A provider from the factory satisfies the plugin contract and talks through wp_remote_request().
\UxStudio\Core\Security::$secrets['uxstudio_secret_ai_assistant_claude_api_key'] = 'sk-test';
$GLOBALS['uxs_http']['next'] = array( 'response' => array( 'code' => 200 ), 'body' => '{"content":[{"type":"text","text":"OK"}],"usage":{"input_tokens":3,"output_tokens":1}}' );
$claude = ProviderFactory::create( 'claude' );
check( 'create: returns the plugin contract', $claude instanceof AiProviderInterface && 'claude' === $claude->get_id() );
$out  = $claude->generate_content( 'S', 'U', 'claude-sonnet-5' );
$call = $GLOBALS['uxs_http']['calls'][0] ?? array( '', array() );
check( 'create: generate goes through wp_remote_request (POST, Anthropic URL)', 'https://api.anthropic.com/v1/messages' === $call[0] && 'POST' === $call[1]['method'] );
check( 'create: the secret key and API version are sent', 'sk-test' === ( $call[1]['headers']['x-api-key'] ?? '' ) && '2023-06-01' === ( $call[1]['headers']['anthropic-version'] ?? '' ) );
check( 'create: answer and usage come back', 'OK' === $out['content'] && 3 === $out['usage']['input_tokens'] );

$GLOBALS['uxs_http']['next'] = new WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out' );
$message = '';
try { $claude->generate_content( 'S', 'U', 'claude-sonnet-5' ); } catch ( \RuntimeException $e ) { $message = $e->getMessage(); }
check( 'http: a WP_Error becomes the same exception message as before', 'cURL error 28: Operation timed out' === $message, $message );

\UxStudio\Core\Settings::$values = array( 'default_provider' => 'claude_code', 'claude_code_url' => 'http://127.0.0.1:7777', 'claude_code_workdir' => 'D:/w' );
\UxStudio\Core\Security::$secrets['uxstudio_secret_ai_assistant_claude_code_api_key'] = 'tok';
$GLOBALS['uxs_http']['next'] = array( 'response' => array( 'code' => 200 ), 'body' => '{"success":true,"content":"Ahoj"}' );
$bridge = ProviderFactory::create();
$text   = '';
$bridge->stream_chat( array( array( 'role' => 'user', 'content' => 'x' ) ), 'claude-code', static function ( $c ) use ( &$text ) { $text .= $c; } );
$call = end( $GLOBALS['uxs_http']['calls'] );
check( 'create(): default provider from settings, bridge URL/token/workdir wired', 'claude_code' === $bridge->get_id() && 'http://127.0.0.1:7777/api/chat' === $call[0] && 'Bearer tok' === $call[1]['headers']['Authorization'] && 'D:/w' === ( json_decode( $call[1]['body'], true )['workdir'] ?? '' ) );
check( 'bridge: answer streamed as one chunk with estimated usage', 'Ahoj' === $text && $bridge->get_last_stream_usage()['output_tokens'] > 0 );

$message = '';
try { ProviderFactory::create( 'nope' ); } catch ( \RuntimeException $e ) { $message = $e->getMessage(); }
check( 'create: unknown provider keeps its message', 'Unknown AI provider: nope' === $message, $message );

echo PHP_EOL . ( $failed ? "{$failed} failed" : 'all passed' ) . PHP_EOL;
exit( $failed ? 1 : 0 );
