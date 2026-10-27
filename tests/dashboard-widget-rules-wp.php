<?php
// Test only: never runs over HTTP (the release zip also excludes tests/).
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { exit; }
/**
 * WordPress side of the dashboard widget rules (PLAN.md 20.10 / 0.8.4), run against a real site (the local pobyty):
 *
 *   cd D:\xampp\htdocs\pobyty
 *   D:/xampp/wp-cli/wp.bat --skip-plugins=wp-mail-smtp eval-file D:\xampp\htdocs\_uxs-wt-csp\tests\dashboard-widget-rules-wp.php
 *
 * Snapshot capture on wp_dashboard_setup (administrators only), the real removal in $wp_meta_boxes for a restricted role
 * versus an administrator, "hide all", the migration from the older per-role lists (fixture), the REST routes (capabilities,
 * validation, bulk actions, undo, refresh, forgetting a widget), and the export / import round trip of `widget_rules`
 * (also an older file with the old keys). The module is switched on for the test only; the module list, the module option,
 * the snapshot and the import snapshot are put back exactly, the uxstudio_* cron hooks are compared before and after, the
 * temporary editor user is deleted.
 *
 * @package UxStudio
 */

defined( 'ABSPATH' ) || exit;

use UxStudio\Core\Migration\Snapshot;
use UxStudio\Core\Transfer\Envelope;
use UxStudio\Core\Transfer\Exporter;
use UxStudio\Core\Transfer\ImportSnapshot;
use UxStudio\Core\Transfer\Importer;
use UxStudio\Modules\DashboardWidgets\WidgetRules;
use UxStudio\Modules\DashboardWidgets\WidgetSnapshot;
use UxStudio\Plugin;

$failures = 0;
$assert   = static function ( string $name, bool $ok, string $detail = '' ) use ( &$failures ): void {
	if ( ! $ok ) {
		++$failures;
	}
	WP_CLI::log( ( $ok ? 'PASS ' : 'FAIL ' ) . $name . ( $ok || '' === $detail ? '' : ' => ' . $detail ) );
};
$j = static fn( $v ): string => (string) wp_json_encode( $v );

// The dashboard registers some widgets only in the blog admin (the constant wp-admin/index.php defines).
if ( ! defined( 'WP_BLOG_ADMIN' ) ) {
	define( 'WP_BLOG_ADMIN', true );
}
require_once ABSPATH . 'wp-admin/includes/admin.php';
require_once ABSPATH . 'wp-admin/includes/dashboard.php';

$admins = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) );
$admin  = (int) $admins[0];
wp_set_current_user( $admin );

$modules  = Plugin::instance()->modules;
$exporter = new Exporter( $modules );
$importer = new Importer( $modules );

/** @return string[] uxstudio_* cron hooks. */
$cron_hooks = static function (): array {
	$out = array();
	foreach ( (array) _get_cron_array() as $hooks ) {
		foreach ( array_keys( (array) $hooks ) as $hook ) {
			if ( 0 === strpos( (string) $hook, 'uxstudio_' ) ) {
				$out[ $hook ] = true;
			}
		}
	}
	$out = array_keys( $out );
	sort( $out );
	return $out;
};
$cron_before = $cron_hooks();

$names = array( 'uxstudio_active_modules', 'uxstudio_dashboard_widgets', WidgetSnapshot::OPTION, ImportSnapshot::OPTION, 'uxstudio_import_applied', 'uxstudio_module_db_versions' );
$saved = Snapshot::read_options( $names );
$user_id = 0;

$restore = static function () use ( &$saved, &$user_id ): void {
	Snapshot::restore_options( $saved );
	delete_transient( 'uxstudio_dashboard_widget_force' );
	delete_transient( 'uxstudio_rl_' . get_current_user_id() );
	if ( $user_id ) {
		wp_delete_user( $user_id );
	}
	wp_cache_flush();
};

/** Run the dashboard setup as a user and return the ids left in $wp_meta_boxes['dashboard'] (and whether the welcome action is hooked). */
$dashboard_as = static function ( int $uid ): array {
	global $wp_meta_boxes;
	wp_set_current_user( $uid );
	$wp_meta_boxes = array();
	remove_all_actions( 'welcome_panel' );
	add_action( 'welcome_panel', 'wp_welcome_panel' );
	set_current_screen( 'dashboard' );
	wp_dashboard_setup();
	$ids = array();
	foreach ( (array) ( $wp_meta_boxes['dashboard'] ?? array() ) as $priorities ) {
		foreach ( (array) $priorities as $boxes ) {
			foreach ( (array) $boxes as $id => $box ) {
				if ( is_array( $box ) ) {
					$ids[] = (string) $id;
				}
			}
		}
	}
	sort( $ids );
	return array( 'ids' => $ids, 'welcome' => (bool) has_action( 'welcome_panel', 'wp_welcome_panel' ) );
};

/** Widgets any user may see (not the admin-only ones of UX Studio). */
$not_own = static fn( string $id ): bool => 0 !== strpos( $id, 'uxstudio_' ) && 'claude_panel_dashboard_widget' !== $id;

/** REST call as the current user. */
$rest = static function ( string $method, string $path, array $body = array() ): array {
	if ( 'GET' !== $method ) {
		delete_transient( 'uxstudio_rl_' . get_current_user_id() ); // 60 writes a minute per user: the test makes more.
	}
	$request = new WP_REST_Request( $method, '/uxstudio/v1' . $path );
	if ( $body ) {
		$request->set_header( 'content-type', 'application/json' );
		$request->set_body( wp_json_encode( $body ) );
		foreach ( $body as $k => $v ) {
			$request->set_param( $k, $v );
		}
	}
	$response = rest_do_request( $request );
	$data     = $response->get_data();
	return array(
		'status' => $response->get_status(),
		'data'   => is_array( $data ) && isset( $data['data'] ) ? $data['data'] : $data,
		'code'   => is_array( $data ) ? ( $data['code'] ?? '' ) : '',
	);
};

try {
	// The module is off on this site: switch it on for the test (restored at the end).
	$active     = (array) get_option( 'uxstudio_active_modules', array() );
	$was_active = in_array( 'dashboard-widgets', $active, true ); // Then it is booted already (its hooks exist) and holds the old settings.
	if ( ! $was_active ) {
		update_option( 'uxstudio_active_modules', array_values( array_merge( $active, array( 'dashboard-widgets' ) ) ) );
	}
	delete_option( 'uxstudio_dashboard_widgets' );
	delete_option( WidgetSnapshot::OPTION );
	wp_cache_flush();

	$module = $modules->instance( 'dashboard-widgets' );
	$module->reload_settings();
	if ( ! $was_active ) {
		$module->boot();
	}
	$GLOBALS['wp_rest_server'] = null;
	rest_get_server();

	// Two widgets of our own for the test (the site's Security settings may remove the news and health widgets of core).
	add_action(
		'wp_dashboard_setup',
		static function (): void {
			wp_add_dashboard_widget( 'uxs_test_a', 'Test box A', '__return_empty_string' );
			wp_add_dashboard_widget( 'uxs_test_b', 'Test box B', '__return_empty_string' );
		},
		20
	);

	$editor = wp_insert_user( array( 'user_login' => 'uxs_dw_test_' . wp_rand( 1000, 9999 ), 'user_pass' => wp_generate_password(), 'user_email' => 'uxs-dw-test@example.invalid', 'role' => 'editor' ) );
	$assert( 'a temporary editor exists', ! is_wp_error( $editor ) );
	$user_id = (int) $editor;

	/* ═══ 1. Snapshot ═══ */

	$view = $dashboard_as( $admin );
	$snap = WidgetSnapshot::read();
	$assert( 'the dashboard setup of an administrator writes the snapshot', count( $snap['widgets'] ) >= 5 && $snap['scan_at'] > 0, count( $snap['widgets'] ) . ' widgets' );
	foreach ( array( 'dashboard_right_now', 'dashboard_activity', 'dashboard_quick_press' ) as $core ) {
		$assert( "snapshot has the core widget $core", isset( $snap['widgets'][ $core ] ) && 'core' === $snap['widgets'][ $core ]['source'] && false === $snap['widgets'][ $core ]['guess'] );
	}
	$assert( 'snapshot has the welcome panel of WordPress (an action, not a box)', isset( $snap['widgets']['dashboard_welcome'] ) && 'welcome' === $snap['widgets']['dashboard_welcome']['context'] );
	$own = array_filter( array_keys( $snap['widgets'] ), static fn( $id ) => 0 === strpos( (string) $id, 'uxstudio_' ) );
	WP_CLI::log( 'INFO own widgets in the snapshot: ' . implode( ', ', $own ) );
	$plugin_widgets = array_filter( $snap['widgets'], static fn( $w ) => 0 === strpos( (string) $w['source'], 'plugin:' ) );
	WP_CLI::log( 'INFO plugin widgets: ' . implode( ', ', array_map( static fn( $w ) => $w['id'] . ' <- ' . $w['source'] . ' (' . $w['source_name'] . ')', $plugin_widgets ) ) );
	$assert( 'a plugin widget is attributed to a plugin and marked as an estimate (when the site has one)', ! $plugin_widgets || ( reset( $plugin_widgets )['guess'] && '' !== reset( $plugin_widgets )['source_name'] ) );
	$assert( 'widgets of UX Studio itself are attributed to UX Studio (no estimate)', ! $own || ( 'uxstudio' === $snap['widgets'][ reset( $own ) ]['source'] && false === $snap['widgets'][ reset( $own ) ]['guess'] ), $j( $snap['widgets'][ reset( $own ) ] ?? null ) );
	// Attribution of a callback of another active plugin (the site may have no plugin widget of its own).
	$foreign = null;
	foreach ( get_declared_classes() as $class_name ) {
		$rc   = new ReflectionClass( $class_name );
		$file = (string) $rc->getFileName();
		if ( '' === $file || 0 !== strpos( wp_normalize_path( $file ), wp_normalize_path( WP_PLUGIN_DIR ) . '/' ) || 0 === strpos( wp_normalize_path( $file ), wp_normalize_path( UXSTUDIO_PATH ) ) ) {
			continue;
		}
		foreach ( $rc->getMethods() as $method ) {
			if ( $method->isPublic() && $method->getFileName() === $file ) {
				$foreign = array( $class_name, $method->getName(), $file );
				break 2;
			}
		}
	}
	if ( $foreign ) {
		$src = WidgetSnapshot::source( array( $foreign[0], $foreign[1] ) );
		$dir = explode( '/', substr( wp_normalize_path( $foreign[2] ), strlen( wp_normalize_path( WP_PLUGIN_DIR ) ) + 1 ) )[0];
		WP_CLI::log( 'INFO callback of another plugin: ' . $foreign[0] . '::' . $foreign[1] . ' -> ' . $j( $src ) );
		$assert( 'a callback of another plugin is attributed to its folder and name', 'plugin' === $src['source'] && $dir === $src['folder'] && '' !== $src['name'], $j( $src ) );
	}
	$core_src = WidgetSnapshot::source( 'wp_dashboard_right_now' );
	$assert( 'a core callback is attributed to WordPress', 'core' === $core_src['source'], $j( $core_src ) );
	$assert( 'an unknown callback gives an unknown source', 'unknown' === WidgetSnapshot::source( 'no_such_function_xyz' )['source'] && 'unknown' === WidgetSnapshot::source( null )['source'] );
	$assert( 'the welcome panel of the module is not in the list (it has its own tab)', ! isset( $snap['widgets']['uxstudio_welcome_panel'] ) );

	$before = get_option( WidgetSnapshot::OPTION );
	$dashboard_as( $user_id );
	$assert( 'an editor viewing the dashboard does not touch the snapshot', $before === get_option( WidgetSnapshot::OPTION ) );

	// Same widgets again: no write (only the times would change).
	wp_set_current_user( $admin );
	$dashboard_as( $admin );
	$assert( 'a second view with nothing new does not rewrite the snapshot', $before === get_option( WidgetSnapshot::OPTION ) );
	$res = $rest( 'POST', '/dashboard-widgets/refresh' );
	$assert( 'refresh returns the dashboard URL and asks for a rewrite', 200 === $res['status'] && $res['data']['url'] === admin_url( 'index.php' ) && (bool) get_transient( 'uxstudio_dashboard_widget_force' ) );
	sleep( 1 );
	$dashboard_as( $admin );
	$after = get_option( WidgetSnapshot::OPTION );
	$assert( 'the next view after a refresh request writes the snapshot (new scan time) and clears the request', $after['scan_at'] > $before['scan_at'] && ! get_transient( 'uxstudio_dashboard_widget_force' ) );

	/* ═══ 2. Rules are applied ═══ */

	$assert( 'no rules: an editor sees the same core widgets as an administrator', array_values( array_filter( $dashboard_as( $user_id )['ids'], $not_own ) ) === array_values( array_filter( $dashboard_as( $admin )['ids'], $not_own ) ) );
	$res = $rest( 'POST', '/dashboard-widgets/rules', array( 'items' => array(
		array( 'id' => 'dashboard_activity', 'hidden' => true ),
		array( 'id' => 'uxs_test_a', 'roles' => array( 'administrator' ) ),
		array( 'id' => 'uxs_test_b', 'roles' => array( 'editor' ) ),
		array( 'id' => 'dashboard_welcome', 'hidden' => true ),
	) ) );
	$assert( 'saving rules works', 200 === $res['status'], $j( $res ) );
	$ed = $dashboard_as( $user_id );
	$ad = $dashboard_as( $admin );
	$assert( 'editor: the hidden widget and the one limited to administrators are gone, the one for editors stays', ! in_array( 'dashboard_activity', $ed['ids'], true ) && ! in_array( 'uxs_test_a', $ed['ids'], true ) && in_array( 'uxs_test_b', $ed['ids'], true ), $j( $ed['ids'] ) );
	$assert( 'administrator: hidden is gone, limited-to-administrators stays, limited-to-editors is gone', ! in_array( 'dashboard_activity', $ad['ids'], true ) && in_array( 'uxs_test_a', $ad['ids'], true ) && ! in_array( 'uxs_test_b', $ad['ids'], true ), $j( $ad['ids'] ) );
	$assert( 'the welcome panel action is removed for everybody when hidden', ! $ed['welcome'] && ! $ad['welcome'] );
	$assert( 'other widgets are untouched', in_array( 'dashboard_right_now', $ed['ids'], true ) && in_array( 'dashboard_right_now', $ad['ids'], true ) );
	$snap_after = WidgetSnapshot::read();
	$assert( 'hiding does not remove the widgets from the snapshot (it is taken before the rules apply)', isset( $snap_after['widgets']['dashboard_activity'] ) && isset( $snap_after['widgets']['uxs_test_a'] ) );

	// A user with two roles: one matching role is enough.
	wp_update_user( array( 'ID' => $user_id, 'role' => 'editor' ) );
	$multi = new WP_User( $user_id );
	$multi->add_role( 'administrator' );
	$both = $dashboard_as( $user_id );
	$assert( 'editor + administrator sees the widget limited to administrators and the one limited to editors', in_array( 'uxs_test_a', $both['ids'], true ) && in_array( 'uxs_test_b', $both['ids'], true ) && ! in_array( 'dashboard_activity', $both['ids'], true ) );
	$multi->remove_role( 'administrator' );

	// A widget registered after the rules were applied is still caught on the columns.
	global $wp_meta_boxes;
	$wp_meta_boxes = array();
	set_current_screen( 'dashboard' );
	wp_dashboard_setup();
	wp_add_dashboard_widget( 'dashboard_activity', 'Late', '__return_empty_string' );
	wp_set_current_user( $user_id );
	do_action( 'do_meta_boxes', 'dashboard', 'normal', '' );
	$late = false;
	foreach ( (array) ( $wp_meta_boxes['dashboard'] ?? array() ) as $priorities ) {
		foreach ( (array) $priorities as $boxes ) {
			$late = $late || isset( $boxes['dashboard_activity'] ) && is_array( $boxes['dashboard_activity'] );
		}
	}
	$assert( 'a hidden widget registered late is removed when the dashboard draws its columns', ! $late );

	// Hide all.
	$module->write_option( array( 'disable_all_widgets' => true ) );
	$all = $dashboard_as( $admin );
	$core_left = array_filter( $all['ids'], static fn( $id ) => 0 !== strpos( $id, 'uxstudio_' ) && 'claude_panel_dashboard_widget' !== $id );
	$assert( 'hide all: nothing but UX Studio widgets stays, no welcome panel', array() === $core_left && ! $all['welcome'], $j( $all ) );
	$module->write_option( array( 'disable_all_widgets' => false ) );

	/* ═══ 3. REST: capabilities and validation ═══ */

	$res = $rest( 'GET', '/dashboard-widgets/rules' );
	$assert( 'GET rules: widgets, rules, roles, scan time', 200 === $res['status'] && count( $res['data']['widgets'] ) >= 5 && isset( ( (array) $res['data']['rules'] )['dashboard_activity'] ), $j( array_keys( (array) $res['data'] ) ) );
	$rows = array_column( $res['data']['widgets'], null, 'id' );
	$assert( 'GET rules: every row says whether it was found by the latest scan', false === $rows['dashboard_activity']['stale'] );
	$assert( 'GET rules: roles are the registered ones', in_array( 'editor', array_column( $res['data']['roles'], 'id' ), true ) && in_array( 'administrator', array_column( $res['data']['roles'], 'id' ), true ) );

	set_transient( 'uxstudio_rl_' . $admin, 60, 60 );
	$request  = new WP_REST_Request( 'POST', '/uxstudio/v1/dashboard-widgets/rules' );
	$request->set_param( 'action', 'dismiss_notice' );
	$assert( 'the write rate limit applies to the rules route (429)', 429 === rest_do_request( $request )->get_status() );
	delete_transient( 'uxstudio_rl_' . $admin );

	wp_set_current_user( $user_id );
	$assert( 'an editor gets 403 on every route', 403 === $rest( 'GET', '/dashboard-widgets/rules' )['status'] && 403 === $rest( 'POST', '/dashboard-widgets/rules', array( 'action' => 'dismiss_notice' ) )['status'] && 403 === $rest( 'POST', '/dashboard-widgets/refresh' )['status'] && 403 === $rest( 'DELETE', '/dashboard-widgets/snapshot/x' )['status'] );
	wp_set_current_user( 0 );
	$assert( 'nobody gets 401', 401 === $rest( 'GET', '/dashboard-widgets/rules' )['status'] );
	wp_set_current_user( $admin );

	$bad = array(
		'unknown action'   => array( 'action' => 'delete', 'ids' => array( 'a' ) ),
		'bad id in items'  => array( 'items' => array( array( 'id' => 'bad id!', 'hidden' => true ) ) ),
		'extra property'   => array( 'items' => array( array( 'id' => 'a', 'evil' => 1 ) ) ),
		'hidden not bool'  => array( 'items' => array( array( 'id' => 'a', 'hidden' => 'yes' ) ) ),
		'too many ids'     => array( 'action' => 'hide', 'ids' => array_map( static fn( $i ) => 'w' . $i, range( 1, 301 ) ) ),
		'roles not strings' => array( 'action' => 'roles', 'ids' => array( 'a' ), 'roles' => array( array( 'editor' ) ) ),
		'id too long'      => array( 'action' => 'hide', 'ids' => array( str_repeat( 'a', 121 ) ) ),
	);
	foreach ( $bad as $name => $body ) {
		$r = $rest( 'POST', '/dashboard-widgets/rules', $body );
		$assert( "validation: $name is refused (400)", 400 === $r['status'], $r['status'] . ' ' . $j( $r['code'] ) );
	}
	$assert( 'a bulk action without widgets is refused', 400 === $rest( 'POST', '/dashboard-widgets/rules', array( 'action' => 'hide', 'ids' => array() ) )['status'] );

	/* ═══ 4. REST: bulk actions, undo ═══ */

	$rest( 'POST', '/dashboard-widgets/rules', array( 'action' => 'replace', 'rules' => array() ) );
	$assert( 'replace with an empty map clears the rules', array() === $module->rules() );
	$r = $rest( 'POST', '/dashboard-widgets/rules', array( 'action' => 'hide', 'ids' => array( 'dashboard_activity', 'uxs_test_a' ) ) );
	$assert( 'bulk hide', 200 === $r['status'] && array( 'dashboard_activity', 'uxs_test_a' ) === array_keys( $module->rules() ) );
	$r = $rest( 'POST', '/dashboard-widgets/rules', array( 'action' => 'roles', 'ids' => array( 'uxs_test_a', 'dashboard_right_now' ), 'roles' => array( 'editor', 'ghost' ) ) );
	$rules = $module->rules();
	$assert( 'bulk roles: unknown roles dropped, hidden state kept', 200 === $r['status'] && array( 'editor' ) === $rules['dashboard_right_now']['roles'] && true === $rules['uxs_test_a']['hidden'] && array( 'editor' ) === $rules['uxs_test_a']['roles'], $j( $rules ) );
	$r = $rest( 'POST', '/dashboard-widgets/rules', array( 'action' => 'show', 'ids' => array( 'dashboard_activity' ) ) );
	$assert( 'bulk show removes an entry that is back to the defaults', ! isset( $module->rules()['dashboard_activity'] ) );
	$r = $rest( 'POST', '/dashboard-widgets/rules', array( 'action' => 'roles', 'ids' => array( 'dashboard_right_now' ), 'roles' => array_column( $rest( 'GET', '/dashboard-widgets/rules' )['data']['roles'], 'id' ) ) );
	$assert( 'all roles chosen = everybody (entry removed)', ! isset( $module->rules()['dashboard_right_now'] ) );
	$undo_to = $module->rules();
	$rest( 'POST', '/dashboard-widgets/rules', array( 'action' => 'reset', 'ids' => array( 'uxs_test_a' ) ) );
	$assert( 'bulk reset', ! isset( $module->rules()['uxs_test_a'] ) );
	$rest( 'POST', '/dashboard-widgets/rules', array( 'action' => 'replace', 'rules' => $undo_to ) );
	$assert( 'replace puts the earlier rules back (revert changes)', $module->rules() === $undo_to );
	$assert( 'the rules are stored in the module option', $module->rules() === get_option( 'uxstudio_dashboard_widgets' )['widget_rules'] );

	/* ═══ 5. Stale widgets ═══ */

	$snap                              = WidgetSnapshot::read();
	$snap['widgets']['gone_plugin_box'] = array( 'id' => 'gone_plugin_box', 'title' => 'Gone box', 'context' => 'normal', 'priority' => 'core', 'source' => 'plugin:gone', 'source_name' => 'Gone', 'guess' => true, 'first_seen' => 1000, 'last_seen' => $snap['scan_at'] - 100 );
	update_option( WidgetSnapshot::OPTION, $snap, false );
	$rest( 'POST', '/dashboard-widgets/rules', array( 'items' => array( array( 'id' => 'gone_plugin_box', 'hidden' => true ) ) ) );
	$rows = array_column( $rest( 'GET', '/dashboard-widgets/rules' )['data']['widgets'], null, 'id' );
	$assert( 'a widget missing from the latest scan is marked stale and keeps its rule', true === $rows['gone_plugin_box']['stale'] && true === $module->rules()['gone_plugin_box']['hidden'] );
	$assert( 'a live widget cannot be forgotten (409)', 409 === $rest( 'DELETE', '/dashboard-widgets/snapshot/dashboard_activity' )['status'] );
	$assert( 'an unknown id gives 404', 404 === $rest( 'DELETE', '/dashboard-widgets/snapshot/never_seen' )['status'] );
	$r = $rest( 'DELETE', '/dashboard-widgets/snapshot/gone_plugin_box' );
	$assert( 'a stale widget is forgotten together with its rule', 200 === $r['status'] && ! isset( WidgetSnapshot::read()['widgets']['gone_plugin_box'] ) && ! isset( $module->rules()['gone_plugin_box'] ) );
	$rest( 'POST', '/dashboard-widgets/rules', array( 'items' => array( array( 'id' => 'imported_only', 'hidden' => true ) ) ) );
	$rows = array_column( $rest( 'GET', '/dashboard-widgets/rules' )['data']['widgets'], null, 'id' );
	$assert( 'a rule of a widget the snapshot does not know still shows up (as not found)', isset( $rows['imported_only'] ) && $rows['imported_only']['stale'] );
	$rest( 'DELETE', '/dashboard-widgets/snapshot/imported_only' );
	$assert( 'and can be removed', ! isset( $module->rules()['imported_only'] ) );

	/* ═══ 6. Migration of the older lists (fixture) ═══ */

	$fixture = array(
		'disable_all_widgets'   => false,
		'hidden_widgets'        => array( 'dashboard_welcome', 'uxs_test_b' ),
		'hidden_widgets_editor' => array( 'dashboard_activity', 'uxs_test_a' ),
		'hidden_widgets_author' => array( 'uxs_test_a' ),
		'widget_tasks'          => true,
	);
	delete_option( 'uxstudio_dashboard_widgets' );
	add_option( 'uxstudio_dashboard_widgets', $fixture, '', false );
	$module->reload_settings();
	$assert( 'migration runs', true === $module->maybe_migrate() );
	$stored = get_option( 'uxstudio_dashboard_widgets' );
	$rules  = $stored['widget_rules'];
	$all_roles = array_keys( wp_roles()->get_names() );
	$assert( 'migration: the global list is hidden', true === $rules['dashboard_welcome']['hidden'] && true === $rules['uxs_test_b']['hidden'] );
	$assert( 'migration: hidden for editor and author = shown to every other role', ! in_array( 'editor', $rules['uxs_test_a']['roles'], true ) && ! in_array( 'author', $rules['uxs_test_a']['roles'], true ) && in_array( 'administrator', $rules['uxs_test_a']['roles'], true ) && count( $rules['uxs_test_a']['roles'] ) === count( $all_roles ) - 2, $j( $rules['uxs_test_a'] ) );
	$assert( 'migration: hidden for editor only', ! in_array( 'editor', $rules['dashboard_activity']['roles'], true ) && count( $rules['dashboard_activity']['roles'] ) === count( $all_roles ) - 1 );
	$assert( 'migration: the old keys are kept, the other settings too, the notice is on, the version is set', $stored['hidden_widgets'] === $fixture['hidden_widgets'] && $stored['hidden_widgets_editor'] === $fixture['hidden_widgets_editor'] && true === $stored['widget_tasks'] && true === $stored['rules_notice'] && 1 === $stored['rules_version'] );
	$assert( 'migration: idempotent (second run does nothing)', false === $module->maybe_migrate() && get_option( 'uxstudio_dashboard_widgets' )['widget_rules'] === $rules );
	$assert( 'migration: the real dashboard follows (editor loses activity and primary, welcome and health are gone for all)', (static function () use ( $dashboard_as, $user_id, $admin ): bool {
		$ed = $dashboard_as( $user_id );
		$ad = $dashboard_as( $admin );
		return ! in_array( 'dashboard_activity', $ed['ids'], true ) && ! in_array( 'uxs_test_a', $ed['ids'], true ) && in_array( 'dashboard_activity', $ad['ids'], true ) && in_array( 'uxs_test_a', $ad['ids'], true ) && ! in_array( 'uxs_test_b', $ad['ids'], true ) && ! $ed['welcome'];
	})() );
	$payload = $rest( 'GET', '/dashboard-widgets/rules' )['data'];
	$assert( 'the notice shows in the payload until it is dismissed', true === $payload['notice'] );
	$r = $rest( 'POST', '/dashboard-widgets/rules', array( 'action' => 'dismiss_notice' ) );
	$assert( 'dismissing the notice', false === $r['data']['notice'] && false === get_option( 'uxstudio_dashboard_widgets' )['rules_notice'] );
	// The user edits the table, the old lists must not come back.
	$rest( 'POST', '/dashboard-widgets/rules', array( 'action' => 'reset', 'ids' => array( 'dashboard_activity' ) ) );
	$module->maybe_migrate();
	$assert( 'after the table was edited the old lists do not overwrite it', ! isset( $module->rules()['dashboard_activity'] ) );
	// New old-format data (another migration source, a file) is converted once more.
	update_option( 'uxstudio_dashboard_widgets', array_merge( get_option( 'uxstudio_dashboard_widgets' ), array( 'hidden_widgets_subscriber' => array( 'dashboard_right_now' ) ) ) );
	$module->reload_settings();
	$assert( 'changed old data is converted again (merged over the rules)', true === $module->maybe_migrate() && isset( $module->rules()['dashboard_right_now'] ) && ! in_array( 'subscriber', $module->rules()['dashboard_right_now']['roles'], true ) );
	// Nothing old: only the version marker.
	delete_option( 'uxstudio_dashboard_widgets' );
	$module->reload_settings();
	$assert( 'no old data: nothing is converted, only the version marker is written', false === $module->maybe_migrate() && 1 === get_option( 'uxstudio_dashboard_widgets' )['rules_version'] && ! isset( get_option( 'uxstudio_dashboard_widgets' )['widget_rules'] ) );

	/* ═══ 7. Export / import ═══ */

	$rest( 'POST', '/dashboard-widgets/rules', array( 'action' => 'replace', 'rules' => array( 'dashboard_activity' => array( 'hidden' => true, 'roles' => array() ), 'uxs_test_a' => array( 'hidden' => false, 'roles' => array( 'administrator', 'editor' ) ) ) ) );
	$mine = $module->rules();
	$built = $exporter->build( array( 'dashboard-widgets' ), array() );
	$assert( 'export builds', ! is_wp_error( $built ), is_wp_error( $built ) ? $built->get_error_message() : '' );
	$entry = $built['envelope']['modules']['dashboard-widgets'];
	$assert( 'export holds widget_rules in the exact structure', $mine === $entry['settings']['widget_rules'], $j( $entry['settings']['widget_rules'] ?? null ) );
	$legacy_exported = array_filter( array_keys( $entry['settings'] ), static fn( $k ) => 'hidden_widgets' === $k || 0 === strpos( $k, 'hidden_widgets_' ) );
	$assert( 'export does not hold the old keys', array() === $legacy_exported, implode( ',', $legacy_exported ) );
	$assert( 'export holds no API key', ! isset( $entry['settings']['pagespeed_api_key'] ) && ! isset( $entry['settings']['ga_service_account_json'] ) );

	$p = $importer->preview( $built['json'], '', Importer::options( array() ) );
	$row = array_column( $p['modules'], null, 'id' )['dashboard-widgets'] ?? null;
	$assert( 'preview of the own export has no change and no rejection for widget_rules', $row && ! in_array( 'widget_rules', array_column( $row['changes'], 'key' ), true ) && ! in_array( 'widget_rules', array_column( $row['rejected'], 'key' ), true ), $j( $row ) );

	$rest( 'POST', '/dashboard-widgets/rules', array( 'action' => 'replace', 'rules' => array() ) );
	$res = $importer->apply( $built['json'], '', Importer::options( array( 'modules' => array( 'dashboard-widgets' ) ) ) );
	$assert( 'import applies', ! is_wp_error( $res ) && 1 === $res['summary']['ok'], is_wp_error( $res ) ? $res->get_error_message() : $j( $res['summary'] ) );
	$module->reload_settings();
	$assert( 'round trip: the rules are back exactly', $module->rules() === $mine, $j( $module->rules() ) );
	$undo = $importer->undo();
	$assert( 'undo works and the rules are as before the import', ! is_wp_error( $undo ) && array() === $module->rules(), is_wp_error( $undo ) ? $undo->get_error_message() : '' );

	// Hostile file: bad ids, unknown roles, wrong types, too many.
	$many = array();
	for ( $i = 0; $i < 301; $i++ ) {
		$many[ 'w' . $i ] = array( 'hidden' => true, 'roles' => array() );
	}
	$files = array(
		'mixed'     => array( 'good_one' => array( 'hidden' => true, 'roles' => array( 'ghost_role', 'editor' ) ), 'bad id!' => array( 'hidden' => true ), '<script>' => array( 'hidden' => true ) ),
		'not array' => 'hidden',
		'bad hidden' => array( 'a' => array( 'hidden' => 'yes' ) ),
		'too many'  => $many,
	);
	$prev = array();
	foreach ( $files as $name => $value ) {
		$file = Envelope::encode( Envelope::build( array( 'plugin_version' => UXSTUDIO_VERSION, 'modules' => array( 'dashboard-widgets' => array( 'settings' => array( 'widget_rules' => $value ), 'needs_manual' => array() ) ) ) ) );
		$pv   = $importer->preview( $file, '', Importer::options( array() ) );
		$prev[ $name ] = array_column( $pv['modules'], null, 'id' )['dashboard-widgets'];
	}
	$assert( 'import: bad ids and unknown roles are dropped and counted, the good entry stays', ! empty( $prev['mixed']['dropped'] ) && 3 === (int) ( $prev['mixed']['dropped'][0]['count'] ?? 0 ) && ! $prev['mixed']['rejected'], $j( $prev['mixed']['dropped'] ?? null ) );
	$assert( 'import: a value that is not a list of rules is rejected', ! empty( $prev['not array']['rejected'] ) && ! empty( $prev['bad hidden']['rejected'] ) );
	$assert( 'import: more than 300 entries are rejected', ! empty( $prev['too many']['rejected'] ) );
	$file = Envelope::encode( Envelope::build( array( 'plugin_version' => UXSTUDIO_VERSION, 'modules' => array( 'dashboard-widgets' => array( 'settings' => $files ? array( 'widget_rules' => $files['mixed'] ) : array(), 'needs_manual' => array() ) ) ) ) );
	$importer->apply( $file, '', Importer::options( array( 'modules' => array( 'dashboard-widgets' ) ) ) );
	$module->reload_settings();
	$assert( 'import: what is stored is only the clean part', array( 'good_one' => array( 'hidden' => true, 'roles' => array( 'editor' ) ) ) === $module->rules(), $j( $module->rules() ) );
	$importer->undo();

	// An older file with the old keys goes through the same migration.
	$old = Envelope::encode( Envelope::build( array( 'plugin_version' => '0.8.3', 'modules' => array( 'dashboard-widgets' => array( 'settings' => array( 'hidden_widgets' => array( 'uxs_test_a' ), 'hidden_widgets_editor' => array( 'dashboard_activity' ) ), 'needs_manual' => array() ) ) ) ) );
	$pv  = $importer->preview( $old, '', Importer::options( array() ) );
	$row = array_column( $pv['modules'], null, 'id' )['dashboard-widgets'];
	$assert( 'an older file with the old keys is accepted by the preview', ! $row['rejected'] && ! in_array( 'hidden_widgets', $row['unknown'], true ), $j( array( $row['rejected'], $row['unknown'] ) ) );
	$rest( 'POST', '/dashboard-widgets/rules', array( 'action' => 'replace', 'rules' => array() ) );
	$res = $importer->apply( $old, '', Importer::options( array( 'modules' => array( 'dashboard-widgets' ) ) ) );
	$module->reload_settings();
	$rules = $module->rules();
	$assert( 'an older file is converted into rules on import', ! is_wp_error( $res ) && true === ( $rules['uxs_test_a']['hidden'] ?? null ) && isset( $rules['dashboard_activity'] ) && ! in_array( 'editor', $rules['dashboard_activity']['roles'], true ), $j( $rules ) );
	$assert( 'and the notice is on', true === $rest( 'GET', '/dashboard-widgets/rules' )['data']['notice'] );
	$undo = $importer->undo();
	$assert( 'undo of that import', ! is_wp_error( $undo ) );

} catch ( Throwable $e ) {
	++$failures;
	WP_CLI::log( 'FAIL exception: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine() );
} finally {
	$restore();
	$modules_now = (array) get_option( 'uxstudio_active_modules', array() );
	$assert( 'the module list is exactly as before', ( $saved['uxstudio_active_modules']['value'] ?? null ) === $modules_now );
	$assert( 'the module option is as before', ( $saved['uxstudio_dashboard_widgets']['exists'] ?? false ) ? get_option( 'uxstudio_dashboard_widgets' ) === $saved['uxstudio_dashboard_widgets']['value'] : false === get_option( 'uxstudio_dashboard_widgets', false ) );
	$assert( 'the snapshot option is as before', ( $saved[ WidgetSnapshot::OPTION ]['exists'] ?? false ) ? get_option( WidgetSnapshot::OPTION ) === $saved[ WidgetSnapshot::OPTION ]['value'] : false === get_option( WidgetSnapshot::OPTION, false ) );
	$assert( 'the temporary editor is deleted', ! $user_id || false === get_userdata( $user_id ) );
	$assert( 'the uxstudio_* cron hooks are as before', $cron_hooks() === $cron_before, $j( array_diff( $cron_hooks(), $cron_before ) ) );
}

WP_CLI::log( $failures ? "FAILED: $failures" : 'ALL PASSED' );
if ( $failures ) {
	WP_CLI::halt( 1 );
}
