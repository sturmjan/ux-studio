<?php
// Test only: never runs over HTTP (the release zip also excludes tests/).
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { exit; }
/**
 * Standalone test of what an update from 0.5.0 does on a client site (PLAN.md 28, "Dopad aktualizace"):
 *
 *  - CSP v5 migration: only the structural step runs in the request, old rows are rated in locked batches and
 *    never cause the high-risk e-mail;
 *  - the CSP mode bookkeeping starts for sites that already had a mode;
 *  - DB::ensure_module_tables() does not record a version when a CREATE/ALTER failed, retries later (throttled)
 *    and logs once;
 *  - the wizard hint on the Modules screen reuses a cached scan.
 *
 * Needs no WordPress and no database:
 *
 *   php tests/update-impact.php
 *
 * @package UxStudio
 */

// phpcs:disable

$fake_abspath = sys_get_temp_dir() . '/uxs-abspath-' . getmypid() . '/';
@mkdir( $fake_abspath . 'wp-admin/includes', 0777, true );
file_put_contents( $fake_abspath . 'wp-admin/includes/upgrade.php', "<?php\n" );
define( 'ABSPATH', $fake_abspath );
define( 'DAY_IN_SECONDS', 86400 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'ARRAY_A', 'ARRAY_A' );
define( 'UXSTUDIO_VERSION', '0.6.0-test' );
define( 'UXSTUDIO_PATH', dirname( __DIR__ ) . '/' );

$GLOBALS['uxs_options']    = array();
$GLOBALS['uxs_transients'] = array();
$GLOBALS['uxs_events']     = array();
$GLOBALS['uxs_log']        = array();
$GLOBALS['uxs_updates']    = array();
$GLOBALS['uxs_mail']       = array();

class FakeWpdb {
	public $prefix     = 'wp_';
	public $last_error = '';
	public $last_query = '';
	public $rows       = array();
	public $queries    = array();
	public function insert( $table, $data ) { $GLOBALS['uxs_log'][] = $data; return 1; }
	public function esc_like( $s ) { return $s; }
	public function prepare( $sql, ...$args ) {
		foreach ( $args as $a ) {
			$sql = preg_replace( '/%[ds]/', (string) $a, $sql, 1 );
		}
		return $sql;
	}
	/** @var array<string, array<int, mixed>> Fake tables for the snapshot checks: real name => rows. */
	public $tables = array();
	public function get_var( $sql ) {
		$this->queries[] = $sql;
		if ( false !== strpos( $sql, 'MAX(id)' ) ) {
			return $this->rows ? max( array_keys( $this->rows ) ) : null;
		}
		if ( preg_match( '/^SHOW TABLES LIKE (\S+)$/', $sql, $m ) ) {
			return isset( $this->tables[ $m[1] ] ) ? $m[1] : null;
		}
		if ( preg_match( '/^SELECT COUNT\(\*\) FROM (\S+)$/', $sql, $m ) ) {
			return count( $this->tables[ $m[1] ] ?? array() );
		}
		return null;
	}
	public function get_row( $sql, $output = null ) {
		if ( preg_match( '/^CHECKSUM TABLE (\S+)$/', $sql, $m ) ) {
			return array( 'Table' => $m[1], 'Checksum' => (string) crc32( serialize( $this->tables[ $m[1] ] ?? array() ) ) );
		}
		return null;
	}
	public function get_results( $sql, $output = null ) {
		$this->queries[] = $sql;
		if ( preg_match( '/id > (\d+) AND id <= (\d+) ORDER BY id ASC LIMIT (\d+)/', $sql, $m ) ) {
			$out = array();
			foreach ( $this->rows as $id => $row ) {
				if ( $id > (int) $m[1] && $id <= (int) $m[2] ) {
					$out[] = array( 'id' => $id, 'blocked_uri' => $row['blocked_uri'], 'directive' => $row['directive'] );
				}
			}
			return array_slice( $out, 0, (int) $m[3] );
		}
		return array();
	}
	public $poison = 0;
	public function update( $table, $data, $where ) {
		if ( $this->poison && (int) $where['id'] === $this->poison ) { throw new RuntimeException( 'poison row' ); }
		$GLOBALS['uxs_updates'][] = array( $where['id'], $data );
		return 1;
	}
	public function query( $sql ) { $this->queries[] = $sql; return 0; }
}
$GLOBALS['wpdb'] = new FakeWpdb();

function get_option( $name, $default = false ) { return array_key_exists( $name, $GLOBALS['uxs_options'] ) ? $GLOBALS['uxs_options'][ $name ] : $default; }
function update_option( $name, $value, $autoload = null ) { $GLOBALS['uxs_options'][ $name ] = $value; return true; }
function add_option( $name, $value = '', $dep = '', $autoload = null ) { if ( array_key_exists( $name, $GLOBALS['uxs_options'] ) ) { return false; } $GLOBALS['uxs_options'][ $name ] = $value; return true; }
function delete_option( $name ) { unset( $GLOBALS['uxs_options'][ $name ] ); return true; }
function get_transient( $k ) { return $GLOBALS['uxs_transients'][ $k ] ?? false; }
function set_transient( $k, $v, $ttl = 0 ) { $GLOBALS['uxs_transients'][ $k ] = $v; return true; }
function delete_transient( $k ) { unset( $GLOBALS['uxs_transients'][ $k ] ); return true; }
function get_user_meta( $uid, $key, $single = false ) { return ''; }
function get_current_user_id() { return 0; }
function current_time( $type ) { return '2026-10-24 12:00:00'; }
function wp_json_encode( $v ) { return json_encode( $v ); }
function wp_parse_url( $url, $component = -1 ) { return parse_url( $url, $component ); }
function home_url( $path = '' ) { return 'https://www.pobyty.cz' . $path; }
function wp_salt( $scheme = 'auth' ) { return 'test-salt'; }
function apply_filters( $hook, $value ) { return $value; }
function sanitize_key( $key ) { return strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', (string) $key ) ); }
function add_filter( $h, $f, $p = 10, $a = 1 ) { return true; }
function remove_filter( $h, $f, $p = 10 ) { return true; }
function __( $text, $domain = '' ) { return $text; }
function wp_next_scheduled( $hook ) { return $GLOBALS['uxs_events'][ $hook ] ?? false; }
function wp_schedule_single_event( $ts, $hook ) { $GLOBALS['uxs_events'][ $hook ] = $ts; return true; }
function wp_mail( $to, $subject, $body, $headers = array() ) { $GLOBALS['uxs_mail'][] = $subject; return true; }
function esc_html( $s ) { return htmlspecialchars( (string) $s ); }
function esc_html__( $s, $d = '' ) { return htmlspecialchars( $s ); }
function esc_url( $s ) { return $s; }
function admin_url( $p = '' ) { return 'https://example.test/wp-admin/' . $p; }
function get_bloginfo( $k ) { return 'Site'; }
function error_log_stub() {}

require_once dirname( __DIR__ ) . '/includes/Autoloader.php';
\UxStudio\Autoloader::register();

use UxStudio\Core\DB;
use UxStudio\Core\Upgrade;
use UxStudio\Core\Migration\AutoStart;
use UxStudio\Modules\SecurityOptimization\CspRerate;
use UxStudio\Modules\SecurityOptimization\CspMaintenance;

$failures = 0;
$assert   = static function ( string $name, bool $ok, string $detail = '' ) use ( &$failures ): void {
	if ( ! $ok ) {
		++$failures;
	}
	echo ( $ok ? 'PASS ' : 'FAIL ' ) . $name . ( $ok || '' === $detail ? '' : ' => ' . $detail ) . PHP_EOL;
};
$reset = static function (): void {
	$GLOBALS['uxs_options']    = array();
	$GLOBALS['uxs_transients'] = array();
	$GLOBALS['uxs_events']     = array();
	$GLOBALS['uxs_log']        = array();
	$GLOBALS['uxs_updates']    = array();
	$GLOBALS['uxs_mail']       = array();
	$GLOBALS['EZSQL_ERROR']    = array();
	$GLOBALS['wpdb']           = new FakeWpdb();
};
$src = static fn( string $rel ): string => (string) file_get_contents( dirname( __DIR__ ) . '/' . $rel );

/* ═══ 5. DB::ensure_module_tables ═══ */

$reset();
$calls = 0;
DB::ensure_module_tables( 'zz-mod', 3, static function ( int $from ) use ( &$calls ): void { ++$calls; } );
$versions = get_option( DB::MODULE_VERSIONS_OPTION );
$assert( 'success: the migrator ran once and the version is recorded', 1 === $calls && 3 === ( $versions['zz_mod'] ?? 0 ), json_encode( $versions ) );
DB::ensure_module_tables( 'zz-mod', 3, static function ( int $from ) use ( &$calls ): void { ++$calls; } );
$assert( 'success: a repeat call does nothing', 1 === $calls );
$assert( 'success: no failure marker, no log', array() === $GLOBALS['uxs_transients'] && array() === $GLOBALS['uxs_log'] );

// dbDelta() on a table that does not exist yet runs a DESCRIBE that fails by design: not a failure.
$reset();
DB::ensure_module_tables(
	'zz-mod',
	1,
	static function ( int $from ): void {
		$GLOBALS['EZSQL_ERROR'][]    = array( 'query' => 'DESCRIBE wp_new_table;', 'error_str' => "Table 'wp_new_table' doesn't exist" );
		$GLOBALS['wpdb']->last_query = 'CREATE TABLE wp_new_table (id int)';
	}
);
$assert( 'a failing DESCRIBE of a table that is about to be created does not block the version', 1 === ( get_option( DB::MODULE_VERSIONS_OPTION )['zz_mod'] ?? 0 ) );

// A CREATE TABLE that fails (recorded in $EZSQL_ERROR by wpdb) must not bump the version.
$reset();
$calls = 0;
$bad   = static function ( int $from ) use ( &$calls ): void {
	++$calls;
	$GLOBALS['EZSQL_ERROR'][]       = array( 'query' => "CREATE TABLE wp_t (id int, KEY k (nope))", 'error_str' => "Key column 'nope' doesn't exist in table" );
	$GLOBALS['wpdb']->last_error    = "Key column 'nope' doesn't exist in table";
	$GLOBALS['wpdb']->last_query    = 'CREATE TABLE wp_t (id int, KEY k (nope))';
};
DB::ensure_module_tables( 'zz-mod', 2, $bad );
$assert( 'failed CREATE: the version is NOT recorded', array() === (array) get_option( DB::MODULE_VERSIONS_OPTION, array() ) );
$assert( 'failed CREATE: logged once in the Activity Log with module, versions and the error', 1 === count( $GLOBALS['uxs_log'] ) && 'db_migration_failed' === $GLOBALS['uxs_log'][0]['action'] && false !== strpos( (string) $GLOBALS['uxs_log'][0]['meta'], 'nope' ) );
$assert( 'failed CREATE: retry is throttled by a transient', false !== get_transient( 'uxstudio_dbfail_zz_mod' ) );
DB::ensure_module_tables( 'zz-mod', 2, $bad );
$assert( 'throttled: the next request does not run the migrator again nor log again', 1 === $calls && 1 === count( $GLOBALS['uxs_log'] ) );
delete_transient( 'uxstudio_dbfail_zz_mod' );
DB::ensure_module_tables( 'zz-mod', 2, $bad );
$assert( 'after the throttle ends it is retried, but the log stays at one entry a day', 2 === $calls && 1 === count( $GLOBALS['uxs_log'] ) );
delete_transient( 'uxstudio_dbfail_zz_mod' );
$GLOBALS['EZSQL_ERROR'] = array();
$ok = static function ( int $from ) use ( &$calls ): void { ++$calls; };
DB::ensure_module_tables( 'zz-mod', 2, $ok );
$assert( 'once the problem is gone the version is recorded', 2 === ( get_option( DB::MODULE_VERSIONS_OPTION )['zz_mod'] ?? 0 ) );

// An ALTER that fails as the LAST query (only last_error tells).
$reset();
DB::ensure_module_tables(
	'zz-alt',
	2,
	static function ( int $from ): void {
		$GLOBALS['wpdb']->last_error = 'The table is full';
		$GLOBALS['wpdb']->last_query = "\n  ALTER TABLE wp_t ADD COLUMN a int";
	}
);
$assert( 'failed ALTER seen only through last_error is caught too', array() === (array) get_option( DB::MODULE_VERSIONS_OPTION, array() ) );
// Harmless duplicate key / column errors of a repeated step do not block the version.
foreach ( array( "Duplicate column name 'a'", "Duplicate key name 'k'", "Can't DROP 'k'; check that column/key exists" ) as $msg ) {
	$reset();
	DB::ensure_module_tables(
		'zz-dup',
		1,
		static function ( int $from ) use ( $msg ): void {
			$GLOBALS['EZSQL_ERROR'][]    = array( 'query' => 'ALTER TABLE wp_t ADD COLUMN a int', 'error_str' => $msg );
			$GLOBALS['wpdb']->last_error = $msg;
			$GLOBALS['wpdb']->last_query = 'ALTER TABLE wp_t ADD COLUMN a int';
		}
	);
	$assert( "benign schema error is ignored: $msg", 1 === ( get_option( DB::MODULE_VERSIONS_OPTION )['zz_dup'] ?? 0 ) );
}
// A stale last_error of an unrelated earlier query is cleared before the migrator.
$reset();
$GLOBALS['wpdb']->last_error = 'old unrelated error';
$GLOBALS['wpdb']->last_query = 'ALTER TABLE x';
DB::ensure_module_tables( 'zz-stale', 1, static function ( int $from ): void {} );
$assert( 'a stale error from before the migrator is ignored', 1 === ( get_option( DB::MODULE_VERSIONS_OPTION )['zz_stale'] ?? 0 ) );
// A failed data query (not CREATE/ALTER) does not block the version.
$reset();
DB::ensure_module_tables(
	'zz-data',
	1,
	static function ( int $from ): void {
		$GLOBALS['EZSQL_ERROR'][]    = array( 'query' => 'INSERT INTO wp_x VALUES (1)', 'error_str' => 'Duplicate entry' );
		$GLOBALS['wpdb']->last_error = 'Duplicate entry';
		$GLOBALS['wpdb']->last_query = 'INSERT INTO wp_x VALUES (1)';
	}
);
$assert( 'a failed data statement is not a schema failure', 1 === ( get_option( DB::MODULE_VERSIONS_OPTION )['zz_data'] ?? 0 ) );

/* ═══ 3. CSP v5 migration in batches ═══ */

$reset();
$wpdb = $GLOBALS['wpdb'];
for ( $i = 1; $i <= 450; $i++ ) {
	$wpdb->rows[ $i ] = array( 'blocked_uri' => 0 === $i % 2 ? 'https://www.googletagmanager.com/gtag/js' : 'https://kjhg.lol/x.js', 'directive' => 'script-src-elem' );
}
CspRerate::start();
$state = get_option( CspRerate::STATE_OPTION );
$assert( 'start: remembers the highest old row id, queues the work, rates nothing yet', 450 === get_option( CspRerate::LEGACY_OPTION ) && 450 === $state['max_id'] && 0 === $state['last_id'] && array() === $GLOBALS['uxs_updates'] );
$assert( 'start: nothing but one MAX(id) query ran (no per-row work in the request)', 1 === count( array_filter( $wpdb->queries, static fn( $q ) => false !== strpos( $q, 'MAX(id)' ) ) ) && 1 === count( $wpdb->queries ) );
$assert( 'start: pending, and ensure_scheduled queues the batch event', CspRerate::pending() && ( CspRerate::ensure_scheduled() || false !== wp_next_scheduled( CspRerate::HOOK ) ) );

$n1 = CspRerate::run_batch();
$assert( 'batch 1: exactly 200 rows rated, still pending, next batch queued', 200 === $n1 && 200 === count( $GLOBALS['uxs_updates'] ) && CspRerate::pending() && 200 === get_option( CspRerate::STATE_OPTION )['last_id'] );
$assert( 'batch 1: a rated row gets level/score/reasons and is flagged for the domain-age check again', isset( $GLOBALS['uxs_updates'][0][1]['risk_level'], $GLOBALS['uxs_updates'][0][1]['risk_score'] ) && array_key_exists( 'risk_checked_at', $GLOBALS['uxs_updates'][0][1] ) && null === $GLOBALS['uxs_updates'][0][1]['risk_checked_at'] );
$assert( 'batch: the lock is released after a batch', false === get_option( CspRerate::LOCK_OPTION, false ) );

// A parallel run is refused while the lock is fresh, taken over when stale.
add_option( CspRerate::LOCK_OPTION, (string) time() );
$assert( 'locked: a second run does nothing', 0 === CspRerate::run_batch() && 200 === count( $GLOBALS['uxs_updates'] ) );
update_option( CspRerate::LOCK_OPTION, (string) ( time() - 1000 ) );
$n2 = CspRerate::run_batch();
$assert( 'stale lock (crashed run) is taken over', 200 === $n2 );
$n3 = CspRerate::run_batch();
$assert( 'last batch: the remaining 50 rows, then the work is finished (state gone)', 50 === $n3 && ! CspRerate::pending() && 450 === count( $GLOBALS['uxs_updates'] ) );
$ids = array_map( static fn( $u ) => $u[0], $GLOBALS['uxs_updates'] );
$assert( 'every old row was rated exactly once, in id order', $ids === range( 1, 450 ) );
$assert( 'finished: nothing more to do and no leftover lock', 0 === CspRerate::run_batch() && false === get_option( CspRerate::LOCK_OPTION, false ) );
$assert( 'finished: the legacy marker stays for good', 450 === CspRerate::legacy_max_id() );

// A row that makes the rating throw is skipped, the later rows are still rated.
$GLOBALS['uxs_updates'] = array();
$GLOBALS['wpdb']->poison = 3;
CspRerate::start();
$p1 = CspRerate::run_batch();
$p2 = CspRerate::run_batch();
$p3 = CspRerate::run_batch();
$GLOBALS['wpdb']->poison = 0;
$ids = array_map( static fn( $u ) => $u[0], $GLOBALS['uxs_updates'] );
$assert( 'poison row: skipped, every other row rated, the work finishes', ! CspRerate::pending() && 449 === count( $ids ) && ! in_array( 3, $ids, true ) && in_array( 450, $ids, true ) );

// The structural migration code no longer rates rows itself.
$boot = $src( 'includes/Modules/SecurityOptimization/CspUploadGuardBootstrap.php' );
$from5 = substr( $boot, (int) strpos( $boot, '$from < 5' ), 900 );
$assert( 'schema step v5: calls CspRerate::start() and does not loop over rows itself', false !== strpos( $from5, 'CspRerate::start()' ) && false === strpos( $from5, 'CspRisk::rate' ) && false === strpos( $from5, 'foreach' ) );
$assert( 'bootstrap: the batch hook is registered and kept scheduled', false !== strpos( $boot, 'CspRerate::HOOK' ) && false !== strpos( $boot, 'CspRerate::ensure_scheduled()' ) );
$assert( 'schema v6 (reporters column) has no row-by-row data step', 1 === preg_match( '/v6 only adds the `reporters` column/', $boot ) );

// Old rows never cause the high-risk e-mail.
$maint = $src( 'includes/Modules/SecurityOptimization/CspMaintenance.php' );
$assert( 'e-mail: escalation by domain age only counts rows newer than the update', false !== strpos( $maint, "(int) \$row['id'] > CspRerate::legacy_max_id()" ) );
$assert( 'e-mail: notify_high_risk lists only rows newer than the update', 1 === preg_match( '/fn\( array \$row \): bool => \(int\) \$row\[\'id\'\] > CspRerate::legacy_max_id\(\)/', $maint ) );

/* ═══ 7. CSP mode bookkeeping for sites that already had a mode ═══ */

$reset();
$GLOBALS['uxs_options']['uxstudio_security_optimization'] = array( 'csp_mode' => 'report-only' );
Upgrade::record_csp_state();
$st = get_option( 'uxstudio_csp_state' );
$assert( 'report-only from 0.5.0 (no state): since = now, so the Enforce gate counts days from the update', 'report-only' === $st['mode'] && abs( $st['since'] - time() ) < 5 && 0 === $st['enforce_since'], json_encode( $st ) );

$reset();
$GLOBALS['uxs_options']['uxstudio_security_optimization'] = array( 'csp_mode' => 'audit' );
Upgrade::record_csp_state();
$assert( 'audit: same', 'audit' === get_option( 'uxstudio_csp_state' )['mode'] && get_option( 'uxstudio_csp_state' )['since'] > 0 );

$reset();
$GLOBALS['uxs_options']['uxstudio_security_optimization'] = array( 'csp_mode' => 'enforce' );
Upgrade::record_csp_state();
$st = get_option( 'uxstudio_csp_state' );
$assert( 'enforce from 0.5.0: mode recorded, enforce_since stays 0 (no automatic rollback window for a policy that was already enforced)', 'enforce' === $st['mode'] && $st['since'] > 0 && 0 === $st['enforce_since'] );

$reset();
$GLOBALS['uxs_options']['uxstudio_security_optimization'] = array( 'csp_mode' => 'off' );
Upgrade::record_csp_state();
$assert( 'CSP off: no state is created', false === get_option( 'uxstudio_csp_state', false ) );

$reset();
$GLOBALS['uxs_options']['uxstudio_security_optimization'] = array( 'csp_mode' => 'report-only' );
$GLOBALS['uxs_options']['uxstudio_csp_state']             = array( 'mode' => 'report-only', 'since' => 1700000000, 'enforce_since' => 0, 'paused_until' => 0 );
Upgrade::record_csp_state();
$assert( 'a site that already has a valid state is left alone', 1700000000 === get_option( 'uxstudio_csp_state' )['since'] );

// run(): every routine once, remembered.
$reset();
$GLOBALS['uxs_options']['uxstudio_security_optimization'] = array( 'csp_mode' => 'report-only', 'login_url_enabled' => true, 'login_url' => 'x' );
require_once dirname( __DIR__ ) . '/includes/Core/Migration/PreviewBoot.php';
Upgrade::run();
$marks = get_option( Upgrade::MARKS_OPTION );
$assert( 'run: both routines ran and are remembered, the login lock marker says it was configured', isset( $marks['login_lock'], $marks['csp_state'] ) && Upgrade::login_lock_was_configured() );
$GLOBALS['uxs_options']['uxstudio_security_optimization']['csp_mode'] = 'off';
unset( $GLOBALS['uxs_options']['uxstudio_csp_state'] );
Upgrade::run();
$assert( 'run: the second boot does nothing again', false === get_option( 'uxstudio_csp_state', false ) );

/* ═══ 4. Rollback of a migration run: what changed since the run ═══ */

use UxStudio\Core\Migration\Snapshot;
$reset();
$GLOBALS['wpdb']->tables['wp_uxstudio_redirects'] = array( array( 1, '/a', '/b' ), array( 2, '/c', '/d' ) );
$GLOBALS['uxs_options']['uxstudio_redirects']        = array( 'enabled' => true );
$GLOBALS['uxs_options']['uxstudio_snippets']         = array( 'x' => 1 );
$GLOBALS['uxs_options'][ DB::MODULE_VERSIONS_OPTION ] = array( 'redirects' => 1 );
// The snapshot as capture_options()/capture_table() leave it (state from BEFORE the run).
update_option(
	Snapshot::option_name( 'rdrift1' ),
	array(
		'run_id'  => 'rdrift1',
		'created' => time(),
		'options' => array(
			'uxstudio_redirects'        => array( 'exists' => true, 'value' => array() ),
			'uxstudio_snippets'         => array( 'exists' => false ),
			DB::MODULE_VERSIONS_OPTION  => array( 'exists' => true, 'value' => array() ),
		),
		'tables'  => array( 'uxstudio_redirects' => array( 'existed' => true, 'backup' => 'wp_uxsmb_rdrift1_uxstudio_redirects' ) ),
	)
);
$before = Snapshot::drift( 'rdrift1' );
$assert( 'run not yet sealed (no fingerprint): reported as unsealed, the stronger warning applies', false === $before['sealed'] && array() === $before['options'] && array( 'uxstudio_redirects', 'uxstudio_snippets' ) === $before['all_options'] && array( 'uxstudio_redirects' ) === $before['all_tables'] );
Snapshot::seal( 'rdrift1' );
$d = Snapshot::drift( 'rdrift1' );
$assert( 'sealed right after the run: nothing changed since', true === $d['sealed'] && false === $d['changed'] && array() === $d['options'] && array() === $d['tables'], json_encode( $d ) );
$GLOBALS['uxs_options'][ DB::MODULE_VERSIONS_OPTION ]['other'] = 3;
$assert( 'the module-version bookkeeping option never counts as a change', false === Snapshot::drift( 'rdrift1' )['changed'] );
$GLOBALS['uxs_options']['uxstudio_redirects'] = array( 'enabled' => false );
$d = Snapshot::drift( 'rdrift1' );
$assert( 'a setting changed after the run is reported (by option name)', true === $d['changed'] && array( 'uxstudio_redirects' ) === $d['options'] && array() === $d['tables'], json_encode( $d ) );
$GLOBALS['uxs_options']['uxstudio_redirects'] = array( 'enabled' => true );
$GLOBALS['wpdb']->tables['wp_uxstudio_redirects'][] = array( 3, '/e', '/f' );
$d = Snapshot::drift( 'rdrift1' );
$assert( 'a row added to a snapshotted table after the run is reported with the row counts', true === $d['changed'] && array() === $d['options'] && 'uxstudio_redirects' === $d['tables'][0]['name'] && 2 === $d['tables'][0]['rows_then'] && 3 === $d['tables'][0]['rows_now'], json_encode( $d ) );
array_pop( $GLOBALS['wpdb']->tables['wp_uxstudio_redirects'] );
$GLOBALS['wpdb']->tables['wp_uxstudio_redirects'][1][2] = '/zzz';
$assert( 'an edited row (same row count) is caught by the checksum', true === Snapshot::drift( 'rdrift1' )['changed'] );
// A big table is fingerprinted by its row count only (CHECKSUM TABLE would read it all).
$GLOBALS['wpdb']->tables['wp_uxstudio_big'] = array_fill( 0, 200001, 1 );
update_option( Snapshot::option_name( 'rbig' ), array( 'created' => time(), 'options' => array(), 'tables' => array( 'uxstudio_big' => array( 'existed' => true, 'backup' => 'x' ) ) ), false );
Snapshot::seal( 'rbig' );
$sealed_big = get_option( Snapshot::option_name( 'rbig' ) )['sealed']['tables']['uxstudio_big'];
$assert( 'seal: a table above 200k rows gets a row count and no checksum', 200001 === $sealed_big['rows'] && '' === $sealed_big['checksum'], json_encode( $sealed_big ) );
$assert( 'seal: the big table still reports no drift while the count is unchanged', false === Snapshot::drift( 'rbig' )['changed'] );
$GLOBALS['wpdb']->tables['wp_uxstudio_big'][] = 1;
$assert( 'seal: a changed row count of the big table is still a drift', true === Snapshot::drift( 'rbig' )['changed'] );
$src_runner = $src( 'includes/Core/Migration/Runner.php' );
$assert( 'runner: the history record is written before the snapshot is sealed', strpos( $src_runner, 'History::put(' ) < strpos( $src_runner, 'Snapshot::seal(' ) );
$assert( 'no snapshot: no drift record', null === Snapshot::drift( 'rnone' ) );

$parts = $src( 'src/app/wizard/parts.tsx' );
$assert( 'UI: the shared undo dialog loads the impact and demands a tick when something changed or cannot be told', false !== strpos( $parts, 'rollback-impact?run_id=' ) && false !== strpos( $parts, 'needs_ack' ) && false !== strpos( $parts, 'needsAck && ! acked' ) );
$assert( 'UI: both the history list and the verify step use that dialog', false !== strpos( $src( 'src/app/wizard/RunHistory.tsx' ), '<UndoMigrationModal' ) && false !== strpos( $src( 'src/app/wizard/steps.tsx' ), '<UndoMigrationModal' ) );

/* ═══ 6. Wizard hint is cached ═══ */

$reset();
$scans = 0;
AutoStart::set_scanner(
	static function () use ( &$scans ): array {
		++$scans;
		return array( array( 'status' => 'active' ), array( 'status' => 'data_only', 'has_data' => true ), array( 'status' => 'inactive', 'has_data' => false ) );
	}
);
$h1 = AutoStart::hint( 1 );
$h2 = AutoStart::hint( 1 );
$h3 = AutoStart::hint( 2 );
$assert( 'hint: the full scan ran once for three visits', 1 === $scans && $h1 === $h2 && $h2 === $h3 && true === $h1['show'] && 2 === $h1['count'], json_encode( array( $scans, $h1 ) ) );
AutoStart::flush_hint_cache();
AutoStart::hint( 1 );
$assert( 'hint: flushing the cache (plugin activated/deactivated/deleted, migration ran) scans again', 2 === $scans );
$reg = $src( 'includes/Core/Migration/AutoStart.php' );
$assert( 'hint: the cache is flushed on activated_plugin, deactivated_plugin and deleted_plugin', 1 === preg_match( "/'activated_plugin', 'deactivated_plugin', 'deleted_plugin'/", $reg ) );
$assert( 'hint: a run in History flushes it too', false !== strpos( $src( 'includes/Core/Migration/History.php' ), 'AutoStart::flush_hint_cache()' ) );
$GLOBALS['uxs_options']['uxstudio_migration_history'] = array();
foreach ( array( 'EmailLogSource', 'RedirectsSource' ) as $cls ) {
	$code = $src( "includes/Core/Migration/Sources/{$cls}.php" );
	$hd   = substr( $code, (int) strpos( $code, 'protected function has_data' ), 200 );
	$assert( "{$cls}: has_data() asks whether a row exists (LIMIT 1), it does not count", false === strpos( $hd, 'count_rows() > 0' ) && false === strpos( $hd, 'collect_count() > 0' ) && false !== strpos( $code, 'LIMIT 1' ) );
}
AutoStart::set_scanner( null );

// Cleanup of the fake ABSPATH.
@unlink( $fake_abspath . 'wp-admin/includes/upgrade.php' );
@rmdir( $fake_abspath . 'wp-admin/includes' );
@rmdir( $fake_abspath . 'wp-admin' );
@rmdir( $fake_abspath );

echo PHP_EOL . ( $failures ? "$failures FAILED" : 'ALL PASSED' ) . PHP_EOL;
exit( $failures ? 1 : 0 );
