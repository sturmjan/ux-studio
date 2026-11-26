<?php
// Test only: never runs over HTTP (the release zip also excludes tests/).
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { exit; }
/**
 * Characterization test of UsageLimiter and PromptBuilder::build_system_prompt() before they move to the shared
 * AI core (PLAN.md §45, F0). Pins today's behaviour: limit defaults, 0 = unlimited, the anonymous user skipping the
 * per-user limit, the status maths, and the order and wording of the system prompt sections.
 *
 *   php tests/ai-limits-prompt-characterization.php
 *
 * Needs no WordPress: doubles of Core\Settings, $wpdb and a few WP functions. Exits non-zero when a case fails.
 *
 * @package UxStudio
 */

// phpcs:disable

use UxStudio\Core\Settings;
use UxStudio\Modules\AiAssistant\PromptBuilder;
use UxStudio\Modules\AiAssistant\UsageLimiter;

define( 'ABSPATH', __DIR__ . '/' );

// Settings double (declared via eval so the guard above stays the first statement): values per test case.
eval( 'namespace UxStudio\Core; final class Settings { public static array $values = array(); public function __construct( string $option ) {} public function get( string $key, $default = null ) { return array_key_exists( $key, self::$values ) ? self::$values[ $key ] : $default; } }' );

$GLOBALS['uxs_user_id'] = 0;
function get_current_user_id() { return $GLOBALS['uxs_user_id']; }
function __( $text, $domain = null ) { return $text; }
function number_format_i18n( $number ) { return number_format( (float) $number, 0, ',', ' ' ); }
function get_bloginfo( $what ) { return 'Pobyty QA'; }
function wp_strip_all_tags( $text ) { return trim( strip_tags( (string) $text ) ); }

/** $wpdb double: answers the two usage queries with configured numbers. */
final class UxsFakeWpdb {
	public string $prefix = 'wp_';
	public int $monthly_tokens = 0;
	public int $daily_requests = 0;
	public array $user_requests = array();
	public function prepare( $sql, ...$args ) { return $sql . ' |' . implode( '|', array_map( 'strval', $args ) ); }
	public function get_var( $sql ) {
		if ( str_starts_with( $sql, 'SHOW TABLES' ) ) { return 'wp_uxstudio_ai_assistant_usage'; }
		if ( str_contains( $sql, 'SUM(input_tokens' ) ) { return $this->monthly_tokens; }
		if ( str_contains( $sql, 'user_id = %d' ) ) {
			$user = (int) substr( $sql, strrpos( $sql, '|' ) + 1 );
			return $this->user_requests[ $user ] ?? 0;
		}
		if ( str_contains( $sql, 'COUNT(*)' ) ) { return $this->daily_requests; }
		return null;
	}
}
$GLOBALS['wpdb'] = new UxsFakeWpdb();

$dir = dirname( __DIR__ ) . '/includes/Modules/AiAssistant/';
spl_autoload_register( static function ( $class ) use ( $dir ) {
	$prefix = 'UxStudio\\Modules\\AiAssistant\\';
	if ( str_starts_with( $class, $prefix ) ) {
		$file = $dir . str_replace( '\\', '/', substr( $class, strlen( $prefix ) ) ) . '.php';
		if ( is_file( $file ) ) { require $file; }
	}
} );

$failed = 0;
function check( string $name, bool $ok, string $detail = '' ): void {
	global $failed;
	echo ( $ok ? 'ok   ' : 'FAIL ' ) . $name . ( $ok || '' === $detail ? '' : ' - ' . $detail ) . PHP_EOL;
	if ( ! $ok ) { ++$failed; }
}
function reset_case( array $settings = array(), int $tokens = 0, int $requests = 0, array $users = array(), int $user = 0 ): void {
	Settings::$values                 = $settings;
	$GLOBALS['wpdb']->monthly_tokens = $tokens;
	$GLOBALS['wpdb']->daily_requests = $requests;
	$GLOBALS['wpdb']->user_requests  = $users;
	$GLOBALS['uxs_user_id']          = $user;
}

// ── UsageLimiter ────────────────────────────────────────────────────────
reset_case();
check( 'limits: nothing used, nothing saved -> allowed', null === UsageLimiter::check( 0 ) );
$status = UsageLimiter::get_status();
check( 'limits: unsaved defaults are 2 000 000 tokens and 500 requests', 2000000 === $status['monthly_tokens']['limit'] && 500 === $status['daily_requests']['limit'] );

reset_case( array(), 2000000 );
$msg = (string) UsageLimiter::check( 0 );
check( 'limits: the default monthly token limit blocks at the limit', str_contains( $msg, 'monthly token limit' ), $msg );

reset_case( array( 'monthly_token_limit' => 0, 'daily_request_limit' => 0 ), 99000000, 99999 );
check( 'limits: saved 0 means unlimited (both limits)', null === UsageLimiter::check( 0 ) );
check( 'limits: unlimited limits are left out of the status', array() === UsageLimiter::get_status() );

reset_case( array( 'daily_request_limit' => 10 ), 0, 10 );
$msg = (string) UsageLimiter::check( 0 );
check( 'limits: the site daily request limit blocks at the limit', str_contains( $msg, '(10 / 10)' ), $msg );

reset_case( array( 'user_daily_request_limit' => 3 ), 0, 0, array( 0 => 50, 7 => 3 ) );
check( 'limits: anonymous user 0 skips the per-user limit', null === UsageLimiter::check( 0 ) );
$msg = (string) UsageLimiter::check( 7 );
check( 'limits: a logged-in user is stopped at the per-user limit', str_contains( $msg, 'Your daily request limit' ), $msg );

reset_case( array( 'monthly_token_limit' => 1000, 'daily_request_limit' => 0 ), 250 );
$status = UsageLimiter::get_status();
check( 'limits: status maths (250 of 1000 = 25 %, 750 left)', 25 === $status['monthly_tokens']['percent'] && 750 === $status['monthly_tokens']['remaining'] );

reset_case( array( 'monthly_token_limit' => 1000, 'daily_request_limit' => 0 ), 5000 );
$status = UsageLimiter::get_status();
check( 'limits: percent caps at 100 and remaining at 0', 100 === $status['monthly_tokens']['percent'] && 0 === $status['monthly_tokens']['remaining'] );

// ── PromptBuilder::build_system_prompt ──────────────────────────────────
reset_case( array( 'custom_system_prompt' => 'Tykej návštěvníkům.' ) );
$context = array(
	'language'           => 'cs',
	'page_url'           => 'https://example.test/pobyty/luhacovice/',
	'current_product_id' => 2390,
	'rag_results'        => array( array( 'source_type' => 'content', 'score' => 0.87, 'chunk_text' => 'RAG úryvek o Luhačovicích', 'metadata' => array( 'title' => 'Luhačovice' ) ) ),
	'knowledge_chunks'   => array( array( 'title' => 'Sleva', 'content' => 'Kód ZIMA dává 7 %.' ) ),
	'faq_matches'        => array( (object) array( 'question' => 'Kdy je nástup?', 'answer' => '<p>V sobotu.</p>' ) ),
	'content'            => array( array( 'post_title' => 'O nás', 'content_text' => str_repeat( 'x', 900 ) ) ),
	'products'           => array(
		array( 'product_id' => 2116, 'name' => 'Valašský víkend', 'price' => '5350.00', 'stock_status' => 'instock', 'categories' => '["Luhačovice"]' ),
		array( 'product_id' => 2390, 'name' => 'Lázně na zkoušku', 'price' => '9705.00', 'stock_status' => 'outofstock', 'categories' => '[]' ),
	),
);
$prompt = PromptBuilder::build_system_prompt( $context );

check( 'prompt: names the site', str_contains( $prompt, 'Pobyty QA' ) );
check( 'prompt: tells the page the visitor is on (cs)', str_contains( $prompt, 'Návštěvník se aktuálně nachází na stránce: https://example.test/pobyty/luhacovice/' ) );
check( 'prompt: RAG source header with relevance', str_contains( $prompt, '[Source: content - Luhačovice | Relevance: 87%]' ) );
check( 'prompt: knowledge entry with its title', str_contains( $prompt, "--- Sleva ---\nKód ZIMA dává 7 %." ) );
check( 'prompt: FAQ answer is stripped of HTML', str_contains( $prompt, 'Odpověď: V sobotu.' ) );
check( 'prompt: page content is cut to 500 characters', str_contains( $prompt, str_repeat( 'x', 500 ) . "\n" ) && ! str_contains( $prompt, str_repeat( 'x', 501 ) ) );
check( 'prompt: product line with price, stock and categories', str_contains( $prompt, 'Produkt #2116: Valašský víkend - 5350.00 | Skladem: ano | Kategorie: Luhačovice' ) );
check( 'prompt: the current product is marked', str_contains( $prompt, ">> AKTUÁLNÍ PRODUKT (návštěvník je právě na stránce tohoto produktu) <<\nProdukt #2390" ) );
check( 'prompt: out-of-stock product says no', str_contains( $prompt, 'Produkt #2390: Lázně na zkoušku - 9705.00 | Skladem: ne' ) );

$order     = array( 'RAG úryvek', 'Kód ZIMA', 'Kdy je nástup', 'O nás', 'Produkt #2116', '=== VLASTNÍ INSTRUKCE ===' );
$positions = array_map( static fn( $needle ) => strpos( $prompt, $needle ), $order );
$sorted    = $positions;
sort( $sorted );
check( 'prompt: section order is RAG, knowledge, FAQ, content, products, rules, custom', ! in_array( false, $positions, true ) && $positions === $sorted, json_encode( $positions ) );
check( 'prompt: custom instructions come last', str_ends_with( rtrim( $prompt ), 'Tykej návštěvníkům.' ) );

reset_case();
$en = PromptBuilder::build_system_prompt( array( 'language' => 'en', 'page_url' => 'https://example.test/', 'products' => $context['products'] ) );
check( 'prompt: English wording for page and stock', str_contains( $en, 'The visitor is currently on this page: https://example.test/' ) && str_contains( $en, 'In stock: yes' ) );
check( 'prompt: no custom block when the setting is empty', ! str_contains( $en, 'CUSTOM INSTRUCTIONS' ) );
check( 'prompt: empty sections are left out', ! str_contains( $en, '[Source:' ) && ! str_contains( $en, '--- ' ) );

echo PHP_EOL . ( $failed ? "{$failed} failed" : 'all passed' ) . PHP_EOL;
exit( $failed ? 1 : 0 );
