<?php
// Test only: never runs over HTTP (the release zip also excludes tests/).
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { exit; }
/**
 * Standalone test of the dashboard widget rules (PLAN.md 20.10 / 0.8.4): who sees which widget, the conversion of
 * the older per-role lists, sanitising, bulk actions, the snapshot of registered widgets.
 *
 *   php tests/dashboard-widget-rules.php
 *
 * Needs no WordPress and no database. Exits non-zero when any case fails.
 *
 * @package UxStudio
 */

// phpcs:disable

define( 'ABSPATH', __DIR__ . '/' );

require_once dirname( __DIR__ ) . '/includes/Modules/DashboardWidgets/WidgetRules.php';

use UxStudio\Modules\DashboardWidgets\WidgetRules as W;

$failures = 0;
$assert   = static function ( string $name, bool $ok, string $detail = '' ) use ( &$failures ): void {
	if ( ! $ok ) {
		++$failures;
	}
	echo ( $ok ? 'PASS ' : 'FAIL ' ) . $name . ( $ok || '' === $detail ? '' : ' => ' . $detail ) . PHP_EOL;
};
$j = static fn( $v ): string => (string) json_encode( $v );

$all = array( 'administrator', 'editor', 'author', 'contributor', 'subscriber' );

/* ── Visibility ── */
$rules = array(
	'hidden_one' => array( 'hidden' => true, 'roles' => array() ),
	'admins'     => array( 'hidden' => false, 'roles' => array( 'administrator' ) ),
	'ed_or_au'   => array( 'hidden' => false, 'roles' => array( 'editor', 'author' ) ),
	'hid_roles'  => array( 'hidden' => true, 'roles' => array( 'administrator' ) ),
);
$assert( 'default: a widget with no rule is shown to everybody', W::visible( $rules, 'dashboard_activity', array( 'subscriber' ) ) && W::visible( array(), 'x', array() ) );
$assert( 'hidden: nobody sees it, not even an administrator', ! W::visible( $rules, 'hidden_one', array( 'administrator' ) ) && ! W::visible( $rules, 'hidden_one', array() ) );
$assert( 'hidden wins over the role list', ! W::visible( $rules, 'hid_roles', array( 'administrator' ) ) );
$assert( 'role list: shown to the listed role, not to another', W::visible( $rules, 'admins', array( 'administrator' ) ) && ! W::visible( $rules, 'admins', array( 'editor' ) ) );
$assert( 'multi-role user: one matching role is enough', W::visible( $rules, 'admins', array( 'editor', 'administrator' ) ) && W::visible( $rules, 'ed_or_au', array( 'subscriber', 'author' ) ) );
$assert( 'multi-role user: none matching = not shown', ! W::visible( $rules, 'ed_or_au', array( 'subscriber', 'contributor' ) ) );
$assert( 'a user without roles does not pass a role list', ! W::visible( $rules, 'admins', array() ) );
$assert( 'hidden_for for an editor', array( 'hidden_one', 'admins', 'hid_roles' ) === W::hidden_for( $rules, array( 'editor' ) ), $j( W::hidden_for( $rules, array( 'editor' ) ) ) );
$assert( 'hidden_for for an administrator', array( 'hidden_one', 'ed_or_au', 'hid_roles' ) === W::hidden_for( $rules, array( 'administrator' ) ), $j( W::hidden_for( $rules, array( 'administrator' ) ) ) );

/* ── Migration of the older lists ── */
$m = W::from_legacy( array( 'dashboard_welcome', 'dashboard_primary' ), array(), $all );
$assert( 'migration: the global list becomes hidden', array( 'dashboard_welcome' => array( 'hidden' => true, 'roles' => array() ), 'dashboard_primary' => array( 'hidden' => true, 'roles' => array() ) ) === $m, $j( $m ) );

$m = W::from_legacy( array(), array( 'editor' => array( 'dashboard_primary', 'dashboard_activity' ), 'author' => array( 'dashboard_primary' ) ), $all );
$assert( 'migration: hidden for some roles = shown to all other roles', array( 'administrator', 'contributor', 'subscriber' ) === $m['dashboard_primary']['roles'] && false === $m['dashboard_primary']['hidden'], $j( $m ) );
$assert( 'migration: one hiding role', array( 'administrator', 'author', 'contributor', 'subscriber' ) === $m['dashboard_activity']['roles'], $j( $m ) );
$assert( 'migration: the same visibility for every user combination as before', (static function () use ( $m, $all ): bool {
	// Old rule: hidden when EVERY role of the user hides it.
	$old = array( 'editor' => array( 'dashboard_primary', 'dashboard_activity' ), 'author' => array( 'dashboard_primary' ) );
	$combos = array( array( 'editor' ), array( 'author' ), array( 'editor', 'author' ), array( 'editor', 'subscriber' ), array( 'author', 'administrator' ), array( 'subscriber' ) );
	foreach ( array( 'dashboard_primary', 'dashboard_activity' ) as $id ) {
		foreach ( $combos as $roles ) {
			$hidden_old = true;
			foreach ( $roles as $r ) {
				$hidden_old = $hidden_old && in_array( $id, $old[ $r ] ?? array(), true );
			}
			if ( ! $hidden_old !== W::visible( $m, $id, $roles ) ) {
				return false;
			}
		}
	}
	return true;
})() );

$m = W::from_legacy( array(), array_fill_keys( $all, array( 'dashboard_site_health' ) ), $all );
$assert( 'migration: hidden by every role = hidden', array( 'dashboard_site_health' => array( 'hidden' => true, 'roles' => array() ) ) === $m, $j( $m ) );

$m = W::from_legacy( array( 'dashboard_primary' ), array( 'editor' => array( 'dashboard_primary', 'dashboard_activity' ) ), $all );
$assert( 'migration: global hide wins over the per-role list', true === $m['dashboard_primary']['hidden'] && array() === $m['dashboard_primary']['roles'] && isset( $m['dashboard_activity'] ), $j( $m ) );

$m = W::from_legacy( array(), array( 'ghost_role' => array( 'dashboard_primary' ), 'editor' => array() ), $all );
$assert( 'migration: a role the site does not have and empty lists give nothing', array() === $m, $j( $m ) );

$m = W::from_legacy( array( 'bad id!', '' ), array( 'editor' => array( '<script>' ) ), $all );
$assert( 'migration: unusable ids are dropped', array() === $m, $j( $m ) );

$a = W::from_legacy( array( 'x' ), array( 'editor' => array( 'y' ) ), $all );
$b = W::from_legacy( array( 'x' ), array( 'editor' => array( 'y' ) ), $all );
$assert( 'migration: idempotent (same input, same output)', $a === $b );
$h1 = W::legacy_hash( array( 'x', 'a' ), array( 'editor' => array( 'y', 'b' ), 'author' => array() ) );
$h2 = W::legacy_hash( array( 'a', 'x' ), array( 'editor' => array( 'b', 'y' ) ) );
$assert( 'legacy_hash: order and empty lists do not matter, nothing = empty', $h1 === $h2 && '' !== $h1 && '' === W::legacy_hash( array(), array( 'editor' => array() ) ) && $h1 !== W::legacy_hash( array( 'x' ), array() ) );

/* ── Sanitising ── */
$rep = array();
$s   = W::sanitize(
	array(
		'good'            => array( 'hidden' => true, 'roles' => array( 'editor', 'editor', 'ghost' ) ),
		'only_roles'      => array( 'roles' => array( 'administrator' ) ),
		'defaults'        => array( 'hidden' => false, 'roles' => array() ),
		'bad id!'         => array( 'hidden' => true ),
		'<b>x</b>'        => array( 'hidden' => true ),
		'x:y.z-1_2'       => array( 'hidden' => '1' ),
		'notarray'        => 'yes',
		str_repeat( 'a', 121 ) => array( 'hidden' => true ),
	),
	$all,
	$rep
);
$assert( 'sanitize: ids with the allowed characters only', array( 'good', 'only_roles', 'x:y.z-1_2' ) === array_keys( $s ), $j( array_keys( $s ) ) );
$assert( 'sanitize: unknown roles are dropped and counted, duplicates merged', array( 'editor' ) === $s['good']['roles'] && 1 === $rep['dropped_roles'], $j( array( $s['good'], $rep ) ) );
$assert( 'sanitize: the defaults are not stored', ! isset( $s['defaults'] ) );
$assert( 'sanitize: loose hidden values', true === $s['x:y.z-1_2']['hidden'] && false === $s['only_roles']['hidden'] );
$assert( 'sanitize: bad ids are counted', 4 === $rep['dropped_ids'], $j( $rep ) );
$list = W::sanitize( array( array( 'id' => 'a', 'hidden' => true ), array( 'id' => 'b', 'roles' => array( 'author' ) ) ), $all );
$assert( 'sanitize: a list of {id, ...} works too', array( 'a', 'b' ) === array_keys( $list ), $j( $list ) );
$assert( 'sanitize: not an array gives an empty map', array() === W::sanitize( 'x', $all ) && array() === W::sanitize( null, $all ) );
$many = array();
for ( $i = 0; $i < 450; $i++ ) {
	$many[ 'w' . $i ] = array( 'hidden' => true );
}
$rep = array();
$s   = W::sanitize( $many, $all, $rep );
$assert( 'sanitize: at most 300 entries', 300 === count( $s ) && 150 === $rep['truncated'], count( $s ) . ' ' . $j( $rep ) );
$assert( 'sanitize: without a role list the roles are kept (characters only)', array( 'whatever' ) === W::sanitize( array( 'a' => array( 'roles' => array( 'whatever', 'bad role' ) ) ), null )['a']['roles'] );
$once = W::sanitize( $rules, $all );
$assert( 'sanitize: idempotent', $once === W::sanitize( $once, $all ) );

/* ── Bulk operations ── */
$base = array( 'a' => array( 'hidden' => false, 'roles' => array( 'editor' ) ) );
$r    = W::apply_bulk( $base, 'hide', array( 'a', 'b' ), array(), $all );
$assert( 'bulk hide: hides, keeps the roles of an existing entry', true === $r['a']['hidden'] && array( 'editor' ) === $r['a']['roles'] && true === $r['b']['hidden'], $j( $r ) );
$r = W::apply_bulk( $r, 'show', array( 'a', 'b' ), array(), $all );
$assert( 'bulk show: shows again, an entry that is back to the defaults disappears', false === $r['a']['hidden'] && ! isset( $r['b'] ), $j( $r ) );
$r = W::apply_bulk( $r, 'roles', array( 'a', 'c' ), array( 'author', 'ghost' ), $all );
$assert( 'bulk roles: sets the allow list, drops unknown roles', array( 'author' ) === $r['a']['roles'] && array( 'author' ) === $r['c']['roles'], $j( $r ) );
$r = W::apply_bulk( $r, 'roles', array( 'a' ), $all, $all );
$assert( 'bulk roles: all roles = everybody (the entry disappears)', ! isset( $r['a'] ) && isset( $r['c'] ), $j( $r ) );
$r = W::apply_bulk( $r, 'roles', array( 'c' ), array(), $all );
$assert( 'bulk roles: no role = everybody', ! isset( $r['c'] ) );
$r = W::apply_bulk( array( 'a' => array( 'hidden' => true, 'roles' => array( 'editor' ) ), 'z' => array( 'hidden' => true, 'roles' => array() ) ), 'reset', array( 'a' ), array(), $all );
$assert( 'bulk reset: back to the defaults for the chosen ones only', ! isset( $r['a'] ) && isset( $r['z'] ), $j( $r ) );
$assert( 'bulk: an unknown action changes nothing', $base === W::apply_bulk( $base, 'delete', array( 'a' ), array(), $all ) );
$assert( 'bulk: bad ids are skipped', $base === W::apply_bulk( $base, 'hide', array( '', 'a b', array( 'x' ) ), array(), $all ) );
$r = W::set_items( array(), array( array( 'id' => 'a', 'hidden' => true ), array( 'id' => 'b', 'roles' => array( 'editor' ) ), 'junk', array( 'id' => 'bad id' ) ), $all );
$assert( 'set_items: hidden and roles per widget', true === $r['a']['hidden'] && array( 'editor' ) === $r['b']['roles'] && 2 === count( $r ), $j( $r ) );
$r = W::set_items( $r, array( array( 'id' => 'a', 'hidden' => false ), array( 'id' => 'b', 'roles' => array() ) ), $all );
$assert( 'set_items: back to the defaults removes the entry', array() === $r, $j( $r ) );

/* ── Snapshot ── */
$seen = array(
	'dashboard_activity' => array( 'title' => 'Activity', 'context' => 'normal', 'priority' => 'high', 'source' => 'core' ),
	'wpseo-dashboard-overview' => array( 'title' => 'Yoast', 'context' => 'normal', 'priority' => 'core', 'source' => 'plugin', 'source_name' => 'Yoast SEO', 'guess' => true ),
);
$snap = W::merge_snapshot( array(), $seen, 1000 );
$assert( 'snapshot: new widgets get first_seen = last_seen = scan time', 1000 === $snap['scan_at'] && 1000 === $snap['widgets']['dashboard_activity']['first_seen'] && 1000 === $snap['widgets']['dashboard_activity']['last_seen'], $j( $snap ) );
$snap2 = W::merge_snapshot( $snap, array( 'dashboard_activity' => $seen['dashboard_activity'] ), 2000 );
$assert( 'snapshot: first_seen stays, last_seen moves', 1000 === $snap2['widgets']['dashboard_activity']['first_seen'] && 2000 === $snap2['widgets']['dashboard_activity']['last_seen'] );
$assert( 'snapshot: a widget missing in the latest scan is stale, the others are not', W::is_stale( $snap2['widgets']['wpseo-dashboard-overview'], $snap2['scan_at'] ) && ! W::is_stale( $snap2['widgets']['dashboard_activity'], $snap2['scan_at'] ) );
$assert( 'snapshot: the stale entry is kept with its data', 'Yoast' === $snap2['widgets']['wpseo-dashboard-overview']['title'] && true === $snap2['widgets']['wpseo-dashboard-overview']['guess'] );
$snap3 = W::merge_snapshot( $snap2, $seen, 3000 );
$assert( 'snapshot: it comes back when the widget is registered again', ! W::is_stale( $snap3['widgets']['wpseo-dashboard-overview'], $snap3['scan_at'] ) && 1000 === $snap3['widgets']['wpseo-dashboard-overview']['first_seen'] );
$assert( 'snapshot: bad ids are ignored', 2 === count( W::merge_snapshot( array(), $seen + array( 'bad id' => array( 'title' => 'x' ) ), 5 )['widgets'] ) );
$assert( 'needs_write: only the times changed = no write', ! W::needs_write( $snap, W::merge_snapshot( $snap, $seen, 1100 ), 3600 ) );
$assert( 'needs_write: a new widget = write', W::needs_write( $snap, W::merge_snapshot( $snap, $seen + array( 'new' => array( 'title' => 'N' ) ), 1100 ), 3600 ) );
$assert( 'needs_write: an old scan is written anyway', W::needs_write( $snap, W::merge_snapshot( $snap, $seen, 5000 ), 3600 ) );

/* ── Where the code of a widget lives ── */
$dirs = array(
	'plugins'    => 'D:/xampp/htdocs/pobyty/wp-content/plugins',
	'mu_plugins' => 'D:/xampp/htdocs/pobyty/wp-content/mu-plugins',
	'themes'     => 'D:/xampp/htdocs/pobyty/wp-content/themes',
	'core'       => 'D:/xampp/htdocs/pobyty/wp-admin|D:/xampp/htdocs/pobyty/wp-includes',
	'self'       => 'D:/xampp/htdocs/_uxs-wt-csp/',
);
$cp = static fn( string $f ): array => W::classify_path( $f, $dirs );
$assert( 'source: core', array( 'source' => 'core', 'folder' => '' ) === $cp( 'D:\\xampp\\htdocs\\pobyty\\wp-admin\\includes\\dashboard.php' ) );
$assert( 'source: a plugin and its folder', array( 'source' => 'plugin', 'folder' => 'wordpress-seo' ) === $cp( 'D:/xampp/htdocs/pobyty/wp-content/plugins/wordpress-seo/admin/x.php' ) );
$assert( 'source: UX Studio before the plugins folder', array( 'source' => 'uxstudio', 'folder' => '' ) === $cp( 'D:/xampp/htdocs/_uxs-wt-csp/includes/Modules/Forms/DashboardWidget.php' ) );
$assert( 'source: mu-plugin and theme', 'mu' === $cp( 'D:/xampp/htdocs/pobyty/wp-content/mu-plugins/a.php' )['source'] && array( 'source' => 'theme', 'folder' => 'astra' ) === $cp( 'D:/xampp/htdocs/pobyty/wp-content/themes/astra/functions.php' ) );
$assert( 'source: unknown path and empty path', 'unknown' === $cp( '/elsewhere/x.php' )['source'] && 'unknown' === $cp( '' )['source'] );
$assert( 'source: a folder that only starts like the plugins folder is not inside it', 'unknown' === $cp( 'D:/xampp/htdocs/pobyty/wp-content/plugins-old/x/a.php' )['source'] );

echo PHP_EOL . ( $failures ? "FAILED: $failures" : 'ALL PASSED' ) . PHP_EOL;
exit( $failures ? 1 : 0 );
