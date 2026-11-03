<?php
// Test only: never runs over HTTP (the release zip also excludes tests/).
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { exit; }
/**
 * Standalone test of the pure logic of Indexing & Sharing (PLAN.md 39): the queue (dedupe, delay, minimum gap,
 * retries), which pages may be sent, the IndexNow payload and key path, the Facebook request and answer reading,
 * and the overlap rule with Rank Math / Yoast IndexNow. Needs no WordPress:
 *
 *   php tests/indexing-queue.php
 *
 * @package UxStudio
 */

// phpcs:disable

define( 'ABSPATH', __DIR__ . '/' );
$root = dirname( __DIR__ );
define( 'UXSTUDIO_PATH', $root . '/' );

$GLOBALS['uxs_options'] = array();
function __( $text, $domain = '' ) { return $text; }
function get_option( $name, $default = false ) { return $GLOBALS['uxs_options'][ $name ] ?? $default; }
function update_option( $name, $value, $autoload = null ) { $GLOBALS['uxs_options'][ $name ] = $value; return true; }
function apply_filters( $hook, $value, ...$args ) { return $value; }

require_once $root . '/includes/Autoloader.php';
\UxStudio\Autoloader::register();

use UxStudio\Core\Migration\Overlaps;
use UxStudio\Modules\IndexingNotice\Eligibility;
use UxStudio\Modules\IndexingNotice\FacebookScraper;
use UxStudio\Modules\IndexingNotice\IndexNow;
use UxStudio\Modules\IndexingNotice\SocialQueue as Q;

$failures = 0;
$assert   = static function ( string $name, bool $ok, string $detail = '' ) use ( &$failures ): void {
	if ( ! $ok ) {
		++$failures;
	}
	echo ( $ok ? 'PASS ' : 'FAIL ' ) . $name . ( $ok || '' === $detail ? '' : ' => ' . $detail ) . PHP_EOL;
};

$both = array( Q::STEP_SOCIAL, Q::STEP_INDEXNOW );
$A    = 'https://example.com/a/';
$B    = 'https://example.com/b/';
$now  = 1000000;

/* ── Queue: dedupe and delay ── */

$q = Q::add( array(), $A, 5, $now, $both );
$assert( 'an address is due 120 seconds after the save', $now + 120 === $q['items'][ $A ]['due'] && array() === Q::due( $q, $now + 119, 50 ) && array( $A ) === array_keys( Q::due( $q, $now + 120, 50 ) ) );
$q2 = Q::add( $q, $A, 5, $now + 60, $both );
$assert( 'a second save of the same address merges, one entry, the earlier time stays', 1 === count( $q2['items'] ) && $now + 120 === $q2['items'][ $A ]['due'] );
$q3 = Q::add( $q, $A, 5, $now + 10, array( Q::STEP_SOCIAL ) );
$assert( 'merged steps are not doubled', $both === $q3['items'][ $A ]['steps'] );
$q4 = Q::add( Q::add( array(), $A, 1, $now, $both ), $B, 2, $now + 5, $both );
$assert( 'a bulk change gives one batch, oldest first', array( $A, $B ) === array_keys( Q::due( $q4, $now + 500, 50 ) ) );
$assert( 'the batch limit cuts the list', array( $A ) === array_keys( Q::due( $q4, $now + 500, 1 ) ) );
$assert( 'next_due is the earliest time, null for an empty queue', $now + 120 === Q::next_due( $q4 ) && null === Q::next_due( array() ) );
$assert( 'an empty address or no steps adds nothing', array() === Q::add( array(), '', 1, $now, $both )['items'] && array() === Q::add( array(), $A, 1, $now, array( 'bogus' ) )['items'] );

/* ── Queue: the same address at most once in 10 minutes ── */

$q = Q::add( array(), $A, 5, $now, $both );
$q = Q::settle( $q, $A, array( Q::STEP_SOCIAL => 'done', Q::STEP_INDEXNOW => 'done' ), $now + 120 );
$assert( 'after a full run the entry is gone and the run is remembered', array() === $q['items'] && $now + 120 === $q['sent'][ $A ] );
$q = Q::add( $q, $A, 5, $now + 200, $both );
$assert( 'a save 80 seconds after a run waits until 10 minutes after it', $now + 120 + 600 === $q['items'][ $A ]['due'] );
$q = Q::add( Q::prune( $q, $now + 5000 ), $B, 6, $now + 5000, $both );
$assert( 'old run marks are forgotten', ! isset( $q['sent'][ $A ] ) );

/* ── Queue: retries ── */

$q = Q::add( array(), $A, 5, $now, $both );
$q = Q::settle( $q, $A, array( Q::STEP_SOCIAL => 900, Q::STEP_INDEXNOW => 'done' ), $now + 120 );
$assert( 'a step that must wait stays, the finished one goes', array( Q::STEP_SOCIAL ) === $q['items'][ $A ]['steps'] && $now + 120 + 900 === $q['items'][ $A ]['due'] && 1 === $q['items'][ $A ]['attempts'] );
$q = Q::settle( $q, $A, array( Q::STEP_SOCIAL => 900 ), $now + 2000 );
$q = Q::settle( $q, $A, array( Q::STEP_SOCIAL => 900 ), $now + 4000 );
$assert( 'after three tries the step is dropped', ! isset( $q['items'][ $A ] ) );
$q = Q::add( array(), $A, 5, $now, $both );
$q = Q::settle( $q, $A, array( Q::STEP_SOCIAL => 'drop', Q::STEP_INDEXNOW => 'drop' ), $now + 120 );
$assert( 'drop removes the entry and does not count as a run', array() === $q['items'] && ! isset( $q['sent'][ $A ] ) );
$q = Q::settle( Q::add( array(), $A, 5, $now, $both ), $A, array( Q::STEP_SOCIAL => 1 ), $now + 120 );
$assert( 'a retry never comes sooner than 30 seconds', $now + 120 + 30 === $q['items'][ $A ]['due'] );
$assert( 'garbage is normalised', array( 'items' => array(), 'sent' => array() ) === Q::normalize( 'junk' ) && array() === Q::normalize( array( 'items' => array( 'x' => array( 'steps' => array( 'bogus' ) ) ) ) )['items'] );

/* ── Which pages are sent ── */

$ok = array( 'blog_public' => 1, 'status' => 'publish', 'viewable' => true, 'password' => false, 'noindex' => false, 'maintenance' => false, 'paused' => false );
$assert( 'a normal published page is sent', '' === Eligibility::reason( $ok ) );
$assert( 'a site that discourages search engines sends nothing', 'not_public_site' === Eligibility::reason( array_merge( $ok, array( 'blog_public' => 0 ) ) ) );
$assert( 'a draft, a password page, a noindex page', 'not_published' === Eligibility::reason( array_merge( $ok, array( 'status' => 'draft' ) ) ) && 'password' === Eligibility::reason( array_merge( $ok, array( 'password' => true ) ) ) && 'noindex' === Eligibility::reason( array_merge( $ok, array( 'noindex' => true ) ) ) );
$assert( 'maintenance mode, a type without a public page', 'maintenance' === Eligibility::reason( array_merge( $ok, array( 'maintenance' => true ) ) ) && 'not_viewable' === Eligibility::reason( array_merge( $ok, array( 'viewable' => false ) ) ) );
$assert( 'a copy or moved site sends nothing, whatever else is true', 'paused' === Eligibility::reason( array_merge( $ok, array( 'paused' => true ) ) ) );
$assert( 'missing facts mean not sent, not guessed', '' !== Eligibility::reason( array() ) );
$assert( 'noindex from Rank Math and Yoast meta', Eligibility::meta_says_noindex( array( 'index', 'noindex' ), '' ) && Eligibility::meta_says_noindex( '', '1' ) && ! Eligibility::meta_says_noindex( array( 'index', 'follow' ), '2' ) && ! Eligibility::meta_says_noindex( '', '' ) );
$assert( 'every reason has a wording', '' !== Eligibility::label( 'paused' ) && '' !== Eligibility::label( 'noindex' ) && '' !== Eligibility::label( 'maintenance' ) && '' === Eligibility::label( '' ) );

/* ── IndexNow ── */

$key = IndexNow::generate_key();
$assert( 'a key is 32 hex characters and passes the check', IndexNow::is_key( $key ) && 32 === strlen( $key ) && ! IndexNow::is_key( 'short' ) && ! IndexNow::is_key( strtoupper( $key ) ) );
$p = IndexNow::payload( 'example.com', $key, 'https://example.com/' . $key . '.txt', array( 'https://example.com/a/', 'https://example.com/a/', 'https://other.test/x', 'https://EXAMPLE.com/b/', 'javascript:alert(1)' ) );
$assert( 'payload has host, key, keyLocation and urlList', array( 'host', 'key', 'keyLocation', 'urlList' ) === array_keys( $p ) && 'example.com' === $p['host'] && $key === $p['key'] );
$assert( 'payload keeps only this host, no duplicates, no foreign or odd addresses', array( 'https://example.com/a/', 'https://EXAMPLE.com/b/' ) === $p['urlList'] );
$assert( 'a payload with no address of the host has an empty list', array() === IndexNow::payload( 'example.com', $key, 'x', array( 'https://other.test/' ) )['urlList'] );
$assert( 'status codes: 200 and 202 ok, 403 key, 422 address, 429 rate, the rest error', 'ok' === IndexNow::interpret( 200 ) && 'ok' === IndexNow::interpret( 202 ) && 'bad_key' === IndexNow::interpret( 403 ) && 'bad_url' === IndexNow::interpret( 422 ) && 'rate' === IndexNow::interpret( 429 ) && 'error' === IndexNow::interpret( 500 ) );
$assert( 'the key is read from /<key>.txt', $key === IndexNow::key_from_path( '/' . $key . '.txt', '' ) );
$assert( 'also in a site folder, and nothing else matches', $key === IndexNow::key_from_path( '/blog/' . $key . '.txt', '/blog' ) && '' === IndexNow::key_from_path( '/a/' . $key . '.txt', '' ) && '' === IndexNow::key_from_path( '/' . $key . '.php', '' ) && '' === IndexNow::key_from_path( '/' . $key . '.txt.bak', '' ) && '' === IndexNow::key_from_path( '/about.txt', '' ) );

/* ── Facebook ── */

$body = FacebookScraper::scrape_body( 'https://example.com/a/', '123456789', 'abcdef0123456789abcdef' );
$assert( 'the scrape request carries id, scrape=true and the app token in the body', 'https://example.com/a/' === $body['id'] && 'true' === $body['scrape'] && '123456789|abcdef0123456789abcdef' === $body['access_token'] );
$assert( 'the key check reads the app with its own token', 'https://graph.facebook.com/123456789?access_token=123456789%7Cabcdef0123456789abcdef' === FacebookScraper::verify_url( '123456789', 'abcdef0123456789abcdef' ) );
$assert( 'an app id is digits only', FacebookScraper::is_app_id( '123456789012345' ) && ! FacebookScraper::is_app_id( '12ab' ) && ! FacebookScraper::is_app_id( '' ) && ! FacebookScraper::is_app_id( '123 456' ) );
$r = FacebookScraper::read_scrape( 'u', 200, array( 'title' => 'Hello', 'image' => array( array( 'url' => 'https://example.com/i.jpg' ) ) ) );
$assert( 'a good answer gives the title and image Facebook sees', $r['ok'] && 'Hello' === $r['og_title'] && 'https://example.com/i.jpg' === $r['og_image'] && ! $r['rate'] );
$r = FacebookScraper::read_scrape( 'u', 400, array( 'error' => array( 'message' => 'Application request limit reached', 'code' => 4 ) ) );
$assert( 'error 4 is a rate limit', ! $r['ok'] && $r['rate'] );
$assert( 'errors 17, 32 and 613 are rate limits, 100 is not', FacebookScraper::read_scrape( 'u', 400, array( 'error' => array( 'code' => 613 ) ) )['rate'] && FacebookScraper::read_scrape( 'u', 400, array( 'error' => array( 'code' => 32 ) ) )['rate'] && FacebookScraper::read_scrape( 'u', 400, array( 'error' => array( 'code' => 17 ) ) )['rate'] && ! FacebookScraper::read_scrape( 'u', 400, array( 'error' => array( 'code' => 100 ) ) )['rate'] );
$r = FacebookScraper::read_scrape( 'u', 500, array() );
$assert( 'HTTP 500 without a body is a failure, not a rate limit', ! $r['ok'] && ! $r['rate'] && 'HTTP 500' === $r['error'] );
$assert( 'a token that slips into an error text is masked', ! str_contains( FacebookScraper::clean_error( 'Invalid token 123456789|abcdef0123456789abcdef for app', 190 ), 'abcdef0123456789' ) && str_contains( FacebookScraper::clean_error( 'Invalid token 123456789|abcdef0123456789abcdef', 190 ), '[token]' ) );

/* ── Overlap with Rank Math / Yoast IndexNow ── */

Overlaps::flush();
$GLOBALS['uxs_options'] = array( 'active_plugins' => array( 'seo-by-rank-math/rank-math.php' ) );
Overlaps::set_foreign_reader( static fn( array $c ): bool => 'rank_math_modules' === ( $c['option'] ?? '' ) );
$assert( 'Rank Math with Instant Indexing on: indexnow_enabled is left out of the pack', array( 'indexnow_enabled' ) === array_keys( Overlaps::blocked_settings( 'indexing-notice' ) ) );
Overlaps::set_foreign_reader( static fn( array $c ): bool => false );
Overlaps::flush();
$assert( 'Rank Math with Instant Indexing off: nothing is left out', array() === Overlaps::blocked_settings( 'indexing-notice' ) );
Overlaps::set_foreign_reader( static fn( array $c ): bool => 'wpseo' === ( $c['option'] ?? '' ) );
Overlaps::flush();
$GLOBALS['uxs_options'] = array( 'active_plugins' => array( 'wordpress-seo/wp-seo.php' ) );
$assert( 'Yoast with IndexNow on: left out', array( 'indexnow_enabled' ) === array_keys( Overlaps::blocked_settings( 'indexing-notice' ) ) );
Overlaps::flush();
$GLOBALS['uxs_options'] = array( 'active_plugins' => array( 'indexnow/indexnow.php' ) );
$assert( 'the IndexNow plugin itself: left out', array( 'indexnow_enabled' ) === array_keys( Overlaps::blocked_settings( 'indexing-notice' ) ) );
Overlaps::flush();
$GLOBALS['uxs_options'] = array( 'active_plugins' => array( 'indexnow/indexnow.php' ), 'uxstudio_active_modules' => array(), );
$assert( 'the same overlap never blocks switching the module on (information only)', array() === array_filter( Overlaps::for_module( 'indexing-notice' ), static fn( array $r ): bool => Overlaps::GROUP_FULL === $r['group'] ) );
Overlaps::set_foreign_reader( null );
Overlaps::flush();

/* ── One-time switch-on for sites already connected (PLAN.md 39.3) ── */

use UxStudio\Core\Onboarding\SharingUpgrade as Up;

$assert( 'upgrade: a site that is not connected is left alone', 'skip' === Up::decide( false, false, false ) && 'skip' === Up::decide( false, true, false ) );
$assert( 'upgrade: a connected site with the module on only gets the options', 'configure' === Up::decide( true, true, false ) );
$assert( 'upgrade: a connected site with the module off gets it switched on', 'enable' === Up::decide( true, false, false ) );
$assert( 'upgrade: a module the administrator switched off stays off', 'skip' === Up::decide( true, false, true ) );
$assert( 'upgrade: options the site stored itself are not overwritten', array( 'indexnow_enabled' => true ) === Up::settings_to_store( array( 'social_refresh_enabled' => false, 'social_key_source' => 'own' ), true ) );
$assert( 'upgrade: all three options for a clean site, IndexNow only when no other plugin sends it', array( 'social_refresh_enabled' => true, 'social_key_source' => 'ca', 'indexnow_enabled' => true ) === Up::settings_to_store( array(), true ) && ! isset( Up::settings_to_store( array(), false )['indexnow_enabled'] ) );

echo PHP_EOL . ( 0 === $failures ? 'ALL PASSED' : $failures . ' FAILED' ) . PHP_EOL;
exit( 0 === $failures ? 0 : 1 );
