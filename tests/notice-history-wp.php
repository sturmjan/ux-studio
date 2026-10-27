<?php
// Test only: never runs over HTTP (the release zip also excludes tests/).
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { exit; }
/**
 * WordPress side of the admin notice history (PLAN.md 20.16 / 0.8.1), run against a real site (the local pobyty):
 *
 *   cd D:\xampp\htdocs\pobyty
 *   D:/xampp/wp-cli/wp.bat --skip-plugins=wp-mail-smtp eval-file D:\xampp\htdocs\_uxs-wt-csp\tests\notice-history-wp.php
 *
 * Table creation (dbDelta result), capture and upsert counters, dismissal and "show again", the gone sweep and
 * the comeback, retention, filters and paging, delete / bulk delete / delete all, the REST routes with an
 * administrator, a subscriber and nobody, nothing recorded while the feature is off, and no record of the PHP
 * version notice. Options, the module schema version and the cron hooks are put back exactly; every row and
 * user the test creates is removed (the table is dropped again when the test created it).
 *
 * @package UxStudio
 */

defined( 'ABSPATH' ) || exit;

use UxStudio\Core\DB;
use UxStudio\Modules\AdminCustomiser\NoticeHistory\Rules;
use UxStudio\Modules\AdminCustomiser\NoticeHistory\Store;

$failures = 0;
$assert   = static function ( string $name, bool $ok, string $detail = '' ) use ( &$failures ): void {
	if ( ! $ok ) {
		++$failures;
	}
	WP_CLI::log( ( $ok ? 'PASS ' : 'FAIL ' ) . $name . ( $ok || '' === $detail ? '' : ' => ' . $detail ) );
};

global $wpdb;
$sentinel  = new stdClass();
$opt_name  = 'uxstudio_admin_customiser';
$saved_opt = get_option( $opt_name, $sentinel );
$saved_ver = get_option( DB::MODULE_VERSIONS_OPTION, $sentinel );
$crons     = static function (): array {
	$hooks = array();
	foreach ( (array) _get_cron_array() as $events ) {
		foreach ( (array) $events as $hook => $_ ) {
			if ( 0 === strpos( (string) $hook, 'uxstudio_' ) ) {
				$hooks[] = $hook;
			}
		}
	}
	sort( $hooks );
	return $hooks;
};
$cron_before   = $crons();
$table         = Store::table();
$table_existed = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) === $table;

$admins   = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) );
$admin_id = (int) $admins[0];
$sub_id   = (int) wp_insert_user( array( 'user_login' => 'nh_test_sub_' . wp_rand( 1000, 9999 ), 'user_pass' => wp_generate_password(), 'user_email' => 'nh-test-' . wp_rand( 1000, 9999 ) . '@example.invalid', 'role' => 'subscriber' ) );

$call = static function ( string $method, string $route, array $params = array(), ?array $json = null ): WP_REST_Response {
	$request = new WP_REST_Request( $method, '/uxstudio/v1' . $route );
	if ( null !== $json ) {
		$request->set_header( 'content-type', 'application/json' );
		$request->set_body( wp_json_encode( $json ) );
	}
	foreach ( $params as $k => $v ) {
		$request->set_param( $k, $v );
	}
	return rest_do_request( $request );
};
$data = static fn ( WP_REST_Response $r ) => $r->get_data()['data'] ?? null;
$item = static fn ( string $text, array $extra = array() ): array => $extra + array(
	'ckey'        => strtolower( $text ),
	'type'        => 'warning',
	'title'       => '',
	'text'        => $text,
	'markup'      => 'notice notice-warning',
	'id'          => '',
	'plugin'      => '',
	'screen'      => 'plugins',
	'dismissible' => true,
);
$rows  = static fn ( string $where = '1=1' ) => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE {$where}" );
$by    = static fn ( string $text ) => $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE notice_key = %s", Rules::key( 'warning', $text ) ), ARRAY_A );

try {
	// 1. Table ---------------------------------------------------------------------------------------------
	// A table that already holds history (the site is in use) is never dropped: only a missing or empty table is
	// re-created from scratch, otherwise the creation checks look at the table that is there.
	$has_history = $table_existed && (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ) > 0;
	if ( ! $has_history ) {
		Store::drop();
	}
	delete_transient( 'uxstudio_admin_notices_maint' );
	$ok = Store::ensure();
	$assert( 'table: ensure() creates it', $ok && $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) === $table );
	$versions = get_option( DB::MODULE_VERSIONS_OPTION, array() );
	$assert( 'table: schema version recorded only after dbDelta succeeded', 1 === (int) ( $versions['admin_customiser'] ?? 0 ) );
	$columns = $wpdb->get_col( "SHOW COLUMNS FROM {$table}", 0 );
	foreach ( array( 'notice_key', 'type', 'title', 'text', 'source_slug', 'source_name', 'screen', 'first_seen', 'last_seen', 'seen_count', 'reappeared_count', 'state', 'dismissed_at', 'dismissed_by', 'dismiss_method', 'first_seen_user' ) as $col ) {
		$assert( "table: column $col", in_array( $col, $columns, true ) );
	}
	$idx = $wpdb->get_results( "SHOW INDEX FROM {$table} WHERE Key_name = 'notice_key'", ARRAY_A );
	$assert( 'table: unique key on notice_key', 1 === count( $idx ) && 0 === (int) $idx[0]['Non_unique'] );
	$assert( 'table: a second ensure() is a no-op', Store::ensure() );

	// 2. Feature off: nothing is recorded -------------------------------------------------------------------
	update_option( $opt_name, array_merge( is_array( $saved_opt ) ? $saved_opt : array(), array( 'hide_notices_enabled' => false, 'hide_notices_relocate' => true ) ) );
	wp_set_current_user( $admin_id );
	$routes = rest_get_server()->get_routes();
	if ( ! isset( $routes['/uxstudio/v1/admin-notices'] ) ) {
		$assert( 'routes: admin-notices registered (module admin-customiser must be enabled on the site)', false );
		throw new RuntimeException( 'routes missing' );
	}
	$r = $call( 'POST', '/admin-notices/log', array(), array( 'items' => array( $item( 'NHTEST off' ) ) ) );
	$assert( 'off: log answers 200 with recorded 0', 200 === $r->get_status() && 0 === $data( $r )['recorded'] && false === $data( $r )['enabled'] );
	$assert( 'off: nothing stored', 0 === $rows( "text LIKE 'NHTEST%'" ) );
	$r = $call( 'POST', '/admin-notices/dismiss', array(), array( 'item' => $item( 'NHTEST off' ), 'method' => 'panel' ) );
	$assert( 'off: dismiss stores nothing either', 0 === $rows() );
	// relocate off = no panel = no capture point.
	update_option( $opt_name, array_merge( (array) get_option( $opt_name ), array( 'hide_notices_enabled' => true, 'hide_notices_relocate' => false ) ) );
	$call( 'POST', '/admin-notices/log', array(), array( 'items' => array( $item( 'NHTEST norelocate' ) ) ) );
	$assert( 'off: hiding without the panel records nothing', 0 === $rows( "text LIKE 'NHTEST%'" ) );

	// 3. Capture and upsert ---------------------------------------------------------------------------------
	update_option( $opt_name, array_merge( (array) get_option( $opt_name ), array( 'hide_notices_enabled' => true, 'hide_notices_relocate' => true ) ) );
	$batch = array(
		$item( 'NHTEST Freemius promo 5 days left', array( 'markup' => 'updated fs-notice fs-slug-elementor', 'title' => 'Elementor', 'type' => 'info' ) ),
		$item( 'NHTEST <script>alert(1)</script>Backup failed', array( 'type' => 'error' ) ),
		$item( 'NHTEST plain' ),
		$item( 'NHTEST plain' ), // duplicate inside one request counts once.
		$item( 'NHTEST php too old', array( 'id' => 'uxstudio-php-version-notice' ) ),
	);
	$r = $call( 'POST', '/admin-notices/log', array(), array( 'items' => $batch ) );
	$assert( 'log: 200', 200 === $r->get_status(), (string) wp_json_encode( $r->get_data() ) );
	$assert( 'log: 3 distinct notices recorded (duplicate merged, PHP notice skipped)', 3 === $data( $r )['recorded'] && 3 === $rows() );
	$assert( 'log: the PHP version notice is not in the table', 0 === $rows( "text LIKE '%php too old%'" ) );
	$row = $wpdb->get_row( "SELECT * FROM {$table} WHERE text LIKE '%Backup failed%'", ARRAY_A );
	$assert( 'log: HTML stripped server side', $row && false === strpos( $row['text'], '<' ) && false === strpos( $row['text'], 'alert(1)' ) );
	$assert( 'log: first record is active, counted once, user recorded', $row && 'active' === $row['state'] && 1 === (int) $row['seen_count'] && (int) $row['first_seen_user'] === $admin_id && 'error' === $row['type'] );
	$promo = $wpdb->get_row( "SELECT * FROM {$table} WHERE text LIKE '%Freemius promo%'", ARRAY_A );
	$assert( 'log: source attributed from the markup', $promo && 'elementor' === $promo['source_slug'] && '' !== $promo['source_name'] );
	$assert( 'log: screen stored', $promo && 'plugins' === $promo['screen'] );

	$call( 'POST', '/admin-notices/log', array(), array( 'items' => array( $item( 'NHTEST Freemius promo 2 days left', array( 'markup' => 'updated fs-notice fs-slug-elementor', 'title' => 'Elementor', 'type' => 'info' ) ) ) ) );
	$promo2 = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $promo['id'] ), ARRAY_A );
	$assert( 'upsert: same notice with other numbers is the same row', 3 === $rows() && 2 === (int) $promo2['seen_count'] );
	$assert( 'upsert: text kept up to date', false !== strpos( $promo2['text'], '2 days left' ) );

	// 4. Dismiss and show again -----------------------------------------------------------------------------
	$r = $call( 'POST', '/admin-notices/dismiss', array(), array( 'item' => $item( 'NHTEST plain' ), 'method' => 'panel' ) );
	$plain = $by( 'NHTEST plain' );
	$assert( 'dismiss: state, user, method', $plain && 'dismissed' === $plain['state'] && (int) $plain['dismissed_by'] === $admin_id && 'panel' === $plain['dismiss_method'] && ! empty( $plain['dismissed_at'] ) );
	$assert( 'dismiss: not counted as a page view', 1 === (int) $plain['seen_count'] );
	$assert( 'dismiss: the panel hides it from now on', in_array( 'nhtest plain', Store::hidden_client_keys(), true ) );
	$r = $call( 'POST', '/admin-notices/' . $plain['id'] . '/reshow' );
	$plain = $by( 'NHTEST plain' );
	$assert( 'reshow: back to active, marker cleared', 200 === $r->get_status() && 'active' === $plain['state'] && 0 === (int) $plain['dismissed_by'] && '' === $plain['dismiss_method'] && ! in_array( 'nhtest plain', Store::hidden_client_keys(), true ) );
	$call( 'POST', '/admin-notices/dismiss', array(), array( 'item' => $item( 'NHTEST plain' ), 'method' => 'plugin' ) );
	$plain = $by( 'NHTEST plain' );
	$r     = $call( 'POST', '/admin-notices/' . $plain['id'] . '/reshow' );
	$assert( 'reshow: refused for a plugin dismissal (409)', 409 === $r->get_status() && 'dismissed' === $by( 'NHTEST plain' )['state'] );
	$assert( 'dismiss: plugin dismissal is not hidden by the panel', ! in_array( 'nhtest plain', Store::hidden_client_keys(), true ) );
	$r = $call( 'POST', '/admin-notices/999999999/reshow' );
	$assert( 'reshow: unknown id is 404', 404 === $r->get_status() );
	$call( 'POST', '/admin-notices/dismiss', array(), array( 'item' => $item( 'NHTEST dismiss before log' ), 'method' => 'panel' ) );
	$early = $by( 'NHTEST dismiss before log' );
	$assert( 'dismiss: creates the row when the log request did not arrive yet', $early && 'dismissed' === $early['state'] );
	$call( 'POST', '/admin-notices/log', array(), array( 'items' => array( $item( 'NHTEST plain' ) ) ) );
	$back = $by( 'NHTEST plain' );
	$assert( 'comeback: a notice the plugin closed shows again -> active, counted', 'active' === $back['state'] && 1 === (int) $back['reappeared_count'] && '' === $back['dismiss_method'] );

	// 5. Gone sweep and retention ---------------------------------------------------------------------------
	$old = wp_date( 'Y-m-d H:i:s', time() - 8 * DAY_IN_SECONDS );
	$wpdb->update( $table, array( 'last_seen' => $old ), array( 'id' => (int) $back['id'] ) );
	$res  = Store::maintenance( true );
	$gone = $by( 'NHTEST plain' );
	$assert( 'sweep: not seen for 8 days -> gone', 'gone' === $gone['state'] && $res['gone'] >= 1 );
	$assert( 'sweep: a notice seen today stays active', 'active' === $wpdb->get_var( $wpdb->prepare( "SELECT state FROM {$table} WHERE id = %d", $promo['id'] ) ) );
	$call( 'POST', '/admin-notices/log', array(), array( 'items' => array( $item( 'NHTEST plain' ) ) ) );
	$again = $by( 'NHTEST plain' );
	$assert( 'comeback: gone -> active, reappeared_count 2', 'active' === $again['state'] && 2 === (int) $again['reappeared_count'] );
	$wpdb->update( $table, array( 'last_seen' => wp_date( 'Y-m-d H:i:s', time() - 91 * DAY_IN_SECONDS ) ), array( 'id' => (int) $early['id'] ) );
	$res = Store::maintenance( true );
	$assert( 'retention: older than 90 days deleted', 0 === $rows( 'id = ' . (int) $early['id'] ) && $res['purged'] >= 1 );
	delete_transient( 'uxstudio_admin_notices_maint' );

	// 6. List, filters, paging ------------------------------------------------------------------------------
	$many = array();
	for ( $i = 1; $i <= 25; $i++ ) {
		$many[] = $item( 'NHTEST bulk row ' . chr( 96 + $i ), array( 'type' => 0 === $i % 2 ? 'error' : 'info', 'markup' => 'notice' ) );
	}
	$call( 'POST', '/admin-notices/log', array(), array( 'items' => array_slice( $many, 0, 25 ) ) );
	$total_now = $rows();
	$r         = $call( 'GET', '/admin-notices', array( 'per_page' => 10, 'page' => 2 ) );
	$meta      = $r->get_data()['meta'];
	$assert( 'list: paging meta', 200 === $r->get_status() && $meta['total'] === $total_now && 10 === $meta['per_page'] && 2 === $meta['page'] && (int) ceil( $total_now / 10 ) === $meta['pages'] );
	$assert( 'list: page 2 has 10 rows', 10 === count( $data( $r ) ) );
	$p1 = array_column( $data( $call( 'GET', '/admin-notices', array( 'per_page' => 10, 'page' => 1 ) ) ), 'id' );
	$p2 = array_column( $data( $r ), 'id' );
	$assert( 'list: pages do not overlap', array() === array_intersect( $p1, $p2 ) );
	$last = $data( $call( 'GET', '/admin-notices', array( 'per_page' => 100 ) ) );
	$sorted = true;
	for ( $i = 1; $i < count( $last ); $i++ ) {
		if ( $last[ $i - 1 ]['last_seen'] < $last[ $i ]['last_seen'] ) {
			$sorted = false;
		}
	}
	$assert( 'list: sorted by last_seen descending', $sorted );
	$r = $call( 'GET', '/admin-notices', array( 'type' => 'error', 'per_page' => 100 ) );
	$assert( 'filter: type', count( array_filter( $data( $r ), static fn ( $x ) => 'error' !== $x['type'] ) ) === 0 && $r->get_data()['meta']['total'] >= 12 );
	$r = $call( 'GET', '/admin-notices', array( 'q' => 'bulk row c', 'per_page' => 100 ) );
	$assert( 'filter: search in text', 1 === count( $data( $r ) ) );
	$r = $call( 'GET', '/admin-notices', array( 'q' => '100%_', 'per_page' => 100 ) );
	$assert( 'filter: LIKE wildcards are escaped', 0 === count( $data( $r ) ) );
	$r = $call( 'GET', '/admin-notices', array( 'source' => 'elementor', 'per_page' => 100 ) );
	$assert( 'filter: source', 1 === count( $data( $r ) ) && 'elementor' === $data( $r )[0]['source_slug'] );
	$r = $call( 'GET', '/admin-notices', array( 'source' => '_unknown', 'per_page' => 100 ) );
	$assert( 'filter: unknown source', count( $data( $r ) ) >= 20 );
	$r = $call( 'GET', '/admin-notices', array( 'state' => 'dismissed', 'per_page' => 100 ) );
	$assert( 'filter: state', 0 === count( array_filter( $data( $r ), static fn ( $x ) => 'dismissed' !== $x['state'] ) ) );
	$r = $call( 'GET', '/admin-notices', array( 'period' => '24h', 'per_page' => 100 ) );
	$assert( 'filter: period 24h includes fresh rows', count( $data( $r ) ) >= 20 );
	$wpdb->update( $table, array( 'last_seen' => wp_date( 'Y-m-d H:i:s', time() - 3 * DAY_IN_SECONDS ) ), array( 'id' => (int) $promo['id'] ) );
	$r = $call( 'GET', '/admin-notices', array( 'period' => '24h', 'q' => 'Freemius promo', 'per_page' => 100 ) );
	$assert( 'filter: period 24h excludes a 3 day old row', 0 === count( $data( $r ) ) );
	$r = $call( 'GET', '/admin-notices', array( 'period' => '7d', 'q' => 'Freemius promo' ) );
	$assert( 'filter: period 7d includes it', 1 === count( $data( $r ) ) );
	$assert( 'validation: unknown state is 400', 400 === $call( 'GET', '/admin-notices', array( 'state' => 'x' ) )->get_status() );
	$assert( 'validation: per_page 1000 is 400', 400 === $call( 'GET', '/admin-notices', array( 'per_page' => 1000 ) )->get_status() );
	$assert( 'validation: bad source is 400', 400 === $call( 'GET', '/admin-notices', array( 'source' => "a'b" ) )->get_status() );
	$assert( 'validation: more than 40 items is 400', 400 === $call( 'POST', '/admin-notices/log', array(), array( 'items' => array_fill( 0, 41, $item( 'NHTEST x' ) ) ) )->get_status() );
	$assert( 'validation: items must be an array', 400 === $call( 'POST', '/admin-notices/log', array(), array( 'items' => 'x' ) )->get_status() );
	$assert( 'validation: oversized body is 413', 413 === $call( 'POST', '/admin-notices/log', array(), array( 'items' => array( $item( 'NHTEST x' ) ), 'pad' => str_repeat( 'a', 210000 ) ) )->get_status() );
	$st = $data( $call( 'GET', '/admin-notices/stats' ) );
	$assert( 'stats: counters and feature flags', $st['total'] === $total_now && array_sum( $st['states'] ) === $total_now && array_sum( $st['types'] ) === $total_now && true === $st['capture'] && ! empty( $st['sources'] ) );

	// 7. Delete ---------------------------------------------------------------------------------------------
	$victim = $by( 'NHTEST plain' );
	$r      = $call( 'DELETE', '/admin-notices/' . $victim['id'] );
	$assert( 'delete: one row', 200 === $r->get_status() && null === $by( 'NHTEST plain' ) );
	$assert( 'delete: unknown id is 404', 404 === $call( 'DELETE', '/admin-notices/' . $victim['id'] )->get_status() );
	$ids = array_slice( $wpdb->get_col( "SELECT id FROM {$table} ORDER BY id ASC LIMIT 5" ), 0, 5 );
	$r   = $call( 'POST', '/admin-notices/bulk-delete', array(), array( 'ids' => array_map( 'intval', $ids ) ) );
	$assert( 'bulk delete: 5 rows', 5 === $data( $r )['deleted'] );
	$assert( 'bulk delete: non-integer ids are 400', 400 === $call( 'POST', '/admin-notices/bulk-delete', array(), array( 'ids' => array( 'a' ) ) )->get_status() );
	$assert( 'bulk delete: more than 200 ids is 400', 400 === $call( 'POST', '/admin-notices/bulk-delete', array(), array( 'ids' => range( 1, 201 ) ) )->get_status() );

	// 8. Permissions ----------------------------------------------------------------------------------------
	$probe = array(
		array( 'GET', '/admin-notices' ),
		array( 'GET', '/admin-notices/stats' ),
		array( 'GET', '/admin-notices/export.csv' ),
		array( 'DELETE', '/admin-notices' ),
		array( 'DELETE', '/admin-notices/1' ),
		array( 'POST', '/admin-notices/1/reshow' ),
		array( 'POST', '/admin-notices/bulk-delete' ),
		array( 'POST', '/admin-notices/log' ),
		array( 'POST', '/admin-notices/dismiss' ),
	);
	$before_rows = $rows();
	wp_set_current_user( $sub_id );
	foreach ( $probe as $p ) {
		$json = 'POST' === $p[0] ? array( 'ids' => array( 1 ), 'items' => array( $item( 'NHTEST sub' ) ), 'item' => $item( 'NHTEST sub' ) ) : null;
		$assert( "403 for a subscriber: {$p[0]} {$p[1]}", 403 === $call( $p[0], $p[1], array(), $json )->get_status() );
	}
	wp_set_current_user( 0 );
	foreach ( $probe as $p ) {
		$json = 'POST' === $p[0] ? array( 'ids' => array( 1 ), 'items' => array( $item( 'NHTEST anon' ) ), 'item' => $item( 'NHTEST anon' ) ) : null;
		$assert( "401 for nobody: {$p[0]} {$p[1]}", 401 === $call( $p[0], $p[1], array(), $json )->get_status() );
	}
	$assert( 'permissions: nothing changed by the refused calls', $rows() === $before_rows );
	wp_set_current_user( $admin_id );

	// 9. Rate limit -----------------------------------------------------------------------------------------
	set_transient( 'uxstudio_rl_notices_' . $admin_id, 40, MINUTE_IN_SECONDS );
	$assert( 'rate limit: the 41st capture request in a minute is 429', 429 === $call( 'POST', '/admin-notices/log', array(), array( 'items' => array( $item( 'NHTEST rl' ) ) ) )->get_status() );
	delete_transient( 'uxstudio_rl_notices_' . $admin_id );

	// 10. Delete all ----------------------------------------------------------------------------------------
	$r = $call( 'DELETE', '/admin-notices' );
	$assert( 'delete all: empties the table', 200 === $r->get_status() && 0 === $rows() );
	$assert( 'delete all: stats are zero', 0 === $data( $call( 'GET', '/admin-notices/stats' ) )['total'] );

	// 11. Export is never part of a settings export ---------------------------------------------------------
	$assert( 'transfer: the table option names are on the never-export list', \UxStudio\Core\Transfer\NonPortable::is_state_option( 'uxstudio_admin_notices_maint' ) );
} catch ( Throwable $e ) {
	++$failures;
	WP_CLI::log( 'FAIL exception: ' . $e->getMessage() . ' @ ' . basename( $e->getFile() ) . ':' . $e->getLine() );
} finally {
	// Restore exactly.
	if ( $sentinel === $saved_opt ) {
		delete_option( $opt_name );
	} else {
		update_option( $opt_name, $saved_opt );
	}
	if ( ! $table_existed ) {
		$wpdb->query( "DROP TABLE IF EXISTS {$table}" );
		if ( $sentinel === $saved_ver ) {
			delete_option( DB::MODULE_VERSIONS_OPTION );
		} else {
			update_option( DB::MODULE_VERSIONS_OPTION, $saved_ver );
		}
	} else {
		$wpdb->query( "DELETE FROM {$table} WHERE text LIKE 'NHTEST%'" );
	}
	delete_transient( 'uxstudio_admin_notices_maint' );
	delete_transient( 'uxstudio_rl_' . $admin_id );
	delete_transient( 'uxstudio_rl_notices_' . $admin_id );
	delete_transient( 'uxstudio_rl_' . $sub_id );
	require_once ABSPATH . 'wp-admin/includes/user.php';
	wp_delete_user( $sub_id );
	$assert( 'cleanup: cron hooks of the plugin unchanged', $crons() === $cron_before );
	$assert( 'cleanup: option restored', $sentinel === $saved_opt ? false === get_option( $opt_name, false ) : get_option( $opt_name ) === $saved_opt );
	$assert( 'cleanup: temporary user removed', false === get_userdata( $sub_id ) );
}

WP_CLI::log( PHP_EOL . ( 0 === $failures ? 'ALL PASSED' : $failures . ' FAILED' ) );
if ( $failures ) {
	WP_CLI::halt( 1 );
}
