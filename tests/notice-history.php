<?php
// Test only: never runs over HTTP (the release zip also excludes tests/).
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { exit; }
/**
 * Standalone test of the pure rules of the admin notice history (PLAN.md 20.16 / 0.8.1): key stability, text
 * cleaning, source attribution, state transitions, retention, CSV escaping and filter validation.
 *
 *   php tests/notice-history.php
 *
 * @package UxStudio
 */

// phpcs:disable

define( 'ABSPATH', __DIR__ . '/' );
define( 'UXSTUDIO_PATH', dirname( __DIR__ ) . '/' );

require_once dirname( __DIR__ ) . '/includes/Autoloader.php';
\UxStudio\Autoloader::register();

use UxStudio\Modules\AdminCustomiser\NoticeHistory\Rules as R;

$failures = 0;
$assert   = static function ( string $name, bool $ok, string $detail = '' ) use ( &$failures ): void {
	if ( ! $ok ) {
		++$failures;
	}
	echo ( $ok ? 'PASS ' : 'FAIL ' ) . $name . ( $ok || '' === $detail ? '' : ' => ' . $detail ) . PHP_EOL;
};

// --- key ---------------------------------------------------------------------------------------------------
$k1 = R::key( 'warning', 'Plugin X: 3 updates are available (checked 27.10.2026).' );
$k2 = R::key( 'warning', 'Plugin X:  12 updates are  available (checked 1.1.2027).' );
$assert( 'key: 40 hex characters', 1 === preg_match( '/^[0-9a-f]{40}$/', $k1 ) );
$assert( 'key: volatile numbers and spacing do not change it', $k1 === $k2 );
$assert( 'key: another text gives another key', $k1 !== R::key( 'warning', 'Plugin Y: 3 updates are available.' ) );
$assert( 'key: the type is part of the key', R::key( 'error', 'abc' ) !== R::key( 'info', 'abc' ) );
$assert( 'key: case does not matter', R::key( 'info', 'Hello World' ) === R::key( 'info', 'hello   WORLD' ) );
$assert( 'key: unknown type means info', R::key( 'weird', 'abc' ) === R::key( 'info', 'abc' ) );
$assert( 'key: only the first 240 characters count', R::key( 'info', str_repeat( 'a', 240 ) . 'X' ) === R::key( 'info', str_repeat( 'a', 240 ) . 'Y' ) );

// --- text --------------------------------------------------------------------------------------------------
$assert( 'text: tags stripped, entities decoded, whitespace collapsed', 'Hello world & co' === R::clean_text( "<p>Hello\n  <b>world</b> &amp; co</p>" ) );
$assert( 'text: script and style content removed', 'Visible' === R::clean_text( '<style>.a{}</style><script>alert(1)</script>Visible' ) );
$assert( 'text: encoded markup does not survive', false === strpos( R::clean_text( '&lt;img src=x onerror=alert(1)&gt;ok' ), '<' ) );
$assert( 'text: unclosed script is dropped to the end', 'a' === R::clean_text( 'a<script>alert(1)' ) );
$long = R::clean_text( str_repeat( 'ab ', 2000 ) );
$assert( 'text: truncated to 2000 characters with an ellipsis', 2000 === mb_strlen( $long ) && "\u{2026}" === mb_substr( $long, -1 ) );
$assert( 'text: czech diacritics survive the cut', 'Žluťoučký kůň' === R::clean_text( 'Žluťoučký   kůň' ) );
$assert( 'text: control characters removed', 'ab' === R::clean_text( "a\x00\x07b" ) );
$assert( 'text: non-string gives empty', '' === R::clean_text( array( 'x' ) ) );
$assert( 'title: capped to 160', 160 >= mb_strlen( R::clean_text( str_repeat( 'x', 500 ), R::TITLE_MAX ) ) );

// --- item --------------------------------------------------------------------------------------------------
$item = R::sanitize_item( array( 'text' => '<b>Hi</b>', 'type' => 'ERROR', 'markup' => 'notice <script>', 'id' => 'a b"c', 'plugin' => 'dir/file.php', 'screen' => 'Plugins', 'dismissible' => 1, 'ckey' => 'hi' ) );
$assert( 'item: fields cleaned', is_array( $item ) && 'Hi' === $item['text'] && 'error' === $item['type'] && 'abc' === $item['id'] && 'plugins' === $item['screen'] && true === $item['dismissible'] && false === strpos( $item['markup'], '<' ) );
$assert( 'item: empty text rejected', null === R::sanitize_item( array( 'text' => '  <i></i> ' ) ) );
$assert( 'item: a non-array is rejected', null === R::sanitize_item( 'x' ) );
$assert( 'item: PHP version notice of UX Studio is never recorded', null === R::sanitize_item( array( 'text' => 'PHP too old', 'id' => 'uxstudio-php-version-notice' ) ) );
$assert( 'item: invalid screen dropped', '' === R::sanitize_item( array( 'text' => 'x', 'screen' => 'a/b?c=1' ) )['screen'] );

// --- source ------------------------------------------------------------------------------------------------
$plugins = array(
	'elementor'          => 'Elementor',
	'updraftplus'        => 'UpdraftPlus: Backup/Restore',
	'wordpress-seo'      => 'Yoast SEO',
	'woocommerce'        => 'WooCommerce',
	'my-booking-plugin'  => 'My Booking Plugin',
	'ux-studio'          => 'UX Studio',
);
$src = static function ( array $raw ) use ( $plugins ): array {
	$item = R::sanitize_item( $raw + array( 'text' => 'text' ) );
	return R::guess_source( $item ?? array(), $plugins );
};
$s = $src( array( 'markup' => 'updated fs-notice fs-has-title fs-slug-my-booking-plugin fs-type-promotion', 'title' => 'My Booking Plugin', 'text' => 'Upgrade now.' ) );
$assert( 'source: Freemius notice resolves the slug to the active plugin', 'my-booking-plugin' === $s['slug'] && 'My Booking Plugin' === $s['name'] && 'class' === $s['how'] );
$s = $src( array( 'markup' => 'notice e-notice e-notice--extended', 'text' => 'Get Elementor Pro.' ) );
$assert( 'source: Elementor classes', 'elementor' === $s['slug'] );
$s = $src( array( 'markup' => 'notice notice-warning updraft-ad-container updated', 'text' => 'Backups are not scheduled.' ) );
$assert( 'source: UpdraftPlus classes resolve to the readable name', 'updraftplus' === $s['slug'] && 'UpdraftPlus: Backup/Restore' === $s['name'] );
$s = $src( array( 'markup' => 'update-nag notice notice-warning', 'text' => 'WordPress 6.9.1 is available! Please update now.' ) );
$assert( 'source: core update nag is WordPress', 'wordpress' === $s['slug'] && 'WordPress' === $s['name'] && 'core' === $s['how'] );
$s = $src( array( 'markup' => 'notice', 'text' => 'WordPress 6.9 je dostupný! Aktualizujte prosím.' ) );
$assert( 'source: czech core sentence is WordPress', 'wordpress' === $s['slug'] );
$s = $src( array( 'markup' => 'update-nag', 'text' => 'Some plugin wants your attention.' ) );
$assert( 'source: update-nag with a foreign text is not core', 'wordpress' !== $s['slug'] );
$s = $src( array( 'markup' => 'notice', 'id' => 'uxstudio-something', 'text' => 'x' ) );
$assert( 'source: UX Studio own notice', 'ux-studio' === $s['slug'] && 'UX Studio' === $s['name'] && 'own' === $s['how'] );
$s = $src( array( 'markup' => 'notice', 'plugin' => 'my-booking-plugin/main.php', 'text' => 'x' ) );
$assert( 'source: data-plugin basename', 'my-booking-plugin' === $s['slug'] && 'data' === $s['how'] );
$s = $src( array( 'markup' => 'notice', 'title' => 'yoast seo', 'text' => 'Something happened.' ) );
$assert( 'source: title label matches an active plugin name', 'wordpress-seo' === $s['slug'] && 'title' === $s['how'] );
$s = $src( array( 'markup' => 'notice', 'text' => 'Your WooCommerce database needs an update.' ) );
$assert( 'source: name of an active plugin in the text', 'woocommerce' === $s['slug'] && 'text' === $s['how'] );
$s = $src( array( 'markup' => 'notice notice-info', 'text' => 'Welcome back, enjoy your day.' ) );
$assert( 'source: unknown stays unknown', '' === $s['slug'] && '' === $s['name'] );
$s = $src( array( 'markup' => 'notice fs-slug-gone_plugin', 'text' => 'x' ) );
$assert( 'source: Freemius slug of an inactive plugin gets a readable name', 'gone-plugin' === $s['slug'] && 'Gone Plugin' === $s['name'] );
$s = $src( array( 'markup' => 'wc-admin-notice', 'text' => 'x' ) );
$assert( 'source: known class fingerprint works for an inactive plugin too', 'woocommerce' === $s['slug'] );
$s = R::guess_source( array( 'markup' => '', 'id' => '', 'text' => 'The word elementors alone is no match', 'title' => '' ), $plugins );
$assert( 'source: a plugin name inside another word does not match', 'elementor' !== $s['slug'] );

// --- states ------------------------------------------------------------------------------------------------
$assert( 'state: active seen again stays active', array( 'state' => 'active', 'reappeared' => 2 ) === R::on_seen( 'active', 2 ) );
$assert( 'state: gone -> active counts a comeback', array( 'state' => 'active', 'reappeared' => 1 ) === R::on_seen( 'gone', 0 ) );
$assert( 'state: dismissed by the plugin -> active when it shows again', 'active' === R::on_seen( 'dismissed', 0 )['state'] );
$assert( 'state: dismiss from the panel', array( 'state' => 'dismissed', 'method' => 'panel' ) === R::on_dismiss( 'panel' ) );
$assert( 'state: dismiss through the plugin', 'plugin' === R::on_dismiss( 'plugin' )['method'] );
$assert( 'state: unknown method falls back to panel', 'panel' === R::on_dismiss( 'x' )['method'] );
$assert( 'reshow: only panel dismissals', R::can_reshow( 'dismissed', 'panel' ) && ! R::can_reshow( 'dismissed', 'plugin' ) && ! R::can_reshow( 'active', 'panel' ) && ! R::can_reshow( 'gone', '' ) );

$now    = strtotime( '2026-10-27 12:00:00' );
$cutoff = gmdate( 'Y-m-d H:i:s', R::gone_cutoff( $now ) );
$assert( 'gone: cutoff is 7 days back', '2026-10-20 12:00:00' === $cutoff );
$rows = array(
	array( 'id' => 1, 'state' => 'active', 'last_seen' => '2026-10-19 08:00:00' ),
	array( 'id' => 2, 'state' => 'active', 'last_seen' => '2026-10-21 08:00:00' ),
	array( 'id' => 3, 'state' => 'dismissed', 'last_seen' => '2026-09-01 08:00:00' ),
	array( 'id' => 4, 'state' => 'gone', 'last_seen' => '2026-09-01 08:00:00' ),
);
$assert( 'gone: only old ACTIVE rows are swept', array( 1 ) === R::gone_ids( $rows, $cutoff ) );
$assert( 'gone: a notice seen after the cutoff stays active', ! in_array( 2, R::gone_ids( $rows, $cutoff ), true ) );

// --- retention ---------------------------------------------------------------------------------------------
$old   = gmdate( 'Y-m-d H:i:s', $now - 90 * 86400 );
$rows  = array(
	array( 'id' => 1, 'last_seen' => '2026-07-01 00:00:00' ),
	array( 'id' => 2, 'last_seen' => '2026-10-26 00:00:00' ),
	array( 'id' => 3, 'last_seen' => '2026-10-25 00:00:00' ),
	array( 'id' => 4, 'last_seen' => '2026-10-24 00:00:00' ),
);
$assert( 'retention: older than the cutoff is deleted', array( 1 ) === R::purge_ids( $rows, $old, 10 ) );
$del = R::purge_ids( $rows, $old, 2 );
sort( $del );
$assert( 'retention: the cap keeps the newest rows', array( 1, 4 ) === $del );
$tie = R::purge_ids( array( array( 'id' => 5, 'last_seen' => 'b' ), array( 'id' => 6, 'last_seen' => 'b' ) ), 'a', 1 );
$assert( 'retention: a tie is broken by id (newer id kept)', array( 5 ) === $tie );
$assert( 'retention: nothing to delete', array() === R::purge_ids( array_slice( $rows, 1 ), $old, 1000 ) );

// --- CSV ---------------------------------------------------------------------------------------------------
$row = array( 'id' => 7, 'type' => 'error', 'title' => '=HYPERLINK("http://x")', 'text' => '+cmd|calc', 'source_name' => '@Evil', 'source_slug' => '-x', 'screen' => 'plugins', 'state' => 'active', 'first_seen' => '2026-10-01 00:00:00', 'last_seen' => '2026-10-02 00:00:00', 'seen_count' => 3, 'dismissed_at' => null, 'dismissed_by' => '', 'dismiss_method' => '' );
$csv = R::csv_row( $row );
$assert( 'csv: one cell per column', count( $csv ) === count( R::CSV_COLUMNS ) );
$bad = array_filter( $csv, static fn ( string $c ): bool => '' !== $c && false !== strpos( "=+-@\t\r", $c[0] ) );
$assert( 'csv: no cell starts with = + - @ tab or CR', array() === $bad );
$assert( 'csv: neutralised with an apostrophe', "'=HYPERLINK(\"http://x\")" === $csv[2] && "'+cmd|calc" === $csv[3] && "'@Evil" === $csv[4] );

// --- filters -----------------------------------------------------------------------------------------------
$f = R::sanitize_filters( array( 'state' => 'active', 'type' => 'error', 'source' => 'Elementor', 'period' => '7d', 'q' => ' <b>abc</b> ', 'page' => '3', 'per_page' => '50' ) );
$assert( 'filters: valid values pass', 'active' === $f['state'] && 'error' === $f['type'] && 'elementor' === $f['source'] && '7d' === $f['period'] && 'abc' === $f['q'] && 3 === $f['page'] && 50 === $f['per_page'] );
$f = R::sanitize_filters( array( 'state' => "x' OR 1=1", 'type' => 'fatal', 'source' => "a'; DROP", 'period' => '1y', 'q' => str_repeat( 'q', 500 ), 'page' => '-4', 'per_page' => '9999' ) );
$assert( 'filters: invalid values are dropped', '' === $f['state'] && '' === $f['type'] && '' === $f['source'] && 'all' === $f['period'] && 100 >= mb_strlen( $f['q'] ) && 1 === $f['page'] && 100 === $f['per_page'] );
$f = R::sanitize_filters( array() );
$assert( 'filters: defaults', 'all' === $f['period'] && 1 === $f['page'] && 20 === $f['per_page'] && '' === $f['q'] );
$assert( 'filters: unknown source token is allowed as a filter value', '_unknown' === R::sanitize_filters( array( 'source' => '_unknown' ) )['source'] );

echo PHP_EOL . ( 0 === $failures ? 'ALL PASSED' : $failures . ' FAILED' ) . PHP_EOL;
exit( 0 === $failures ? 0 : 1 );
